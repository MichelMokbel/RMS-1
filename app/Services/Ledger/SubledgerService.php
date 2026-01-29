<?php

namespace App\Services\Ledger;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\ExpensePayment;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransfer;
use App\Models\LedgerAccount;
use App\Models\PettyCashExpense;
use App\Models\PettyCashIssue;
use App\Models\PettyCashReconciliation;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SubledgerService
{
    protected array $accountCache = [];

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

        $inventoryAccount = $this->mapKey('inventory_asset');
        $offsetAccount = $this->mapKey($offsetKey);

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

        $inventoryAccount = $this->mapKey('inventory_asset');
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
        $debitAccount = $this->mapKey($debitKey);
        $apAccount = $this->mapKey('ap_invoice_ap');

        $lines = [];
        if ($subtotal > 0) {
            $lines[] = [
                'account' => $debitAccount,
                'debit' => $subtotal,
                'credit' => 0,
                'memo' => 'Invoice subtotal',
            ];
        }
        if ($tax > 0) {
            $lines[] = [
                'account' => $this->mapKey('ap_invoice_tax'),
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
            userId: $userId
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
        $debitAccount = $this->mapKey($debitKey);
        $apAccount = $this->mapKey('ap_invoice_ap');

        $lines = [];
        if ($subtotal > 0) {
            $lines[] = [
                'account' => $debitAccount,
                'debit' => 0,
                'credit' => $subtotal,
                'memo' => 'Invoice reversal',
            ];
        }
        if ($tax > 0) {
            $lines[] = [
                'account' => $this->mapKey('ap_invoice_tax'),
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
            userId: $userId
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

        $lines = [
            [
                'account' => $this->mapKey('ap_payment_cash'),
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Cash payment',
            ],
        ];

        if ($applied > 0) {
            $lines[] = [
                'account' => $this->mapKey('ap_invoice_ap'),
                'debit' => $applied,
                'credit' => 0,
                'memo' => 'AP payment applied',
            ];
        }

        if ($unapplied > 0) {
            $lines[] = [
                'account' => $this->mapKey('ap_payment_prepay'),
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
            userId: $userId
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

        $prepayAccountId = $this->resolveAccountId('ap_payment_prepay');
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
                'account' => $this->mapKey('ap_invoice_ap'),
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Apply supplier advance',
            ],
            [
                'account' => $this->mapKey('ap_payment_prepay'),
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
            userId: $userId
        );
    }

    public function recordExpensePayment(ExpensePayment $payment, ?int $userId = null): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        $amount = (float) ($payment->amount ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $lines = [
            [
                'account' => $this->mapKey('expense_default'),
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Expense payment',
            ],
            [
                'account' => $this->mapKey('expense_cash'),
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Cash payment',
            ],
        ];

        $date = optional($payment->payment_date)->toDateString() ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'expense_payment',
            sourceId: $payment->id,
            event: 'payment',
            entryDate: $date,
            description: 'Expense payment '.$payment->id,
            lines: $lines,
            userId: $userId
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
                'account' => $this->mapKey('petty_cash_asset'),
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Petty cash issue',
            ],
            [
                'account' => $this->mapKey('petty_cash_issue_cash'),
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

    public function recordPettyCashExpense(PettyCashExpense $expense, ?int $userId = null): ?SubledgerEntry
    {
        if (! $this->canPost()) {
            return null;
        }

        if ($expense->status !== 'approved') {
            return null;
        }

        $amount = (float) ($expense->total_amount ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $lines = [
            [
                'account' => $this->mapKey('petty_cash_expense'),
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Petty cash expense',
            ],
            [
                'account' => $this->mapKey('petty_cash_asset'),
                'debit' => 0,
                'credit' => $amount,
                'memo' => 'Reduce petty cash',
            ],
        ];

        $date = optional($expense->approved_at)->toDateString()
            ?? optional($expense->expense_date)->toDateString()
            ?? now()->toDateString();

        return $this->recordEntry(
            sourceType: 'petty_cash_expense',
            sourceId: $expense->id,
            event: 'approve',
            entryDate: $date,
            description: 'Petty cash expense '.$expense->id,
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
                    'account' => $this->mapKey('petty_cash_asset'),
                    'debit' => $variance,
                    'credit' => 0,
                    'memo' => 'Petty cash overage',
                ],
                [
                    'account' => $this->mapKey('petty_cash_over_short'),
                    'debit' => 0,
                    'credit' => $variance,
                    'memo' => 'Over/short',
                ],
            ];
        } else {
            $short = abs($variance);
            $lines = [
                [
                    'account' => $this->mapKey('petty_cash_over_short'),
                    'debit' => $short,
                    'credit' => 0,
                    'memo' => 'Over/short',
                ],
                [
                    'account' => $this->mapKey('petty_cash_asset'),
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

    private function recordEntry(
        string $sourceType,
        int $sourceId,
        string $event,
        string $entryDate,
        ?string $description,
        array $lines,
        ?int $userId = null,
        ?int $branchId = null
    ): ?SubledgerEntry {
        if (! $this->canPost()) {
            return null;
        }

        $this->assertOpenPeriod($entryDate);

        $exists = SubledgerEntry::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('event', $event)
            ->exists();

        if ($exists) {
            return null;
        }

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

            $accountId = $this->resolveAccountId($line['account'] ?? null);
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

        $entry = SubledgerEntry::create([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'event' => $event,
            'entry_date' => $entryDate,
            'description' => $description,
            'branch_id' => $branchId,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $userId,
        ]);

        foreach ($normalized as $row) {
            $row['entry_id'] = $entry->id;
            SubledgerLine::create($row);
        }

        return $entry->load('lines');
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
        $this->assertOpenPeriod($entryDate);

        $exists = SubledgerEntry::where('source_type', $entry->source_type)
            ->where('source_id', $entry->source_id)
            ->where('event', $event)
            ->exists();

        if ($exists) {
            return null;
        }

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

        $reversal = SubledgerEntry::create([
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'event' => $event,
            'entry_date' => $entryDate,
            'description' => $description,
            'branch_id' => $entry->branch_id,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $userId,
        ]);

        foreach ($reversed as $row) {
            $row['entry_id'] = $reversal->id;
            SubledgerLine::create($row);
        }

        return $reversal->load('lines');
    }

    private function resolveAccountId(?string $key): ?int
    {
        if (! $key) {
            return null;
        }

        $mapped = $this->mapKey($key);
        $accounts = $this->accountsConfig();

        $code = $accounts[$mapped]['code'] ?? $mapped;
        if (isset($this->accountCache[$code])) {
            return $this->accountCache[$code];
        }

        $account = LedgerAccount::where('code', $code)->first();
        if (! $account) {
            $meta = $accounts[$mapped] ?? ['name' => $code, 'type' => 'asset'];
            $account = LedgerAccount::create([
                'code' => $code,
                'name' => $meta['name'] ?? $code,
                'type' => $meta['type'] ?? 'asset',
                'is_active' => true,
            ]);
        }

        $this->accountCache[$code] = $account->id;

        return $account->id;
    }

    private function apInvoiceDebitKey(ApInvoice $invoice): string
    {
        if ($invoice->purchase_order_id) {
            return 'inventory_clearing';
        }

        return $invoice->is_expense ? 'ap_invoice_expense' : 'ap_invoice_inventory';
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

    private function mapKey(string $key): string
    {
        $map = Config::get('ledger.mappings', []);
        return $map[$key] ?? $key;
    }

    private function accountsConfig(): array
    {
        return Config::get('ledger.accounts', []);
    }

    private function canPost(): bool
    {
        return Schema::hasTable('subledger_entries')
            && Schema::hasTable('subledger_lines')
            && Schema::hasTable('ledger_accounts');
    }

    private function assertOpenPeriod(string $entryDate): void
    {
        $lockDate = Config::get('finance.lock_date');
        if (! $lockDate) {
            return;
        }

        $locked = Carbon::parse($lockDate)->startOfDay();
        $date = Carbon::parse($entryDate)->startOfDay();

        if ($date->lessThanOrEqualTo($locked)) {
            throw ValidationException::withMessages([
                'ledger' => __('Posting is locked for periods on or before :date.', ['date' => $locked->toDateString()]),
            ]);
        }
    }
}
