<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

use TiborSrc\XaiSdkPhp\Http\AbortSignal;
use TiborSrc\XaiSdkPhp\Http\ByteSource;
use TiborSrc\XaiSdkPhp\Http\CurlSession;
use TiborSrc\XaiSdkPhp\Http\FormData;
use TiborSrc\XaiSdkPhp\Http\HeaderBag;
use TiborSrc\XaiSdkPhp\Http\HttpRequest;
use TiborSrc\XaiSdkPhp\Http\HttpResponse;
use TiborSrc\XaiSdkPhp\Http\Poll;

final class RetryBudget
{
    private int $spent = 0;

    public function __construct(public readonly int $max) {}

    public function canRetry(): bool
    {
        return $this->spent < $this->max;
    }

    public function spend(): int
    {
        return $this->spent++;
    }
}

final class HttpMeta
{
    public function __construct(
        public readonly int $status,
        public readonly HeaderBag $headers,
        public readonly ?string $requestId,
        public readonly string $clientRequestId,
        public mixed $body = null,
    ) {}
}

final class IdleStream
{
    /** @param list<string> $prefix */
    public function __construct(
        private readonly ByteSource $source,
        private readonly int $idleTimeoutMs,
        private readonly ?AbortSignal $signal,
        private readonly ?string $requestId,
        private array $prefix = [],
        private bool $ended = false,
        private readonly ?\Closure $onFinalize = null,
    ) {}

    private bool $finalized = false;

    public function read(): ?string
    {
        if ($this->prefix !== []) {
            return array_shift($this->prefix);
        }
        if ($this->ended) {
            $this->finalize();

            return null;
        }
        try {
            $chunk = Transport::readOnce($this->source, $this->idleTimeoutMs, $this->signal, $this->requestId);
        } catch (\Throwable $exception) {
            $this->source->cancel();
            $this->finalize();
            throw $exception;
        }
        if ($chunk === null) {
            $this->ended = true;
            $this->finalize();
        }

        return $chunk;
    }

    public function cancel(): void
    {
        $this->ended = true;
        $this->source->cancel();
        $this->finalize();
    }

    private function finalize(): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        if ($this->onFinalize instanceof \Closure) {
            ($this->onFinalize)();
        }
    }
}

final class SendResult
{
    public function __construct(
        public readonly HttpResponse $response,
        public readonly HttpMeta $http,
        public readonly mixed $payload,
        public readonly ?IdleStream $body,
        public readonly bool $sawByte,
    ) {}
}

final class Transport
{
    public const DEFAULT_BASE_URL = 'https://api.x.ai/v1';

    public const DEFAULT_TIMEOUT_MS = 3_600_000;

    public const DEFAULT_IDLE_TIMEOUT_MS = 60_000;

    public const DEFAULT_MAX_RETRIES = 2;

    public const DEFAULT_MAX_RESPONSE_BODY_BYTES = 32 * 1024 * 1024;

    public const DEFAULT_MAX_ERROR_BODY_BYTES = 1024 * 1024;

    public const CLIENT_REQUEST_ID_HEADER = 'x-client-request-id';

    /** @var array<int, true> */
    private const RETRYABLE_STATUS = [429 => true];

    /** @var array<int, true> */
    private const IDEMPOTENT_RETRY_STATUS = [408 => true, 409 => true, 500 => true, 502 => true, 503 => true, 504 => true, 529 => true];

    /** @var array<int, true> */
    private const SERVER_ERROR_RETRY_STATUS = [500 => true, 502 => true, 503 => true, 504 => true, 529 => true];

    /** @var array{initial: int, max: int} */
    private const BACKOFF_MS = ['initial' => 250, 'max' => 8_000];

    /** @var array{initial: int, max: int} */
    private const RATE_LIMIT_BACKOFF_MS = ['initial' => 1_000, 'max' => 30_000];

    /** @var list<string> */
    private const REDACT_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-api-key', 'api-key'];

    /** @param callable(string): void|null $debugWriter */
    public static ?\Closure $debugWriter = null;

    public static function joinUrl(string $base, string $path): string
    {
        $base = rtrim($base, '/');
        $path = str_starts_with($path, '/') ? $path : '/' . $path;

        return $base . $path;
    }

    /** @param array<string, string|int|float|null> $query */
    public static function withQuery(string $url, ?array $query): string
    {
        if ($query === null || $query === []) {
            return $url;
        }
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        if ($parts === []) {
            return $url;
        }
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . implode('&', $parts);
    }

