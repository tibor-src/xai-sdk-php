<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Errors;

class APIError extends \Exception
{
    public readonly ?string $requestId;

    public readonly ?string $clientRequestId;

    public readonly ?int $status;

    public readonly ?string $errorCode;

    public readonly ?string $param;

    public readonly ?string $type;

    public readonly mixed $body;

    /**
     * @param array<string, mixed> $init
     */
    public function __construct(string $message, array $init = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->requestId = isset($init['requestId']) ? (string) $init['requestId'] : null;
        $this->clientRequestId = isset($init['clientRequestId']) ? (string) $init['clientRequestId'] : null;
        $this->status = isset($init['status']) ? (int) $init['status'] : null;
        $this->errorCode = isset($init['code']) ? (string) $init['code'] : null;
        $this->param = isset($init['param']) ? (string) $init['param'] : null;
        $this->type = isset($init['type']) ? (string) $init['type'] : null;
        $this->body = $init['body'] ?? null;
    }

    public static function is(\Throwable $err): bool
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
        return $this instanceof AbortError;
    }
}
