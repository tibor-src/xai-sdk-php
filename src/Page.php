<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

final class Page implements \IteratorAggregate, \ArrayAccess, \JsonSerializable
{
    /**
     * @param array<string, mixed> $fields
     * @param \Closure(array<string, mixed>): ?array<string, mixed> $next
     * @param \Closure(array<string, mixed>): list<mixed> $items
     */
    public function __construct(
        private array $fields,
        private readonly \Closure $next,
        private readonly \Closure $items,
    ) {}

    /**
     * @param \Closure(): array<string, mixed> $first
     * @param \Closure(array<string, mixed>): ?array<string, mixed> $next
     * @param \Closure(array<string, mixed>): list<mixed> $items
     */
    public static function start(\Closure $first, \Closure $next, \Closure $items): self
    {
        return new self($first(), $next, $items);
    }

    /**
     * @param array<string, mixed> $query
     * @param \Closure(array<string, mixed>): array<string, mixed> $fetch
     * @param \Closure(array<string, mixed>): list<mixed> $items
     */
    public static function tokens(array $query, \Closure $fetch, \Closure $items): self
    {
        return self::start(
            static fn (): array => $fetch($query),
            static function (array $page) use ($query, $fetch): ?array {
                $token = $page['pagination_token'] ?? null;
                if ($token === null || $token === '' || $token === false || $token === 0) {
                    return null;
                }

                return $fetch(array_merge($query, ['pagination_token' => $token]));
            },
            $items,
        );
    }

    public function getIterator(): \Generator
    {
        $fields = $this->fields;
        while (true) {
            foreach (($this->items)($fields) as $item) {
                yield $item;
            }
            $next = ($this->next)($fields);
            if ($next === null) {
                return;
            }
            $fields = $next;
        }
    }

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
