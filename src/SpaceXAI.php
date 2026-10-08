<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

use TiborSrc\XaiSdkPhp\Http\HeaderBag;
use TiborSrc\XaiSdkPhp\Resources\Account;
use TiborSrc\XaiSdkPhp\Resources\Batches;
use TiborSrc\XaiSdkPhp\Resources\Files;
use TiborSrc\XaiSdkPhp\Resources\Images;
use TiborSrc\XaiSdkPhp\Resources\ModelsResource;
use TiborSrc\XaiSdkPhp\Resources\Responses;
use TiborSrc\XaiSdkPhp\Resources\Tokenizer;
use TiborSrc\XaiSdkPhp\Resources\Videos;
use TiborSrc\XaiSdkPhp\Resources\VoiceResource;

final class SpaceXAI
{
    public readonly string $baseURL;

    public readonly int $timeout;

    public readonly int $idleTimeout;

    /** Maximum size of a buffered JSON response, and of each event in a response stream. Defaults to 32 MiB. */
    public readonly int $maxResponseBodyBytes;

    public readonly int $maxRetries;

    public readonly bool $retryBeforeOutput;

    /** @var array<string, string> */
    public readonly array $defaultHeaders;

    /** @var callable(\TiborSrc\XaiSdkPhp\Http\HttpRequest): \TiborSrc\XaiSdkPhp\Http\HttpResponse */
    public $fetch;

    /** @var null|callable(\TiborSrc\XaiSdkPhp\Http\HttpRequest): void */
    public $onRequest;

    /** @var null|callable(\TiborSrc\XaiSdkPhp\Http\HttpResponse): void */
    public $onResponse;

    public readonly Responses $responses;

    public readonly ModelsResource $models;

    public readonly Images $images;

    public readonly Videos $videos;

    public readonly Files $files;

    public readonly Batches $batches;

    public readonly VoiceResource $voice;

    public readonly Tokenizer $tokenizer;

    public readonly Account $account;

    /** @param array<string, mixed> $opts */
    public function __construct(array $opts = [])
    {
        $apiKey = $opts['apiKey'] ?? Env::apiKey();
        if (! is_string($apiKey) || $apiKey === '') {
            throw new \RuntimeException('SpaceXAI: apiKey is missing (set XAI_API_KEY or pass apiKey)');
        }
        Credentials::store($this, $apiKey);
        $base = is_string($opts['baseURL'] ?? null) ? $opts['baseURL'] : Transport::DEFAULT_BASE_URL;
        $this->baseURL = rtrim($base, '/');
        $this->timeout = is_int($opts['timeout'] ?? null) ? $opts['timeout'] : Transport::DEFAULT_TIMEOUT_MS;
        $this->idleTimeout = is_int($opts['idleTimeout'] ?? null) ? $opts['idleTimeout'] : Transport::DEFAULT_IDLE_TIMEOUT_MS;
        $this->maxResponseBodyBytes = is_int($opts['maxResponseBodyBytes'] ?? null) ? $opts['maxResponseBodyBytes'] : Transport::DEFAULT_MAX_RESPONSE_BODY_BYTES;
        $this->maxRetries = is_int($opts['maxRetries'] ?? null) ? $opts['maxRetries'] : Transport::DEFAULT_MAX_RETRIES;
        $this->retryBeforeOutput = (bool) ($opts['retryBeforeOutput'] ?? false);
        $headers = [];
        if (isset($opts['defaultHeaders']) && is_array($opts['defaultHeaders'])) {
            foreach (HeaderBag::from($opts['defaultHeaders'])->entries() as [$name, $value]) {
                $headers[$name] = $value;
            }
        }
        $this->defaultHeaders = $headers;
        $this->fetch = $opts['fetch'] ?? [Transport::class, 'curl'];
        $this->onRequest = $opts['onRequest'] ?? null;
        $this->onResponse = $opts['onResponse'] ?? null;
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

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'baseURL' => $this->baseURL,
            'timeout' => $this->timeout,
            'idleTimeout' => $this->idleTimeout,
            'maxResponseBodyBytes' => $this->maxResponseBodyBytes,
            'maxRetries' => $this->maxRetries,
            'retryBeforeOutput' => $this->retryBeforeOutput,
        ];
    }
}
