<?php

namespace App\Services\Accounting;

use App\Models\Job;
use App\Models\JobBudget;
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
            'source_type' => null,
            'source_id' => null,
            'memo' => $data['memo'] ?? null,
        ]);

        $this->auditLog->log('job_transaction.recorded', $actorId, $transaction, [
            'job_id' => (int) $job->id,
            'transaction_type' => $transaction->transaction_type,
            'amount' => (float) $transaction->amount,
        ], (int) $job->company_id);

        return $transaction->load(['phase', 'costCode']);
    }

    public function profitability(Job $job): array
    {
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
        ];
    }
}
