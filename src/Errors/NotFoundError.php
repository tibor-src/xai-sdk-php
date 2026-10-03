<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

final class NotFoundError extends APIStatusError
{
    public function __construct(string $message = 'Not found', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 404]));
    }
}
