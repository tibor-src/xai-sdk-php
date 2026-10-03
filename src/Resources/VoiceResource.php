<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Resources;

use GuzzleHttp\Psr7\MultipartStream;
use XaiOfficial\Sdk\BinaryResponse;
use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Http\HttpTransport;
use XaiOfficial\Sdk\SpaceXAI;
use XaiOfficial\Sdk\Support\PagePromise;
use XaiOfficial\Sdk\Support\RecordHelper;
use XaiOfficial\Sdk\Types\RequestOptions;

final class VoiceResource
{
    public readonly CustomVoices $custom;

    public readonly ClientSecrets $clientSecrets;

    public function __construct(private readonly SpaceXAI $client)
    {
        $this->custom = new CustomVoices($client);
        $this->clientSecrets = new ClientSecrets($client);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function speak(array $body, ?RequestOptions $opts = null): BinaryResponse|array
    {
        $binary = ($body['with_timestamps'] ?? false) !== true;
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/tts',
            'body' => $body,
            'binary' => $binary,
            'opts' => $opts,
        ]);
        if ($binary) {
            return new BinaryResponse($result['body'], $result['http']);
        }
        $speech = RecordHelper::requireRecord($result['payload'], $result['http'], 'Speech response');
        if (! isset($speech['audio'])) {
            throw new APIProtocolError('Speech response is missing audio', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $speech],
            ));
        }

        return array_merge($speech, ['http' => $result['http']]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function transcribe(array $body, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/stt',
            'body' => self::toFormData($body),
            'opts' => $opts,
        ]);
        $transcription = RecordHelper::requireRecord($result['payload'], $result['http'], 'Transcription');
        if (! isset($transcription['text'])) {
            throw new APIProtocolError('Transcription is missing text', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $transcription],
            ));
        }

        return array_merge($transcription, ['http' => $result['http']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function list(?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/tts/voices',
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Voice list');
        if (! is_array($body['voices'] ?? null)) {
            throw new APIProtocolError('Voice list is missing voices', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $voiceId, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/tts/voices/'.rawurlencode($voiceId),
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Voice');
        if (! isset($body['voice_id'])) {
            throw new APIProtocolError('Voice response is missing voice_id', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function toFormData(array $fields): MultipartStream
    {
        $file = $fields['file'] ?? null;
        unset($fields['file']);
        $parts = [];
        foreach ($fields as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if ($item !== null) {
                    $parts[] = ['name' => $name, 'contents' => (string) $item];
                }
            }
        }
        if ($file !== null) {
            $parts[] = ['name' => 'file', 'contents' => $file];
        }

        return new MultipartStream($parts);
    }
}

final class CustomVoices
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/custom-voices',
            'body' => VoiceResource::toFormData($body),
            'opts' => $opts,
        ]);

        return $this->toCustomVoice($result);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function list(array $query = [], ?RequestOptions $opts = null): PagePromise
    {
        return PagePromise::tokenPages(
            $query,
            fn ($pageQuery) => $this->fetchPage($pageQuery, $opts),
            fn ($page) => $page['voices'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $voiceId, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/custom-voices/'.rawurlencode($voiceId),
            'opts' => $opts,
        ]);

        return $this->toCustomVoice($result);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(string $voiceId, array $body, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'PATCH',
            'path' => '/custom-voices/'.rawurlencode($voiceId),
            'body' => $body,
            'opts' => $opts,
        ]);

        return $this->toCustomVoice($result);
    }

    /**
     * @return array{deleted: true, http: mixed}
     */
    public function delete(string $voiceId, ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'DELETE',
            'path' => '/custom-voices/'.rawurlencode($voiceId),
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Custom voice delete response');
        if (($body['deleted'] ?? false) !== true) {
            throw new APIProtocolError('Custom voice delete response is missing deleted=true', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    public function getAudio(string $voiceId, ?RequestOptions $opts = null): BinaryResponse
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/custom-voices/'.rawurlencode($voiceId).'/audio',
            'binary' => true,
            'opts' => $opts,
        ]);

        return new BinaryResponse($result['body'], $result['http']);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function fetchPage(array $query, ?RequestOptions $opts): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'GET',
            'path' => '/custom-voices',
            'query' => $query,
            'opts' => $opts,
        ]);
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Custom voice list');
        if (! is_array($body['voices'] ?? null)) {
            throw new APIProtocolError('Custom voice list is missing voices', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }

    /**
     * @param array{http: mixed, payload: mixed} $result
     * @return array<string, mixed>
     */
    private function toCustomVoice(array $result): array
    {
        $body = RecordHelper::requireRecord($result['payload'], $result['http'], 'Custom voice');
        if (! isset($body['voice_id'])) {
            throw new APIProtocolError('Custom voice response is missing voice_id', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $body],
            ));
        }

        return array_merge($body, ['http' => $result['http']]);
    }
}

final class ClientSecrets
{
    public function __construct(private readonly SpaceXAI $client)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(array $body = [], ?RequestOptions $opts = null): array
    {
        $result = HttpTransport::send($this->client, [
            'method' => 'POST',
            'path' => '/realtime/client_secrets',
            'body' => $body,
            'opts' => $opts,
        ]);
        $secret = RecordHelper::requireRecord($result['payload'], $result['http'], 'Client secret');
        if (! isset($secret['value'])) {
            throw new APIProtocolError('Client secret response is missing value', array_merge(
                ErrorFactory::requestIds($result['http']),
                ['body' => $secret],
            ));
        }

        return array_merge($secret, ['http' => $result['http']]);
    }
}
