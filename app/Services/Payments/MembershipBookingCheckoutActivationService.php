<?php

namespace App\Services\Payments;

use App\Jobs\SendSkipCashOrderConfirmation;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderTransaction;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\AR\ArPaymentService;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
use App\Services\Subscriptions\MembershipBookingService;
use App\Services\Subscriptions\MembershipQueueService;
use Illuminate\Support\Facades\DB;

class MembershipBookingCheckoutActivationService
{
    public function __construct(
        private readonly ArPaymentService $payments,
        private readonly MembershipQueueService $queues,
        private readonly MembershipBookingService $bookings,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly AccountingAuditLogService $auditLog,
        private readonly MailSettingsService $mailSettings,
    ) {}

    public function complete(int $attemptId, int $providerTransactionId): PaymentCheckoutAttempt
    {
        return DB::transaction(function () use ($attemptId, $providerTransactionId): PaymentCheckoutAttempt {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->state === 'completed') {
                return $attempt->fresh('targets.invoice');
            }
            if ($attempt->purpose !== 'membership_booking' || $attempt->state !== 'paid_processing') {
                throw new PaymentCheckoutException('CAPTURE_NOT_ACCEPTED', 409, __('This membership add-on payment is not ready for completion.'));
            }
            $provider = PaymentProviderTransaction::query()->lockForUpdate()->findOrFail($providerTransactionId);
            $intent = (array) $attempt->financial_intent;
            if ((int) ($intent['provider_transaction_id'] ?? 0) !== (int) $provider->id
                || (int) $provider->attempt_id !== (int) $attempt->id
                || ! $provider->verified_paid_at
                || (int) $provider->verified_amount_cents !== (int) $attempt->payable_amount_cents
                || (string) $provider->verified_currency !== 'QAR') {
                throw new PaymentCheckoutException('CAPTURE_MISMATCH', 409, __('The payment capture does not match this membership booking.'));
            }
            $customer = $this->customerOwnership->lockCanonicalCustomer((int) $attempt->customer_id);
            $source = $attempt->paymentSource()->lockForUpdate()->first();
            $clearingAccountId = (int) ($attempt->source_account_snapshot['clearing_account_id'] ?? 0);
            if (! $customer->isActive() || ! $source || (int) $source->company_id !== (int) $attempt->company_id
                || (int) $source->id !== (int) ($attempt->source_account_snapshot['payment_source_id'] ?? 0)
                || $clearingAccountId <= 0) {
                throw new PaymentCheckoutException('PAYMENT_CONTEXT_UNAVAILABLE', 503, __('The payment context is unavailable.'));
            }
            $targets = PaymentCheckoutTarget::query()->where('attempt_id', $attempt->id)->orderBy('sequence')->lockForUpdate()->get();
            if ($targets->isEmpty() || $targets->contains(fn (PaymentCheckoutTarget $target): bool => $target->target_type !== 'order'
                || ! in_array($target->hold_state, ['held', 'released'], true) || $target->order_id || $target->invoice_id)
                || $targets->pluck('hold_state')->unique()->count() !== 1
                || (int) $targets->sum('expected_amount_cents') !== (int) $attempt->payable_amount_cents) {
                throw new PaymentCheckoutException('TARGET_TOTAL_MISMATCH', 503, __('The membership booking targets are inconsistent.'));
            }
            $actorId = (int) config('payments.system_user_id');
            $invoiceDate = (string) ($intent['invoice_issue_date'] ?? '');
            $allocationDate = (string) ($intent['allocation_date'] ?? '');
            if ($actorId <= 0 || $invoiceDate === '' || $allocationDate === '') {
                throw new PaymentCheckoutException('FINANCIAL_CONTEXT_MISSING', 503, __('The payment financial context is unavailable.'));
            }
            $roots = $this->queues->lockCompatibleRoots($customer->id, $attempt->company_id, $attempt->branch_id);
            $root = $roots->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();
            if (! $root || $targets->contains(fn (PaymentCheckoutTarget $target): bool => (int) $target->membership_subscription_id !== (int) $root->id)) {
                throw new PaymentCheckoutException('MEMBERSHIP_SCOPE_CHANGED', 409, __('Your membership changed before payment could be applied.'));
            }
            $payment = $this->payments->createPaymentWithAllocations([
                'customer_id' => $customer->id,
                'branch_id' => $attempt->branch_id,
                'amount_cents' => $attempt->payable_amount_cents,
                'method' => 'skipcash',
                'currency' => 'QAR',
                'received_at' => $provider->verified_finished_at,
                'reference' => $provider->provider_payment_id,
                'notes' => __('SkipCash membership add-on receipt for checkout :reference', ['reference' => $attempt->reference]),
                'client_uuid' => $provider->receipt_client_uuid,
                'payment_source_id' => $source->id,
                'settlement_account_id' => $clearingAccountId,
                'record_bank_transaction' => false,
                'allocations' => [],
            ], $actorId);
            $provider->update(['payment_id' => $payment->id, 'classification' => 'purchase', 'receipt_date' => $allocationDate]);
            if (! $provider->verified_finished_at || ! $provider->verified_finished_at->lt($attempt->expires_at)) {
                return $this->retainAsCredit($attempt, $provider, $payment->id, $actorId, $allocationDate);
            }
            if ($targets->first()->hold_state === 'released') {
                $requiredMeals = (int) $targets->sum('membership_main_quantity');
                $queue = $this->queues->summary($customer->id, $attempt->company_id, $attempt->branch_id);
                if ($requiredMeals <= 0 || (int) $queue['available_meals'] < $requiredMeals) {
                    return $this->retainAsCredit($attempt, $provider, $payment->id, $actorId, $allocationDate);
                }
            }
            $portalUser = $attempt->portalUser()->with('customer')->firstOrFail();
            $created = [];
            try {
                DB::transaction(function () use ($targets, $portalUser, $customer, $attempt, $root, $roots, $invoiceDate, $actorId, $payment, &$created): void {
                    foreach ($targets as $target) {
                        $snapshot = (array) $target->item_snapshot;
                        $booking = $this->bookings->createBookedDay(
                            user: $portalUser,
                            customerId: $customer->id,
                            branchId: $attempt->branch_id,
                            root: $root,
                            roots: $roots,
                            day: [
                                'date' => (string) ($snapshot['date'] ?? ''),
                                'submission' => (array) ($snapshot['submission'] ?? []),
                                'order_lines' => (array) ($snapshot['order_lines'] ?? []),
                                'notes' => $snapshot['notes'] ?? null,
                            ],
                            operationUuid: (string) $attempt->client_uuid,
                            issueDate: $invoiceDate,
                            actorId: $actorId,
                            cutoff: (string) ($snapshot['booking_cutoff_time'] ?? '23:00:00'),
                            profileSnapshot: $this->bookingProfileSnapshot($attempt),
                            checkoutPayment: $payment,
                            checkoutAddOnCents: (int) $target->expected_amount_cents,
                        );
                        $target->update([
                            'order_id' => $booking['order_id'],
                            'invoice_id' => $booking['invoice_id'],
                            'hold_state' => 'activated',
                            'activated_at' => now('UTC'),
                            'released_at' => null,
                            'intended_invoice_issue_date' => $invoiceDate,
                        ]);
                        $created[] = $booking;
                    }
                });
            } catch (\RuntimeException $exception) {
                if ($exception->getMessage() !== 'The membership does not have enough available meals.') {
                    throw $exception;
                }

                return $this->retainAsCredit($attempt, $provider, $payment->id, $actorId, $allocationDate);
            }
            $payment = $payment->fresh(['allocations']);
            if ($payment->unallocatedCents() !== 0 || (int) $payment->allocations->sum('amount_cents') !== (int) $attempt->payable_amount_cents) {
                throw new PaymentCheckoutException('PAYMENT_ALLOCATION_INCOMPLETE', 503, __('The add-on payment allocation could not be completed.'));
            }
            $attempt->update([
                'state' => 'completed',
                'completed_at' => now('UTC'),
                'next_recovery_at' => null,
                'last_error_code' => null,
                'notification_snapshots' => [
                    'customer_email' => $attempt->customer_snapshot['email'] ?? null,
                    'admin_emails' => $this->adminRecipients((int) $attempt->company_id),
                    'order_ids' => array_column($created, 'order_id'),
                    'amount_cents' => (int) $attempt->payable_amount_cents,
                    'add_on_amount_cents' => (int) $attempt->payable_amount_cents,
                    'reference' => (string) $attempt->reference,
                ],
                'notification_dispatch' => ['customer_confirmation' => ['state' => 'pending'], 'admin_confirmation' => ['state' => 'pending']],
            ]);
            $this->auditLog->log('payment.membership_booking_add_ons.completed', $actorId, $attempt, [
                'payment_id' => $payment->id,
                'order_ids' => array_column($created, 'order_id'),
                'invoice_ids' => array_column($created, 'invoice_id'),
                'add_on_amount_cents' => (int) $attempt->payable_amount_cents,
            ], $attempt->company_id);
            DB::afterCommit(function () use ($attempt): void {
                SendSkipCashOrderConfirmation::dispatch($attempt->id, 'customer');
                SendSkipCashOrderConfirmation::dispatch($attempt->id, 'admin');
            });

            return $attempt->fresh('targets.invoice');
        }, 3);
    }

    private function retainAsCredit(
        PaymentCheckoutAttempt $attempt,
        PaymentProviderTransaction $provider,
        int $paymentId,
        int $actorId,
        string $allocationDate,
    ): PaymentCheckoutAttempt {
        $provider->update(['classification' => 'retained_credit']);
        PaymentCheckoutTarget::query()->where('attempt_id', $attempt->id)->update([
            'hold_state' => 'released',
            'released_at' => now('UTC'),
        ]);
        $attempt->update([
            'state' => 'payment_received_as_credit',
            'completed_at' => now('UTC'),
            'next_recovery_at' => null,
            'last_error_code' => null,
            'notification_dispatch' => null,
        ]);
        $this->auditLog->log('payment.membership_booking_add_ons.received_as_credit', $actorId, $attempt, [
            'provider_transaction_id' => $provider->id,
            'payment_id' => $paymentId,
            'receipt_date' => $allocationDate,
        ], $attempt->company_id);

        return $attempt->fresh('targets.invoice');
    }

    /** @return array{name:string|null,phone:string|null,email:string|null,address:string|null} */
    private function bookingProfileSnapshot(PaymentCheckoutAttempt $attempt): array
    {
        $snapshot = (array) $attempt->customer_snapshot;

        return [
            'name' => $snapshot['full_name'] ?? null,
            'phone' => $snapshot['phone'] ?? null,
            'email' => $snapshot['email'] ?? null,
            'address' => $snapshot['address'] ?? null,
        ];
    }

    /** @return array<int,string> */
    private function adminRecipients(int $companyId): array
    {
        try {
            return $this->mailSettings->adminRecipientsForCompany($companyId);
        } catch (MailConfigurationUnavailableException) {
            return [];
        }
    }
}