    public static function shouldRetryStatus(string $method, int $status, bool $retryServerErrors = false): bool
    {
        if (isset(self::RETRYABLE_STATUS[$status])) {
            return true;
        }
        if ($retryServerErrors && isset(self::SERVER_ERROR_RETRY_STATUS[$status])) {
            return true;
        }

        return self::isReadOnly($method) && isset(self::IDEMPOTENT_RETRY_STATUS[$status]);
    }

    public static function retryDelayMs(int $attempt, ?string $retryAfter, ?int $status = null): float
    {
        if ($retryAfter !== null && $retryAfter !== '') {
            if (is_numeric($retryAfter)) {
                $seconds = (float) $retryAfter;
                if (is_finite($seconds) && $seconds >= 0) {
                    return min($seconds * 1000, 60_000);
                }
            }
            $date = strtotime($retryAfter);
            if ($date !== false) {
                return min(max(($date - time()) * 1000, 0), 60_000);
            }
        }
        $bounds = $status === 429 ? self::RATE_LIMIT_BACKOFF_MS : self::BACKOFF_MS;
        $capped = min($bounds['initial'] * (2 ** $attempt), $bounds['max']);
        $random = mt_rand() / (mt_getrandmax() + 1);

        return $capped * (0.5 + $random * 0.5);
    }

    public static function sleepMs(float $ms, ?AbortSignal $signal): void
    {
        if ($ms <= 0) {
            return;
        }
        $end = hrtime(true) + (int) ($ms * 1_000_000);
        while (hrtime(true) < $end) {
            $signal?->throwIfAborted();
            $remaining = (int) (($end - hrtime(true)) / 1000);
            if ($remaining <= 0) {
                break;
            }
            usleep(min(5_000, $remaining));
        }
        $signal?->throwIfAborted();
    }

    public static function formatCurl(string $method, string $url, HeaderBag $headers, string|FormData|null $body = null): string
    {
        $lines = ["curl -sS -X {$method} '" . self::escapeSingle(self::redactUrl($url)) . "'"];
        foreach ($headers->entries() as [$name, $value]) {
            $printed = strtolower($name);
            $lines[] = "  -H '" . self::escapeSingle($printed . ': ' . self::redactHeader($printed, $value)) . "'";
        }
        $command = implode(" \\\n", $lines);

        return $body === null ? $command : $command . "\n# Request body omitted because it may contain sensitive data.";
    }

    /**
     * @param array{
     *   method: string,
     *   path: string,
     *   body?: mixed,
     *   query?: array<string, string|int|float|null>,
     *   stream?: bool,
     *   acceptJson?: bool,
     *   binary?: bool,
     *   retryServerErrors?: bool,
     *   retryBudget?: RetryBudget|null,
     *   opts?: array<string, mixed>
     * } $request
     */
    public static function send(SpaceXAI $client, array $request): SendResult
    {
        $clientRequestId = self::clientRequestId($client, $request['opts'] ?? []);
        try {
            return self::sendWithRetries($client, $request, $clientRequestId);
        } catch (\Throwable $exception) {
            throw withClientRequestId($exception, $clientRequestId);
        }
    }

    public static function readOnce(ByteSource $source, int $idleTimeoutMs, ?AbortSignal $signal, ?string $requestId): ?string
    {
        $idleDeadline = $idleTimeoutMs > 0 ? hrtime(true) + $idleTimeoutMs * 1_000_000 : null;
        while (true) {
            if ($signal?->isAborted()) {
                $source->cancel();
                throw errorFromAbort($signal, $requestId);
            }
            try {
                $poll = $source->poll();
            } catch (\Throwable $exception) {
                $source->cancel();
                throw $exception;
            }
            if ($poll->kind === 'chunk') {
                return $poll->bytes;
            }
            if ($poll->kind === 'eof') {
                return null;
            }
            if ($idleDeadline !== null && hrtime(true) >= $idleDeadline) {
                $source->cancel();
                throw new TimeoutError('Idle timeout', ['requestId' => $requestId]);
            }
            $sleep = 1_000;
            if ($idleDeadline !== null) {
                $remaining = (int) (($idleDeadline - hrtime(true)) / 1000);
                if ($remaining <= 0) {
                    $source->cancel();
                    throw new TimeoutError('Idle timeout', ['requestId' => $requestId]);
                }
                $sleep = min(5_000, $remaining);
            }
            usleep(max(1, $sleep));
        }
    }

