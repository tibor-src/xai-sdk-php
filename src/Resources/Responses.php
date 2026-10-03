<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Resources;

use XaiOfficial\Sdk\Constants;
use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\Http\RetryBudget;
use XaiOfficial\Sdk\ModelResponse;
use XaiOfficial\Sdk\ResponseStream;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Support\PagePromise;
use XaiOfficial\Sdk\Support\Porcelain;
use XaiOfficial\Sdk\Support\RecordHelper;
use XaiOfficial\Sdk\Types\RequestOptions;

final class Responses
{
    public readonly InputItems $inputItems;

    public function __construct(private readonly SpaceXAI $client)
    {
        $this->inputItems = new InputItems($client);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function create(array $body, ?RequestOptions $opts = null): ModelResponse|ResponseStream
    {
        $input = Porcelain::inlineBlobs($body['input'] ?? '');
        $payload = Porcelain::applyCreateDefaults(array_merge($body, ['input' => $input]));

        if (! array_key_exists('stream', $body)) {
            return $this->streamToResponse($payload, $opts);
        }

        $stream = (bool) $body['stream'];
        $budget = $stream ? $this->retryBudget($opts) : null;
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/responses',
            'body' => $payload,
            'stream' => $stream,
            'retryServerErrors' => $budget !== null,
            'retryBudget' => $budget,
            'opts' => $opts,
        ]);

        if ($stream) {
            return new ResponseStream(
                $result['body'],
                $result['http'],
                $this->wantsJson($body),
                $budget,
            );
        }

        return new ModelResponse($result['payload'], $result['http']);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function compact(array $body, ?RequestOptions $opts = null): array
    {
        $input = Porcelain::inlineBlobs($body['input'] ?? '');
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/responses/compact',
            'body' => array_merge($body, ['input' => $input]),
            'opts' => $opts,
        ]);
        $compacted = RecordHelper::requireRecord($result['payload'], $result['http'], 'Compact response');
        if (! isset($compacted['id']) || ! is_array($compacted['output'] ?? null)) {
            throw new APIProtocolError('Compact response is missing id or output', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $compacted],
            ));
        }

        return array_merge($compacted, ['http' => $result['http']]);
    }

    public function get(string $id, ?RequestOptions $opts = null): ModelResponse
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/responses/'.rawurlencode($id),
            'opts' => $opts,
        ]);

        return new ModelResponse($result['payload'], $result['http']);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'DELETE',
            'path' => '/responses/'.rawurlencode($id),
            'opts' => $opts,
        ]);
        $body = $result['http']->status === 204
            ? ['id' => $id, 'object' => 'response', 'deleted' => true]
            : RecordHelper::requireRecord($result['payload'], $result['http'], 'Delete response');
        if (! isset($body['id']) || ($body['deleted'] ?? false) !== true) {
            throw new APIProtocolError('Delete response is missing id or deleted=true', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function streamToResponse(array $payload, ?RequestOptions $opts): ModelResponse
    {
        $streamOpts = $opts;
        $budget = $this->retryBudget($opts);
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/responses',
            'body' => array_merge($payload, ['stream' => true]),
            'stream' => true,
            'acceptJson' => true,
            'retryServerErrors' => $budget !== null,
            'retryBudget' => $budget,
            'opts' => $streamOpts,
        ]);
        if ($result['body'] === null) {
            return new ModelResponse($result['payload'], $result['http']);
        }

        return (new ResponseStream($result['body'], $result['http'], false, $budget))->done();
    }

    private function retryBudget(?RequestOptions $opts): ?RetryBudget
    {
        $retry = $opts?->retryBeforeOutput ?? $this->client->retryBeforeOutput;
        if (! $retry) {
            return null;
        }

        return new RetryBudget($opts?->maxRetries ?? $this->client->maxRetries);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function wantsJson(array $body): bool
    {
        $type = $body['text']['format']['type'] ?? null;

        return in_array($type, ['json_schema', 'json_object'], true);
    }
}

final class InputItems
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function list(string $id, array $query = [], ?RequestOptions $opts = null): PagePromise
    {
        return new PagePromise(
            function () use ($id, $query, $opts) {
                return $this->fetchPage($id, $query, $opts);
            },
            fn ($page) => ($page['has_more'] ?? false) && ! empty($page['last_id'])
                ? $this->fetchPage($id, array_merge($query, ['after' => $page['last_id']]), $opts)
                : null,
            fn ($page) => $page['data'] ?? [],
        );
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function fetchPage(string $id, array $query, ?RequestOptions $opts): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/responses/'.rawurlencode($id).'/input_items',
            'query' => $query,
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Input item list');
        if (($body['object'] ?? '') !== 'list' || ! is_array($body['data'] ?? null)) {
            throw new APIProtocolError('Input item list is missing object=list or data', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }
}
