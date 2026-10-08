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

final class Videos
{
    public const DEFAULT_WAIT_INTERVAL_MS = 5_000;

    public const DEFAULT_WAIT_TIMEOUT_MS = 600_000;

    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function generate(array $body, array $opts = []): Record
    {
        $signal = $opts['signal'] ?? null;
        $payload = Porcelain::inlineMediaUrls($body, $signal instanceof AbortSignal ? $signal : null);
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/videos/generations',
            'body' => $payload,
            'opts' => $opts,
        ]);

        return self::toStart($result->payload, $result->http);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function edit(array $body, array $opts = []): Record
    {
        $signal = $opts['signal'] ?? null;
        $body['video'] = Porcelain::inlineVideoInput($body['video'] ?? null, $signal instanceof AbortSignal ? $signal : null);
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/videos/edits',
            'body' => $body,
            'opts' => $opts,
        ]);

        return self::toStart($result->payload, $result->http);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function extend(array $body, array $opts = []): Record
    {
        $signal = $opts['signal'] ?? null;
        $body['video'] = Porcelain::inlineVideoInput($body['video'] ?? null, $signal instanceof AbortSignal ? $signal : null);
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/videos/extensions',
            'body' => $body,
            'opts' => $opts,
        ]);

        return self::toStart($result->payload, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function get(string $requestId, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/videos/' . Wire::encodePath($requestId),
            'opts' => $opts,
        ]);
        $body = $result->http->status === 202 && $result->payload === null
            ? ['status' => 'pending']
            : Wire::requireRecord($result->payload, $result->http, 'Video response');
        if (! is_string($body['status'] ?? null)) {
            throw new APIProtocolError('Video response is missing status', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }
        $body['usage'] = Usage::mapMedia($body['usage'] ?? null);
        $body['http'] = $result->http;

        return new Record($body);
    }

    /** @param array<string, mixed> $opts */
    public function wait(string $requestId, array $opts = []): Record
    {
        $interval = is_int($opts['interval'] ?? null) ? $opts['interval'] : self::DEFAULT_WAIT_INTERVAL_MS;
        $timeout = is_int($opts['timeout'] ?? null) ? $opts['timeout'] : self::DEFAULT_WAIT_TIMEOUT_MS;
        $user = $opts['signal'] ?? null;
        $deadline = new AbortSignal();
        $deadline->deadline = microtime(true) + ($timeout / 1000);
        $deadline->timeoutMessage = "Video request {$requestId} did not finish within {$timeout}ms";
        $signal = AbortSignal::any([$user instanceof AbortSignal ? $user : null, $deadline]);
        while (true) {
            $result = $this->get($requestId, ['signal' => $signal]);
            if ($result->status !== 'pending') {
                return $result;
            }
            Transport::sleepMs($interval, $signal);
        }
    }

    private static function toStart(mixed $payload, \TiborSrc\XaiSdkPhp\HttpMeta $http): Record
    {
        $body = Wire::requireRecord($payload, $http, 'Video start response');
        if (! is_string($body['request_id'] ?? null) || $body['request_id'] === '') {
            throw new APIProtocolError('Video start response is missing request_id', [
                ...requestIds($http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $http);
    }
}
