<?php

namespace App\Services\Customers;

use Illuminate\Validation\ValidationException;

class PhoneNumberService
{
    public function normalize(?string $phone): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return null;
        }

        $normalized = preg_replace('/[^\d+]+/', '', $phone) ?? '';

        if (str_starts_with($normalized, '00')) {
            $normalized = '+'.substr($normalized, 2);
        }

        if (str_starts_with($normalized, '+')) {
            $digits = preg_replace('/\D+/', '', substr($normalized, 1)) ?? '';

            return $digits === '' ? null : '+'.$digits;
        }

        $digits = preg_replace('/\D+/', '', $normalized) ?? '';
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return null;
        }

        $defaultDigits = preg_replace('/\D+/', '', (string) config('customers.default_country_code', '+974')) ?: '974';
        $localLength = max(1, (int) config('customers.local_phone_length', 8));

        if (strlen($digits) <= $localLength) {
            return '+'.$defaultDigits.$digits;
        }

        return '+'.$digits;
    }

    public function normalizeOrFail(?string $phone): string
    {
        $normalized = $this->normalize($phone);

        if ($normalized === null) {
            throw ValidationException::withMessages([
                'phone' => __('A valid phone number is required.'),
            ]);
        }

        return $normalized;
    }

    public function mask(string $phoneE164): string
    {
        $digits = preg_replace('/\D+/', '', $phoneE164) ?? '';
        if (strlen($digits) <= 4) {
            return $phoneE164;
        }

        return '+'.substr($digits, 0, max(1, strlen($digits) - 4)).str_repeat('*', 4);
    }
}
