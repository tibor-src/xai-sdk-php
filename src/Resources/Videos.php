<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Resources;

use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Errors\TimeoutError;
use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Support\Porcelain;
use XaiOfficial\Sdk\Support\RecordHelper;
use XaiOfficial\Sdk\Support\UsageMapper;
use XaiOfficial\Sdk\Types\RequestOptions;

final class Videos
{
    private const DEFAULT_WAIT_INTERVAL_MS = 5_000;

    private const DEFAULT_WAIT_TIMEOUT_MS = 600_000;

    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function generate(array $body, ?RequestOptions $opts = null): array
    {
        $payload = $this->inlineImages($body);
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/videos/generations',
            'body' => $payload,
            'opts' => $opts,
        ]);

        return $this->toStartResponse($result);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function edit(array $body, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/videos/edits',
            'body' => array_merge($body, ['video' => Porcelain::inlineVideoInput($body['video'])]),
            'opts' => $opts,
        ]);

        return $this->toStartResponse($result);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function extend(array $body, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/videos/extensions',
            'body' => array_merge($body, ['video' => Porcelain::inlineVideoInput($body['video'])]),
            'opts' => $opts,
        ]);

        return $this->toStartResponse($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $requestId, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/videos/'.rawurlencode($requestId),
            'opts' => $opts,
        ]);
        $body = $result['http']->status === 202 && $result['payload'] === null
            ? ['status' => 'pending']
            : RecordHelper::requireRecord($result['payload'], $result['http'], 'Video response');
        if (! isset($body['status'])) {
            throw new APIProtocolError('Video response is missing status', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, [
            'usage' => UsageMapper::mapMediaUsage($body['usage'] ?? null),
            'http' => $result['http'],
        ]);
    }

    /**
     * @param array<string, mixed> $opts
     * @return array<string, mixed>
     */
    public function wait(string $requestId, array $opts = []): array
    {
        $interval = (int) ($opts['interval'] ?? self::DEFAULT_WAIT_INTERVAL_MS);
        $timeout = (int) ($opts['timeout'] ?? self::DEFAULT_WAIT_TIMEOUT_MS);
        $deadline = microtime(true) + ($timeout / 1000);

        while (true) {
            $result = $this->get($requestId);
            if (($result['status'] ?? '') !== 'pending') {
                return $result;
            }
            if (microtime(true) >= $deadline) {
                throw new TimeoutError("Video request {$requestId} did not finish within {$timeout}ms");
            }
            HttpTransport::sleep($interval);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function inlineImages(array $body): array
    {
        $out = $body;
        if (isset($body['image'])) {
            $out['image'] = Porcelain::inlineImageInput($body['image']);
        }
        if (isset($body['reference_images']) && is_array($body['reference_images'])) {
            $out['reference_images'] = array_map(
                static fn ($item) => Porcelain::inlineImageInput($item),
                $body['reference_images'],
            );
        }
        if (isset($body['keyframes']) && is_array($body['keyframes'])) {
            $out['keyframes'] = [];
            foreach ($body['keyframes'] as $keyframe) {
                $out['keyframes'][] = array_merge($keyframe, [
                    'image' => Porcelain::inlineImageInput($keyframe['image']),
                ]);
            }
        }

        return $out;
    }

    /**
     * @param array{http: mixed, payload: mixed} $result
     * @return array<string, mixed>
     */
    private function toStartResponse(array $result): array
    {
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Video start response');
        if (! isset($body['request_id']) || $body['request_id'] === '') {
            throw new APIProtocolError('Video start response is missing request_id', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }
}
