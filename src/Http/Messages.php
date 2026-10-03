<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Http;

final class Poll
{
    private function __construct(
        public readonly string $kind,
        public readonly string $bytes = '',
    ) {}

    public static function chunk(string $bytes): self
    {
        return new self('chunk', $bytes);
    }

    public static function eof(): self
    {
        return new self('eof');
    }

    public static function wait(): self
    {
        return new self('wait');
    }
}

interface ByteSource
{
    public function poll(): Poll;

    public function cancel(): void;
}

final class StringSource implements ByteSource
{
    private bool $sent = false;

    public function __construct(private readonly string $bytes) {}

    public function poll(): Poll
    {
        if ($this->sent) {
            return Poll::eof();
        }
        $this->sent = true;
        if ($this->bytes === '') {
            return Poll::eof();
        }

        return Poll::chunk($this->bytes);
    }

    public function cancel(): void
    {
        $this->sent = true;
    }
}

final class ChunkSource implements ByteSource
{
    private int $index = 0;

    private ?int $readyAt = null;

    private bool $cancelled = false;

    /**
     * @param list<array{0: string, 1: int}> $chunks
     * @param (\Closure(): void)|null $onCancel
     */
    public function __construct(
        private readonly array $chunks,
        private readonly ?\Closure $onCancel = null,
        private readonly bool $hangAtEnd = false,
        private readonly ?\Throwable $errorAt = null,
        private readonly bool $errorBeforeByte = false,
    ) {}

    public function poll(): Poll
    {
        if ($this->cancelled) {
            return Poll::eof();
        }
        if ($this->errorBeforeByte && $this->errorAt instanceof \Throwable && $this->index === 0) {
            throw $this->errorAt;
        }
        if ($this->index >= count($this->chunks)) {
            if ($this->errorAt instanceof \Throwable) {
                throw $this->errorAt;
            }
            if ($this->hangAtEnd) {
                return Poll::wait();
            }

            return Poll::eof();
        }
        [$bytes, $delay] = $this->chunks[$this->index];
        if ($this->readyAt === null) {
            $this->readyAt = hrtime(true) + $delay * 1_000_000;
        }
        if (hrtime(true) < $this->readyAt) {
            return Poll::wait();
        }
        $this->index++;
        $this->readyAt = null;

        return Poll::chunk($bytes);
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        if ($this->onCancel instanceof \Closure) {
            ($this->onCancel)();
        }
    }
}

final class HttpRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly HeaderBag $headers,
        public readonly string|FormData|null $body,
        public readonly ?AbortSignal $signal,
    ) {}

    public function json(): mixed
    {
        if (! is_string($this->body)) {
            return null;
        }

        return \TiborSrc\XaiSdkPhp\Json::decode($this->body);
    }

    public function clone(): self
    {
        return new self($this->method, $this->url, $this->headers->clone(), $this->body, $this->signal);
    }
}

final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly HeaderBag $headers,
        public readonly string $statusText = '',
        public readonly ?ByteSource $body = null,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
