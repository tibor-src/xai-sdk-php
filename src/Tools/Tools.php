<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Tools;

/**
 * Tool helpers matching @xai-official/sdk/tools.
 */
final class Tools
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function webSearch(array $options = []): array
    {
        return array_merge($options, ['type' => 'web_search']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function xSearch(array $options = []): array
    {
        return array_merge($options, ['type' => 'x_search']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function codeExecution(): array
    {
        return ['type' => 'code_interpreter'];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function collectionsSearch(array $options): array
    {
        return array_merge($options, ['type' => 'file_search']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function mcp(array $options): array
    {
        return array_merge($options, ['type' => 'mcp']);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function imageGeneration(array $options = []): array
    {
        return array_merge($options, ['type' => 'image_generation']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolSearch(): array
    {
        return ['type' => 'tool_search'];
    }
}
