<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Resources;

use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Support\RecordHelper;
use XaiOfficial\Sdk\Types\RequestOptions;

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

    /**
     * @return array<string, mixed>
     */
    public function list(?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/models',
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Model list');
        if (($body['object'] ?? '') !== 'list' || ! is_array($body['data'] ?? null)) {
            throw new APIProtocolError('Model list is missing object=list or data', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/models/'.rawurlencode($id),
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Model');
        if (! isset($body['id']) || $body['id'] === '') {
            throw new APIProtocolError('Model response is missing id', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }
}

final class LanguageModels
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/language-models',
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Language model list');
        if (! is_array($body['models'] ?? null)) {
            throw new APIProtocolError('Language model list is missing models', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/language-models/'.rawurlencode($id),
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Language model');
        if (! isset($body['id']) || $body['id'] === '') {
            throw new APIProtocolError('Language model response is missing id', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }
}

final class ImageModels
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/image-generation-models',
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Image model list');
        if (! is_array($body['models'] ?? null)) {
            throw new APIProtocolError('Image model list is missing models', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/image-generation-models/'.rawurlencode($id),
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Image model');
        if (! isset($body['id']) || $body['id'] === '') {
            throw new APIProtocolError('Image model response is missing id', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }
}

final class VideoModels
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/video-generation-models',
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Video model list');
        if (! is_array($body['models'] ?? null)) {
            throw new APIProtocolError('Video model list is missing models', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/video-generation-models/'.rawurlencode($id),
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Video model');
        if (! isset($body['id']) || $body['id'] === '') {
            throw new APIProtocolError('Video model response is missing id', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }
}
