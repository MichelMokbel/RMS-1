<?php

namespace App\Services\Payments;

use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentProviderTransaction;
use App\Services\Customers\CustomerOwnershipService;
use Throwable;

class PaymentOperationsConsistencyService
{
    private const REASON_CODES = [
        'CONSISTENCY_VERIFIED_PAYMENT_MISSING',
        'CONSISTENCY_PROVIDER_SOURCE_MISMATCH',
        'CONSISTENCY_PROVIDER_AMOUNT_MISMATCH',
        'CONSISTENCY_PROVIDER_CURRENCY_MISMATCH',
        'CONSISTENCY_RECEIPT_MISSING',
        'CONSISTENCY_RECEIPT_SOURCE_MISMATCH',
        'CONSISTENCY_RECEIPT_AMOUNT_MISMATCH',
        'CONSISTENCY_RECEIPT_CURRENCY_MISMATCH',
        'CONSISTENCY_RECEIPT_SCOPE_MISMATCH',
        'CONSISTENCY_RECEIPT_CUSTOMER_MISMATCH',
        'CONSISTENCY_TARGET_TOTAL_MISMATCH',
        'CONSISTENCY_TARGET_LINK_MISSING',
        'CONSISTENCY_TARGET_SCOPE_MISMATCH',
        'CONSISTENCY_TARGET_CUSTOMER_MISMATCH',
        'CONSISTENCY_TARGET_AMOUNT_MISMATCH',
        'CONSISTENCY_TARGET_ALLOCATION_MISMATCH',
        'CONSISTENCY_ALLOCATION_EXCESS',
    ];

    public function __construct(
        private readonly CustomerOwnershipService $customerOwnership,
        private readonly PaymentOperationsTrackingService $tracking,
    ) {}

    public function scan(int $limit = 100): int
    {
        $ids = PaymentCheckoutAttempt::query()
            ->whereIn('purpose', ['ordinary_order', 'menu_order'])
            ->whereIn('state', ['paid_processing', 'completed'])
            ->orderBy('id')
            ->limit(max(1, min(100, $limit)))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->check((int) $id);
        }

