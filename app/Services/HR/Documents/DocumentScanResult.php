<?php

namespace App\Services\HR\Documents;

final readonly class DocumentScanResult
{
    public function __construct(
        public bool $clean,
        public string $engine = 'none',
        public ?string $reason = null,
    ) {}
}
