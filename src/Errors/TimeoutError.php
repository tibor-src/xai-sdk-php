<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

final class TimeoutError extends APIError
{
    public function __construct(string $message = 'Request timed out', array $init = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $init, $previous);
    }
}
