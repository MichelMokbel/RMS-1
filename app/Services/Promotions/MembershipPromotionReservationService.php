<?php

namespace App\Services\Promotions;

use App\Models\MealPlanRequest;
use App\Models\MembershipPromotionRedemption;
use App\Models\MembershipPromotionReservation;
use App\Models\MembershipPurchaseBlock;
use App\Models\PaymentCheckoutAttempt;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Payments\PaymentConsistencyDispatchService;

class MembershipPromotionReservationService
{
    public function __construct(
        private readonly AccountingAuditLogService $auditLog,
        private readonly PaymentConsistencyDispatchService $consistency,
    ) {}

    /** @param array<string, mixed> $evaluation */
    public function create(PaymentCheckoutAttempt $attempt, User $user, array $evaluation): MembershipPromotionReservation
    {
        if ((int) $evaluation['net_cents'] <= 0
            || (int) $attempt->payable_amount_cents !== (int) $evaluation['net_cents']
            || (int) $attempt->discount_amount_cents !== (int) $evaluation['discount_cents']) {
            throw new PaymentCheckoutException('PROMOTION_RESERVATION_INVALID', 409, __('This promotion checkout is inconsistent.'));
        }

        $existing = MembershipPromotionReservation::query()
            ->where('checkout_id', $attempt->id)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }

        $promotion = $evaluation['promotion'];
        $reservation = MembershipPromotionReservation::query()->create([
            'promotion_id' => $promotion->id,
            'company_id' => $attempt->company_id,
            'branch_id' => $attempt->branch_id,
            'original_customer_id' => $attempt->customer_id,
            'original_user_id' => $user->id,
            'checkout_id' => $attempt->id,
            'status' => MembershipPromotionReservation::STATUS_HELD,
            'offer_snapshot' => $evaluation['offer_snapshot'],
            'eligibility_snapshot' => $evaluation['eligibility_snapshot'],
            'gross_cents' => $evaluation['gross_cents'],
            'discount_cents' => $evaluation['discount_cents'],
            'net_cents' => $evaluation['net_cents'],
            'starts_at' => $attempt->started_at,
            'expires_at' => $attempt->expires_at,
        ]);
        $this->auditLog->log('membership_promotion.reserved', (int) $user->id, $reservation, [
            'checkout_id' => (int) $attempt->id,
            'promotion_id' => (int) $promotion->id,
            'original_customer_id' => (int) $attempt->customer_id,
            'gross_cents' => (int) $evaluation['gross_cents'],
            'discount_cents' => (int) $evaluation['discount_cents'],
            'net_cents' => (int) $evaluation['net_cents'],
            'acceptance_fingerprint' => (string) $evaluation['acceptance_fingerprint'],
        ], (int) $attempt->company_id);
        $this->consistency->promotionAfterCommit(
            (int) $promotion->id,
            'promotion_reservation',
            (int) $reservation->id,
            MembershipPromotionReservation::STATUS_HELD,
        );

        return $reservation;
    }

    public function releaseForCheckout(int $checkoutId, string $reason, ?int $actorId = null): ?MembershipPromotionReservation
    {
        $reservation = MembershipPromotionReservation::query()
            ->where('checkout_id', $checkoutId)
            ->lockForUpdate()
            ->first();
        if (! $reservation || $reservation->status !== MembershipPromotionReservation::STATUS_HELD) {
            return $reservation;
        }

        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 80) {
            throw new \InvalidArgumentException('A bounded promotion release reason is required.');
        }
        $reservation->update([
            'status' => MembershipPromotionReservation::STATUS_RELEASED,
            'released_at' => now('UTC'),
            'release_reason' => $reason,
        ]);
        $this->auditLog->log('membership_promotion.released', $actorId, $reservation, [
            'checkout_id' => $checkoutId,
            'promotion_id' => (int) $reservation->promotion_id,
            'reason' => $reason,
        ], (int) $reservation->company_id);
        $this->consistency->promotionAfterCommit(
            (int) $reservation->promotion_id,
            'promotion_reservation',
            (int) $reservation->id,
            MembershipPromotionReservation::STATUS_RELEASED,
        );

        return $reservation->fresh();
    }

    public function redeemPaidPurchase(
        PaymentCheckoutAttempt $attempt,
        MealPlanRequest $request,
        MembershipPurchaseBlock $block,
        int $actorId,
    ): MembershipPromotionRedemption {
        $reservation = MembershipPromotionReservation::query()
            ->where('checkout_id', $attempt->id)
            ->lockForUpdate()
            ->first();
        if (! $reservation) {
            throw new PaymentCheckoutException('PROMOTION_RESERVATION_MISSING', 503, __('The retained promotion use is unavailable.'));
        }
        $existing = MembershipPromotionRedemption::query()
            ->where('reservation_id', $reservation->id)
            ->lockForUpdate()
            ->first();
        if ($existing) {
            return $existing;
        }
        if ($reservation->status !== MembershipPromotionReservation::STATUS_HELD
            || (int) $reservation->company_id !== (int) $attempt->company_id
            || (int) $reservation->branch_id !== (int) $attempt->branch_id
            || (int) $reservation->original_customer_id !== (int) $attempt->customer_id
            || (int) $reservation->gross_cents !== (int) $attempt->gross_amount_cents
            || (int) $reservation->discount_cents !== (int) $attempt->discount_amount_cents
            || (int) $reservation->net_cents !== (int) $attempt->payable_amount_cents
            || (int) $block->meal_plan_request_id !== (int) $request->id
            || (int) $block->final_price_cents !== (int) $reservation->net_cents) {
            throw new PaymentCheckoutException('PROMOTION_REDEMPTION_MISMATCH', 503, __('The retained promotion use is inconsistent.'));
        }

        $redeemedAt = now('UTC');
        $redemption = MembershipPromotionRedemption::query()->create([
            'promotion_id' => $reservation->promotion_id,
            'company_id' => $reservation->company_id,
            'branch_id' => $reservation->branch_id,
            'original_customer_id' => $reservation->original_customer_id,
            'original_user_id' => $reservation->original_user_id,
            'kind' => MembershipPromotionRedemption::KIND_PAID_PURCHASE,
            'checkout_id' => $attempt->id,
            'reservation_id' => $reservation->id,
            'meal_plan_request_id' => $request->id,
            'purchase_block_id' => $block->id,
            'offer_snapshot' => $reservation->offer_snapshot,
            'eligibility_snapshot' => $reservation->eligibility_snapshot,
            'gross_cents' => $reservation->gross_cents,
            'discount_cents' => $reservation->discount_cents,
            'net_cents' => $reservation->net_cents,
            'redeemed_at' => $redeemedAt,
        ]);
        $reservation->update([
            'status' => MembershipPromotionReservation::STATUS_REDEEMED,
            'redeemed_at' => $redeemedAt,
        ]);
        $request->update(['redemption_id' => $redemption->id]);
        $this->auditLog->log('membership_promotion.redeemed', $actorId, $redemption, [
            'checkout_id' => (int) $attempt->id,
            'reservation_id' => (int) $reservation->id,
            'meal_plan_request_id' => (int) $request->id,
            'purchase_block_id' => (int) $block->id,
            'payment_id' => (int) $block->payment_id,
        ], (int) $reservation->company_id);
        $this->consistency->promotionAfterCommit(
            (int) $redemption->promotion_id,
            'promotion_redemption',
            (int) $redemption->id,
            MembershipPromotionRedemption::KIND_PAID_PURCHASE,
        );

        return $redemption;
    }
}
