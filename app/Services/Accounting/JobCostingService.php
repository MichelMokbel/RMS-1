<?php

namespace App\Services\Accounting;

use App\Models\Job;
use App\Models\JobBudget;
use App\Models\JobCostCode;
use App\Models\JobPhase;
use App\Models\JobTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JobCostingService
{
    public function __construct(
        protected AccountingContextService $context,
        protected AccountingAuditLogService $auditLog
    ) {
    }

    public function createJob(array $data, int $actorId): Job
    {
        $companyId = (int) ($data['company_id'] ?? 0) ?: $this->context->defaultCompanyId();

        $job = DB::transaction(function () use ($data, $companyId) {
            $job = Job::query()->create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'name' => $data['name'],
                'code' => $data['code'],
                'status' => $data['status'] ?? 'active',
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'estimated_revenue' => $data['estimated_revenue'] ?? 0,
                'estimated_cost' => $data['estimated_cost'] ?? 0,
                'notes' => $data['notes'] ?? null,
            ]);

            if (! empty($data['phase_name']) && ! empty($data['phase_code'])) {
                JobPhase::query()->create([
                    'job_id' => $job->id,
                    'name' => $data['phase_name'],
                    'code' => $data['phase_code'],
                    'status' => 'active',
                ]);
            }

            if (! empty($data['budget_amount'])) {
                JobBudget::query()->create([
                    'job_id' => $job->id,
                    'job_phase_id' => null,
                    'job_cost_code_id' => null,
                    'budget_amount' => round((float) $data['budget_amount'], 2),
                ]);
            }

            return $job;
        });

        $this->auditLog->log('job.created', $actorId, $job, [
            'code' => $job->code,
            'has_phase' => ! empty($data['phase_name']) && ! empty($data['phase_code']),
            'budget_amount' => round((float) ($data['budget_amount'] ?? 0), 2),
        ], $companyId);

        return $job->load(['phases', 'budgets']);
    }

    public function recordTransaction(Job $job, array $data, int $actorId): JobTransaction
    {
        return $this->recordTransactionForJob($job, $data, $actorId, null, null, false);
    }

    public function savePhase(Job $job, array $data, int $actorId, ?JobPhase $phase = null): JobPhase
    {
        $phase = $phase ?: new JobPhase();
        $phase->fill([
            'job_id' => $job->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'status' => $data['status'] ?? 'active',
        ]);
        $phase->save();

        $this->auditLog->log($phase->wasRecentlyCreated ? 'job_phase.created' : 'job_phase.updated', $actorId, $phase, [], (int) $job->company_id);

        return $phase->fresh();
    }

    public function closePhase(JobPhase $phase, int $actorId): JobPhase
    {
        $phase->status = 'closed';
        $phase->save();

        $this->auditLog->log('job_phase.closed', $actorId, $phase, [], (int) $phase->job?->company_id);

        return $phase->fresh();
    }

    public function saveCostCode(int $companyId, array $data, int $actorId, ?JobCostCode $costCode = null): JobCostCode
    {
        $costCode = $costCode ?: new JobCostCode();
        $costCode->fill([
            'company_id' => $companyId,
            'name' => $data['name'],
            'code' => $data['code'],
            'default_account_id' => $data['default_account_id'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $costCode->save();

        $this->auditLog->log($costCode->wasRecentlyCreated ? 'job_cost_code.created' : 'job_cost_code.updated', $actorId, $costCode, [], $companyId);

        return $costCode->fresh();
    }

    public function deactivateCostCode(JobCostCode $costCode, int $actorId): JobCostCode
    {
        $costCode->is_active = false;
        $costCode->save();

        $this->auditLog->log('job_cost_code.deactivated', $actorId, $costCode, [], (int) $costCode->company_id);

        return $costCode->fresh();
    }

    public function saveBudget(Job $job, array $data, int $actorId, ?JobBudget $budget = null): JobBudget
    {
        if (! empty($data['job_phase_id']) && ! $job->phases()->whereKey($data['job_phase_id'])->exists()) {
            throw ValidationException::withMessages([
                'job_phase_id' => __('The selected phase does not belong to this job.'),
            ]);
        }

        $budget = $budget ?: new JobBudget();
        $budget->fill([
            'job_id' => $job->id,
            'job_phase_id' => $data['job_phase_id'] ?? null,
            'job_cost_code_id' => $data['job_cost_code_id'] ?? null,
            'budget_amount' => round((float) ($data['budget_amount'] ?? 0), 2),
        ]);
        $budget->save();

        $this->auditLog->log($budget->wasRecentlyCreated ? 'job_budget.created' : 'job_budget.updated', $actorId, $budget, [], (int) $job->company_id);

        return $budget->fresh();
    }

    public function deleteBudget(JobBudget $budget, int $actorId): void
    {
        $companyId = (int) $budget->job?->company_id;
        $budgetId = (int) $budget->id;
        $budget->delete();

        $this->auditLog->log('job_budget.deleted', $actorId, null, [
            'job_budget_id' => $budgetId,
        ], $companyId);
    }

    public function recordSourceTransaction(
        Job $job,
        array $data,
        string $sourceType,
        int $sourceId,
        int $actorId
    ): JobTransaction {
        return $this->recordTransactionForJob($job, $data, $actorId, $sourceType, $sourceId, true);
    }

    private function recordTransactionForJob(
        Job $job,
        array $data,
        int $actorId,
        ?string $sourceType,
        ?int $sourceId,
        bool $sourceLinked
    ): JobTransaction {
        $phaseId = $data['job_phase_id'] ?? null;
        if ($phaseId && ! $job->phases()->whereKey($phaseId)->exists()) {
            throw ValidationException::withMessages([
                'job_phase_id' => __('The selected phase does not belong to this job.'),
            ]);
        }

        $transaction = JobTransaction::query()->create([
            'job_id' => $job->id,
            'job_phase_id' => $phaseId,
            'job_cost_code_id' => $data['job_cost_code_id'] ?? null,
            'company_id' => $job->company_id,
            'transaction_date' => $data['transaction_date'],
            'amount' => round((float) $data['amount'], 2),
            'transaction_type' => $data['transaction_type'],
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'memo' => $data['memo'] ?? null,
        ]);

        $this->auditLog->log($sourceLinked ? 'job_transaction.sourced' : 'job_transaction.recorded', $actorId, $transaction, [
            'job_id' => (int) $job->id,
            'transaction_type' => $transaction->transaction_type,
            'amount' => (float) $transaction->amount,
        ], (int) $job->company_id);

        return $transaction->load(['phase', 'costCode']);
    }

    public function profitability(Job $job): array
    {
        $job->loadMissing(['phases', 'budgets', 'transactions.costCode', 'transactions.phase']);

        $budgetTotal = (float) $job->budgets()->sum('budget_amount');
        $actualCost = (float) $job->transactions()
            ->whereIn('transaction_type', ['cost', 'adjustment'])
            ->sum('amount');
        $actualRevenue = (float) $job->transactions()
            ->where('transaction_type', 'revenue')
            ->sum('amount');

        $phaseBreakdown = $job->transactions()
            ->selectRaw('job_phase_id, transaction_type, SUM(amount) as total_amount')
            ->groupBy('job_phase_id', 'transaction_type')
            ->get()
            ->groupBy('job_phase_id')
            ->map(function ($rows, $phaseId) use ($job) {
                $phase = $job->phases->firstWhere('id', $phaseId);
                $cost = (float) $rows->whereIn('transaction_type', ['cost', 'adjustment'])->sum('total_amount');
                $revenue = (float) $rows->where('transaction_type', 'revenue')->sum('total_amount');

                return [
                    'phase_id' => $phaseId ? (int) $phaseId : null,
                    'phase_name' => $phase?->name ?? __('Unassigned'),
                    'cost_total' => round($cost, 2),
                    'revenue_total' => round($revenue, 2),
                    'margin_total' => round($revenue - $cost, 2),
                ];
            })
            ->values()
            ->all();

        $costCodeBreakdown = $job->transactions()
            ->selectRaw('job_cost_code_id, transaction_type, SUM(amount) as total_amount')
            ->groupBy('job_cost_code_id', 'transaction_type')
            ->get()
            ->groupBy('job_cost_code_id')
            ->map(function ($rows, $costCodeId) use ($job) {
                $costCode = $job->transactions->pluck('costCode')->filter()->firstWhere('id', $costCodeId)
                    ?? JobCostCode::query()->find($costCodeId);
                $cost = (float) $rows->whereIn('transaction_type', ['cost', 'adjustment'])->sum('total_amount');
                $revenue = (float) $rows->where('transaction_type', 'revenue')->sum('total_amount');
                $budget = (float) $job->budgets->where('job_cost_code_id', $costCodeId)->sum('budget_amount');

                return [
                    'job_cost_code_id' => $costCodeId ? (int) $costCodeId : null,
                    'cost_code' => $costCode?->code ?? __('Unassigned'),
                    'cost_code_name' => $costCode?->name,
                    'budget_total' => round($budget, 2),
                    'cost_total' => round($cost, 2),
                    'revenue_total' => round($revenue, 2),
                    'margin_total' => round($revenue - $cost, 2),
                ];
            })
            ->values()
            ->all();

        $budgetBreakdown = $job->budgets
            ->groupBy(fn (JobBudget $budget) => implode(':', [$budget->job_phase_id ?: 0, $budget->job_cost_code_id ?: 0]))
            ->map(function ($rows) {
                /** @var JobBudget $sample */
                $sample = $rows->first();

                return [
                    'phase_name' => $sample->phase?->name ?? __('Unassigned'),
                    'cost_code' => $sample->costCode?->code ?? __('Unassigned'),
                    'budget_total' => round((float) $rows->sum('budget_amount'), 2),
                ];
            })
            ->values()
            ->all();

        $transactionRows = $job->transactions
            ->sortByDesc('transaction_date')
            ->map(function (JobTransaction $transaction) {
                [$sourceRoute, $sourceRouteParams] = match ($transaction->source_type) {
                    \App\Models\ApInvoice::class => ['payables.invoices.show', [$transaction->source_id]],
                    \App\Models\ArInvoice::class => ['invoices.show', [$transaction->source_id]],
                    \App\Models\PurchaseOrder::class => ['purchase-orders.show', [$transaction->source_id]],
                    default => [null, []],
                };

                return [
                    'id' => (int) $transaction->id,
                    'transaction_date' => optional($transaction->transaction_date)->toDateString(),
                    'transaction_type' => $transaction->transaction_type,
                    'amount' => round((float) $transaction->amount, 2),
                    'phase_name' => $transaction->phase?->name,
                    'cost_code' => $transaction->costCode?->code,
                    'source_type' => $transaction->source_type,
                    'source_id' => $transaction->source_id,
                    'source_route' => $sourceRoute,
                    'source_route_params' => $sourceRouteParams,
                    'memo' => $transaction->memo,
                    'is_source_linked' => $transaction->source_type !== null && $transaction->source_id !== null,
                ];
            })
            ->values()
            ->all();

        return [
            'job_id' => (int) $job->id,
            'job_code' => $job->code,
            'job_name' => $job->name,
            'status' => $job->status,
            'estimated_revenue' => round((float) $job->estimated_revenue, 2),
            'estimated_cost' => round((float) $job->estimated_cost, 2),
            'budget_total' => round($budgetTotal, 2),
            'actual_cost' => round($actualCost, 2),
            'actual_revenue' => round($actualRevenue, 2),
            'actual_margin' => round($actualRevenue - $actualCost, 2),
            'budget_variance' => round($actualCost - $budgetTotal, 2),
            'phase_breakdown' => $phaseBreakdown,
            'cost_code_breakdown' => $costCodeBreakdown,
            'budget_breakdown' => $budgetBreakdown,
            'transactions' => $transactionRows,
        ];
    }
}
