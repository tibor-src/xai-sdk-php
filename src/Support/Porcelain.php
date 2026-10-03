<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Support;

use XaiOfficial\Sdk\Constants;

final class Porcelain
{
    public static function isMessage(mixed $item): bool
    {
        return is_array($item) && ($item['type'] ?? null) === 'message';
    }

    public static function isReasoning(mixed $item): bool
    {
        return is_array($item) && ($item['type'] ?? null) === 'reasoning';
    }

    public static function isFunctionCall(mixed $item): bool
    {
        return is_array($item) && ($item['type'] ?? null) === 'function_call';
    }

    public static function isImageGenerationCall(mixed $item): bool
    {
        return is_array($item) && ($item['type'] ?? null) === 'image_generation_call';
    }

    /**
     * @param array<int, mixed> $output
     */
    public static function toText(array $output): string
    {
        $text = '';
        foreach ($output as $item) {
            if (! self::isMessage($item) || ! is_array($item['content'] ?? null)) {
                continue;
            }
            $chunk = '';
            foreach ($item['content'] as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $chunk .= $part['text'];
                }
            }
            if ($chunk === '') {
                continue;
            }
            $text = $text === '' ? $chunk : $text."\n".$chunk;
        }

        return $text;
    }

    /**
     * @param array<int, mixed> $output
     * @return array<int, mixed>
     */
    public static function toInput(array $output): array
    {
        return array_map(static fn ($item) => is_array($item) ? $item : $item, $output);
    }

    /**
     * @param array<int, mixed> $output
     */
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
        } catch (\JsonException $e) {
            if ($throwOnFail) {
                throw $e;
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
        $store = $body['store'] ?? Constants::SDK_STORE_DEFAULT;
        $out = array_merge($body, ['store' => $store]);
        if ($store === false) {
            $include = is_array($body['include'] ?? null) ? $body['include'] : [];
            if (! in_array(Constants::ENCRYPTED_REASONING, $include, true)) {
                $include[] = Constants::ENCRYPTED_REASONING;
            }
            $out['include'] = $include;
        }
        if (! array_key_exists('stream', $body)) {
            $out['stream'] = false;
        }

        return $out;
    }

    /**
     * @param string|array<int, mixed> $input
     * @return string|array<int, mixed>
     */
    public static function inlineBlobs(string|array $input): string|array
    {
        if (is_string($input)) {
            return $input;
        }

        return self::inlineValue($input);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function inlineValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (isset($value['type']) && $value['type'] === 'input_image' && isset($value['image'])) {
                $image = $value['image'];
                if (is_string($image) && is_file($image)) {
                    $bytes = file_get_contents($image);
                    $mime = self::sniffImageType($bytes !== false ? $bytes : '');
                    $dataUrl = 'data:'.$mime.';base64,'.base64_encode($bytes !== false ? $bytes : '');
                    $next = ['type' => 'input_image', 'image_url' => $dataUrl];
                    if (isset($value['detail'])) {
                        $next['detail'] = $value['detail'];
                    }

                    return $next;
                }
            }

            $out = [];
            foreach ($value as $k => $v) {
                if (in_array($k, ['__proto__', 'constructor', 'prototype'], true)) {
                    continue;
                }
                $out[$k] = self::inlineValue($v);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|string $image
     * @return array<string, mixed>
     */
    public static function inlineImageInput(array|string $image): array
    {
        if (is_string($image) && is_file($image)) {
            $bytes = file_get_contents($image);
            $mime = self::sniffImageType($bytes !== false ? $bytes : '');

            return ['url' => 'data:'.$mime.';base64,'.base64_encode($bytes !== false ? $bytes : '')];
        }

        return is_array($image) ? $image : ['url' => $image];
    }

    /**
     * @param array<string, mixed>|string $video
     * @return array<string, mixed>
     */
    public static function inlineVideoInput(array|string $video): array
    {
        if (is_string($video) && is_file($video)) {
            $bytes = file_get_contents($video);

            return ['url' => 'data:video/mp4;base64,'.base64_encode($bytes !== false ? $bytes : '')];
        }

        return is_array($video) ? $video : ['url' => $video];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function inlineImageInputs(array $body): array
    {
        $image = $body['image'] ?? null;
        $images = $body['images'] ?? null;
        $rest = $body;
        unset($rest['image'], $rest['images']);
        $out = $rest;
        if ($image !== null) {
            $out['image'] = self::inlineImageInput($image);
        }
        if (is_array($images)) {
            $out['images'] = [];
            foreach ($images as $item) {
                $out['images'][] = self::inlineImageInput($item);
            }
        }

        return $out;
    }

    public static function sniffImageType(string $bytes): string
    {
        if (strlen($bytes) >= 3 && $bytes[0] === "\xFF" && $bytes[1] === "\xD8" && $bytes[2] === "\xFF") {
            return 'image/jpeg';
        }
        if (strlen($bytes) >= 8 && substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n") {
            return 'image/png';
        }
        if (strlen($bytes) >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return 'application/octet-stream';
    }
}
