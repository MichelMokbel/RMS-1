<?php

namespace App\Services\POS\Exceptions;

class PosSyncException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $data = [],
        int $httpStatus = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}

