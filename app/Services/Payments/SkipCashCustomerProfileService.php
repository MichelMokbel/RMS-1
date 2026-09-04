<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Services\Customers\PhoneNumberService;
use Illuminate\Validation\ValidationException;

class SkipCashCustomerProfileService
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumbers,
    ) {}

    /** @return array{full_name:string,first_name:string,last_name:string,phone:string,email:string,address:string|null} */
    public function snapshot(User $user): array
    {
        $name = $this->normalizeName((string) ($user->portal_name ?: $user->name));
        $phone = $this->phoneNumbers->normalize($user->portal_phone_e164)
            ?? $this->phoneNumbers->normalize($user->customer?->phone_e164);
        $email = trim((string) $user->email);

        if ($name === null) {
            throw $this->profileError('name', __('Add your full name before paying.'));
        }
        if ($phone === null || mb_strlen($phone) > 15) {
            throw $this->profileError('phone', __('Add a valid phone number before paying.'));
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw $this->profileError('email', __('Add a valid email address before paying.'));
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $firstName = (string) ($parts[0] ?? '');
        $lastName = count($parts) === 1 ? $firstName : implode(' ', array_slice($parts, 1));
        if (mb_strlen($firstName) > 60 || mb_strlen($lastName) > 60) {
            throw $this->profileError('name', __('Your name is too long for payment processing.'));
        }

        return [
            'full_name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'email' => $email,
            'address' => filled($user->portal_delivery_address)
                ? (string) $user->portal_delivery_address
                : $user->customer?->delivery_address,
        ];
    }

    private function normalizeName(string $value): ?string
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            return null;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?: '';

        return $value === '' ? null : $value;
    }

    private function profileError(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([
            'profile.'.$field => $message,
            'profile_code' => 'PROFILE_REQUIRED',
        ]);
    }
}
