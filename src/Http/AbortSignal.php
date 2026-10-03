<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Http;

use TiborSrc\XaiSdkPhp\TimeoutError;

final class AbortSignal
{
    public bool $aborted = false;

    public mixed $reason = null;

    public ?float $deadline = null;

    public ?string $timeoutMessage = null;

    /** @var list<self> */
    public array $linked = [];

    public function abort(mixed $reason = null): void
    {
        if ($this->aborted) {
            return;
        }
        $this->aborted = true;
        $this->reason = $reason;
    }

    public function isAborted(): bool
    {
        if ($this->aborted) {
            return true;
        }
        foreach ($this->linked as $signal) {
            if ($signal->isAborted()) {
                if (! $this->aborted) {
                    $this->abort($signal->activeReason());
                }

                return true;
            }
        }
        if ($this->deadline !== null && microtime(true) >= $this->deadline) {
            $this->abort(new TimeoutError($this->timeoutMessage ?? 'Request timed out'));

            return true;
        }

        return false;
    }

    public function activeReason(): mixed
    {
        if ($this->aborted) {
            return $this->reason;
        }
        foreach ($this->linked as $signal) {
            if ($signal->isAborted()) {
                return $signal->activeReason();
            }
        }

        return $this->reason;
    }

    public function throwIfAborted(?string $requestId = null): void
    {
        if (! $this->isAborted()) {
            return;
        }
        throw \TiborSrc\XaiSdkPhp\errorFromAbort($this, $requestId);
    }

    /** @param list<self|null> $signals */
    public static function any(array $signals): ?self
    {
        $list = [];
        foreach ($signals as $signal) {
            if ($signal instanceof self) {
                $list[] = $signal;
            }
        }
        if ($list === []) {
            return null;
        }
        if (count($list) === 1) {
            return $list[0];
        }
        $merged = new self();
        $merged->linked = $list;
        $deadlines = [];
        foreach ($list as $signal) {
            if ($signal->deadline !== null) {
                $deadlines[] = $signal->deadline;
            }
            if ($signal->isAborted()) {
                $merged->abort($signal->activeReason());
                break;
            }
        }
        if ($deadlines !== []) {
            $merged->deadline = min($deadlines);
        }

        return $merged;
    }
}

final class AbortController
{
    public readonly AbortSignal $signal;

    public function __construct()
    {
        $this->signal = new AbortSignal();
    }

    public function abort(mixed $reason = null): void
    {
        $this->signal->abort($reason);
    }
}
