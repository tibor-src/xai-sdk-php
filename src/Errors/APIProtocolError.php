<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

final class APIProtocolError extends APIError
{
    public function __construct(string $message = 'Invalid API response', array $init = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $init, $previous);
    }
}
