<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Types;

use Psr\Http\Client\ClientInterface;

/**
 * @phpstan-type RequestHook callable(\Psr\Http\Message\RequestInterface): void
 * @phpstan-type ResponseHook callable(\Psr\Http\Message\ResponseInterface): void
 */
final class ClientOptions
{
    public function __construct(
        public readonly ?string $apiKey = null,
        public readonly ?string $baseURL = null,
        public readonly ?int $timeout = null,
        public readonly ?int $idleTimeout = null,
        public readonly ?int $maxResponseBodyBytes = null,
        public readonly ?int $maxRetries = null,
        public readonly bool $retryBeforeOutput = false,
        /** @var array<string, string> */
        public readonly array $defaultHeaders = [],
        public readonly ?ClientInterface $httpClient = null,
        /** @var callable|null */
        public readonly mixed $onRequest = null,
        /** @var callable|null */
        public readonly mixed $onResponse = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            apiKey: isset($options['apiKey']) ? (string) $options['apiKey'] : null,
            baseURL: isset($options['baseURL']) ? (string) $options['baseURL'] : null,
            timeout: isset($options['timeout']) ? (int) $options['timeout'] : null,
            idleTimeout: isset($options['idleTimeout']) ? (int) $options['idleTimeout'] : null,
            maxResponseBodyBytes: isset($options['maxResponseBodyBytes']) ? (int) $options['maxResponseBodyBytes'] : null,
            maxRetries: isset($options['maxRetries']) ? (int) $options['maxRetries'] : null,
            retryBeforeOutput: (bool) ($options['retryBeforeOutput'] ?? false),
            defaultHeaders: is_array($options['defaultHeaders'] ?? null) ? $options['defaultHeaders'] : [],
            httpClient: $options['httpClient'] ?? null,
            onRequest: $options['onRequest'] ?? null,
            onResponse: $options['onResponse'] ?? null,
        );
    }
}
