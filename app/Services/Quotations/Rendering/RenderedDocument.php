<?php

namespace App\Services\Quotations\Rendering;

final readonly class RenderedDocument
{
    public function __construct(
        public string $format,
        public string $mimeType,
        public string $extension,
        public string $contents,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }

    public function checksum(): string
    {
        return hash('sha256', $this->contents);
    }
}
