<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

/**
 * A JSON object returned by the API, including the `http` metadata this client attaches.
 */
final class Record implements \ArrayAccess, \JsonSerializable
{
    /** @param array<string, mixed> $fields */
    public function __construct(private array $fields) {}

    public function __get(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->fields) && $this->fields[$name] !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->fields;
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_string($offset) && array_key_exists($offset, $this->fields);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) ? ($this->fields[$offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_string($offset)) {
            $this->fields[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        if (is_string($offset)) {
            unset($this->fields[$offset]);
        }
    }

    public function jsonSerialize(): mixed
    {
        return $this->fields;
    }
}
