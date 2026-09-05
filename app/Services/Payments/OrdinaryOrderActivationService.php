<?php

namespace App\Services\Payments;

use App\Jobs\SendSkipCashOrderConfirmation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Models\PaymentProviderTransaction;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\AR\ArInvoiceService;
use App\Services\AR\ArPaymentService;
use App\Services\Customers\CustomerOwnershipService;
use App\Services\Mail\MailConfigurationUnavailableException;
use App\Services\Mail\MailSettingsService;
use App\Services\Orders\OrderNumberService;
use Illuminate\Support\Facades\DB;

class OrdinaryOrderActivationService
{
    public function __construct(
        private readonly OrderNumberService $numbers,
        private readonly ArInvoiceService $invoices,
        private readonly ArPaymentService $payments,
        private readonly AccountingAuditLogService $auditLog,
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly MailSettingsService $mailSettings,
    ) {}

    public function complete(int $attemptId, int $providerTransactionId): PaymentCheckoutAttempt
    {
        return DB::transaction(function () use ($attemptId, $providerTransactionId): PaymentCheckoutAttempt {
            $attempt = PaymentCheckoutAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->state === 'completed') {
                return $attempt->fresh('targets.invoice');
            }
            if ($attempt->state !== 'paid_processing') {
                throw new PaymentCheckoutException('CAPTURE_NOT_ACCEPTED', 409, __('This payment capture is not ready for completion.'));
            }

            $providerTransaction = PaymentProviderTransaction::query()
                ->lockForUpdate()
                ->findOrFail($providerTransactionId);
            $intent = is_array($attempt->financial_intent) ? $attempt->financial_intent : [];
            if ((int) ($intent['provider_transaction_id'] ?? 0) !== $providerTransaction->id
                || $providerTransaction->attempt_id !== $attempt->id
                || $providerTransaction->verified_paid_at === null
                || (int) $providerTransaction->verified_amount_cents !== (int) $attempt->payable_amount_cents
                || $providerTransaction->verified_currency !== 'QAR') {
                throw new PaymentCheckoutException('CAPTURE_MISMATCH', 409, __('The payment capture does not match this checkout.'));
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
            if ($targets->isEmpty() || (int) $targets->sum('expected_amount_cents') !== (int) $attempt->payable_amount_cents) {
                throw new PaymentCheckoutException('TARGET_TOTAL_MISMATCH', 503, __('The checkout targets are inconsistent.'));
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

            $invoices = [];
            foreach ($targets as $target) {
                if ($target->order_id || $target->invoice_id) {
                    throw new PaymentCheckoutException('TARGET_ALREADY_LINKED', 409, __('The checkout target was already activated.'));
                }
                $order = $this->createOrder($attempt, $target, $customer->id, $actorId);
                $invoice = $this->invoices->createFromOrder(
                    $order,
                    $actorId,
                    __('SkipCash checkout :reference', ['reference' => $attempt->reference]),
                    $invoiceDate,
                );
                $invoice = $this->invoices->issue($invoice, $actorId, false);
                $target->update([
                    'order_id' => $order->id,
                    'invoice_id' => $invoice->id,
                    'hold_state' => 'activated',
                    'activated_at' => now('UTC'),
                    'intended_invoice_issue_date' => $invoiceDate,
                ]);
                $invoices[] = $invoice;
            }

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
                'allocations' => collect($invoices)->map(fn ($invoice): array => [
                    'invoice_id' => $invoice->id,
                    'amount_cents' => (int) $invoice->total_cents,
                ])->all(),
            ], $actorId);

            $payment->load('allocations');
            if ((int) $payment->amount_cents !== (int) $attempt->payable_amount_cents
                || (int) $payment->unallocatedCents() !== 0
                || $payment->allocations->count() !== count($invoices)
                || $payment->allocations->sum('amount_cents') !== (int) $attempt->payable_amount_cents
                || collect($invoices)->contains(fn ($invoice): bool => $invoice->fresh()->status !== 'paid' || (int) $invoice->fresh()->balance_cents !== 0)) {
                throw new PaymentCheckoutException('PAYMENT_ALLOCATION_INCOMPLETE', 503, __('The payment allocation could not be completed.'));
            }

            $providerTransaction->update([
                'payment_id' => $payment->id,
                'classification' => 'purchase',
                'receipt_date' => $allocationDate,
            ]);
            $notificationSnapshots = [
                'customer_email' => $attempt->customer_snapshot['email'] ?? null,
                'admin_emails' => $this->adminRecipients((int) $attempt->company_id),
                'order_ids' => $targets->pluck('order_id')->map(fn ($id): int => (int) $id)->all(),
                'amount_cents' => (int) $attempt->payable_amount_cents,
                'reference' => $attempt->reference,
            ];
            $existingNotificationSnapshots = is_array($attempt->notification_snapshots)
                ? $attempt->notification_snapshots
                : [];
            if (is_array($existingNotificationSnapshots['operations_alerts'] ?? null)) {
                $notificationSnapshots['operations_alerts'] = $existingNotificationSnapshots['operations_alerts'];
            }
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
                'order_ids' => $targets->pluck('order_id')->all(),
                'invoice_ids' => $targets->pluck('invoice_id')->all(),
                'receipt_date' => $allocationDate,
            ], $attempt->company_id);
            DB::afterCommit(function () use ($attempt): void {
                SendSkipCashOrderConfirmation::dispatch($attempt->id, 'customer');
                SendSkipCashOrderConfirmation::dispatch($attempt->id, 'admin');
            });

