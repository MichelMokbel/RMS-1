<?php

namespace App\Services\Storefront;

use App\Jobs\InitiateSkipCashCheckout;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentCheckoutTargetItem;
use App\Models\User;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
use App\Services\Payments\CheckoutCanonicalizer;
use App\Services\Payments\CheckoutStatusPresenter;
use App\Services\Payments\PaymentCheckoutException;
use App\Services\Payments\PaymentConsistencyDispatchService;
use App\Services\Payments\PaymentSetupService;
use App\Services\Payments\SkipCashCustomerProfileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StorefrontMenuCheckoutService
{
    public function __construct(
        private readonly StorefrontMenuQuoteService $quotes,
        private readonly PaymentSetupService $setup,
        private readonly SkipCashCustomerProfileService $profiles,
        private readonly CheckoutCanonicalizer $canonicalizer,
        private readonly CheckoutStatusPresenter $statuses,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly MailSettingsService $mailSettings,
        private readonly PaymentConsistencyDispatchService $paymentConsistency,
    ) {}

    /**
     * @param  array<string, mixed>  $request
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
                throw new PaymentCheckoutException(
                    'REQUEST_CHANGED',
                    409,
                    __('This checkout identifier was already used for a different menu order.'),
                );
            }

            return [
                'result' => $this->statuses->present($replay, true),
                'replayed' => true,
                'status' => $this->responseStatus($replay),
            ];
        }

        if (($request['purpose'] ?? null) !== 'menu_order') {
            throw ValidationException::withMessages(['purpose' => __('Only advance menu orders can use this checkout.')]);
        }

        $normalizedGroup = $this->quotes->normalizeGroup(
            is_array($request['group'] ?? null) ? $request['group'] : [],
        );
        $equivalent = $this->findOpenEquivalentCheckout($user, $normalizedGroup);
        if ($equivalent) {
            return [
                'result' => $this->statuses->present($equivalent, true),
                'replayed' => true,
                'status' => $this->responseStatus($equivalent),
            ];
        }

        $quote = $this->quotes->quote($user, $request + [
            'previous_quote_fingerprint' => (string) ($request['quote_fingerprint'] ?? ''),
        ]);
        if (! hash_equals((string) $quote['quote_fingerprint'], (string) ($request['quote_fingerprint'] ?? ''))) {
            throw new PaymentCheckoutException(
                'MENU_CART_CHANGED',
                409,
                __('Your menu order changed. Review the current details before paying.'),
                ['quote' => $this->quotes->publicQuote($quote)],
            );
        }
        if ((string) ($request['accepted_terms_version'] ?? '') !== (string) $quote['terms_version']) {
            throw new PaymentCheckoutException(
                'TERMS_CHANGED',
                409,
                __('Accept the current terms before paying.'),
                ['quote' => $this->quotes->publicQuote($quote)],
            );
        }

        $context = $quote['_context'];
        $this->setup->assertReadyForNewCheckout((int) $context['branch']->id);
        $ownedUser = $user->fresh('customer');
        $customerSnapshot = $this->profiles->snapshot($ownedUser);
        $adminRecipients = $this->adminRecipients((int) $context['company_id']);
        $recoveryFingerprint = $this->recoveryFingerprint(
            (int) $context['company_id'],
            (int) $context['branch']->id,
            $normalizedGroup,
        );

        $result = DB::transaction(function () use (
            $ownedUser,
            $quote,
            $request,
            $clientUuid,
            $requestFingerprint,
            $customerSnapshot,
            $adminRecipients,
            $normalizedGroup,
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
                    throw new PaymentCheckoutException(
                        'REQUEST_CHANGED',
                        409,
                        __('This checkout identifier was already used for a different menu order.'),
                    );
                }

                return ['attempt' => $existing, 'replayed' => true];
            }

            $equivalent = $this->findOpenEquivalentCheckout($ownedUser, $normalizedGroup, true);
            if ($equivalent) {
                return ['attempt' => $equivalent, 'replayed' => true];
            }

            $context = $quote['_context'];
            $startedAt = now('Asia/Qatar');
            $expiresAt = $startedAt->copy()->addMinutes((int) $context['payment_settings']->checkout_duration_minutes);
            $terms = $context['terms'];
            $group = $quote['group'];
            $attempt = PaymentCheckoutAttempt::query()->create([
                'reference' => (string) Str::uuid(),
                'company_id' => $context['company_id'],
                'branch_id' => $context['branch']->id,
                'customer_id' => $customer->id,
                'portal_user_id' => $ownedUser->id,
                'payment_source_id' => $context['source']->id,
                'client_uuid' => $clientUuid,
                'purpose' => 'menu_order',
                'currency' => 'QAR',
                'gross_amount_cents' => (int) $quote['gross_amount_cents'],
                'discount_amount_cents' => 0,
                'payable_amount_cents' => (int) $quote['payable_amount_cents'],
                'cart_fingerprint' => $this->canonicalizer->hash([
                    'menu-order-cart-v1',
                    (string) $context['company_id'],
                    (string) $context['branch']->id,
                    $group,
                ]),
                'quote_fingerprint' => $quote['quote_fingerprint'],
                'request_fingerprint' => $requestFingerprint,
                'recovery_fingerprint' => $recoveryFingerprint,
                'state' => 'initiating',
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
                'cart_snapshot' => [
                    'schema' => 'menu-order-v1',
                    'group' => $group,
                    'items' => $quote['items'],
                ],
                'customer_snapshot' => $customerSnapshot,
                'pricing_snapshot' => [
                    'schema' => 'menu-order-pricing-v1',
                    'storefront_revision' => (int) $context['storefront']->revision,
                    'quoted_at_qatar' => $startedAt->toIso8601String(),
                    'timezone' => (string) $context['storefront']->timezone,
                    'cutoff_time' => (string) $context['storefront']->menu_cutoff_time,
                    'resolved_values' => $quote['_resolved_values'],
                    'payable_amount_cents' => (int) $quote['payable_amount_cents'],
                    'add_on_amount_cents' => (int) ($quote['add_on_amount_cents'] ?? 0),
                ],
                'terms_snapshot' => [
                    'version' => $terms['version'],
                    'url' => $terms['url'],
                    'content_hash' => $terms['content_hash'],
                    'accepted_at' => $startedAt->toIso8601String(),
                    'support_phone' => $context['payment_settings']->order_support_phone,
                ],
                'request_snapshot' => [
                    'purpose' => 'menu_order',
                    'group' => $group,
                    'submitted_quote_fingerprint' => (string) $request['quote_fingerprint'],
                    'accepted_terms_version' => (string) $request['accepted_terms_version'],
                ],
                'notification_snapshots' => [
                    'customer_name' => $customerSnapshot['full_name'],
                    'customer_email' => $customerSnapshot['email'],
                    'customer_phone' => $customerSnapshot['phone'],
                    'customer_address' => $customerSnapshot['address'],
                    'admin_emails' => $adminRecipients,
                    'service_date' => $group['service_date'],
                    'items' => $quote['items'],
                    'amount_cents' => (int) $quote['payable_amount_cents'],
                    'support_phone' => $context['payment_settings']->order_support_phone,
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

            $target = PaymentCheckoutTarget::query()->create([
                'attempt_id' => $attempt->id,
                'sequence' => 1,
                'target_type' => 'order',
                'service_date' => $group['service_date'],
                'expected_amount_cents' => (int) $quote['payable_amount_cents'],
                'item_snapshot' => [
                    'schema' => 'menu-order-target-v1',
                    'service_date' => $group['service_date'],
                    'note' => $group['note'],
                    'line_count' => count($quote['items']),
                    'add_on_amount_cents' => (int) collect($quote['items'])
                        ->where('line_role', 'checkout_add_on')->sum('line_total_cents'),
                    'total_cents' => (int) $quote['payable_amount_cents'],
                ],
                'hold_state' => 'held',
                'held_at' => $startedAt,
            ]);
            foreach (array_values($quote['items']) as $index => $line) {
                PaymentCheckoutTargetItem::query()->create([
                    'target_id' => $target->id,
                    'sequence' => $index + 1,
                    'line_role' => (string) $line['line_role'],
                    'menu_item_id' => (int) $line['menu_item_id'],
                    'title' => (string) $line['title'],
                    'description' => $line['description'],
                    'unit' => (string) $line['unit'],
                    'quantity' => (string) $line['quantity'],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'line_total_cents' => (int) $line['line_total_cents'],
                ]);
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
            'menu-order-request-v1',
            'menu_order',
            $this->quotes->normalizeGroup(is_array($request['group'] ?? null) ? $request['group'] : []),
            strtolower((string) ($request['quote_fingerprint'] ?? '')),
            (string) ($request['accepted_terms_version'] ?? ''),
        ]);
    }

    /** @param array<string, mixed> $group */
    private function recoveryFingerprint(int $companyId, int $branchId, array $group): string
    {
        return $this->canonicalizer->hash([
            'menu-order-recovery-v1',
            (string) $companyId,
            (string) $branchId,
            [
                'version' => $group['version'],
                'service_date' => $group['service_date'],
                'items' => $group['items'],
            ],
        ]);
    }

    /** @param array<string, mixed> $group */
    private function findOpenEquivalentCheckout(User $user, array $group, bool $forUpdate = false): ?PaymentCheckoutAttempt
    {
        $attempts = PaymentCheckoutAttempt::query()
            ->whereIn('customer_id', $this->customerOwnership->historicalCustomerIds((int) $user->customer_id))
            ->where('purpose', 'menu_order')
            ->whereIn('state', ['initiating', 'pending', 'paid_processing'])
            ->orderBy('id')
            ->when($forUpdate, fn ($query) => $query->lockForUpdate())
            ->get();
        foreach ($attempts as $attempt) {
            $fingerprint = $this->recoveryFingerprint(
                (int) $attempt->company_id,
                (int) $attempt->branch_id,
                $group,
            );
            if (hash_equals((string) $attempt->recovery_fingerprint, $fingerprint)) {
                return $attempt;
            }
        }

        return null;
    }

    private function responseStatus(PaymentCheckoutAttempt $attempt): int
    {
        if (in_array($attempt->state, ['completed', 'declined', 'expired'], true)
            || $attempt->provider_create_outcome === 'created') {
            return 200;
        }

        return 202;
    }

    /** @return array<int, string> */
    private function adminRecipients(int $companyId): array
    {
        try {
            return $this->mailSettings->adminRecipientsForCompany($companyId);
        } catch (MailConfigurationUnavailableException) {
            return [];
        }
    }
}