    /** @param array<string, mixed> $request */
    private static function sendWithRetries(SpaceXAI $client, array $request, string $clientRequestId): SendResult
    {
        $opts = $request['opts'] ?? [];
        $timeout = $opts['timeout'] ?? $client->timeout;
        $budget = $request['retryBudget'] ?? new RetryBudget($opts['maxRetries'] ?? $client->maxRetries);
        $idleTimeout = $opts['idleTimeout'] ?? $client->idleTimeout;
        $maxResponseBodyBytes = $opts['maxResponseBodyBytes'] ?? $client->maxResponseBodyBytes;
        $method = $request['method'];
        $hasBody = array_key_exists('body', $request) && $method !== 'GET' && $method !== 'HEAD';
        $body = null;
        if ($hasBody) {
            $value = $request['body'];
            if ($value instanceof FormData) {
                $body = $value;
            } else {
                $forceObject = is_array($value) && ($value === [] || ! array_is_list($value));
                $body = Json::encode($value, $forceObject);
            }
        }
        $url = self::withQuery(self::joinUrl($client->baseURL, $request['path']), $request['query'] ?? null);
        $stream = (bool) ($request['stream'] ?? false);
        $binary = (bool) ($request['binary'] ?? false);
        $accept = $stream ? 'text/event-stream' : ($binary ? '*/*' : 'application/json');
        $lastRequestId = null;
        $fromHook = false;
        $userSignal = $opts['signal'] ?? null;
        if (! $userSignal instanceof AbortSignal) {
            $userSignal = null;
        }

        while (true) {
            $headers = self::buildHeaders($client, $opts, $accept, is_string($body), $clientRequestId, $body instanceof FormData ? $body : null);
            $timeoutSignal = null;
            if (is_int($timeout) && $timeout > 0) {
                $timeoutSignal = new AbortSignal();
                $timeoutSignal->deadline = microtime(true) + ($timeout / 1000);
            }
            $signal = AbortSignal::any([$userSignal, $timeoutSignal]);
            if ($signal?->isAborted()) {
                throw errorFromAbort($signal, $lastRequestId);
            }
            $httpRequest = new HttpRequest($method, $url, $headers, $body, $signal);
            if (Env::debugEnabled()) {
                self::writeDebug(self::formatCurl($method, $url, $headers, $body));
            }
            $receivedResponse = false;
            $fromHook = false;
            try {
                if ($client->onRequest !== null) {
                    try {
                        ($client->onRequest)($httpRequest->clone());
                    } catch (\Throwable $exception) {
                        if ($signal?->isAborted()) {
                            throw errorFromAbort($signal, $lastRequestId);
                        }
                        $fromHook = true;
                        throw errorFromUnknown($exception, $lastRequestId);
                    }
                }
                $signal?->throwIfAborted($lastRequestId);
                $response = ($client->fetch)($httpRequest);
                $receivedResponse = true;
                $lastRequestId = requestIdFromHeaders($response->headers);
                if (! $response->ok()) {
                    $text = self::readBody($response, $idleTimeout, $signal, $lastRequestId, min($maxResponseBodyBytes, self::DEFAULT_MAX_ERROR_BODY_BYTES));
                    $error = errorFromResponse($response->status, $response->statusText, $response->headers, Json::decode($text));
                    if ($client->onResponse !== null) {
                        try {
                            ($client->onResponse)(new HttpResponse($response->status, $response->headers->clone(), $response->statusText, null));
                        } catch (\Throwable) {
                            // The HTTP status is authoritative; hooks must not mask it.
                        }
                    }
                    if ($signal?->isAborted()) {
                        throw $error;
                    }
                    if (self::shouldRetryStatus($method, $response->status, (bool) ($request['retryServerErrors'] ?? false)) && $budget->canRetry()) {
                        self::sleepMs(self::retryDelayMs($budget->spend(), $response->headers->get('retry-after'), $response->status), $userSignal);
                        continue;
                    }
                    throw $error;
                }
                if ($client->onResponse !== null) {
                    try {
                        ($client->onResponse)(new HttpResponse($response->status, $response->headers->clone(), $response->statusText, null));
                    } catch (\Throwable $exception) {
                        if ($signal?->isAborted()) {
                            throw errorFromAbort($signal, $lastRequestId);
                        }
                        $fromHook = true;
                        throw errorFromUnknown($exception, $lastRequestId);
                    }
                }
                $http = new HttpMeta($response->status, $response->headers, $lastRequestId, $clientRequestId);
                $contentType = strtolower(trim(explode(';', (string) $response->headers->get('content-type'), 2)[0]));
                $eventStream = $stream && ! (($request['acceptJson'] ?? false) && $contentType === 'application/json');
                if ($eventStream || $binary) {
                    if ($eventStream && $contentType !== 'text/event-stream') {
                        $response->body?->cancel();
                        throw new APIProtocolError('Streaming response must use text/event-stream', [
                            'requestId' => $lastRequestId,
                        ]);
                    }
                    if ($response->body === null || in_array($response->status, [204, 205, 304], true)) {
                        if ($binary) {
                            return new SendResult($response, $http, null, null, false);
                        }
                        throw new APIConnectionError('SSE response had no body', ['requestId' => $lastRequestId]);
                    }
                    $peeked = self::peekFirstChunk($response->body, $idleTimeout, $signal, $lastRequestId);
                    if ($peeked['error'] instanceof APIError && ! $peeked['sawByte']) {
                        throw $peeked['error'];
                    }
                    if (! $peeked['stream'] instanceof IdleStream) {
                        throw new APIConnectionError('SSE response had no body', ['requestId' => $lastRequestId]);
                    }

                    return new SendResult($response, $http, null, $peeked['stream'], $peeked['sawByte']);
                }
                $text = self::readBody($response, $idleTimeout, $signal, $lastRequestId, $maxResponseBodyBytes);
                $payload = Json::decode($text);
                if (($opts['http']['body'] ?? false) === true) {
                    $http->body = $payload;
                }

                return new SendResult($response, $http, $payload, null, true);
            } catch (\Throwable $exception) {
                if ($fromHook) {
                    throw $exception;
                }
                if ($exception instanceof APIError && $exception->status !== null) {
                    throw $exception;
                }
                if ($signal?->isAborted()) {
                    throw errorFromAbort($signal, $lastRequestId);
                }
                if ($receivedResponse || self::isRedirectFailure($exception)) {
                    if ($exception instanceof APIError) {
                        throw $exception;
                    }
                    throw errorFromUnknown($exception, $lastRequestId);
                }
                if ($exception instanceof APIError && $exception->name !== 'APIConnectionError') {
                    throw $exception;
                }
                if ((self::isReadOnly($method) || ($request['retryServerErrors'] ?? false)) && $budget->canRetry()) {
                    self::sleepMs(self::retryDelayMs($budget->spend(), null), $userSignal);
                    continue;
                }
                throw errorFromUnknown($exception, $lastRequestId);
            }
        }
    }

