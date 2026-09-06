<?php

namespace App\Services\Promotions;

use App\Jobs\SendMembershipPromotionRequestConfirmation;
use App\Models\MealPlanRequest;
use App\Models\MembershipPromotion;
use App\Models\MembershipPromotionRedemption;
use App\Models\User;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Customers\CustomerIdentityResolver;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Mail\MailSettingsService;
use App\Services\Payments\CheckoutCanonicalizer;
use App\Services\Payments\MembershipQuoteService;
use App\Services\Payments\OrdinaryOrderQuoteService;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Payments\SkipCashCustomerProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipPromotionRequestService
{
    public function __construct(
        private readonly MembershipQuoteService $membershipQuotes,
        private readonly MembershipPromotionQuoteService $promotionQuotes,
        private readonly OrdinaryOrderQuoteService $ordinaryQuotes,
        private readonly CustomerIdentityResolver $identityResolver,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly AccountingContextService $accountingContext,
        private readonly SkipCashCustomerProfileService $profiles,
        private readonly MailSettingsService $mailSettings,
        private readonly CheckoutCanonicalizer $canonicalizer,
        private readonly AccountingAuditLogService $auditLog,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{result:array<string,mixed>,replayed:bool,status:int}
     */
    public function create(User $user, array $payload): array
    {
        $clientUuid = strtolower(trim((string) ($payload['client_uuid'] ?? '')));
        if (! Str::isUuid($clientUuid)) {
            throw ValidationException::withMessages(['client_uuid' => __('A valid request identifier is required.')]);
        }

        $submittedCode = strtoupper(trim((string) ($payload['promo_code'] ?? '')));
        $requestFingerprint = $this->requestFingerprint($payload, $submittedCode);
        $ownedUser = $this->identityResolver->resolveForCheckout($user)->fresh('customer');

        $exactReplay = MealPlanRequest::query()
            ->where('user_id', $ownedUser->id)
            ->where('client_uuid', $clientUuid)
            ->first();
        if ($exactReplay) {
            $this->assertExactReplay($exactReplay, $requestFingerprint);

            return $this->result($exactReplay, true, 200);
        }

        $code = $this->promotionQuotes->normalizeCode($submittedCode);
        $companyId = $this->accountingContext->defaultCompanyId();
        if (! $companyId) {
            throw new PaymentCheckoutException('PAYMENT_CONTEXT_UNAVAILABLE', 503, __('Membership requests are currently unavailable.'));
        }
        $existingUse = $this->existingZeroUse($companyId, (int) $ownedUser->customer_id, $code);
        if ($existingUse) {
            return $this->result($existingUse, true, 200);
        }

        $quotePayload = [
            'purpose' => 'membership',
            'selected_branch_id' => $payload['selected_branch_id'] ?? null,
            'plan_code' => $payload['plan_code'] ?? null,
            'selections' => [],
            'promo_code' => $code,
        ];
        $quote = $this->membershipQuotes->quote($ownedUser, $quotePayload);
        if ((int) $quote['payable_amount_cents'] !== 0 || ! is_array($quote['_promotion'])) {
            throw new PaymentCheckoutException(
                'PROMOTION_REQUIRES_PAYMENT',
                422,
                __('This promotion still has an amount to pay and must use secure checkout.'),
            );
        }
        if (! hash_equals((string) $quote['quote_fingerprint'], (string) ($payload['quote_fingerprint'] ?? ''))) {
            throw new PaymentCheckoutException('QUOTE_CHANGED', 409, __('Your membership quote changed. Review it before submitting the request.'), [
                'quote' => $this->publicQuote($quote),
            ]);
        }
        if ((string) ($payload['accepted_terms_version'] ?? '') !== (string) $quote['terms_version']) {
            throw new PaymentCheckoutException('TERMS_CHANGED', 409, __('Accept the current terms before submitting the request.'), [
                'quote' => $this->publicQuote($quote),
            ]);
        }

        $proposed = $this->normalizeProposedSelections(
            $ownedUser,
            $payload['selections'] ?? [],
            (int) $quote['_plan']->meal_count,
        );
        $profile = $this->profiles->snapshot($ownedUser);
        $adminRecipients = $this->mailSettings->adminRecipientsForCompany($companyId);

        return DB::transaction(function () use (
            $ownedUser,
            $clientUuid,
            $requestFingerprint,
            $companyId,
            $code,
            $quote,
            $payload,
            $proposed,
            $profile,
            $adminRecipients,
        ): array {
            $customer = $this->customerOwnership->lockCanonicalCustomer((int) $ownedUser->customer_id);
            if (! $customer->isActive()) {
                throw ValidationException::withMessages(['account' => __('This customer account is not available.')]);
            }

            $exactReplay = MealPlanRequest::query()
                ->where('user_id', $ownedUser->id)
                ->where('client_uuid', $clientUuid)
                ->lockForUpdate()
                ->first();
            if ($exactReplay) {
                $this->assertExactReplay($exactReplay, $requestFingerprint);

                return $this->result($exactReplay, true, 200);
            }

            $promotion = MembershipPromotion::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();
            if ($promotion) {
                $existingUse = $this->existingZeroUseForPromotion(
                    (int) $promotion->id,
                    (int) $customer->id,
                    true,
                );
                if ($existingUse) {
                    return $this->result($existingUse, true, 200);
                }
            }

            try {
                $acceptedPromotion = $this->promotionQuotes->quoteForAcceptance(
                    $companyId,
                    (int) $customer->id,
                    $quote['_plan'],
                    $code,
                );
            } catch (PaymentCheckoutException) {
                throw new PaymentCheckoutException(
                    'QUOTE_CHANGED',
                    409,
                    __('Your promotion quote changed. Review it before submitting the request.'),
                );
            }
            if ((int) $acceptedPromotion['net_cents'] !== 0
                || ! hash_equals(
                    (string) $quote['_promotion']['acceptance_fingerprint'],
                    (string) $acceptedPromotion['acceptance_fingerprint'],
                )) {
                throw new PaymentCheckoutException(
                    'QUOTE_CHANGED',
                    409,
                    __('Your promotion quote changed. Review it before submitting the request.'),
                );
            }

            $termsSnapshot = [
                'version' => (string) $quote['terms_version'],
                'url' => (string) $quote['terms_url'],
                'content_hash' => (string) $quote['terms_content_hash'],
                'accepted_at' => now('UTC')->toIso8601String(),
                'support_phone' => (string) $quote['support_phone'],
            ];
            $request = MealPlanRequest::query()->create([
                'customer_id' => $customer->id,
                'user_id' => $ownedUser->id,
                'customer_name' => $profile['full_name'],
                'customer_phone' => $profile['phone'],
                'customer_email' => $profile['email'],
                'delivery_address' => $profile['address'],
                'notes' => null,
                'plan_meals' => (int) $quote['_plan']->meal_count,
                'status' => 'new',
                'submission_kind' => 'promo_request',
                'client_uuid' => $clientUuid,
                'promotion_id' => $acceptedPromotion['promotion']->id,
                'submission_snapshot' => [
                    'request_fingerprint' => $requestFingerprint,
                    'selected_branch_id' => (int) $quote['_context']['branch']->id,
                    'plan' => $quote['plan'],
                    'proposed_selections' => $proposed,
                    'promotion' => $acceptedPromotion['offer_snapshot'],
                    'terms' => $termsSnapshot,
                    'submitted_quote_fingerprint' => (string) $payload['quote_fingerprint'],
                ],
                'proposed_selections_snapshot' => $proposed,
                'promotion_terms_snapshot' => [
                    'offer' => $acceptedPromotion['offer_snapshot'],
                    'terms' => $termsSnapshot,
                ],
                'notification_dispatch' => [
                    'customer_confirmation' => ['state' => 'pending'],
                    'admin_confirmation' => ['state' => 'pending'],
                ],
            ]);

            $redemption = MembershipPromotionRedemption::query()->create([
                'promotion_id' => $acceptedPromotion['promotion']->id,
                'company_id' => $companyId,
                'branch_id' => (int) $quote['_context']['branch']->id,
                'original_customer_id' => $customer->id,
                'original_user_id' => $ownedUser->id,
                'kind' => MembershipPromotionRedemption::KIND_ZERO_REQUEST,
                'meal_plan_request_id' => $request->id,
                'offer_snapshot' => $acceptedPromotion['offer_snapshot'],
                'eligibility_snapshot' => $acceptedPromotion['eligibility_snapshot'],
                'gross_cents' => $acceptedPromotion['gross_cents'],
                'discount_cents' => $acceptedPromotion['discount_cents'],
                'net_cents' => 0,
                'redeemed_at' => now('UTC'),
                'zero_subject_key' => $this->zeroSubjectKey(
                    $companyId,
                    (int) $acceptedPromotion['promotion']->id,
                    (int) $customer->id,
                ),
            ]);
            $request->update([
                'redemption_id' => $redemption->id,
                'notification_snapshots' => [
                    'meal_plan_request_id' => (int) $request->id,
                    'reference' => $clientUuid,
                    'customer_name' => $profile['full_name'],
                    'customer_email' => $profile['email'],
                    'admin_emails' => $adminRecipients,
                    'plan' => $quote['plan'],
                    'promotion' => $acceptedPromotion['offer_snapshot'],
                    'proposed_selections' => $proposed,
                    'gross_amount_cents' => (int) $acceptedPromotion['gross_cents'],
                    'discount_amount_cents' => (int) $acceptedPromotion['discount_cents'],
                    'payable_amount_cents' => 0,
                    'terms' => $termsSnapshot,
                ],
            ]);

            $this->auditLog->log('membership_promotion.zero_redeemed', (int) $ownedUser->id, $redemption, [
                'promotion_id' => (int) $redemption->promotion_id,
                'meal_plan_request_id' => (int) $request->id,
                'original_customer_id' => (int) $customer->id,
                'original_user_id' => (int) $ownedUser->id,
                'quote_fingerprint' => (string) $payload['quote_fingerprint'],
                'terms_version' => (string) $quote['terms_version'],
                'proposed_main_quantity' => (int) $proposed['main_quantity'],
            ], $companyId);

            DB::afterCommit(function () use ($request): void {
                SendMembershipPromotionRequestConfirmation::dispatch((int) $request->id, 'customer');
                SendMembershipPromotionRequestConfirmation::dispatch((int) $request->id, 'admin');
            });

            return $this->result($request->fresh(), false, 201);
        }, 3);
    }

    public function findOwned(User $user, string $reference): MealPlanRequest
    {
        $user = $this->identityResolver->resolveForCheckout($user);
        $customerIds = $this->customerOwnership->historicalCustomerIds((int) $user->customer_id);

        return MealPlanRequest::query()
            ->where('client_uuid', strtolower(trim($reference)))
            ->where('submission_kind', 'promo_request')
            ->whereIn('customer_id', $customerIds)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    public function present(MealPlanRequest $request): array
    {
        $submission = is_array($request->submission_snapshot) ? $request->submission_snapshot : [];
        $proposed = is_array($request->proposed_selections_snapshot) ? $request->proposed_selections_snapshot : [];
        $promotionTerms = is_array($request->promotion_terms_snapshot) ? $request->promotion_terms_snapshot : [];
        $offer = is_array($promotionTerms['offer'] ?? null) ? $promotionTerms['offer'] : [];
        $dispatch = is_array($request->notification_dispatch) ? $request->notification_dispatch : [];

        return [
            'reference' => (string) $request->client_uuid,
            'request_id' => (int) $request->id,
            'result_kind' => 'pending_request',
            'status' => (string) $request->status,
            'message' => __('No payment required. Request received; your membership is not active yet.'),
            'plan' => $submission['plan'] ?? null,
            'promotion' => [
                'code' => $offer['code'] ?? null,
                'discount_type' => $offer['discount_type'] ?? null,
                'fixed_amount_cents' => $offer['fixed_amount_cents'] ?? null,
                'percentage_basis_points' => $offer['percentage_basis_points'] ?? null,
                'offer_revision' => $offer['revision'] ?? null,
            ],
            'gross_amount_cents' => (int) ($request->promotionRedemption?->gross_cents ?? 0),
            'discount_amount_cents' => (int) ($request->promotionRedemption?->discount_cents ?? 0),
            'payable_amount_cents' => 0,
            'currency' => 'QAR',
            'proposed_selections' => array_values((array) ($proposed['selections'] ?? [])),
            'excluded_today' => array_values((array) ($proposed['excluded_today'] ?? [])),
            'main_quantity' => (int) ($proposed['main_quantity'] ?? 0),
            'terms_version' => data_get($promotionTerms, 'terms.version'),
            'notifications' => [
                'customer' => data_get($dispatch, 'customer_confirmation.state'),
                'admin' => data_get($dispatch, 'admin_confirmation.state'),
            ],
        ];
    }

    private function existingZeroUse(int $companyId, int $customerId, string $code): ?MealPlanRequest
    {
        $promotion = MembershipPromotion::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        return $promotion
            ? $this->existingZeroUseForPromotion((int) $promotion->id, $customerId)
            : null;
    }

    private function existingZeroUseForPromotion(int $promotionId, int $customerId, bool $forUpdate = false): ?MealPlanRequest
    {
        $customerIds = $this->customerOwnership->historicalCustomerIds($customerId);
        $redemption = MembershipPromotionRedemption::query()
            ->where('promotion_id', $promotionId)
            ->where('kind', MembershipPromotionRedemption::KIND_ZERO_REQUEST)
            ->whereIn('original_customer_id', $customerIds)
            ->orderBy('redeemed_at')
            ->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->first();

        return $redemption?->request()->first();
    }

    /** @param array<string, mixed> $payload */
    private function requestFingerprint(array $payload, string $code): string
    {
        return $this->canonicalizer->hash([
            'membership-promotion-request-v1',
            (string) ($payload['selected_branch_id'] ?? ''),
            trim((string) ($payload['plan_code'] ?? '')),
            $code,
            is_array($payload['selections'] ?? null) ? $payload['selections'] : null,
            (string) ($payload['quote_fingerprint'] ?? ''),
            (string) ($payload['accepted_terms_version'] ?? ''),
        ]);
    }

    private function assertExactReplay(MealPlanRequest $request, string $requestFingerprint): void
    {
        $saved = (string) data_get($request->submission_snapshot, 'request_fingerprint', '');
        if ($request->submission_kind !== 'promo_request'
            || $saved === ''
            || ! hash_equals($saved, $requestFingerprint)) {
            throw new PaymentCheckoutException(
                'REQUEST_CHANGED',
                409,
                __('This request identifier was already used for a different submission.'),
            );
        }
    }

    /**
     * @param  array<int, mixed>  $rawSelections
     * @return array{selections:array<int,array<string,mixed>>,excluded_today:array<int,array<string,mixed>>,main_quantity:int}
     */
    private function normalizeProposedSelections(User $user, array $rawSelections, int $mealLimit): array
    {
        if ($rawSelections === []) {
            return ['selections' => [], 'excluded_today' => [], 'main_quantity' => 0];
        }

        $normalized = $this->ordinaryQuotes->normalizeCart(['items' => $rawSelections]);
        $membershipSelections = array_map(function (array $day): array {
            $mainQuantity = 0;
            foreach ($day['mains'] as $main) {
                if ($main['portion'] !== 'plate') {
                    throw ValidationException::withMessages([
                        'selections' => __('Membership meals support plate main dishes only.'),
                    ]);
                }
                $mainQuantity += (int) $main['qty'];
            }

            return [
                'key' => $day['date'],
                'mains' => $day['mains'],
                'salad_qty' => $mainQuantity,
                'dessert_qty' => $mainQuantity,
                'notes' => $day['notes'],
            ];
        }, $normalized);
        $ordinaryQuote = $this->ordinaryQuotes->quote($user, ['cart' => ['items' => $membershipSelections]]);
        $selections = array_values((array) data_get($ordinaryQuote, 'cart.items', []));
        $mainQuantity = collect($selections)->sum(fn (array $day): int => collect($day['mains'] ?? [])
            ->sum(fn (array $main): int => (int) ($main['qty'] ?? 0)));
        if ($mainQuantity > $mealLimit) {
            throw ValidationException::withMessages([
                'selections' => __('The proposed meals exceed the selected membership allowance.'),
            ]);
        }

        return [
            'selections' => $selections,
            'excluded_today' => array_values((array) ($ordinaryQuote['excluded_today'] ?? [])),
            'main_quantity' => $mainQuantity,
        ];
    }

    private function zeroSubjectKey(int $companyId, int $promotionId, int $customerId): string
    {
        return $this->canonicalizer->hash([
            'membership-promotion-zero-subject-v1',
            (string) $companyId,
            (string) $promotionId,
            (string) $customerId,
        ]);
    }

    /** @return array<string, mixed> */
    private function publicQuote(array $quote): array
    {
        return array_diff_key($quote, array_flip(['_context', '_plan', '_promotion']));
    }

    /** @return array{result:array<string,mixed>,replayed:bool,status:int} */
    private function result(MealPlanRequest $request, bool $replayed, int $status): array
    {
        $request->loadMissing('promotionRedemption');

        return [
            'result' => $this->present($request),
            'replayed' => $replayed,
            'status' => $status,
        ];
    }
}
