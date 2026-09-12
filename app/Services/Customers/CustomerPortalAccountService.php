<?php

namespace App\Services\Customers;

use App\Models\CustomerPhoneVerificationChallenge;
use App\Models\User;

class CustomerPortalAccountService
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumbers,
        private readonly CustomerDeliveryLocationService $deliveryLocations,
    ) {}

    public function isLinked(User $user): bool
    {
        return (int) ($user->customer_id ?? 0) > 0 && $user->relationLoaded('customer')
            ? $user->customer !== null
            : (int) ($user->customer_id ?? 0) > 0;
    }

    public function isPhoneVerified(User $user): bool
    {
        return (bool) $this->phoneVerification($user)['satisfied'];
    }

    /**
     * @return array{method:string,satisfied:bool,required:bool,verified_at:string|null,phone_masked:string|null}
     */
    public function phoneVerification(User $user): array
    {
        $effectivePhone = $this->effectivePhone($user);
        $challenge = $effectivePhone === null
            ? null
            : CustomerPhoneVerificationChallenge::query()
                ->where('user_id', $user->id)
                ->where('phone_e164', $effectivePhone)
                ->whereNull('cancelled_at')
                ->whereNotNull('verified_at')
                ->whereIn('purpose', [
                    CustomerPhoneVerificationService::PURPOSE_SIGNUP,
                    CustomerPhoneVerificationService::PURPOSE_PHONE_CHANGE,
                    CustomerPhoneVerificationService::PURPOSE_CURRENT_PHONE,
                ])
                ->latest('verified_at')
                ->first();

        if ($challenge !== null) {
            return [
                'method' => 'sms',
                'satisfied' => true,
                'required' => true,
                'verified_at' => $challenge->verified_at?->toIso8601String(),
                'phone_masked' => $this->phoneNumbers->mask($effectivePhone),
            ];
        }

        $bypassEnabled = (bool) config('customers.verification_bypass', false);

        return [
            'method' => $bypassEnabled ? 'bypass' : 'unverified',
            'satisfied' => $bypassEnabled,
            'required' => ! $bypassEnabled,
            'verified_at' => null,
            'phone_masked' => $effectivePhone === null ? null : $this->phoneNumbers->mask($effectivePhone),
        ];
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
                'delivery_location' => $this->deliveryLocations->serialize($user, $customer),
                'billing_address' => $customer?->billing_address,
                'customer_type' => $customer?->customer_type,
                'data_source' => $linked ? 'customer' : 'portal',
            ],
            'linked_customer' => $linked,
            'link_status' => $linked ? 'linked' : 'unlinked',
            'phone_verification' => $this->phoneVerification($user),
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

    private function effectivePhone(User $user): ?string
    {
        $user->loadMissing('customer');

        return $this->phoneNumbers->normalize($user->portal_phone_e164)
            ?? $this->phoneNumbers->normalize($user->customer?->phone_e164);
    }
}
