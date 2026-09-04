<?php

namespace App\Services\Payments;

use RuntimeException;

class PaymentCheckoutException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $codeName,
        public readonly int $status,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
