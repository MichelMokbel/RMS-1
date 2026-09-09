<?php

namespace App\Services\Payments;

use App\Models\Branch;
use App\Models\PaymentSetting;
use App\Models\PaymentSource;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Customers\CustomerIdentityResolver;
use App\Services\Promotions\MembershipPromotionQuoteService;
use App\Services\Subscriptions\MembershipPlanCatalogService;
use App\Services\Subscriptions\MembershipQueueService;
use Illuminate\Validation\ValidationException;

class MembershipQuoteService
{
    public function __construct(
        private readonly AccountingContextService $accountingContext,
        private readonly CustomerIdentityResolver $identityResolver,
        private readonly PaymentTermsService $paymentTerms,
        private readonly CheckoutCanonicalizer $canonicalizer,
        private readonly MembershipPlanCatalogService $plans,
        private readonly MembershipQueueService $queues,
        private readonly MembershipPromotionQuoteService $promotions,
        private readonly OrdinaryOrderQuoteService $ordinaryQuotes,
    ) {}

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function quote(User $user, array $request): array
    {
        if (! (bool) config('payments.membership.checkout_enabled', false)
            || ! (bool) config('payments.membership.queue_enabled', false)) {
            throw new PaymentCheckoutException('MEMBERSHIP_CHECKOUT_DISABLED', 503, __('Membership checkout is not available yet.'));
        }

        $user = $this->identityResolver->resolveForCheckout($user);
        $context = $this->resolveContext((int) ($request['selected_branch_id'] ?? 0));
        $selections = $request['selections'] ?? [];
        if (! is_array($selections)) {
            throw ValidationException::withMessages(['selections' => __('Membership meal selections must be valid.')]);
        }
        $planCode = trim((string) ($request['plan_code'] ?? ''));
        $plan = $this->plans->findActive($context['company_id'], $planCode);
        if (! $plan) {
            throw ValidationException::withMessages(['plan_code' => __('Choose an available membership plan.')]);
        }
        $planSnapshot = $this->plans->present($plan);
        $promoCode = trim((string) ($request['promo_code'] ?? ''));
        $promotion = null;
        if ($promoCode !== '') {
            if (! (bool) config('payments.membership.promotions_enabled', false)) {
                throw new PaymentCheckoutException(
                    'MEMBERSHIP_PROMOTIONS_NOT_AVAILABLE',
                    503,
                    __('Promotion codes are not available for checkout yet.'),
                );
            }
            $promotion = $this->promotions->quote(
                $context['company_id'],
                (int) $user->customer_id,
                $plan,
                $promoCode,
            );
        }
        $discountCents = (int) ($promotion['discount_cents'] ?? 0);
        $membershipPayableCents = (int) $plan->package_price_cents - $discountCents;
        $resultKind = (string) ($promotion['result_kind'] ?? 'paid_membership');
        $pricedDays = [];
        $acceptedSelections = [];
        $excludedToday = [];
        $mainQuantity = 0;
        $addOnTotalCents = 0;
        $selectionCanonical = [];
        if ($membershipPayableCents > 0 && $selections !== []) {
            $normalized = $this->ordinaryQuotes->normalizeCart(['items' => $selections]);
            $membershipSelections = array_map(function (array $day): array {
                $mainQuantity = 0;
                foreach ($day['mains'] as $main) {
                    if ($main['portion'] !== 'plate') {
                        throw ValidationException::withMessages(['selections' => __('Membership meals support plate main dishes only.')]);
                    }
                    $mainQuantity += (int) $main['qty'];
                }

                return [
                    'key' => $day['date'],
                    'mains' => $day['mains'],
                    'salad_qty' => $mainQuantity,
                    'dessert_qty' => $mainQuantity,
                    'notes' => $day['notes'],
                    'add_ons' => $day['add_ons'],
                ];
            }, $normalized);
            $ordinary = $this->ordinaryQuotes->quote($user, ['cart' => ['items' => $membershipSelections]]);
            $pricedDays = $ordinary['_priced_days'];
            $acceptedSelections = array_map(fn (array $day): array => $day['submission'], $pricedDays);
            $excludedToday = $ordinary['excluded_today'];
            $mainQuantity = (int) collect($acceptedSelections)->sum(fn (array $day): int => collect($day['mains'])
                ->sum(fn (array $main): int => (int) $main['qty']));
            if ($mainQuantity > (int) $plan->meal_count) {
                throw new PaymentCheckoutException(
                    'MEMBERSHIP_PURCHASE_SELECTION_LIMIT',
                    422,
                    __('This purchase includes :meals meals. Reduce the selected main dishes before paying.', ['meals' => (int) $plan->meal_count]),
                );
            }
            $addOnTotalCents = (int) collect($pricedDays)->sum('add_on_total_cents');
            $selectionCanonical = array_map(fn (array $day): array => $day['canonical_tuple'], $pricedDays);
        }
        $payableCents = $membershipPayableCents + $addOnTotalCents;
        $quoteFingerprint = $this->canonicalizer->hash([
            'membership-quote-v3',
            (string) $user->customer_id,
            (string) $context['company_id'],
            (string) $context['branch']->id,
            'QAR',
            $planSnapshot,
            $selectionCanonical,
            $promotion ? [
                'acceptance_fingerprint' => $promotion['acceptance_fingerprint'],
                'code' => $promotion['code'],
            ] : null,
            $resultKind,
            $membershipPayableCents,
            $addOnTotalCents,
            $context['terms']['version'],
            $context['terms']['content_hash'],
        ]);

        return [
            'purpose' => 'membership',
            'plan' => $planSnapshot,
            'selections' => $acceptedSelections,
            'excluded_today' => $excludedToday,
            'main_quantity' => $mainQuantity,
            'purchase_allowance' => (int) $plan->meal_count,
            'gross_amount_cents' => (int) $plan->package_price_cents + $addOnTotalCents,
            'discount_amount_cents' => $discountCents,
            'payable_amount_cents' => $payableCents,
            'membership_gross_amount_cents' => (int) $plan->package_price_cents,
            'membership_payable_amount_cents' => $membershipPayableCents,
            'add_on_amount_cents' => $addOnTotalCents,
            'currency' => 'QAR',
            'delivery_included' => true,
            'quote_fingerprint' => $quoteFingerprint,
            'terms_version' => $context['terms']['version'],
            'terms_url' => $context['terms']['url'],
            'terms_content_hash' => $context['terms']['content_hash'],
            'support_phone' => $context['settings']->order_support_phone,
            'queue' => $this->queues->summary(
                (int) $user->customer_id,
                $context['company_id'],
                (int) $context['branch']->id,
            ),
            'result_kind' => $resultKind,
            'promotion' => $promotion ? [
                'code' => $promotion['code'],
                'discount_type' => $promotion['offer_snapshot']['discount_type'],
                'fixed_amount_cents' => $promotion['offer_snapshot']['fixed_amount_cents'],
                'percentage_basis_points' => $promotion['offer_snapshot']['percentage_basis_points'],
                'purchase_eligibility' => $promotion['offer_snapshot']['purchase_eligibility'],
                'offer_revision' => $promotion['offer_snapshot']['revision'],
            ] : null,
            'can_checkout' => $membershipPayableCents > 0,
            'can_submit_request' => $membershipPayableCents === 0,
            '_context' => $context,
            '_plan' => $plan,
            '_promotion' => $promotion,
            '_priced_days' => $pricedDays,
        ];
    }

