<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Resources;

use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\Record;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\Transport;

use function TiborSrc\XaiSdkPhp\requestIds;

final class Account
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $opts */
    public function apiKey(array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/api-key',
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, 'API key info');
        if (! is_string($body['api_key_id'] ?? null)) {
            throw new APIProtocolError('API key info is missing api_key_id', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }
}
