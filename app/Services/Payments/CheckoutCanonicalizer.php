<?php

namespace App\Services\Payments;

use JsonException;
use RuntimeException;

class CheckoutCanonicalizer
{
    /** @param array<int, mixed> $value */
    public function hash(array $value): string
    {
        return hash('sha256', $this->encode($value));
    }

    /** @param array<int, mixed> $value */
    public function encode(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Checkout data cannot be encoded safely.', previous: $exception);
        }
    }
}
