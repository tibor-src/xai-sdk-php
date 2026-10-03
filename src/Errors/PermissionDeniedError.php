<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

final class PermissionDeniedError extends APIStatusError
{
    public function __construct(string $message = 'Permission denied', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 403]));
    }
}