    /** @return array{company_id:int,branch:Branch,source:PaymentSource,settings:PaymentSetting,terms:array<string,string>} */
    private function resolveContext(int $submittedBranchId): array
    {
        $configuredBranchId = (int) config('payments.public_order_branch_id', 1);
        if ($submittedBranchId !== $configuredBranchId) {
            throw ValidationException::withMessages(['selected_branch_id' => __('This membership branch is unavailable.')]);
        }

        $companyId = $this->accountingContext->defaultCompanyId();
        $branch = Branch::query()->find($configuredBranchId);
        $settings = $companyId ? PaymentSetting::query()->where('company_id', $companyId)->first() : null;
        $source = $companyId
            ? PaymentSource::query()
                ->where('company_id', $companyId)
                ->where('code', PaymentSource::CODE_SKIPCASH)
                ->where('method', PaymentSource::METHOD_SKIPCASH)
                ->where('is_active', true)
                ->first()
            : null;
        $terms = $this->paymentTerms->inspect()['current'];

        if (! $companyId || ! $branch || ! $branch->is_active || (int) $branch->company_id !== $companyId
            || ! $settings || ! $source || ! $terms) {
            throw new PaymentCheckoutException('PAYMENT_CONTEXT_UNAVAILABLE', 503, __('Checkout is currently unavailable.'));
        }

        return [
            'company_id' => $companyId,
            'branch' => $branch,
            'source' => $source,
            'settings' => $settings,
            'terms' => $terms,
        ];
    }
}
