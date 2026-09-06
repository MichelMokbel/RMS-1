<?php

namespace App\Services\Payments;

use App\Jobs\RunPaymentConsistency;
use App\Jobs\RunPromotionPaymentConsistency;
use App\Models\PaymentConsistencyRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentConsistencyDispatchService
{
    public function checkoutGraphAfterCommit(
        int $attemptId,
        string $sourceType,
        int $sourceId,
        string $transition,
    ): void {
        if (! $this->enabled() || $attemptId <= 0 || $sourceId <= 0) {
            return;
        }

        DB::afterCommit(function () use ($attemptId, $sourceType, $sourceId, $transition): void {
            $attempt = DB::table('payment_checkout_attempts')->where('id', $attemptId)->first(['id', 'purpose']);
            if (! $attempt) {
                return;
            }
            $this->dispatchTargeted('provider_checkout_v1', 'payment_checkout_attempt', $attemptId, $sourceType, $sourceId, $transition);
            $this->dispatchTargeted('notification_operations_v1', 'payment_checkout_attempt', $attemptId, $sourceType, $sourceId, $transition);
            $targets = DB::table('payment_checkout_targets')->where('attempt_id', $attemptId)->orderBy('id')->get([
                'id', 'target_type', 'meal_plan_request_id',
            ]);
            if ($attempt->purpose === 'ordinary_order') {
                foreach ($targets as $target) {
                    $this->dispatchTargeted('ordinary_accounting_v1', 'payment_checkout_target', (int) $target->id, $sourceType, $sourceId, $transition);
                }
            }
            foreach ($targets->pluck('meal_plan_request_id')->filter()->unique() as $requestId) {
                $blockId = DB::table('membership_purchase_blocks')->where('meal_plan_request_id', $requestId)->value('id');
                if ($blockId) {
                    $this->dispatchTargeted('membership_purchase_v1', 'membership_purchase_block', (int) $blockId, $sourceType, $sourceId, $transition);
                    $subscriptionId = DB::table('membership_purchase_blocks')->where('id', $blockId)->value('subscription_id');
                    if ($subscriptionId) {
                        $this->dispatchTargeted('membership_balance_v1', 'meal_subscription', (int) $subscriptionId, $sourceType, $sourceId, $transition);
                        $this->dispatchTargeted('membership_sequence_v1', 'meal_subscription', (int) $subscriptionId, $sourceType, $sourceId, $transition);
                    }
                }
            }
            $paymentIds = DB::table('payment_provider_transactions')->where('attempt_id', $attemptId)->pluck('payment_id')->filter()->unique();
            foreach ($paymentIds as $paymentId) {
                $this->dispatchTargeted('saved_credit_v1', 'payment', (int) $paymentId, $sourceType, $sourceId, $transition);
            }
        });
    }

    public function customerAfterCommit(int $customerId, string $sourceType, int $sourceId, string $transition): void
    {
        $this->ruleAfterCommit('customer_ownership_v1', 'customer', $customerId, $sourceType, $sourceId, $transition);
    }

    public function paymentAfterCommit(int $paymentId, string $sourceType, int $sourceId, string $transition): void
    {
        $this->ruleAfterCommit('saved_credit_v1', 'payment', $paymentId, $sourceType, $sourceId, $transition);
    }

    public function membershipQueueAfterCommit(int $subscriptionId, string $sourceType, int $sourceId, string $transition): void
    {
        $this->ruleAfterCommit('membership_balance_v1', 'meal_subscription', $subscriptionId, $sourceType, $sourceId, $transition);
        $this->ruleAfterCommit('membership_sequence_v1', 'meal_subscription', $subscriptionId, $sourceType, $sourceId, $transition);
    }

    public function bookingAfterCommit(int $subscriptionOrderId, int $subscriptionId, string $sourceType, int $sourceId, string $transition): void
    {
        $this->ruleAfterCommit('booking_correction_v1', 'meal_subscription_order', $subscriptionOrderId, $sourceType, $sourceId, $transition);
        $this->ruleAfterCommit('booking_policy_v1', 'meal_subscription_order', $subscriptionOrderId, $sourceType, $sourceId, $transition);
        $this->ruleAfterCommit('notification_operations_v1', 'meal_subscription_order', $subscriptionOrderId, $sourceType, $sourceId, $transition);
        $this->membershipQueueAfterCommit($subscriptionId, $sourceType, $sourceId, $transition);
    }

    public function settlementAfterCommit(int $importId, string $sourceType, int $sourceId, string $transition): void
    {
        $this->ruleAfterCommit('settlement_v1', 'gateway_settlement_import', $importId, $sourceType, $sourceId, $transition);
    }

    public function notificationAfterCommit(string $subjectType, int $subjectId, string $sourceType, int $sourceId, string $transition): void
    {
        $this->ruleAfterCommit('notification_operations_v1', $subjectType, $subjectId, $sourceType, $sourceId, $transition);
    }

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
        ?int $parentRunId = null,
    ): bool {
        if (! $this->enabled() || $promotionId <= 0) {
            return false;
        }

        try {
            RunPromotionPaymentConsistency::dispatch($promotionId, $kind, $triggerKey, $requestedBy, $parentRunId);

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

    public function ruleAfterCommit(
        string $ruleCode,
        string $subjectType,
        int $subjectId,
        string $sourceType,
        int $sourceId,
        string $transition,
    ): void {
        if (! $this->enabled() || $subjectId <= 0 || $sourceId <= 0) {
            return;
        }

        $triggerKey = $this->targetedTriggerKey(
            $ruleCode.':'.$sourceType,
            $sourceId,
            $transition.':'.$subjectType.':'.$subjectId,
        );
        DB::afterCommit(fn (): bool => $this->dispatchRule(
            $ruleCode,
            $subjectType,
            $subjectId,
            PaymentConsistencyRun::KIND_TARGETED,
            $triggerKey,
        ));
    }

    public function dispatchRule(
        string $ruleCode,
        string $subjectType,
        int $subjectId,
        string $kind,
        string $triggerKey,
        ?int $requestedBy = null,
        ?int $parentRunId = null,
    ): bool {
        if (! $this->enabled() || $subjectId <= 0) {
            return false;
        }

        try {
            RunPaymentConsistency::dispatch(
                $ruleCode,
                $subjectType,
                $subjectId,
                $kind,
                $triggerKey,
                $requestedBy,
                $parentRunId,
            );

            return true;
        } catch (Throwable $exception) {
            Log::warning('payment_consistency_dispatch_failed', [
                'rule_code' => $ruleCode,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
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

    private function dispatchTargeted(
        string $ruleCode,
        string $subjectType,
        int $subjectId,
        string $sourceType,
        int $sourceId,
        string $transition,
    ): void {
        $triggerKey = $this->targetedTriggerKey(
            $ruleCode.':'.$sourceType,
            $sourceId,
            $transition.':'.$subjectType.':'.$subjectId,
        );
        $this->dispatchRule(
            $ruleCode,
            $subjectType,
            $subjectId,
            PaymentConsistencyRun::KIND_TARGETED,
            $triggerKey,
        );
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
