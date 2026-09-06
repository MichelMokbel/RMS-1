<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerMatchReview;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class CustomerMatchingService
{
    private const PROFILE_VERSION = 'customer-matching-v1';

    private const MAX_SUGGESTIONS = 10;

    private const MIN_NAME_SIMILARITY = 0.80;

    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
        private readonly CustomerOwnershipService $ownership,
        private readonly PhoneNumberService $phoneNumbers,
        private readonly CustomerMatchingDispatchService $dispatches,
    ) {}

    public function normalizeName(?string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $name)) ?: '';

        return mb_strtolower($normalized);
    }

    public function effectivePhone(User $user): ?string
    {
        $user->loadMissing('customer');

        return $this->phoneNumbers->normalize($user->portal_phone_e164)
            ?? $this->phoneNumbers->normalize($user->customer?->phone_e164);
    }

    public function profileFingerprint(User $user, Customer $customer): string
    {
        try {
            $payload = json_encode([
                self::PROFILE_VERSION,
                (string) $user->id,
                (string) $customer->id,
                $this->normalizeName($user->portal_name ?: $user->name),
                $this->effectivePhone($user),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Customer identity fingerprint could not be created.', previous: $exception);
        }

        return hash('sha256', $payload);
    }

    /**
     * @param  array<int, string>  $reasonCodes
     */
    public function rememberCandidate(
        User $user,
        Customer $customer,
        Customer $candidate,
        array $reasonCodes,
        string $fingerprint,
    ): CustomerMatchReview {
        $reasons = collect($reasonCodes)
            ->filter(fn ($reason): bool => in_array($reason, [
                'name_variant',
                'multiple_exact_matches',
                'existing_login',
                'inactive_candidate',
                'name_only_candidate',
            ], true))
            ->unique()
            ->values()
            ->all();

        if ($reasons === [] || $customer->is($candidate)) {
            throw new RuntimeException('A customer match review requires a distinct candidate and a supported reason.');
        }

        $review = CustomerMatchReview::query()->firstOrNew([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'candidate_customer_id' => $candidate->id,
        ]);

        if (! $review->exists || $review->status === CustomerMatchReview::STATUS_PENDING) {
            $review->forceFill([
                'reason_codes' => $reasons,
                'profile_fingerprint' => $fingerprint,
                'status' => CustomerMatchReview::STATUS_PENDING,
            ])->save();
        }

        return $review->fresh();
    }

    /**
     * Scan local customer records and retain a bounded set of possible duplicates.
     *
     * @return Collection<int, CustomerMatchReview>
     */
    public function scan(int $userId, string $fingerprint): Collection
    {
        if (! (bool) config('customers.matching_enabled', false)) {
            return collect();
        }

        $user = User::query()->with('customer')->find($userId);
        if (! $user || ! $user->isActive() || ! $user->isCustomerPortalUser() || ! $user->customer) {
            return collect();
        }

        $canonicalCustomerId = $this->ownership->canonicalCustomerId((int) $user->customer_id);
        if ($canonicalCustomerId !== (int) $user->customer_id) {
            return collect();
        }

        if (! hash_equals($this->profileFingerprint($user, $user->customer), $fingerprint)) {
            return collect();
        }

        $profileName = $this->normalizeName($user->portal_name ?: $user->name);
        $profilePhone = $this->effectivePhone($user);
        $matches = collect();

        Customer::query()
            ->select(['id', 'merged_into_customer_id', 'name', 'phone_e164', 'is_active'])
            ->with('user:id,customer_id')
            ->whereKeyNot($user->customer_id)
            ->orderBy('id')
            ->chunkById(200, function (Collection $customers) use ($canonicalCustomerId, $profileName, $profilePhone, $matches): void {
                foreach ($customers as $candidate) {
                    try {
                        if ($this->ownership->canonicalCustomerId((int) $candidate->id) === $canonicalCustomerId) {
                            continue;
                        }
                    } catch (RuntimeException) {
                        continue;
                    }

                    $candidateName = $this->normalizeName($candidate->name);
                    $samePhone = $profilePhone !== null
                        && $this->phoneNumbers->normalize($candidate->phone_e164) === $profilePhone;
                    $sameName = $profileName !== '' && hash_equals($profileName, $candidateName);
                    $similarity = $sameName ? 1.0 : $this->nameSimilarity($profileName, $candidateName);

                    if (! $samePhone && ! $sameName && $similarity < self::MIN_NAME_SIMILARITY) {
                        continue;
                    }

                    $reasons = [];
                    if (! $candidate->is_active) {
                        $reasons[] = 'inactive_candidate';
                    }
                    if ($candidate->user !== null) {
                        $reasons[] = 'existing_login';
                    }
                    if ($samePhone && ! $sameName) {
                        $reasons[] = 'name_variant';
                    }
                    if (! $samePhone && ($sameName || $similarity >= self::MIN_NAME_SIMILARITY)) {
                        $reasons[] = 'name_only_candidate';
                    }
                    if ($samePhone && $sameName) {
                        $reasons[] = 'multiple_exact_matches';
                    }

                    $matches->push([
                        'candidate' => $candidate,
                        'reasons' => $reasons,
                        'priority' => $samePhone ? 0 : ($sameName ? 1 : 2),
                        'similarity' => $similarity,
                    ]);
                }
            }, 'id');

        $selected = $matches
            ->sort(function (array $left, array $right): int {
                return [$left['priority'], -$left['similarity'], $left['candidate']->id]
                    <=> [$right['priority'], -$right['similarity'], $right['candidate']->id];
            })
            ->take(self::MAX_SUGGESTIONS)
            ->values();

        $reviews = DB::transaction(function () use ($selected, $user, $fingerprint): Collection {
            return $selected->map(fn (array $match): CustomerMatchReview => $this->rememberCandidate(
                $user,
                $user->customer,
                $match['candidate'],
                $match['reasons'],
                $fingerprint,
            ));
        });

        $this->auditLog->log('customer.matching.scan_completed', $user->id, $user->customer, [
            'user_id' => (int) $user->id,
            'customer_id' => (int) $user->customer_id,
            'profile_fingerprint' => $fingerprint,
            'candidate_count' => $reviews->count(),
        ]);

        if ($reviews->isNotEmpty() && (bool) config('customers.matching_ai_enabled', false)) {
            $this->dispatches->dispatchRank((int) $user->id, $fingerprint);
        }

        return $reviews;
    }

    public function recordProfileChange(User $user): ?string
    {
        $user->loadMissing('customer');
        if (! $user->customer) {
            return null;
        }

        $fingerprint = $this->profileFingerprint($user, $user->customer);
        $prior = $this->latestRecordedFingerprint($user);
        if ($prior !== null && hash_equals($prior, $fingerprint)) {
            return $fingerprint;
        }

        $this->auditLog->log('customer.matching.profile_changed', $user->id, $user->customer, [
            'user_id' => (int) $user->id,
            'customer_id' => (int) $user->customer_id,
            'profile_fingerprint' => $fingerprint,
        ]);

        return $fingerprint;
    }

    public function latestRecordedFingerprint(User $user): ?string
    {
        $payload = DB::table('accounting_audit_logs')
            ->where('actor_id', $user->id)
            ->where('subject_id', $user->customer_id)
            ->whereIn('action', ['customer.identity.resolved', 'customer.matching.profile_changed'])
            ->latest('id')
            ->value('payload');

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        $fingerprint = is_array($payload) ? ($payload['profile_fingerprint'] ?? null) : null;

        return is_string($fingerprint) && strlen($fingerprint) === 64 ? $fingerprint : null;
    }

    private function nameSimilarity(string $left, string $right): float
    {
        $leftCharacters = $this->characters($left);
        $rightCharacters = $this->characters($right);
        $longer = max(count($leftCharacters), count($rightCharacters));

        if ($longer === 0) {
            return 0.0;
        }

        return 1 - ($this->editDistance($leftCharacters, $rightCharacters) / $longer);
    }

    /** @return array<int, string> */
    private function characters(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     */
    private function editDistance(array $left, array $right): int
    {
        if ($left === []) {
            return count($right);
        }
        if ($right === []) {
            return count($left);
        }

        $previous = range(0, count($right));
        foreach ($left as $leftIndex => $leftCharacter) {
            $current = [$leftIndex + 1];
            foreach ($right as $rightIndex => $rightCharacter) {
                $current[] = min(
                    $current[$rightIndex] + 1,
                    $previous[$rightIndex + 1] + 1,
                    $previous[$rightIndex] + ($leftCharacter === $rightCharacter ? 0 : 1),
                );
            }
            $previous = $current;
        }

        return $previous[count($right)];
    }
}
