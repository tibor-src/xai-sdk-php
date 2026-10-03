<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

class APIError extends \Exception
{
    public string $name;

    public ?string $requestId;

    public ?string $clientRequestId;

    public ?int $status;

    /** @var string|null API error code. Distinct from Exception's numeric code, which stays 0. */
    public $code;

    public ?string $param;

    public ?string $type;

    public mixed $body;

    /** @param array<string, mixed> $init */
    public function __construct(string $message = '', array $init = [])
    {
        $cause = $init['cause'] ?? null;
        parent::__construct($message, 0, $cause instanceof \Throwable ? $cause : null);
        $this->name = (new \ReflectionClass($this))->getShortName();
        $this->requestId = $init['requestId'] ?? null;
        $this->clientRequestId = $init['clientRequestId'] ?? null;
        $this->status = array_key_exists('status', $init) ? (is_int($init['status']) ? $init['status'] : null) : null;
        $this->code = isset($init['code']) && is_string($init['code']) ? $init['code'] : (isset($init['code']) && is_numeric($init['code']) ? (string) $init['code'] : null);
        $this->param = isset($init['param']) && is_string($init['param']) ? $init['param'] : null;
        $this->type = isset($init['type']) && is_string($init['type']) ? $init['type'] : null;
        $this->body = $init['body'] ?? null;
    }

    public static function is(mixed $err): bool
    {
        return $err instanceof self;
    }

    public function isRateLimit(): bool
    {
        return $this instanceof RateLimitError || $this->status === 429;
    }

    public function isOverloaded(): bool
    {
        return $this instanceof OverloadedError || $this->status === 529;
    }

    public function isAbort(): bool
    {
        return $this->name === 'AbortError';
    }
}

class APIConnectionError extends APIError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message = 'Connection error', array $init = [])
    {
        parent::__construct($message, $init);
    }
}

class APIProtocolError extends APIError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message = 'Invalid API response', array $init = [])
    {
        parent::__construct($message, $init);
    }
}

class TimeoutError extends APIError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message = 'Request timed out', array $init = [])
    {
        parent::__construct($message, $init);
    }
}

class AbortError extends APIError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message = 'Request aborted', array $init = [])
    {
        parent::__construct($message, $init);
    }
}

class APIStatusError extends APIError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message, array $init)
    {
        parent::__construct($message, $init);
    }
}

class AuthenticationError extends APIStatusError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message = 'Authentication failed', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 401]));
    }
}

class PermissionDeniedError extends APIStatusError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message = 'Permission denied', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 403]));
    }
}

class NotFoundError extends APIStatusError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message = 'Not found', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 404]));
    }
}

class RateLimitError extends APIStatusError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message = 'Rate limited', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 429]));
    }
}

class OverloadedError extends APIStatusError
{
    /** @param array<string, mixed> $init */
    public function __construct(string $message = 'Service overloaded', array $init = [])
    {
        parent::__construct($message, array_merge($init, ['status' => 529]));
    }
}

