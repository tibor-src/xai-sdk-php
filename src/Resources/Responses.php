<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Resources;

use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\Http\AbortSignal;
use TiborSrc\XaiSdkPhp\Http\HeaderBag;
use TiborSrc\XaiSdkPhp\HttpMeta;
use TiborSrc\XaiSdkPhp\IdleStream;
use TiborSrc\XaiSdkPhp\ModelResponse;
use TiborSrc\XaiSdkPhp\Page;
use TiborSrc\XaiSdkPhp\Porcelain;
use TiborSrc\XaiSdkPhp\Record;
use TiborSrc\XaiSdkPhp\ResponseStream;
use TiborSrc\XaiSdkPhp\RetryBudget;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\Transport;

use function TiborSrc\XaiSdkPhp\requestIds;

final class Responses
{
    public readonly InputItems $inputItems;

    public function __construct(private readonly SpaceXAI $client)
    {
        $this->inputItems = new InputItems($client);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function create(array $body, array $opts = []): ModelResponse|ResponseStream
    {
        $signal = $opts['signal'] ?? null;
        if (array_key_exists('input', $body)) {
            $body['input'] = Porcelain::inlineBlobs($body['input'], $signal instanceof AbortSignal ? $signal : null);
        }
        $payload = Porcelain::applyCreateDefaults($body);
        if (! array_key_exists('stream', $body)) {
            return $this->streamToResponse($payload, $opts);
        }
        $stream = (bool) $body['stream'];
        $budget = $stream ? $this->retryBudget($opts) : null;
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/responses',
            'body' => $payload,
            'stream' => $stream,
            'retryServerErrors' => $budget instanceof RetryBudget,
            'retryBudget' => $budget,
            'opts' => $opts,
        ]);
        if ($stream) {
            return new ResponseStream([
                'body' => $result->body,
                'http' => $result->http,
                'signal' => $signal instanceof AbortSignal ? $signal : null,
                'json' => self::wantsJson($body),
                'retry' => $budget instanceof RetryBudget ? $this->streamRetry($payload, $opts, $result->http, $budget) : null,
            ]);
        }

