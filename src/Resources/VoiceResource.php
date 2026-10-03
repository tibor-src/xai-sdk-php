<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Resources;

use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\BinaryResponse;
use TiborSrc\XaiSdkPhp\Http\Blob;
use TiborSrc\XaiSdkPhp\Http\FormData;
use TiborSrc\XaiSdkPhp\Page;
use TiborSrc\XaiSdkPhp\Record;
use TiborSrc\XaiSdkPhp\SpaceXAI;
use TiborSrc\XaiSdkPhp\Transport;

use function TiborSrc\XaiSdkPhp\requestIds;

final class VoiceResource
{
    public readonly CustomVoices $custom;

    public readonly ClientSecrets $clientSecrets;

    public function __construct(private readonly SpaceXAI $client)
    {
        $this->custom = new CustomVoices($client);
        $this->clientSecrets = new ClientSecrets($client);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function speak(array $body, array $opts = []): BinaryResponse|Record
    {
        $binary = ($body['with_timestamps'] ?? null) !== true;
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/tts',
            'body' => $body,
            'binary' => $binary,
            'opts' => $opts,
        ]);
        if ($binary) {
            return new BinaryResponse($result->body, $result->http);
        }
        $speech = Wire::requireRecord($result->payload, $result->http, 'Speech response');
        if (! is_string($speech['audio'] ?? null)) {
            throw new APIProtocolError('Speech response is missing audio', [
                ...requestIds($result->http),
                'body' => $speech,
            ]);
        }

        return Wire::withHttp($speech, $result->http);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function transcribe(array $body, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/stt',
            'body' => self::toFormData($body),
            'opts' => $opts,
        ]);
        $transcription = Wire::requireRecord($result->payload, $result->http, 'Transcription');
        if (! is_string($transcription['text'] ?? null)) {
            throw new APIProtocolError('Transcription is missing text', [
                ...requestIds($result->http),
                'body' => $transcription,
            ]);
        }

        return Wire::withHttp($transcription, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function list(array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/tts/voices',
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, 'Voice list');
        if (! is_array($body['voices'] ?? null) || ! array_is_list($body['voices'])) {
            throw new APIProtocolError('Voice list is missing voices', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function get(string $voiceId, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/tts/voices/' . Wire::encodePath($voiceId),
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, 'Voice');
        if (! is_string($body['voice_id'] ?? null)) {
            throw new APIProtocolError('Voice response is missing voice_id', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }

    /** @param array<string, mixed> $body */
    public static function toFormData(array $body): FormData
    {
        $form = new FormData();
        $file = $body['file'] ?? null;
        foreach ($body as $name => $value) {
            if ($name === 'file') {
                continue;
            }
            $items = is_array($value) && array_is_list($value) ? $value : [$value];
            foreach ($items as $item) {
                $form->append((string) $name, self::jsString($item));
            }
        }
        if ($file instanceof Blob) {
            $form->append('file', $file);
        }

        return $form;
    }

    public static function jsString(mixed $value): string
    {
        if ($value === true) {
            return 'true';
        }
        if ($value === false) {
            return 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            return '[object Object]';
        }

        return (string) $value;
    }
}

final class CustomVoices
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function create(array $body, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/custom-voices',
            'body' => VoiceResource::toFormData($body),
            'opts' => $opts,
        ]);

        return self::toVoice($result->payload, $result->http);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $opts
     */
    public function list(array $query = [], array $opts = []): Page
    {
        $client = $this->client;

        return Page::tokens(
            $query,
            static function (array $pageQuery) use ($client, $opts): array {
                $result = Transport::send($client, [
                    'method' => 'GET',
                    'path' => '/custom-voices',
                    'query' => $pageQuery,
                    'opts' => $opts,
                ]);
                $body = Wire::requireRecord($result->payload, $result->http, 'Custom voice list');
                if (! is_array($body['voices'] ?? null) || ! array_is_list($body['voices'])) {
                    throw new APIProtocolError('Custom voice list is missing voices', [
                        ...requestIds($result->http),
                        'body' => $body,
                    ]);
                }
                $body['http'] = $result->http;

                return $body;
            },
            static fn (array $page): array => $page['voices'],
        );
    }

    /** @param array<string, mixed> $opts */
    public function get(string $voiceId, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/custom-voices/' . Wire::encodePath($voiceId),
            'opts' => $opts,
        ]);

        return self::toVoice($result->payload, $result->http);
    }

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function update(string $voiceId, array $body, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'PATCH',
            'path' => '/custom-voices/' . Wire::encodePath($voiceId),
            'body' => $body,
            'opts' => $opts,
        ]);

        return self::toVoice($result->payload, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function delete(string $voiceId, array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'DELETE',
            'path' => '/custom-voices/' . Wire::encodePath($voiceId),
            'opts' => $opts,
        ]);
        $body = Wire::requireRecord($result->payload, $result->http, 'Custom voice delete response');
        if (($body['deleted'] ?? null) !== true) {
            throw new APIProtocolError('Custom voice delete response is missing deleted=true', [
                ...requestIds($result->http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $result->http);
    }

    /** @param array<string, mixed> $opts */
    public function getAudio(string $voiceId, array $opts = []): BinaryResponse
    {
        $result = Transport::send($this->client, [
            'method' => 'GET',
            'path' => '/custom-voices/' . Wire::encodePath($voiceId) . '/audio',
            'binary' => true,
            'opts' => $opts,
        ]);

        return new BinaryResponse($result->body, $result->http);
    }

    private static function toVoice(mixed $payload, \TiborSrc\XaiSdkPhp\HttpMeta $http): Record
    {
        $body = Wire::requireRecord($payload, $http, 'Custom voice');
        if (! is_string($body['voice_id'] ?? null)) {
            throw new APIProtocolError('Custom voice response is missing voice_id', [
                ...requestIds($http),
                'body' => $body,
            ]);
        }

        return Wire::withHttp($body, $http);
    }
}

final class ClientSecrets
{
    public function __construct(private readonly SpaceXAI $client) {}

    /** @param array<string, mixed> $body
     * @param array<string, mixed> $opts
     */
    public function create(array $body = [], array $opts = []): Record
    {
        $result = Transport::send($this->client, [
            'method' => 'POST',
            'path' => '/realtime/client_secrets',
            'body' => $body,
            'opts' => $opts,
        ]);
        $secret = Wire::requireRecord($result->payload, $result->http, 'Client secret');
        if (! is_string($secret['value'] ?? null)) {
            throw new APIProtocolError('Client secret response is missing value', [
                ...requestIds($result->http),
                'body' => $secret,
            ]);
        }

        return Wire::withHttp($secret, $result->http);
    }
}
