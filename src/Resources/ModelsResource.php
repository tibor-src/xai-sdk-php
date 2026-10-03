<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Resources;

use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\Record;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\Transport;

use function TiborSrc\XaiSdkPhp\requestIds;

final class ModelsResource
{
    public readonly LanguageModels $language;

    public readonly ImageModels $image;

    public readonly VideoModels $video;

    public function __construct(private readonly SpaceXAI $client)
    {
        $this->language = new LanguageModels($client);
        $this->image = new ImageModels($client);
        $this->video = new VideoModels($client);
    }

    /** @param array<string, mixed> $opts */
    public function list(array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/models',
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, 'Model list');
        if (($body['object'] ?? null) !== 'list' || ! Wire::modelsHaveIds($body['data'] ?? null)) {
            throw new APIProtocolError('Model list is missing object=list or data', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function get(string $id, array $opts = []): Record
    {
        return self::one($this->client, '/models/' . Wire::encodePath($id), $opts, 'Model', 'Model response is missing id');
    }

    /** @param array<string, mixed> $opts */
    public static function one(SpaceXAI $client, string $path, array $opts, string $label, string $missing): Record
    {
        $result = Transport::send($client, [
            'method' => 'GET',
            'path' => $path,
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, $label);
        if (! is_string($body['id'] ?? null) || $body['id'] === '') {
            throw new APIProtocolError($missing, [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public static function collection(SpaceXAI $client, string $path, array $opts, string $label, string $missing): Record
    {
        $result = Transport::send($client, [
            'method' => 'GET',
            'path' => $path,
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, $label);
        if (! Wire::modelsHaveIds($body['models'] ?? null)) {
            throw new APIProtocolError($missing, [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }
}

final class LanguageModels
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $opts */
    public function list(array $opts = []): Record
    {
        return ModelsResource::collection($this->client, '/language-models', $opts, 'Language model list', 'Language model list is missing models');
    }

    /** @param array<string, mixed> $opts */
    public function get(string $id, array $opts = []): Record
    {
        return ModelsResource::one($this->client, '/language-models/' . Wire::encodePath($id), $opts, 'Language model', 'Language model response is missing id');
    }
}

final class ImageModels
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $opts */
    public function list(array $opts = []): Record
    {
        return ModelsResource::collection($this->client, '/image-generation-models', $opts, 'Image model list', 'Image model list is missing models');
    }

    /** @param array<string, mixed> $opts */
    public function get(string $id, array $opts = []): Record
    {
        return ModelsResource::one($this->client, '/image-generation-models/' . Wire::encodePath($id), $opts, 'Image model', 'Image model response is missing id');
    }
}

final class VideoModels
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $opts */
    public function list(array $opts = []): Record
    {
        return ModelsResource::collection($this->client, '/video-generation-models', $opts, 'Video model list', 'Video model list is missing models');
    }

    /** @param array<string, mixed> $opts */
    public function get(string $id, array $opts = []): Record
    {
        return ModelsResource::one($this->client, '/video-generation-models/' . Wire::encodePath($id), $opts, 'Video model', 'Video model response is missing id');
    }
}
