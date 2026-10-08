<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

use TiborSrc\XaiSdkPhp\Http\AbortSignal;

final class Sse
{
    private const DONE = '[DONE]';

    /** A blank line ends an event, so a delimiter is at most four characters, as in `\r\n\r\n`. */
    private const EVENT_DELIMITER = '/(?:\r\n|\r|\n)(?:\r\n|\r|\n)/';

    /**
     * @param int|null $maxEventChars Longest event in characters. Null uses the 32 MiB JSON body limit. 0 turns the limit off.
     */
    public static function parse(IdleStream $body, ?AbortSignal $close = null, ?int $maxEventChars = null): \Generator
    {
        $maxChars = $maxEventChars ?? Transport::DEFAULT_MAX_RESPONSE_BODY_BYTES;
        $buf = '';
        // The last characters of $buf, where a delimiter that the next chunk completes would start.
        $bufEnd = '';
        try {
            while (true) {
                if ($close?->isAborted()) {
                    $body->cancel();

                    return;
                }
                $chunk = $body->read();
                if ($chunk === null) {
                    $flushed = self::flush($buf, $maxChars);
                    self::assertEventSize($flushed['rest'], $maxChars);
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
                // Splitting the whole buffer for every chunk would make reading a long event quadratic.
                $recent = $bufEnd . $chunk;
                $buf .= $chunk;
                $bufEnd = self::tail($recent, 3);
                if (preg_match(self::EVENT_DELIMITER, $recent) !== 1) {
                    self::assertEventSize($buf, $maxChars);

                    continue;
                }
                $flushed = self::flush($buf, $maxChars);
                $buf = $flushed['rest'];
                $bufEnd = self::tail($buf, 3);
                self::assertEventSize($buf, $maxChars);
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

    private static function assertEventSize(string $text, int $maxChars): void
    {
        if ($maxChars > 0 && strlen($text) > $maxChars) {
            throw new \RuntimeException('SSE event exceeds ' . $maxChars . ' characters');
        }
    }

    private static function tail(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, -$length);
    }

    /** @return array{items: list<mixed>, rest: string} */
    private static function flush(string $buf, int $maxChars): array
    {
        $parts = preg_split(self::EVENT_DELIMITER, $buf);
        if ($parts === false) {
            $parts = [$buf];
        }
        $rest = array_pop($parts) ?? '';
        $items = [];
        foreach ($parts as $block) {
            self::assertEventSize($block, $maxChars);
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
