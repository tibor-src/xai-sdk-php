<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Resources;

use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Support\Porcelain;
use XaiOfficial\Sdk\Support\RecordHelper;
use XaiOfficial\Sdk\Support\UsageMapper;
use XaiOfficial\Sdk\Types\RequestOptions;

final class Images
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generate(array $body, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/images/generations',
            'body' => $body,
            'opts' => $opts,
        ]);

        return $this->toImageResponse($result);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function edit(array $body, ?RequestOptions $opts = null): array
    {
        $payload = Porcelain::inlineImageInputs($body);
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/images/edits',
            'body' => $payload,
            'opts' => $opts,
        ]);

        return $this->toImageResponse($result);
    }

    /**
     * @param array{http: mixed, payload: mixed} $result
     * @return array<string, mixed>
     */
    private function toImageResponse(array $result): array
    {
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Image response');
        if (! is_array($body['data'] ?? null)) {
            throw new APIProtocolError('Image response is missing data', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, [
            'usage' => UsageMapper::mapMediaUsage($body['usage'] ?? null),
            'http' => $result['http'],
        ]);
    }
}
