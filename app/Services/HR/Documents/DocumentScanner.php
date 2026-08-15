<?php

namespace App\Services\HR\Documents;

interface DocumentScanner
{
    public function scan(string $absolutePath): DocumentScanResult;
}
