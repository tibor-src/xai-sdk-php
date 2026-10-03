<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk;

use Psr\Http\Message\StreamInterface;
use XaiOfficial\Sdk\Constants;
use XaiOfficial\Sdk\Errors\APIError;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Http\RetryBudget;
use XaiOfficial\Sdk\Support\PartialJson;
use XaiOfficial\Sdk\Support\Porcelain;
use XaiOfficial\Sdk\Support\SseParser;
use XaiOfficial\Sdk\Types\HttpMeta;

final class ResponseStream implements \IteratorAggregate
{
    private HttpMeta $http;

    private ?StreamInterface $body;

    private bool $consumed = false;

    private bool $closed = false;

    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    private ?array $final = null;

    private ?APIError $error = null;

    private ?string $jsonText = null;

    private ?ModelResponse $doneResult = null;

    /**
     * @param callable|null $resend
     */
    public function __construct(
        ?StreamInterface $body,
        HttpMeta $http,
        private readonly bool $json = false,
        private readonly ?RetryBudget $retryBudget = null,
        private $resend = null,
    ) {
        $this->body = $body;
        $this->http = $http;
        if ($json) {
            $this->jsonText = '';
        }
    }

    public function getHttp(): HttpMeta
    {
        return $this->http;
    }

    public function on(string $event, callable $listener): self
    {
        $this->listeners[$event][] = $listener;

        return $this;
    }

    public function done(): ModelResponse
    {
        if ($this->doneResult !== null) {
            return $this->doneResult;
        }
        if (! $this->consumed) {
            foreach ($this as $_) {
            }
        }
        if ($this->error !== null) {
            throw $this->error;
        }
        if ($this->final === null) {
            throw new \RuntimeException('Stream closed before response completed');
        }

        return $this->doneResult = new ModelResponse($this->final, $this->http);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->body?->close();
        $this->body = null;
    }

    public function getIterator(): \Traversable
    {
        if ($this->consumed) {
            throw new \RuntimeException('Stream already iterated');
        }
        $this->consumed = true;

        return $this->events();
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function events(): \Generator
    {
        if ($this->body === null) {
            return;
        }

        foreach (SseParser::parse($this->body) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $normalized = $this->normalizeEvent($event);
            $this->emit($normalized['type'] ?? 'unknown', $normalized);
            $this->emitHelpers($normalized);
            yield $normalized;
            if (in_array($normalized['type'] ?? '', ['response.completed', 'response.failed', 'response.incomplete'], true)) {
                $this->final = $normalized['response'] ?? $normalized;
                break;
            }
            if (($normalized['type'] ?? '') === 'error') {
                $this->error = ErrorFactory::streamErrorEvent($normalized, $this->http->requestId);
                throw $this->error;
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function normalizeEvent(array $event): array
    {
        $type = (string) ($event['type'] ?? 'unknown');
        if (! Constants::isKnownStreamEventType($type)) {
            return ['type' => 'unknown', 'raw' => $event];
        }

        return $event;
    }

    private function emit(string $name, mixed $value): void
    {
        foreach ($this->listeners[$name] ?? [] as $listener) {
            $listener($value);
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function emitHelpers(array $event): void
    {
        $type = $event['type'] ?? '';
        if ($type === 'response.output_text.delta' && isset($event['delta'])) {
            $this->emit('text', $event['delta']);
            if ($this->jsonText !== null) {
                $this->jsonText .= $event['delta'];
                $parsed = PartialJson::parse($this->jsonText);
                if ($parsed !== null) {
                    $this->emit('json', $parsed);
                }
            }
        }
        if (in_array($type, ['response.reasoning_text.delta', 'response.reasoning_summary_text.delta'], true) && isset($event['delta'])) {
            $this->emit('reasoning', $event['delta']);
        }
        if ($type === 'response.output_item.done' && isset($event['item']) && is_array($event['item'])) {
            $item = $event['item'];
            $this->emit('tool_call', $item);
            if (Porcelain::isFunctionCall($item) || ($item['type'] ?? '') === 'shell_call') {
                $this->emit('client_tool_call', $item);
            }
            if (Constants::isServerToolCallType($item['type'] ?? null)) {
                $this->emit('server_tool_call', $item);
            }
            if (Porcelain::isImageGenerationCall($item)) {
                $this->emit('image', $item);
            }
            if (Porcelain::isMessage($item)) {
                foreach ($this->urlCitations($item) as $citation) {
                    $this->emit('citation', $citation);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $message
     * @return array<int, array<string, mixed>>
     */
    private function urlCitations(array $message): array
    {
        $citations = [];
        if (! is_array($message['content'] ?? null)) {
            return $citations;
        }
        foreach ($message['content'] as $part) {
            if (! is_array($part) || ($part['type'] ?? '') !== 'output_text' || ! is_array($part['annotations'] ?? null)) {
                continue;
            }
            foreach ($part['annotations'] as $annotation) {
                if (is_array($annotation) && ($annotation['type'] ?? '') === 'url_citation' && isset($annotation['url'])) {
                    $citations[] = $annotation;
                }
            }
        }

        return $citations;
    }
}
