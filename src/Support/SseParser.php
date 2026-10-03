<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Support;

use Psr\Http\Message\StreamInterface;
use XaiOfficial\Sdk\Constants;

final class SseParser
{
    private const SSE_DONE = '[DONE]';

    /**
     * @return \Generator<int, mixed>
     */
    public static function parse(StreamInterface $body): \Generator
    {
        $buf = '';
        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            $buf .= $chunk;
            $parts = preg_split("/(?:\r\n|\r|\n)(?:\r\n|\r|\n)/", $buf);
            $buf = array_pop($parts) ?? '';
            if (strlen($buf) > Constants::MAX_SSE_EVENT_CHARS) {
                throw new \RuntimeException('SSE event exceeds '.Constants::MAX_SSE_EVENT_CHARS.' characters');
            }
            foreach ($parts as $block) {
                $item = self::parseBlock($block);
                if ($item === self::SSE_DONE) {
                    return;
                }
                if ($item !== null) {
                    yield $item;
                }
            }
        }

        if (trim($buf) !== '') {
            $item = self::parseBlock($buf);
            if ($item === self::SSE_DONE) {
                return;
            }
            if ($item !== null) {
                yield $item;
            }
        }
    }

    private static function parseBlock(string $block): mixed
    {
        $lines = preg_split("/\r\n|\r|\n/", $block) ?: [];
        $dataLines = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5));
            }
        }
        if ($dataLines === []) {
            return null;
        }
        $data = implode("\n", $dataLines);
        if ($data === self::SSE_DONE) {
            return self::SSE_DONE;
        }
        if ($data === 'ping') {
            return ['type' => 'ping'];
        }
        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }
}
