<?php

declare(strict_types=1);

namespace TiborSrc\XaiSdkPhp\Http;

final class FormPart
{
    public function __construct(
        public readonly string $name,
        public readonly string $value = '',
        public readonly ?Blob $file = null,
        public readonly ?string $filename = null,
    ) {}
}

final class FormData
{
    /** @var list<FormPart> */
    public array $parts = [];

    public readonly string $boundary;

    public function __construct()
    {
        $this->boundary = '----XaiSdkPhp' . bin2hex(random_bytes(8));
    }

    public function append(string $name, string|Blob $value, ?string $filename = null): void
    {
        if ($value instanceof Blob) {
            $nameForFile = $filename ?? ($value instanceof File ? $value->name : 'blob');
            $this->parts[] = new FormPart($name, '', $value, $nameForFile);

            return;
        }
        $this->parts[] = new FormPart($name, $value);
    }

    public function contentType(): string
    {
        return 'multipart/form-data; boundary=' . $this->boundary;
    }

    public function body(): string
    {
        $out = '';
        foreach ($this->parts as $part) {
            $out .= '--' . $this->boundary . "\r\n";
            if ($part->file instanceof Blob) {
                $filename = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $part->filename);
                $out .= 'Content-Disposition: form-data; name="' . $part->name . '"; filename="' . $filename . "\"\r\n";
                if ($part->file->type !== '') {
                    $out .= 'Content-Type: ' . $part->file->type . "\r\n";
                }
                $out .= "\r\n" . $part->file->bytes . "\r\n";
            } else {
                $out .= 'Content-Disposition: form-data; name="' . $part->name . "\"\r\n\r\n";
                $out .= $part->value . "\r\n";
            }
        }
        $out .= '--' . $this->boundary . "--\r\n";

        return $out;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(static fn (FormPart $part): string => $part->name, $this->parts);
    }

    public function get(string $name): string|Blob|null
    {
        foreach ($this->parts as $part) {
            if ($part->name === $name) {
                return $part->file ?? $part->value;
            }
        }

        return null;
    }
}
