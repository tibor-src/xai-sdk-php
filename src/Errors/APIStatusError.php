<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

class APIStatusError extends APIError
{
    /**
     * @param array<string, mixed> $init
     */
    public function __construct(string $message, array $init)
    {
        $init['status'] = (int) $init['status'];
        parent::__construct($message, $init);
    }
}
