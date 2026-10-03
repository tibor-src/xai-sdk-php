<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk;

use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Support\Porcelain;
use XaiOfficial\Sdk\Support\UsageMapper;
use XaiOfficial\Sdk\Types\HttpMeta;

final class ModelResponse
{
    public readonly string $id;

    public readonly string $status;

    /** @var array<int, mixed> */
    public readonly array $output;

    public readonly mixed $incomplete_details;

    /** @var array<string, mixed> */
    public readonly array $usage;

    public readonly HttpMeta $http;

    /** @var array<string, mixed> */
    public readonly array $raw;

    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(mixed $payload, HttpMeta $http, array $extra = [])
    {
        if (! is_array($payload)) {
            throw new APIProtocolError('Response body must be a JSON object', array_merge(
                ErrorFactory::requestIds($http),
                ['body' => $payload],
            ));
        }
        if (! isset($payload['id'], $payload['status'], $payload['output'])
            || ! is_string($payload['id']) || $payload['id'] === ''
            || ! is_array($payload['output'])) {
            throw new APIProtocolError('Response body is missing id, status, or output', array_merge(
                ErrorFactory::requestIds($http),
                ['body' => $payload],
            ));
        }
        $this->id = $payload['id'];
        $this->status = (string) $payload['status'];
        $this->output = $payload['output'];
        $this->incomplete_details = $payload['incomplete_details'] ?? null;
        $this->usage = UsageMapper::mapUsage($payload['usage'] ?? null);
        $this->http = $http;
        $this->raw = array_merge($payload, $extra);
    }

    public function getParsed(): mixed
    {
        return Porcelain::parseJsonOutput($this->output, $this->status, false);
    }

    public function toText(): string
    {
        return Porcelain::toText($this->output);
    }

    /**
     * @return array<int, mixed>
     */
    public function toInput(): array
    {
        return Porcelain::toInput($this->output);
    }

    public function toJson(?callable $schema = null): mixed
    {
        $value = Porcelain::parseJsonOutput($this->output, $this->status, true);
        if ($schema === null) {
            return $value;
        }

        return $schema($value);
    }
}
