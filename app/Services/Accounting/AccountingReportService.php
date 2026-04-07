<?php

namespace App\Services\Accounting;

use App\Models\AccountingCompany;
use App\Models\ArInvoice;
use App\Models\ApInvoice;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\SubledgerEntry;
use App\Models\Branch;
use App\Models\BankReconciliationRun;
use App\Models\BudgetVersion;
use App\Models\Department;
use App\Models\Job;
use App\Models\Supplier;
use App\Services\AP\PurchaseOrderInvoiceMatchingService;
use App\Services\AR\ArAllocationIntegrityService;
use App\Services\Spend\SpendReportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    public function __construct(
        protected AccountingContextService $context,
        protected BudgetService $budgetService,
        protected JobCostingService $jobCostingService,
        protected PurchaseOrderInvoiceMatchingService $matchingService,
        protected SpendReportService $spendReportService,
        protected ArAllocationIntegrityService $allocationIntegrity,
    ) {
    }

    public function summary(?int $companyId = null, ?string $dateTo = null): array
    {
        $companyId = $companyId ?: $this->context->defaultCompanyId();
        $dateTo = $dateTo ?: now()->toDateString();

        $activeBudget = BudgetVersion::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->latest('created_at')
            ->first();

        $jobs = Job::query()
            ->where('company_id', $companyId)
            ->latest('created_at')
            ->limit(10)
            ->get();

        return [
            'trial_balance' => $this->trialBalance($companyId, $dateTo),
            'profit_and_loss' => $this->profitAndLoss($companyId, $dateTo),
            'balance_sheet' => $this->balanceSheet($companyId, $dateTo),
            'cash_flow' => $this->cashFlow($companyId, $dateTo),
            'bank_reconciliation' => $this->bankReconciliationSummary($companyId),
            'budget_variance' => $activeBudget ? $this->budgetService->variance($activeBudget) : null,
            'job_profitability' => $jobs->map(fn (Job $job) => $this->jobCostingService->profitability($job))->all(),
        ];
    }

    public function trialBalance(int $companyId, string $dateTo): array
    {
        $rows = DB::table('subledger_lines as sl')
            ->join('subledger_entries as se', 'se.id', '=', 'sl.entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'sl.account_id')
            ->where('se.company_id', $companyId)
            ->whereNull('se.voided_at')
            ->whereDate('se.entry_date', '<=', $dateTo)
            ->selectRaw('la.id, la.code, la.name, la.type, SUM(sl.debit) as debit_total, SUM(sl.credit) as credit_total')
            ->groupBy('la.id', 'la.code', 'la.name', 'la.type')
            ->orderBy('la.code')
            ->get();

        $totals = ['debit_total' => 0.0, 'credit_total' => 0.0];

        $entries = $rows->map(function ($row) use (&$totals) {
            $balance = round((float) $row->debit_total - (float) $row->credit_total, 2);
            $debitBalance = $balance >= 0 ? $balance : 0.0;
            $creditBalance = $balance < 0 ? abs($balance) : 0.0;

            $totals['debit_total'] += $debitBalance;
            $totals['credit_total'] += $creditBalance;

            return [
                'account_id' => (int) $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'debit_total' => round((float) $row->debit_total, 2),
                'credit_total' => round((float) $row->credit_total, 2),
                'debit_balance' => round($debitBalance, 2),
                'credit_balance' => round($creditBalance, 2),
            ];
        })->all();

        return [
            'as_of' => $dateTo,
            'entries' => $entries,
            'totals' => [
                'debit_total' => round($totals['debit_total'], 2),
                'credit_total' => round($totals['credit_total'], 2),
            ],
        ];
    }

    public function profitAndLoss(int $companyId, string $dateTo): array
    {
        $rows = DB::table('subledger_lines as sl')
            ->join('subledger_entries as se', 'se.id', '=', 'sl.entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'sl.account_id')
            ->where('se.company_id', $companyId)
            ->whereNull('se.voided_at')
            ->whereDate('se.entry_date', '<=', $dateTo)
            ->whereIn('la.type', ['income', 'revenue', 'expense'])
            ->selectRaw('la.id, la.code, la.name, la.type, SUM(sl.debit) as debit_total, SUM(sl.credit) as credit_total')
            ->groupBy('la.id', 'la.code', 'la.name', 'la.type')
            ->orderBy('la.code')
            ->get()
            ->map(function ($row) {
                $amount = in_array($row->type, ['income', 'revenue'], true)
                    ? round((float) $row->credit_total - (float) $row->debit_total, 2)
                    : round((float) $row->debit_total - (float) $row->credit_total, 2);

                return [
                    'account_id' => (int) $row->id,
                    'code' => $row->code,
                    'name' => $row->name,
                    'type' => $row->type,
                    'amount' => $amount,
                ];
            });

        $revenue = round((float) $rows->whereIn('type', ['income', 'revenue'])->sum('amount'), 2);
        $expenses = round((float) $rows->where('type', 'expense')->sum('amount'), 2);

        return [
            'as_of' => $dateTo,
            'revenue_total' => $revenue,
            'expense_total' => $expenses,
            'net_income' => round($revenue - $expenses, 2),
            'rows' => $rows->all(),
        ];
    }

    public function balanceSheet(int $companyId, string $dateTo): array
    {
        $rows = DB::table('subledger_lines as sl')
            ->join('subledger_entries as se', 'se.id', '=', 'sl.entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'sl.account_id')
            ->where('se.company_id', $companyId)
            ->whereNull('se.voided_at')
            ->whereDate('se.entry_date', '<=', $dateTo)
            ->whereIn('la.type', ['asset', 'liability', 'equity'])
            ->selectRaw('la.id, la.code, la.name, la.type, SUM(sl.debit) as debit_total, SUM(sl.credit) as credit_total')
            ->groupBy('la.id', 'la.code', 'la.name', 'la.type')
            ->orderBy('la.code')
            ->get()
            ->map(function ($row) {
                $amount = $row->type === 'asset'
                    ? round((float) $row->debit_total - (float) $row->credit_total, 2)
                    : round((float) $row->credit_total - (float) $row->debit_total, 2);

                return [
                    'account_id' => (int) $row->id,
                    'code' => $row->code,
                    'name' => $row->name,
                    'type' => $row->type,
                    'amount' => $amount,
                ];
            });

        return [
            'as_of' => $dateTo,
            'asset_total' => round((float) $rows->where('type', 'asset')->sum('amount'), 2),
            'liability_total' => round((float) $rows->where('type', 'liability')->sum('amount'), 2),
            'equity_total' => round((float) $rows->where('type', 'equity')->sum('amount'), 2),
            'rows' => $rows->all(),
        ];
    }

    public function cashFlow(int $companyId, string $dateTo): array
    {
        $rows = DB::table('bank_transactions')
            ->where('company_id', $companyId)
            ->whereNull('statement_import_id')
            ->where('status', '!=', 'void')
            ->whereDate('transaction_date', '<=', $dateTo)
            ->selectRaw('bank_account_id, direction, SUM(amount) as amount_total')
            ->groupBy('bank_account_id', 'direction')
            ->get();

        $inflows = round((float) $rows->where('direction', 'inflow')->sum('amount_total'), 2);
        $outflows = round((float) $rows->where('direction', 'outflow')->sum('amount_total'), 2);

        return [
            'as_of' => $dateTo,
            'inflow_total' => $inflows,
            'outflow_total' => $outflows,
            'net_cash_flow' => round($inflows - $outflows, 2),
        ];
    }

    public function bankReconciliationSummary(int $companyId): array
    {
        $runs = BankReconciliationRun::query()
            ->with('bankAccount')
            ->where('company_id', $companyId)
            ->latest('statement_date')
            ->limit(10)
            ->get()
            ->map(function (BankReconciliationRun $run) {
                $transactions = $run->transactions;

                return [
                    'run_id' => (int) $run->id,
                    'bank_account_name' => $run->bankAccount?->name,
                    'statement_date' => $run->statement_date?->toDateString(),
                    'statement_ending_balance' => round((float) $run->statement_ending_balance, 2),
                    'book_ending_balance' => round((float) $run->book_ending_balance, 2),
                    'variance_amount' => round((float) $run->variance_amount, 2),
                    'status' => $run->status,
                    'matched_count' => $transactions->where('status', 'matched')->count(),
                    'reconciled_count' => $transactions->where('status', 'reconciled')->count(),
                    'exception_count' => $transactions->where('status', 'exception')->count(),
                ];
            })
            ->all();

        return ['runs' => $runs];
    }

    public function vendorLedger(int $companyId, ?int $supplierId = null, ?string $dateFrom = null, ?string $dateTo = null, array $filters = []): array
    {
        $query = ApInvoice::query()
            ->with(['supplier', 'allocations.payment'])
            ->where('company_id', $companyId)
            ->whereIn('status', ['posted', 'partially_paid', 'paid'])
            ->when($supplierId, fn ($builder) => $builder->where('supplier_id', $supplierId))
            ->when(! empty($filters['branch_id']), fn ($builder) => $builder->where('branch_id', (int) $filters['branch_id']))
            ->when(! empty($filters['department_id']), fn ($builder) => $builder->where('department_id', (int) $filters['department_id']))
            ->when(! empty($filters['job_id']), fn ($builder) => $builder->where('job_id', (int) $filters['job_id']))
            ->when($dateFrom, fn ($builder) => $builder->whereDate('invoice_date', '>=', $dateFrom))
            ->when($dateTo, fn ($builder) => $builder->whereDate('invoice_date', '<=', $dateTo))
            ->orderBy('invoice_date')
            ->orderBy('id');

        $rows = $query->get()->flatMap(function (ApInvoice $invoice) {
            $entries = [[
                'date' => optional($invoice->invoice_date)->toDateString(),
                'supplier_id' => (int) $invoice->supplier_id,
                'supplier_name' => $invoice->supplier?->name,
                'reference' => $invoice->invoice_number,
                'description' => __('Invoice :invoice', ['invoice' => $invoice->invoice_number]),
                'debit' => 0.0,
                'credit' => round((float) $invoice->total_amount, 2),
                'source_type' => 'invoice',
                'source_id' => (int) $invoice->id,
            ]];

            foreach ($invoice->allocations as $allocation) {
                $entries[] = [
                    'date' => optional($allocation->payment?->payment_date)->toDateString(),
                    'supplier_id' => (int) $invoice->supplier_id,
                    'supplier_name' => $invoice->supplier?->name,
                    'reference' => (string) $allocation->payment?->reference,
                    'description' => __('Payment :payment', ['payment' => $allocation->payment?->reference ?? $allocation->payment_id]),
                    'debit' => round((float) $allocation->allocated_amount, 2),
                    'credit' => 0.0,
                    'source_type' => 'payment',
                    'source_id' => (int) $allocation->payment_id,
                ];
            }

            return $entries;
        })->sortBy(['supplier_name', 'date', 'reference'])->values();

        return ['rows' => $rows->all()];
    }

    public function apAging(int $companyId, array $filters = []): array
    {
        $asOf = (string) ($filters['date_to'] ?? now()->toDateString());

        $rows = ApInvoice::query()
            ->with('supplier')
            ->withSum('allocations as paid_sum', 'allocated_amount')
            ->where('company_id', $companyId)
            ->whereIn('status', ['posted', 'partially_paid'])
            ->when(! empty($filters['supplier_id']), fn ($query) => $query->where('supplier_id', (int) $filters['supplier_id']))
            ->when(! empty($filters['branch_id']), fn ($query) => $query->where('branch_id', (int) $filters['branch_id']))
            ->when(! empty($filters['department_id']), fn ($query) => $query->where('department_id', (int) $filters['department_id']))
            ->when(! empty($filters['job_id']), fn ($query) => $query->where('job_id', (int) $filters['job_id']))
            ->whereDate('invoice_date', '<=', $asOf)
            ->orderBy('due_date')
            ->get()
            ->map(function (ApInvoice $invoice) use ($asOf) {
                $balance = round((float) $invoice->total_amount - (float) ($invoice->paid_sum ?? 0), 2);
                if ($balance <= 0.0005) {
                    return null;
                }

                $dueDate = optional($invoice->due_date ?? $invoice->invoice_date)?->toDateString() ?? $asOf;
                $daysPastDue = max(0, \Carbon\Carbon::parse($dueDate)->startOfDay()->diffInDays(\Carbon\Carbon::parse($asOf)->startOfDay(), false));
                $bucket = match (true) {
                    $daysPastDue <= 0 => 'current',
                    $daysPastDue <= 30 => '1_30',
                    $daysPastDue <= 60 => '31_60',
                    $daysPastDue <= 90 => '61_90',
                    default => 'over_90',
                };

                return [
                    'invoice_id' => (int) $invoice->id,
                    'supplier_id' => (int) $invoice->supplier_id,
                    'supplier_name' => $invoice->supplier?->name ?? __('Unknown supplier'),
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => optional($invoice->invoice_date)->toDateString(),
                    'due_date' => optional($invoice->due_date)->toDateString(),
                    'balance' => $balance,
                    'bucket' => $bucket,
                ];
            })
            ->filter();

        $grouped = $rows->groupBy('supplier_id')->map(function (Collection $bucket) {
            $sample = $bucket->first();

            return [
                'supplier_id' => $sample['supplier_id'],
                'supplier_name' => $sample['supplier_name'],
                'document_count' => $bucket->count(),
                'current' => round((float) $bucket->where('bucket', 'current')->sum('balance'), 2),
                '1_30' => round((float) $bucket->where('bucket', '1_30')->sum('balance'), 2),
                '31_60' => round((float) $bucket->where('bucket', '31_60')->sum('balance'), 2),
                '61_90' => round((float) $bucket->where('bucket', '61_90')->sum('balance'), 2),
                'over_90' => round((float) $bucket->where('bucket', 'over_90')->sum('balance'), 2),
                'total' => round((float) $bucket->sum('balance'), 2),
                'documents' => $bucket->values()->all(),
            ];
        })->sortBy('supplier_name')->values();

        return [
            'as_of' => $asOf,
            'rows' => $grouped->all(),
            'totals' => [
                'current' => round((float) $grouped->sum('current'), 2),
                '1_30' => round((float) $grouped->sum('1_30'), 2),
                '31_60' => round((float) $grouped->sum('31_60'), 2),
                '61_90' => round((float) $grouped->sum('61_90'), 2),
                'over_90' => round((float) $grouped->sum('over_90'), 2),
                'total' => round((float) $grouped->sum('total'), 2),
            ],
        ];
    }

    public function expenseAnalysis(int $companyId, array $filters = []): array
    {
        $rows = $this->spendReportService->collect(array_merge($filters, ['company_id' => $companyId]), 5000);
        $branchNames = Branch::query()->pluck('name', 'id');
        $departmentNames = Department::query()->pluck('name', 'id');
        $jobNames = Job::query()->pluck('name', 'id');
        $supplierNames = Supplier::query()->pluck('name', 'id');

        $grouped = $rows->groupBy(fn (array $row) => implode(':', [
            $row['branch_id'] ?? 0,
            $row['department_id'] ?? 0,
            $row['job_id'] ?? 0,
            $row['supplier'] ?? '',
        ]))->map(function (Collection $bucket) {
            $sample = $bucket->first();

            return [
                'branch_id' => $sample['branch_id'] ?? null,
                'branch_name' => ($sample['branch_id'] ?? null) ? $branchNames->get((int) $sample['branch_id']) : null,
                'department_id' => $sample['department_id'] ?? null,
                'department_name' => ($sample['department_id'] ?? null) ? $departmentNames->get((int) $sample['department_id']) : null,
                'job_id' => $sample['job_id'] ?? null,
                'job_name' => ($sample['job_id'] ?? null) ? $jobNames->get((int) $sample['job_id']) : null,
                'supplier' => $sample['supplier'] ?? null,
                'supplier_id' => $sample['supplier_id'] ?? null,
                'supplier_name' => ($sample['supplier_id'] ?? null) ? $supplierNames->get((int) $sample['supplier_id']) : ($sample['supplier'] ?? null),
                'amount' => round((float) $bucket->sum('amount'), 2),
                'count' => $bucket->count(),
                'rows' => $bucket->values()->all(),
            ];
        })->values();

        return ['rows' => $grouped->all()];
    }

    public function inventoryValuation(int $companyId, ?string $dateTo = null): array
    {
        $dateTo = $dateTo ?: now()->toDateString();

        $rows = DB::table('inventory_transactions as it')
            ->join('inventory_items as ii', 'ii.id', '=', 'it.item_id')
            ->leftJoin('branches as b', 'b.id', '=', 'it.branch_id')
            ->where(function ($query) use ($companyId) {
                $query->where('b.company_id', $companyId)
                    ->orWhereNull('b.company_id');
            })
            ->whereDate('it.transaction_date', '<=', $dateTo)
            ->selectRaw('ii.id as item_id, ii.name, b.id as branch_id, b.name as branch_name, SUM(it.quantity) as quantity_total, MAX(ii.cost_per_unit) as current_cost')
            ->groupBy('ii.id', 'ii.name', 'b.id', 'b.name')
            ->orderBy('ii.name')
            ->get()
            ->map(fn ($row) => [
                'item_id' => (int) $row->item_id,
                'item_name' => $row->name,
                'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
                'branch_name' => $row->branch_name,
                'quantity' => round((float) $row->quantity_total, 3),
                'unit_cost' => round((float) $row->current_cost, 4),
                'valuation_amount' => round((float) $row->quantity_total * (float) $row->current_cost, 2),
            ]);

        return [
            'as_of' => $dateTo,
            'rows' => $rows->all(),
            'total' => round((float) $rows->sum('valuation_amount'), 2),
        ];
    }

    public function purchaseAccruals(int $companyId, array $filters = []): array
    {
        $rows = $this->matchingService->purchaseAccrualRows($companyId, $filters);

        return [
            'rows' => $rows->all(),
            'total' => round((float) $rows->sum('accrual_value'), 2),
        ];
    }

    public function multiCompanySummary(?string $dateTo = null): array
    {
        $dateTo = $dateTo ?: now()->toDateString();

        $rows = AccountingCompany::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (AccountingCompany $company) use ($dateTo) {
                $profitAndLoss = $this->profitAndLoss((int) $company->id, $dateTo);
                $balanceSheet = $this->balanceSheet((int) $company->id, $dateTo);

                return [
                    'company_id' => (int) $company->id,
                    'company_name' => $company->name,
                    'revenue_total' => $profitAndLoss['revenue_total'],
                    'expense_total' => $profitAndLoss['expense_total'],
                    'net_income' => $profitAndLoss['net_income'],
                    'asset_total' => $balanceSheet['asset_total'],
                    'liability_total' => $balanceSheet['liability_total'],
                    'equity_total' => $balanceSheet['equity_total'],
                ];
            });

        return ['rows' => $rows->all()];
    }

    public function arCreditBalanceExceptions(int $companyId, string $dateTo): array
    {
        $arAccount = LedgerAccount::query()
            ->where('company_id', $companyId)
            ->where('code', '1500')
            ->first()
            ?: LedgerAccount::query()->where('code', '1500')->first();

        $creditNotes = ArInvoice::query()
            ->with('customer')
            ->where('company_id', $companyId)
            ->where('type', 'credit_note')
            ->whereNull('voided_at')
            ->whereDate('issue_date', '<=', $dateTo)
            ->where('balance_cents', '<', 0)
            ->get()
            ->map(fn (ArInvoice $invoice) => [
                'kind' => 'credit_note',
                'customer_name' => $invoice->customer?->name ?? '—',
                'reference' => $invoice->invoice_number,
                'date' => optional($invoice->issue_date)->toDateString(),
                'amount' => round(abs((int) $invoice->balance_cents) / 100, 2),
                'notes' => __('Unapplied credit note'),
            ]);

        $unappliedReceipts = Payment::query()
            ->with('customer')
            ->where('source', 'ar')
            ->where('company_id', $companyId)
            ->whereNull('voided_at')
            ->whereDate('received_at', '<=', $dateTo)
            ->withSum('allocations as allocated_sum', 'amount_cents')
            ->get()
            ->map(function (Payment $payment) {
                $allocated = (int) ($payment->allocated_sum ?? 0);
                $remaining = (int) $payment->amount_cents - $allocated;

                if ($remaining <= 0) {
                    return null;
                }

                return [
                    'kind' => 'unapplied_receipt',
                    'customer_name' => $payment->customer?->name ?? '—',
                    'reference' => '#'.$payment->id,
                    'date' => optional($payment->received_at)->toDateString(),
                    'amount' => round($remaining / 100, 2),
                    'notes' => __('Unapplied AR receipt currently parked in customer advances'),
                ];
            })
            ->filter()
            ->values();

        $mismatches = $this->allocationIntegrity->mismatchedAllocations($companyId)
            ->map(fn (array $row) => [
                'kind' => 'cross_company_allocation',
                'customer_name' => $row['customer_name'] ?: '—',
                'reference' => '#'.$row['allocation']->id,
                'date' => optional($row['payment']->received_at)->toDateString(),
                'amount' => round((float) $row['amount'], 2),
                'notes' => __('Payment company: :payment_company / Invoice company: :invoice_company', [
                    'payment_company' => $row['payment_company_name'] ?: __('Unresolved'),
                    'invoice_company' => $row['invoice_company_name'] ?: __('Unresolved'),
                ]),
            ]);

        $otherEntries = collect();
        $arCreditBalance = 0.0;

        if ($arAccount) {
            $totals = DB::table('subledger_lines as sl')
                ->join('subledger_entries as se', 'se.id', '=', 'sl.entry_id')
                ->where('sl.account_id', $arAccount->id)
                ->where('se.company_id', $companyId)
                ->whereNull('se.voided_at')
                ->whereDate('se.entry_date', '<=', $dateTo)
                ->selectRaw('SUM(sl.debit) as debit_total, SUM(sl.credit) as credit_total')
                ->first();

            $arCreditBalance = max(0, round(((float) ($totals->credit_total ?? 0)) - ((float) ($totals->debit_total ?? 0)), 2));

            $otherEntries = SubledgerEntry::query()
                ->with('lines')
                ->where('company_id', $companyId)
                ->whereNull('voided_at')
                ->whereDate('entry_date', '<=', $dateTo)
                ->whereNotIn('source_type', ['ar_invoice', 'ar_payment_allocation'])
                ->whereHas('lines', fn ($query) => $query->where('account_id', $arAccount->id)->where('credit', '>', 0))
                ->get()
                ->map(function (SubledgerEntry $entry) use ($arAccount) {
                    $creditAmount = round((float) $entry->lines
                        ->where('account_id', $arAccount->id)
                        ->sum('credit'), 2);

                    return [
                        'kind' => 'other_ar_credit_entry',
                        'customer_name' => '—',
                        'reference' => $entry->description ?: ($entry->source_type.'#'.$entry->source_id),
                        'date' => optional($entry->entry_date)->toDateString(),
                        'amount' => $creditAmount,
                        'notes' => __('Source type: :type', ['type' => $entry->source_type ?: 'manual']),
                    ];
                });
        }

        $knownArCredit = round(
            (float) $creditNotes->sum('amount')
            + (float) $mismatches->sum('amount')
            + (float) $otherEntries->sum('amount'),
            2
        );

        return [
            'as_of' => $dateTo,
            'ar_credit_balance' => $arCreditBalance,
            'known_ar_credit' => $knownArCredit,
            'unresolved_ar_credit' => round(max(0, $arCreditBalance - $knownArCredit), 2),
            'rows' => [
                'credit_notes' => $creditNotes->values()->all(),
                'cross_company_allocations' => $mismatches->values()->all(),
                'other_entries' => $otherEntries->values()->all(),
                'unapplied_receipts' => $unappliedReceipts->values()->all(),
            ],
            'totals' => [
                'credit_notes' => round((float) $creditNotes->sum('amount'), 2),
                'cross_company_allocations' => round((float) $mismatches->sum('amount'), 2),
                'other_entries' => round((float) $otherEntries->sum('amount'), 2),
                'unapplied_receipts' => round((float) $unappliedReceipts->sum('amount'), 2),
            ],
        ];
    }
}
