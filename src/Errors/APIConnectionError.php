<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

final class APIConnectionError extends APIError
{
    public function __construct(string $message = 'Connection error', array $init = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $init, $previous);
    }
}
