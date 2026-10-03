<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp;

use TiborSrc\XaiSdkPhp\Http\Blob;

final class BinaryResponse
{
    public ?IdleStream $body;

    public ?string $contentType;

    public HttpMeta $http;

    private ?string $cached = null;

    public function __construct(?IdleStream $body, HttpMeta $http)
    {
        $this->body = $body;
        $this->contentType = $http->headers->get('content-type');
        $this->http = $http;
    }

    public function arrayBuffer(): string
    {
        return $this->read();
    }

    public function bytes(): string
    {
        return $this->read();
    }

    public function text(): string
    {
        return $this->read();
    }

    public function blob(): Blob
    {
        return new Blob($this->bytes(), $this->contentType ?? '');
    }

    private function read(): string
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        try {
            if ($this->body === null) {
                return $this->cached = '';
            }
            $out = '';
            while (($chunk = $this->body->read()) !== null) {
                $out .= $chunk;
            }

            return $this->cached = $out;
        } catch (\Throwable $exception) {
            throw withClientRequestId($exception, $this->http->clientRequestId);
        }
    }
}