        return $ids->count();
    }

    public function check(int $attemptId): ?string
    {
        $attempt = PaymentCheckoutAttempt::query()
            ->with([
                'customer',
                'targets.order',
                'targets.invoice.paymentAllocations',
                'providerTransactions' => fn ($query) => $query
                    ->whereNotNull('verified_paid_at')
                    ->with('payment.allocations')
                    ->orderBy('id'),
            ])
            ->find($attemptId);

        if (! $attempt || ! in_array($attempt->purpose, ['ordinary_order', 'menu_order'], true)
            || ! in_array($attempt->state, ['paid_processing', 'completed'], true)) {
            return null;
        }

        $reasonCode = $this->findMismatch($attempt);
        if ($reasonCode) {
            $this->tracking->recordConsistencyIssue($attempt->id, $reasonCode);
        } else {
            $trackedReason = (string) data_get($attempt->operations_tracking, 'issues.processing.reason_code', '');
            if (in_array($trackedReason, self::REASON_CODES, true)) {
                $this->tracking->resolveConsistencyIssue($attempt->id, $trackedReason);
            }
        }

        return $reasonCode;
    }

    private function findMismatch(PaymentCheckoutAttempt $attempt): ?string
    {
        /** @var PaymentProviderTransaction|null $provider */
        $provider = $attempt->providerTransactions->first();
        if (! $provider) {
            return 'CONSISTENCY_VERIFIED_PAYMENT_MISSING';
        }
        if ((int) $provider->payment_source_id !== (int) $attempt->payment_source_id) {
            return 'CONSISTENCY_PROVIDER_SOURCE_MISMATCH';
        }
        if ((int) $provider->verified_amount_cents !== (int) $attempt->payable_amount_cents) {
            return 'CONSISTENCY_PROVIDER_AMOUNT_MISMATCH';
        }
        if ((string) $provider->verified_currency !== (string) $attempt->currency) {
            return 'CONSISTENCY_PROVIDER_CURRENCY_MISMATCH';
        }

        if ($attempt->state !== 'completed') {
            return null;
        }

        $payment = $provider->payment;
        if (! $payment) {
            return 'CONSISTENCY_RECEIPT_MISSING';
        }
        if ($payment->source !== 'ar' || $payment->method !== 'skipcash'
            || (int) $payment->payment_source_id !== (int) $attempt->payment_source_id) {
            return 'CONSISTENCY_RECEIPT_SOURCE_MISMATCH';
        }
        if ((int) $payment->amount_cents !== (int) $provider->verified_amount_cents) {
            return 'CONSISTENCY_RECEIPT_AMOUNT_MISMATCH';
        }
        if ((string) $payment->currency !== (string) $provider->verified_currency) {
            return 'CONSISTENCY_RECEIPT_CURRENCY_MISMATCH';
        }
        if ((int) $payment->company_id !== (int) $attempt->company_id
            || (int) $payment->branch_id !== (int) $attempt->branch_id) {
            return 'CONSISTENCY_RECEIPT_SCOPE_MISMATCH';
        }
        if (! $this->sameCanonicalCustomer((int) $attempt->customer_id, (int) $payment->customer_id)) {
            return 'CONSISTENCY_RECEIPT_CUSTOMER_MISMATCH';
        }

        if ($attempt->targets->isEmpty()
            || (int) $attempt->targets->sum('expected_amount_cents') !== (int) $attempt->payable_amount_cents) {
            return 'CONSISTENCY_TARGET_TOTAL_MISMATCH';
        }

        foreach ($attempt->targets as $target) {
            if ($target->target_type !== 'order' || ! $target->order_id || ! $target->invoice_id
                || ! $target->order || ! $target->invoice) {
                return 'CONSISTENCY_TARGET_LINK_MISSING';
            }

            $invoice = $target->invoice;
            if ((int) $target->order->branch_id !== (int) $attempt->branch_id
                || (int) $invoice->branch_id !== (int) $attempt->branch_id
                || (int) $invoice->company_id !== (int) $attempt->company_id
                || (int) $invoice->source_order_id !== (int) $target->order_id) {
                return 'CONSISTENCY_TARGET_SCOPE_MISMATCH';
            }
            if (! $this->sameCanonicalCustomer((int) $attempt->customer_id, (int) $target->order->customer_id)
                || ! $this->sameCanonicalCustomer((int) $attempt->customer_id, (int) $invoice->customer_id)) {
                return 'CONSISTENCY_TARGET_CUSTOMER_MISMATCH';
            }
            if ((string) $invoice->currency !== (string) $attempt->currency
                || (int) $invoice->total_cents !== (int) $target->expected_amount_cents) {
                return 'CONSISTENCY_TARGET_AMOUNT_MISMATCH';
            }

            if (! $invoice->voided_at && ! in_array($invoice->status, ['void', 'voided'], true)) {
                $funded = (int) $invoice->paymentAllocations
                    ->where('payment_id', $payment->id)
                    ->sum('amount_cents');
                if ($funded !== (int) $target->expected_amount_cents) {
                    return 'CONSISTENCY_TARGET_ALLOCATION_MISMATCH';
                }
            }
        }

        $allocated = (int) $payment->allocations->sum('amount_cents');
        if ($allocated < 0 || $allocated > (int) $payment->amount_cents) {
            return 'CONSISTENCY_ALLOCATION_EXCESS';
        }

        return null;
    }

    private function sameCanonicalCustomer(int $leftCustomerId, int $rightCustomerId): bool
    {
        if ($leftCustomerId <= 0 || $rightCustomerId <= 0) {
            return false;
        }

        try {
            return $this->customerOwnership->canonicalCustomerId($leftCustomerId)
                === $this->customerOwnership->canonicalCustomerId($rightCustomerId);
        } catch (Throwable) {
            return false;
        }
    }
}
