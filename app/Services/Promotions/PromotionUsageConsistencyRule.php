<?php

namespace App\Services\Promotions;

use App\Models\MembershipPromotion;
use App\Models\MembershipPromotionRedemption;
use App\Models\MembershipPromotionReservation;
use App\Services\Customers\CustomerOwnershipService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class PromotionUsageConsistencyRule
{
    public const CODE = 'promotion_usage_v1';

    public const VERSION = 1;

    public const SUBJECT_TYPE = 'membership_promotion';

    public function __construct(
        private readonly CustomerOwnershipService $customerOwnership,
    ) {}

    /**
     * @return array{
     *     healthy:bool,
     *     company_id:int,
     *     branch_id:null,
     *     checkout_id:int|null,
     *     expected:array<string,mixed>,
     *     observed:array<string,mixed>,
     *     evidence_fingerprint:string
     * }
     */
    public function evaluate(MembershipPromotion $promotion): array
    {
        $reservations = DB::table('membership_promotion_reservations')
            ->where('promotion_id', $promotion->id)
            ->orderBy('id')
            ->get([
                'id', 'company_id', 'branch_id', 'original_customer_id', 'checkout_id', 'status',
                'gross_cents', 'discount_cents', 'net_cents', 'starts_at', 'expires_at',
                'redeemed_at', 'released_at', 'release_reason',
            ]);
        $redemptions = DB::table('membership_promotion_redemptions')
            ->where('promotion_id', $promotion->id)
            ->orderBy('id')
            ->get([
                'id', 'promotion_id', 'company_id', 'branch_id', 'original_customer_id', 'kind', 'checkout_id',
                'reservation_id', 'meal_plan_request_id', 'purchase_block_id', 'gross_cents',
                'discount_cents', 'net_cents', 'redeemed_at', 'zero_subject_key',
            ]);
        $attempts = $this->rowsById('payment_checkout_attempts', $redemptions->pluck('checkout_id')
            ->merge($reservations->pluck('checkout_id')), [
                'id', 'company_id', 'branch_id', 'customer_id', 'purpose', 'currency', 'state',
                'gross_amount_cents', 'discount_amount_cents', 'payable_amount_cents',
            ]);
        $requests = $this->rowsById('meal_plan_requests', $redemptions->pluck('meal_plan_request_id'), [
            'id', 'customer_id', 'user_id', 'plan_meals', 'status', 'submission_kind', 'checkout_id',
            'converted_subscription_id', 'promotion_id', 'redemption_id', 'converted_at',
        ]);
        $blocks = $this->rowsById('membership_purchase_blocks', $redemptions->pluck('purchase_block_id'), [
            'id', 'subscription_id', 'plan_id', 'payment_id', 'meal_plan_request_id', 'company_id',
            'branch_id', 'original_customer_id', 'meal_count', 'gross_price_cents', 'discount_cents',
            'final_price_cents', 'currency', 'funded_at', 'cancelled_at',
        ]);
        $payments = $this->rowsById('payments', $blocks->pluck('payment_id'), [
            'id', 'company_id', 'branch_id', 'customer_id', 'source', 'method', 'amount_cents',
            'currency', 'voided_at',
        ]);
        $requestIds = $redemptions->pluck('meal_plan_request_id')->filter()->map(fn ($id): int => (int) $id);
        $subscriptionCounts = $requestIds->isEmpty()
            ? collect()
            : DB::table('meal_subscriptions')
                ->whereIn('meal_plan_request_id', $requestIds)
                ->selectRaw('meal_plan_request_id, COUNT(*) AS aggregate')
                ->groupBy('meal_plan_request_id')
                ->pluck('aggregate', 'meal_plan_request_id');
        $requestOrderCounts = $requestIds->isEmpty()
            ? collect()
            : DB::table('meal_plan_request_orders')
                ->whereIn('meal_plan_request_id', $requestIds)
                ->selectRaw('meal_plan_request_id, COUNT(*) AS aggregate')
                ->groupBy('meal_plan_request_id')
                ->pluck('aggregate', 'meal_plan_request_id');

        $issues = [];
        $heldCount = $reservations->where('status', MembershipPromotionReservation::STATUS_HELD)->count();
        if ($redemptions->count() + $heldCount > (int) $promotion->total_limit) {
            $this->issue($issues, 'PROMOTION_TOTAL_LIMIT_EXCEEDED', self::SUBJECT_TYPE, (int) $promotion->id);
        }

        $redemptionsByReservation = $redemptions->whereNotNull('reservation_id')->keyBy('reservation_id');
        foreach ($reservations as $reservation) {
            $attempt = $attempts->get((int) $reservation->checkout_id);
            $redemption = $redemptionsByReservation->get((int) $reservation->id);
            if ((int) $reservation->company_id !== (int) $promotion->company_id) {
                $this->issue($issues, 'PROMOTION_RESERVATION_COMPANY_MISMATCH', 'membership_promotion_reservation', (int) $reservation->id);
            }
            if (! $this->validMoney($reservation->gross_cents, $reservation->discount_cents, $reservation->net_cents, true)) {
                $this->issue($issues, 'PROMOTION_RESERVATION_MONEY_MISMATCH', 'membership_promotion_reservation', (int) $reservation->id);
            }
            if (! $attempt) {
                $this->issue($issues, 'PROMOTION_RESERVATION_CHECKOUT_MISSING', 'membership_promotion_reservation', (int) $reservation->id);
            } elseif ((int) $attempt->company_id !== (int) $reservation->company_id
                || (int) $attempt->branch_id !== (int) $reservation->branch_id
                || (int) $attempt->customer_id !== (int) $reservation->original_customer_id
                || $attempt->purpose !== 'membership'
                || (int) $attempt->gross_amount_cents !== (int) $reservation->gross_cents
                || (int) $attempt->discount_amount_cents !== (int) $reservation->discount_cents
                || (int) $attempt->payable_amount_cents !== (int) $reservation->net_cents) {
                $this->issue($issues, 'PROMOTION_RESERVATION_CHECKOUT_MISMATCH', 'membership_promotion_reservation', (int) $reservation->id);
            }

            if ($reservation->status === MembershipPromotionReservation::STATUS_HELD
                && ($redemption || ($attempt && in_array($attempt->state, ['completed', 'payment_received_as_credit', 'declined', 'expired'], true)))) {
                $this->issue($issues, 'PROMOTION_HELD_RESERVATION_TERMINAL', 'membership_promotion_reservation', (int) $reservation->id);
            }
            if ($reservation->status === MembershipPromotionReservation::STATUS_REDEEMED && ! $redemption) {
                $this->issue($issues, 'PROMOTION_REDEEMED_RESERVATION_USE_MISSING', 'membership_promotion_reservation', (int) $reservation->id);
            }
            if ($reservation->status === MembershipPromotionReservation::STATUS_RELEASED && $redemption) {
                $this->issue($issues, 'PROMOTION_RELEASED_RESERVATION_HAS_USE', 'membership_promotion_reservation', (int) $reservation->id);
            }
        }

        foreach ($redemptions as $redemption) {
            $request = $requests->get((int) $redemption->meal_plan_request_id);
            if ((int) $redemption->company_id !== (int) $promotion->company_id
                || ! $this->validMoney($redemption->gross_cents, $redemption->discount_cents, $redemption->net_cents, false)) {
                $this->issue($issues, 'PROMOTION_REDEMPTION_SCOPE_OR_MONEY_MISMATCH', 'membership_promotion_redemption', (int) $redemption->id);
            }

            if ($redemption->kind === MembershipPromotionRedemption::KIND_PAID_PURCHASE) {
                $this->evaluatePaidRedemption(
                    $issues,
                    $redemption,
                    $attempts->get((int) $redemption->checkout_id),
                    $reservations->firstWhere('id', (int) $redemption->reservation_id),
                    $request,
                    $blocks->get((int) $redemption->purchase_block_id),
                    $payments,
                );
            } elseif ($redemption->kind === MembershipPromotionRedemption::KIND_ZERO_REQUEST) {
                $this->evaluateZeroRedemption(
                    $issues,
                    $redemption,
                    $request,
                    (int) ($subscriptionCounts[(int) $redemption->meal_plan_request_id] ?? 0),
                    (int) ($requestOrderCounts[(int) $redemption->meal_plan_request_id] ?? 0),
                );
            } else {
                $this->issue($issues, 'PROMOTION_REDEMPTION_KIND_INVALID', 'membership_promotion_redemption', (int) $redemption->id);
            }
        }

        usort($issues, fn (array $left, array $right): int => [$left['code'], $left['subject_type'], $left['subject_id']]
            <=> [$right['code'], $right['subject_type'], $right['subject_id']]);
        $evidence = [
            'promotion' => [
                'id' => (int) $promotion->id,
                'company_id' => (int) $promotion->company_id,
                'status' => (string) $promotion->status,
                'revision' => (int) $promotion->revision,
                'total_limit' => (int) $promotion->total_limit,
                'per_customer_limit' => (int) $promotion->per_customer_limit,
            ],
            'reservations' => $reservations->map(fn ($row): array => (array) $row)->all(),
            'redemptions' => $redemptions->map(fn ($row): array => (array) $row)->all(),
            'attempts' => $attempts->values()->map(fn ($row): array => (array) $row)->all(),
            'requests' => $requests->values()->map(fn ($row): array => (array) $row)->all(),
            'blocks' => $blocks->values()->map(fn ($row): array => (array) $row)->all(),
            'payments' => $payments->values()->map(fn ($row): array => (array) $row)->all(),
            'subscription_counts' => $subscriptionCounts->sortKeys()->all(),
            'request_order_counts' => $requestOrderCounts->sortKeys()->all(),
        ];

        return [
            'healthy' => $issues === [],
            'company_id' => (int) $promotion->company_id,
            'branch_id' => null,
            'checkout_id' => $this->firstCheckoutId($issues, $reservations, $redemptions),
            'expected' => [
                'completed_and_held_not_above_total_limit' => (int) $promotion->total_limit,
                'paid_use_requires_matching_redeemed_hold_checkout_request_block_and_payment' => true,
                'zero_use_requires_request_only_until_explicit_manual_conversion' => true,
                'completed_use_survives_cancellation_and_merge' => true,
            ],
            'observed' => [
                'held_count' => $heldCount,
                'paid_use_count' => $redemptions->where('kind', MembershipPromotionRedemption::KIND_PAID_PURCHASE)->count(),
                'zero_request_use_count' => $redemptions->where('kind', MembershipPromotionRedemption::KIND_ZERO_REQUEST)->count(),
                'issues' => $issues,
            ],
            'evidence_fingerprint' => hash('sha256', json_encode(
                $evidence,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )),
        ];
    }

    /** @param array<int,array<string,mixed>> $issues */
    private function evaluatePaidRedemption(
        array &$issues,
        object $redemption,
        ?object $attempt,
        ?object $reservation,
        ?object $request,
        ?object $block,
        Collection $payments,
    ): void {
        $id = (int) $redemption->id;
        if (! $attempt || ! $reservation || ! $request || ! $block) {
            $this->issue($issues, 'PROMOTION_PAID_USE_PARENT_MISSING', 'membership_promotion_redemption', $id);

            return;
        }
        $payment = $payments->get((int) $block->payment_id);
        if ($attempt->state !== 'completed'
            || (int) $attempt->company_id !== (int) $redemption->company_id
            || (int) $attempt->branch_id !== (int) $redemption->branch_id
            || (int) $attempt->customer_id !== (int) $redemption->original_customer_id
            || (int) $attempt->gross_amount_cents !== (int) $redemption->gross_cents
            || (int) $attempt->discount_amount_cents !== (int) $redemption->discount_cents
            || (int) $attempt->payable_amount_cents !== (int) $redemption->net_cents) {
            $this->issue($issues, 'PROMOTION_PAID_USE_CHECKOUT_MISMATCH', 'membership_promotion_redemption', $id);
        }
        if ($reservation->status !== MembershipPromotionReservation::STATUS_REDEEMED
            || (int) $reservation->checkout_id !== (int) $attempt->id
            || (int) $reservation->gross_cents !== (int) $redemption->gross_cents
            || (int) $reservation->discount_cents !== (int) $redemption->discount_cents
            || (int) $reservation->net_cents !== (int) $redemption->net_cents) {
            $this->issue($issues, 'PROMOTION_PAID_USE_RESERVATION_MISMATCH', 'membership_promotion_redemption', $id);
        }
        if ($request->submission_kind !== 'paid_checkout'
            || $request->status !== 'converted'
            || (int) $request->checkout_id !== (int) $attempt->id
            || (int) $request->promotion_id !== (int) $redemption->promotion_id
            || (int) $request->redemption_id !== $id
            || ! $request->converted_subscription_id) {
            $this->issue($issues, 'PROMOTION_PAID_USE_REQUEST_MISMATCH', 'membership_promotion_redemption', $id);
        }
        if ((int) $block->meal_plan_request_id !== (int) $request->id
            || (int) $block->company_id !== (int) $redemption->company_id
            || (int) $block->branch_id !== (int) $redemption->branch_id
            || (int) $block->original_customer_id !== (int) $redemption->original_customer_id
            || (int) $block->gross_price_cents !== (int) $redemption->gross_cents
            || (int) $block->discount_cents !== (int) $redemption->discount_cents
            || (int) $block->final_price_cents !== (int) $redemption->net_cents
            || $block->currency !== 'QAR') {
            $this->issue($issues, 'PROMOTION_PAID_USE_BLOCK_MISMATCH', 'membership_promotion_redemption', $id);
        }
        if (! $payment
            || $payment->source !== 'ar'
            || $payment->method !== 'skipcash'
            || $payment->voided_at !== null
            || (int) $payment->company_id !== (int) $redemption->company_id
            || (int) $payment->branch_id !== (int) $redemption->branch_id
            || (int) $payment->amount_cents !== (int) $redemption->net_cents
            || $payment->currency !== 'QAR'
            || ! $this->sameCanonicalCustomer((int) $redemption->original_customer_id, (int) ($payment->customer_id ?? 0))) {
            $this->issue($issues, 'PROMOTION_PAID_USE_PAYMENT_MISMATCH', 'membership_promotion_redemption', $id);
        }
    }

    /** @param array<int,array<string,mixed>> $issues */
    private function evaluateZeroRedemption(
        array &$issues,
        object $redemption,
        ?object $request,
        int $subscriptionCount,
        int $requestOrderCount,
    ): void {
        $id = (int) $redemption->id;
        if ($redemption->checkout_id !== null
            || $redemption->reservation_id !== null
            || $redemption->purchase_block_id !== null
            || (int) $redemption->net_cents !== 0
            || ! is_string($redemption->zero_subject_key)
            || strlen($redemption->zero_subject_key) !== 64) {
            $this->issue($issues, 'PROMOTION_ZERO_USE_SHAPE_MISMATCH', 'membership_promotion_redemption', $id);
        }
        if (! $request
            || $request->submission_kind !== 'promo_request'
            || (int) $request->promotion_id !== (int) $redemption->promotion_id
            || (int) $request->redemption_id !== $id
            || $request->checkout_id !== null) {
            $this->issue($issues, 'PROMOTION_ZERO_USE_REQUEST_MISMATCH', 'membership_promotion_redemption', $id);

            return;
        }

        $converted = $request->status === 'converted';
        if ((! $converted && ($request->converted_subscription_id !== null || $subscriptionCount > 0 || $requestOrderCount > 0))
            || ($converted && ($request->converted_subscription_id === null || $subscriptionCount !== 1))) {
            $this->issue($issues, 'PROMOTION_ZERO_USE_AUTOMATIC_EFFECT_MISMATCH', 'membership_promotion_redemption', $id);
        }
    }

    private function validMoney(mixed $gross, mixed $discount, mixed $net, bool $positiveNet): bool
    {
        $gross = (int) $gross;
        $discount = (int) $discount;
        $net = (int) $net;

        return $gross > 0
            && $discount > 0
            && ($positiveNet ? $net > 0 : $net >= 0)
            && $gross === $discount + $net;
    }

    /** @param array<int,array<string,mixed>> $issues */
    private function issue(array &$issues, string $code, string $subjectType, int $subjectId): void
    {
        $candidate = compact('code', 'subjectType', 'subjectId');
        $candidate = [
            'code' => $candidate['code'],
            'subject_type' => $candidate['subjectType'],
            'subject_id' => $candidate['subjectId'],
        ];
        if (! in_array($candidate, $issues, true)) {
            $issues[] = $candidate;
        }
    }

    /** @param Collection<int,mixed> $ids
     * @param  array<int,string>  $columns
     * @return Collection<int,object>
     */
    private function rowsById(string $table, Collection $ids, array $columns): Collection
    {
        $ids = $ids->filter()->map(fn ($id): int => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return DB::table($table)->whereIn('id', $ids)->orderBy('id')->get($columns)->keyBy('id');
    }

    /** @param array<int,array<string,mixed>> $issues */
    private function firstCheckoutId(array $issues, Collection $reservations, Collection $redemptions): ?int
    {
        if ($issues === []) {
            return null;
        }
        $checkoutId = $redemptions->pluck('checkout_id')->merge($reservations->pluck('checkout_id'))->filter()->first();

        return $checkoutId === null ? null : (int) $checkoutId;
    }

    private function sameCanonicalCustomer(int $leftCustomerId, int $rightCustomerId): bool
    {
        if ($leftCustomerId <= 0 || $rightCustomerId <= 0) {
            return false;
        }

        try {
            return $this->customerOwnership->canonicalCustomerId($leftCustomerId)
                === $this->customerOwnership->canonicalCustomerId($rightCustomerId);
        } catch (Throwable) {
            return false;
        }
    }
}
