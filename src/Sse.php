<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

use TiborSrc\XaiSdkPhp\Http\AbortSignal;

final class Sse
{
    public const MAX_EVENT_CHARS = 1_048_576;

    private const DONE = '[DONE]';

    public static function parse(IdleStream $body, ?AbortSignal $close = null): \Generator
    {
        $buf = '';
        try {
            while (true) {
                if ($close?->isAborted()) {
                    $body->cancel();

                    return;
                }
                $chunk = $body->read();
                if ($chunk === null) {
                    $flushed = self::flush($buf);
                    foreach ($flushed['items'] as $item) {
                        if ($item === self::DONE) {
                            return;
                        }
                        yield $item;
                    }
                    if (trim($flushed['rest']) !== '') {
                        $tail = self::block($flushed['rest']);
                        if ($tail === self::DONE) {
                            return;
                        }
                        if ($tail !== null) {
                            yield $tail;
                        }
                    }

                    return;
                }
                if ($chunk !== '') {
                    $buf .= $chunk;
                }
                $flushed = self::flush($buf);
                $buf = $flushed['rest'];
                if (mb_strlen($buf) > self::MAX_EVENT_CHARS) {
                    throw new \RuntimeException('SSE event exceeds ' . self::MAX_EVENT_CHARS . ' characters');
                }
                foreach ($flushed['items'] as $item) {
                    if ($item === self::DONE) {
                        return;
                    }
                    yield $item;
                }
            }
        } finally {
            $body->cancel();
        }
    }

    /** @return array{items: list<mixed>, rest: string} */
    private static function flush(string $buf): array
    {
        $parts = preg_split('/(?:\r\n|\r|\n)(?:\r\n|\r|\n)/', $buf);
        if ($parts === false) {
            $parts = [$buf];
        }
        $rest = array_pop($parts) ?? '';
        if (mb_strlen($rest) > self::MAX_EVENT_CHARS) {
            throw new \RuntimeException('SSE event exceeds ' . self::MAX_EVENT_CHARS . ' characters');
        }
        $items = [];
        foreach ($parts as $block) {
            if (mb_strlen($block) > self::MAX_EVENT_CHARS) {
                throw new \RuntimeException('SSE event exceeds ' . self::MAX_EVENT_CHARS . ' characters');
            }
            $parsed = self::block($block);
            if ($parsed !== null) {
                $items[] = $parsed;
            }
        }

        return ['items' => $items, 'rest' => $rest];
    }

    private static function block(string $block): mixed
    {
        $eventName = null;
        $dataLines = [];
        $lines = preg_split('/\r\n|\r|\n/', $block);
        if ($lines === false) {
            $lines = [$block];
        }
        foreach ($lines as $rawLine) {
            $line = preg_replace('/^\x{FEFF}/u', '', $rawLine) ?? $rawLine;
            if ($line === '' || str_starts_with($line, ':')) {
                continue;
            }
            if (str_starts_with($line, 'event:')) {
                $eventName = trim(substr($line, 6));
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = preg_replace('/^ /', '', substr($line, 5), 1) ?? substr($line, 5);
            }
        }
        if ($dataLines === []) {
            return $eventName === 'ping' ? ['type' => 'ping'] : null;
        }
        $data = implode("\n", $dataLines);
        if ($data === self::DONE) {
            return self::DONE;
        }
        try {
            $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $eventName === 'ping' ? ['type' => 'ping'] : ['type' => 'unknown', 'raw' => $data];
        }
        if ($eventName !== null && is_array($parsed) && ($parsed['type'] ?? null) === null) {
            $parsed['type'] = $eventName;
        }

        return $parsed;
    }
}
