<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerPhoneVerificationChallenge;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CustomerIdentityResolver
{
    public const VERIFICATION_BYPASS = 'bypass';

    public const VERIFICATION_SMS = 'sms';

    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
        private readonly CustomerMatchingService $matching,
        private readonly CustomerMatchingDispatchService $dispatches,
        private readonly CustomerDeliveryLocationService $deliveryLocations,
    ) {}

    public function resolveForRegistration(
        User $user,
        string $verificationMethod,
        ?int $verificationChallengeId = null,
    ): User {
        $dispatch = null;
        $resolved = DB::transaction(function () use ($user, $verificationMethod, $verificationChallengeId, &$dispatch): User {
            $profile = User::query()->with('customer')->findOrFail($user->id);
            $matchingEnabled = (bool) config('customers.matching_enabled', false);
            $profilePhone = $this->matching->effectivePhone($profile);
            $candidates = collect();

            if ($matchingEnabled && $profilePhone !== null) {
                $candidates = Customer::query()
                    ->where('phone_e164', $profilePhone)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
            }

            $linkedUserIds = $candidates->isEmpty()
                ? collect()
                : User::query()->whereIn('customer_id', $candidates->pluck('id'))->pluck('id');
            $lockedUsers = User::query()
                ->with('customer')
                ->whereIn('id', $linkedUserIds->push($profile->id)->unique()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $lockedUser = $lockedUsers->get($profile->id);

            if (! $lockedUser) {
                throw ValidationException::withMessages([
                    'account' => __('This customer account is not available.'),
                ]);
            }

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
            $normalizedName = $this->matching->normalizeName($this->profileName($lockedUser));
            $activeExact = $candidates->filter(fn (Customer $candidate): bool => $candidate->isActive()
                && $candidate->merged_into_customer_id === null
                && hash_equals($normalizedName, $this->matching->normalizeName($candidate->name)));
            $eligibleExact = $activeExact->filter(function (Customer $candidate) use ($lockedUsers): bool {
                return ! $lockedUsers->contains(fn (User $candidateUser): bool => (int) $candidateUser->customer_id === (int) $candidate->id);
            });
            $matchedCustomer = $activeExact->count() === 1 && $eligibleExact->count() === 1
                ? $eligibleExact->first()
                : null;

            if ($matchedCustomer) {
                $matchedCustomer->forceFill(array_merge(
                    $this->deliveryLocations->customerAttributesFromUser($lockedUser),
                    ['updated_by' => $lockedUser->id],
                ))->save();
            }

            $customer = $matchedCustomer ?: Customer::query()->create(array_merge([
                'name' => $this->profileName($lockedUser),
                'customer_type' => Customer::TYPE_RETAIL,
                'contact_name' => $this->profileName($lockedUser),
                'phone' => $lockedUser->portal_phone,
                'phone_e164' => $profilePhone,
                'phone_verified_at' => $proof['verified_at'],
                'email' => $lockedUser->email,
                'delivery_address' => $lockedUser->portal_delivery_address,
                'credit_limit' => 0,
                'credit_terms_days' => 0,
                'credit_status' => null,
                'is_active' => true,
                'created_by' => $lockedUser->id,
                'updated_by' => $lockedUser->id,
            ], $this->deliveryLocations->customerAttributesFromUser($lockedUser)));

            $lockedUser->forceFill([
                'customer_id' => $customer->id,
            ])->save();

            $fingerprint = $this->matching->profileFingerprint($lockedUser->fresh('customer'), $customer);
            $this->rememberKnownCandidates(
                $lockedUser,
                $customer,
                $candidates,
                $activeExact,
                $lockedUsers,
                $fingerprint,
            );
            $this->recordResolution(
                $lockedUser,
                $customer,
                $proof,
                $matchedCustomer ? 'existing_exact_match' : 'separately_owned_fallback',
                $fingerprint,
            );
            if ($matchingEnabled) {
                $dispatch = [(int) $lockedUser->id, $fingerprint];
            }

            return $lockedUser->fresh('customer');
        }, 3);

        if ($dispatch !== null) {
            $this->dispatches->scanAfterCommit($dispatch[0], $dispatch[1]);
        }

        return $resolved;
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
        $challengeId = $this->latestVerificationChallengeId($user);

        if ($challengeId !== null) {
            return $this->resolveForRegistration($user, self::VERIFICATION_SMS, (int) $challengeId);
        }

        return $this->resolveForRegistration($user, self::VERIFICATION_BYPASS);
    }

    public function resolveForLogin(User $user): User
    {
        if ($user->customer_id !== null) {
            return $user->fresh('customer');
        }

        $challengeId = $this->latestVerificationChallengeId($user);
        if ($challengeId !== null) {
            return $this->resolveForRegistration($user, self::VERIFICATION_SMS, $challengeId);
        }

        if ((bool) config('customers.verification_bypass', false)) {
            return $this->resolveForRegistration($user, self::VERIFICATION_BYPASS);
        }

        return $user->fresh('customer');
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
                CustomerPhoneVerificationService::PURPOSE_CURRENT_PHONE,
            ])
            ->first();

        if (! $challenge || $challenge->phone_e164 !== $this->matching->effectivePhone($user)) {
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
    private function recordResolution(
        User $user,
        Customer $customer,
        array $proof,
        string $resolution,
        string $fingerprint,
    ): void {
        if (! Schema::hasTable('accounting_audit_logs')) {
            throw new RuntimeException('Customer identity audit storage is unavailable.');
        }

        $this->auditLog->log('customer.identity.resolved', $user->id, $customer, [
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'resolution' => $resolution,
            'verification_method' => $proof['method'],
            'verification_challenge_id' => $proof['challenge_id'],
            'matching_enabled' => (bool) config('customers.matching_enabled', false),
            'profile_fingerprint' => $fingerprint,
        ]);
    }

    private function profileName(User $user): string
    {
        return trim((string) ($user->portal_name ?: $user->name));
    }

    private function latestVerificationChallengeId(User $user): ?int
    {
        $effectivePhone = $this->matching->effectivePhone($user);
        if ($effectivePhone === null) {
            return null;
        }

        $id = CustomerPhoneVerificationChallenge::query()
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
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Customer>  $candidates
     * @param  \Illuminate\Support\Collection<int, Customer>  $activeExact
     * @param  \Illuminate\Support\Collection<int, User>  $lockedUsers
     */
    private function rememberKnownCandidates(
        User $user,
        Customer $customer,
        $candidates,
        $activeExact,
        $lockedUsers,
        string $fingerprint,
    ): void {
        foreach ($candidates as $candidate) {
            if ($customer->is($candidate)) {
                continue;
            }

            $reasons = [];
            if (! $candidate->isActive() || $candidate->merged_into_customer_id !== null) {
                $reasons[] = 'inactive_candidate';
            }
            if ($lockedUsers->contains(fn (User $candidateUser): bool => (int) $candidateUser->customer_id === (int) $candidate->id)) {
                $reasons[] = 'existing_login';
            }
            if ($activeExact->count() > 1 && $activeExact->contains(fn (Customer $exact): bool => $exact->is($candidate))) {
                $reasons[] = 'multiple_exact_matches';
            }
            if (! $activeExact->contains(fn (Customer $exact): bool => $exact->is($candidate))) {
                $reasons[] = 'name_variant';
            }
            if ($reasons === []) {
                $reasons[] = 'multiple_exact_matches';
            }

            $this->matching->rememberCandidate($user, $customer, $candidate, $reasons, $fingerprint);
        }
    }

    public function profileFingerprint(User $user, Customer $customer): string
    {
        return $this->matching->profileFingerprint($user, $customer);
    }
}
