<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Resources;

use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\Record;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\Transport;

use function TiborSrc\XaiSdkPhp\requestIds;

final class Tokenizer
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $params
     * @param array<string, mixed> $opts
     */
    public function encode(array $params, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/tokenize-text',
            'body' => $params,
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, 'Tokenize response');
        if (! is_array($body['token_ids'] ?? null) || ! array_is_list($body['token_ids'])) {
            throw new APIProtocolError('Tokenize response is missing token_ids', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }
}
