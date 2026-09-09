<?php

namespace App\Services\Payments\Consistency;

use App\Models\ArInvoice;
use App\Models\PaymentCheckoutAttempt;
use App\Models\PaymentCheckoutTarget;
use App\Services\Customers\CustomerOwnershipService;
use Illuminate\Support\Facades\DB;
use Throwable;

class CheckoutAccountingConsistencyRule implements PaymentConsistencyRule
{
    public const PROVIDER = 'provider_checkout_v1';

    public const ORDINARY = 'ordinary_accounting_v1';

    public function __construct(
        private readonly CustomerOwnershipService $customerOwnership,
    ) {}

    public function ruleCodes(): array
    {
        return [self::PROVIDER, self::ORDINARY];
    }

    public function scope(string $ruleCode, string $subjectType, int $subjectId): array
    {
        $attempt = $this->attempt($ruleCode, $subjectType, $subjectId);

        return [
            'company_id' => (int) $attempt->company_id,
            'branch_id' => (int) $attempt->branch_id,
            'checkout_id' => (int) $attempt->id,
        ];
    }

    public function evaluate(string $ruleCode, string $subjectType, int $subjectId): array
    {
        return match ($ruleCode) {
            self::PROVIDER => $this->evaluateProvider($this->attempt($ruleCode, $subjectType, $subjectId)),
            self::ORDINARY => $this->evaluateOrdinary(
                PaymentCheckoutTarget::query()->findOrFail($subjectId),
                $this->attempt($ruleCode, $subjectType, $subjectId),
            ),
            default => throw new \InvalidArgumentException('Unsupported checkout consistency rule.'),
        };
    }

    private function attempt(string $ruleCode, string $subjectType, int $subjectId): PaymentCheckoutAttempt
    {
        if ($ruleCode === self::PROVIDER && $subjectType === 'payment_checkout_attempt') {
            return PaymentCheckoutAttempt::query()->findOrFail($subjectId);
        }
        if ($ruleCode === self::ORDINARY && $subjectType === 'payment_checkout_target') {
            $attemptId = PaymentCheckoutTarget::query()->whereKey($subjectId)->value('attempt_id');

            return PaymentCheckoutAttempt::query()->findOrFail($attemptId);
        }

        throw new \InvalidArgumentException('Unsupported checkout consistency subject.');
    }

