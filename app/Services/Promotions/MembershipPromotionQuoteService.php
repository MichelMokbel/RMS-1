<?php

namespace App\Services\Promotions;

use App\Models\MembershipPlan;
use App\Models\MembershipPromotion;
use App\Models\MembershipPromotionRedemption;
use App\Models\MembershipPromotionReservation;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Subscriptions\MembershipPurchaseHistoryService;
use Carbon\CarbonInterface;

class MembershipPromotionQuoteService
{
    public function __construct(
        private readonly MembershipPurchaseHistoryService $purchaseHistory,
        private readonly CustomerOwnershipService $customerOwnership,
    ) {}

    /** @return array<string, mixed> */
    public function quote(
        int $companyId,
        int $customerId,
        MembershipPlan $plan,
        string $rawCode,
        ?CarbonInterface $now = null,
    ): array {
        return $this->evaluate($companyId, $customerId, $plan, $rawCode, $now, false);
    }

    /**
     * The caller must already hold the canonical customer lock. This method then
     * locks the promotion before checking capacity and eligibility.
     *
     * @return array<string, mixed>
     */
    public function quoteForAcceptance(
        int $companyId,
        int $customerId,
        MembershipPlan $plan,
        string $rawCode,
        ?CarbonInterface $now = null,
    ): array {
        return $this->evaluate($companyId, $customerId, $plan, $rawCode, $now, true);
    }

    public function normalizeCode(string $rawCode): string
    {
        $code = strtoupper(trim($rawCode));
        if (! preg_match('/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{12}$/', $code)) {
            throw new PaymentCheckoutException(
                'PROMOTION_INVALID',
                422,
                __('This promotion code is invalid.'),
            );
        }

        return $code;
    }

    /** @return array<string, mixed> */
    private function evaluate(
        int $companyId,
        int $customerId,
        MembershipPlan $plan,
        string $rawCode,
        ?CarbonInterface $now,
        bool $forUpdate,
    ): array {
        $code = $this->normalizeCode($rawCode);
        $now = $now ? $now->copy()->utc() : now('UTC');
        $promotion = MembershipPromotion::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->first();
        if (! $promotion || $promotion->status !== MembershipPromotion::STATUS_ACTIVE) {
            throw new PaymentCheckoutException('PROMOTION_UNAVAILABLE', 422, __('This promotion code is not available.'));
        }
        if ($now->lt($promotion->starts_at) || $now->gte($promotion->ends_at)) {
            throw new PaymentCheckoutException('PROMOTION_UNAVAILABLE', 422, __('This promotion code is not available.'));
        }
        if ((int) $plan->company_id !== $companyId
            || ! $promotion->plans()->whereKey($plan->id)->exists()) {
            throw new PaymentCheckoutException('PROMOTION_PLAN_INELIGIBLE', 422, __('This promotion does not apply to the selected membership.'));
        }

        $history = $this->purchaseHistory->resolve($customerId, $companyId);
        $classification = $history['has_completed_purchase'] ? 'renewal' : 'first';
        if (! in_array($promotion->purchase_eligibility, [$classification, MembershipPromotion::ELIGIBILITY_BOTH], true)) {
            throw new PaymentCheckoutException('PROMOTION_PURCHASE_INELIGIBLE', 422, __('This promotion does not apply to this membership purchase.'));
        }

        $customerIds = $this->customerOwnership->historicalCustomerIds((int) $history['canonical_customer_id']);
        $completedTotal = MembershipPromotionRedemption::query()
            ->where('promotion_id', $promotion->id)
            ->count();
        $heldTotal = MembershipPromotionReservation::query()
            ->where('promotion_id', $promotion->id)
            ->where('status', MembershipPromotionReservation::STATUS_HELD)
            ->count();
        if ($completedTotal + $heldTotal >= (int) $promotion->total_limit) {
            throw new PaymentCheckoutException('PROMOTION_EXHAUSTED', 422, __('This promotion code has reached its usage limit.'));
        }

        $completedForCustomer = MembershipPromotionRedemption::query()
            ->where('promotion_id', $promotion->id)
            ->whereIn('original_customer_id', $customerIds)
            ->count();
        $heldForCustomer = MembershipPromotionReservation::query()
            ->where('promotion_id', $promotion->id)
            ->whereIn('original_customer_id', $customerIds)
            ->where('status', MembershipPromotionReservation::STATUS_HELD)
            ->count();
        if ($completedForCustomer + $heldForCustomer >= (int) $promotion->per_customer_limit) {
            throw new PaymentCheckoutException('PROMOTION_CUSTOMER_LIMIT', 422, __('This promotion code has already been used for this customer.'));
        }

        $grossCents = (int) $plan->package_price_cents;
        $discountCents = $this->discountCents($promotion, $grossCents);
        $netCents = $grossCents - $discountCents;
        $offerSnapshot = [
            'promotion_id' => (int) $promotion->id,
            'code' => (string) $promotion->code,
            'revision' => (int) $promotion->revision,
            'discount_type' => (string) $promotion->discount_type,
            'fixed_amount_cents' => $promotion->fixed_amount_cents === null ? null : (int) $promotion->fixed_amount_cents,
            'percentage_basis_points' => $promotion->percentage_basis_points === null ? null : (int) $promotion->percentage_basis_points,
            'purchase_eligibility' => (string) $promotion->purchase_eligibility,
            'starts_at' => $promotion->starts_at?->utc()->toISOString(),
            'ends_at' => $promotion->ends_at?->utc()->toISOString(),
            'plan_id' => (int) $plan->id,
            'plan_code' => (string) $plan->code,
        ];
        $eligibilitySnapshot = [
            'classification' => $classification,
            'completed_purchase_count' => (int) $history['completed_purchase_count'],
            'canonical_customer_id' => (int) $history['canonical_customer_id'],
            'customer_ids' => $customerIds,
            'completed_uses' => $completedForCustomer,
            'held_uses' => $heldForCustomer,
        ];
        $acceptanceFingerprint = hash('sha256', json_encode([
            'membership-promotion-acceptance-v1',
            $offerSnapshot,
            $eligibilitySnapshot,
            $grossCents,
            $discountCents,
            $netCents,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'promotion' => $promotion,
            'code' => $code,
            'gross_cents' => $grossCents,
            'discount_cents' => $discountCents,
            'net_cents' => $netCents,
            'result_kind' => $netCents === 0 ? 'pending_request' : 'paid_membership',
            'offer_snapshot' => $offerSnapshot,
            'eligibility_snapshot' => $eligibilitySnapshot,
            'acceptance_fingerprint' => $acceptanceFingerprint,
        ];
    }

    private function discountCents(MembershipPromotion $promotion, int $grossCents): int
    {
        if ($grossCents <= 0) {
            throw new \RuntimeException('Membership gross price must be positive.');
        }
        if ($promotion->discount_type === MembershipPromotion::DISCOUNT_FIXED) {
            return min($grossCents, (int) $promotion->fixed_amount_cents);
        }

        $basisPoints = (int) $promotion->percentage_basis_points;
        if ($basisPoints < 1 || $basisPoints > 10000
            || $grossCents > intdiv(PHP_INT_MAX - 5000, $basisPoints)) {
            throw new \RuntimeException('Membership percentage discount is outside supported bounds.');
        }

        return min($grossCents, intdiv(($grossCents * $basisPoints) + 5000, 10000));
    }
}
