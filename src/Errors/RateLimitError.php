<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

final class RateLimitError extends APIStatusError
{
    public function __construct(string $message = 'Rate limited', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 429]));
    }
}
