<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Http;

final class File extends Blob
{
    public readonly string $name;

    public function __construct(string $bytes, string $name, string $type = '')
    {
        parent::__construct($bytes, $type);
        $this->name = $name;
    }
}
