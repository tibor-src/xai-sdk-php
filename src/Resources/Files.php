<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Resources;

use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Utils;
use XaiOfficial\Sdk\BinaryResponse;
use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Support\PagePromise;
use XaiOfficial\Sdk\Support\RecordHelper;
use XaiOfficial\Sdk\Types\RequestOptions;

final class Files
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function upload(array $params, ?RequestOptions $opts = null): array
    {
        $parts = [];
        if (isset($params['expires_after'])) {
            $parts[] = ['name' => 'expires_after', 'contents' => (string) $params['expires_after']];
        }
        if (isset($params['purpose'])) {
            $parts[] = ['name' => 'purpose', 'contents' => (string) $params['purpose']];
        }
        $file = $params['file'];
        $filename = $params['filename'] ?? null;
        if (is_string($file) && is_file($file)) {
            $parts[] = [
                'name' => 'file',
                'contents' => Utils::tryFopen($file, 'r'),
                'filename' => $filename ?? basename($file),
            ];
        } else {
            $parts[] = [
                'name' => 'file',
                'contents' => $file,
                'filename' => $filename,
            ];
        }

        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/files',
            'body' => new MultipartStream($parts),
            'opts' => $opts,
        ]);

        return $this->toFileObject($result);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function list(array $query = [], ?RequestOptions $opts = null): PagePromise
    {
        return PagePromise::tokenPages(
            array_merge($query, ['sort_by' => $query['sort_by'] ?? 'created_at']),
            fn ($pageQuery) => $this->fetchListPage($pageQuery, $opts),
            fn ($page) => $page['data'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/files/'.rawurlencode($id),
            'opts' => $opts,
        ]);

        return $this->toFileObject($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'DELETE',
            'path' => '/files/'.rawurlencode($id),
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'File delete response');
        if (! isset($body['id']) || ($body['deleted'] ?? false) !== true) {
            throw new APIProtocolError('File delete response is missing id or deleted=true', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function content(string $id, array $query = [], ?RequestOptions $opts = null): BinaryResponse
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/files/'.rawurlencode($id).'/content',
            'query' => $query,
            'binary' => true,
            'opts' => $opts,
        ]);

        return new BinaryResponse($result['body'], $result['http']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function createPublicUrl(string $id, array $params = [], ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/files/'.rawurlencode($id).'/public-url',
            'body' => $params,
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Public URL response');
        if (! isset($body['public_url'])) {
            throw new APIProtocolError('Public URL response is missing public_url', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function revokePublicUrl(string $id, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/files/'.rawurlencode($id).'/public-url/revoke',
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Public URL revoke response');
        if (! isset($body['id'], $body['revoked'])) {
            throw new APIProtocolError('Public URL revoke response is missing id or revoked', array_merge(
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
    private function fetchListPage(array $query, ?RequestOptions $opts): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/files',
            'query' => $query,
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'File list');
        if (! is_array($body['data'] ?? null)) {
            throw new APIProtocolError('File list is missing data', array_merge(
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
    private function toFileObject(array $result): array
    {
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'File');
        if (! isset($body['id']) || $body['id'] === '') {
            throw new APIProtocolError('File response is missing id', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }
}
