<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Http;

class Blob
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $type = '',
    ) {}
}
