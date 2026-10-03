<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

final class AbortError extends APIError
{
    public function __construct(string $message = 'Request aborted', array $init = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $init, $previous);
    }
}
