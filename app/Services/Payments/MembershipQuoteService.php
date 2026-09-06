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
        if ($selections !== []) {
            throw new PaymentCheckoutException(
                'MEMBERSHIP_SELECTIONS_NOT_AVAILABLE',
                503,
                __('Choose meals later while membership meal booking is being enabled.'),
            );
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
        $payableCents = (int) $plan->package_price_cents - $discountCents;
        $resultKind = (string) ($promotion['result_kind'] ?? 'paid_membership');
        $quoteFingerprint = $this->canonicalizer->hash([
            'membership-quote-v2',
            (string) $user->customer_id,
            (string) $context['company_id'],
            (string) $context['branch']->id,
            'QAR',
            $planSnapshot,
            [],
            $promotion ? [
                'acceptance_fingerprint' => $promotion['acceptance_fingerprint'],
                'code' => $promotion['code'],
            ] : null,
            $resultKind,
            $context['terms']['version'],
            $context['terms']['content_hash'],
        ]);

        return [
            'purpose' => 'membership',
            'plan' => $planSnapshot,
            'selections' => [],
            'main_quantity' => 0,
            'purchase_allowance' => (int) $plan->meal_count,
            'gross_amount_cents' => (int) $plan->package_price_cents,
            'discount_amount_cents' => $discountCents,
            'payable_amount_cents' => $payableCents,
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
            'can_checkout' => $payableCents > 0,
            'can_submit_request' => $payableCents === 0,
            '_context' => $context,
            '_plan' => $plan,
            '_promotion' => $promotion,
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