    private function evaluateProvider(PaymentCheckoutAttempt $attempt): array
    {
        $targets = DB::table('payment_checkout_targets')->where('attempt_id', $attempt->id)->orderBy('id')->get([
            'id', 'sequence', 'target_type', 'service_date', 'expected_amount_cents', 'hold_state',
            'intended_invoice_issue_date', 'activated_at', 'released_at', 'order_id', 'meal_plan_request_id',
            'invoice_id', 'created_at', 'updated_at',
        ]);
        $transactions = DB::table('payment_provider_transactions')->where('attempt_id', $attempt->id)->orderBy('id')->get([
            'id', 'attempt_id', 'payment_source_id', 'provider_payment_id', 'merchant_transaction_id',
            'amount_cents', 'currency', 'raw_status', 'normalized_status', 'finished_at',
            'finished_at_evidence_source', 'verified_paid_at', 'verified_amount_cents', 'verified_currency',
            'verified_finished_at', 'details_checked_at', 'classification', 'receipt_date', 'payment_id',
            'created_at', 'updated_at',
        ]);
        $events = DB::table('payment_provider_events')
            ->where('payment_source_id', $attempt->payment_source_id)
            ->whereIn('provider_payment_id', $transactions->pluck('provider_payment_id')->filter())
            ->orderBy('id')
            ->get([
                'id', 'payment_source_id', 'provider_transaction_id', 'provider_payment_id', 'payload_hash',
                'merchant_transaction_id', 'amount_cents', 'raw_status', 'normalized_status',
                'signature_key_reference', 'processing_state', 'received_at', 'processed_at', 'created_at', 'updated_at',
            ]);
        $payments = DB::table('payments')->whereIn('id', $transactions->pluck('payment_id')->filter())->orderBy('id')->get([
            'id', 'company_id', 'branch_id', 'customer_id', 'payment_source_id', 'source', 'method',
            'amount_cents', 'currency', 'received_at', 'voided_at', 'created_at', 'updated_at',
        ])->keyBy('id');
        $issues = [];
        $deferred = in_array((string) $attempt->state, ['initiating', 'pending', 'paid_processing'], true);
        $terminalUnpaid = in_array((string) $attempt->state, ['declined', 'expired'], true);
        $paid = in_array((string) $attempt->state, ['completed', 'payment_received_as_credit'], true);
        $verified = $transactions->whereNotNull('verified_paid_at')->values();

        if ((int) $attempt->gross_amount_cents <= 0
            || (int) $attempt->discount_amount_cents < 0
            || (int) $attempt->payable_amount_cents <= 0
            || (int) $attempt->gross_amount_cents !== (int) $attempt->discount_amount_cents + (int) $attempt->payable_amount_cents
            || (string) $attempt->currency !== 'QAR') {
            $issues[] = ConsistencyEvidence::issue('CHECKOUT_MONEY_INVALID', 'payment_checkout_attempt', (int) $attempt->id);
        }
        if ($targets->isEmpty() || (int) $targets->sum('expected_amount_cents') !== (int) $attempt->payable_amount_cents) {
            $issues[] = ConsistencyEvidence::issue('CHECKOUT_TARGET_TOTAL_MISMATCH', 'payment_checkout_attempt', (int) $attempt->id);
        }
        if ($paid && $verified->count() !== 1) {
            $issues[] = ConsistencyEvidence::issue('CHECKOUT_VERIFIED_PAYMENT_COUNT_MISMATCH', 'payment_checkout_attempt', (int) $attempt->id);
        }
        if ($terminalUnpaid && $verified->isNotEmpty()) {
            $issues[] = ConsistencyEvidence::issue('CHECKOUT_UNPAID_STATE_HAS_VERIFIED_CAPTURE', 'payment_checkout_attempt', (int) $attempt->id);
        }

        foreach ($verified as $transaction) {
            $payment = $payments->get((int) $transaction->payment_id);
            if ((int) $transaction->payment_source_id !== (int) $attempt->payment_source_id
                || (int) $transaction->amount_cents !== (int) $attempt->payable_amount_cents
                || (int) $transaction->verified_amount_cents !== (int) $attempt->payable_amount_cents
                || (string) $transaction->currency !== (string) $attempt->currency
                || (string) $transaction->verified_currency !== (string) $attempt->currency
                || ! $transaction->verified_finished_at) {
                $issues[] = ConsistencyEvidence::issue('CHECKOUT_PROVIDER_EVIDENCE_MISMATCH', 'payment_provider_transaction', (int) $transaction->id);
            }
            if (! $payment
                || (int) $payment->company_id !== (int) $attempt->company_id
                || (int) $payment->branch_id !== (int) $attempt->branch_id
                || (int) $payment->payment_source_id !== (int) $attempt->payment_source_id
                || (string) $payment->source !== 'ar'
                || (string) $payment->method !== 'skipcash'
                || (int) $payment->amount_cents !== (int) $attempt->payable_amount_cents
                || (string) $payment->currency !== (string) $attempt->currency
                || $payment->voided_at !== null
                || ! $this->sameCustomer((int) $attempt->customer_id, (int) ($payment->customer_id ?? 0))) {
                $issues[] = ConsistencyEvidence::issue('CHECKOUT_RECEIPT_MISMATCH', 'payment_provider_transaction', (int) $transaction->id);
            }
        }

        if ((string) $attempt->state === 'completed') {
            foreach ($targets as $target) {
                $resultId = $target->target_type === 'order' ? $target->order_id : $target->meal_plan_request_id;
                if ($target->hold_state !== 'activated' || ! $target->activated_at || ! $resultId) {
                    $issues[] = ConsistencyEvidence::issue('CHECKOUT_COMPLETED_TARGET_NOT_ACTIVATED', 'payment_checkout_target', (int) $target->id);
                }
            }
        }
        if ((string) $attempt->state === 'payment_received_as_credit') {
            foreach ($targets as $target) {
                if ($target->hold_state !== 'released' || ! $target->released_at || $target->order_id || $target->invoice_id) {
                    $issues[] = ConsistencyEvidence::issue('CHECKOUT_CREDIT_TARGET_NOT_RELEASED', 'payment_checkout_target', (int) $target->id);
                }
            }
        }
        if ($terminalUnpaid) {
            foreach ($targets as $target) {
                if ($target->hold_state !== 'released' || ! $target->released_at || $target->order_id || $target->invoice_id) {
                    $issues[] = ConsistencyEvidence::issue('CHECKOUT_UNPAID_TARGET_NOT_RELEASED', 'payment_checkout_target', (int) $target->id);
                }
            }
        }

        return ConsistencyEvidence::result(
            (int) $attempt->company_id,
            (int) $attempt->branch_id,
            (int) $attempt->id,
            [
                'money_matches_quote' => true,
                'verified_paid_capture_count' => $paid ? 1 : 0,
                'completed_targets_activated' => true,
                'terminal_unpaid_targets_released' => true,
            ],
            [
                'attempt_state' => (string) $attempt->state,
                'target_count' => $targets->count(),
                'target_total_cents' => (int) $targets->sum('expected_amount_cents'),
                'provider_transaction_count' => $transactions->count(),
                'verified_paid_count' => $verified->count(),
                'provider_event_count' => $events->count(),
                'payment_count' => $payments->count(),
                'deferred_reason' => $deferred ? 'CHECKOUT_NOT_TERMINAL' : null,
            ],
            [
                'attempt' => $attempt->only([
                    'id', 'company_id', 'branch_id', 'customer_id', 'portal_user_id', 'payment_source_id',
                    'purpose', 'currency', 'gross_amount_cents', 'discount_amount_cents', 'payable_amount_cents',
                    'cart_fingerprint', 'quote_fingerprint', 'request_fingerprint', 'state', 'expires_at',
                    'completed_at', 'provider_create_outcome', 'provider_dispatched_at', 'created_at', 'updated_at',
                ]),
                'targets' => $targets->map(fn ($row): array => (array) $row)->all(),
                'transactions' => $transactions->map(fn ($row): array => (array) $row)->all(),
                'events' => $events->map(fn ($row): array => (array) $row)->all(),
                'payments' => $payments->values()->map(fn ($row): array => (array) $row)->all(),
            ],
            $issues,
            $deferred,
        );
    }

