<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

use TiborSrc\XaiSdkPhp\Http\AbortController;
use TiborSrc\XaiSdkPhp\Http\AbortSignal;

final class ResponseStream implements \IteratorAggregate
{
    /** @var array<string, true> */
    private const HELPERS = [
        'text' => true,
        'reasoning' => true,
        'tool_call' => true,
        'client_tool_call' => true,
        'server_tool_call' => true,
        'image' => true,
        'citation' => true,
        'json' => true,
    ];

    /** @var array<int, true> */
    private const RETRYABLE = [429 => true];

    /** @var array<int, true> */
    private const SERVER_ERRORS = [500 => true, 502 => true, 503 => true, 504 => true, 529 => true];

    /**
     * How long the stream waits for the body to end after the terminal event. A body that is read to
     * its end leaves the connection open for the next request. The wait is short, so a server that
     * holds the socket open can't delay the response much.
     */
    public const STREAM_TAIL_GRACE_MS = 250;

    private HttpMeta $http;

    private ?IdleStream $body;

    private bool $closed = false;

    private bool $consumed = false;

    private AbortController $closeController;

    private ?string $requestId;

    private ?AbortSignal $signal;

    /** @var array{budget: RetryBudget, resend: \Closure(): array{body: ?IdleStream, http: HttpMeta, payload: mixed}}|null */
    private ?array $retry;

    private int $maxEventChars;

    private ?string $jsonText;

    /** @var array<string, list<\Closure>> */
    private array $listeners = [];

    /** @var array<string, mixed>|null */
    private ?array $final = null;

    private ?APIError $error = null;

    private ?\Throwable $iterationError = null;

    private ?APIError $attemptFailure = null;

    /**
     * @param array{
     *   body: ?IdleStream,
     *   http: HttpMeta,
     *   signal?: ?AbortSignal,
     *   json?: bool,
     *   retry?: array{budget: RetryBudget, resend: \Closure(): array{body: ?IdleStream, http: HttpMeta, payload: mixed}}|null,
     *   maxEventChars?: int
     * } $init
     */
    public function __construct(array $init)
    {
        $this->http = $init['http'];
        $this->body = $init['body'];
        $this->requestId = $init['http']->requestId;
        $this->signal = $init['signal'] ?? null;
        $this->retry = $init['retry'] ?? null;
        $this->maxEventChars = $init['maxEventChars'] ?? Transport::DEFAULT_MAX_RESPONSE_BODY_BYTES;
        $this->jsonText = ($init['json'] ?? false) ? '' : null;
        $this->closeController = new AbortController();
        if ($this->body instanceof IdleStream) {
            $this->body->attachAbortSignal($this->closeController->signal);
        }
    }

    public function http(): HttpMeta
    {
        return $this->http;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'http') {
            return $this->http;
        }
        if ($name === 'watermarked') {
            return ModelResponse::isWatermarked($this->http);
        }