        return new ModelResponse($result->payload, $result->http);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function compact(array $body, array $opts = []): Record
    {
        $signal = $opts['signal'] ?? null;
        if (array_key_exists('input', $body)) {
            $body['input'] = Porcelain::inlineBlobs($body['input'], $signal instanceof AbortSignal ? $signal : null);
        }
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/responses/compact',
            'body' => $body,
            'opts' => $opts,
        ]);
        $compacted = Wire::requireRecord($result->payload, $result->http, 'Compact response');
        if (! is_string($compacted['id'] ?? null) || ! is_array($compacted['output'] ?? null) || ! array_is_list($compacted['output'])) {
            throw new APIProtocolError('Compact response is missing id or output', [
                ...requestIds($result->http),
                'body' => $compacted,
            ]);
        }

        return Wire::withHttp($compacted, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function get(string $id, array $opts = []): ModelResponse
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/responses/' . Wire::encodePath($id),
            'opts' => $opts,
        ]);

        return new ModelResponse($result->payload, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function delete(string $id, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'DELETE',
            'path' => '/responses/' . Wire::encodePath($id),
            'opts' => $opts,
        ]);
        $body = $result->http->status === 204
            ? ['id' => $id, 'object' => 'response', 'deleted' => true]
            : Wire::requireRecord($result->payload, $result->http, 'Delete response');
        if (! is_string($body['id'] ?? null) || ($body['deleted'] ?? null) !== true) {
            throw new APIProtocolError('Delete response is missing id or deleted=true', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed> $opts
     */
    private function streamToResponse(array $payload, array $opts): ModelResponse
    {
        $streamOpts = $opts;
        if (! array_key_exists('idleTimeout', $opts)) {
            $streamOpts['idleTimeout'] = 0;
        }
        $budget = $this->retryBudget($opts);
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/responses',
            'body' => array_merge($payload, ['stream' => true]),
            'stream' => true,
            'acceptJson' => true,
            'retryServerErrors' => $budget instanceof RetryBudget,
            'retryBudget' => $budget,
            'opts' => $streamOpts,
        ]);
        if (! $result->body instanceof IdleStream) {
            $response = new ModelResponse($result->payload, $result->http);
        } else {
            $signal = $opts['signal'] ?? null;
            $response = (new ResponseStream([
                'body' => $result->body,
                'http' => $result->http,
                'signal' => $signal instanceof AbortSignal ? $signal : null,
                'retry' => $budget instanceof RetryBudget ? $this->streamRetry($payload, $streamOpts, $result->http, $budget) : null,
            ]))->done();
        }
        if (($opts['http']['body'] ?? false) === true) {
            $response->http->body = $response->raw;
        }

        return $response;
    }

    /** @param array<string, mixed> $opts */
    private function retryBudget(array $opts): ?RetryBudget
    {
        $enabled = array_key_exists('retryBeforeOutput', $opts) ? (bool) $opts['retryBeforeOutput'] : $this->client->retryBeforeOutput;
        if (! $enabled) {
            return null;
        }

        return new RetryBudget(is_int($opts['maxRetries'] ?? null) ? $opts['maxRetries'] : $this->client->maxRetries);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $opts
     * @return array{budget: RetryBudget, resend: \Closure(): array{body: ?IdleStream, http: HttpMeta}}
     */
    private function streamRetry(array $payload, array $opts, HttpMeta $http, RetryBudget $budget): array
    {
        $headers = isset($opts['headers']) && (is_array($opts['headers']) || $opts['headers'] instanceof HeaderBag)
            ? ($opts['headers'] instanceof HeaderBag ? $opts['headers']->clone() : HeaderBag::from($opts['headers']))
            : new HeaderBag();
        $headers->set(Transport::CLIENT_REQUEST_ID_HEADER, $http->clientRequestId);
        $client = $this->client;

        return [
            'budget' => $budget,
            'resend' => static function () use ($client, $payload, $opts, $headers, $budget): array {
                $next = $opts;
                $next['headers'] = $headers;
                $result = Transport::send($client, [
                    'method' => 'POST',
                    'path' => '/responses',
                    'body' => array_merge($payload, ['stream' => true]),
                    'stream' => true,
                    'retryServerErrors' => true,
                    'retryBudget' => $budget,
                    'opts' => $next,
                ]);

                return ['body' => $result->body, 'http' => $result->http];
            },
        ];
    }

    /** @param array<string, mixed> $body */
    private static function wantsJson(array $body): bool
    {
        $type = $body['text']['format']['type'] ?? null;

        return $type === 'json_schema' || $type === 'json_object';
    }
}

final class InputItems
{
    public function __construct(private readonly SpaceXAI $client) {}

    /**
     * @param array{after?: string, limit?: int, order?: string} $query
     * @param array<string, mixed> $opts
     */
    public function list(string $id, array $query = [], array $opts = []): Page
    {
        $client = $this->client;
        $fetch = static function (array $pageQuery) use ($client, $id, $opts): array {
            $result = Transport::send($client, [
                'method' => 'GET',
                'path' => '/responses/' . Wire::encodePath($id) . '/input_items',
                'query' => $pageQuery,
                'opts' => $opts,
            ]);
            $body = Wire::requireRecord($result->payload, $result->http, 'Input item list');
            if (($body['object'] ?? null) !== 'list' || ! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
                throw new APIProtocolError('Input item list is missing object=list or data', [
                    ...requestIds($result->http),
                    'body' => $body,
                ]);
            }
            $body['http'] = $result->http;

            return $body;
        };

        return Page::start(
            static fn (): array => $fetch($query),
            static function (array $page) use ($query, $fetch): ?array {
                if (($page['has_more'] ?? false) && is_string($page['last_id'] ?? null) && $page['last_id'] !== '') {
                    return $fetch(array_merge($query, ['after' => $page['last_id']]));
                }

                return null;
            },
            static fn (array $page): array => $page['data'],
        );
    }
}
