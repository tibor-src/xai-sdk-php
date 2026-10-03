<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Support;

final class PartialJson
{
    private const MISSING = '__MISSING__';

    /**
     * Parses JSON that may be cut off during streaming.
     */
    public static function parse(string $text): mixed
    {
        if ($text === '') {
            return null;
        }

        $parser = new self($text);

        try {
            $value = $parser->value();
            if ($value === self::MISSING) {
                return null;
            }

            return $value;
        } catch (InvalidJsonException) {
            return null;
        }
    }

    private int $i = 0;

    public function __construct(private readonly string $text)
    {
    }

    private function fail(): never
    {
        throw new InvalidJsonException();
    }

    private function skipWhitespace(): void
    {
        while ($this->i < strlen($this->text) && in_array($this->text[$this->i], [' ', "\t", "\n", "\r"], true)) {
            $this->i++;
        }
    }

    private function value(): mixed
    {
        $this->skipWhitespace();
        if ($this->i >= strlen($this->text)) {
            return self::MISSING;
        }
        $char = $this->text[$this->i];
        if ($char === '{') {
            return $this->object();
        }
        if ($char === '[') {
            return $this->array();
        }
        if ($char === '"') {
            return $this->string()['value'];
        }
        if ($char === '-' || ($char >= '0' && $char <= '9')) {
            return $this->number();
        }

        return $this->literal();
    }

    /**
     * @return array<string, mixed>
     */
    private function object(): array
    {
        $this->i++;
        $out = [];
        $this->skipWhitespace();
        if ($this->i < strlen($this->text) && $this->text[$this->i] === '}') {
            $this->i++;

            return $out;
        }
        while (true) {
            $this->skipWhitespace();
            if ($this->i >= strlen($this->text)) {
                return $out;
            }
            if ($this->text[$this->i] !== '"') {
                $this->fail();
            }
            $key = $this->string();
            $this->skipWhitespace();
            if (! $key['closed'] || $this->i >= strlen($this->text)) {
                return $out;
            }
            if ($this->text[$this->i] !== ':') {
                $this->fail();
            }
            $this->i++;
            $item = $this->value();
            if ($item === self::MISSING) {
                return $out;
            }
            $out[$key['value']] = $item;
            $this->skipWhitespace();
            if ($this->i >= strlen($this->text)) {
                return $out;
            }
            if ($this->text[$this->i] === '}') {
                $this->i++;

                return $out;
            }
            if ($this->text[$this->i] !== ',') {
                $this->fail();
            }
            $this->i++;
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function array(): array
    {
        $this->i++;
        $out = [];
        $this->skipWhitespace();
        if ($this->i < strlen($this->text) && $this->text[$this->i] === ']') {
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
            if ($this->i >= strlen($this->text)) {
                return $out;
            }
            if ($this->text[$this->i] === ']') {
                $this->i++;

                return $out;
            }
            if ($this->text[$this->i] !== ',') {
                $this->fail();
            }
            $this->i++;
        }
    }

    /**
     * @return array{value: string, closed: bool}
     */
    private function string(): array
    {
        $this->i++;
        $value = '';
        $closed = false;
        while ($this->i < strlen($this->text)) {
            $char = $this->text[$this->i];
            if ($char === '"') {
                $this->i++;
                $closed = true;
                break;
            }
            if ($char === '\\') {
                $this->i++;
                if ($this->i >= strlen($this->text)) {
                    break;
                }
                $esc = $this->text[$this->i];
                $value .= match ($esc) {
                    '"', '\\', '/' => $esc,
                    'b' => "\x08",
                    'f' => "\x0C",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    default => $esc,
                };
                $this->i++;
                continue;
            }
            $value .= $char;
            $this->i++;
        }

        return ['value' => $value, 'closed' => $closed];
    }

    private function number(): mixed
    {
        $start = $this->i;
        if ($this->text[$this->i] === '-') {
            $this->i++;
        }
        while ($this->i < strlen($this->text) && $this->text[$this->i] >= '0' && $this->text[$this->i] <= '9') {
            $this->i++;
        }
        if ($this->i < strlen($this->text) && $this->text[$this->i] === '.') {
            $this->i++;
            while ($this->i < strlen($this->text) && $this->text[$this->i] >= '0' && $this->text[$this->i] <= '9') {
                $this->i++;
            }
        }
        if ($this->i < strlen($this->text) && ($this->text[$this->i] === 'e' || $this->text[$this->i] === 'E')) {
            return self::MISSING;
        }
        $slice = substr($this->text, $start, $this->i - $start);
        if (! preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $slice)) {
            return self::MISSING;
        }

        return str_contains($slice, '.') ? (float) $slice : (int) $slice;
    }

    private function literal(): mixed
    {
        foreach ([['true', true], ['false', false], ['null', null]] as [$lit, $val]) {
            if (str_starts_with(substr($this->text, $this->i), $lit)) {
                $this->i += strlen($lit);

                return $val;
            }
        }

        $this->fail();
    }
}

final class InvalidJsonException extends \Exception
{
}
