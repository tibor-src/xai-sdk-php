<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Resources;

use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\Http\AbortSignal;
use TiborSrc\XaiSdkPhp\Page;
use TiborSrc\XaiSdkPhp\Porcelain;
use TiborSrc\XaiSdkPhp\Record;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\Transport;

use function TiborSrc\XaiSdkPhp\requestIds;

final class Batches
{
    public const DEFAULT_WAIT_INTERVAL_MS = 5_000;

    public const DEFAULT_WAIT_TIMEOUT_MS = 86_400_000;

    public readonly BatchRequests $requests;

    public function __construct(private readonly SpaceXAI $client)
    {
        $this->requests = new BatchRequests($client);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function create(array $body, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/batches',
            'body' => $body,
            'opts' => $opts,
        ]);

        return self::toBatch($result->payload, $result->http);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $opts
     */
    public function list(array $query = [], array $opts = []): Page
    {
        return self::tokenList($this->client, '/batches', $query, $opts, 'Batch list', 'batches', 'Batch list is missing batches');
    }

    /** @param array<string, mixed> $opts */
    public function get(string $batchId, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/batches/' . Wire::encodePath($batchId),
            'opts' => $opts,
        ]);

        return self::toBatch($result->payload, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function cancel(string $batchId, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/batches/' . Wire::encodePath($batchId) . ':cancel',
            'opts' => $opts,
        ]);

        return self::toBatch($result->payload, $result->http);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $opts
     */
    public function results(string $batchId, array $query = [], array $opts = []): Page
    {
        return self::tokenList(
            $this->client,
            '/batches/' . Wire::encodePath($batchId) . '/results',
            $query,
            $opts,
            'Batch result list',
            'results',
            'Batch result list is missing results',
        );
    }

    /**
     * Poll `get()` until the batch has requests and none are pending.
     * A batch created from `input_file_id` has no requests until it loads the file, so a batch
     * without requests counts as finished only once it is cancelled or expires.
     *
     * @param array<string, mixed> $opts
     */
    public function wait(string $batchId, array $opts = []): Record
    {
        $interval = is_int($opts['interval'] ?? null) ? $opts['interval'] : self::DEFAULT_WAIT_INTERVAL_MS;
        $timeout = is_int($opts['timeout'] ?? null) ? $opts['timeout'] : self::DEFAULT_WAIT_TIMEOUT_MS;
        $user = $opts['signal'] ?? null;
        $deadline = new AbortSignal();
        $deadline->deadline = microtime(true) + ($timeout / 1000);
        $deadline->timeoutMessage = "Batch {$batchId} did not finish within {$timeout}ms";
        $signal = AbortSignal::any([$user instanceof AbortSignal ? $user : null, $deadline]);
        while (true) {
            $batch = $this->get($batchId, ['signal' => $signal]);
            if (self::isFinished($batch)) {
                return $batch;
            }
            Transport::sleepMs($interval, $signal);
        }
    }

    private static function isFinished(Record $batch): bool
    {
        $state = $batch['state'] ?? null;
        if (! is_array($state)) {
            return false;
        }
        if (($state['num_pending'] ?? null) !== 0) {
            return false;
        }
        $requests = $state['num_requests'] ?? null;

        return (is_int($requests) && $requests > 0) || self::hasEnded($batch);
    }

    /** Cancelled, by you or by SpaceXAI, or past its expiry time. */
    private static function hasEnded(Record $batch): bool
    {
        if (($batch['cancel_time'] ?? null) !== null || ($batch['cancel_by_xai_message'] ?? null) !== null) {
            return true;
        }
        $expire = $batch['expire_time'] ?? null;
        if (! is_string($expire) || $expire === '') {
            return false;
        }
        try {
            $time = new \DateTimeImmutable($expire, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return false;
        }

        return $time->getTimestamp() <= time();
    }

    private static function toBatch(mixed $payload, \TiborSrc\XaiSdkPhp\HttpMeta $http): Record
    {
        $body = Wire::requireRecord($payload, $http, 'Batch');
        if (! is_string($body['batch_id'] ?? null) || $body['batch_id'] === '') {
            throw new APIProtocolError('Batch response is missing batch_id', [
                ...requestIds($http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $http);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $opts
     */
    private static function tokenList(
        SpaceXAI $client,
        string $path,
        array $query,
        array $opts,
        string $label,
        string $itemsKey,
        string $missing,
    ): Page {
        return Page::tokens(
            $query,
            static function (array $pageQuery) use ($client, $path, $opts, $label, $itemsKey, $missing): array {
                $result = Transport::send($client, [
                    'method' => 'GET',
                    'path' => $path,
                    'query' => $pageQuery,
                    'opts' => $opts,
                ]);
                $body = Wire::requireRecord($result->payload, $result->http, $label);
                if (! is_array($body[$itemsKey] ?? null) || ! array_is_list($body[$itemsKey])) {
                    throw new APIProtocolError($missing, [
                        ...requestIds($result->http),
                        'body' => $body,
                    ]);
                }
                $body['http'] = $result->http;

                return $body;
            },
            static fn (array $page): array => $page[$itemsKey],
        );
    }
}

final class BatchRequests
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function add(string $batchId, array $body, array $opts = []): Record
    {
        $signal = $opts['signal'] ?? null;
        $abort = $signal instanceof AbortSignal ? $signal : null;
        $requests = [];
        foreach ($body['batch_requests'] ?? [] as $request) {
            $requests[] = self::toWire(is_array($request) ? $request : [], $abort);
        }
        $body['batch_requests'] = $requests;
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/batches/' . Wire::encodePath($batchId) . '/requests',
            'body' => $body,
            'opts' => $opts,
        ]);

        return new Record(['http' => $result->http]);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $opts
     */
    public function list(string $batchId, array $query = [], array $opts = []): Page
    {
        $client = $this->client;

        return Page::tokens(
            $query,
            static function (array $pageQuery) use ($client, $batchId, $opts): array {
                $result = Transport::send($client, [
                    'method' => 'GET',
                    'path' => '/batches/' . Wire::encodePath($batchId) . '/requests',
                    'query' => $pageQuery,
                    'opts' => $opts,
                ]);
                $body = Wire::requireRecord($result->payload, $result->http, 'Batch request list');
                if (! is_array($body['batch_request_metadata'] ?? null) || ! array_is_list($body['batch_request_metadata'])) {
                    throw new APIProtocolError('Batch request list is missing batch_request_metadata', [
                        ...requestIds($result->http),
                        'body' => $body,
                    ]);
                }
                $body['http'] = $result->http;

                return $body;
            },
            static fn (array $page): array => $page['batch_request_metadata'],
        );
    }

    /** @param array<string, mixed> $request */
    private static function toWire(array $request, ?AbortSignal $signal): array
    {
        $batchRequest = $request['batch_request'] ?? null;
        if (! is_array($batchRequest) || ! array_key_exists('responses', $batchRequest) || ! is_array($batchRequest['responses'])) {
            return $request;
        }
        $responses = $batchRequest['responses'];
        if (array_key_exists('input', $responses)) {
            $responses['input'] = Porcelain::inlineBlobs($responses['input'], $signal);
        }
        $request['batch_request'] = ['responses' => Porcelain::applyCreateDefaults($responses)];

        return $request;
    }
}
