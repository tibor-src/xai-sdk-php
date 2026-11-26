<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Resources;

use TiborSrc\XaiSdkPhp\APIProtocolError;
use TiborSrc\XaiSdkPhp\HttpMeta;
use TiborSrc\XaiSdkPhp\Record;

use function TiborSrc\XaiSdkPhp\requestIds;

final class Wire
{
    /** @return array<string, mixed> */
    public static function requireRecord(mixed $payload, HttpMeta $http, string $label): array
    {
        if (is_array($payload) && ($payload === [] || ! array_is_list($payload))) {
            return $payload;
        }

        throw new APIProtocolError($label . ' must be a JSON object', [
            ...requestIds($http),
            'body' => $payload,
        ]);
    }

    /** @param array<string, mixed> $fields */
    public static function withHttp(array $fields, HttpMeta $http): Record
    {
        $fields['http'] = $http;

        return new Record($fields);
    }

    public static function modelsHaveIds(mixed $models): bool
    {
        if (! is_array($models) || ! array_is_list($models)) {
            return false;
        }
        foreach ($models as $model) {
            if (! is_array($model) || array_is_list($model) || ! is_string($model['id'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Encodes a value for use as a single URL path segment, rejecting values that would not address
     * their own resource. An empty id drops the segment, and `.` or `..` are resolved as dot segments,
     * so e.g. `files->delete('..')` would hit `DELETE /v1/`. `%2E%2E` collapses too, so encoding alone
     * cannot fix this.
     *
     * Resource methods type-hint `string`, so PHP rejects an array or object before this runs. Direct
     * callers can pass any value, and `['..']` is not `'..'` but would be encoded as `..` if it were
     * stringified. Anything other than a string or number is rejected, and the check runs on
     * `(string) $value`, which is what gets encoded.
     */
    public static function encodePath(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            $got = match (true) {
                $value === null => 'null',
                is_array($value) => 'array',
                is_bool($value) => 'boolean',
                is_object($value) => 'object',
                default => get_debug_type($value),
            };
            throw new \TypeError('Invalid path parameter: expected a string or number, got ' . $got);
        }
        $segment = (string) $value;
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new \TypeError('Invalid path parameter: ' . json_encode($segment, JSON_THROW_ON_ERROR));
        }

        return str_replace(
            ['%21', '%27', '%28', '%29', '%2A'],
            ['!', "'", '(', ')', '*'],
            rawurlencode($segment),
        );
    }
}
