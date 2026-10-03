<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Http;

final class HeaderBag
{
    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, string> */
    private array $names = [];

    /** @param array<string, string>|list<array{0: string, 1: string}> $headers */
    public static function from(array $headers): self
    {
        $bag = new self();
        if ($headers === []) {
            return $bag;
        }
        if (array_is_list($headers)) {
            foreach ($headers as $pair) {
                $bag->set($pair[0], $pair[1]);
            }

            return $bag;
        }
        foreach ($headers as $name => $value) {
            $bag->set((string) $name, (string) $value);
        }

        return $bag;
    }

    public function set(string $name, string $value): void
    {
        $key = strtolower($name);
        if (! isset($this->names[$key])) {
            $this->names[$key] = $name;
        }
        $this->values[$key] = $value;
    }

    public function get(string $name): ?string
    {
        return $this->values[strtolower($name)] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->values);
    }

    public function clone(): self
    {
        $copy = new self();
        $copy->values = $this->values;
        $copy->names = $this->names;

        return $copy;
    }

    /** @return list<array{0: string, 1: string}> */
    public function entries(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            $out[] = [$this->names[$key], $value];
        }

        return $out;
    }
}
