<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Resources;

use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\BinaryResponse;
use TiborSrc\XaiSdkPhp\Http\Blob;
use TiborSrc\XaiSdkPhp\Http\FormData;
use TiborSrc\XaiSdkPhp\Page;
use TiborSrc\XaiSdkPhp\Record;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\Transport;

use function TiborSrc\XaiSdkPhp\requestIds;

final class Files
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $params
     * @param array<string, mixed> $opts
     */
    public function upload(array $params, array $opts = []): Record
    {
        $form = new FormData();
        if (array_key_exists('expires_after', $params) && $params['expires_after'] !== null) {
            $form->append('expires_after', self::jsString($params['expires_after']));
        }
        if (array_key_exists('purpose', $params) && $params['purpose'] !== null) {
            $form->append('purpose', self::jsString($params['purpose']));
        }
        $file = $params['file'] ?? null;
        if (! $file instanceof Blob) {
            throw new \TypeError('file must be a Blob or File');
        }
        if (! array_key_exists('filename', $params)) {
            $form->append('file', $file);
        } else {
            $filename = $params['filename'];
            $form->append('file', $file, is_string($filename) ? $filename : null);
        }
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/files',
            'body' => $form,
            'opts' => $opts,
        ]);

        return self::toFile($result->payload, $result->http);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $opts
     */
    public function list(array $query = [], array $opts = []): Page
    {
        $client = $this->client;

        return Page::tokens(
            $query,
            static function (array $pageQuery) use ($client, $opts): array {
                $result = Transport::send($client, [
                    'method' => 'GET',
                    'path' => '/files',
                    'query' => array_merge($pageQuery, ['sort_by' => $pageQuery['sort_by'] ?? 'created_at']),
                    'opts' => $opts,
                ]);
                $body = Wire::requireRecord($result->payload, $result->http, 'File list');
                if (! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
                    throw new APIProtocolError('File list is missing data', [
                        ...requestIds($result->http),
                        'body' => $body,
                    ]);
                }
                $body['http'] = $result->http;

                return $body;
            },
            static fn (array $page): array => $page['data'],
        );
    }

    /** @param array<string, mixed> $opts */
    public function get(string $id, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/files/' . Wire::encodePath($id),
            'opts' => $opts,
        ]);

        return self::toFile($result->payload, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function delete(string $id, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'DELETE',
            'path' => '/files/' . Wire::encodePath($id),
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, 'File delete response');
        if (! is_string($body['id'] ?? null) || ($body['deleted'] ?? null) !== true) {
            throw new APIProtocolError('File delete response is missing id or deleted=true', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }

    /** @param array<string, string|int|float|null> $query
     * @param array<string, mixed> $opts
     */
    public function content(string $id, array $query = [], array $opts = []): BinaryResponse
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/files/' . Wire::encodePath($id) . '/content',
            'query' => $query,
            'binary' => true,
            'opts' => $opts,
        ]);

        return new BinaryResponse($result->body, $result->http);
    }

    /** @param array<string, mixed> $params
     * @param array<string, mixed> $opts
     */
    public function createPublicUrl(string $id, array $params = [], array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/files/' . Wire::encodePath($id) . '/public-url',
            'body' => $params,
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, 'Public URL response');
        if (! is_string($body['public_url'] ?? null)) {
            throw new APIProtocolError('Public URL response is missing public_url', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function revokePublicUrl(string $id, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/files/' . Wire::encodePath($id) . '/public-url/revoke',
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, 'Public URL revoke response');
        if (! is_string($body['id'] ?? null) || ! is_bool($body['revoked'] ?? null)) {
            throw new APIProtocolError('Public URL revoke response is missing id or revoked', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }

    private static function toFile(mixed $payload, \TiborSrc\XaiSdkPhp\HttpMeta $http): Record
    {
        $body = Wire::requireRecord($payload, $http, 'File');
        if (! is_string($body['id'] ?? null) || $body['id'] === '') {
            throw new APIProtocolError('File response is missing id', [
                ...requestIds($http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $http);
    }

    private static function jsString(mixed $value): string
    {
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }
}
