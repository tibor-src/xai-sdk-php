<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

final class Env
{
    public static function apiKey(): ?string
    {
        $key = getenv('XAI_API_KEY');
        if (! is_string($key) || $key === '') {
            $key = $_ENV['XAI_API_KEY'] ?? $_SERVER['XAI_API_KEY'] ?? null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    public static function debugEnabled(): bool
    {
        return getenv('XAI_DEBUG') === '1' || (($_ENV['XAI_DEBUG'] ?? null) === '1');
    }

    public static function language(): string
    {
        return 'php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }
}
