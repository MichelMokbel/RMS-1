<?php

namespace App\Services\Ledger;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\ArInvoice;
use App\Models\Branch;
use App\Models\FinanceSetting;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransfer;
use App\Models\JournalEntry;
use App\Models\PurchaseOrderInvoiceMatch;
use App\Models\PettyCashIssue;
use App\Models\PettyCashReconciliation;
use App\Models\Supplier;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\LedgerAccountMappingService;
use App\Services\Accounting\AccountingPeriodGateService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SubledgerService
{
    protected array $accountCache = [];

    public function __construct(
        protected AccountingContextService $accountingContext,
        protected LedgerAccountMappingService $mappingService,
        protected AccountingPeriodGateService $periodGate
    )
    {
    }

    public function recordInventoryTransaction(InventoryTransaction $transaction, ?int $userId = null): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $amount = (float) ($transaction->total_cost ?? 0);
        if (abs($amount) < 0.0001) {
            return null;
        }

        $offsetKey = $this->inventoryOffsetKey($transaction->transaction_type, $transaction->reference_type);
        if ($offsetKey === null) {
            return null;
        }

        $inventoryAccount = 'inventory_asset';
        $offsetAccount = $offsetKey;

        $lines = $this->buildDualLines($amount, $inventoryAccount, $offsetAccount);
        $date = optional($transaction->transaction_date)->toDateString() ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'inventory_transaction',
            sourceId: $transaction->id,
            event: 'post',
            entryDate: $date,
            description: $this->buildInventoryDescription($transaction),
            lines: $lines,
            userId: $userId,
            branchId: $transaction->branch_id
        );
    }

    public function recordInventoryTransfer(InventoryTransfer $transfer, int $userId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $transfer->loadMissing('lines');

        $amount = (float) $transfer->lines->sum('total_cost');
        if (abs($amount) < 0.0001) {
            return null;
        }

        $inventoryAccount = 'inventory_asset';
        $lines = [
            [
                'account' => $inventoryAccount,
                'debit' => abs($amount),
                'credit' => 0,
                'memo' => 'Transfer in (branch '.$transfer->to_branch_id.')',
            ],
            [
                'account' => $inventoryAccount,
                'debit' => 0,
                'credit' => abs($amount),
                'memo' => 'Transfer out (branch '.$transfer->from_branch_id.')',
            ],
        ];

        $date = optional($transfer->transfer_date)->toDateString() ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'inventory_transfer',
            sourceId: $transfer->id,
            event: 'post',
            entryDate: $date,
            description: 'Inventory transfer '.$transfer->id.' ('.$transfer->from_branch_id.' -> '.$transfer->to_branch_id.')',
            lines: $lines,
            userId: $userId,
            branchId: $transfer->to_branch_id
        );
    }

    public function recordApInvoice(ApInvoice $invoice, int $userId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $invoice->loadMissing('items');

        $subtotal = (float) ($invoice->subtotal ?? 0);
        $tax = (float) ($invoice->tax_amount ?? 0);
        $total = (float) ($invoice->total_amount ?? 0);
        if ($total <= 0) {
            return null;
        }

        $debitKey = $this->apInvoiceDebitKey($invoice);
        $expenseAccountId = $this->apInvoiceExpenseAccountId($invoice);
        $apAccount = 'ap_invoice_ap';
        $companyId = $this->accountingContext->resolveCompanyId((int) ($invoice->branch_id ?? 0), (int) ($invoice->company_id ?? 0));

        $requiredMappings = ['ap_control'];
        if ($subtotal > 0 && ! $expenseAccountId) {
            $requiredMappings[] = $debitKey;
        }
        if ($tax > 0) {
            $requiredMappings[] = 'tax_input';
        }
        $this->mappingService->assertRequiredMappings($companyId, $requiredMappings);

        $lines = [];
        if ($subtotal > 0 && $invoice->document_type === 'landed_cost_adjustment') {
            $lines[] = [
                'account' => 'inventory_asset',
                'debit' => $subtotal,
                'credit' => 0,
                'memo' => 'Landed cost capitalization',
            ];
        } elseif ($subtotal > 0 && $invoice->purchase_order_id) {
            $matchingSummary = $this->purchaseOrderMatchingSummary($invoice);
            $grniAmount = min(round((float) ($matchingSummary['matched_amount'] ?? 0), 2), $subtotal);
            $varianceAmount = round($subtotal - $grniAmount, 2);

            if ($grniAmount > 0) {
                $lines[] = [
                    'account' => 'ap_invoice_inventory',
                    'debit' => $grniAmount,
                    'credit' => 0,
                    'memo' => 'Clear GRNI for matched receipts',
                ];
            }

            if (abs($varianceAmount) > 0.0001) {
                $varianceLine = [
                    'debit' => $varianceAmount > 0 ? abs($varianceAmount) : 0,
                    'credit' => $varianceAmount < 0 ? abs($varianceAmount) : 0,
                    'memo' => 'Purchase price variance',
                ];

                $varianceAccountId = FinanceSetting::query()->find(1)?->purchase_price_variance_account_id;
                if ($varianceAccountId) {
                    $varianceLine['account_id'] = (int) $varianceAccountId;
                } else {
                    $varianceLine['account'] = 'inventory_adjustment';
                }

                $lines[] = $varianceLine;
            }
        } elseif ($subtotal > 0) {
            $lines[] = [
                'account' => $expenseAccountId ?: $debitKey,
                'debit' => $subtotal,
                'credit' => 0,
                'memo' => 'Invoice subtotal',
            ];
        }
        if ($tax > 0) {
            $lines[] = [
                'account' => 'ap_invoice_tax',
                'debit' => $tax,
                'credit' => 0,
                'memo' => 'Invoice tax',
            ];
        }

        $lines[] = [
            'account' => $apAccount,
            'debit' => 0,
            'credit' => $total,
            'memo' => 'Accounts payable',
        ];

        $date = optional($invoice->posted_at)->toDateString()
            ?? optional($invoice->invoice_date)->toDateString()
            ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'ap_invoice',
            sourceId: $invoice->id,
            event: 'post',
            entryDate: $date,
            description: 'AP Invoice '.$invoice->invoice_number,
            lines: $lines,
            userId: $userId,
            branchId: $invoice->branch_id,
            companyId: $companyId,
            departmentId: $invoice->department_id,
            jobId: $invoice->job_id
        );
    }

    public function recordApInvoiceVoid(ApInvoice $invoice, int $userId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        if (! in_array($invoice->status, ['void', 'posted', 'partially_paid', 'paid'], true)) {
            return null;
        }

        $subtotal = (float) ($invoice->subtotal ?? 0);
        $tax = (float) ($invoice->tax_amount ?? 0);
        $total = (float) ($invoice->total_amount ?? 0);
        if ($total <= 0) {
            return null;
        }

        $debitKey = $this->apInvoiceDebitKey($invoice);
        $expenseAccountId = $this->apInvoiceExpenseAccountId($invoice);
        $apAccount = 'ap_invoice_ap';

        $lines = [];
        if ($subtotal > 0) {
            $lines[] = [
                'account' => $expenseAccountId ?: $debitKey,
                'debit' => 0,
                'credit' => $subtotal,
                'memo' => 'Invoice reversal',
            ];
        }
        if ($tax > 0) {
            $lines[] = [
                'account' => 'ap_invoice_tax',
                'debit' => 0,
                'credit' => $tax,
                'memo' => 'Tax reversal',
            ];
        }

        $lines[] = [
            'account' => $apAccount,
            'debit' => $total,
            'credit' => 0,
            'memo' => 'AP reversal',
        ];

        $date = optional($invoice->voided_at)->toDateString() ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'ap_invoice',
            sourceId: $invoice->id,
            event: 'void',
            entryDate: $date,
            description: 'AP Invoice void '.$invoice->invoice_number,
            lines: $lines,
            userId: $userId,
            branchId: $invoice->branch_id,
            companyId: $invoice->company_id,
            departmentId: $invoice->department_id,
            jobId: $invoice->job_id
        );
    }

    public function recordApPayment(ApPayment $payment, int $userId, ?float $appliedAmount = null): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $amount = (float) ($payment->amount ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $payment->loadMissing('allocations');
        $applied = $appliedAmount ?? (float) $payment->allocations()->sum('allocated_amount');
        $applied = max(min($applied, $amount), 0);
        $unapplied = max($amount - $applied, 0);
        $companyId = $this->accountingContext->resolveCompanyId((int) ($payment->branch_id ?? 0), (int) ($payment->company_id ?? 0));

        $lines = [
            [
                'account_id' => $this->paymentSettlementAccount($payment->payment_method, $companyId, (int) ($payment->bank_account_id ?? 0), 'ap'),
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Cash payment',
            ],
        ];

        if ($applied > 0) {
            $lines[] = [
                'account' => 'ap_invoice_ap',
                'debit' => $applied,
                'credit' => 0,
                'memo' => 'AP payment applied',
            ];
        }

        if ($unapplied > 0) {
            $lines[] = [
                'account' => 'ap_payment_prepay',
                'debit' => $unapplied,
                'credit' => 0,
                'memo' => 'Supplier advance',
            ];
        }

        $date = optional($payment->payment_date)->toDateString() ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'ap_payment',
            sourceId: $payment->id,
            event: 'payment',
            entryDate: $date,
            description: 'AP Payment '.$payment->reference,
            lines: $lines,
            userId: $userId,
            branchId: $payment->branch_id,
            companyId: $payment->company_id,
            departmentId: $payment->department_id,
            jobId: $payment->job_id
        );
    }

    public function recordApPaymentAllocation(ApPaymentAllocation $allocation, int $userId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $amount = (float) ($allocation->allocated_amount ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $allocation->loadMissing('payment');
        $paymentEntry = SubledgerEntry::where('source_type', 'ap_payment')
            ->where('source_id', $allocation->payment_id)
            ->where('event', 'payment')
            ->first();

        if (! $paymentEntry) {
            return null;
        }

        $prepayAccountId = $this->resolveAccountId('ap_payment_prepay', (int) ($allocation->payment?->company_id ?? 0));
        if (! $prepayAccountId) {
            return null;
        }

        $hasPrepay = SubledgerLine::where('entry_id', $paymentEntry->id)
            ->where('account_id', $prepayAccountId)
            ->where('debit', '>', 0)
            ->exists();

        if (! $hasPrepay) {
            return null;
        }
        $date = optional($allocation->payment?->payment_date)->toDateString() ?? now()->toDateString();

        $lines = [
            [
                'account' => 'ap_invoice_ap',
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Apply supplier advance',
            ],
            [
                'account' => 'ap_payment_prepay',
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Reduce supplier advance',
            ],
        ];

        return $this->recordEntry(
            sourceType: 'ap_payment_allocation',
            sourceId: $allocation->id,
            event: 'apply',
            entryDate: $date,
            description: 'Apply supplier advance',
            lines: $lines,
            userId: $userId,
            branchId: $allocation->payment?->branch_id,
            companyId: $allocation->payment?->company_id,
            departmentId: $allocation->payment?->department_id,
            jobId: $allocation->payment?->job_id
        );
    }

    public function recordArInvoiceIssued(ArInvoice $invoice, int $userId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $totalCents = (int) ($invoice->total_cents ?? 0);
        if ($totalCents === 0) {
            return null;
        }

        $taxCents = (int) ($invoice->tax_total_cents ?? 0);
        $netCents = $totalCents - $taxCents;

        $total = $this->moneyFromCents(abs($totalCents));
        $tax = $this->moneyFromCents(abs($taxCents));
        $net = $this->moneyFromCents(abs($netCents));
        $companyId = $this->accountingContext->resolveCompanyId((int) ($invoice->branch_id ?? 0), (int) ($invoice->company_id ?? 0));

        $requiredMappings = ['ar_control', 'sales_revenue'];
        if ($tax > 0) {
            $requiredMappings[] = 'tax_output';
        }
        $this->mappingService->assertRequiredMappings($companyId, $requiredMappings);

        $lines = [];
        if ($totalCents > 0) {
            $lines[] = [
                'account' => 'ar_invoice_ar',
                'debit' => $total,
                'credit' => 0,
                'memo' => 'Accounts receivable',
            ];
            if ($net > 0) {
                $lines[] = [
                    'account' => 'ar_invoice_revenue',
                    'debit' => 0,
                    'credit' => $net,
                    'memo' => 'Sales revenue',
                ];
            }
            if ($tax > 0) {
                $lines[] = [
                    'account' => 'ar_invoice_tax',
                    'debit' => 0,
                    'credit' => $tax,
                    'memo' => 'Output tax',
                ];
            }
        } else {
            $lines[] = [
                'account' => 'ar_invoice_ar',
                'debit' => 0,
                'credit' => $total,
                'memo' => 'Accounts receivable reversal',
            ];
            if ($net > 0) {
                $lines[] = [
                    'account' => 'ar_invoice_revenue',
                    'debit' => $net,
                    'credit' => 0,
                    'memo' => 'Sales revenue reversal',
                ];
            }
            if ($tax > 0) {
                $lines[] = [
                    'account' => 'ar_invoice_tax',
                    'debit' => $tax,
                    'credit' => 0,
                    'memo' => 'Output tax reversal',
                ];
            }
        }

        $date = optional($invoice->issue_date)->toDateString() ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'ar_invoice',
            sourceId: $invoice->id,
            event: 'issue',
            entryDate: $date,
            description: 'AR Invoice '.$invoice->invoice_number,
            lines: $lines,
            userId: $userId,
            branchId: $invoice->branch_id,
            companyId: $companyId,
            jobId: $invoice->job_id
        );
    }

    public function recordArPaymentReceived(Payment $payment, int $appliedCents, int $unappliedCents, int $userId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $amountCents = (int) ($payment->amount_cents ?? 0);
        if ($amountCents <= 0) {
            return null;
        }

        $applied = $this->moneyFromCents(max($appliedCents, 0));
        $unapplied = $this->moneyFromCents(max($unappliedCents, 0));
        $total = $this->moneyFromCents($amountCents);

        $lines = [
            [
                'account_id' => $this->paymentSettlementAccount($payment->method, (int) ($payment->company_id ?? 0), (int) ($payment->bank_account_id ?? 0), 'ar'),
                'debit' => $total,
                'credit' => 0,
                'memo' => 'Cash received',
            ],
        ];

        if ($applied > 0) {
            $lines[] = [
                'account' => 'ar_invoice_ar',
                'debit' => 0,
                'credit' => $applied,
                'memo' => 'Apply to receivables',
            ];
        }

        if ($unapplied > 0) {
            $lines[] = [
                'account' => 'ar_payment_advance',
                'debit' => 0,
                'credit' => $unapplied,
                'memo' => 'Customer advance',
            ];
        }

        $date = optional($payment->received_at)->toDateString() ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'ar_payment',
            sourceId: $payment->id,
            event: 'payment',
            entryDate: $date,
            description: 'AR Payment '.$payment->id,
            lines: $lines,
            userId: $userId,
            branchId: $payment->branch_id,
            companyId: $payment->company_id
        );
    }

    public function recordArAdvanceApplied(PaymentAllocation $allocation, int $userId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $amountCents = (int) ($allocation->amount_cents ?? 0);
        if ($amountCents <= 0) {
            return null;
        }

        $amount = $this->moneyFromCents($amountCents);

        $allocation->loadMissing('payment');
        $date = optional($allocation->created_at)->toDateString()
            ?? optional($allocation->payment?->received_at)->toDateString()
            ?? now()->toDateString();

        $lines = [
            [
                'account' => 'ar_payment_advance',
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Reduce customer advance',
            ],
            [
                'account' => 'ar_invoice_ar',
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Apply advance to receivables',
            ],
        ];

        return $this->recordEntry(
            sourceType: 'ar_payment_allocation',
            sourceId: $allocation->id,
            event: 'apply',
            entryDate: $date,
            description: 'Apply customer advance',
            lines: $lines,
            userId: $userId,
            branchId: $allocation->payment?->branch_id,
            companyId: $allocation->payment?->company_id
        );
    }

    public function recordArAllocationReleased(PaymentAllocation $allocation, int $userId, string $reason = 'void', ?string $entryDate = null, bool $skipFallbackWhenNoApplyEntry = false): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $allocation->loadMissing('payment');
        $effectiveDate = $entryDate
            ?: optional($allocation->payment?->voided_at)->toDateString()
            ?: now()->toDateString();

        $applyEntry = SubledgerEntry::query()
            ->where('source_type', 'ar_payment_allocation')
            ->where('source_id', $allocation->id)
            ->where('event', 'apply')
            ->first();

        if ($applyEntry) {
            return $this->recordReversalForEntry(
                $applyEntry,
                $reason,
                'Reverse customer advance application '.$allocation->id,
                $effectiveDate,
                $userId
            );
        }

        // No advance-apply entry exists — this was a directly-allocated payment.
        // When voiding the full payment, recordArPaymentVoided reverses the entire
        // original payment entry (including the applied portion). Creating entries
        // here would double-post ar_invoice_ar. Skip when the caller handles it.
        if ($skipFallbackWhenNoApplyEntry) {
            return null;
        }

        $amountCents = (int) ($allocation->amount_cents ?? 0);
        if ($amountCents <= 0) {
            return null;
        }

        $amount = $this->moneyFromCents($amountCents);

        return $this->recordEntry(
            sourceType: 'ar_payment_allocation',
            sourceId: $allocation->id,
            event: $reason,
            entryDate: $effectiveDate,
            description: 'Release AR payment allocation '.$allocation->id,
            lines: [
                [
                    'account' => 'ar_invoice_ar',
                    'debit' => $amount,
                    'credit' => 0,
                    'memo' => 'Restore customer advance from invoice allocation',
                ],
                [
                    'account' => 'ar_payment_advance',
                    'debit' => 0,
                    'credit' => $amount,
                    'memo' => 'Reclassify removed allocation to customer advance',
                ],
            ],
            userId: $userId,
            branchId: $allocation->payment?->branch_id,
            companyId: $allocation->payment?->company_id
        );
    }

    public function recordArPaymentVoided(Payment $payment, int $userId, ?string $entryDate = null): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $paymentEntry = SubledgerEntry::query()
            ->where('source_type', 'ar_payment')
            ->where('source_id', $payment->id)
            ->where('event', 'payment')
            ->first();

        if (! $paymentEntry) {
            return null;
        }

        return $this->recordReversalForEntry(
            $paymentEntry,
            'delete',
            'AR payment delete '.$payment->id,
            $entryDate ?: optional($payment->voided_at)->toDateString() ?: now()->toDateString(),
            $userId
        );
    }

    public function recordPettyCashIssue(PettyCashIssue $issue, ?int $userId = null): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $amount = (float) ($issue->amount ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $lines = [
            [
                'account' => 'petty_cash_asset',
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Petty cash issue',
            ],
            [
                'account' => 'petty_cash_issue_cash',
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Cash funding',
            ],
        ];

        $date = optional($issue->issue_date)->toDateString() ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'petty_cash_issue',
            sourceId: $issue->id,
            event: 'issue',
            entryDate: $date,
            description: 'Petty cash issue '.$issue->id,
            lines: $lines,
            userId: $userId
        );
    }

    public function recordPettyCashReconciliation(PettyCashReconciliation $recon, ?int $userId = null): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        if (! config('petty_cash.apply_reconciliation_to_wallet_balance', true)) {
            return null;
        }

        $variance = (float) ($recon->variance ?? 0);
        if (abs($variance) < 0.0001) {
            return null;
        }

        if ($variance > 0) {
            $lines = [
                [
                    'account' => 'petty_cash_asset',
                    'debit' => $variance,
                    'credit' => 0,
                    'memo' => 'Petty cash overage',
                ],
                [
                    'account' => 'petty_cash_over_short',
                    'debit' => 0,
                    'credit' => $variance,
                    'memo' => 'Over/short',
                ],
            ];
        } else {
            $short = abs($variance);
            $lines = [
                [
                    'account' => 'petty_cash_over_short',
                    'debit' => $short,
                    'credit' => 0,
                    'memo' => 'Over/short',
                ],
                [
                    'account' => 'petty_cash_asset',
                    'debit' => 0,
                    'credit' => $short,
                    'memo' => 'Petty cash shortage',
                ],
            ];
        }

        $date = optional($recon->reconciled_at)->toDateString()
            ?? optional($recon->period_end)->toDateString()
            ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'petty_cash_reconciliation',
            sourceId: $recon->id,
            event: 'reconcile',
            entryDate: $date,
            description: 'Petty cash reconciliation '.$recon->id,
            lines: $lines,
            userId: $userId
        );
    }

    public function recordJournalEntry(JournalEntry $journal, int $userId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $journal->loadMissing('lines');
        if ($journal->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'journal' => __('Journal entry must contain at least one line.'),
            ]);
        }

        $lines = $journal->lines->map(function ($line) {
            return [
                'account_id' => (int) $line->account_id,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'memo' => $line->memo,
            ];
        })->all();

        return $this->recordEntry(
            sourceType: 'journal_entry',
            sourceId: (int) $journal->id,
            event: 'post',
            entryDate: optional($journal->entry_date)->toDateString() ?? now()->toDateString(),
            description: $journal->memo ?: 'Journal '.$journal->entry_number,
            lines: $lines,
            userId: $userId,
            branchId: $journal->lines->pluck('branch_id')->filter()->unique()->count() === 1
                ? (int) $journal->lines->pluck('branch_id')->filter()->first()
                : null,
            companyId: (int) $journal->company_id,
            departmentId: $journal->lines->pluck('department_id')->filter()->unique()->count() === 1
                ? (int) $journal->lines->pluck('department_id')->filter()->first()
                : null,
            jobId: $journal->lines->pluck('job_id')->filter()->unique()->count() === 1
                ? (int) $journal->lines->pluck('job_id')->filter()->first()
                : null
        );
    }

    private function recordEntry(
        string $sourceType,
        int $sourceId,
        string $event,
        string $entryDate,
        ?string $description,
        array $lines,
        ?int $userId = null,
        ?int $branchId = null,
        ?int $companyId = null,
        ?int $departmentId = null,
        ?int $jobId = null
    ): ?SubledgerEntry {
        if (! $this->canPost()) {
            return null;
        }

        $branchId = $this->normalizeBranchId($branchId);
        $companyId = $this->accountingContext->resolveCompanyId($branchId, $companyId);
        $this->assertOpenPeriod($entryDate, $companyId, null, 'ledger');

        $normalized = [];
        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 4);
            $credit = round((float) ($line['credit'] ?? 0), 4);
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }
            if ($debit > 0 && $credit > 0) {
                throw ValidationException::withMessages(['ledger' => __('Ledger line cannot have both debit and credit.')]);
            }

            $accountId = isset($line['account_id']) && (int) $line['account_id'] > 0
                ? (int) $line['account_id']
                : $this->resolveAccountId($line['account'] ?? null, $companyId);
            if (! $accountId) {
                throw ValidationException::withMessages(['ledger' => __('Ledger account is missing.')]);
            }

            $normalized[] = [
                'account_id' => $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $line['memo'] ?? null,
            ];

            $debits += $debit;
            $credits += $credit;
        }

        if (abs($debits - $credits) > 0.0001) {
            throw ValidationException::withMessages(['ledger' => __('Ledger entry is unbalanced.')]);
        }

        try {
            $entry = SubledgerEntry::create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'company_id' => $companyId,
                'event' => $event,
                'entry_date' => $entryDate,
                'description' => $description,
                'source_document_type' => $sourceType,
                'source_document_id' => $sourceId,
                'branch_id' => $branchId,
                'department_id' => $departmentId,
                'job_id' => $jobId,
                'period_id' => $this->accountingContext->resolvePeriodId($entryDate, $companyId),
                'currency_code' => config('pos.currency', 'QAR'),
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            foreach ($normalized as $row) {
                $row['entry_id'] = $entry->id;
                SubledgerLine::create($row);
            }

            return $entry->load('lines');
        } catch (\Illuminate\Database\QueryException $e) {
            // Only handle duplicate-key violations (MySQL 1062 / SQLSTATE 23000).
            // All other DB errors (FK violations, column errors, connection issues) must propagate.
            if ($e->errorInfo[1] !== 1062) {
                throw $e;
            }

            $existing = SubledgerEntry::where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('event', $event)
                ->first();

            if ($existing) {
                return $existing->load('lines');
            }

            throw $e;
        }
    }

    public function recordReversalForEntry(
        SubledgerEntry $entry,
        string $event,
        ?string $description,
        ?string $entryDate = null,
        ?int $userId = null
    ): ?SubledgerEntry {
        if (! $this->canPost()) {
            return null;
        }

        $entryDate = $entryDate ?? now()->toDateString();
        $this->assertOpenPeriod($entryDate, (int) ($entry->company_id ?? 0), null, 'ledger');

        $lines = $entry->lines()->get();
        if ($lines->isEmpty()) {
            return null;
        }

        $debits = 0;
        $credits = 0;
        $reversed = [];
        foreach ($lines as $line) {
            $debit = round((float) $line->credit, 4);
            $credit = round((float) $line->debit, 4);
            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            $reversed[] = [
                'account_id' => $line->account_id,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $line->memo ? 'Reversal: '.$line->memo : 'Reversal',
            ];

            $debits += $debit;
            $credits += $credit;
        }

        if (abs($debits - $credits) > 0.0001) {
            throw ValidationException::withMessages(['ledger' => __('Ledger entry is unbalanced.')]);
        }

        try {
            $reversal = SubledgerEntry::create([
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'company_id' => $entry->company_id,
                'event' => $event,
                'entry_date' => $entryDate,
                'description' => $description,
                'source_document_type' => $entry->source_document_type,
                'source_document_id' => $entry->source_document_id,
                'branch_id' => $entry->branch_id,
                'department_id' => $entry->department_id,
                'job_id' => $entry->job_id,
                'period_id' => $this->accountingContext->resolvePeriodId($entryDate, (int) ($entry->company_id ?? 0)),
                'currency_code' => $entry->currency_code ?: config('pos.currency', 'QAR'),
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $userId,
            ]);

            foreach ($reversed as $row) {
                $row['entry_id'] = $reversal->id;
                SubledgerLine::create($row);
            }

            return $reversal->load('lines');
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->errorInfo[1] !== 1062) {
                throw $e;
            }

            $existing = SubledgerEntry::where('source_type', $entry->source_type)
                ->where('source_id', $entry->source_id)
                ->where('event', $event)
                ->first();

            if ($existing) {
                return $existing->load('lines');
            }

            throw $e;
        }
    }

    public function recordArCreditNoteApplied(Payment $voucherPayment, ArInvoice $creditNote, ArInvoice $targetInvoice, int $applyAmountCents, int $userId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        if ($applyAmountCents <= 0) {
            return null;
        }

        $amount = $this->moneyFromCents($applyAmountCents);
        $companyId = $this->accountingContext->resolveCompanyId(
            (int) ($voucherPayment->branch_id ?? 0),
            (int) ($voucherPayment->company_id ?? 0)
        );

        // Net-zero reclassification within ar_invoice_ar.
        // Both the credit note and the invoice are already in ar_invoice_ar from their
        // original posting. This entry documents the offset event for subledger audit.
        $lines = [
            [
                'account' => 'ar_invoice_ar',
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Credit note applied to invoice #'.$targetInvoice->id,
            ],
            [
                'account' => 'ar_invoice_ar',
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Credit note AR reduced #'.$creditNote->id,
            ],
        ];

        return $this->recordEntry(
            sourceType: 'ar_credit_note_application',
            sourceId: (int) $voucherPayment->id,
            event: 'apply',
            entryDate: now()->toDateString(),
            description: 'Credit note #'.$creditNote->id.' applied to invoice #'.$targetInvoice->id,
            lines: $lines,
            userId: $userId,
            branchId: (int) ($voucherPayment->branch_id ?? 0) ?: null,
            companyId: $companyId,
        );
    }

    public function recordArClearingSettlement(\App\Models\ArClearingSettlement $settlement, int $actorId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $companyId = (int) $settlement->company_id;
        $bankAccount = \App\Models\BankAccount::find($settlement->bank_account_id);
        if (! $bankAccount?->ledger_account_id) {
            return null;
        }

        $bankLedgerAccountId = (int) $bankAccount->ledger_account_id;
        $clearingKey = $settlement->settlement_method === 'card' ? 'card_clearing' : 'ar_cheque_clearing';
        $clearingAccountId = $this->resolveAccountId($clearingKey, $companyId);

        if (! $clearingAccountId) {
            return null;
        }

        $amount = $this->moneyFromCents((int) $settlement->amount_cents);

        return $this->recordEntry(
            sourceType: 'ar_clearing_settlement',
            sourceId: (int) $settlement->id,
            event: 'settle',
            entryDate: optional($settlement->settlement_date)->toDateString() ?? now()->toDateString(),
            description: 'AR clearing settlement #'.$settlement->id,
            lines: [
                ['account_id' => $bankLedgerAccountId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Settlement to bank'],
                ['account_id' => $clearingAccountId, 'debit' => 0, 'credit' => $amount, 'memo' => ucfirst($settlement->settlement_method).' clearing settled'],
            ],
            userId: $actorId,
            companyId: $companyId,
        );
    }

    public function recordApChequeClearance(\App\Models\ApChequeClearance $clearance, int $actorId): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $companyId = (int) $clearance->company_id;
        $bankAccount = \App\Models\BankAccount::find($clearance->bank_account_id);
        if (! $bankAccount?->ledger_account_id) {
            return null;
        }

        $bankLedgerAccountId = (int) $bankAccount->ledger_account_id;
        $clearingAccountId = $this->resolveAccountId('issued_cheques_clearing', $companyId);

        if (! $clearingAccountId) {
            return null;
        }

        $amount = round((float) $clearance->amount, 2);

        return $this->recordEntry(
            sourceType: 'ap_cheque_clearance',
            sourceId: (int) $clearance->id,
            event: 'clear',
            entryDate: optional($clearance->clearance_date)->toDateString() ?? now()->toDateString(),
            description: 'AP cheque clearance #'.$clearance->id,
            lines: [
                ['account_id' => $clearingAccountId, 'debit' => $amount, 'credit' => 0, 'memo' => 'Cheque presented to bank'],
                ['account_id' => $bankLedgerAccountId, 'debit' => 0, 'credit' => $amount, 'memo' => 'Bank deduction on cheque clearance'],
            ],
            userId: $actorId,
            companyId: $companyId,
        );
    }

    private function resolveAccountId(mixed $key, ?int $companyId = null): ?int
    {
        if (is_int($key) || ctype_digit((string) $key)) {
            return (int) $key;
        }

        if (! is_string($key) || $key === '') {
            return null;
        }

        $cacheKey = ($companyId ?: 0).'::'.$key;
        if (isset($this->accountCache[$cacheKey])) {
            return $this->accountCache[$cacheKey];
        }

        $account = $this->mappingService->resolveAccount($key, $companyId);
        if (! $account) {
            return null;
        }

        $this->accountCache[$cacheKey] = (int) $account->id;

        return (int) $account->id;
    }

    private function apInvoiceDebitKey(ApInvoice $invoice): string
    {
        if ($invoice->document_type === 'landed_cost_adjustment') {
            return 'ap_invoice_inventory';
        }

        if ($invoice->purchase_order_id) {
            return 'inventory_clearing';
        }

        return $invoice->is_expense ? 'ap_invoice_expense' : 'ap_invoice_inventory';
    }

    private function apInvoiceExpenseAccountId(ApInvoice $invoice): ?int
    {
        if (! $invoice->is_expense) {
            return null;
        }

        $supplier = $invoice->relationLoaded('supplier')
            ? $invoice->supplier
            : Supplier::query()->select(['id', 'default_expense_account_id'])->find($invoice->supplier_id);

        return $supplier?->default_expense_account_id ? (int) $supplier->default_expense_account_id : null;
    }

    private function inventoryOffsetKey(string $transactionType, ?string $referenceType): ?string
    {
        if ($transactionType === 'in') {
            return $referenceType === 'purchase_order' ? 'inventory_clearing' : 'inventory_adjustment';
        }

        if ($transactionType === 'out') {
            return $referenceType === 'recipe' ? 'inventory_cogs' : 'inventory_adjustment';
        }

        if ($transactionType === 'adjustment') {
            return 'inventory_adjustment';
        }

        return null;
    }

    private function buildDualLines(float $amount, string $inventoryAccount, string $offsetAccount): array
    {
        $amount = round($amount, 4);
        $abs = abs($amount);

        if ($amount > 0) {
            return [
                ['account' => $inventoryAccount, 'debit' => $abs, 'credit' => 0, 'memo' => 'Inventory increase'],
                ['account' => $offsetAccount, 'debit' => 0, 'credit' => $abs, 'memo' => 'Inventory offset'],
            ];
        }

        return [
            ['account' => $offsetAccount, 'debit' => $abs, 'credit' => 0, 'memo' => 'Inventory offset'],
            ['account' => $inventoryAccount, 'debit' => 0, 'credit' => $abs, 'memo' => 'Inventory decrease'],
        ];
    }

    private function buildInventoryDescription(InventoryTransaction $transaction): string
    {
        $reference = $transaction->reference_type ?? 'manual';
        $refId = $transaction->reference_id ? '#'.$transaction->reference_id : '';
        return trim('Inventory '.$transaction->transaction_type.' '.$reference.' '.$refId);
    }

    /**
     * @return array<string, float>
     */
    private function purchaseOrderMatchingSummary(ApInvoice $invoice): array
    {
        $matches = PurchaseOrderInvoiceMatch::query()
            ->where('ap_invoice_id', $invoice->id)
            ->get();

        return [
            'matched_amount' => round((float) $matches->sum('matched_amount'), 2),
            'variance_amount' => round((float) $matches->sum('price_variance'), 2),
        ];
    }

    private function moneyFromCents(int $cents): float
    {
        $scale = (int) config('pos.money_scale', 100);
        if ($scale <= 0) {
            return 0.0;
        }

        return round(((float) $cents) / $scale, 4);
    }

    private function paymentSettlementAccount(?string $method, ?int $companyId, ?int $bankAccountId, string $module): int
    {
        $accountId = $this->mappingService->resolveSettlementAccountId((string) $method, $companyId, $bankAccountId, $module);
        if (! $accountId) {
            throw ValidationException::withMessages([
                'payment_method' => __('No settlement ledger account is configured for the selected payment method.'),
            ]);
        }

        $required = $module === 'ap'
            ? ['ap_control', 'ap_prepay']
            : ['ar_control', 'customer_advances'];
        $normalizedMethod = $this->mappingService->normalizePaymentMethod($method);
        if ($normalizedMethod !== 'bank_transfer') {
            $required[] = match ($normalizedMethod) {
                'cash' => 'cash',
                'card' => 'card_clearing',
                'cheque' => match ($module) {
                    'ar' => 'ar_cheque_clearing',
                    'ap' => 'issued_cheques_clearing',
                    default => 'cheque_clearing',
                },
                'petty_cash' => 'petty_cash_asset',
                default => 'other_clearing',
            };
        }
        $this->mappingService->assertRequiredMappings($companyId, $required);

        return $accountId;
    }

    private function canPost(): bool
    {
        $can = Schema::hasTable('subledger_entries')
            && Schema::hasTable('subledger_lines')
            && Schema::hasTable('ledger_accounts');

        if (! $can) {
            \Illuminate\Support\Facades\Log::warning('[SubledgerService] Accounting tables not available — subledger posting skipped.', [
                'subledger_entries' => Schema::hasTable('subledger_entries'),
                'subledger_lines'   => Schema::hasTable('subledger_lines'),
                'ledger_accounts'   => Schema::hasTable('ledger_accounts'),
            ]);
        }

        return $can;
    }

    private function normalizeBranchId(?int $branchId): ?int
    {
        if (! $branchId || ! Schema::hasTable('branches')) {
            return null;
        }

        return Branch::query()->whereKey($branchId)->exists() ? $branchId : null;
    }

    private function assertOpenPeriod(string $entryDate, ?int $companyId = null, ?int $periodId = null, string $module = 'all'): void
    {
        $this->periodGate->assertDateOpen($entryDate, $companyId, $periodId, $module, 'ledger');
    }
}
