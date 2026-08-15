<?php

namespace App\Services\HR;

use App\Models\AccountingCompany;
use App\Models\HrPayrollPaymentBatch;
use App\Models\HrPayrollRun;
use App\Models\HrPayrollStatusEvent;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollRunService
{
    public function __construct(
        protected PayrollCalculationService $calculator,
        protected PayrollPostingService $posting,
        protected PayrollPaymentService $payments,
        protected HrAuditLogService $audit,
        protected HrAccessService $access,
        protected AccountingContextService $accountingContext,
        protected HrNumberService $numbers,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, User $actor): HrPayrollRun
    {
        $companyId = (int) ($attributes['company_id'] ?? 0);
        $this->access->assertPayrollCompany($actor, $companyId, 'hr.payroll.prepare');
        $start = Carbon::parse($attributes['pay_period_start'] ?? null)->startOfDay();
        $end = Carbon::parse($attributes['pay_period_end'] ?? null)->startOfDay();
        if (! $start->isStartOfMonth() || ! $end->isEndOfMonth() || ! $start->isSameMonth($end)) {
            throw ValidationException::withMessages(['pay_period' => __('A Phase 1 payroll run must cover one complete calendar month.')]);
        }

        return DB::transaction(function () use ($attributes, $actor, $companyId, $start, $end): HrPayrollRun {
            AccountingCompany::query()->lockForUpdate()->findOrFail($companyId);
            $duplicate = HrPayrollRun::query()->where('company_id', $companyId)->where('origin', 'native')
                ->whereNotIn('status', ['rejected', 'reversed'])
                ->whereDate('pay_period_start', '<=', $end->toDateString())
                ->whereDate('pay_period_end', '>=', $start->toDateString())
                ->lockForUpdate()->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['pay_period' => __('An active native payroll run already covers this month.')]);
            }
            if (array_key_exists('run_number', $attributes)) {
                throw ValidationException::withMessages(['run_number' => __('Payroll run numbers are system assigned.')]);
            }
            $number = $this->nextRunNumber($companyId, $start);
            $run = HrPayrollRun::query()->create([
                ...$attributes, 'company_id' => $companyId, 'run_number' => $number,
                'period_id' => $this->accountingContext->resolvePeriodId($end->toDateString(), $companyId),
                'pay_period_start' => $start->toDateString(), 'pay_period_end' => $end->toDateString(),
                'currency' => $attributes['currency'] ?? config('hr.currency', 'QAR'), 'origin' => 'native',
                'status' => 'draft', 'is_postable' => true,
                'proration_divisor' => $attributes['proration_divisor'] ?? config('hr.proration_divisor', 30),
                'prepared_by' => $actor->id,
            ]);
            $this->event($run, null, 'draft', (int) $actor->id, null);
            $this->audit->log('hr.payroll.created', (int) $actor->id, $run, ['run_number' => $number], $companyId);

            return $run;
        });
    }

    public function calculate(HrPayrollRun $run, User $actor): HrPayrollRun
    {
        $this->access->assertPayroll($actor, $run, 'hr.payroll.prepare');

        return DB::transaction(function () use ($run, $actor): HrPayrollRun {
            $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->assertState($run, ['draft']);
            if ((int) $run->prepared_by !== (int) $actor->id && ! $actor->isAdmin()) {
                throw ValidationException::withMessages(['payroll' => __('Only the assigned preparer may calculate this run.')]);
            }
            $totals = $this->calculator->calculate($run);
            $run->forceFill(['status' => 'calculated', 'calculated_at' => now(), 'calculated_by' => $actor->id,
                'lock_version' => (int) $run->lock_version + 1])->save();
            $this->event($run, 'draft', 'calculated', (int) $actor->id, null, $totals);
            $this->audit->log('hr.payroll.calculated', (int) $actor->id, $run, $totals, (int) $run->company_id);

            return $run->fresh('results.components');
        });
    }

    public function approve(HrPayrollRun $run, User $actor, ?string $reason = null): HrPayrollRun
    {
        $this->access->assertPayroll($actor, $run, 'hr.payroll.approve');

        return DB::transaction(function () use ($run, $actor, $reason): HrPayrollRun {
            $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->assertState($run, ['calculated']);
            if (in_array((int) $actor->id, [(int) $run->prepared_by, (int) $run->calculated_by], true)) {
                throw ValidationException::withMessages(['approval' => __('The payroll approver must be different from the preparer.')]);
            }
            $run->adjustments()->whereNull('approved_at')->lockForUpdate()->get()->each(function ($adjustment) use ($actor): void {
                $adjustment->forceFill(['approved_at' => now(), 'approved_by' => $actor->id])->save();
            });
            $run->forceFill(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $actor->id,
                'lock_version' => (int) $run->lock_version + 1])->save();
            $this->event($run, 'calculated', 'approved', (int) $actor->id, $reason);
            $this->audit->log('hr.payroll.approved', (int) $actor->id, $run, ['reason' => $reason], (int) $run->company_id);

            return $run->fresh();
        });
    }

    public function post(HrPayrollRun $run, User $actor): HrPayrollRun
    {
        $this->access->assertPayroll($actor, $run, 'hr.payroll.post');

        return DB::transaction(function () use ($run, $actor): HrPayrollRun {
            $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($this->state($run) === 'posted') {
                return $run;
            }
            $this->assertState($run, ['approved']);
            $this->assertFinanceActor($run, (int) $actor->id);
            $journal = $this->posting->post($run, (int) $actor->id);
            $run->forceFill(['status' => 'posted', 'journal_entry_id' => $journal->id, 'posted_at' => now(),
                'posted_by' => $actor->id, 'lock_version' => (int) $run->lock_version + 1])->save();
            $this->event($run, 'approved', 'posted', (int) $actor->id, null, ['journal_entry_id' => (int) $journal->id]);
            $this->audit->log('hr.payroll.posted', (int) $actor->id, $run, ['journal_entry_id' => (int) $journal->id], (int) $run->company_id);

            return $run->fresh(['journalEntry', 'results']);
        });
    }

    public function pay(HrPayrollRun $run, int $bankAccountId, string $paymentDate, ?string $reference, User $actor): HrPayrollRun
    {
        $this->access->assertPayroll($actor, $run, 'hr.payroll.pay');

        return DB::transaction(function () use ($run, $bankAccountId, $paymentDate, $reference, $actor): HrPayrollRun {
            $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($this->state($run) === 'paid') {
                return $run;
            }
            $this->assertState($run, ['posted']);
            $this->assertFinanceActor($run, (int) $actor->id);
            $batch = $this->payments->pay($run, $bankAccountId, $paymentDate, $reference, $actor);
            $run->forceFill(['status' => 'paid', 'paid_at' => now(), 'paid_by' => $actor->id,
                'lock_version' => (int) $run->lock_version + 1])->save();
            $this->event($run, 'posted', 'paid', (int) $actor->id, null, ['payment_batch_id' => (int) $batch->id]);
            $this->audit->log('hr.payroll.paid', (int) $actor->id, $run, ['payment_batch_id' => (int) $batch->id], (int) $run->company_id);

            return $run->fresh('paymentBatches');
        });
    }

    public function reject(HrPayrollRun $run, User $actor, string $reason): HrPayrollRun
    {
        $this->access->assertPayroll($actor, $run, 'hr.payroll.approve');

        return DB::transaction(function () use ($run, $actor, $reason): HrPayrollRun {
            $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            $from = $this->state($run);
            $this->assertState($run, ['draft', 'calculated', 'approved']);
            $run->forceFill(['status' => 'rejected', 'rejected_at' => now(), 'rejected_by' => $actor->id,
                'lock_version' => (int) $run->lock_version + 1])->save();
            $this->event($run, $from, 'rejected', (int) $actor->id, $reason);
            $this->audit->log('hr.payroll.rejected', (int) $actor->id, $run, ['reason' => $reason], (int) $run->company_id);

            return $run->fresh();
        });
    }

    public function reverse(HrPayrollRun $run, User $actor, string $date, string $reason): HrPayrollRun
    {
        $this->access->assertPayroll($actor, $run, 'hr.payroll.reverse');

        return DB::transaction(function () use ($run, $actor, $date, $reason): HrPayrollRun {
            $run = HrPayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($this->state($run) === 'reversed') {
                return $run;
            }
            $from = $this->state($run);
            $this->assertState($run, ['posted', 'paid']);
            if ($from === 'paid') {
                $batch = HrPayrollPaymentBatch::query()->where('payroll_run_id', $run->id)->where('status', 'processed')->firstOrFail();
                $this->payments->reverse($batch, $actor, $date, $reason);
            }
            $reversal = $this->posting->reverse($run, (int) $actor->id, $date, $reason);
            $run->forceFill(['status' => 'reversed', 'reversal_journal_entry_id' => $reversal->id,
                'reversed_at' => now(), 'reversed_by' => $actor->id, 'lock_version' => (int) $run->lock_version + 1])->save();
            $this->event($run, $from, 'reversed', (int) $actor->id, $reason, ['reversal_journal_entry_id' => (int) $reversal->id]);
            $this->audit->log('hr.payroll.reversed', (int) $actor->id, $run, ['reason' => $reason], (int) $run->company_id);

            return $run->fresh(['reversalJournalEntry', 'paymentBatches']);
        });
    }

    private function assertFinanceActor(HrPayrollRun $run, int $actorId): void
    {
        if (in_array($actorId, [(int) $run->prepared_by, (int) $run->approved_by], true)) {
            throw ValidationException::withMessages(['payroll' => __('The finance poster/payer must differ from the preparer and approver.')]);
        }
    }

    /** @param array<int, string> $allowed */
    private function assertState(HrPayrollRun $run, array $allowed): void
    {
        if (! in_array($this->state($run), $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('Payroll action is not valid in the current state.')]);
        }
    }

    private function state(HrPayrollRun $run): string
    {
        return is_object($run->status) ? $run->status->value : (string) $run->status;
    }

    /** @param array<string, mixed> $metadata */
    private function event(HrPayrollRun $run, ?string $from, string $to, int $actorId, ?string $reason, array $metadata = []): void
    {
        HrPayrollStatusEvent::query()->create(['company_id' => $run->company_id, 'payroll_run_id' => $run->id,
            'from_status' => $from, 'to_status' => $to, 'actor_id' => $actorId, 'reason' => $reason, 'metadata' => $metadata]);
    }

    private function nextRunNumber(int $companyId, Carbon $start): string
    {
        return $this->numbers->payrollRun($companyId, $start);
    }
}