    private function evaluateOrdinary(PaymentCheckoutTarget $target, PaymentCheckoutAttempt $attempt): array
    {
        $order = $target->order_id ? DB::table('orders')->where('id', $target->order_id)->first([
            'id', 'branch_id', 'customer_id', 'source', 'type', 'status', 'invoiced_at', 'scheduled_date',
            'order_discount_amount', 'total_before_tax', 'tax_amount', 'total_amount', 'created_at', 'updated_at',
        ]) : null;
        $invoice = $target->invoice_id ? DB::table('ar_invoices')->where('id', $target->invoice_id)->first([
            'id', 'branch_id', 'company_id', 'customer_id', 'source_order_id', 'type', 'status', 'issue_date',
            'currency', 'subtotal_cents', 'discount_total_cents', 'tax_total_cents', 'total_cents',
            'paid_total_cents', 'balance_cents', 'voided_at', 'created_at', 'updated_at',
        ]) : null;
        $items = $invoice ? DB::table('ar_invoice_items')->where('invoice_id', $invoice->id)->orderBy('id')->get([
            'id', 'invoice_id', 'qty', 'unit_price_cents', 'discount_cents', 'tax_cents', 'line_total_cents',
            'sellable_type', 'sellable_id', 'created_at', 'updated_at',
        ]) : collect();
        $provider = DB::table('payment_provider_transactions')->where('attempt_id', $attempt->id)
            ->whereNotNull('verified_paid_at')->orderBy('id')->first([
                'id', 'attempt_id', 'payment_source_id', 'verified_amount_cents', 'verified_currency',
                'verified_paid_at', 'payment_id', 'created_at', 'updated_at',
            ]);
        $payment = $provider?->payment_id ? DB::table('payments')->where('id', $provider->payment_id)->first([
            'id', 'company_id', 'branch_id', 'customer_id', 'payment_source_id', 'source', 'method',
            'amount_cents', 'currency', 'received_at', 'voided_at', 'created_at', 'updated_at',
        ]) : null;
        $allocations = $invoice ? DB::table('payment_allocations')
            ->where('allocatable_type', ArInvoice::class)->where('allocatable_id', $invoice->id)->orderBy('id')->get([
                'id', 'payment_id', 'allocatable_type', 'allocatable_id', 'amount_cents', 'voided_at',
                'voided_by', 'created_at', 'updated_at',
            ]) : collect();
        $subledger = collect();
        $subledgerLines = collect();
        if ($invoice || $payment) {
            $subledger = DB::table('subledger_entries')
                ->where(function ($query) use ($invoice, $payment): void {
                    if ($invoice) {
                        $query->orWhere(fn ($inner) => $inner->where('source_type', 'ar_invoice')->where('source_id', $invoice->id));
                    }
                    if ($payment) {
                        $query->orWhere(fn ($inner) => $inner->where('source_type', 'ar_payment')->where('source_id', $payment->id));
                    }
                })->orderBy('id')->get([
                    'id', 'company_id', 'source_type', 'source_id', 'event', 'entry_date', 'status',
                    'posted_at', 'voided_at', 'created_at', 'updated_at',
                ]);
            $subledgerLines = DB::table('subledger_lines')->whereIn('entry_id', $subledger->pluck('id'))
                ->orderBy('id')->get(['id', 'entry_id', 'account_id', 'debit', 'credit', 'created_at', 'updated_at']);
        }
        $issues = [];
        $deferred = (string) $attempt->state === 'paid_processing';

        if ($target->target_type !== 'order' || ! in_array($attempt->purpose, ['ordinary_order', 'menu_order'], true)) {
            $issues[] = ConsistencyEvidence::issue('ORDINARY_TARGET_TYPE_MISMATCH', 'payment_checkout_target', (int) $target->id);
        }
        if ((string) $attempt->state === 'completed') {
            if (! $order || ! $invoice || ! $payment) {
                $issues[] = ConsistencyEvidence::issue('ORDINARY_ACCOUNTING_PARENT_MISSING', 'payment_checkout_target', (int) $target->id);
            } else {
                $invoiceVoided = $invoice->voided_at !== null || in_array((string) $invoice->status, ['void', 'voided'], true);
                if ((int) $order->branch_id !== (int) $attempt->branch_id
                    || (int) $invoice->branch_id !== (int) $attempt->branch_id
                    || (int) $invoice->company_id !== (int) $attempt->company_id
                    || (int) $invoice->source_order_id !== (int) $order->id
                    || ! $this->sameCustomer((int) $attempt->customer_id, (int) $order->customer_id)
                    || ! $this->sameCustomer((int) $attempt->customer_id, (int) $invoice->customer_id)) {
                    $issues[] = ConsistencyEvidence::issue('ORDINARY_ACCOUNTING_SCOPE_MISMATCH', 'payment_checkout_target', (int) $target->id);
                }
                if ((string) $invoice->currency !== (string) $attempt->currency
                    || (int) $invoice->total_cents !== (int) $target->expected_amount_cents) {
                    $issues[] = ConsistencyEvidence::issue('ORDINARY_INVOICE_AMOUNT_MISMATCH', 'ar_invoice', (int) $invoice->id);
                }
                $activeAllocated = (int) $allocations->whereNull('voided_at')->where('payment_id', $payment->id)->sum('amount_cents');
                if (! $invoiceVoided && $activeAllocated !== (int) $target->expected_amount_cents) {
                    $issues[] = ConsistencyEvidence::issue('ORDINARY_ALLOCATION_MISMATCH', 'ar_invoice', (int) $invoice->id);
                }
                if ($invoiceVoided && $activeAllocated !== 0) {
                    $issues[] = ConsistencyEvidence::issue('ORDINARY_VOID_NOT_PROPAGATED', 'ar_invoice', (int) $invoice->id);
                }

                $requiredEntries = [
                    ['source_type' => 'ar_invoice', 'source_id' => (int) $invoice->id, 'event' => 'issue', 'amount_cents' => (int) $target->expected_amount_cents],
                    ['source_type' => 'ar_payment', 'source_id' => (int) $payment->id, 'event' => 'payment', 'amount_cents' => (int) $attempt->payable_amount_cents],
                ];
                if ($invoiceVoided) {
                    $requiredEntries[] = [
                        'source_type' => 'ar_invoice',
                        'source_id' => (int) $invoice->id,
                        'event' => 'void',
                        'amount_cents' => (int) $target->expected_amount_cents,
                    ];
                }
                foreach ($requiredEntries as $requiredEntry) {
                    $entry = $subledger->first(fn ($candidate): bool => (string) $candidate->source_type === $requiredEntry['source_type']
                        && (int) $candidate->source_id === $requiredEntry['source_id']
                        && (string) $candidate->event === $requiredEntry['event']
                        && (string) $candidate->status === 'posted');
                    if (! $entry) {
                        $issues[] = ConsistencyEvidence::issue('ORDINARY_SUBLEDGER_MISSING', 'payment_checkout_target', (int) $target->id);

                        continue;
                    }
                    if (! $this->entryMatchesAmount($entry, $subledgerLines, $requiredEntry['amount_cents'])) {
                        $issues[] = ConsistencyEvidence::issue('ORDINARY_SUBLEDGER_MISMATCH', 'payment_checkout_target', (int) $target->id);
                    }
                }
            }
        }

        return ConsistencyEvidence::result(
            (int) $attempt->company_id,
            (int) $attempt->branch_id,
            (int) $attempt->id,
            [
                'completed_target_has_order_invoice_and_receipt' => true,
                'active_invoice_is_fully_allocated' => true,
                'voided_invoice_has_no_active_allocation' => true,
                'required_subledger_entries_exist_balance_and_match_amounts' => true,
            ],
            [
                'attempt_state' => (string) $attempt->state,
                'target_hold_state' => (string) $target->hold_state,
                'order_id' => $order?->id,
                'invoice_id' => $invoice?->id,
                'payment_id' => $payment?->id,
                'active_allocation_cents' => (int) $allocations->whereNull('voided_at')->sum('amount_cents'),
                'deferred_reason' => $deferred ? 'CHECKOUT_PAYMENT_PROCESSING' : null,
            ],
            [
                'attempt' => $attempt->only(['id', 'company_id', 'branch_id', 'customer_id', 'purpose', 'currency', 'state', 'updated_at']),
                'target' => $target->only(['id', 'attempt_id', 'sequence', 'target_type', 'service_date', 'expected_amount_cents', 'hold_state', 'activated_at', 'released_at', 'intended_invoice_issue_date', 'order_id', 'invoice_id', 'created_at', 'updated_at']),
                'order' => $order ? (array) $order : ['missing' => true],
                'invoice' => $invoice ? (array) $invoice : ['missing' => true],
                'invoice_items' => $items->map(fn ($row): array => (array) $row)->all(),
                'payment' => $payment ? (array) $payment : ['missing' => true],
                'allocations' => $allocations->map(fn ($row): array => (array) $row)->all(),
                'subledger' => $subledger->map(fn ($row): array => (array) $row)->all(),
                'subledger_lines' => $subledgerLines->map(fn ($row): array => (array) $row)->all(),
            ],
            $issues,
            $deferred,
        );
    }

    private function sameCustomer(int $left, int $right): bool
    {
        if ($left <= 0 || $right <= 0) {
            return false;
        }

        try {
            return $this->customerOwnership->canonicalCustomerId($left)
                === $this->customerOwnership->canonicalCustomerId($right);
        } catch (Throwable) {
            return false;
        }
    }

    private function decimalCents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private function entryMatchesAmount(object $entry, $lines, int $expectedCents): bool
    {
        $entryLines = $lines->where('entry_id', $entry->id);
        if ($entryLines->isEmpty()) {
            return false;
        }

        $debitCents = $this->decimalCents($entryLines->sum('debit'));
        $creditCents = $this->decimalCents($entryLines->sum('credit'));

        return $debitCents === $creditCents && $debitCents === abs($expectedCents);
    }
}
