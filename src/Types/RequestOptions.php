<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Types;

final class RequestOptions
{
    public function __construct(
        public readonly ?int $timeout = null,
        public readonly ?int $idleTimeout = null,
        public readonly ?int $maxResponseBodyBytes = null,
        public readonly ?int $maxRetries = null,
        public readonly ?bool $retryBeforeOutput = null,
        /** @var array<string, string> */
        public readonly array $headers = [],
        /** @var array{body?: bool}|null */
        public readonly ?array $http = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(?array $options): ?self
    {
        if ($options === null) {
            return null;
        }

        return new self(
            timeout: isset($options['timeout']) ? (int) $options['timeout'] : null,
            idleTimeout: isset($options['idleTimeout']) ? (int) $options['idleTimeout'] : null,
            maxResponseBodyBytes: isset($options['maxResponseBodyBytes']) ? (int) $options['maxResponseBodyBytes'] : null,
            maxRetries: isset($options['maxRetries']) ? (int) $options['maxRetries'] : null,
            retryBeforeOutput: isset($options['retryBeforeOutput']) ? (bool) $options['retryBeforeOutput'] : null,
            headers: is_array($options['headers'] ?? null) ? $options['headers'] : [],
            http: is_array($options['http'] ?? null) ? $options['http'] : null,
        );
    }
}
