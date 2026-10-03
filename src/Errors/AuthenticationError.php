<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

final class AuthenticationError extends APIStatusError
{
    public function __construct(string $message = 'Authentication failed', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 401]));
    }
}