        return null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'watermarked';
    }

    /** @param callable(mixed): void $listener */
    public function on(string $event, callable $listener): self
    {
        if (! isset(self::HELPERS[$event]) && $event !== 'unknown' && ! isKnownStreamEventType($event)) {
            throw new \TypeError('Unsupported stream event: ' . $event);
        }
        $this->listeners[$event][] = $listener instanceof \Closure ? $listener : $listener(...);

        return $this;
    }

    public function done(): ModelResponse
    {
        if (! $this->consumed) {
            try {
                foreach ($this as $event) {
                }
            } catch (\Throwable $exception) {
                $this->iterationError = $exception;
            }
        }
        if ($this->iterationError instanceof \Throwable) {
            throw $this->iterationError;
        }

        return $this->finalResponse();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->closeController->abort();
        $body = $this->body;
        $this->body = null;
        if ($body instanceof IdleStream) {
            try {
                $body->cancel();
            } catch (\Throwable) {
            }
        }
    }

    public function getIterator(): \Generator
    {
        if ($this->consumed) {
            throw new \RuntimeException('Stream already iterated');
        }
        $this->consumed = true;
        try {
            foreach ($this->events() as $event) {
                $this->emit((string) ($event['type'] ?? 'unknown'), $event);
                $this->emitHelpers($event);
                yield $event;
            }
        } catch (\Throwable $exception) {
            $this->iterationError = $exception;
            throw $exception;
        }
    }

    private function emit(string $name, mixed $value): void
    {
        foreach ($this->listeners[$name] ?? [] as $listener) {
            $listener($value);
        }
    }

    private function emitToolCall(mixed $item): void
    {
        if (self::isClientToolCall($item)) {
            $this->emit('tool_call', $item);
            $this->emit('client_tool_call', $item);
        } elseif (self::isServerToolCall($item)) {
            $this->emit('tool_call', $item);
            $this->emit('server_tool_call', $item);
        }
    }

    /** @param array<string, mixed> $event */
    private function emitHelpers(array $event): void
    {
        $type = $event['type'] ?? null;
        if ($type === 'response.output_text.delta') {
            $delta = is_string($event['delta'] ?? null) ? $event['delta'] : '';
            $this->emit('text', $delta);
            $this->emitJson($delta);
        } elseif ($type === 'response.reasoning_text.delta' || $type === 'response.reasoning_summary_text.delta') {
            $this->emit('reasoning', is_string($event['delta'] ?? null) ? $event['delta'] : '');
        } elseif ($type === 'response.output_item.done') {
            $this->emitToolCall($event['item'] ?? null);
            if (isImageGenerationCall($event['item'] ?? null)) {
                $this->emit('image', $event['item']);
            }
            if (isMessage($event['item'] ?? null)) {
                foreach (self::urlCitations($event['item']) as $citation) {
                    $this->emit('citation', $citation);
                }
            }
        }
    }

    private function emitJson(string $delta): void
    {
        if ($this->jsonText === null) {
            return;
        }
        $this->jsonText .= $delta;
        if (! isset($this->listeners['json'])) {
            return;
        }
        $parsed = PartialJson::defined($this->jsonText);
        if ($parsed['defined']) {
            $this->emit('json', $parsed['value']);
        }
    }

    private function finalResponse(): ModelResponse
    {
        if ($this->error instanceof APIError) {
            throw $this->error;
        }
        if ($this->final === null) {
            throw new AbortError('Stream closed before the response completed', [
                'requestId' => $this->requestId,
                'clientRequestId' => $this->http->clientRequestId,
            ]);
        }

        return new ModelResponse($this->final, $this->http);
    }

    private function events(): \Generator
    {
        if ($this->body === null) {
            return;
        }
        try {
            while ($this->body instanceof IdleStream) {
                $retry = $this->retry !== null && $this->retry['budget']->canRetry() ? $this->retry : null;
                $this->attemptFailure = null;
                yield from $this->attempt($this->body, $retry !== null);
                $failure = $this->attemptFailure;
                if ($failure === null || $retry === null) {
                    break;
                }
                Transport::sleepMs(Transport::retryDelayMs($retry['budget']->spend(), null, $failure->status), $this->signal);
                if ($this->closed) {
                    break;
                }
                $next = ($retry['resend'])();
                $this->body = $next['body'];
                $this->http = $next['http'];
                $this->requestId = $next['http']->requestId;
                if ($this->body instanceof IdleStream) {
                    $this->body->attachAbortSignal($this->closeController->signal);
                }
                if (! $next['body'] instanceof IdleStream) {
                    $this->final = $this->terminal($next['payload'] ?? null);
                }
            }
            if (! $this->closed && $this->final === null && $this->error === null) {
                throw new APIProtocolError('Stream ended without a terminal response event', [
                    'requestId' => $this->requestId,
                ]);
            }
        } catch (\Throwable $exception) {
            $mapped = withClientRequestId(
                $this->signal?->isAborted()
                    ? errorFromUnknown($this->signal->activeReason() ?? $exception, $this->requestId)
                    : errorFromUnknown($exception, $this->requestId),
                $this->http->clientRequestId,
            );
            if ($mapped instanceof TimeoutError) {
                yield [
                    'type' => 'error',
                    'message' => $mapped->getMessage(),
                    'error' => $mapped,
                ];
            }
            throw $mapped;
        } finally {
            $this->close();
        }
    }

    private function attempt(IdleStream $body, bool $canRetry): \Generator
    {
        $held = [];
        $holding = $canRetry;
        $seenTerminal = false;
        try {
            foreach (Sse::parse($body, $this->closeController->signal, $this->maxEventChars) as $raw) {
                $event = $this->normalize($raw);
                if ($holding) {
                    if (self::isLifecycle($event)) {
                        $held[] = $event;
                        continue;
                    }
                    if (($event['type'] ?? null) === 'error' && ($event['error'] ?? null) instanceof APIError && self::isRetryableBeforeOutput($event['error'])) {
                        $this->attemptFailure = $event['error'];

                        return;
                    }
                    $holding = false;
                    foreach ($held as $early) {
                        $this->apply($early);
                        yield $early;
                    }
                }
                $this->apply($event);
                yield $event;
                // The terminal event carries the whole response, so the stream ends here and nothing after it counts.
                if (self::isTerminal($event)) {
                    $seenTerminal = true;
                    $this->drainTail($body);

                    return;
                }
            }
        } catch (\Throwable $exception) {
            if ($seenTerminal) {
                return;
            }
            $error = errorFromUnknown($exception, $this->requestId);
            if ($holding && ! $this->closed && ! $this->signal?->isAborted() && self::isRetryableBeforeOutput($error)) {
                $this->attemptFailure = $error;

                return;
            }
            throw $exception;
        }
        if ($holding && ! $this->closed) {
            $this->attemptFailure = new APIConnectionError('Stream ended before any output', [
                'requestId' => $this->requestId,
            ]);
        }
    }

    /**
     * Reads and discards what follows the terminal event, until the stream ends, so the connection can
     * be reused. After STREAM_TAIL_GRACE_MS it stops and cancels the body instead. The response is
     * already complete, so nothing read here is applied or yielded, and a connection error, abort, or
     * timeout here is ignored.
     */
    private function drainTail(IdleStream $body): void
    {
        $this->closeController->signal->deadline = microtime(true) + (self::STREAM_TAIL_GRACE_MS / 1000);
        try {
            while ($body->read() !== null) {
                // discarded
            }
        } catch (\Throwable) {
            // the response is already complete
        }
    }

    /** @return array<string, mixed> */
    private function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return ['type' => 'unknown', 'raw' => $raw];
        }
        $type = $raw['type'] ?? null;
        if ($type === 'error') {
            $parsed = streamErrorEvent($raw, $this->requestId);
            withClientRequestId($parsed['error'], $this->http->clientRequestId);

            return $parsed['event'];
        }
        if (is_string($type) && isKnownStreamEventType($type)) {
            return $raw;
        }

        return ['type' => 'unknown', 'raw' => $raw];
    }

    /** @param array<string, mixed> $event */
    private function apply(array $event): void
    {
        if (self::isTerminal($event)) {
            $this->final = $this->terminal($event['response'] ?? null);
        } elseif (($event['type'] ?? null) === 'error' && ($event['error'] ?? null) instanceof APIError) {
            $this->error = $event['error'];
        }
    }

    /** @return array<string, mixed> */
    private function terminal(mixed $response): array
    {
        if (! is_record($response)) {
            throw new APIProtocolError('Terminal stream event is missing a response object', [
                'requestId' => $this->requestId,
                'body' => $response,
            ]);
        }
        if (! is_string($response['id'] ?? null) || $response['id'] === '' || ! is_string($response['status'] ?? null) || ! is_list_array($response['output'] ?? null)) {
            throw new APIProtocolError('Terminal stream response is missing id, status, or output', [
                'requestId' => $this->requestId,
                'body' => $response,
            ]);
        }

        return $response;
    }

    private static function isLifecycle(array $event): bool
    {
        $type = $event['type'] ?? null;

        return $type === 'ping' || $type === 'response.created' || $type === 'response.in_progress';
    }

    private static function isTerminal(array $event): bool
    {
        $type = $event['type'] ?? null;

        return $type === 'response.completed' || $type === 'response.failed' || $type === 'response.incomplete';
    }

    private static function isRetryableBeforeOutput(APIError $error): bool
    {
        if ($error instanceof APIConnectionError) {
            return true;
        }

        return $error->status !== null && (isset(self::RETRYABLE[$error->status]) || isset(self::SERVER_ERRORS[$error->status]));
    }

    private static function isClientToolCall(mixed $item): bool
    {
        return is_record($item) && (($item['type'] ?? null) === 'function_call' || ($item['type'] ?? null) === 'shell_call');
    }

    private static function isServerToolCall(mixed $item): bool
    {
        return is_record($item) && isServerToolCallType($item['type'] ?? null);
    }

    /** @param array<string, mixed> $message
     * @return list<array<string, mixed>>
     */
    private static function urlCitations(array $message): array
    {
        $citations = [];
        if (! is_list_array($message['content'] ?? null)) {
            return $citations;
        }
        foreach ($message['content'] as $part) {
            if (! is_record($part) || ($part['type'] ?? null) !== 'output_text' || ! is_list_array($part['annotations'] ?? null)) {
                continue;
            }
            foreach ($part['annotations'] as $annotation) {
                if (is_record($annotation) && ($annotation['type'] ?? null) === 'url_citation' && is_string($annotation['url'] ?? null)) {
                    $citations[] = $annotation;
                }
            }
        }

        return $citations;
    }
}
