<?php

namespace App\Services\Payments\Consistency;

use App\Models\ArInvoice;
use App\Models\MealPlanRequest;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionOrder;
use App\Models\MembershipBookingOperation;
use App\Models\MembershipPurchaseBlock;
use App\Models\PaymentCheckoutAttempt;
use App\Services\Accounting\AccountingContextService;
use App\Services\Customers\CustomerOwnershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class MembershipConsistencyRule implements PaymentConsistencyRule
{
    public const PURCHASE = 'membership_purchase_v1';

    public const BALANCE = 'membership_balance_v1';

    public const SEQUENCE = 'membership_sequence_v1';

    public const BOOKING_CORRECTION = 'booking_correction_v1';

    public const BOOKING_POLICY = 'booking_policy_v1';

    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly CustomerOwnershipService $customerOwnership,
    ) {}

    public function ruleCodes(): array
    {
        return [self::PURCHASE, self::BALANCE, self::SEQUENCE, self::BOOKING_CORRECTION, self::BOOKING_POLICY];
    }

    public function scope(string $ruleCode, string $subjectType, int $subjectId): array
    {
        if ($ruleCode === self::PURCHASE && $subjectType === 'membership_purchase_block') {
            $block = MembershipPurchaseBlock::query()->findOrFail($subjectId);

            return [
                'company_id' => (int) $block->company_id,
                'branch_id' => (int) $block->branch_id,
                'checkout_id' => $block->meal_plan_request_id
                    ? DB::table('meal_plan_requests')->where('id', $block->meal_plan_request_id)->value('checkout_id')
                    : null,
            ];
        }
        if ($ruleCode === self::PURCHASE && $subjectType === 'meal_plan_request') {
            $request = MealPlanRequest::query()->findOrFail($subjectId);
            $promotion = $request->promotion_id
                ? DB::table('membership_promotions')->where('id', $request->promotion_id)->first(['company_id'])
                : null;
            $attempt = $request->checkout_id
                ? DB::table('payment_checkout_attempts')->where('id', $request->checkout_id)->first(['company_id', 'branch_id'])
                : null;

            return [
                'company_id' => (int) ($attempt?->company_id ?? $promotion?->company_id ?? $this->defaultCompanyId()),
                'branch_id' => isset($attempt?->branch_id) ? (int) $attempt->branch_id : null,
                'checkout_id' => $request->checkout_id ? (int) $request->checkout_id : null,
            ];
        }
        if (in_array($ruleCode, [self::BALANCE, self::SEQUENCE], true) && $subjectType === 'meal_subscription') {
            $subscription = MealSubscription::query()->findOrFail($subjectId);

            return [
                'company_id' => (int) ($subscription->queue_company_id ?: $this->defaultCompanyId()),
                'branch_id' => $subscription->branch_id ? (int) $subscription->branch_id : null,
                'checkout_id' => null,
            ];
        }
        if (in_array($ruleCode, [self::BOOKING_CORRECTION, self::BOOKING_POLICY], true)
            && $subjectType === 'meal_subscription_order') {
            $mapping = MealSubscriptionOrder::query()->findOrFail($subjectId);
            $subscription = MealSubscription::query()->findOrFail($mapping->subscription_id);

            return [
                'company_id' => (int) ($subscription->queue_company_id ?: $this->defaultCompanyId()),
                'branch_id' => (int) $mapping->branch_id,
                'checkout_id' => null,
            ];
        }

        throw new \InvalidArgumentException('Unsupported membership consistency subject.');
    }

    public function evaluate(string $ruleCode, string $subjectType, int $subjectId): array
    {
        return match ($ruleCode) {
            self::PURCHASE => $this->evaluatePurchase($subjectType, $subjectId),
            self::BALANCE => $this->evaluateQueue($subjectType, $subjectId, false),
            self::SEQUENCE => $this->evaluateQueue($subjectType, $subjectId, true),
            self::BOOKING_CORRECTION => $this->evaluateBooking($subjectType, $subjectId, false),
            self::BOOKING_POLICY => $this->evaluateBooking($subjectType, $subjectId, true),
            default => throw new \InvalidArgumentException('Unsupported membership consistency rule.'),
        };
    }

    private function evaluatePurchase(string $subjectType, int $subjectId): array
    {
        if ($subjectType === 'meal_plan_request') {
            return $this->evaluateZeroRequest(MealPlanRequest::query()->findOrFail($subjectId));
        }
        if ($subjectType !== 'membership_purchase_block') {
            throw new \InvalidArgumentException('Membership purchase checks require a block or free request.');
        }

        $block = MembershipPurchaseBlock::query()->findOrFail($subjectId);
        $request = $block->meal_plan_request_id ? DB::table('meal_plan_requests')->where('id', $block->meal_plan_request_id)->first([
            'id', 'customer_id', 'user_id', 'checkout_id', 'converted_subscription_id', 'submission_kind',
            'promotion_id', 'redemption_id', 'plan_meals', 'status', 'converted_at', 'created_at', 'updated_at',
        ]) : null;
        $subscription = DB::table('meal_subscriptions')->where('id', $block->subscription_id)->first([
            'id', 'customer_id', 'branch_id', 'status', 'start_date', 'end_date', 'plan_meals_total', 'meals_used',
            'meal_plan_request_id', 'fulfillment_mode', 'queue_company_id', 'queue_currency', 'queue_revision',
            'created_at', 'updated_at',
        ]);
        $plan = $block->plan_id ? DB::table('membership_plans')->where('id', $block->plan_id)->first([
            'id', 'company_id', 'code', 'meal_count', 'package_price_cents', 'currency', 'delivery_included',
            'is_active', 'effective_from', 'effective_to', 'created_at', 'updated_at',
        ]) : null;
        $payment = DB::table('payments')->where('id', $block->payment_id)->first([
            'id', 'customer_id', 'company_id', 'branch_id', 'payment_source_id', 'source', 'method',
            'amount_cents', 'currency', 'received_at', 'voided_at', 'created_at', 'updated_at',
        ]);
        $attempt = $request?->checkout_id ? PaymentCheckoutAttempt::query()->find($request->checkout_id, [
            'id', 'company_id', 'branch_id', 'customer_id', 'payment_source_id', 'purpose', 'currency',
            'gross_amount_cents', 'discount_amount_cents', 'payable_amount_cents', 'quote_fingerprint',
            'pricing_snapshot', 'state', 'expires_at', 'completed_at', 'created_at', 'updated_at',
        ]) : null;
        $target = $attempt ? DB::table('payment_checkout_targets')->where('attempt_id', $attempt->id)
            ->where('meal_plan_request_id', $request->id)->first([
                'id', 'attempt_id', 'sequence', 'target_type', 'expected_amount_cents', 'hold_state',
                'activated_at', 'released_at', 'meal_plan_request_id', 'created_at', 'updated_at',
            ]) : null;
        $provider = $attempt ? DB::table('payment_provider_transactions')->where('attempt_id', $attempt->id)
            ->where('payment_id', $block->payment_id)->whereNotNull('verified_paid_at')->first([
                'id', 'attempt_id', 'payment_source_id', 'verified_paid_at', 'verified_amount_cents',
                'verified_currency', 'verified_finished_at', 'payment_id', 'created_at', 'updated_at',
            ]) : null;
        $issues = [];
        $deferred = $attempt && (string) $attempt->state === 'paid_processing';
        $pricing = $attempt?->pricing_snapshot ?? [];
        $membershipGross = (int) ($pricing['membership_gross_amount_cents'] ?? $attempt?->gross_amount_cents ?? 0);
        $membershipPayable = (int) ($pricing['membership_payable_amount_cents'] ?? $attempt?->payable_amount_cents ?? 0);

        if (! $request || ! $subscription || ! $plan || ! $payment || ! $attempt || ! $target || ! $provider) {
            $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_PURCHASE_PARENT_MISSING', 'membership_purchase_block', (int) $block->id);
        } else {
            if ((string) $request->submission_kind !== 'paid_checkout'
                || (string) $request->status !== 'converted'
                || (int) $request->converted_subscription_id !== (int) $subscription->id
                || (int) $request->plan_meals !== (int) $block->meal_count
                || ! $request->converted_at) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_PURCHASE_REQUEST_MISMATCH', 'membership_purchase_block', (int) $block->id);
            }
            if ((string) $attempt->purpose !== 'membership'
                || (string) $attempt->state !== 'completed'
                || (int) $attempt->company_id !== (int) $block->company_id
                || (int) $attempt->branch_id !== (int) $block->branch_id
                || (string) $attempt->currency !== (string) $block->currency
                || $membershipGross !== (int) $block->gross_price_cents
                || (int) $attempt->discount_amount_cents !== (int) $block->discount_cents
                || $membershipPayable !== (int) $block->final_price_cents
                || ! hash_equals((string) $attempt->quote_fingerprint, (string) $block->quote_fingerprint)) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_PURCHASE_CHECKOUT_MISMATCH', 'membership_purchase_block', (int) $block->id);
            }
            if ($target->target_type !== 'meal_plan_request' || $target->hold_state !== 'activated'
                || (int) $target->expected_amount_cents !== (int) $block->final_price_cents) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_PURCHASE_TARGET_MISMATCH', 'membership_purchase_block', (int) $block->id);
            }
            if ((int) $plan->company_id !== (int) $block->company_id
                || (int) $plan->meal_count !== (int) $block->meal_count
                || (int) $plan->package_price_cents !== (int) $block->gross_price_cents
                || (string) $plan->currency !== (string) $block->currency
                || ! (bool) $plan->delivery_included) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_PURCHASE_PLAN_MISMATCH', 'membership_purchase_block', (int) $block->id);
            }
            if ((string) $subscription->fulfillment_mode !== 'customer_selection'
                || (int) $subscription->queue_company_id !== (int) $block->company_id
                || (int) $subscription->branch_id !== (int) $block->branch_id
                || (string) $subscription->queue_currency !== (string) $block->currency
                || $subscription->end_date !== null
                || ! $this->sameCustomer((int) $subscription->customer_id, (int) $block->original_customer_id)) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_PURCHASE_QUEUE_MISMATCH', 'membership_purchase_block', (int) $block->id);
            }
            if ((string) $payment->source !== 'ar' || (string) $payment->method !== 'skipcash'
                || (int) $payment->amount_cents !== (int) $attempt->payable_amount_cents
                || (string) $payment->currency !== (string) $block->currency
                || (int) $payment->company_id !== (int) $block->company_id
                || (int) $payment->branch_id !== (int) $block->branch_id
                || $payment->voided_at !== null
                || ! $this->sameCustomer((int) $payment->customer_id, (int) $block->original_customer_id)
                || (int) $provider->verified_amount_cents !== (int) $attempt->payable_amount_cents) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_PURCHASE_PAYMENT_MISMATCH', 'membership_purchase_block', (int) $block->id);
            }
        }
        if ((int) $block->meal_count <= 0 || (int) $block->gross_price_cents <= 0
            || (int) $block->discount_cents < 0 || (int) $block->final_price_cents <= 0
            || (int) $block->gross_price_cents !== (int) $block->discount_cents + (int) $block->final_price_cents) {
            $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_PURCHASE_MONEY_INVALID', 'membership_purchase_block', (int) $block->id);
        }

        return ConsistencyEvidence::result(
            (int) $block->company_id,
            (int) $block->branch_id,
            $attempt?->id ? (int) $attempt->id : null,
            [
                'positive_purchase_has_verified_receipt' => true,
                'request_is_converted_once' => true,
                'block_matches_plan_quote_and_queue' => true,
            ],
            [
                'block_id' => (int) $block->id,
                'request_id' => $request?->id,
                'subscription_id' => $subscription?->id,
                'payment_id' => $payment?->id,
                'checkout_id' => $attempt?->id,
                'deferred_reason' => $deferred ? 'MEMBERSHIP_PAYMENT_PROCESSING' : null,
            ],
            [
                'block' => $block->only([
                    'id', 'subscription_id', 'plan_id', 'payment_id', 'meal_plan_request_id', 'company_id',
                    'branch_id', 'original_customer_id', 'queue_position', 'meal_count', 'gross_price_cents',
                    'discount_cents', 'final_price_cents', 'currency', 'origin', 'origin_key', 'quote_fingerprint',
                    'opening_used_quantity', 'opening_released_quantity', 'funded_at', 'cancelled_at', 'created_at', 'updated_at',
                ]),
                'request' => $request ? (array) $request : ['missing' => true],
                'subscription' => $subscription ? (array) $subscription : ['missing' => true],
                'plan' => $plan ? (array) $plan : ['missing' => true],
                'payment' => $payment ? (array) $payment : ['missing' => true],
                'attempt' => $attempt ? $attempt->only([
                    'id', 'company_id', 'branch_id', 'customer_id', 'payment_source_id', 'purpose', 'currency',
                    'gross_amount_cents', 'discount_amount_cents', 'payable_amount_cents', 'quote_fingerprint',
                    'state', 'expires_at', 'completed_at', 'created_at', 'updated_at',
                ]) : ['missing' => true],
                'target' => $target ? (array) $target : ['missing' => true],
                'provider' => $provider ? (array) $provider : ['missing' => true],
            ],
            $issues,
            (bool) $deferred,
        );
    }

    private function evaluateZeroRequest(MealPlanRequest $request): array
    {
        $scope = $this->scope(self::PURCHASE, 'meal_plan_request', (int) $request->id);
        $redemption = $request->redemption_id ? DB::table('membership_promotion_redemptions')->where('id', $request->redemption_id)->first([
            'id', 'promotion_id', 'company_id', 'branch_id', 'original_customer_id', 'kind', 'checkout_id',
            'reservation_id', 'meal_plan_request_id', 'purchase_block_id', 'gross_cents', 'discount_cents',
            'net_cents', 'redeemed_at', 'zero_subject_key', 'created_at', 'updated_at',
        ]) : null;
        $subscriptions = DB::table('meal_subscriptions')->where('meal_plan_request_id', $request->id)->orderBy('id')->get([
            'id', 'customer_id', 'branch_id', 'status', 'meal_plan_request_id', 'fulfillment_mode',
            'queue_company_id', 'queue_currency', 'created_at', 'updated_at',
        ]);
        $blocks = DB::table('membership_purchase_blocks')->where('meal_plan_request_id', $request->id)->orderBy('id')->get(['id', 'payment_id', 'subscription_id', 'cancelled_at', 'created_at', 'updated_at']);
        $orders = DB::table('meal_plan_request_orders')->where('meal_plan_request_id', $request->id)->orderBy('id')->get(['id', 'meal_plan_request_id', 'order_id', 'created_at']);
        $checkoutTargets = DB::table('payment_checkout_targets')->where('meal_plan_request_id', $request->id)
            ->orderBy('id')->get([
                'id', 'attempt_id', 'target_type', 'hold_state', 'expected_amount_cents',
                'order_id', 'invoice_id', 'created_at', 'updated_at',
            ]);
        $manualAudits = DB::table('accounting_audit_logs')->where('action', 'membership_promotion.manual_converted')
            ->where('subject_type', MealPlanRequest::class)->where('subject_id', $request->id)->orderBy('id')->get(['id', 'actor_id', 'created_at']);
        $issues = [];

        if ($request->submission_kind !== 'promo_request' || $request->checkout_id !== null || ! $redemption
            || $redemption->kind !== 'zero_request' || (int) $redemption->net_cents !== 0
            || (int) $redemption->meal_plan_request_id !== (int) $request->id
            || $redemption->checkout_id !== null || $redemption->purchase_block_id !== null
            || ! is_string($redemption->zero_subject_key) || strlen($redemption->zero_subject_key) !== 64) {
            $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_ZERO_REQUEST_SHAPE_MISMATCH', 'meal_plan_request', (int) $request->id);
        }
        $converted = $request->status === 'converted';
        if (! $converted && ($request->converted_subscription_id !== null || $subscriptions->isNotEmpty()
            || $blocks->isNotEmpty() || $orders->isNotEmpty() || $checkoutTargets->isNotEmpty())) {
            $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_ZERO_REQUEST_AUTOMATIC_EFFECT', 'meal_plan_request', (int) $request->id);
        }
        if ($converted && ($manualAudits->isEmpty() || $subscriptions->count() !== 1
            || (int) $subscriptions->first()->id !== (int) $request->converted_subscription_id)) {
            $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_ZERO_REQUEST_MANUAL_CONVERSION_UNPROVEN', 'meal_plan_request', (int) $request->id);
        }

        return ConsistencyEvidence::result(
            (int) $scope['company_id'],
            $scope['branch_id'],
            null,
            [
                'free_promo_creates_request_and_permanent_redemption_only' => true,
                'manual_conversion_requires_separate_audit' => true,
            ],
            [
                'request_status' => (string) $request->status,
                'redemption_id' => $redemption?->id,
                'subscription_ids' => $subscriptions->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'block_ids' => $blocks->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'order_count' => $orders->count(),
                'checkout_target_count' => $checkoutTargets->count(),
                'manual_conversion_audit_ids' => $manualAudits->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ],
            [
                'request' => $request->only([
                    'id', 'customer_id', 'user_id', 'checkout_id', 'converted_subscription_id', 'submission_kind',
                    'promotion_id', 'redemption_id', 'plan_meals', 'status', 'converted_at', 'created_at', 'updated_at',
                ]),
                'redemption' => $redemption ? (array) $redemption : ['missing' => true],
                'subscriptions' => $subscriptions->map(fn ($row): array => (array) $row)->all(),
                'blocks' => $blocks->map(fn ($row): array => (array) $row)->all(),
                'orders' => $orders->map(fn ($row): array => (array) $row)->all(),
                'checkout_targets' => $checkoutTargets->map(fn ($row): array => (array) $row)->all(),
                'manual_audits' => $manualAudits->map(fn ($row): array => (array) $row)->all(),
            ],
            $issues,
        );
    }

    private function evaluateQueue(string $subjectType, int $subjectId, bool $sequenceOnly): array
    {
        if ($subjectType !== 'meal_subscription') {
            throw new \InvalidArgumentException('Membership queue checks require a subscription subject.');
        }
        $subject = MealSubscription::query()->findOrFail($subjectId);
        if ($subject->fulfillment_mode !== 'customer_selection') {
            throw new \InvalidArgumentException('Only customer-selection subscriptions have a logical membership queue.');
        }
        $customerIds = $this->customerOwnership->historicalCustomerIds((int) $subject->customer_id);
        $roots = DB::table('meal_subscriptions')
            ->whereIn('customer_id', $customerIds)
            ->where('fulfillment_mode', 'customer_selection')
            ->where('queue_company_id', $subject->queue_company_id)
            ->where('branch_id', $subject->branch_id)
            ->where('queue_currency', $subject->queue_currency)
            ->orderBy('id')->get([
                'id', 'customer_id', 'branch_id', 'status', 'start_date', 'end_date', 'plan_meals_total',
                'meals_used', 'meal_plan_request_id', 'fulfillment_mode', 'queue_company_id', 'queue_currency',
                'queue_revision', 'created_at', 'updated_at',
            ]);
        $blocks = DB::table('membership_purchase_blocks')->whereIn('subscription_id', $roots->pluck('id'))
            ->orderBy('funded_at')->orderBy('id')->get([
                'id', 'subscription_id', 'payment_id', 'company_id', 'branch_id', 'original_customer_id',
                'queue_position', 'meal_count', 'gross_price_cents', 'discount_cents', 'final_price_cents',
                'currency', 'origin', 'opening_used_quantity', 'opening_released_quantity',
                'opening_used_ranges', 'opening_released_ranges', 'funded_at', 'cancelled_at', 'created_at', 'updated_at',
            ]);
        $funding = DB::table('membership_booking_funding')->whereIn('purchase_block_id', $blocks->pluck('id'))
            ->orderBy('id')->get([
                'id', 'purchase_block_id', 'subscription_order_id', 'main_quantity', 'position_ranges',
                'invoice_id', 'invoice_gross_cents', 'invoice_discount_cents', 'invoice_net_cents',
                'payment_allocation_id', 'state', 'reserved_at', 'invoiced_at', 'released_at',
                'booking_cutoff_time', 'booking_timezone', 'change_deadline_at', 'created_at', 'updated_at',
            ]);
        $operations = DB::table('membership_booking_operations')->whereIn('subscription_id', $roots->pluck('id'))
            ->orderBy('id')->get([
                'id', 'client_uuid', 'customer_id', 'company_id', 'branch_id', 'subscription_id',
                'input_queue_revision', 'request_fingerprint', 'state', 'completed_at', 'created_at', 'updated_at',
            ]);
        $mergeAudits = DB::table('accounting_audit_logs')
            ->where('action', 'customer.merged')
            ->where(function ($query) use ($customerIds): void {
                foreach ($customerIds as $customerId) {
                    $query->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.source_customer_id')) = ?", [(string) $customerId])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.destination_customer_id')) = ?", [(string) $customerId]);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'payload', 'created_at']);
        $issues = [];
        $activeBlocks = $blocks->whereNull('cancelled_at');

        foreach ($blocks->groupBy('subscription_id') as $subscriptionBlocks) {
            $positions = $subscriptionBlocks->pluck('queue_position')->map(fn ($value): int => (int) $value)->all();
            if (count($positions) !== count(array_unique($positions)) || $positions !== range(1, count($positions))) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_QUEUE_POSITION_INVALID', 'meal_subscription', (int) $subject->id);
            }
        }

        $expectedUsedBySubscription = [];
        foreach ($activeBlocks as $block) {
            $usedPositions = $this->positions($block->opening_used_ranges);
            $releasedOpening = $this->positions($block->opening_released_ranges);
            if (count($usedPositions) !== (int) $block->opening_used_quantity
                || count($releasedOpening) !== (int) $block->opening_released_quantity
                || array_diff($releasedOpening, $usedPositions) !== []) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_OPENING_RANGE_MISMATCH', 'membership_purchase_block', (int) $block->id);
            }
            $occupied = array_fill_keys(array_diff($usedPositions, $releasedOpening), true);
            foreach ($funding->where('purchase_block_id', $block->id)->whereIn('state', ['reserved', 'invoiced']) as $row) {
                $positions = $this->positions($row->position_ranges);
                if (count($positions) !== (int) $row->main_quantity
                    || $positions === [] || min($positions) < 1 || max($positions) > (int) $block->meal_count) {
                    $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_FUNDING_RANGE_INVALID', 'membership_booking_funding', (int) $row->id);
                }
                foreach ($positions as $position) {
                    if (isset($occupied[$position])) {
                        $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_FUNDING_POSITION_OVERLAP', 'membership_booking_funding', (int) $row->id);
                    }
                    $occupied[$position] = true;
                }
            }
            if (count($occupied) > (int) $block->meal_count) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BLOCK_OVERCONSUMED', 'membership_purchase_block', (int) $block->id);
            }
            $expectedUsedBySubscription[(int) $block->subscription_id] = ($expectedUsedBySubscription[(int) $block->subscription_id] ?? 0)
                + (int) $block->opening_used_quantity - (int) $block->opening_released_quantity
                + (int) $funding->where('purchase_block_id', $block->id)->where('state', 'invoiced')->sum('main_quantity');
        }

        if (! $sequenceOnly) {
            foreach ($roots as $root) {
                $total = (int) $activeBlocks->where('subscription_id', $root->id)->sum('meal_count');
                if ((int) $root->plan_meals_total !== $total
                    || (int) $root->meals_used !== (int) ($expectedUsedBySubscription[(int) $root->id] ?? 0)) {
                    $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_QUEUE_AGGREGATE_MISMATCH', 'meal_subscription', (int) $root->id);
                }
                if ($root->end_date !== null) {
                    $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_ALLOWANCE_EXPIRY_CONFIGURED', 'meal_subscription', (int) $root->id);
                }
            }
        }
        foreach ($operations as $operation) {
            $currentRevision = (int) ($roots->firstWhere('id', (int) $operation->subscription_id)?->queue_revision ?? -1);
            if ($operation->state !== 'completed' || (int) $operation->input_queue_revision > $currentRevision) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_OPERATION_REVISION_INVALID', 'membership_booking_operation', (int) $operation->id);
            }
        }
        if ($sequenceOnly) {
            $this->appendSequentialAttributionIssues($blocks, $funding, $mergeAudits, $issues);
        }

        return ConsistencyEvidence::result(
            (int) $subject->queue_company_id,
            (int) $subject->branch_id,
            null,
            $sequenceOnly ? [
                'block_order_is_funded_at_then_id' => true,
                'each_booking_uses_the_earliest_free_positions' => true,
                'queue_positions_and_attributed_ranges_are_unique' => true,
                'accepted_revision_not_after_current_revision' => true,
            ] : [
                'subscription_totals_equal_active_blocks' => true,
                'used_meals_equal_opening_plus_invoiced_less_released' => true,
                'reserved_and_invoiced_positions_do_not_overlap' => true,
                'unused_allowance_has_no_expiry_date' => true,
            ],
            [
                'root_ids' => $roots->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'active_block_ids' => $activeBlocks->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'funding_count' => $funding->count(),
                'operation_count' => $operations->count(),
            ],
            [
                'roots' => $roots->map(fn ($row): array => (array) $row)->all(),
                'blocks' => $blocks->map(fn ($row): array => (array) $row)->all(),
                'funding' => $funding->map(fn ($row): array => (array) $row)->all(),
                'operations' => $operations->map(fn ($row): array => (array) $row)->all(),
                'merge_audits' => $mergeAudits->map(fn ($row): array => (array) $row)->all(),
            ],
            $issues,
        );
    }

    private function evaluateBooking(string $subjectType, int $subjectId, bool $policyOnly): array
    {
        if ($subjectType !== 'meal_subscription_order') {
            throw new \InvalidArgumentException('Booking checks require a subscription-order subject.');
        }
        $mapping = MealSubscriptionOrder::query()->findOrFail($subjectId);
        $subscription = DB::table('meal_subscriptions')->where('id', $mapping->subscription_id)->first([
            'id', 'customer_id', 'branch_id', 'status', 'fulfillment_mode', 'queue_company_id',
            'queue_currency', 'queue_revision', 'created_at', 'updated_at',
        ]);
        if (! $subscription) {
            throw new \RuntimeException('Booking subscription parent is missing.');
        }
        $order = DB::table('orders')->where('id', $mapping->order_id)->first([
            'id', 'customer_id', 'branch_id', 'source', 'type', 'status', 'scheduled_date', 'invoiced_at', 'created_at', 'updated_at',
        ]);
        $funding = DB::table('membership_booking_funding')->where('subscription_order_id', $mapping->id)
            ->orderBy('id')->get([
                'id', 'purchase_block_id', 'subscription_order_id', 'main_quantity', 'position_ranges',
                'invoice_id', 'intended_invoice_issue_date', 'invoice_gross_cents', 'invoice_discount_cents',
                'invoice_net_cents', 'payment_allocation_id', 'state', 'reserved_at', 'invoiced_at',
                'released_at', 'booking_cutoff_time', 'booking_timezone', 'change_deadline_at',
                'released_by', 'created_at', 'updated_at',
            ]);
        $invoices = DB::table('ar_invoices')->whereIn('id', $funding->pluck('invoice_id')->filter())->orderBy('id')->get([
            'id', 'branch_id', 'company_id', 'customer_id', 'source_order_id', 'status', 'issue_date',
            'currency', 'total_cents', 'paid_total_cents', 'balance_cents', 'voided_at', 'created_at', 'updated_at',
        ])->keyBy('id');
        $allocations = DB::table('payment_allocations')->whereIn('id', $funding->pluck('payment_allocation_id')->filter())
            ->orderBy('id')->get(['id', 'payment_id', 'allocatable_type', 'allocatable_id', 'amount_cents', 'voided_at', 'created_at', 'updated_at'])->keyBy('id');
        $invoiceAudits = DB::table('accounting_audit_logs')
            ->where('subject_type', ArInvoice::class)
            ->whereIn('subject_id', $invoices->keys())
            ->whereIn('action', ['ar_invoice.voided', 'ar_invoice.voided_and_duplicated'])
            ->orderBy('id')->get(['id', 'action', 'subject_id', 'actor_id', 'created_at']);
        $blocks = DB::table('membership_purchase_blocks')->whereIn('id', $funding->pluck('purchase_block_id'))
            ->orderBy('id')->get(['id', 'subscription_id', 'payment_id', 'company_id', 'branch_id', 'currency', 'cancelled_at', 'created_at', 'updated_at'])->keyBy('id');
        $operation = $mapping->accepted_operation_uuid ? MembershipBookingOperation::query()
            ->where('client_uuid', $mapping->accepted_operation_uuid)->first([
                'id', 'client_uuid', 'customer_id', 'company_id', 'branch_id', 'subscription_id',
                'input_queue_revision', 'request_fingerprint', 'state', 'result_snapshot',
                'completed_at', 'created_at', 'updated_at',
            ]) : null;
        $orderCustomerEmail = $order
            ? DB::table('orders')->where('id', $order->id)->value('customer_email_snapshot')
            : null;
        $pauses = DB::table('meal_subscription_pauses')->where('subscription_id', $mapping->subscription_id)
            ->whereDate('pause_start', '<=', $mapping->service_date)->whereDate('pause_end', '>=', $mapping->service_date)
            ->orderBy('id')->get(['id', 'subscription_id', 'pause_start', 'pause_end', 'resumed_at', 'resumed_by', 'created_by', 'created_at']);
        $audits = DB::table('accounting_audit_logs')->whereIn('action', [
            'membership.booking.created', 'membership.booking.replaced', 'membership.booking.cancelled',
            'membership.pause.created', 'membership.pause.resumed',
        ])->where(function ($query) use ($mapping, $operation): void {
            $query->where('subject_id', $mapping->id);
            if ($operation) {
                $query->orWhere('subject_id', $operation->id);
            }
        })->orderBy('id')->get(['id', 'action', 'subject_type', 'subject_id', 'actor_id', 'created_at']);
        $issues = [];
        $acceptedBooking = null;
        $notificationSnapshot = is_array($mapping->notification_snapshots) ? $mapping->notification_snapshots : [];

        if (! $order || $funding->isEmpty()) {
            $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BOOKING_PARENT_MISSING', 'meal_subscription_order', (int) $mapping->id);
        }
        if ($order && ((int) $order->branch_id !== (int) $mapping->branch_id
            || (int) $order->customer_id <= 0
            || ! $this->sameCustomer((int) $order->customer_id, (int) $subscription->customer_id)
            || $order->scheduled_date !== $mapping->service_date?->toDateString())) {
            $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BOOKING_ORDER_MISMATCH', 'meal_subscription_order', (int) $mapping->id);
        }

        if (! $policyOnly) {
            foreach ($funding as $row) {
                $invoice = $invoices->get((int) $row->invoice_id);
                $block = $blocks->get((int) $row->purchase_block_id);
                $allocation = $allocations->get((int) $row->payment_allocation_id);
                if (! $invoice || ! $block
                    || (int) $invoice->source_order_id !== (int) $mapping->order_id
                    || (int) $invoice->branch_id !== (int) $mapping->branch_id
                    || (int) $invoice->company_id !== (int) $subscription->queue_company_id
                    || (int) $block->subscription_id <= 0
                    || (int) $block->branch_id !== (int) $mapping->branch_id) {
                    $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BOOKING_FUNDING_SCOPE_MISMATCH', 'membership_booking_funding', (int) $row->id);
                }
                $invoiceVoided = $invoice && ($invoice->voided_at !== null || in_array((string) $invoice->status, ['void', 'voided'], true));
                if ((string) $row->state === 'invoiced') {
                    if ($invoiceVoided || ! $order || strcasecmp((string) $order->status, 'Cancelled') === 0
                        || (int) $invoice->balance_cents !== 0
                        || ((int) $row->invoice_net_cents > 0 && (! $allocation
                            || $allocation->voided_at !== null
                            || $allocation->allocatable_type !== ArInvoice::class
                            || (int) $allocation->allocatable_id !== (int) $invoice->id
                            || (int) $allocation->payment_id !== (int) $block->payment_id
                            || (int) $allocation->amount_cents !== (int) $row->invoice_net_cents))) {
                        $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_ACTIVE_BOOKING_NOT_FUNDED', 'membership_booking_funding', (int) $row->id);
                    }
                } elseif ((string) $row->state === 'released') {
                    if (! $invoiceVoided || ! $order || strcasecmp((string) $order->status, 'Cancelled') !== 0
                        || ($allocation && $allocation->voided_at === null) || ! $row->released_at) {
                        $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_RELEASE_NOT_PROPAGATED', 'membership_booking_funding', (int) $row->id);
                    }
                    if (! $invoice || ! $invoiceAudits->contains(fn ($audit): bool => (int) $audit->subject_id === (int) $invoice->id)) {
                        $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_RELEASE_AUDIT_MISSING', 'membership_booking_funding', (int) $row->id);
                    }
                }
            }
        } else {
            if (! $operation || $operation->state !== 'completed') {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BOOKING_OPERATION_MISSING', 'meal_subscription_order', (int) $mapping->id);
            } else {
                $accepted = CarbonImmutable::parse($operation->completed_at)->setTimezone('Asia/Qatar')->toDateString();
                if (! $mapping->service_date || $mapping->service_date->toDateString() <= $accepted) {
                    $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BOOKING_SAME_DAY_OR_PAST', 'meal_subscription_order', (int) $mapping->id);
                }
            }
            $acceptedBooking = $operation ? $this->acceptedBooking($operation, $mapping) : null;
            if (! $acceptedBooking
                || (string) ($acceptedBooking['service_date'] ?? '') !== $mapping->service_date?->toDateString()
                || (int) ($acceptedBooking['main_quantity'] ?? -1) !== (int) $funding->sum('main_quantity')) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BOOKING_ACCEPTED_SNAPSHOT_MISMATCH', 'meal_subscription_order', (int) $mapping->id);
            }
            if ((string) ($notificationSnapshot['booking_uuid'] ?? '') !== (string) $mapping->booking_uuid
                || (int) ($notificationSnapshot['booking_revision'] ?? -1) !== (int) $mapping->booking_revision
                || (string) ($notificationSnapshot['service_date'] ?? '') !== $mapping->service_date?->toDateString()
                || (int) ($notificationSnapshot['main_quantity'] ?? -1) !== (int) $funding->sum('main_quantity')
                || ! $this->sameInstant(
                    $notificationSnapshot['change_deadline_at'] ?? null,
                    $funding->first()?->change_deadline_at,
                )
                || trim((string) ($notificationSnapshot['customer_email'] ?? '')) !== trim((string) $orderCustomerEmail)) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BOOKING_NOTIFICATION_SNAPSHOT_MISMATCH', 'meal_subscription_order', (int) $mapping->id);
            }
            foreach ($funding as $row) {
                $expectedDeadline = CarbonImmutable::createFromFormat(
                    '!Y-m-d H:i:s',
                    $mapping->service_date->toDateString().' '.(string) $row->booking_cutoff_time,
                    (string) $row->booking_timezone,
                )->subDay();
                if ((string) $row->booking_timezone !== 'Asia/Qatar'
                    || ! $row->change_deadline_at
                    || ! $expectedDeadline->equalTo(CarbonImmutable::parse($row->change_deadline_at))) {
                    $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BOOKING_POLICY_SNAPSHOT_MISMATCH', 'membership_booking_funding', (int) $row->id);
                }
                if ($operation?->completed_at && $row->change_deadline_at
                    && CarbonImmutable::parse($operation->completed_at)->greaterThanOrEqualTo(
                        CarbonImmutable::parse($row->change_deadline_at),
                    )) {
                    $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_BOOKING_ACCEPTED_AFTER_DEADLINE', 'membership_booking_funding', (int) $row->id);
                }
            }
            if ($pauses->whereNull('resumed_at')->isNotEmpty()
                && ($funding->where('state', 'released')->count() !== $funding->count()
                    || ! $order || strcasecmp((string) $order->status, 'Cancelled') !== 0)) {
                $issues[] = ConsistencyEvidence::issue('MEMBERSHIP_PAUSE_BOOKING_NOT_CANCELLED', 'meal_subscription_order', (int) $mapping->id);
            }
        }

        return ConsistencyEvidence::result(
            (int) $subscription->queue_company_id,
            (int) $mapping->branch_id,
            null,
            $policyOnly ? [
                'service_date_after_qatar_acceptance_date' => true,
                'saved_change_deadline_matches_saved_cutoff' => true,
                'accepted_operation_precedes_saved_change_deadline' => true,
                'accepted_and_notification_snapshots_match_the_booking' => true,
                'active_pause_has_no_active_booking' => true,
            ] : [
                'active_invoice_has_active_exact_funding' => true,
                'voided_invoice_has_cancelled_order_released_funding_and_voided_allocation' => true,
                'released_funding_has_invoice_void_audit' => true,
                'invoice_edit_does_not_change_quantity' => true,
            ],
            [
                'mapping_id' => (int) $mapping->id,
                'order_id' => $order?->id,
                'funding_ids' => $funding->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'invoice_ids' => $invoices->keys()->map(fn ($id): int => (int) $id)->values()->all(),
                'active_pause_ids' => $pauses->whereNull('resumed_at')->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                'operation_id' => $operation?->id,
                'accepted_snapshot_matches' => $policyOnly ? $acceptedBooking !== null : null,
                'notification_profile_matches_order' => $policyOnly
                    ? trim((string) ($notificationSnapshot['customer_email'] ?? '')) === trim((string) $orderCustomerEmail)
                    : null,
            ],
            [
                'mapping' => $mapping->only([
                    'id', 'subscription_id', 'order_id', 'service_date', 'branch_id', 'booking_uuid',
                    'booking_revision', 'supersedes_subscription_order_id', 'accepted_operation_uuid', 'created_at',
                ]),
                'subscription' => (array) $subscription,
                'order' => $order ? (array) $order : ['missing' => true],
                'funding' => $funding->map(fn ($row): array => (array) $row)->all(),
                'invoices' => $invoices->values()->map(fn ($row): array => (array) $row)->all(),
                'allocations' => $allocations->values()->map(fn ($row): array => (array) $row)->all(),
                'invoice_audits' => $invoiceAudits->map(fn ($row): array => (array) $row)->all(),
                'blocks' => $blocks->values()->map(fn ($row): array => (array) $row)->all(),
                'operation' => $operation ? $operation->only([
                    'id', 'client_uuid', 'customer_id', 'company_id', 'branch_id', 'subscription_id',
                    'input_queue_revision', 'request_fingerprint', 'state', 'completed_at', 'created_at', 'updated_at',
                ]) : ['missing' => true],
                'pauses' => $pauses->map(fn ($row): array => (array) $row)->all(),
                'audits' => $audits->map(fn ($row): array => (array) $row)->all(),
            ],
            $issues,
        );
    }

    /** @return array<int, int> */
    private function positions(mixed $ranges): array
    {
        if (is_string($ranges)) {
            $ranges = json_decode($ranges, true);
        }
        $positions = [];
        foreach (is_array($ranges) ? $ranges : [] as $range) {
            if (! is_array($range) || count($range) !== 2) {
                continue;
            }
            $start = (int) $range[0];
            $end = (int) $range[1];
            if ($start <= 0 || $end < $start || $end - $start > 1000) {
                continue;
            }
            foreach (range($start, $end) as $position) {
                $positions[] = $position;
            }
        }
        sort($positions, SORT_NUMERIC);

        return $positions;
    }

    /** @return array<string, mixed>|null */
    private function acceptedBooking(MembershipBookingOperation $operation, MealSubscriptionOrder $mapping): ?array
    {
        $result = is_array($operation->result_snapshot) ? $operation->result_snapshot : [];
        $bookings = [];
        if (is_array($result['booking'] ?? null)) {
            $bookings[] = $result['booking'];
        }
        foreach (is_array($result['bookings'] ?? null) ? $result['bookings'] : [] as $booking) {
            if (is_array($booking)) {
                $bookings[] = $booking;
            }
        }

        foreach ($bookings as $booking) {
            if ((string) ($booking['booking_reference'] ?? '') === (string) $mapping->booking_uuid
                && (int) ($booking['booking_revision'] ?? -1) === (int) $mapping->booking_revision) {
                return $booking;
            }
        }

        return null;
    }

    private function sameInstant(mixed $left, mixed $right): bool
    {
        if (! $left || ! $right) {
            return false;
        }

        try {
            return CarbonImmutable::parse($left)->equalTo(CarbonImmutable::parse($right));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  Collection<int, object>  $blocks
     * @param  Collection<int, object>  $funding
     * @param  Collection<int, object>  $mergeAudits
     * @param  array<int, array<string, int|string>>  $issues
     */
    private function appendSequentialAttributionIssues(
        Collection $blocks,
        Collection $funding,
        Collection $mergeAudits,
        array &$issues,
    ): void {
        $orderedBlocks = $blocks->sort(function (object $left, object $right): int {
            $timeComparison = strcmp((string) $left->funded_at, (string) $right->funded_at);

            return $timeComparison !== 0 ? $timeComparison : ((int) $left->id <=> (int) $right->id);
        })->values();
        $blockOrder = $orderedBlocks->pluck('id')->map(fn ($id): int => (int) $id)->flip();
        $groups = $funding->groupBy('subscription_order_id')->map(function (Collection $rows): array {
            $orderedRows = $rows->sortBy('id')->values();

            return [
                'subscription_order_id' => (int) $orderedRows->first()->subscription_order_id,
                'reserved_at' => (string) $orderedRows->min('reserved_at'),
                'rows' => $orderedRows,
            ];
        })->sort(function (array $left, array $right): int {
            $timeComparison = strcmp($left['reserved_at'], $right['reserved_at']);

            return $timeComparison !== 0
                ? $timeComparison
                : ($left['subscription_order_id'] <=> $right['subscription_order_id']);
        })->values();
        $priorRows = collect();

        foreach ($groups as $group) {
            $acceptedAt = CarbonImmutable::parse($group['reserved_at']);
            $selectedOriginalCustomerIds = $group['rows']
                ->map(fn (object $row): int => (int) ($blocks->firstWhere('id', (int) $row->purchase_block_id)?->original_customer_id ?? 0))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $eligibleOriginalCustomerIds = $this->mergedCustomerIdsAt(
                $selectedOriginalCustomerIds,
                $mergeAudits,
                $acceptedAt,
            );
            $eligibleBlocks = $orderedBlocks->filter(function (object $block) use ($acceptedAt, $eligibleOriginalCustomerIds): bool {
                if (CarbonImmutable::parse($block->funded_at)->greaterThan($acceptedAt)) {
                    return false;
                }

                if (! in_array((int) $block->original_customer_id, $eligibleOriginalCustomerIds, true)) {
                    return false;
                }

                return ! $block->cancelled_at
                    || CarbonImmutable::parse($block->cancelled_at)->greaterThan($acceptedAt);
            })->values();
            $occupied = [];

            foreach ($eligibleBlocks as $block) {
                $opening = array_diff(
                    $this->positions($block->opening_used_ranges),
                    $this->positions($block->opening_released_ranges),
                );
                $occupied[(int) $block->id] = array_fill_keys($opening, true);
            }
            foreach ($priorRows as $priorRow) {
                $blockId = (int) $priorRow->purchase_block_id;
                if (! array_key_exists($blockId, $occupied)) {
                    continue;
                }
                if ($priorRow->released_at
                    && CarbonImmutable::parse($priorRow->released_at)->lessThanOrEqualTo($acceptedAt)) {
                    continue;
                }
                foreach ($this->positions($priorRow->position_ranges) as $position) {
                    $occupied[$blockId][$position] = true;
                }
            }

            $quantity = (int) $group['rows']->sum('main_quantity');
            $expected = [];
            foreach ($eligibleBlocks as $block) {
                $blockId = (int) $block->id;
                for ($position = 1; $position <= (int) $block->meal_count && count($expected) < $quantity; $position++) {
                    if (! isset($occupied[$blockId][$position])) {
                        $expected[] = $blockId.':'.$position;
                    }
                }
                if (count($expected) === $quantity) {
                    break;
                }
            }

            $actual = $group['rows']->flatMap(function (object $row) use ($blockOrder): array {
                $blockId = (int) $row->purchase_block_id;

                return collect($this->positions($row->position_ranges))->map(fn (int $position): array => [
                    'key' => $blockId.':'.$position,
                    'block_order' => (int) ($blockOrder->get($blockId) ?? PHP_INT_MAX),
                    'position' => $position,
                ])->all();
            })->sortBy([
                ['block_order', 'asc'],
                ['position', 'asc'],
            ])->pluck('key')->values()->all();

            if ($actual !== $expected) {
                $issues[] = ConsistencyEvidence::issue(
                    'MEMBERSHIP_BLOCK_SEQUENCE_PREMATURE',
                    'meal_subscription_order',
                    (int) $group['subscription_order_id'],
                );
            }
            $priorRows = $priorRows->concat($group['rows']);
        }
    }

    /**
     * @param  array<int, int>  $seedCustomerIds
     * @param  Collection<int, object>  $mergeAudits
     * @return array<int, int>
     */
    private function mergedCustomerIdsAt(array $seedCustomerIds, Collection $mergeAudits, CarbonImmutable $acceptedAt): array
    {
        $connected = array_fill_keys($seedCustomerIds, true);
        $eligibleAudits = $mergeAudits->filter(
            fn (object $audit): bool => CarbonImmutable::parse($audit->created_at)->lessThan($acceptedAt),
        );

        do {
            $changed = false;
            foreach ($eligibleAudits as $audit) {
                $payload = is_array($audit->payload) ? $audit->payload : json_decode((string) $audit->payload, true);
                $sourceId = (int) ($payload['source_customer_id'] ?? 0);
                $destinationId = (int) ($payload['destination_customer_id'] ?? 0);
                if ($sourceId <= 0 || $destinationId <= 0
                    || (! isset($connected[$sourceId]) && ! isset($connected[$destinationId]))) {
                    continue;
                }
                if (! isset($connected[$sourceId]) || ! isset($connected[$destinationId])) {
                    $changed = true;
                }
                $connected[$sourceId] = true;
                $connected[$destinationId] = true;
            }
        } while ($changed);

        return array_map('intval', array_keys($connected));
    }

    private function defaultCompanyId(): int
    {
        $companyId = (int) ($this->accountingContext->defaultCompanyId() ?? 0);
        if ($companyId <= 0) {
            throw new \RuntimeException('The default company is unavailable for membership consistency checks.');
        }

        return $companyId;
    }

    private function sameCustomer(int $left, int $right): bool
    {
        try {
            return $left > 0 && $right > 0
                && $this->customerOwnership->canonicalCustomerId($left) === $this->customerOwnership->canonicalCustomerId($right);
        } catch (Throwable) {
            return false;
        }
    }
}
