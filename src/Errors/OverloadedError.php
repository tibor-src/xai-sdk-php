<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

final class OverloadedError extends APIStatusError
{
    public function __construct(string $message = 'Service overloaded', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 529]));
    }
}
