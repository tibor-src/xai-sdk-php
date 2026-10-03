<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

final class Credentials
{
    private static ?\WeakMap $keys = null;

    public static function store(object $client, string $apiKey): void
    {
        self::map()[$client] = $apiKey;
    }

    public static function get(object $client): string
    {
        $map = self::map();
        if (! isset($map[$client])) {
            throw new \RuntimeException('SpaceXAI: internal API key state is unavailable');
        }

        return $map[$client];
    }

    private static function map(): \WeakMap
    {
        return self::$keys ??= new \WeakMap();
    }
}
