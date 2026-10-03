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

    public static function encodePath(string $value): string
    {
        return str_replace(
            ['%21', '%27', '%28', '%29', '%2A'],
            ['!', "'", '(', ')', '*'],
            rawurlencode($value),
        );
    }
}
