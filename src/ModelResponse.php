<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

final class ModelResponse
{
    /** @var list<string> */
    private const SKIP = ['__proto__', 'constructor', 'prototype', 'parsed'];

    /** @var list<string> */
    private const OWN = ['id', 'status', 'output', 'incomplete_details', 'usage', 'http', 'raw'];

    public string $id;

    public string $status;

    /** @var list<array<string, mixed>> */
    public array $output;

    public mixed $incomplete_details;

    public Record $usage;

    public HttpMeta $http;

    /** @var array<string, mixed> */
    public readonly array $raw;

    /** @var array<string, mixed> */
    private array $extras = [];

    public function __construct(mixed $payload, HttpMeta $http)
    {
        if (! is_record($payload)) {
            throw new APIProtocolError('Response body must be a JSON object', [
                ...requestIds($http),
                'body' => $payload,
            ]);
        }
        if (! is_string($payload['id'] ?? null) || $payload['id'] === '' || ! is_string($payload['status'] ?? null) || ! is_list_array($payload['output'] ?? null)) {
            throw new APIProtocolError('Response body is missing id, status, or output', [
                ...requestIds($http),
                'body' => $payload,
            ]);
        }
        $this->id = $payload['id'];
        $this->status = $payload['status'];
        $this->output = $payload['output'];
        $this->incomplete_details = $payload['incomplete_details'] ?? null;
        $this->usage = Usage::map($payload['usage'] ?? null);
        $this->http = $http;
        $this->raw = $payload;
        foreach ($payload as $key => $value) {
            if (in_array($key, self::SKIP, true) || in_array($key, self::OWN, true) || method_exists($this, (string) $key)) {
                continue;
            }
            $this->extras[(string) $key] = $value;
        }
    }

    public function __get(string $name): mixed
    {
        if ($name === 'parsed') {
            return Porcelain::parseJsonOutput($this->output, $this->status, false);
        }

        return $this->extras[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        if ($name === 'parsed') {
            return true;
        }

        return array_key_exists($name, $this->extras) && $this->extras[$name] !== null;
    }

    public function toText(): string
    {
        return Porcelain::toText($this->output);
    }

    /** @return list<array<string, mixed>> */
    public function toInput(): array
    {
        return Porcelain::toInput($this->output);
    }

    public function toJson(mixed $schema = null): mixed
    {
        $value = Porcelain::parseJsonOutput($this->output, $this->status, true);
        if ($schema === null) {
            return $value;
        }

        return self::validate($schema, $value);
    }

    private static function validate(mixed $schema, mixed $value): mixed
    {
        $validate = null;
        if (is_array($schema) && isset($schema['~standard']['validate']) && is_callable($schema['~standard']['validate'])) {
            $validate = $schema['~standard']['validate'];
        } elseif (is_object($schema) && isset($schema->{'~standard'}) && is_array($schema->{'~standard'}) && isset($schema->{'~standard'}['validate']) && is_callable($schema->{'~standard'}['validate'])) {
            $validate = $schema->{'~standard'}['validate'];
        } elseif (is_object($schema) && isset($schema->{'~standard'}->validate) && is_callable($schema->{'~standard'}->validate)) {
            $validate = $schema->{'~standard'}->validate;
        }
        if ($validate === null) {
            throw new \TypeError('toJson() needs a schema that validates synchronously');
        }
        $result = $validate($value);
        if ($result instanceof \Generator || (is_object($result) && method_exists($result, 'then'))) {
            throw new \TypeError('toJson() needs a schema that validates synchronously');
        }
        $issues = is_array($result) ? ($result['issues'] ?? null) : (is_object($result) ? ($result->issues ?? null) : null);
        if (self::truthy($issues)) {
            $list = is_array($issues) ? $issues : [$issues];
            $rendered = [];
            foreach ($list as $issue) {
                $rendered[] = self::formatIssue($issue);
            }
            throw new \RuntimeException("Structured output doesn't match the schema: " . implode('; ', $rendered));
        }
        if (is_array($result) && array_key_exists('value', $result)) {
            return $result['value'];
        }
        if (is_object($result) && isset($result->value)) {
            return $result->value;
        }

        return null;
    }

    private static function truthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '') {
            return false;
        }

        return true;
    }

    private static function formatIssue(mixed $issue): string
    {
        $message = '';
        $path = '';
        $segments = null;
        if (is_array($issue)) {
            $message = isset($issue['message']) ? (string) $issue['message'] : '';
            $segments = $issue['path'] ?? null;
        } elseif (is_object($issue)) {
            $message = isset($issue->message) ? (string) $issue->message : '';
            $segments = $issue->path ?? null;
        }
        if (is_array($segments)) {
            $parts = [];
            foreach ($segments as $segment) {
                if (is_array($segment) && array_key_exists('key', $segment)) {
                    $parts[] = (string) $segment['key'];
                } elseif (is_object($segment) && isset($segment->key)) {
                    $parts[] = (string) $segment->key;
                } else {
                    $parts[] = (string) $segment;
                }
            }
            $path = implode('.', $parts);
        }

        return $path !== '' ? $path . ': ' . $message : $message;
    }
}
