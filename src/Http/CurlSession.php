<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Http;

use TiborSrc\XaiSdkPhp\TimeoutError;

final class CurlSession implements ByteSource
{
    private \CurlHandle $handle;

    private \CurlMultiHandle $multi;

    public int $status = 0;

    public string $statusText = '';

    public HeaderBag $headers;

    public ?string $transportError = null;

    public bool $timedOut = false;

    private string $buffer = '';

    private bool $headersDone = false;

    private bool $done = false;

    private bool $cancelled = false;

    private ?AbortSignal $signal;

    public function __construct(HttpRequest $request)
    {
        $this->signal = $request->signal;
        $this->headers = new HeaderBag();
        $handle = curl_init();
        if ($handle === false) {
            throw new \RuntimeException('Connection error');
        }
        $this->handle = $handle;
        $headerLines = [];
        foreach ($request->headers->entries() as [$name, $value]) {
            $headerLines[] = $name . ': ' . $value;
        }
        $headerLines[] = 'Expect:';
        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_NOPROGRESS => true,
        ];
        if ($request->signal?->deadline !== null) {
            $remaining = (int) (($request->signal->deadline - microtime(true)) * 1000);
            $options[CURLOPT_TIMEOUT_MS] = max(1, $remaining);
        }
        if (is_string($request->body)) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        } elseif ($request->body instanceof FormData) {
            $options[CURLOPT_POSTFIELDS] = $request->body->body();
        }
        curl_setopt_array($this->handle, $options);
        curl_setopt($this->handle, CURLOPT_HEADERFUNCTION, function ($curl, string $line): int {
            $trim = rtrim($line, "\r\n");
            if ($trim === '') {
                $this->headersDone = true;

                return strlen($line);
            }
            if (preg_match('#^HTTP/\S+\s+(\d+)\s*(.*)$#i', $trim, $matches) === 1) {
                $this->status = (int) $matches[1];
                $this->statusText = trim($matches[2]);

                return strlen($line);
            }
            $colon = strpos($trim, ':');
            if ($colon !== false) {
                $this->headers->set(trim(substr($trim, 0, $colon)), trim(substr($trim, $colon + 1)));
            }

            return strlen($line);
        });
        curl_setopt($this->handle, CURLOPT_WRITEFUNCTION, function ($curl, string $data): int {
            $this->buffer .= $data;

            return strlen($data);
        });
        $multi = curl_multi_init();
        if ($multi === false) {
            throw new \RuntimeException('Connection error');
        }
        $this->multi = $multi;
        curl_multi_add_handle($this->multi, $this->handle);
    }

    public function awaitHeaders(): void
    {
        while (! $this->headersDone && ! $this->done) {
            $this->signal?->throwIfAborted();
            $this->pump(0.01);
        }
        if (! $this->headersDone) {
            $this->status = $this->status ?: (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
            if ($this->timedOut) {
                throw new TimeoutError('Request timed out');
            }
            throw new \RuntimeException($this->transportError ?: 'Connection error');
        }
    }

    public function poll(): Poll
    {
        $this->signal?->throwIfAborted();
        if ($this->buffer !== '') {
            $chunk = $this->buffer;
            $this->buffer = '';

            return Poll::chunk($chunk);
        }
        if ($this->done || $this->cancelled) {
            return $this->finish();
        }
        $this->pump(0.01);
        if ($this->buffer !== '') {
            $chunk = $this->buffer;
            $this->buffer = '';

            return Poll::chunk($chunk);
        }
        if ($this->done || $this->cancelled) {
            return $this->finish();
        }

        return Poll::wait();
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        $this->close();
    }

    public function close(): void
    {
        if (! isset($this->multi)) {
            return;
        }
        curl_multi_remove_handle($this->multi, $this->handle);
        unset($this->handle, $this->multi);
    }

    private function finish(): Poll
    {
        if ($this->timedOut) {
            throw new TimeoutError('Request timed out');
        }
        if ($this->transportError !== null && $this->status === 0) {
            throw new \RuntimeException($this->transportError);
        }

        return Poll::eof();
    }

    private function pump(float $seconds): void
    {
        if ($this->done || $this->cancelled || ! isset($this->multi)) {
            return;
        }
        do {
            $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        if ($running) {
            curl_multi_select($this->multi, $seconds);
            do {
                $status = curl_multi_exec($this->multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }
        while ($info = curl_multi_info_read($this->multi)) {
            if (($info['result'] ?? CURLE_OK) !== CURLE_OK) {
                $result = $info['result'];
                $this->transportError = curl_error($this->handle) ?: (curl_strerror($result) ?: 'Connection error');
                if ($result === CURLE_OPERATION_TIMEDOUT) {
                    $this->timedOut = true;
                }
            }
            $this->done = true;
            $this->status = $this->status ?: (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
        }
        if (! $running) {
            $this->done = true;
            $this->status = $this->status ?: (int) curl_getinfo($this->handle, CURLINFO_RESPONSE_CODE);
            if ($this->transportError === null && $this->status === 0) {
                $message = curl_error($this->handle);
                $this->transportError = $message !== '' ? $message : 'Connection error';
            }
        }
    }
}
