<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Support;

final class UsageMapper
{
    private const TICKS_PER_USD = 1e10;

    private const NANO_USD_PER_USD = 1e9;

    /**
     * @return array<string, mixed>
     */
    public static function mapUsage(mixed $raw): array
    {
        $u = is_array($raw) ? $raw : [];

        return array_merge($u, [
            'input_tokens' => $u['input_tokens'] ?? 0,
            'output_tokens' => $u['output_tokens'] ?? 0,
            'total_tokens' => $u['total_tokens'] ?? 0,
            'input_tokens_details' => [
                'cached_tokens' => $u['input_tokens_details']['cached_tokens'] ?? 0,
            ],
            'output_tokens_details' => [
                'reasoning_tokens' => $u['output_tokens_details']['reasoning_tokens'] ?? 0,
            ],
            'num_sources_used' => $u['num_sources_used'] ?? 0,
            'num_server_side_tools_used' => $u['num_server_side_tools_used'] ?? 0,
            'cost_in_nano_usd' => $u['cost_in_nano_usd'] ?? null,
            'cost_in_usd_ticks' => $u['cost_in_usd_ticks'] ?? null,
            'cost_usd' => self::costUsd(
                $u['cost_in_usd_ticks'] ?? null,
                $u['cost_in_nano_usd'] ?? null,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function mapMediaUsage(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $usage = is_array($raw) ? $raw : [];

        return array_merge($usage, [
            'cost_usd' => self::costUsd($usage['cost_in_usd_ticks'] ?? null),
        ]);
    }

    private static function costUsd(?int $ticks = null, ?int $nano = null): ?float
    {
        if ($ticks !== null) {
            return $ticks / self::TICKS_PER_USD;
        }
        if ($nano !== null) {
            return $nano / self::NANO_USD_PER_USD;
        }

        return null;
    }
}
