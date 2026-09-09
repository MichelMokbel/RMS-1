<?php

namespace App\Services\Payments;

use App\Jobs\InitiateSkipCashCheckout;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\User;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Subscriptions\MembershipBookingQuoteService;
use App\Services\Subscriptions\MembershipQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipBookingCheckoutService
{
    public function __construct(
        private readonly MembershipBookingQuoteService $quotes,
        private readonly MembershipQueueService $queues,
        private readonly PaymentSetupService $setup,
        private readonly SkipCashCustomerProfileService $profiles,
        private readonly CheckoutCanonicalizer $canonicalizer,
        private readonly CheckoutStatusPresenter $statuses,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly PaymentConsistencyDispatchService $paymentConsistency,
    ) {}

    /** @param array<string,mixed> $request
     * @return array{result:array<string,mixed>,replayed:bool,status:int}
     */
    public function create(User $user, array $request): array
    {
        $replay = $this->replay($user, $request);
        if ($replay !== null) {
            return $replay;
        }

        $clientUuid = strtolower(trim((string) ($request['client_uuid'] ?? '')));
        $requestFingerprint = $this->canonicalizer->hash([
            'membership-booking-checkout-request-v1',
            (string) ($request['selected_branch_id'] ?? ''),
            (string) ($request['queue_reference'] ?? ''),
            (string) ($request['queue_revision'] ?? ''),
            $request['selections'] ?? [],
            (string) ($request['quote_fingerprint'] ?? ''),
            (string) ($request['accepted_terms_version'] ?? ''),
        ]);
        $quote = $this->quotes->quote($user, $request);
        if ((int) $quote['payable_amount_cents'] <= 0) {
            throw new PaymentCheckoutException('PAYMENT_NOT_REQUIRED', 409, __('This membership booking does not require payment.'));
        }
        if (! hash_equals((string) $quote['quote_fingerprint'], (string) ($request['quote_fingerprint'] ?? ''))) {
            throw new PaymentCheckoutException('QUOTE_CHANGED', 409, __('Your membership booking changed. Review it before paying.'));
        }
        if ((string) ($request['accepted_terms_version'] ?? '') !== (string) $quote['terms_version']) {
            throw new PaymentCheckoutException('TERMS_CHANGED', 409, __('Accept the current terms before paying.'));
        }

        $this->setup->assertReadyForNewCheckout((int) $quote['_context']['branch']->id);
        $ownedUser = $user->fresh('customer');
        $profile = $this->profiles->snapshot($ownedUser);
        $result = DB::transaction(function () use ($ownedUser, $quote, $request, $clientUuid, $requestFingerprint, $profile): array {
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
                    throw new PaymentCheckoutException('REQUEST_CHANGED', 409, __('This checkout identifier was already used for a different booking.'));
                }

                return ['attempt' => $existing, 'replayed' => true];
            }
            $unresolved = PaymentCheckoutAttempt::query()
                ->whereIn('customer_id', $this->customerOwnership->historicalCustomerIds($customer->id))
                ->where('purpose', 'membership_booking')
                ->whereIn('state', ['initiating', 'pending', 'paid_processing'])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($unresolved) {
                throw new PaymentCheckoutException('EXISTING_CHECKOUT', 409, __('You already have a membership add-on payment in progress.'), [
                    'recovery_reference' => $unresolved->reference,
                ]);
            }
            $context = $quote['_context'];
            $roots = $this->queues->lockCompatibleRoots(
                (int) $customer->id,
                (int) $context['company_id'],
                (int) $context['branch']->id,
            );
            $quote = $this->quotes->quote($ownedUser, $request);
            if ((int) $quote['payable_amount_cents'] <= 0
                || ! hash_equals((string) $quote['quote_fingerprint'], (string) ($request['quote_fingerprint'] ?? ''))
                || (string) ($request['accepted_terms_version'] ?? '') !== (string) $quote['terms_version']) {
                throw new PaymentCheckoutException('QUOTE_CHANGED', 409, __('Your membership booking changed. Review it before paying.'));
            }
            $context = $quote['_context'];
            $root = $roots->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();
            if (! $root || ! hash_equals((string) $root->subscription_code, (string) $quote['queue_reference'])
                || (int) $quote['queue_revision'] !== (int) $request['queue_revision']) {
                throw new PaymentCheckoutException('MEMBERSHIP_QUEUE_CHANGED', 409, __('Your membership balance changed. Review it before paying.'));
            }
            $startedAt = now('Asia/Qatar');
            $terms = $context['terms'];
            $attempt = PaymentCheckoutAttempt::query()->create([
                'reference' => (string) Str::uuid(),
                'company_id' => $context['company_id'],
                'branch_id' => $context['branch']->id,
                'customer_id' => $customer->id,
                'portal_user_id' => $ownedUser->id,
                'payment_source_id' => $context['source']->id,
                'client_uuid' => $clientUuid,
                'purpose' => 'membership_booking',
                'currency' => 'QAR',
                'gross_amount_cents' => (int) $quote['payable_amount_cents'],
                'discount_amount_cents' => 0,
                'payable_amount_cents' => (int) $quote['payable_amount_cents'],
                'cart_fingerprint' => $this->canonicalizer->hash(['membership-booking-add-ons-v1', $quote['selections']]),
                'quote_fingerprint' => $quote['quote_fingerprint'],
                'request_fingerprint' => $requestFingerprint,
                'recovery_fingerprint' => $this->canonicalizer->hash([
                    'membership-booking-recovery-v1',
                    (string) $context['company_id'],
                    (string) $context['branch']->id,
                    (string) $quote['queue_reference'],
                    $quote['selections'],
                ]),
                'state' => 'initiating',
                'started_at' => $startedAt,
                'expires_at' => $startedAt->copy()->addMinutes((int) $context['settings']->checkout_duration_minutes),
                'cart_snapshot' => ['purpose' => 'membership_booking', 'selections' => $quote['selections']],
                'customer_snapshot' => $profile,
                'pricing_snapshot' => ['add_on_amount_cents' => (int) $quote['payable_amount_cents']],
                'terms_snapshot' => [
                    'version' => $terms['version'],
                    'url' => $terms['url'],
                    'content_hash' => $terms['content_hash'],
                    'accepted_at' => $startedAt->toIso8601String(),
                    'support_phone' => $context['settings']->order_support_phone,
                ],
                'request_snapshot' => [
                    'selected_branch_id' => (int) $context['branch']->id,
                    'queue_reference' => (string) $quote['queue_reference'],
                    'queue_revision' => (int) $quote['queue_revision'],
                    'selections' => $quote['selections'],
                ],
                'source_account_snapshot' => [
                    'payment_source_id' => $context['source']->id,
                    'clearing_account_id' => $context['source']->clearing_account_id,
                ],
                'provider_request_uuid' => (string) Str::uuid(),
                'provider_create_outcome' => 'not_sent',
                'next_recovery_at' => $startedAt,
            ]);
            foreach (array_values($quote['_priced_days']) as $index => $day) {
                PaymentCheckoutTarget::query()->create([
                    'attempt_id' => $attempt->id,
                    'sequence' => $index + 1,
                    'target_type' => 'order',
                    'service_date' => $day['date'],
                    'expected_amount_cents' => (int) $day['add_on_total_cents'],
                    'item_snapshot' => [
                        'kind' => 'membership_booking',
                        'date' => $day['date'],
                        'submission' => $day['submission'],
                        'order_lines' => $day['order_lines'],
                        'notes' => $day['notes'],
                        'booking_cutoff_time' => (string) $context['settings']->booking_cutoff_time,
                    ],
                    'hold_state' => 'held',
                    'held_at' => $startedAt,
                    'membership_subscription_id' => $root->id,
                    'membership_main_quantity' => (int) collect($day['submission']['mains'])
                        ->sum(fn (array $main): int => (int) $main['qty']),
                ]);
            }
            DB::afterCommit(fn () => InitiateSkipCashCheckout::dispatch($attempt->id));
            $this->paymentConsistency->checkoutGraphAfterCommit($attempt->id, 'payment_checkout_attempt', $attempt->id, 'created');

            return ['attempt' => $attempt, 'replayed' => false];
        }, 3);

        return [
            'result' => $this->statuses->present($result['attempt']->fresh(), true),
            'replayed' => $result['replayed'],
            'status' => $result['replayed'] ? 200 : 202,
        ];
    }

    /** @param array<string,mixed> $request
     * @return array{result:array<string,mixed>,replayed:bool,status:int}|null
     */
    public function replay(User $user, array $request): ?array
    {
        $clientUuid = strtolower(trim((string) ($request['client_uuid'] ?? '')));
        if (! Str::isUuid($clientUuid)) {
            throw ValidationException::withMessages(['client_uuid' => __('A valid checkout identifier is required.')]);
        }
        $requestFingerprint = $this->canonicalizer->hash([
            'membership-booking-checkout-request-v1',
            (string) ($request['selected_branch_id'] ?? ''),
            (string) ($request['queue_reference'] ?? ''),
            (string) ($request['queue_revision'] ?? ''),
            $request['selections'] ?? [],
            (string) ($request['quote_fingerprint'] ?? ''),
            (string) ($request['accepted_terms_version'] ?? ''),
        ]);
        $existing = PaymentCheckoutAttempt::query()
            ->where('portal_user_id', $user->id)
            ->where('client_uuid', $clientUuid)
            ->first();
        if (! $existing) {
            return null;
        }
        if (! hash_equals((string) $existing->request_fingerprint, $requestFingerprint)) {
            throw new PaymentCheckoutException('REQUEST_CHANGED', 409, __('This checkout identifier was already used for a different booking.'));
        }

        return ['result' => $this->statuses->present($existing, true), 'replayed' => true, 'status' => 200];
    }
}
