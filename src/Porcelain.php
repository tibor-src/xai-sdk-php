<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

use TiborSrc\XaiSdkPhp\Http\AbortSignal;
use TiborSrc\XaiSdkPhp\Http\Blob;

final class Porcelain
{
    /** @param list<array<string, mixed>> $output */
    public static function toText(array $output): string
    {
        $text = '';
        foreach ($output as $item) {
            if (! isMessage($item) || ! is_list_array($item['content'] ?? null)) {
                continue;
            }
            $chunk = '';
            foreach ($item['content'] as $part) {
                if (is_record($part) && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $chunk .= $part['text'];
                }
            }
            if ($chunk === '') {
                continue;
            }
            $text .= $text !== '' ? "\n" . $chunk : $chunk;
        }

        return $text;
    }

    /**
     * @param list<array<string, mixed>> $output
     * @return list<array<string, mixed>>
     */
    public static function toInput(array $output): array
    {
        return array_map(static fn (array $item): array => self::deepCopy($item), $output);
    }

    /** @param list<array<string, mixed>> $output */
    public static function parseJsonOutput(array $output, string $status, bool $throwOnFail): mixed
    {
        $text = self::toText($output);
        if ($status !== 'completed') {
            if ($throwOnFail) {
                throw new \RuntimeException('Response is truncated; toJson() requires a completed result');
            }

            return null;
        }
        if ($text === '') {
            if ($throwOnFail) {
                throw new \RuntimeException('Response has no output_text; toJson() requires completed text output');
            }

            return null;
        }
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            if ($throwOnFail) {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function applyCreateDefaults(array $body): array
    {
        $store = array_key_exists('store', $body) && $body['store'] !== null ? $body['store'] : false;
        $out = $body;
        $out['store'] = $store;
        if ($store === false) {
            $include = isset($body['include']) && is_list_array($body['include']) ? $body['include'] : [];
            if (! in_array(ENCRYPTED_REASONING, $include, true)) {
                $include[] = ENCRYPTED_REASONING;
            }
            $out['include'] = $include;
        }
        if (! array_key_exists('stream', $body)) {
            $out['stream'] = false;
        }

        return $out;
    }

    public static function inlineBlobs(mixed $input, ?AbortSignal $signal): mixed
    {
        $signal?->throwIfAborted();
        if (is_string($input)) {
            return $input;
        }

        return self::inlineValue($input, $signal);
    }

    /** @return array<string, mixed>|string */
    public static function inlineImageInput(mixed $image, ?AbortSignal $signal): mixed
    {
        if ($image instanceof Blob) {
            return ['url' => self::blobToDataUrl($image, $signal)];
        }

        return $image;
    }

    /** @return array<string, mixed>|string */
    public static function inlineVideoInput(mixed $video, ?AbortSignal $signal): mixed
    {
        if ($video instanceof Blob) {
            return ['url' => self::blobToDataUrl($video, $signal, static fn (string $bytes): string => 'video/mp4')];
        }

        return $video;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function inlineImageInputs(array $body, ?AbortSignal $signal): array
    {
        $signal?->throwIfAborted();
        $out = $body;
        unset($out['image'], $out['images']);
        if (array_key_exists('image', $body) && $body['image'] !== null) {
            $out['image'] = self::inlineImageInput($body['image'], $signal);
        }
        if (array_key_exists('images', $body) && $body['images'] !== null) {
            $images = [];
            foreach ($body['images'] as $item) {
                $images[] = self::inlineImageInput($item, $signal);
            }
            $out['images'] = $images;
        }

        return $out;
    }

    private static function inlineValue(mixed $value, ?AbortSignal $signal): mixed
    {
        $signal?->throwIfAborted();
        if (is_list_array($value)) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::inlineValue($item, $signal);
            }

            return $out;
        }
        if (! is_record($value)) {
            return $value;
        }
        if (($value['type'] ?? null) === 'input_image' && ($value['image'] ?? null) instanceof Blob) {
            $next = [
                'type' => 'input_image',
                'image_url' => self::blobToDataUrl($value['image'], $signal),
            ];
            if (array_key_exists('detail', $value)) {
                $next['detail'] = $value['detail'];
            }

            return $next;
        }
        $next = [];
        foreach ($value as $key => $item) {
            if ($key === '__proto__' || $key === 'constructor' || $key === 'prototype') {
                continue;
            }
            $next[$key] = self::inlineValue($item, $signal);
        }

        return $next;
    }

    /** @param callable(string): string|null $detectType */
    private static function blobToDataUrl(Blob $blob, ?AbortSignal $signal, ?callable $detectType = null): string
    {
        $signal?->throwIfAborted();
        $bytes = $blob->bytes;
        $signal?->throwIfAborted();
        $mime = $blob->type !== '' && $blob->type !== 'application/octet-stream'
            ? $blob->type
            : ($detectType ?? self::sniffImageType(...))($bytes);

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    public static function sniffImageType(string $bytes): string
    {
        if (self::hasBytes($bytes, 0, [0xff, 0xd8, 0xff])) {
            return 'image/jpeg';
        }
        if (self::hasBytes($bytes, 0, [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])) {
            return 'image/png';
        }
        if (self::hasBytes($bytes, 0, [0x52, 0x49, 0x46, 0x46]) && self::hasBytes($bytes, 8, [0x57, 0x45, 0x42, 0x50])) {
            return 'image/webp';
        }

        return 'application/octet-stream';
    }

    /** @param list<int> $expected */
    private static function hasBytes(string $bytes, int $offset, array $expected): bool
    {
        foreach ($expected as $index => $byte) {
            if (! isset($bytes[$offset + $index]) || ord($bytes[$offset + $index]) !== $byte) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $value */
    private static function deepCopy(array $value): array
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
