<?php

namespace App\Services\Customers;

use App\Models\CustomerMatchReview;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CustomerMatchReviewService
{
    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
        private readonly CustomerMergeService $merges,
    ) {}

    public function markDifferent(int $reviewId, User $actor, ?string $note = null): CustomerMatchReview
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($reviewId, $actor, $note): CustomerMatchReview {
            $review = CustomerMatchReview::query()->lockForUpdate()->findOrFail($reviewId);
            if ($review->status === CustomerMatchReview::STATUS_DIFFERENT) {
                return $review;
            }
            if ($review->status === CustomerMatchReview::STATUS_MERGED) {
                throw ValidationException::withMessages([
                    'review' => __('This possible duplicate was already merged.'),
                ]);
            }

            $this->requireAuditStorage();
            $review->forceFill([
                'status' => CustomerMatchReview::STATUS_DIFFERENT,
                'decision_note' => $this->note($note),
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'merge_audit_id' => null,
            ])->save();
            $this->auditLog->log('customer.matching.review_different', $actor->id, $review, [
                'review_id' => (int) $review->id,
                'customer_id' => (int) $review->customer_id,
                'candidate_customer_id' => (int) $review->candidate_customer_id,
                'profile_fingerprint' => (string) $review->profile_fingerprint,
            ]);

            return $review->fresh();
        });
    }

    public function merge(int $reviewId, User $actor, ?string $note = null): CustomerMatchReview
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($reviewId, $actor, $note): CustomerMatchReview {
            $review = CustomerMatchReview::query()->lockForUpdate()->findOrFail($reviewId);
            if ($review->status === CustomerMatchReview::STATUS_MERGED) {
                return $review;
            }

            $auditId = $this->merges->merge(
                $review->customer()->firstOrFail(),
                $review->candidateCustomer()->firstOrFail(),
                (int) $actor->id,
            );

            $review->forceFill([
                'status' => CustomerMatchReview::STATUS_MERGED,
                'decision_note' => $this->note($note),
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'merge_audit_id' => $auditId,
            ])->save();

            return $review->fresh();
        });
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->isAdmin()) {
            abort(403);
        }
    }

    private function requireAuditStorage(): void
    {
        if (! Schema::hasTable('accounting_audit_logs')) {
            throw new RuntimeException('Customer review audit storage is unavailable.');
        }
    }

    private function note(?string $note): ?string
    {
        $value = trim((string) $note);

        return $value === '' ? null : mb_substr($value, 0, 1000);
    }
}
