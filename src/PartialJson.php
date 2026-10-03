<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

final class InvalidPartialJson extends \Exception {}

final class PartialJson
{
    private const MISSING = "\0missing";

    /** @var array<string, string> */
    private const ESCAPES = [
        '"' => '"',
        '\\' => '\\',
        '/' => '/',
        'b' => "\u{0008}",
        'f' => "\f",
        'n' => "\n",
        'r' => "\r",
        't' => "\t",
    ];

    public static function parse(string $text): mixed
    {
        $parsed = self::defined($text);

        return $parsed['defined'] ? $parsed['value'] : null;
    }

    /** @return array{defined: bool, value: mixed} */
    public static function defined(string $text): array
    {
        $parser = new self($text);

        try {
            $result = $parser->value();
            $parser->skipWhitespace();
            if ($parser->i < $parser->length) {
                $parser->fail();
            }
            if ($result === self::MISSING) {
                return ['defined' => false, 'value' => null];
            }

            return ['defined' => true, 'value' => $result];
        } catch (InvalidPartialJson) {
            return ['defined' => false, 'value' => null];
        }
    }

    /** @var list<string> */
    private array $chars;

    private int $length;

    private int $i = 0;

    private function __construct(string $text)
    {
        $this->chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $this->length = count($this->chars);
    }

    private function fail(): never
    {
        throw new InvalidPartialJson();
    }

    private function skipWhitespace(): void
    {
        while ($this->i < $this->length && in_array($this->chars[$this->i], [' ', "\t", "\n", "\r"], true)) {
            $this->i++;
        }
    }

    private function value(): mixed
    {
        $this->skipWhitespace();
        if ($this->i >= $this->length) {
            return self::MISSING;
        }
        $char = $this->chars[$this->i];
        if ($char === '{') {
            return $this->object();
        }
        if ($char === '[') {
            return $this->arrayValue();
        }
        if ($char === '"') {
            return $this->string()['value'];
        }
        if ($char === '-' || ($char >= '0' && $char <= '9')) {
            return $this->number();
        }

        return $this->literal();
    }

    /** @return array<string, mixed> */
    private function object(): array
    {
        $this->i++;
        $out = [];
        $this->skipWhitespace();
        if (($this->chars[$this->i] ?? null) === '}') {
            $this->i++;

            return $out;
        }
        while (true) {
            $this->skipWhitespace();
            if ($this->i >= $this->length) {
                return $out;
            }
            if ($this->chars[$this->i] !== '"') {
                $this->fail();
            }
            $key = $this->string();
            $this->skipWhitespace();
            if (! $key['closed'] || $this->i >= $this->length) {
                return $out;
            }
            if ($this->chars[$this->i] !== ':') {
                $this->fail();
            }
            $this->i++;
            $item = $this->value();
            if ($item === self::MISSING) {
                return $out;
            }
            $out[$key['value']] = $item;
            $this->skipWhitespace();
            if ($this->i >= $this->length) {
                return $out;
            }
            if ($this->chars[$this->i] === '}') {
                $this->i++;

                return $out;
            }
            if ($this->chars[$this->i] !== ',') {
                $this->fail();
            }
            $this->i++;
        }
    }

    /** @return list<mixed> */
    private function arrayValue(): array
    {
        $this->i++;
        $out = [];
        $this->skipWhitespace();
        if (($this->chars[$this->i] ?? null) === ']') {
            $this->i++;

            return $out;
        }
        while (true) {
            $item = $this->value();
            if ($item === self::MISSING) {
                return $out;
            }
            $out[] = $item;
            $this->skipWhitespace();
            if ($this->i >= $this->length) {
                return $out;
            }
            if ($this->chars[$this->i] === ']') {
                $this->i++;

                return $out;
            }
            if ($this->chars[$this->i] !== ',') {
                $this->fail();
            }
            $this->i++;
        }
    }

    /** @return array{value: string, closed: bool} */
    private function string(): array
    {
        $this->i++;
        $units = [];
        while ($this->i < $this->length) {
            $char = $this->chars[$this->i];
            if ($char === '"') {
                $this->i++;

                return ['value' => $this->unitsToUtf8($units), 'closed' => true];
            }
            if ($char === '\\') {
                $escape = $this->chars[$this->i + 1] ?? null;
                if ($escape === null) {
                    break;
                }
                if ($escape === 'u') {
                    $hex = implode('', array_slice($this->chars, $this->i + 2, 4));
                    if (strlen($hex) < 4) {
                        break;
                    }
                    if (preg_match('/^[0-9a-fA-F]{4}$/', $hex) !== 1) {
                        $this->fail();
                    }
                    $units[] = hexdec($hex);
                    $this->i += 6;
                    continue;
                }
                if (! array_key_exists($escape, self::ESCAPES)) {
                    $this->fail();
                }
                $escaped = self::ESCAPES[$escape];
                $units[] = mb_ord($escaped, 'UTF-8');
                $this->i += 2;
                continue;
            }
            if (ord($char) < 32) {
                $this->fail();
            }
            $units[] = mb_ord($char, 'UTF-8');
            $this->i++;
        }
        $this->i = $this->length;
        if ($units !== [] && $units[array_key_last($units)] >= 0xD800 && $units[array_key_last($units)] <= 0xDBFF) {
            array_pop($units);
        }

        return ['value' => $this->unitsToUtf8($units), 'closed' => false];
    }

    private function number(): mixed
    {
        $start = $this->i;
        while ($this->i < $this->length && preg_match('/[-+.\deE]/', $this->chars[$this->i]) === 1) {
            $this->i++;
        }
        if ($this->i >= $this->length) {
            return self::MISSING;
        }
        $raw = implode('', array_slice($this->chars, $start, $this->i - $start));
        if (preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?$/', $raw) !== 1) {
            $this->fail();
        }

        return str_contains($raw, '.') || str_contains(strtolower($raw), 'e') ? (float) $raw : (int) $raw + 0;
    }

    private function literal(): mixed
    {
        $rest = implode('', array_slice($this->chars, $this->i));
        foreach ([['true', true], ['false', false], ['null', null]] as [$word, $result]) {
            $slice = substr($rest, 0, strlen($word));
            if ($slice === $word || (strlen($slice) === strlen($rest) && str_starts_with($word, $slice) && $slice !== '')) {
                $this->i += strlen($slice);

                return $result;
            }
        }

        return $this->fail();
    }

    /** @param list<int> $units */
    private function unitsToUtf8(array $units): string
    {
        $out = '';
        $count = count($units);
        for ($index = 0; $index < $count; $index++) {
            $unit = $units[$index];
            if ($unit >= 0xD800 && $unit <= 0xDBFF && $index + 1 < $count) {
                $low = $units[$index + 1];
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $codePoint = 0x10000 + (($unit - 0xD800) << 10) + ($low - 0xDC00);
                    $out .= mb_chr($codePoint, 'UTF-8');
                    $index++;
                    continue;
                }
            }
            $char = mb_chr($unit, 'UTF-8');
            if (is_string($char)) {
                $out .= $char;
            }
        }

        return $out;
    }
}
