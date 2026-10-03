<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk;

use Psr\Http\Message\StreamInterface;
use XaiOfficial\Sdk\Errors\ErrorFactory;
use XaiOfficial\Sdk\Types\HttpMeta;

final class BinaryResponse
{
    public function __construct(
        public readonly ?StreamInterface $body,
        public readonly HttpMeta $http,
        public readonly ?string $contentType = null,
    ) {
        $this->contentType ??= $http->header('content-type');
    }

    public function bytes(): string
    {
        try {
            return $this->body?->getContents() ?? '';
        } catch (\Throwable $e) {
            throw ErrorFactory::withClientRequestId($e, $this->http->clientRequestId);
        }
    }

    public function text(): string
    {
        return $this->bytes();
    }
}
