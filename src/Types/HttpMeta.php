<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Types;

final class HttpMeta
{
    public function __construct(
        public readonly int $status,
        /** @var array<string, string|string[]> */
        public readonly array $headers,
        public readonly ?string $requestId,
        public readonly string $clientRequestId,
        public mixed $body = null,
    ) {
    }

    public function header(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $lower) {
                return is_array($value) ? ($value[0] ?? null) : (string) $value;
            }
        }

        return null;
    }
}
