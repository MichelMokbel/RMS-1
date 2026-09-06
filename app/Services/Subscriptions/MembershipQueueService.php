<?php

namespace App\Services\Subscriptions;

use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionDay;
use App\Models\MealSubscriptionPause;
use App\Models\MembershipBookingFunding;
use App\Models\MembershipPlan;
use App\Models\MembershipPurchaseBlock;
use App\Models\Payment;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderTransaction;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Customers\CustomerOwnershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class MembershipQueueService
{
    public function __construct(
        private readonly MealSubscriptionCodeService $codes,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly AccountingAuditLogService $auditLog,
    ) {}

    /** @return array<string, mixed> */
    public function summary(int $customerId, int $companyId, int $branchId, string $currency = 'QAR'): array
    {
        $roots = $this->compatibleRoots($customerId, $companyId, $branchId, $currency);
        $blocks = MembershipPurchaseBlock::query()
            ->with('plan:id,code')
            ->whereIn('subscription_id', $roots->pluck('id'))
            ->orderBy('funded_at')
            ->orderBy('id')
            ->get();
        $activeBlocks = $blocks->whereNull('cancelled_at');
        $total = (int) $activeBlocks->sum('meal_count');
        $used = (int) $roots->sum('meals_used');
        $activeFunding = MembershipBookingFunding::query()
            ->with('subscriptionOrder:id,service_date')
            ->whereIn('purchase_block_id', $activeBlocks->pluck('id'))
            ->whereIn('state', ['reserved', 'invoiced'])
            ->get();
        $reserved = (int) $activeFunding->where('state', 'reserved')->sum('main_quantity');
        $available = $total - $used - $reserved;
        if ($available < 0) {
            throw new \RuntimeException('Membership queue usage exceeds its funded allowance.');
        }

        $firstRoot = $roots->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();
        $today = CarbonImmutable::now('Asia/Qatar')->toDateString();
        $upcoming = (int) $activeFunding
            ->filter(fn (MembershipBookingFunding $row): bool => $row->subscriptionOrder?->service_date?->toDateString() > $today)
            ->sum('main_quantity');
        $pausePeriods = MealSubscriptionPause::query()
            ->whereIn('subscription_id', $roots->pluck('id'))
            ->whereNull('resumed_at')
            ->orderBy('pause_start')
            ->orderBy('id')
            ->get()
            ->map(fn (MealSubscriptionPause $pause): array => [
                'start' => $pause->pause_start?->toDateString(),
                'end' => $pause->pause_end?->toDateString(),
                'reason' => $pause->reason,
            ])
            ->all();

        return [
            'queue_subscription_id' => $firstRoot?->id,
            'queue_reference' => $firstRoot?->subscription_code,
            'queue_revision' => (int) $roots->sum('queue_revision'),
            'total_meals' => $total,
            'used_meals' => $used,
            'selected_meals' => $used + $reserved,
            'reserved_meals' => $reserved,
            'available_meals' => $available,
            'upcoming_meals' => $upcoming,
            'pause_periods' => $pausePeriods,
            'blocks' => $blocks->map(fn (MembershipPurchaseBlock $block): array => [
                'id' => (int) $block->id,
                'plan_code' => $block->plan?->code,
                'queue_position' => (int) $block->queue_position,
                'meal_count' => (int) $block->meal_count,
                'remaining_meals' => $block->cancelled_at ? 0 : $this->blockRemaining($block, $activeFunding),
                'status' => $block->cancelled_at ? 'cancelled' : 'active',
                'funded_at' => $block->funded_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return Collection<int, MealSubscription> */
    public function lockCompatibleRoots(int $customerId, int $companyId, int $branchId, string $currency = 'QAR'): Collection
    {
        return $this->compatibleRoots($customerId, $companyId, $branchId, $currency, true);
    }

    /** @return Collection<int, MealSubscription> */
    public function compatibleRootsForRead(int $customerId, int $companyId, int $branchId, string $currency = 'QAR'): Collection
    {
        return $this->compatibleRoots($customerId, $companyId, $branchId, $currency);
    }

    private function blockRemaining(MembershipPurchaseBlock $block, Collection $activeFunding): int
    {
        $activeQuantity = (int) $activeFunding
            ->where('purchase_block_id', $block->id)
            ->sum('main_quantity');
        $remaining = (int) $block->meal_count
            - (int) $block->opening_used_quantity
            + (int) $block->opening_released_quantity
            - $activeQuantity;
        if ($remaining < 0) {
            throw new \RuntimeException('Membership block usage exceeds its funded allowance.');
        }

        return $remaining;
    }

    /**
     * Convert one verified on time membership payment while the caller owns the
     * surrounding financial transaction and has already locked the attempt.
     *
     * @return array{subscription:MealSubscription,block:MembershipPurchaseBlock,request:MealPlanRequest}
     */
    public function convertPaidPurchase(
        PaymentCheckoutAttempt $attempt,
        PaymentCheckoutTarget $target,
        PaymentProviderTransaction $providerTransaction,
        Payment $payment,
        MembershipPlan $plan,
        int $actorId,
    ): array {
        $customer = $this->customerOwnership->lockCanonicalCustomer((int) $attempt->customer_id);
        $request = MealPlanRequest::query()->lockForUpdate()->findOrFail($target->meal_plan_request_id);
        $existingBlock = MembershipPurchaseBlock::query()
            ->where('payment_id', $payment->id)
            ->lockForUpdate()
            ->first();
        if ($existingBlock) {
            return [
                'subscription' => $existingBlock->subscription()->firstOrFail(),
                'block' => $existingBlock,
                'request' => $request,
            ];
        }

        $this->assertPurchaseMatches($attempt, $target, $providerTransaction, $payment, $plan, $request, $customer->id);
        $roots = $this->compatibleRoots(
            $customer->id,
            (int) $attempt->company_id,
            (int) $attempt->branch_id,
            'QAR',
            true,
        );
        $subscription = $roots->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();
        if (! $subscription) {
            $purchaseDate = CarbonImmutable::parse($providerTransaction->verified_finished_at)
                ->setTimezone('Asia/Qatar')
                ->toDateString();
            $subscription = MealSubscription::query()->create([
                'subscription_code' => $this->codes->generate(),
                'customer_id' => $customer->id,
                'branch_id' => $attempt->branch_id,
                'status' => 'active',
                'start_date' => $purchaseDate,
                'end_date' => null,
                'plan_meals_total' => 0,
                'meals_used' => 0,
                'meal_plan_request_id' => $request->id,
                'default_order_type' => 'Delivery',
                'delivery_time' => null,
                'address_snapshot' => $attempt->customer_snapshot['address'] ?? null,
                'phone_snapshot' => $attempt->customer_snapshot['phone'] ?? null,
                'preferred_role' => 'main',
                'include_salad' => true,
                'include_dessert' => true,
                'notes' => null,
                'created_by' => $actorId,
                'source_payment_id' => null,
                'uses_invoice_tracking' => true,
                'fulfillment_mode' => 'customer_selection',
                'queue_company_id' => $attempt->company_id,
                'queue_currency' => 'QAR',
                'queue_revision' => 0,
            ]);
            foreach (range(1, 7) as $weekday) {
                MealSubscriptionDay::query()->create([
                    'subscription_id' => $subscription->id,
                    'weekday' => $weekday,
                ]);
            }
        } else {
            $subscription = MealSubscription::query()->lockForUpdate()->findOrFail($subscription->id);
        }

        $position = (int) MembershipPurchaseBlock::query()
            ->where('subscription_id', $subscription->id)
            ->lockForUpdate()
            ->max('queue_position') + 1;
        $block = MembershipPurchaseBlock::query()->create([
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'payment_id' => $payment->id,
            'meal_plan_request_id' => $request->id,
            'company_id' => $attempt->company_id,
            'branch_id' => $attempt->branch_id,
            'original_customer_id' => $customer->id,
            'queue_position' => $position,
            'meal_count' => $plan->meal_count,
            'gross_price_cents' => $attempt->gross_amount_cents,
            'discount_cents' => $attempt->discount_amount_cents,
            'final_price_cents' => $attempt->payable_amount_cents,
            'currency' => 'QAR',
            'origin' => 'checkout',
            'origin_key' => 'checkout:'.$attempt->id,
            'quote_fingerprint' => $attempt->quote_fingerprint,
            'pricing_snapshot' => $attempt->pricing_snapshot,
            'terms_snapshot' => $attempt->terms_snapshot,
            'funded_at' => $providerTransaction->verified_finished_at,
            'created_by' => $actorId,
        ]);

        $subscription->update([
            'status' => 'active',
            'end_date' => null,
            'plan_meals_total' => (int) $subscription->plan_meals_total + (int) $plan->meal_count,
            'queue_revision' => (int) $subscription->queue_revision + 1,
        ]);
        $request->update([
            'status' => 'converted',
            'converted_subscription_id' => $subscription->id,
            'converted_at' => now('UTC'),
        ]);

        $this->auditLog->log('membership.purchase.converted', $actorId, $block, [
            'checkout_attempt_id' => $attempt->id,
            'meal_plan_request_id' => $request->id,
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
            'queue_position' => $position,
            'meal_count' => (int) $plan->meal_count,
            'gross_price_cents' => (int) $attempt->gross_amount_cents,
            'discount_cents' => (int) $attempt->discount_amount_cents,
            'final_price_cents' => (int) $attempt->payable_amount_cents,
        ], (int) $attempt->company_id);

        return [
            'subscription' => $subscription->fresh(),
            'block' => $block,
            'request' => $request->fresh(),
        ];
    }

    /** @return Collection<int, MealSubscription> */
    private function compatibleRoots(
        int $customerId,
        int $companyId,
        int $branchId,
        string $currency,
        bool $forUpdate = false,
    ): Collection {
        return MealSubscription::query()
            ->whereIn('customer_id', $this->customerOwnership->historicalCustomerIds($customerId))
            ->where('fulfillment_mode', 'customer_selection')
            ->where('queue_company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('queue_currency', $currency)
            ->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    private function assertPurchaseMatches(
        PaymentCheckoutAttempt $attempt,
        PaymentCheckoutTarget $target,
        PaymentProviderTransaction $providerTransaction,
        Payment $payment,
        MembershipPlan $plan,
        MealPlanRequest $request,
        int $customerId,
    ): void {
        if ($attempt->purpose !== 'membership'
            || $target->target_type !== 'meal_plan_request'
            || (int) $request->checkout_id !== (int) $attempt->id
            || (int) $request->customer_id !== $customerId
            || $request->submission_kind !== 'paid_checkout'
            || $request->status !== 'new'
            || (int) $request->plan_meals !== (int) $plan->meal_count
            || (int) $plan->company_id !== (int) $attempt->company_id
            || (int) $payment->customer_id !== $customerId
            || (int) $payment->company_id !== (int) $attempt->company_id
            || (int) $payment->branch_id !== (int) $attempt->branch_id
            || (int) $payment->amount_cents !== (int) $attempt->payable_amount_cents
            || $payment->currency !== 'QAR'
            || $payment->method !== 'skipcash'
            || (int) $providerTransaction->payment_id !== (int) $payment->id) {
            throw new \RuntimeException('The paid membership purchase does not match its retained checkout.');
        }
    }
}
