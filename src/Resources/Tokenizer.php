<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Resources;

use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Support\RecordHelper;
use XaiOfficial\Sdk\Types\RequestOptions;

final class Tokenizer
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function encode(array $params, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/tokenize-text',
            'body' => $params,
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Tokenize response');

        return array_merge($body, ['http' => $result['http']]);
    }
}
