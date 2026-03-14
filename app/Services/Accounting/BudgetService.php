<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\BudgetLine;
use App\Models\BudgetVersion;
use App\Models\FiscalYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BudgetService
{
    public function __construct(
        protected AccountingContextService $context,
        protected AccountingAuditLogService $auditLog
    ) {
    }

    public function createVersion(array $data, int $actorId): BudgetVersion
    {
        $companyId = (int) ($data['company_id'] ?? 0) ?: $this->context->defaultCompanyId();
        $fiscalYear = FiscalYear::query()->findOrFail($data['fiscal_year_id']);
        $periodNumbers = AccountingPeriod::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->orderBy('period_number')
            ->pluck('period_number')
            ->all();

        if ($periodNumbers === []) {
            $periodNumbers = range(1, 12);
        }

        $budget = DB::transaction(function () use ($data, $actorId, $companyId, $fiscalYear, $periodNumbers) {
            if (! empty($data['is_active'])) {
                BudgetVersion::query()
                    ->where('company_id', $companyId)
                    ->update(['is_active' => false]);
            }

            $budget = BudgetVersion::query()->create([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYear->id,
                'name' => $data['name'],
                'status' => $data['status'] ?? 'draft',
                'is_active' => (bool) ($data['is_active'] ?? false),
                'created_by' => $actorId,
            ]);

            foreach ((array) $data['lines'] as $line) {
                foreach ($this->normalizedPeriodAmounts($line, $periodNumbers) as $periodNumber => $amount) {
                    BudgetLine::query()->create([
                        'budget_version_id' => $budget->id,
                        'account_id' => $line['account_id'],
                        'department_id' => $line['department_id'] ?? null,
                        'job_id' => $line['job_id'] ?? null,
                        'branch_id' => $line['branch_id'] ?? null,
                        'period_number' => $periodNumber,
                        'amount' => $amount,
                    ]);
                }
            }

            return $budget;
        });

        $this->auditLog->log('budget.created', $actorId, $budget, [
            'line_count' => count((array) $data['lines']),
            'fiscal_year_id' => (int) $fiscalYear->id,
        ], $companyId);

        return $budget->load(['company', 'fiscalYear', 'lines.account']);
    }

    public function variance(BudgetVersion $version): array
    {
        $fiscalYear = $version->fiscalYear;
        $budgetRows = BudgetLine::query()
            ->with(['account'])
            ->where('budget_version_id', $version->id)
            ->orderBy('period_number')
            ->get();

        $actualRows = DB::table('subledger_lines as sl')
            ->join('subledger_entries as se', 'se.id', '=', 'sl.entry_id')
            ->join('ledger_accounts as la', 'la.id', '=', 'sl.account_id')
            ->leftJoin('accounting_periods as ap', 'ap.id', '=', 'se.period_id')
            ->where('se.company_id', $version->company_id)
            ->whereNull('se.voided_at')
            ->whereDate('se.entry_date', '>=', $fiscalYear->start_date)
            ->whereDate('se.entry_date', '<=', $fiscalYear->end_date)
            ->selectRaw('
                sl.account_id,
                se.department_id,
                se.job_id,
                se.branch_id,
                COALESCE(ap.period_number, MONTH(se.entry_date)) as period_number,
                SUM(CASE
                    WHEN la.type IN ("income", "revenue", "liability", "equity") THEN sl.credit - sl.debit
                    ELSE sl.debit - sl.credit
                END) as actual_amount
            ')
            ->groupBy('sl.account_id', 'se.department_id', 'se.job_id', 'se.branch_id', DB::raw('COALESCE(ap.period_number, MONTH(se.entry_date))'))
            ->get()
            ->keyBy(fn ($row) => $this->varianceKey(
                (int) $row->account_id,
                $row->department_id ? (int) $row->department_id : null,
                $row->job_id ? (int) $row->job_id : null,
                $row->branch_id ? (int) $row->branch_id : null,
                (int) $row->period_number
            ));

        $rows = [];
        $budgetTotal = 0.0;
        $actualTotal = 0.0;
        $periodTotals = [];

        foreach ($budgetRows as $line) {
            $key = $this->varianceKey(
                (int) $line->account_id,
                $line->department_id ? (int) $line->department_id : null,
                $line->job_id ? (int) $line->job_id : null,
                $line->branch_id ? (int) $line->branch_id : null,
                (int) $line->period_number
            );

            $actual = (float) ($actualRows[$key]->actual_amount ?? 0);
            $budget = (float) $line->amount;
            $variance = round($actual - $budget, 2);

            $budgetTotal += $budget;
            $actualTotal += $actual;
            $periodTotals[$line->period_number] = ($periodTotals[$line->period_number] ?? ['budget' => 0.0, 'actual' => 0.0]);
            $periodTotals[$line->period_number]['budget'] += $budget;
            $periodTotals[$line->period_number]['actual'] += $actual;

            $rows[] = [
                'account_id' => (int) $line->account_id,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'period_number' => (int) $line->period_number,
                'budget_amount' => round($budget, 2),
                'actual_amount' => round($actual, 2),
                'variance_amount' => $variance,
                'status' => $variance > 0 ? 'over' : ($variance < 0 ? 'under' : 'on_plan'),
            ];
        }

        ksort($periodTotals);

        return [
            'budget_version_id' => (int) $version->id,
            'summary' => [
                'budget_total' => round($budgetTotal, 2),
                'actual_total' => round($actualTotal, 2),
                'variance_total' => round($actualTotal - $budgetTotal, 2),
            ],
            'period_totals' => collect($periodTotals)->map(fn (array $totals, int $period) => [
                'period_number' => $period,
                'budget_amount' => round($totals['budget'], 2),
                'actual_amount' => round($totals['actual'], 2),
                'variance_amount' => round($totals['actual'] - $totals['budget'], 2),
            ])->values()->all(),
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $line
     * @param array<int, int> $periodNumbers
     * @return array<int, float>
     */
    private function normalizedPeriodAmounts(array $line, array $periodNumbers): array
    {
        $periodAmounts = array_values(array_filter(
            (array) ($line['period_amounts'] ?? []),
            static fn ($value) => $value !== null && $value !== ''
        ));

        if ($periodAmounts !== []) {
            $normalized = [];

            foreach ($periodNumbers as $index => $periodNumber) {
                $normalized[$periodNumber] = round((float) ($periodAmounts[$index] ?? 0), 2);
            }

            return $normalized;
        }

        $annualAmount = round((float) ($line['annual_amount'] ?? 0), 2);
        $periodCount = max(count($periodNumbers), 1);
        $baseAmount = round($annualAmount / $periodCount, 2);
        $normalized = [];

        foreach ($periodNumbers as $periodNumber) {
            $normalized[$periodNumber] = $baseAmount;
        }

        $allocated = round(array_sum($normalized), 2);
        $difference = round($annualAmount - $allocated, 2);

        if ($difference !== 0.0) {
            $lastPeriod = end($periodNumbers);
            $normalized[$lastPeriod] = round($normalized[$lastPeriod] + $difference, 2);
        }

        return $normalized;
    }

    private function varianceKey(int $accountId, ?int $departmentId, ?int $jobId, ?int $branchId, int $periodNumber): string
    {
        return implode(':', [
            $accountId,
            $departmentId ?: 0,
            $jobId ?: 0,
            $branchId ?: 0,
            $periodNumber,
        ]);
    }
}
