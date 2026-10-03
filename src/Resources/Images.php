<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Resources;

use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\Http\AbortSignal;
use TiborSrc\XaiSdkPhp\Porcelain;
use TiborSrc\XaiSdkPhp\Record;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\Transport;
use TiborSrc\XaiSdkPhp\Usage;

use function TiborSrc\XaiSdkPhp\requestIds;

final class Images
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function generate(array $body, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/images/generations',
            'body' => $body,
            'opts' => $opts,
        ]);

        return self::toImageResponse($result->payload, $result->http);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function edit(array $body, array $opts = []): Record
    {
        $signal = $opts['signal'] ?? null;
        $payload = Porcelain::inlineImageInputs($body, $signal instanceof AbortSignal ? $signal : null);
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/images/edits',
            'body' => $payload,
            'opts' => $opts,
        ]);

        return self::toImageResponse($result->payload, $result->http);
    }

    private static function toImageResponse(mixed $payload, \TiborSrc\XaiSdkPhp\HttpMeta $http): Record
    {
        $body = Wire::requireRecord($payload, $http, 'Image response');
        if (! is_array($body['data'] ?? null) || ! array_is_list($body['data'])) {
            throw new APIProtocolError('Image response is missing data', [
                ...requestIds($http),
                'body' => $body,
            ]);
        }
        $body['usage'] = Usage::mapMedia($body['usage'] ?? null);
        $body['http'] = $http;

        return new Record($body);
    }
}
