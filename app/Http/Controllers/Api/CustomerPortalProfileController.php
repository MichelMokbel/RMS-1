<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CustomerPortalConflictException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerPhoneVerificationService;
use App\Services\Customers\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerPortalProfileController extends Controller
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumbers,
        private readonly CustomerPhoneVerificationService $verification,
    ) {
    }

    public function startPhoneChange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user()->load('customer');
        $customer = $user->customer;
        $phoneE164 = $this->phoneNumbers->normalizeOrFail($data['phone']);

        if (! $customer) {
            return response()->json(['message' => 'Customer account not found.'], 404);
        }

        if ($customer->phone_e164 === $phoneE164) {
            return response()->json(['message' => 'That phone number is already active on this account.'], 409);
        }

        $matches = Customer::query()
            ->where('phone_e164', $phoneE164)
            ->whereKeyNot($customer->id)
            ->with('user')
            ->get();

        if ($matches->count() > 1 || $matches->contains(fn (Customer $match) => $match->user !== null)) {
            return response()->json([
                'message' => 'This phone number is already linked to another customer account.',
            ], 409);
        }

        $challenge = $this->verification->createChallenge(
            $user,
            $customer,
            CustomerPhoneVerificationService::PURPOSE_PHONE_CHANGE,
            $phoneE164,
            $request->ip(),
            $request->userAgent()
        );

        return response()->json([
            'phone_change_token' => $this->verification->createChallengeToken($challenge, [
                'phone_raw' => $data['phone'],
            ]),
            'phone' => [
                'e164' => $phoneE164,
                'masked' => $this->phoneNumbers->mask($phoneE164),
            ],
        ]);
    }

    public function verifyPhoneChange(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone_change_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:'.(int) config('customers.verification_code_length', 6)],
        ]);

        $payload = $this->verification->decodeChallengeToken(
            $data['phone_change_token'],
            CustomerPhoneVerificationService::PURPOSE_PHONE_CHANGE
        );
        $challenge = $this->verification->resolveChallengeFromToken(
            $data['phone_change_token'],
            CustomerPhoneVerificationService::PURPOSE_PHONE_CHANGE
        );
        $challenge = $this->verification->verifyChallenge($challenge, $data['code']);
        $customer = $challenge->customer()->firstOrFail();

        $customer->forceFill([
            'phone' => (string) ($payload['phone_raw'] ?? $customer->phone_e164),
            'phone_e164' => $challenge->phone_e164,
            'phone_verified_at' => now(),
        ])->save();

        /** @var User $user */
        $user = $request->user()->load('customer');

        return response()->json([
            'account' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'customer' => [
                    'id' => $user->customer?->id,
                    'name' => $user->customer?->name,
                    'email' => $user->customer?->email,
                    'phone' => $user->customer?->phone,
                    'phone_e164' => $user->customer?->phone_e164,
                    'phone_verified_at' => $user->customer?->phone_verified_at?->toIso8601String(),
                    'delivery_address' => $user->customer?->delivery_address,
                    'billing_address' => $user->customer?->billing_address,
                    'customer_type' => $user->customer?->customer_type,
                ],
            ],
        ]);
    }
}
