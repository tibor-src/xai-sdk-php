<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

final class Usage
{
    private const TICKS_PER_USD = 1e10;

    private const NANO_USD_PER_USD = 1e9;

    public static function map(mixed $raw): Record
    {
        $usage = is_record($raw) ? $raw : [];
        $details = isset($usage['input_tokens_details']) && is_record($usage['input_tokens_details'])
            ? $usage['input_tokens_details']
            : [];
        $outputDetails = isset($usage['output_tokens_details']) && is_record($usage['output_tokens_details'])
            ? $usage['output_tokens_details']
            : [];
        $ticks = $usage['cost_in_usd_ticks'] ?? null;
        $nano = $usage['cost_in_nano_usd'] ?? null;

        return new Record(array_merge($usage, [
            'input_tokens' => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'total_tokens' => $usage['total_tokens'] ?? 0,
            'input_tokens_details' => ['cached_tokens' => $details['cached_tokens'] ?? 0],
            'output_tokens_details' => ['reasoning_tokens' => $outputDetails['reasoning_tokens'] ?? 0],
            'num_sources_used' => $usage['num_sources_used'] ?? 0,
            'num_server_side_tools_used' => $usage['num_server_side_tools_used'] ?? 0,
            'cost_in_nano_usd' => $nano,
            'cost_in_usd_ticks' => $ticks,
            'cost_usd' => self::costUsd($ticks, $nano),
        ]));
    }

    public static function mapMedia(mixed $raw): ?Record
    {
        if ($raw === null || ! is_record($raw)) {
            return null;
        }

        return new Record(array_merge($raw, [
            'cost_usd' => self::costUsd($raw['cost_in_usd_ticks'] ?? null, null),
        ]));
    }

    public static function costUsd(mixed $ticks, mixed $nano): ?float
    {
        if ($ticks !== null) {
            return ((float) $ticks) / self::TICKS_PER_USD;
        }
        if ($nano !== null) {
            return ((float) $nano) / self::NANO_USD_PER_USD;
        }

        return null;
    }
}
