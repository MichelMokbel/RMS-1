<?php

namespace App\Services\Payments;

use App\Jobs\RunPromotionPaymentConsistency;
use App\Models\PaymentConsistencyRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentConsistencyDispatchService
{
    public function promotionAfterCommit(
        int $promotionId,
        string $sourceType,
        int $sourceId,
        string $transition,
    ): void {
        if (! $this->enabled() || $promotionId <= 0 || $sourceId <= 0) {
            return;
        }

        $triggerKey = $this->targetedTriggerKey($sourceType, $sourceId, $transition);
        DB::afterCommit(fn (): bool => $this->dispatchPromotion(
            $promotionId,
            PaymentConsistencyRun::KIND_TARGETED,
            $triggerKey,
        ));
    }

    public function dispatchPromotion(
        int $promotionId,
        string $kind,
        string $triggerKey,
        ?int $requestedBy = null,
    ): bool {
        if (! $this->enabled() || $promotionId <= 0) {
            return false;
        }

        try {
            RunPromotionPaymentConsistency::dispatch($promotionId, $kind, $triggerKey, $requestedBy);

            return true;
        } catch (Throwable $exception) {
            Log::warning('payment_consistency_dispatch_failed', [
                'promotion_id' => $promotionId,
                'kind' => $kind,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function enabled(): bool
    {
        return (bool) config('payment_consistency.enabled', false);
    }

    private function targetedTriggerKey(string $sourceType, int $sourceId, string $transition): string
    {
        $sourceType = $this->boundedSegment($sourceType);
        $transition = $this->boundedSegment($transition);

        return substr("targeted:{$sourceType}:{$sourceId}:{$transition}", 0, 190);
    }

    private function boundedSegment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?: 'unknown';

        return substr(trim($value, '_'), 0, 60) ?: 'unknown';
    }
}
