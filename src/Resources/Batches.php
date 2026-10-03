<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Resources;

use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Errors\TimeoutError;
use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Support\PagePromise;
use XaiOfficial\Sdk\Support\Porcelain;
use XaiOfficial\Sdk\Support\RecordHelper;
use XaiOfficial\Sdk\Types\RequestOptions;

final class Batches
{
    private const DEFAULT_WAIT_INTERVAL_MS = 5_000;

    private const DEFAULT_WAIT_TIMEOUT_MS = 86_400_000;

    public readonly BatchRequests $requests;

    public function __construct(private readonly SpaceXAI $client)
    {
        $this->requests = new BatchRequests($client);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/batches',
            'body' => $body,
            'opts' => $opts,
        ]);

        return $this->toBatch($result);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function list(array $query = [], ?RequestOptions $opts = null): PagePromise
    {
        return PagePromise::tokenPages(
            $query,
            fn ($pageQuery) => $this->fetchListPage($pageQuery, $opts),
            fn ($page) => $page['batches'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $batchId, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/batches/'.rawurlencode($batchId),
            'opts' => $opts,
        ]);

        return $this->toBatch($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $batchId, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/batches/'.rawurlencode($batchId).':cancel',
            'opts' => $opts,
        ]);

        return $this->toBatch($result);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function results(string $batchId, array $query = [], ?RequestOptions $opts = null): PagePromise
    {
        return PagePromise::tokenPages(
            $query,
            fn ($pageQuery) => $this->fetchResultsPage($batchId, $pageQuery, $opts),
            fn ($page) => $page['results'] ?? [],
        );
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    public function wait(string $batchId, array $opts = []): array
    {
        $interval = (int) ($opts['interval'] ?? self::DEFAULT_WAIT_INTERVAL_MS);
        $timeout = (int) ($opts['timeout'] ?? self::DEFAULT_WAIT_TIMEOUT_MS);
        $deadline = microtime(true) + ($timeout / 1000);

        while (true) {
            $batch = $this->get($batchId);
            if (($batch['state']['num_pending'] ?? 1) === 0) {
                return $batch;
            }
            if (microtime(true) >= $deadline) {
                throw new TimeoutError("Batch {$batchId} did not finish within {$timeout}ms");
            }
            HttpTransport::sleep($interval);
        }
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function fetchListPage(array $query, ?RequestOptions $opts): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/batches',
            'query' => $query,
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Batch list');
        if (! is_array($body['batches'] ?? null)) {
            throw new APIProtocolError('Batch list is missing batches', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function fetchResultsPage(string $batchId, array $query, ?RequestOptions $opts): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/batches/'.rawurlencode($batchId).'/results',
            'query' => $query,
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Batch result list');
        if (! is_array($body['results'] ?? null)) {
            throw new APIProtocolError('Batch result list is missing results', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @param array{http: mixed, payload: mixed} $result
     * @return array<string, mixed>
     */
    private function toBatch(array $result): array
    {
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Batch');
        if (! isset($body['batch_id']) || $body['batch_id'] === '') {
            throw new APIProtocolError('Batch response is missing batch_id', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }
}

final class BatchRequests
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{http: mixed}
     */
    public function add(string $batchId, array $body, ?RequestOptions $opts = null): array
    {
        $batchRequests = [];
        foreach ($body['batch_requests'] ?? [] as $request) {
            $batchRequests[] = $this->toWireRequest($request);
        }
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/batches/'.rawurlencode($batchId).'/requests',
            'body' => array_merge($body, ['batch_requests' => $batchRequests]),
            'opts' => $opts,
        ]);

        return ['http' => $result['http']];
    }

    /**
     * @param array<string, mixed> $query
     */
    public function list(string $batchId, array $query = [], ?RequestOptions $opts = null): PagePromise
    {
        return PagePromise::tokenPages(
            $query,
            fn ($pageQuery) => $this->fetchPage($batchId, $pageQuery, $opts),
            fn ($page) => $page['batch_request_metadata'] ?? [],
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function fetchPage(string $batchId, array $query, ?RequestOptions $opts): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/batches/'.rawurlencode($batchId).'/requests',
            'query' => $query,
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Batch request list');
        if (! is_array($body['batch_request_metadata'] ?? null)) {
            throw new APIProtocolError('Batch request list is missing batch_request_metadata', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function toWireRequest(array $request): array
    {
        $batchRequest = $request['batch_request'] ?? [];
        if (! isset($batchRequest['responses'])) {
            return $request;
        }
        $responses = $batchRequest['responses'];
        $input = Porcelain::inlineBlobs($responses['input'] ?? '');
        $batchRequest['responses'] = Porcelain::applyCreateDefaults(array_merge($responses, ['input' => $input]));

        return array_merge($request, ['batch_request' => $batchRequest]);
    }
}
