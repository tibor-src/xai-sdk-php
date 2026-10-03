<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Support;

use XaiOfficial\Sdk\Errors\APIProtocolError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Types\HttpMeta;

final class RecordHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function requireRecord(mixed $payload, HttpMeta $http, string $label): array
    {
        if (! is_array($payload)) {
            throw new APIProtocolError("$label must be a JSON object", array_merge(
                ErrorFactory::requestIds($http),
                ['body' => $payload],
            ));
        }

        return $payload;
    }
}