    /** @param array<string, mixed> $opts */
    private static function clientRequestId(SpaceXAI $client, array $opts): string
    {
        $headers = self::callerHeaders($client, $opts);

        return $headers->get(self::CLIENT_REQUEST_ID_HEADER) ?? self::uuid();
    }

    /** @param array<string, mixed> $opts */
    private static function callerHeaders(SpaceXAI $client, array $opts): HeaderBag
    {
        $headers = HeaderBag::from($client->defaultHeaders);
        if (isset($opts['headers'])) {
            $extra = $opts['headers'] instanceof HeaderBag ? $opts['headers'] : (is_array($opts['headers']) ? HeaderBag::from($opts['headers']) : null);
            if ($extra instanceof HeaderBag) {
                foreach ($extra->entries() as [$name, $value]) {
                    $headers->set($name, $value);
                }
            }
        }

        return $headers;
    }

    /** @param array<string, mixed> $opts */
    private static function buildHeaders(
        SpaceXAI $client,
        array $opts,
        string $accept,
        bool $jsonBody,
        string $clientRequestId,
        ?FormData $form,
    ): HeaderBag {
        $headers = self::callerHeaders($client, $opts);
        if (! $headers->has('authorization')) {
            $headers->set('authorization', 'Bearer ' . Credentials::get($client));
        }
        if ($jsonBody && ! $headers->has('content-type')) {
            $headers->set('content-type', 'application/json');
        }
        if ($form instanceof FormData && ! $headers->has('content-type')) {
            $headers->set('content-type', $form->contentType());
        }
        if (! $headers->has('accept')) {
            $headers->set('accept', $accept);
        }
        if (! $headers->has('user-agent')) {
            $headers->set('user-agent', SDK_USER_AGENT);
        }
        $headers->set(self::CLIENT_REQUEST_ID_HEADER, $clientRequestId);
        $headers->set('xai-sdk-version', 'php/' . SDK_VERSION);
        $headers->set('xai-sdk-language', Env::language());

        return $headers;
    }

