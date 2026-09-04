<?php

namespace App\Services\Payments;

/**
 * The narrow external boundary used by checkout initiation and verified capture.
 *
 * Implementations return normalized provider facts only. They never create RMS
 * orders, payments, or customer records.
 */
interface SkipCashProvider
{
    /**
     * @param  array<string, string>  $request
     * @return array<string, mixed>
     */
    public function create(array $request): array;

    /**
     * @return array<string, mixed>
     */
    public function details(string $providerPaymentId): array;
}
