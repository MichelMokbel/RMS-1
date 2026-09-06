<?php

namespace App\Services\Payments;

use App\Jobs\InitiateSkipCashCheckout;
use App\Models\MealPlanRequest;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\User;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Promotions\MembershipPromotionQuoteService;
use App\Services\Promotions\MembershipPromotionReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipCheckoutService
{
    public function __construct(
        private readonly MembershipQuoteService $quotes,
        private readonly PaymentSetupService $setup,
        private readonly SkipCashCustomerProfileService $profiles,
        private readonly CheckoutCanonicalizer $canonicalizer,
        private readonly CheckoutStatusPresenter $statuses,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly MembershipPromotionQuoteService $promotionQuotes,
        private readonly MembershipPromotionReservationService $promotionReservations,
        private readonly PaymentConsistencyDispatchService $paymentConsistency,
    ) {}

    /** @param array<string, mixed> $request
     * @return array{result:array<string,mixed>,replayed:bool,status:int}
     */
    public function create(User $user, array $request): array
    {
        $clientUuid = strtolower(trim((string) ($request['client_uuid'] ?? '')));
        if (! Str::isUuid($clientUuid)) {
            throw ValidationException::withMessages(['client_uuid' => __('A valid checkout identifier is required.')]);
        }

        $requestFingerprint = $this->requestFingerprint($request);
        $replay = PaymentCheckoutAttempt::query()
            ->where('portal_user_id', $user->id)
            ->where('client_uuid', $clientUuid)
            ->first();
        if ($replay) {
            if (! hash_equals((string) $replay->request_fingerprint, $requestFingerprint)) {
                throw new PaymentCheckoutException('REQUEST_CHANGED', 409, __('This checkout identifier was already used for a different purchase.'));
            }

            return [
                'result' => $this->statuses->present($replay, true),
                'replayed' => true,
                'status' => $this->responseStatus($replay),
            ];
        }

        try {
            $quote = $this->quotes->quote($user, $request);
        } catch (PaymentCheckoutException $exception) {
            $this->throwPromotionAcceptanceConflict($request, $exception);

            throw $exception;
        }
        if (! hash_equals((string) $quote['quote_fingerprint'], (string) ($request['quote_fingerprint'] ?? ''))) {
            throw new PaymentCheckoutException('QUOTE_CHANGED', 409, __('Your membership quote changed. Review it before paying.'), [
                'quote' => $this->publicQuote($quote),
            ]);
        }
        if ((string) ($request['accepted_terms_version'] ?? '') !== (string) $quote['terms_version']) {
            throw new PaymentCheckoutException('TERMS_CHANGED', 409, __('Accept the current terms before paying.'), [
                'quote' => $this->publicQuote($quote),
            ]);
        }
        if ((int) $quote['payable_amount_cents'] <= 0) {
            throw new PaymentCheckoutException(
                'PROMOTION_REQUEST_REQUIRED',
                409,
                __('This promotion requires the request submission flow and cannot start a payment.'),
                ['quote' => $this->publicQuote($quote)],
            );
        }

        $this->setup->assertReadyForNewCheckout((int) $quote['_context']['branch']->id);
        $ownedUser = $user->fresh('customer');
        $profile = $this->profiles->snapshot($ownedUser);
        $recoveryFingerprint = $this->canonicalizer->hash([
            'membership-recovery-v1',
            (string) $quote['_context']['company_id'],
            (string) $quote['_context']['branch']->id,
            (string) $quote['_plan']->code,
        ]);

        $result = DB::transaction(function () use (
            $ownedUser,
            $quote,
            $clientUuid,
            $profile,
            $request,
            $requestFingerprint,
            $recoveryFingerprint,
        ): array {
            $customer = $this->customerOwnership->lockCanonicalCustomer((int) $ownedUser->customer_id);
            if (! $customer->isActive()) {
                throw ValidationException::withMessages(['account' => __('This customer account is not available.')]);
            }

            $existing = PaymentCheckoutAttempt::query()
                ->where('portal_user_id', $ownedUser->id)
                ->where('client_uuid', $clientUuid)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! hash_equals((string) $existing->request_fingerprint, $requestFingerprint)) {
                    throw new PaymentCheckoutException('REQUEST_CHANGED', 409, __('This checkout identifier was already used for a different purchase.'));
                }

                return ['attempt' => $existing, 'replayed' => true];
            }

            $unresolved = PaymentCheckoutAttempt::query()
                ->whereIn('customer_id', $this->customerOwnership->historicalCustomerIds($customer->id))
                ->where('purpose', 'membership')
                ->whereIn('state', ['initiating', 'pending', 'paid_processing'])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($unresolved) {
                throw new PaymentCheckoutException(
                    'EXISTING_CHECKOUT',
                    409,
                    __('You already have a membership payment in progress. Resume it before starting another one.'),
                    ['recovery_reference' => $unresolved->reference],
                );
            }

            $context = $quote['_context'];
            $plan = $quote['_plan'];
            $acceptedPromotion = null;
            if (is_array($quote['_promotion'])) {
                try {
                    $acceptedPromotion = $this->promotionQuotes->quoteForAcceptance(
                        (int) $context['company_id'],
                        (int) $customer->id,
                        $plan,
                        (string) $request['promo_code'],
                    );
                } catch (PaymentCheckoutException $exception) {
                    $this->throwPromotionAcceptanceConflict($request, $exception);

                    throw $exception;
                }
                if (! hash_equals(
                    (string) $quote['_promotion']['acceptance_fingerprint'],
                    (string) $acceptedPromotion['acceptance_fingerprint'],
                )) {
                    throw new PaymentCheckoutException(
                        'QUOTE_CHANGED',
                        409,
                        __('Your promotion quote changed. Review it before paying.'),
                    );
                }
            }
            $startedAt = now('Asia/Qatar');
            $expiresAt = $startedAt->copy()->addMinutes((int) $context['settings']->checkout_duration_minutes);
            $terms = $context['terms'];
            $mealPlanRequest = MealPlanRequest::query()->create([
                'customer_id' => $customer->id,
                'user_id' => $ownedUser->id,
                'customer_name' => $profile['full_name'],
                'customer_phone' => $profile['phone'],
                'customer_email' => $profile['email'],
                'delivery_address' => $profile['address'],
                'notes' => null,
                'plan_meals' => $plan->meal_count,
                'status' => 'new',
                'submission_kind' => 'paid_checkout',
                'promotion_id' => $acceptedPromotion['promotion']->id ?? null,
                'submission_snapshot' => [
                    'plan' => $quote['plan'],
                    'selections' => [],
                    'selected_branch_id' => (int) $context['branch']->id,
                    'terms_version' => $terms['version'],
                    'promotion' => $acceptedPromotion['offer_snapshot'] ?? null,
                ],
                'promotion_terms_snapshot' => $acceptedPromotion['offer_snapshot'] ?? null,
            ]);
            $attempt = PaymentCheckoutAttempt::query()->create([
                'reference' => (string) Str::uuid(),
                'company_id' => $context['company_id'],
                'branch_id' => $context['branch']->id,
                'customer_id' => $customer->id,
                'portal_user_id' => $ownedUser->id,
                'payment_source_id' => $context['source']->id,
                'client_uuid' => $clientUuid,
                'purpose' => 'membership',
                'currency' => 'QAR',
                'gross_amount_cents' => (int) $quote['gross_amount_cents'],
                'discount_amount_cents' => (int) $quote['discount_amount_cents'],
                'payable_amount_cents' => (int) $quote['payable_amount_cents'],
                'cart_fingerprint' => $this->canonicalizer->hash([
                    'membership-cart-v1',
                    (string) $plan->id,
                    [],
                    $acceptedPromotion ? [
                        'code' => $acceptedPromotion['code'],
                        'acceptance_fingerprint' => $acceptedPromotion['acceptance_fingerprint'],
                    ] : null,
                ]),
                'quote_fingerprint' => $quote['quote_fingerprint'],
                'request_fingerprint' => $requestFingerprint,
                'recovery_fingerprint' => $recoveryFingerprint,
                'state' => 'initiating',
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'cart_snapshot' => [
                    'purpose' => 'membership',
                    'plan' => $quote['plan'],
                    'selections' => [],
                ],
                'customer_snapshot' => $profile,
                'pricing_snapshot' => [
                    'plan_id' => (int) $plan->id,
                    'plan' => $quote['plan'],
                    'gross_amount_cents' => (int) $quote['gross_amount_cents'],
                    'discount_amount_cents' => (int) $quote['discount_amount_cents'],
                    'payable_amount_cents' => (int) $quote['payable_amount_cents'],
                    'promotion' => $acceptedPromotion['offer_snapshot'] ?? null,
                ],
                'terms_snapshot' => [
                    'version' => $terms['version'],
                    'url' => $terms['url'],
                    'content_hash' => $terms['content_hash'],
                    'accepted_at' => $startedAt->toIso8601String(),
                    'support_phone' => $context['settings']->order_support_phone,
                ],
                'request_snapshot' => [
                    'purpose' => 'membership',
                    'selected_branch_id' => (int) $context['branch']->id,
                    'plan_code' => (string) $plan->code,
                    'selections' => [],
                    'promo_code' => $acceptedPromotion['code'] ?? null,
                    'promotion_acceptance_fingerprint' => $acceptedPromotion['acceptance_fingerprint'] ?? null,
                    'submitted_quote_fingerprint' => (string) $request['quote_fingerprint'],
                    'accepted_terms_version' => (string) $request['accepted_terms_version'],
                ],
                'source_account_snapshot' => [
                    'payment_source_id' => $context['source']->id,
                    'clearing_account_id' => $context['source']->clearing_account_id,
                ],
                'notification_dispatch' => [
                    'customer_confirmation' => ['state' => 'pending'],
                    'admin_confirmation' => ['state' => 'pending'],
                ],
                'provider_request_uuid' => (string) Str::uuid(),
                'provider_create_outcome' => 'not_sent',
                'next_recovery_at' => $startedAt,
            ]);
            $mealPlanRequest->update(['checkout_id' => $attempt->id]);
            PaymentCheckoutTarget::query()->create([
                'attempt_id' => $attempt->id,
                'sequence' => 1,
                'target_type' => 'meal_plan_request',
                'service_date' => null,
                'expected_amount_cents' => (int) $quote['payable_amount_cents'],
                'item_snapshot' => [
                    'plan_id' => (int) $plan->id,
                    'plan' => $quote['plan'],
                    'selections' => [],
                    'promotion' => $acceptedPromotion['offer_snapshot'] ?? null,
                ],
                'hold_state' => 'held',
                'held_at' => $startedAt,
                'meal_plan_request_id' => $mealPlanRequest->id,
            ]);
            if ($acceptedPromotion) {
                $this->promotionReservations->create($attempt, $ownedUser, $acceptedPromotion);
            }

            DB::afterCommit(fn () => InitiateSkipCashCheckout::dispatch($attempt->id));
            $this->paymentConsistency->checkoutGraphAfterCommit(
                (int) $attempt->id,
                'payment_checkout_attempt',
                (int) $attempt->id,
                'created',
            );

            return ['attempt' => $attempt, 'replayed' => false];
        }, 3);

        return [
            'result' => $this->statuses->present($result['attempt']->fresh(), true),
            'replayed' => $result['replayed'],
            'status' => $result['replayed'] ? $this->responseStatus($result['attempt']) : 202,
        ];
    }

    /** @param array<string, mixed> $request */
    private function requestFingerprint(array $request): string
    {
        return $this->canonicalizer->hash([
            'membership-request-v1',
            'membership',
            (string) ($request['selected_branch_id'] ?? ''),
            trim((string) ($request['plan_code'] ?? '')),
            [],
            trim((string) ($request['promo_code'] ?? '')) === ''
                ? null
                : strtoupper(trim((string) $request['promo_code'])),
            (string) ($request['quote_fingerprint'] ?? ''),
            (string) ($request['accepted_terms_version'] ?? ''),
        ]);
    }

    private function responseStatus(PaymentCheckoutAttempt $attempt): int
    {
        if (in_array($attempt->state, ['completed', 'payment_received_as_credit', 'declined', 'expired'], true)
            || $attempt->provider_create_outcome === 'created') {
            return 200;
        }

        return 202;
    }

    /** @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function publicQuote(array $quote): array
    {
        return array_diff_key($quote, array_flip(['_context', '_plan', '_promotion']));
    }

    /** @param array<string, mixed> $request */
    private function throwPromotionAcceptanceConflict(array $request, PaymentCheckoutException $exception): void
    {
        if (trim((string) ($request['promo_code'] ?? '')) === ''
            || ! in_array($exception->codeName, [
                'PROMOTION_UNAVAILABLE',
                'PROMOTION_PLAN_INELIGIBLE',
                'PROMOTION_PURCHASE_INELIGIBLE',
                'PROMOTION_EXHAUSTED',
                'PROMOTION_CUSTOMER_LIMIT',
            ], true)) {
            return;
        }

        throw new PaymentCheckoutException(
            'QUOTE_CHANGED',
            409,
            __('Your promotion quote changed. Review it before paying.'),
        );
    }
}
