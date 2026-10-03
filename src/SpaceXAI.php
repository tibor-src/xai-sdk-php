<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk;

use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use XaiOfficial\Sdk\Resources\Account;
use XaiOfficial\Sdk\Resources\Batches;
use XaiOfficial\Sdk\Resources\Files;
use XaiOfficial\Sdk\Resources\Images;
use XaiOfficial\Sdk\Resources\ModelsResource;
use XaiOfficial\Sdk\Resources\Responses;
use XaiOfficial\Sdk\Resources\Tokenizer;
use XaiOfficial\Sdk\Resources\Videos;
use XaiOfficial\Sdk\Resources\VoiceResource;
use XaiOfficial\Sdk\Types\ClientOptions;

final class SpaceXAI
{
    public readonly string $baseURL;

    public readonly int $timeout;

    public readonly int $idleTimeout;

    public readonly int $maxResponseBodyBytes;

    public readonly int $maxRetries;

    public readonly bool $retryBeforeOutput;

    /** @var array<string, string> */
    public readonly array $defaultHeaders;

    public readonly ClientInterface $httpClient;

    /** @var callable|null */
    public readonly mixed $onRequest;

    /** @var callable|null */
    public readonly mixed $onResponse;

    public readonly Responses $responses;

    public readonly ModelsResource $models;

    public readonly Images $images;

    public readonly Videos $videos;

    public readonly Files $files;

    public readonly Batches $batches;

    public readonly VoiceResource $voice;

    public readonly Tokenizer $tokenizer;

    public readonly Account $account;

    private string $apiKey;

    /**
     * @param array<string, mixed>|ClientOptions $options
     */
    public function __construct(array|ClientOptions $options = [])
    {
        $opts = $options instanceof ClientOptions ? $options : ClientOptions::fromArray($options);
        $apiKey = $opts->apiKey ?? getenv('XAI_API_KEY') ?: null;
        if ($apiKey === null || $apiKey === '') {
            throw new \InvalidArgumentException('SpaceXAI: apiKey is missing (set XAI_API_KEY or pass apiKey)');
        }
        $this->apiKey = $apiKey;
        $this->baseURL = rtrim($opts->baseURL ?? Constants::DEFAULT_BASE_URL, '/');
        $this->timeout = $opts->timeout ?? Constants::DEFAULT_TIMEOUT_MS;
        $this->idleTimeout = $opts->idleTimeout ?? Constants::DEFAULT_IDLE_TIMEOUT_MS;
        $this->maxResponseBodyBytes = $opts->maxResponseBodyBytes ?? Constants::DEFAULT_MAX_RESPONSE_BODY_BYTES;
        $this->maxRetries = $opts->maxRetries ?? Constants::DEFAULT_MAX_RETRIES;
        $this->retryBeforeOutput = $opts->retryBeforeOutput;
        $this->defaultHeaders = $opts->defaultHeaders;
        $this->httpClient = $opts->httpClient ?? new Client();
        $this->onRequest = $opts->onRequest;
        $this->onResponse = $opts->onResponse;

        $this->responses = new Responses($this);
        $this->models = new ModelsResource($this);
        $this->images = new Images($this);
        $this->videos = new Videos($this);
        $this->files = new Files($this);
        $this->batches = new Batches($this);
        $this->voice = new VoiceResource($this);
        $this->tokenizer = new Tokenizer($this);
        $this->account = new Account($this);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }
}
