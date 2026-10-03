<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Resources;

use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Support\RecordHelper;
use XaiOfficial\Sdk\Types\RequestOptions;

final class Account
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function apiKey(?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/api-key',
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'API key info');

        return array_merge($body, ['http' => $result['http']]);
    }
}
