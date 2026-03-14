<?php

namespace App\Services\Accounting;

use App\Models\BankReconciliationRun;
use App\Models\BudgetVersion;
use App\Models\Job;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    public function __construct(
        protected AccountingContextService $context,
        protected BudgetService $budgetService,
        protected JobCostingService $jobCostingService
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
}
