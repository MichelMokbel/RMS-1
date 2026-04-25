<?php

namespace App\Services\Customers;

use App\Models\User;

class CustomerPortalAccountService
{
    public function isLinked(User $user): bool
    {
        return (int) ($user->customer_id ?? 0) > 0 && $user->relationLoaded('customer')
            ? $user->customer !== null
            : (int) ($user->customer_id ?? 0) > 0;
    }

    public function isPhoneVerified(User $user): bool
    {
        $user->loadMissing('customer');

        if ($user->customer) {
            return $user->customer->phone_verified_at !== null;
        }

        return $user->portal_phone_verified_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeAccount(User $user): array
    {
        $user->loadMissing('customer');

        $customer = $user->customer;
        $linked = $customer !== null;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'customer' => [
                'id' => $customer?->id,
                'name' => $customer?->name ?? $user->portal_name ?? $user->name,
                'email' => $customer?->email ?? $user->email,
                'phone' => $customer?->phone ?? $user->portal_phone,
                'phone_e164' => $customer?->phone_e164 ?? $user->portal_phone_e164,
                'phone_verified_at' => ($customer?->phone_verified_at ?? $user->portal_phone_verified_at)?->toIso8601String(),
                'delivery_address' => $customer?->delivery_address ?? $user->portal_delivery_address,
                'billing_address' => $customer?->billing_address,
                'customer_type' => $customer?->customer_type,
                'data_source' => $linked ? 'customer' : 'portal',
            ],
            'linked_customer' => $linked,
            'link_status' => $linked ? 'linked' : 'unlinked',
        ];
    }

    public function markPortalPhoneVerified(User $user, string $phoneRaw, string $phoneE164): User
    {
        $user->forceFill([
            'portal_phone' => $phoneRaw,
            'portal_phone_e164' => $phoneE164,
            'portal_phone_verified_at' => now(),
        ])->save();

        return $user->fresh('customer');
    }
}
