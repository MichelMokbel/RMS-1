<?php

namespace App\Services\Payments;

use App\Jobs\InitiateSkipCashCheckout;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\User;
use App\Services\Customers\CustomerOwnershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrdinaryOrderCheckoutService
{
    public function __construct(
        private readonly OrdinaryOrderQuoteService $quotes,
        private readonly PaymentSetupService $setup,
        private readonly SkipCashCustomerProfileService $profiles,
        private readonly CheckoutCanonicalizer $canonicalizer,
        private readonly CheckoutStatusPresenter $statuses,
        private readonly CustomerOwnershipService $customerOwnership,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array{result: array<string,mixed>, replayed: bool, status: int}
     */
    public function create(User $user, array $request): array
    {
        $clientUuid = strtolower(trim((string) ($request['client_uuid'] ?? '')));
        if (! Str::isUuid($clientUuid)) {
            throw ValidationException::withMessages(['client_uuid' => __('A valid checkout identifier is required.')]);
        }

        $replay = PaymentCheckoutAttempt::query()
            ->where('portal_user_id', $user->id)
            ->where('client_uuid', $clientUuid)
            ->first();
        if ($replay) {
            $fingerprint = $this->requestFingerprint($request);
            if (! hash_equals((string) $replay->request_fingerprint, $fingerprint)) {
                throw new PaymentCheckoutException('REQUEST_CHANGED', 409, __('This checkout identifier was already used for different selections.'));
            }

            return [
                'result' => $this->statuses->present($replay, true),
                'replayed' => true,
                'status' => $this->recoveryResponseStatus($replay),
            ];
        }

        if (($request['purpose'] ?? null) !== 'ordinary_order') {
            throw ValidationException::withMessages(['purpose' => __('Only ordinary orders can use this checkout.')]);
        }

        $normalizedCart = $this->normalizedSubmittedCart($request);
        $separatePurchaseFrom = $this->separatePurchaseReference($request);
        $equivalent = $this->findOpenEquivalentCheckout($user, $normalizedCart);
        if ($equivalent) {
            $this->assertSeparatePurchaseReference($user, $normalizedCart, $separatePurchaseFrom, $equivalent);
        } elseif ($separatePurchaseFrom !== null) {
            $this->assertSeparatePurchaseReference($user, $normalizedCart, $separatePurchaseFrom);
        }

        $quote = $this->quotes->quote($user, $request);
        if (! ($quote['can_checkout'] ?? false)) {
            throw new PaymentCheckoutException('TODAY_ONLY_CART', 409, __('Remove today from your order before paying.'), [
                'quote' => $this->publicQuote($quote),
            ]);
        }
        if (($quote['excluded_today'] ?? []) !== []) {
            throw new PaymentCheckoutException('TODAY_REVIEW_REQUIRED', 409, __('Remove today from your order before paying.'), [
                'quote' => $this->publicQuote($quote),
            ]);
        }
        if (! hash_equals((string) ($quote['quote_fingerprint'] ?? ''), (string) ($request['quote_fingerprint'] ?? ''))) {
            throw new PaymentCheckoutException('QUOTE_CHANGED', 409, __('Your order details changed. Review the current quote before paying.'), [
                'quote' => $this->publicQuote($quote),
            ]);
        }
        if ((string) ($request['accepted_terms_version'] ?? '') !== (string) ($quote['terms_version'] ?? '')) {
            throw new PaymentCheckoutException('TERMS_CHANGED', 409, __('Accept the current terms before paying.'), [
                'quote' => $this->publicQuote($quote),
            ]);
        }

        $this->setup->assertReadyForNewCheckout((int) $quote['_context']['branch']->id);
        $ownedUser = $user->fresh('customer');
        $profile = $this->profiles->snapshot($ownedUser);
        $requestFingerprint = $this->requestFingerprint($request);
        $recoveryFingerprint = $this->recoveryFingerprint(
            (int) $quote['_context']['company_id'],
            (int) $quote['_context']['branch']->id,
            $normalizedCart,
        );

        $result = DB::transaction(function () use ($ownedUser, $quote, $clientUuid, $request, $profile, $requestFingerprint, $recoveryFingerprint, $normalizedCart, $separatePurchaseFrom): array {
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
                    throw new PaymentCheckoutException('REQUEST_CHANGED', 409, __('This checkout identifier was already used for different selections.'));
                }

                return ['attempt' => $existing, 'recovered' => true];
            }

            $equivalent = $this->findOpenEquivalentCheckout($ownedUser, $normalizedCart, true);
            if ($equivalent) {
                $this->assertSeparatePurchaseReference($ownedUser, $normalizedCart, $separatePurchaseFrom, $equivalent, true);
            } elseif ($separatePurchaseFrom !== null) {
                $this->assertSeparatePurchaseReference($ownedUser, $normalizedCart, $separatePurchaseFrom, null, true);
            }

            $context = $quote['_context'];
            $startedAt = now('Asia/Qatar');
            $expiresAt = $startedAt->copy()->addMinutes((int) $context['settings']->checkout_duration_minutes);
            $terms = $context['terms'];
            $attempt = PaymentCheckoutAttempt::query()->create([
                'reference' => (string) Str::uuid(),
                'company_id' => $context['company_id'],
                'branch_id' => $context['branch']->id,
                'customer_id' => $customer->id,
                'portal_user_id' => $ownedUser->id,
                'payment_source_id' => $context['source']->id,
                'client_uuid' => $clientUuid,
                'purpose' => 'ordinary_order',
                'currency' => 'QAR',
                'gross_amount_cents' => (int) $quote['gross_amount_cents'],
                'discount_amount_cents' => 0,
                'payable_amount_cents' => (int) $quote['payable_amount_cents'],
                'cart_fingerprint' => $this->canonicalizer->hash([
                    'ordinary-cart-v1',
                    (string) $context['company_id'],
                    (string) $context['branch']->id,
                    $quote['_canonical_days'],
                ]),
                'quote_fingerprint' => $quote['quote_fingerprint'],
                'request_fingerprint' => $requestFingerprint,
                'recovery_fingerprint' => $recoveryFingerprint,
                'state' => 'initiating',
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'cart_snapshot' => [
                    'cart' => $quote['cart'],
                    'excluded_today' => $quote['excluded_today'],
                ],
                'customer_snapshot' => $profile,
                'pricing_snapshot' => [
                    'pricing_version' => $quote['_pricing_version'],
                    'day_totals' => $quote['day_totals'],
                    'payable_amount_cents' => (int) $quote['payable_amount_cents'],
                ],
                'terms_snapshot' => [
                    'version' => $terms['version'],
                    'url' => $terms['url'],
                    'content_hash' => $terms['content_hash'],
                    'accepted_at' => $startedAt->toIso8601String(),
                    'support_phone' => $context['settings']->order_support_phone,
                ],
                'request_snapshot' => [
                    'purpose' => 'ordinary_order',
                    'normalized_cart' => $normalizedCart,
                    'submitted_quote_fingerprint' => (string) $request['quote_fingerprint'],
                    'accepted_terms_version' => (string) $request['accepted_terms_version'],
                    'separate_purchase_from' => $separatePurchaseFrom,
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
            foreach (array_values($quote['_priced_days']) as $index => $pricedDay) {
                PaymentCheckoutTarget::query()->create([
                    'attempt_id' => $attempt->id,
                    'sequence' => $index + 1,
                    'target_type' => 'order',
                    'service_date' => $pricedDay['date'],
                    'expected_amount_cents' => (int) $pricedDay['total_cents'],
                    'item_snapshot' => [
                        'date' => $pricedDay['date'],
                        'order_lines' => $pricedDay['order_lines'],
                        'notes' => $pricedDay['notes'],
                    ],
                    'hold_state' => 'held',
                    'held_at' => $startedAt,
                ]);
            }

            DB::afterCommit(fn () => InitiateSkipCashCheckout::dispatch($attempt->id));

            return ['attempt' => $attempt, 'recovered' => false];
        }, 3);

        $attempt = $result['attempt'];

        return [
            'result' => $this->statuses->present($attempt->fresh(), true),
            'replayed' => $result['recovered'],
            'status' => $result['recovered'] ? $this->recoveryResponseStatus($attempt) : 202,
        ];
    }

    /** @param array<string, mixed> $request */
    private function requestFingerprint(array $request): string
    {
        return $this->canonicalizer->hash([
            'ordinary-request-v1',
            'ordinary_order',
            $this->normalizedSubmittedCart($request),
            (string) ($request['quote_fingerprint'] ?? ''),
            (string) ($request['accepted_terms_version'] ?? ''),
            $this->separatePurchaseReference($request),
        ]);
    }

    /** @param array<string, mixed> $request */
    private function recoveryFingerprint(int $companyId, int $branchId, array $normalizedCart): string
    {
        return $this->canonicalizer->hash([
            'ordinary-recovery-v1',
            (string) $companyId,
            (string) $branchId,
            $normalizedCart,
        ]);
    }

    /**
     * The recovery comparison intentionally stops at syntax normalization. It
     * therefore remains usable after a menu, price, terms, or profile change.
     * A new payment still takes the normal live validation and quote path.
     *
     * @param  array<int, mixed>  $normalizedCart
     */
    private function findOpenEquivalentCheckout(User $user, array $normalizedCart, bool $forUpdate = false): ?PaymentCheckoutAttempt
    {
        $attempts = PaymentCheckoutAttempt::query()
            ->whereIn('customer_id', $this->customerOwnership->historicalCustomerIds((int) $user->customer_id))
            ->where('purpose', 'ordinary_order')
            ->whereIn('state', ['initiating', 'pending', 'paid_processing'])
            ->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->get();
        foreach ($attempts as $attempt) {
            $fingerprint = $this->recoveryFingerprint(
                (int) $attempt->company_id,
                (int) $attempt->branch_id,
                $normalizedCart,
            );
            if (hash_equals((string) $attempt->recovery_fingerprint, $fingerprint)) {
                return $attempt;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $request */
    private function separatePurchaseReference(array $request): ?string
    {
        $reference = strtolower(trim((string) ($request['separate_purchase_from'] ?? '')));
        if ($reference === '') {
            return null;
        }
        if (! Str::isUuid($reference)) {
            throw ValidationException::withMessages([
                'separate_purchase_from' => __('Choose a valid existing checkout before starting a separate purchase.'),
            ]);
        }

        return $reference;
    }

    /**
     * A repeated cart must resume its owned unresolved checkout unless the customer
     * explicitly names that same checkout as the purchase they want to keep separate.
     *
     * @param  array<int, mixed>  $normalizedCart
     */
    private function assertSeparatePurchaseReference(
        User $user,
        array $normalizedCart,
        ?string $reference,
        ?PaymentCheckoutAttempt $equivalent = null,
        bool $forUpdate = false,
    ): void {
        if ($reference === null) {
            if ($equivalent !== null) {
                throw new PaymentCheckoutException(
                    'EXISTING_CHECKOUT',
                    409,
                    __('You already have a secure checkout for these selections. Resume it before starting another purchase.'),
                    ['recovery_reference' => $equivalent->reference],
                );
            }

            return;
        }

        $source = $equivalent && hash_equals((string) $equivalent->reference, $reference)
            ? $equivalent
            : PaymentCheckoutAttempt::query()
                ->where('reference', $reference)
                ->whereIn('customer_id', $this->customerOwnership->historicalCustomerIds((int) $user->customer_id))
                ->where('purpose', 'ordinary_order')
                ->whereIn('state', ['initiating', 'pending', 'paid_processing'])
                ->when($forUpdate, fn ($query) => $query->lockForUpdate())
                ->first();

        if (! $source) {
            throw ValidationException::withMessages([
                'separate_purchase_from' => __('Choose one of your unresolved checkouts before starting a separate purchase.'),
            ]);
        }

        $fingerprint = $this->recoveryFingerprint(
            (int) $source->company_id,
            (int) $source->branch_id,
            $normalizedCart,
        );
        if (! hash_equals((string) $source->recovery_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages([
                'separate_purchase_from' => __('The selected checkout does not match these selections.'),
            ]);
        }
    }

    private function recoveryResponseStatus(PaymentCheckoutAttempt $attempt): int
    {
        if (in_array($attempt->state, ['completed', 'declined', 'expired'], true)
            || $attempt->provider_create_outcome === 'created') {
            return 200;
        }

        return 202;
    }

    /** @param array<string, mixed> $request
     * @return array<int, mixed>
     */
    private function normalizedSubmittedCart(array $request): array
    {
        $days = $this->quotes->normalizeCart(is_array($request['cart'] ?? null) ? $request['cart'] : []);

        return array_map(fn (array $day): array => [
            $day['date'],
            array_map(fn (array $main): array => [(string) $main['menu_item_id'], $main['portion'], (int) $main['qty']], $day['mains']),
            (int) $day['salad_qty'],
            (int) $day['dessert_qty'],
            $day['notes'],
        ], $days);
    }

    /** @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function publicQuote(array $quote): array
    {
        return array_diff_key($quote, array_flip(['_context', '_priced_days', '_pricing_version', '_canonical_days']));
    }
}
