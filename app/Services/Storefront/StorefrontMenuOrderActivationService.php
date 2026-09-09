<?php

namespace App\Services\Storefront;

use App\Jobs\SendSkipCashOrderConfirmation;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderTransaction;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\AR\ArPaymentService;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Payments\PaymentCheckoutException;
use Illuminate\Support\Facades\DB;

class StorefrontMenuOrderActivationService
{
    public function __construct(
        private readonly StorefrontMenuOrderCreationService $orders,
        private readonly ArPaymentService $payments,
        private readonly AccountingAuditLogService $auditLog,
        private readonly CustomerOwnershipService $customerOwnership,
    ) {}

    public function complete(int $attemptId, int $providerTransactionId): PaymentCheckoutAttempt
    {
        return DB::transaction(function () use ($attemptId, $providerTransactionId): PaymentCheckoutAttempt {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->state === 'completed') {
                return $attempt->fresh('targets.invoice');
            }
            if ($attempt->purpose !== 'menu_order' || $attempt->state !== 'paid_processing') {
                throw new PaymentCheckoutException('CAPTURE_NOT_ACCEPTED', 409, __('This menu payment is not ready for completion.'));
            }

            $providerTransaction = PaymentProviderTransaction::query()
                ->lockForUpdate()
                ->findOrFail($providerTransactionId);
            $intent = is_array($attempt->financial_intent) ? $attempt->financial_intent : [];
            if ((int) ($intent['provider_transaction_id'] ?? 0) !== $providerTransaction->id
                || (int) $providerTransaction->attempt_id !== (int) $attempt->id
                || $providerTransaction->verified_paid_at === null
                || (int) $providerTransaction->verified_amount_cents !== (int) $attempt->payable_amount_cents
                || (string) $providerTransaction->verified_currency !== 'QAR') {
                throw new PaymentCheckoutException('CAPTURE_MISMATCH', 409, __('The payment capture does not match this menu checkout.'));
            }

            $customer = $this->customerOwnership->lockCanonicalCustomer((int) $attempt->customer_id);
            if (! $customer->isActive()) {
                throw new PaymentCheckoutException('CUSTOMER_UNAVAILABLE', 409, __('The payment customer is unavailable.'));
            }
            $source = $attempt->paymentSource()->lockForUpdate()->first();
            if (! $source
                || (int) $source->company_id !== (int) $attempt->company_id
                || (int) $source->id !== (int) ($attempt->source_account_snapshot['payment_source_id'] ?? 0)) {
                throw new PaymentCheckoutException('PAYMENT_SOURCE_MISMATCH', 503, __('The payment source is unavailable.'));
            }
            $clearingAccountId = (int) ($attempt->source_account_snapshot['clearing_account_id'] ?? 0);
            if ($clearingAccountId <= 0) {
                throw new PaymentCheckoutException('CLEARING_ACCOUNT_MISSING', 503, __('The payment clearing account is unavailable.'));
            }

            $targets = PaymentCheckoutTarget::query()
                ->where('attempt_id', $attempt->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();
            if ($targets->count() !== 1
                || (int) $targets->sum('expected_amount_cents') !== (int) $attempt->payable_amount_cents) {
                throw new PaymentCheckoutException('TARGET_TOTAL_MISMATCH', 503, __('The menu checkout target is inconsistent.'));
            }

            $actorId = (int) config('payments.system_user_id');
            if ($actorId <= 0) {
                throw new PaymentCheckoutException('SYSTEM_ACTOR_MISSING', 503, __('The payment system actor is unavailable.'));
            }
            $invoiceDate = (string) ($intent['invoice_issue_date'] ?? '');
            $allocationDate = (string) ($intent['allocation_date'] ?? '');
            if ($invoiceDate === '' || $allocationDate === '') {
                throw new PaymentCheckoutException('FINANCIAL_DATE_MISSING', 503, __('The payment financial date is unavailable.'));
            }

            $target = $targets->first();
            if ($target->order_id || $target->invoice_id || $target->hold_state !== 'held') {
                throw new PaymentCheckoutException('TARGET_ALREADY_LINKED', 409, __('The menu checkout target was already activated.'));
            }
            $created = $this->orders->create($attempt, $target, $customer->id, $actorId, $invoiceDate);
            $order = $created['order'];
            $invoice = $created['invoice'];
            $target->update([
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'hold_state' => 'activated',
                'activated_at' => now('UTC'),
                'intended_invoice_issue_date' => $invoiceDate,
            ]);

            $payment = $this->payments->createPaymentWithAllocations([
                'customer_id' => $customer->id,
                'branch_id' => $attempt->branch_id,
                'amount_cents' => (int) $attempt->payable_amount_cents,
                'method' => 'skipcash',
                'currency' => 'QAR',
                'received_at' => $providerTransaction->verified_finished_at,
                'reference' => $providerTransaction->provider_payment_id,
                'notes' => __('SkipCash receipt for checkout :reference', ['reference' => $attempt->reference]),
                'client_uuid' => $providerTransaction->receipt_client_uuid,
                'payment_source_id' => $source->id,
                'settlement_account_id' => $clearingAccountId,
                'record_bank_transaction' => false,
                'allocations' => [[
                    'invoice_id' => $invoice->id,
                    'amount_cents' => (int) $invoice->total_cents,
                ]],
            ], $actorId);

            $payment->load('allocations');
            $invoice = $invoice->fresh();
            if ((int) $payment->amount_cents !== (int) $attempt->payable_amount_cents
                || (int) $payment->unallocatedCents() !== 0
                || $payment->allocations->count() !== 1
                || (int) $payment->allocations->sum('amount_cents') !== (int) $attempt->payable_amount_cents
                || $invoice->status !== 'paid'
                || (int) $invoice->balance_cents !== 0) {
                throw new PaymentCheckoutException('PAYMENT_ALLOCATION_INCOMPLETE', 503, __('The payment allocation could not be completed.'));
            }

            $providerTransaction->update([
                'payment_id' => $payment->id,
                'classification' => 'purchase',
                'receipt_date' => $allocationDate,
            ]);
            $notificationSnapshots = is_array($attempt->notification_snapshots)
                ? $attempt->notification_snapshots
                : [];
            $notificationSnapshots['order_ids'] = [(int) $order->id];
            $notificationSnapshots['order_number'] = (string) $order->order_number;
            $notificationSnapshots['invoice_ids'] = [(int) $invoice->id];
            $notificationSnapshots['invoice_id'] = (int) $invoice->id;
            $notificationSnapshots['payment_id'] = (int) $payment->id;
            $notificationSnapshots['payment_reference'] = (string) $providerTransaction->provider_payment_id;
            $notificationSnapshots['reference'] = (string) $attempt->reference;
            $attempt->update([
                'state' => 'completed',
                'completed_at' => now('UTC'),
                'next_recovery_at' => null,
                'last_error_code' => null,
                'notification_snapshots' => $notificationSnapshots,
                'notification_dispatch' => [
                    'customer_confirmation' => ['state' => 'pending'],
                    'admin_confirmation' => ['state' => 'pending'],
                ],
            ]);
            $this->auditLog->log('payment.checkout.completed', $actorId, $attempt, [
                'attempt_id' => $attempt->id,
                'provider_transaction_id' => $providerTransaction->id,
                'payment_id' => $payment->id,
                'order_ids' => [$order->id],
                'invoice_ids' => [$invoice->id],
                'receipt_date' => $allocationDate,
                'purpose' => 'menu_order',
            ], $attempt->company_id);
            DB::afterCommit(function () use ($attempt): void {
                SendSkipCashOrderConfirmation::dispatch($attempt->id, 'customer');
                SendSkipCashOrderConfirmation::dispatch($attempt->id, 'admin');
            });

            return $attempt->fresh('targets.invoice');
        }, 3);
    }
}
