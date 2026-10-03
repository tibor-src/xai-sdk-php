<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Http;

final class RetryBudget
{
    private int $spent = 0;

    public function __construct(public readonly int $max)
    {
    }

    public function canRetry(): bool
    {
        return $this->spent < $this->max;
    }

    public function spend(): int
    {
        return $this->spent++;
    }
}
