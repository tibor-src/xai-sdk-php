<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use XaiOfficial\Sdk\Constants;
use XaiOfficial\Sdk\Errors\APIConnectionError;
use XaiOfficial\Sdk\Errors\APIError;
use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\AbortError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Errors\TimeoutError;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Types\HttpMeta;
use XaiOfficial\Sdk\Types\RequestOptions;
use XaiOfficial\Sdk\Version;

final class HttpTransport
{
    public static function joinURL(string $base, string $path): string
    {
        $base = rtrim($base, '/');
        $path = str_starts_with($path, '/') ? $path : '/'.$path;

        return $base.$path;
    }

    /**
     * @param array<string, string|int|float|null> $query
     */
    public static function withQuery(string $url, array $query = []): string
    {
        $parts = parse_url($url);
        $existing = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $existing);
        }
        foreach ($query as $k => $v) {
            if ($v === null) {
                continue;
            }
            $existing[$k] = (string) $v;
        }
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (! empty($parts['port'])) {
            $base .= ':'.$parts['port'];
        }
        $base .= $parts['path'] ?? '';
        $qs = http_build_query($existing);

        return $qs === '' ? $base : $base.'?'.$qs;
    }

    public static function shouldRetryStatus(string $method, int $status, bool $retryServerErrors = false): bool
    {
        if (in_array($status, Constants::RETRYABLE_STATUS, true)) {
            return true;
        }
        if ($retryServerErrors && in_array($status, Constants::SERVER_ERROR_RETRY_STATUS, true)) {
            return true;
        }

        return self::isReadOnlyMethod($method) && in_array($status, Constants::IDEMPOTENT_RETRY_STATUS, true);
    }

    public static function retryDelayMs(int $attempt, ?string $retryAfter, ?int $status = null): int
    {
        if ($retryAfter !== null) {
            if (is_numeric($retryAfter)) {
                return min((int) ((float) $retryAfter * 1000), 60_000);
            }
            $date = strtotime($retryAfter);
            if ($date !== false) {
                return min(max(($date - time()) * 1000, 0), 60_000);
            }
        }
        $config = $status === 429 ? Constants::RATE_LIMIT_BACKOFF_MS : Constants::BACKOFF_MS;
        $capped = min($config['initial'] * (2 ** $attempt), $config['max']);

        return (int) ($capped * (0.5 + (mt_rand() / mt_getrandmax()) * 0.5));
    }

    public static function sleep(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }
        usleep($ms * 1000);
    }

    /**
     * @param array<string, mixed> $req
     * @return array{http: HttpMeta, payload: mixed, body: StreamInterface|null, sawByte: bool}
     */
    public static function send(SpaceXAI $client, array $req): array
    {
        $clientRequestId = self::clientRequestIdFor($client, $req['opts'] ?? null);
        try {
            return self::sendWithRetries($client, $req, $clientRequestId);
        } catch (\Throwable $e) {
            throw ErrorFactory::withClientRequestId($e, $clientRequestId);
        }
    }

    /**
     * @param array<string, mixed> $req
     * @return array{http: HttpMeta, payload: mixed, body: StreamInterface|null, sawByte: bool}
     */
    private static function sendWithRetries(SpaceXAI $client, array $req, string $clientRequestId): array
    {
        $opts = $req['opts'] ?? null;
        $timeout = ($opts instanceof RequestOptions ? $opts->timeout : null) ?? $client->timeout;
        $budget = $req['retryBudget'] ?? new RetryBudget(
            ($opts instanceof RequestOptions ? $opts->maxRetries : null) ?? $client->maxRetries,
        );
        $idleTimeout = ($opts instanceof RequestOptions ? $opts->idleTimeout : null) ?? $client->idleTimeout;
        $maxResponseBodyBytes = ($opts instanceof RequestOptions ? $opts->maxResponseBodyBytes : null)
            ?? $client->maxResponseBodyBytes;
        $method = $req['method'];
        $hasBody = isset($req['body']) && ! in_array($method, ['GET', 'HEAD'], true);
        $url = self::withQuery(self::joinURL($client->baseURL, $req['path']), $req['query'] ?? []);
        $stream = (bool) ($req['stream'] ?? false);
        $binary = (bool) ($req['binary'] ?? false);
        $accept = $stream ? 'text/event-stream' : ($binary ? '*/*' : 'application/json');

        $lastRequestId = null;

        while (true) {
            $headers = self::buildHeaders($client, $opts, $accept, $hasBody && ! ($req['body'] instanceof \GuzzleHttp\Psr7\MultipartStream), $clientRequestId);
            $guzzleOptions = [
                'headers' => $headers,
                'http_errors' => false,
                'allow_redirects' => false,
                'timeout' => $timeout / 1000,
            ];

            if ($hasBody) {
                if ($req['body'] instanceof \GuzzleHttp\Psr7\MultipartStream) {
                    $guzzleOptions['body'] = $req['body'];
                } elseif (is_array($req['body'])) {
                    $guzzleOptions['json'] = $req['body'];
                } else {
                    $guzzleOptions['body'] = $req['body'];
                }
            }

            if (getenv('XAI_DEBUG') === '1') {
                fwrite(STDERR, self::formatCurl($method, $url, $headers)."\n");
            }

            $receivedResponse = false;
            try {
                $request = new \GuzzleHttp\Psr7\Request($method, $url, $headers);
                if ($client->onRequest !== null) {
                    ($client->onRequest)($request);
                }

                $response = $client->httpClient->request($method, $url, $guzzleOptions);
                $receivedResponse = true;
                $responseHeaders = self::normalizeHeaders($response->getHeaders());
                $lastRequestId = ErrorFactory::requestIdFromHeaders($responseHeaders);

                if ($client->onResponse !== null) {
                    ($client->onResponse)($response);
                }

                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    $text = self::readBody($response->getBody(), $idleTimeout, min($maxResponseBodyBytes, Constants::DEFAULT_MAX_ERROR_BODY_BYTES));
                    $err = ErrorFactory::errorFromResponse($response->getStatusCode(), $lastRequestId, self::parsePayload($text));
                    if (self::shouldRetryStatus($method, $response->getStatusCode(), (bool) ($req['retryServerErrors'] ?? false)) && $budget->canRetry()) {
                        $retryAfter = $response->getHeaderLine('Retry-After') ?: null;
                        self::sleep(self::retryDelayMs($budget->spend(), $retryAfter, $response->getStatusCode()));
                        continue;
                    }
                    throw $err;
                }

                $http = new HttpMeta(
                    status: $response->getStatusCode(),
                    headers: $responseHeaders,
                    requestId: $lastRequestId,
                    clientRequestId: $clientRequestId,
                );

                $contentType = strtolower(trim(explode(';', $response->getHeaderLine('content-type'))[0] ?? ''));
                $eventStream = $stream && ! (($req['acceptJson'] ?? false) && $contentType === 'application/json');

                if ($eventStream || $binary) {
                    if ($eventStream && $contentType !== 'text/event-stream') {
                        throw new APIProtocolError('Streaming response must use text/event-stream', [
                            'requestId' => $lastRequestId,
                        ]);
                    }
                    $body = $response->getBody();

                    return ['http' => $http, 'payload' => null, 'body' => $body, 'sawByte' => $body->getSize() > 0];
                }

                $text = self::readBody($response->getBody(), $idleTimeout, $maxResponseBodyBytes);
                $payload = self::parsePayload($text);
                if (($opts instanceof RequestOptions ? $opts->http['body'] ?? false : false)) {
                    $http->body = $payload;
                }

                return ['http' => $http, 'payload' => $payload, 'body' => null, 'sawByte' => true];
            } catch (GuzzleException $e) {
                if ($receivedResponse || APIError::is($e)) {
                    throw ErrorFactory::errorFromUnknown($e, $lastRequestId);
                }
                if ((self::isReadOnlyMethod($method) || ($req['retryServerErrors'] ?? false)) && $budget->canRetry()) {
                    self::sleep(self::retryDelayMs($budget->spend(), null));
                    continue;
                }
                throw new APIConnectionError($e->getMessage(), ['requestId' => $lastRequestId], $e);
            }
        }
    }

    private static function isReadOnlyMethod(string $method): bool
    {
        return in_array($method, ['GET', 'HEAD'], true);
    }

    /**
     * @return array<string, string>
     */
    private static function buildHeaders(
        SpaceXAI $client,
        ?RequestOptions $opts,
        string $accept,
        bool $jsonBody,
        string $clientRequestId,
    ): array {
        $headers = $client->defaultHeaders;
        if ($opts !== null) {
            $headers = array_merge($headers, $opts->headers);
        }
        if (! isset($headers['authorization'])) {
            $headers['authorization'] = 'Bearer '.$client->getApiKey();
        }
        if ($jsonBody && ! isset($headers['content-type'])) {
            $headers['content-type'] = 'application/json';
        }
        if (! isset($headers['accept'])) {
            $headers['accept'] = $accept;
        }
        if (! isset($headers['user-agent'])) {
            $headers['user-agent'] = Constants::SDK_USER_AGENT;
        }
        $headers[Constants::CLIENT_REQUEST_ID_HEADER] = $clientRequestId;
        $headers['xai-sdk-version'] = 'php/'.Version::SDK_VERSION;
        $headers['xai-sdk-language'] = 'php/'.PHP_VERSION;

        return $headers;
    }

    private static function clientRequestIdFor(SpaceXAI $client, ?RequestOptions $opts): string
    {
        $headers = array_merge($client->defaultHeaders, $opts?->headers ?? []);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === Constants::CLIENT_REQUEST_ID_HEADER) {
                return (string) $value;
            }
        }

        return self::uuid();
    }

    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function readBody(StreamInterface $body, int $idleTimeout, int $maxBytes): string
    {
        $out = '';
        $bytesRead = 0;
        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            $bytesRead += strlen($chunk);
            if ($maxBytes > 0 && $bytesRead > $maxBytes) {
                throw new APIProtocolError("Response body exceeds {$maxBytes} bytes");
            }
            $out .= $chunk;
        }

        return $out;
    }

    private static function parsePayload(string $text): mixed
    {
        if ($text === '') {
            return null;
        }
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }
    }

    /**
     * @param array<string, string|string[]> $headers
     * @return array<string, string|string[]>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            $out[$key] = count($value) === 1 ? $value[0] : $value;
        }

        return $out;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function formatCurl(string $method, string $url, array $headers): string
    {
        $lines = ["curl -sS -X {$method} '".str_replace("'", "'\\''", $url)."'"];
        foreach ($headers as $key => $value) {
            $redacted = self::redactHeader($key, $value);
            $lines[] = "  -H '".str_replace("'", "'\\''", "{$key}: {$redacted}")."'";
        }

        return implode(" \\\n", $lines)."\n# Request body omitted because it may contain sensitive data.";
    }

    private static function redactHeader(string $key, string $value): string
    {
        $normalized = strtolower($key);
        $sensitive = in_array($normalized, ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'api-key'], true)
            || preg_match('/(?:auth|token|secret|api[-_]?key|cookie|credential|session)/i', $normalized);
        if (! $sensitive) {
            return $value;
        }
        if ($normalized === 'authorization' && preg_match('/^bearer\s+/i', $value)) {
            return 'Bearer [REDACTED]';
        }

        return '[REDACTED]';
    }
}