    private static function readBody(
        HttpResponse $response,
        int $idleTimeout,
        ?AbortSignal $signal,
        ?string $requestId,
        int $maxBytes,
    ): string {
        if ($response->body === null) {
            return '';
        }
        $out = '';
        $bytesRead = 0;
        while (true) {
            $chunk = self::readOnce($response->body, $idleTimeout, $signal, $requestId);
            if ($chunk === null) {
                break;
            }
            $bytesRead += strlen($chunk);
            if ($maxBytes > 0 && $bytesRead > $maxBytes) {
                $response->body->cancel();
                throw new APIProtocolError('Response body exceeds ' . $maxBytes . ' bytes', [
                    'requestId' => $requestId,
                ]);
            }
            $out .= $chunk;
        }

        return $out;
    }

    /** @return array{sawByte: bool, stream: IdleStream|null, error: APIError|null} */
    private static function peekFirstChunk(ByteSource $body, int $idleTimeout, ?AbortSignal $signal, ?string $requestId): array
    {
        try {
            $first = self::readOnce($body, $idleTimeout, $signal, $requestId);
            $prefix = [];
            $ended = $first === null;
            if (is_string($first) && $first !== '') {
                $prefix[] = $first;
            }

            return [
                'sawByte' => $prefix !== [],
                'stream' => new IdleStream($body, $idleTimeout, $signal, $requestId, $prefix, $ended),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            $body->cancel();

            return [
                'sawByte' => false,
                'stream' => null,
                'error' => errorFromUnknown($exception, $requestId),
            ];
        }
    }

    public static function curl(HttpRequest $request): HttpResponse
    {
        $request->signal?->throwIfAborted();
        $session = new CurlSession($request);
        try {
            $session->awaitHeaders();
        } catch (\Throwable $exception) {
            $session->close();
            throw $exception;
        }
        if ($session->status >= 300 && $session->status < 400) {
            $session->close();
            throw new \RuntimeException('redirect');
        }
        if (in_array($session->status, [204, 205, 304], true)) {
            $session->close();

            return new HttpResponse($session->status, $session->headers, $session->statusText, null);
        }

        return new HttpResponse($session->status, $session->headers, $session->statusText, $session);
    }

    private static function isReadOnly(string $method): bool
    {
        return $method === 'GET' || $method === 'HEAD';
    }

    private static function isRedirectFailure(\Throwable $exception): bool
    {
        return preg_match('/redirect/i', $exception->getMessage()) === 1;
    }

    private static function redactUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            $parts['user'] = 'REDACTED';
            $parts['pass'] = 'REDACTED';
        }
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            foreach (array_keys($query) as $key) {
                if (preg_match('/key|token|secret|password|auth/i', (string) $key) === 1) {
                    $query[$key] = '[REDACTED]';
                }
            }
            $parts['query'] = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return self::buildUrl($parts);
    }

    /** @param array<string, mixed> $parts */
    private static function buildUrl(array $parts): string
    {
        $url = '';
        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'] . '://';
        }
        if (isset($parts['user'])) {
            $url .= $parts['user'];
            if (isset($parts['pass'])) {
                $url .= ':' . $parts['pass'];
            }
            $url .= '@';
        }
        $url .= $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }
        $url .= $parts['path'] ?? '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $url .= '?' . $parts['query'];
        }

        return $url;
    }

    private static function redactHeader(string $key, string $value): string
    {
        $normalized = strtolower($key);
        $sensitive = in_array($normalized, self::REDACT_HEADERS, true)
            || preg_match('/(?:auth|token|secret|api[-_]?key|cookie|credential|session)/i', $normalized) === 1;
        if (! $sensitive) {
            return $value;
        }
        if ($normalized === 'authorization' && preg_match('/^bearer\s+/i', $value) === 1) {
            return 'Bearer [REDACTED]';
        }

        return '[REDACTED]';
    }

    private static function escapeSingle(string $value): string
    {
        return str_replace("'", "'\\''", $value);
    }

    private static function writeDebug(string $message): void
    {
        if (self::$debugWriter instanceof \Closure) {
            (self::$debugWriter)($message);

            return;
        }
        fwrite(STDERR, $message . "\n");
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }
}
