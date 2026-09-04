<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerPhoneVerificationChallenge;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class CustomerIdentityResolver
{
    public const VERIFICATION_BYPASS = 'bypass';

    public const VERIFICATION_SMS = 'sms';

    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
        private readonly PhoneNumberService $phoneNumbers,
    ) {}

    public function resolveForRegistration(
        User $user,
        string $verificationMethod,
        ?int $verificationChallengeId = null,
    ): User {
        return DB::transaction(function () use ($user, $verificationMethod, $verificationChallengeId): User {
            $lockedUser = User::query()
                ->with('customer')
                ->lockForUpdate()
                ->findOrFail($user->id);

            if (! $lockedUser->isActive() || ! $lockedUser->isCustomerPortalUser()) {
                throw ValidationException::withMessages([
                    'account' => __('This customer account is not available.'),
                ]);
            }

            if ($lockedUser->customer_id !== null) {
                $customer = Customer::query()
                    ->lockForUpdate()
                    ->find($lockedUser->customer_id);

                if (! $customer?->isActive()) {
                    throw ValidationException::withMessages([
                        'account' => __('This customer account requires support.'),
                    ]);
                }

                return $lockedUser->fresh('customer');
            }

            $proof = $this->resolveProof($lockedUser, $verificationMethod, $verificationChallengeId);
            $customer = Customer::query()->create([
                'name' => $this->profileName($lockedUser),
                'customer_type' => Customer::TYPE_RETAIL,
                'contact_name' => $this->profileName($lockedUser),
                'phone' => $lockedUser->portal_phone,
                'phone_e164' => $this->effectivePhone($lockedUser),
                'phone_verified_at' => $proof['verified_at'],
                'email' => $lockedUser->email,
                'delivery_address' => $lockedUser->portal_delivery_address,
                'credit_limit' => 0,
                'credit_terms_days' => 0,
                'credit_status' => null,
                'is_active' => true,
                'created_by' => $lockedUser->id,
                'updated_by' => $lockedUser->id,
            ]);

            $lockedUser->forceFill([
                'customer_id' => $customer->id,
            ])->save();

            $this->recordResolution($lockedUser, $customer, $proof);

            return $lockedUser->fresh('customer');
        }, 3);
    }

    /**
     * Resolve a separately owned customer before a customer portal financial action.
     *
     * Existing sessions remain usable while the customer link is completed. The
     * registered proof is reused where it exists, and the expressly configured
     * temporary bypass remains a bypass rather than fabricated SMS evidence.
     */
    public function resolveForCheckout(User $user): User
    {
        $effectivePhone = $this->effectivePhone($user);
        $challengeId = $effectivePhone === null
            ? null
            : CustomerPhoneVerificationChallenge::query()
                ->where('user_id', $user->id)
                ->where('phone_e164', $effectivePhone)
                ->whereNull('cancelled_at')
                ->whereNotNull('verified_at')
                ->whereIn('purpose', [
                    CustomerPhoneVerificationService::PURPOSE_SIGNUP,
                    CustomerPhoneVerificationService::PURPOSE_PHONE_CHANGE,
                    'portal_phone_verify',
                ])
                ->latest('verified_at')
                ->value('id');

        if ($challengeId !== null) {
            return $this->resolveForRegistration($user, self::VERIFICATION_SMS, (int) $challengeId);
        }

        return $this->resolveForRegistration($user, self::VERIFICATION_BYPASS);
    }

    /**
     * @return array{method:string,challenge_id:int|null,verified_at:\Carbon\CarbonInterface|null}
     */
    private function resolveProof(User $user, string $verificationMethod, ?int $verificationChallengeId): array
    {
        if ($verificationMethod === self::VERIFICATION_BYPASS) {
            if (! (bool) config('customers.verification_bypass', false)) {
                throw ValidationException::withMessages([
                    'phone' => __('Phone verification is required.'),
                ]);
            }

            return [
                'method' => self::VERIFICATION_BYPASS,
                'challenge_id' => null,
                'verified_at' => null,
            ];
        }

        if ($verificationMethod !== self::VERIFICATION_SMS || $verificationChallengeId === null) {
            throw ValidationException::withMessages([
                'phone' => __('Phone verification is required.'),
            ]);
        }

        $challenge = CustomerPhoneVerificationChallenge::query()
            ->lockForUpdate()
            ->whereKey($verificationChallengeId)
            ->where('user_id', $user->id)
            ->whereNull('cancelled_at')
            ->whereNotNull('verified_at')
            ->whereIn('purpose', [
                CustomerPhoneVerificationService::PURPOSE_SIGNUP,
                CustomerPhoneVerificationService::PURPOSE_PHONE_CHANGE,
                'portal_phone_verify',
            ])
            ->first();

        if (! $challenge || $challenge->phone_e164 !== $this->effectivePhone($user)) {
            throw ValidationException::withMessages([
                'phone' => __('Phone verification is required.'),
            ]);
        }

        return [
            'method' => self::VERIFICATION_SMS,
            'challenge_id' => $challenge->id,
            'verified_at' => $challenge->verified_at,
        ];
    }

    /**
     * @param  array{method:string,challenge_id:int|null,verified_at:\Carbon\CarbonInterface|null}  $proof
     */
    private function recordResolution(User $user, Customer $customer, array $proof): void
    {
        if (! Schema::hasTable('accounting_audit_logs')) {
            throw new RuntimeException('Customer identity audit storage is unavailable.');
        }

        $this->auditLog->log('customer.identity.resolved', $user->id, $customer, [
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'resolution' => 'separately_owned_fallback',
            'verification_method' => $proof['method'],
            'verification_challenge_id' => $proof['challenge_id'],
            'matching_enabled' => (bool) config('customers.matching_enabled', false),
            'profile_fingerprint' => $this->profileFingerprint($user, $customer),
        ]);
    }

    private function profileName(User $user): string
    {
        return trim((string) ($user->portal_name ?: $user->name));
    }

    private function normalizedProfileName(User $user): string
    {
        $name = preg_replace('/\s+/u', ' ', trim($this->profileName($user))) ?: '';

        return mb_strtolower($name);
    }

    private function effectivePhone(User $user): ?string
    {
        $user->loadMissing('customer');

        return $this->phoneNumbers->normalize($user->portal_phone_e164)
            ?? $this->phoneNumbers->normalize($user->customer?->phone_e164);
    }

    private function profileFingerprint(User $user, Customer $customer): string
    {
        try {
            $payload = json_encode([
                'customer-matching-v1',
                (string) $user->id,
                (string) $customer->id,
                $this->normalizedProfileName($user),
                $this->effectivePhone($user),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Customer identity fingerprint could not be created.', previous: $exception);
        }

        return hash('sha256', $payload);
    }
}
