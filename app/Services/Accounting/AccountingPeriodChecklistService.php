<?php

namespace App\Services\Accounting;

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\BankAccount;
use App\Models\BankReconciliationRun;
use App\Models\ClosingChecklist;
use App\Models\GlBatch;
use App\Models\JournalEntry;
use App\Support\Accounting\PeriodCloseTaskCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AccountingPeriodChecklistService
{
    public function __construct(
        protected AccountingAuditLogService $auditLog
    ) {
    }

    /**
     * @return Collection<int, ClosingChecklist>
     */
    public function ensurePeriodTasks(AccountingPeriod $period): Collection
    {
        if (! Schema::hasTable('closing_checklists')) {
            return collect();
        }

        foreach (PeriodCloseTaskCatalog::definitions() as $definition) {
            ClosingChecklist::query()->firstOrCreate(
                [
                    'company_id' => $period->company_id,
                    'period_id' => $period->id,
                    'task_key' => $definition['key'],
                ],
                [
                    'task_name' => $definition['name'],
                    'task_type' => $definition['type'],
                    'is_required' => (bool) $definition['required'],
                    'status' => 'pending',
                ]
            );
        }

        return $this->periodItems($period);
    }

    public function ensureCompanyTasks(?int $companyId = null): void
    {
        if (! Schema::hasTable('accounting_periods')) {
            return;
        }

        AccountingPeriod::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->get()
            ->each(fn (AccountingPeriod $period) => $this->ensurePeriodTasks($period));
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(AccountingPeriod $period): array
    {
        $this->ensurePeriodTasks($period);

        $items = $this->periodItems($period);

        foreach ($items->where('task_type', 'system') as $item) {
            $result = $this->evaluateSystemTask($period, (string) $item->task_key);

            $item->forceFill([
                'status' => $result['status'],
                'notes' => $result['message'] ?? null,
                'result_payload' => $result['payload'] ?? null,
                'completed_at' => $result['status'] === 'complete' ? now() : null,
                'completed_by' => null,
            ])->save();
        }

        $items = $this->periodItems($period);
        $required = $items->where('is_required', true);
        $isReady = $required->every(fn (ClosingChecklist $item) => $item->status === 'complete');
        $exceptions = $this->exceptions($items);

        return [
            'items' => $items,
            'summary' => [
                'required_total' => $required->count(),
                'completed_total' => $required->where('status', 'complete')->count(),
                'failed_total' => $required->where('status', 'failed')->count(),
                'pending_total' => $required->where('status', 'pending')->count(),
                'is_ready' => $isReady,
            ],
            'exceptions' => $exceptions,
        ];
    }

    public function completeManualTask(ClosingChecklist $item, int $actorId, ?string $notes = null): ClosingChecklist
    {
        if ($item->task_type !== 'manual') {
            throw ValidationException::withMessages([
                'task' => __('System checklist tasks are read-only.'),
            ]);
        }

        $item->forceFill([
            'status' => 'complete',
            'notes' => $notes,
            'completed_at' => now(),
            'completed_by' => $actorId,
        ])->save();

        $this->auditLog->log('closing_checklist.completed', $actorId, $item, [
            'task_key' => $item->task_key,
            'period_id' => (int) $item->period_id,
        ], (int) $item->company_id);

        return $item->fresh();
    }

    public function resetManualTask(ClosingChecklist $item, int $actorId, ?string $notes = null): ClosingChecklist
    {
        if ($item->task_type !== 'manual') {
            throw ValidationException::withMessages([
                'task' => __('System checklist tasks are read-only.'),
            ]);
        }

        $item->forceFill([
            'status' => 'pending',
            'notes' => $notes,
            'completed_at' => null,
            'completed_by' => null,
        ])->save();

        $this->auditLog->log('closing_checklist.reset', $actorId, $item, [
            'task_key' => $item->task_key,
            'period_id' => (int) $item->period_id,
        ], (int) $item->company_id);

        return $item->fresh();
    }

    /**
     * @return Collection<int, ClosingChecklist>
     */
    private function periodItems(AccountingPeriod $period): Collection
    {
        return ClosingChecklist::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->orderByRaw("case when task_type = 'system' then 0 else 1 end")
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateSystemTask(AccountingPeriod $period, string $taskKey): array
    {
        return match ($taskKey) {
            'bank_accounts_reconciled' => $this->checkBankAccountsReconciled($period),
            'no_open_bank_reconciliation_runs' => $this->checkNoOpenBankReconciliationRuns($period),
            'no_unposted_gl_batches' => $this->checkNoUnpostedGlBatches($period),
            'no_draft_manual_journals' => $this->checkNoDraftManualJournals($period),
            'no_draft_ap_bills' => $this->checkNoDraftApBills($period),
            'no_draft_expenses' => $this->checkNoDraftExpenses($period),
            'no_pending_expense_approvals' => $this->checkNoPendingExpenseApprovals($period),
            'no_ap_documents_missing_dimensions' => $this->checkNoApDocumentsMissingDimensions($period),
            'no_unbalanced_gl_batches' => $this->checkNoUnbalancedGlBatches($period),
            default => ['status' => 'pending', 'message' => __('No evaluator found for task :task.', ['task' => $taskKey]), 'payload' => null],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function exceptions(Collection $items): array
    {
        return $items
            ->filter(fn (ClosingChecklist $item) => $item->status === 'failed')
            ->map(function (ClosingChecklist $item) {
                $payload = (array) ($item->result_payload ?? []);

                return [
                    'task_key' => $item->task_key,
                    'task_name' => $item->task_name,
                    'message' => $item->notes,
                    'count' => (int) ($payload['count'] ?? 0),
                    'details' => $payload['details'] ?? [],
                    'route' => $payload['route'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function checkBankAccountsReconciled(AccountingPeriod $period): array
    {
        if (! Schema::hasTable('bank_accounts') || ! Schema::hasTable('bank_reconciliation_runs')) {
            return ['status' => 'complete', 'message' => __('Banking tables unavailable.'), 'payload' => ['count' => 0]];
        }

        $accounts = BankAccount::query()
            ->where('company_id', $period->company_id)
            ->where('is_active', true)
            ->get();

        if ($accounts->isEmpty()) {
            return ['status' => 'complete', 'message' => __('No active bank accounts to reconcile.'), 'payload' => ['count' => 0]];
        }

        $missing = [];
        foreach ($accounts as $account) {
            $hasCompleted = BankReconciliationRun::query()
                ->where('bank_account_id', $account->id)
                ->where('status', 'completed')
                ->whereDate('statement_date', '>=', $period->end_date->toDateString())
                ->whereRaw('ABS(COALESCE(variance_amount, 0)) < 0.01')
                ->exists();

            if (! $hasCompleted) {
                $missing[] = [
                    'bank_account_id' => (int) $account->id,
                    'label' => $account->name,
                ];
            }
        }

        if ($missing === []) {
            return ['status' => 'complete', 'message' => __('All active bank accounts are reconciled through period end.'), 'payload' => ['count' => 0]];
        }

        return [
            'status' => 'failed',
            'message' => __('One or more active bank accounts are not reconciled through period end.'),
            'payload' => [
                'count' => count($missing),
                'details' => $missing,
                'route' => route('accounting.banking'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkNoOpenBankReconciliationRuns(AccountingPeriod $period): array
    {
        if (! Schema::hasTable('bank_reconciliation_runs')) {
            return ['status' => 'complete', 'message' => __('No bank reconciliation table found.'), 'payload' => ['count' => 0]];
        }

        $runs = BankReconciliationRun::query()
            ->where('company_id', $period->company_id)
            ->where('period_id', $period->id)
            ->whereIn('status', ['draft', 'review'])
            ->get(['id', 'bank_account_id', 'status']);

        if ($runs->isEmpty()) {
            return ['status' => 'complete', 'message' => __('No open bank reconciliation runs remain.'), 'payload' => ['count' => 0]];
        }

        return [
            'status' => 'failed',
            'message' => __('There are open bank reconciliation runs that must be resolved.'),
            'payload' => [
                'count' => $runs->count(),
                'details' => $runs->map(fn (BankReconciliationRun $run) => ['id' => (int) $run->id, 'label' => 'Run #'.$run->id])->all(),
                'route' => route('accounting.banking'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkNoUnpostedGlBatches(AccountingPeriod $period): array
    {
        if (! Schema::hasTable('gl_batches')) {
            return ['status' => 'complete', 'message' => __('No GL batch table found.'), 'payload' => ['count' => 0]];
        }

        $batches = GlBatch::query()
            ->where('company_id', $period->company_id)
            ->where(function ($query) use ($period) {
                $query->where('period_id', $period->id)
                    ->orWhere(function ($range) use ($period) {
                        $range->whereDate('period_start', '>=', $period->start_date->toDateString())
                            ->whereDate('period_end', '<=', $period->end_date->toDateString());
                    });
            })
            ->where('status', 'open')
            ->get(['id', 'period_end']);

        if ($batches->isEmpty()) {
            return ['status' => 'complete', 'message' => __('No unposted GL batches remain.'), 'payload' => ['count' => 0]];
        }

        return [
            'status' => 'failed',
            'message' => __('Unposted GL batches remain for this period.'),
            'payload' => [
                'count' => $batches->count(),
                'details' => $batches->map(fn (GlBatch $batch) => ['id' => (int) $batch->id, 'label' => 'Batch #'.$batch->id])->all(),
                'route' => route('ledger.batches.index'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkNoDraftManualJournals(AccountingPeriod $period): array
    {
        if (! Schema::hasTable('journal_entries')) {
            return ['status' => 'complete', 'message' => __('No journal table found.'), 'payload' => ['count' => 0]];
        }

        $journals = JournalEntry::query()
            ->where('company_id', $period->company_id)
            ->whereDate('entry_date', '>=', $period->start_date->toDateString())
            ->whereDate('entry_date', '<=', $period->end_date->toDateString())
            ->where('status', 'draft')
            ->get(['id', 'entry_number']);

        if ($journals->isEmpty()) {
            return ['status' => 'complete', 'message' => __('No draft journals remain.'), 'payload' => ['count' => 0]];
        }

        return [
            'status' => 'failed',
            'message' => __('Draft manual journals remain in this period.'),
            'payload' => [
                'count' => $journals->count(),
                'details' => $journals->map(fn (JournalEntry $journal) => ['id' => (int) $journal->id, 'label' => $journal->entry_number])->all(),
                'route' => route('accounting.journals'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkNoDraftApBills(AccountingPeriod $period): array
    {
        if (! Schema::hasTable('ap_invoices')) {
            return ['status' => 'complete', 'message' => __('No AP invoice table found.'), 'payload' => ['count' => 0]];
        }

        $rows = DB::table('ap_invoices')
            ->where('company_id', $period->company_id)
            ->where('is_expense', false)
            ->where('status', 'draft')
            ->whereDate('invoice_date', '>=', $period->start_date->toDateString())
            ->whereDate('invoice_date', '<=', $period->end_date->toDateString())
            ->select(['id', 'invoice_number'])
            ->get();

        if ($rows->isEmpty()) {
            return ['status' => 'complete', 'message' => __('No draft AP bills remain.'), 'payload' => ['count' => 0]];
        }

        return [
            'status' => 'failed',
            'message' => __('Draft vendor bills remain in this period.'),
            'payload' => [
                'count' => $rows->count(),
                'details' => $rows->map(fn (object $row) => ['id' => (int) $row->id, 'label' => $row->invoice_number])->all(),
                'route' => route('payables.index', ['tab' => 'bills']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkNoDraftExpenses(AccountingPeriod $period): array
    {
        if (! Schema::hasTable('ap_invoices')) {
            return ['status' => 'complete', 'message' => __('No AP invoice table found.'), 'payload' => ['count' => 0]];
        }

        $rows = DB::table('ap_invoices')
            ->where('company_id', $period->company_id)
            ->where('is_expense', true)
            ->where('status', 'draft')
            ->whereDate('invoice_date', '>=', $period->start_date->toDateString())
            ->whereDate('invoice_date', '<=', $period->end_date->toDateString())
            ->select(['id', 'invoice_number'])
            ->get();

        if ($rows->isEmpty()) {
            return ['status' => 'complete', 'message' => __('No draft expenses remain.'), 'payload' => ['count' => 0]];
        }

        return [
            'status' => 'failed',
            'message' => __('Draft expenses or reimbursements remain in this period.'),
            'payload' => [
                'count' => $rows->count(),
                'details' => $rows->map(fn (object $row) => ['id' => (int) $row->id, 'label' => $row->invoice_number])->all(),
                'route' => route('payables.index', ['tab' => 'expenses']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkNoPendingExpenseApprovals(AccountingPeriod $period): array
    {
        if (! Schema::hasTable('expense_profiles') || ! Schema::hasTable('ap_invoices')) {
            return ['status' => 'complete', 'message' => __('Expense approval tables unavailable.'), 'payload' => ['count' => 0]];
        }

        $rows = DB::table('expense_profiles as ep')
            ->join('ap_invoices as ai', 'ai.id', '=', 'ep.invoice_id')
            ->where('ai.company_id', $period->company_id)
            ->whereDate('ai.invoice_date', '>=', $period->start_date->toDateString())
            ->whereDate('ai.invoice_date', '<=', $period->end_date->toDateString())
            ->whereIn('ep.approval_status', ['submitted', 'manager_approved'])
            ->select(['ai.id', 'ai.invoice_number', 'ep.approval_status'])
            ->get();

        if ($rows->isEmpty()) {
            return ['status' => 'complete', 'message' => __('No pending expense approvals remain.'), 'payload' => ['count' => 0]];
        }

        return [
            'status' => 'failed',
            'message' => __('Pending expense approvals must be completed before close.'),
            'payload' => [
                'count' => $rows->count(),
                'details' => $rows->map(fn (object $row) => ['id' => (int) $row->id, 'label' => $row->invoice_number.' ('.$row->approval_status.')'])->all(),
                'route' => route('payables.index', ['tab' => 'approvals']),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkNoApDocumentsMissingDimensions(AccountingPeriod $period): array
    {
        if (! Schema::hasTable('ap_invoices')) {
            return ['status' => 'complete', 'message' => __('No AP invoice table found.'), 'payload' => ['count' => 0]];
        }

        $rows = DB::table('ap_invoices')
            ->where(function ($query) use ($period) {
                $query->where('company_id', $period->company_id)
                    ->orWhereNull('company_id');
            })
            ->whereDate('invoice_date', '>=', $period->start_date->toDateString())
            ->whereDate('invoice_date', '<=', $period->end_date->toDateString())
            ->where(function ($query) {
                $query->whereNull('company_id')->orWhereNull('period_id');
            })
            ->select(['id', 'invoice_number'])
            ->get();

        if ($rows->isEmpty()) {
            return ['status' => 'complete', 'message' => __('All AP documents have company and period assignment.'), 'payload' => ['count' => 0]];
        }

        return [
            'status' => 'failed',
            'message' => __('Some AP documents are missing company or period assignment.'),
            'payload' => [
                'count' => $rows->count(),
                'details' => $rows->map(fn (object $row) => ['id' => (int) $row->id, 'label' => $row->invoice_number])->all(),
                'route' => route('payables.index'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkNoUnbalancedGlBatches(AccountingPeriod $period): array
    {
        if (! Schema::hasTable('gl_batches') || ! Schema::hasTable('gl_batch_lines')) {
            return ['status' => 'complete', 'message' => __('No GL batch tables found.'), 'payload' => ['count' => 0]];
        }

        $rows = GlBatch::query()
            ->with('lines')
            ->where('company_id', $period->company_id)
            ->where(function ($query) use ($period) {
                $query->where('period_id', $period->id)
                    ->orWhere(function ($range) use ($period) {
                        $range->whereDate('period_start', '>=', $period->start_date->toDateString())
                            ->whereDate('period_end', '<=', $period->end_date->toDateString());
                    });
            })
            ->get()
            ->filter(function (GlBatch $batch) {
                $debits = round((float) $batch->lines->sum('debit_total'), 4);
                $credits = round((float) $batch->lines->sum('credit_total'), 4);

                return abs($debits - $credits) > 0.0001;
            })
            ->values();

        if ($rows->isEmpty()) {
            return ['status' => 'complete', 'message' => __('All GL batches are balanced.'), 'payload' => ['count' => 0]];
        }

        return [
            'status' => 'failed',
            'message' => __('One or more GL batches are out of balance.'),
            'payload' => [
                'count' => $rows->count(),
                'details' => $rows->map(fn (GlBatch $batch) => ['id' => (int) $batch->id, 'label' => 'Batch #'.$batch->id])->all(),
                'route' => route('ledger.batches.index'),
            ],
        ];
    }
}