            return $attempt->fresh('targets.invoice');
        }, 3);
    }

    private function createOrder(PaymentCheckoutAttempt $attempt, PaymentCheckoutTarget $target, int $customerId, int $actorId): Order
    {
        $snapshot = $target->item_snapshot;
        $lines = $snapshot['order_lines'] ?? null;
        if (! is_array($lines) || $lines === []) {
            throw new PaymentCheckoutException('TARGET_SNAPSHOT_INVALID', 503, __('The checkout target snapshot is invalid.'));
        }
        $total = (int) $target->expected_amount_cents;
        $order = Order::query()->create([
            'order_number' => $this->numbers->generate(),
            'branch_id' => $attempt->branch_id,
            'source' => 'Website',
            'is_daily_dish' => true,
            'daily_dish_portion_type' => null,
            'daily_dish_portion_quantity' => null,
            'type' => 'Delivery',
            'status' => 'Draft',
            'customer_id' => $customerId,
            'user_id' => $attempt->portal_user_id,
            'customer_name_snapshot' => $attempt->customer_snapshot['full_name'] ?? null,
            'customer_phone_snapshot' => $attempt->customer_snapshot['phone'] ?? null,
            'customer_email_snapshot' => $attempt->customer_snapshot['email'] ?? null,
            'delivery_address_snapshot' => $attempt->customer_snapshot['address'] ?? null,
            'scheduled_date' => $target->service_date,
            'scheduled_time' => null,
            'notes' => $snapshot['notes'] ?? null,
            'order_discount_amount' => '0.000',
            'total_before_tax' => $this->decimalCents($total),
            'tax_amount' => '0.000',
            'total_amount' => $this->decimalCents($total),
            'created_by' => $actorId,
        ]);
        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line) || (int) ($line['quantity'] ?? 0) <= 0) {
                throw new PaymentCheckoutException('TARGET_SNAPSHOT_INVALID', 503, __('The checkout target line is invalid.'));
            }
            OrderItem::query()->create([
                'order_id' => $order->id,
                'menu_item_id' => (int) $line['menu_item_id'],
                'description_snapshot' => (string) $line['description'],
                'quantity' => (string) (int) $line['quantity'],
                'unit_price' => $this->decimalCents((int) $line['unit_price_cents']),
                'discount_amount' => '0.000',
                'line_total' => $this->decimalCents((int) $line['line_total_cents']),
                'status' => 'Pending',
                'sort_order' => $index,
                'role' => (string) $line['role'],
            ]);
        }

        return $order;
    }

    private function decimalCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT).'0';
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
