<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerIdentityResolver;
use App\Services\Customers\CustomerMatchingDispatchService;
use App\Services\Customers\CustomerMatchingService;
use App\Services\Customers\CustomerPhoneVerificationService;
use App\Services\Customers\CustomerPortalAccountService;
use App\Services\Customers\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerPortalProfileController extends Controller
{
    public function __construct(
        private readonly CustomerPortalAccountService $accounts,
        private readonly CustomerIdentityResolver $identities,
        private readonly CustomerMatchingService $matching,
        private readonly CustomerMatchingDispatchService $matchingDispatches,
        private readonly PhoneNumberService $phoneNumbers,
        private readonly CustomerPhoneVerificationService $verification,
    ) {}

    public function startCurrentPhoneVerification(Request $request): JsonResponse
    {
        if ($this->verificationBypassEnabled()) {
            return response()->json([
                'message' => 'Phone verification is temporarily bypassed.',
                'code' => 'PHONE_VERIFICATION_BYPASSED',
            ], 409);
        }

        /** @var User $user */
        $user = $request->user()->load('customer');
        $state = $this->accounts->phoneVerification($user);
        if ($state['method'] === 'sms' && $state['satisfied']) {
            return response()->json([
                'message' => 'This phone number is already verified.',
                'code' => 'PHONE_ALREADY_VERIFIED',
            ], 409);
        }

        $phoneE164 = $this->matching->effectivePhone($user);
        if ($phoneE164 === null) {
            throw ValidationException::withMessages([
                'phone' => __('Add a valid phone number before verification.'),
            ]);
        }

        $challenge = $this->verification->createChallenge(
            $user,
            $user->customer,
            CustomerPhoneVerificationService::PURPOSE_CURRENT_PHONE,
            $phoneE164,
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'verification_token' => $this->verification->createChallengeToken($challenge),
            'phone' => [
                'masked' => $this->phoneNumbers->mask($phoneE164),
            ],
        ]);
    }

    public function verifyCurrentPhone(Request $request): JsonResponse
    {
        if ($this->verificationBypassEnabled()) {
            return response()->json([
                'message' => 'Phone verification is temporarily bypassed.',
                'code' => 'PHONE_VERIFICATION_BYPASSED',
            ], 409);
        }

        $data = $request->validate([
            'verification_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:'.(int) config('customers.verification_code_length', 6)],
        ]);

        /** @var User $user */
        $user = $request->user()->load('customer');
        $payload = $this->verification->decodeChallengeToken(
            $data['verification_token'],
            CustomerPhoneVerificationService::PURPOSE_CURRENT_PHONE,
        );
        $this->assertTokenOwner($payload, $user);
        $challenge = $this->verification->resolveChallengeFromToken(
            $data['verification_token'],
            CustomerPhoneVerificationService::PURPOSE_CURRENT_PHONE,
        );
        if ($challenge->phone_e164 !== $this->matching->effectivePhone($user)) {
            return response()->json([
                'message' => 'The phone number changed. Start verification again.',
                'code' => 'PHONE_VERIFICATION_STALE',
            ], 409);
        }

        $verified = $this->verification->verifyChallenge($challenge, $data['code']);

        $user = DB::transaction(function () use ($user, $verified): User {
            $user = $this->accounts->markPortalPhoneVerified(
                $user,
                (string) ($user->portal_phone ?: $verified->phone_e164),
                $verified->phone_e164,
            );
            if ($user->customer && $this->phoneNumbers->normalize($user->customer->phone_e164) === $verified->phone_e164) {
                $user->customer->forceFill(['phone_verified_at' => $verified->verified_at])->save();
            }

            return $this->identities->resolveForRegistration(
                $user,
                CustomerIdentityResolver::VERIFICATION_SMS,
                (int) $verified->id,
            );
        });

        return response()->json([
            'account' => $this->accounts->serializeAccount($user->fresh('customer')),
        ]);
    }

    public function resendCurrentPhoneVerification(Request $request): JsonResponse
    {
        if ($this->verificationBypassEnabled()) {
            return response()->json([
                'message' => 'Phone verification is temporarily bypassed.',
                'code' => 'PHONE_VERIFICATION_BYPASSED',
            ], 409);
        }

        $data = $request->validate([
            'verification_token' => ['required', 'string'],
        ]);
        /** @var User $user */
        $user = $request->user()->load('customer');
        $payload = $this->verification->decodeChallengeToken(
            $data['verification_token'],
            CustomerPhoneVerificationService::PURPOSE_CURRENT_PHONE,
        );
        $this->assertTokenOwner($payload, $user);
        $challenge = $this->verification->resolveChallengeFromToken(
            $data['verification_token'],
            CustomerPhoneVerificationService::PURPOSE_CURRENT_PHONE,
        );
        if ($challenge->phone_e164 !== $this->matching->effectivePhone($user)) {
            return response()->json([
                'message' => 'The phone number changed. Start verification again.',
                'code' => 'PHONE_VERIFICATION_STALE',
            ], 409);
        }

        $challenge = $this->verification->resendChallenge($challenge);

        return response()->json([
            'verification_token' => $this->verification->createChallengeToken($challenge),
            'phone' => [
                'masked' => $this->phoneNumbers->mask($challenge->phone_e164),
            ],
        ]);
    }

    public function startPhoneChange(Request $request): JsonResponse
    {
        if ($this->verificationBypassEnabled()) {
            return response()->json([
                'message' => 'Phone changes are temporarily unavailable while phone verification is bypassed.',
                'code' => 'PHONE_CHANGE_TEMPORARILY_DISABLED',
            ], 409);
        }

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:50'],
        ]);

        /** @var User $user */
        $user = $request->user()->load('customer');
        $customer = $user->customer;
        $phoneE164 = $this->phoneNumbers->normalizeOrFail($data['phone']);

        $currentPhoneE164 = $customer?->phone_e164 ?: $user->portal_phone_e164;
        if ($currentPhoneE164 === $phoneE164) {
            return response()->json(['message' => 'That phone number is already active on this account.'], 409);
        }

        $matches = Customer::query()
            ->where('phone_e164', $phoneE164)
            ->when($customer, fn ($query) => $query->whereKeyNot($customer->id))
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
        if ($this->verificationBypassEnabled()) {
            return response()->json([
                'message' => 'Phone changes are temporarily unavailable while phone verification is bypassed.',
                'code' => 'PHONE_CHANGE_TEMPORARILY_DISABLED',
            ], 409);
        }

        $data = $request->validate([
            'phone_change_token' => ['required', 'string'],
            'code' => ['required', 'string', 'size:'.(int) config('customers.verification_code_length', 6)],
        ]);

        $payload = $this->verification->decodeChallengeToken(
            $data['phone_change_token'],
            CustomerPhoneVerificationService::PURPOSE_PHONE_CHANGE
        );
        /** @var User $user */
        $user = $request->user()->load('customer');
        $this->assertTokenOwner($payload, $user);
        $challenge = $this->verification->resolveChallengeFromToken(
            $data['phone_change_token'],
            CustomerPhoneVerificationService::PURPOSE_PHONE_CHANGE
        );
        $challenge = $this->verification->verifyChallenge($challenge, $data['code']);

        if ($user->customer) {
            $user->customer->forceFill([
                'phone' => (string) ($payload['phone_raw'] ?? $user->customer->phone_e164),
                'phone_e164' => $challenge->phone_e164,
                'phone_verified_at' => now(),
            ])->save();
        }
        $user = $this->accounts->markPortalPhoneVerified(
            $user,
            (string) ($payload['phone_raw'] ?? $user->portal_phone ?? $challenge->phone_e164),
            $challenge->phone_e164,
        );
        $this->dispatchCurrentScan($user);

        return response()->json([
            'account' => $this->accounts->serializeAccount($user->fresh('customer')),
        ]);
    }

    private function verificationBypassEnabled(): bool
    {
        return (bool) config('customers.verification_bypass', false);
    }

    /** @param array<string, mixed> $payload */
    private function assertTokenOwner(array $payload, User $user): void
    {
        $payloadCustomerId = $payload['customer_id'] ?? null;
        if ((int) ($payload['user_id'] ?? 0) !== (int) $user->id
            || ($payloadCustomerId === null) !== ($user->customer_id === null)
            || ($payloadCustomerId !== null && (int) $payloadCustomerId !== (int) $user->customer_id)) {
            throw ValidationException::withMessages([
                'token' => __('The verification token does not belong to this account.'),
            ]);
        }
    }

    private function dispatchCurrentScan(User $user): void
    {
        $fingerprint = $this->matching->recordProfileChange($user->fresh('customer'));
        if ($fingerprint !== null && (bool) config('customers.matching_enabled', false)) {
            $this->matchingDispatches->dispatchScan((int) $user->id, $fingerprint);
        }
    }
}
