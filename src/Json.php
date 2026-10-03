<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

final class Json
{
    public static function encode(mixed $value, bool $forceObject = false): string
    {
        return json_encode(self::normalize($value, $forceObject), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function decode(string $text): mixed
    {
        if ($text === '') {
            return null;
        }
        try {
            $decoded = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $text;
        }

        return self::unwrap($decoded);
    }

    private static function normalize(mixed $value, bool $forceObject): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if ($forceObject || ! array_is_list($value)) {
            $object = new \stdClass();
            foreach ($value as $key => $item) {
                $object->{(string) $key} = self::normalize($item, false);
            }

            return $object;
        }

        return array_map(static fn (mixed $item): mixed => self::normalize($item, false), $value);
    }

    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $out = [];
            foreach (get_object_vars($value) as $key => $item) {
                $out[$key] = self::unwrap($item);
            }

            return $out;
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::unwrap($item), $value);
        }

        return $value;
    }
}
