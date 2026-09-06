<?php

namespace App\Jobs;

use App\Models\CustomerMatchReview;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Ai\AiProviderInterface;
use App\Services\Customers\CustomerMatchingService;
use App\Services\Customers\CustomerOwnershipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RankCustomerMatchCandidates implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public int $timeout = 60;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $userId,
        public readonly string $profileFingerprint,
    ) {}

    public function handle(
        AiProviderInterface $provider,
        CustomerMatchingService $matching,
        CustomerOwnershipService $ownership,
        AccountingAuditLogService $auditLog,
    ): void {
        if (! (bool) config('customers.matching_enabled', false)
            || ! (bool) config('customers.matching_ai_enabled', false)) {
            return;
        }

        $user = User::query()->with('customer')->find($this->userId);
        if (! $user || ! $user->isActive() || ! $user->isCustomerPortalUser() || ! $user->customer) {
            return;
        }
        if (! hash_equals($matching->profileFingerprint($user, $user->customer), $this->profileFingerprint)) {
            return;
        }

        $reviews = CustomerMatchReview::query()
            ->with('candidateCustomer:id,name')
            ->where('user_id', $user->id)
            ->where('customer_id', $user->customer_id)
            ->where('profile_fingerprint', $this->profileFingerprint)
            ->where('status', CustomerMatchReview::STATUS_PENDING)
            ->orderBy('candidate_customer_id')
            ->limit(10)
            ->get();
        if ($reviews->isEmpty()) {
            return;
        }

        $labels = [];
        $candidateNames = [];
        foreach ($reviews->values() as $index => $review) {
            if (! $review->candidateCustomer) {
                continue;
            }
            $label = 'candidate_'.($index + 1);
            $labels[$label] = (int) $review->id;
            $candidateNames[$label] = (string) $review->candidateCustomer->name;
        }
        if ($labels === []) {
            return;
        }

        try {
            $result = $provider->generateStructured([
                [
                    'role' => 'user',
                    'content' => json_encode([
                        'task' => 'Compare the submitted customer name with each candidate name. Return only the requested structured data.',
                        'submitted_name' => (string) ($user->portal_name ?: $user->name),
                        'candidates' => $candidateNames,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ],
            ], [
                'type' => 'object',
                'properties' => [
                    'suggestions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'similarity' => ['type' => 'number'],
                                'reason' => ['type' => 'string', 'enum' => ['exact_name', 'spelling_variant', 'uncertain']],
                            ],
                            'required' => ['label', 'similarity', 'reason'],
                        ],
                    ],
                ],
                'required' => ['suggestions'],
            ]);
        } catch (Throwable) {
            throw new RuntimeException('Customer match ranking is temporarily unavailable.');
        }

        $suggestions = $result['suggestions'] ?? null;
        if (! is_array($suggestions)) {
            throw new RuntimeException('Customer match ranking returned an invalid result.');
        }

        $validated = [];
        foreach ($suggestions as $suggestion) {
            if (! is_array($suggestion)) {
                throw new RuntimeException('Customer match ranking returned an invalid result.');
            }
            $label = $suggestion['label'] ?? null;
            $similarity = $suggestion['similarity'] ?? null;
            $reason = $suggestion['reason'] ?? null;
            if (! is_string($label) || ! isset($labels[$label])) {
                continue;
            }
            if (isset($validated[$label])) {
                continue;
            }
            if (! is_numeric($similarity) || (float) $similarity < 0 || (float) $similarity > 1
                || ! in_array($reason, ['exact_name', 'spelling_variant', 'uncertain'], true)) {
                throw new RuntimeException('Customer match ranking returned an invalid result.');
            }
            $validated[$label] = [
                'similarity' => (float) $similarity,
                'reason' => $reason,
                'model' => (string) config('services.gemini.model', 'gemini-2.5-flash'),
            ];
        }

        DB::transaction(function () use ($labels, $validated, $matching, $ownership, $user, $auditLog): void {
            $freshUser = User::query()->with('customer')->find($user->id);
            if (! $freshUser || ! $freshUser->customer
                || ! hash_equals($matching->profileFingerprint($freshUser, $freshUser->customer), $this->profileFingerprint)) {
                return;
            }

            foreach ($labels as $label => $reviewId) {
                $review = CustomerMatchReview::query()->lockForUpdate()->find($reviewId);
                if (! $review || $review->status !== CustomerMatchReview::STATUS_PENDING
                    || ! hash_equals((string) $review->profile_fingerprint, $this->profileFingerprint)) {
                    continue;
                }
                try {
                    if ($ownership->canonicalCustomerId((int) $review->customer_id)
                        === $ownership->canonicalCustomerId((int) $review->candidate_customer_id)) {
                        continue;
                    }
                } catch (RuntimeException) {
                    continue;
                }
                $review->forceFill([
                    'ai_suggestion' => $validated[$label] ?? null,
                    'ai_checked_at' => now(),
                ])->save();
            }

            $auditLog->log('customer.matching.ai_checked', $freshUser->id, $freshUser->customer, [
                'user_id' => (int) $freshUser->id,
                'customer_id' => (int) $freshUser->customer_id,
                'profile_fingerprint' => $this->profileFingerprint,
                'candidate_count' => count($labels),
                'suggestion_count' => count($validated),
                'model' => (string) config('services.gemini.model', 'gemini-2.5-flash'),
            ]);
        });
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'customer-match-rank:'.$this->userId.':'.$this->profileFingerprint;
    }
}
