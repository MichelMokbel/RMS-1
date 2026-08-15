<?php

namespace App\Services\HR;

use App\Models\HrPayrollRun;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\LedgerAccountMappingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollPostingService
{
    public function __construct(
        protected LedgerAccountMappingService $mappings,
        protected JournalEntryService $journals,
    ) {}

    public function post(HrPayrollRun $run, int $actorId): JournalEntry
    {
        return DB::transaction(function () use ($run, $actorId): JournalEntry {
            $run = HrPayrollRun::query()->with('results.components')->lockForUpdate()->findOrFail($run->id);
            $this->assertPostable($run);
            $existing = JournalEntry::query()->where('source_type', HrPayrollRun::class)->where('source_id', $run->id)
                ->where('entry_type', 'payroll')->first();
            if ($existing?->status === 'posted') {
                return $existing;
            }

            $companyId = (int) $run->company_id;
            $salaryAccount = $this->requiredAccount('payroll.salary_expense', $companyId);
            $allowanceAccount = $this->requiredAccount('payroll.allowance_expense', $companyId);
            $payableAccount = $this->requiredAccount('payroll.payable', $companyId);
            $hasExternalDeductions = $run->results->flatMap->components->contains(fn ($component) => $component->category === 'deduction'
                && ! $this->isUnpaidLeave($component->code) && ! $this->isAdvance($component->code));
            $hasAdvances = $run->results->flatMap->components->contains(fn ($component) => $component->category === 'deduction' && $this->isAdvance($component->code));
            $deductionAccount = $hasExternalDeductions ? $this->requiredAccount('payroll.deduction_liability', $companyId) : null;
            $advanceAccount = $hasAdvances ? $this->requiredAccount('payroll.employee_advance', $companyId) : null;

            $lines = [];
            foreach ($run->results->groupBy(fn ($result) => ($result->branch_id ?: 0).':'.($result->department_id ?: 0)) as $results) {
                $first = $results->first();
                $dimension = ['branch_id' => $first->branch_id, 'department_id' => $first->department_id, 'job_id' => null];
                $basic = (int) $results->sum('basic_minor');
                $gross = (int) $results->sum('gross_minor');
                $deductionComponents = $results->flatMap->components->filter(fn ($component) => in_array($component->category, ['deduction'], true));
                $unpaid = (int) $deductionComponents->filter(fn ($component) => $this->isUnpaidLeave($component->code))->sum('amount_minor');
                $advances = (int) $deductionComponents->filter(fn ($component) => $this->isAdvance($component->code))->sum('amount_minor');
                $deductions = max((int) $results->sum('deductions_minor') - $unpaid - $advances, 0);
                $net = (int) $results->sum('net_minor');
                $recognizedGross = max($gross - $unpaid, 0);
                $recognizedBasic = $gross > 0 ? (int) round($recognizedGross * ($basic / $gross), 0, PHP_ROUND_HALF_UP) : 0;
                $recognizedAllowances = $recognizedGross - $recognizedBasic;
                if ($recognizedBasic > 0) {
                    $lines[] = $this->line($salaryAccount, $recognizedBasic, 0, $dimension, 'Payroll basic salary');
                }
                if ($recognizedAllowances > 0) {
                    $lines[] = $this->line($allowanceAccount, $recognizedAllowances, 0, $dimension, 'Payroll allowances and earnings');
                }
                if ($deductions > 0) {
                    $lines[] = $this->line($deductionAccount, 0, $deductions, $dimension, 'Payroll deductions payable');
                }
                if ($advances > 0) {
                    $lines[] = $this->line($advanceAccount, 0, $advances, $dimension, 'Employee advance recoveries');
                }
                if ($net > 0) {
                    $lines[] = $this->line($payableAccount, 0, $net, $dimension, 'Net payroll payable');
                }
            }

            if ($existing) {
                $journal = $this->journals->saveDraft($this->payload($run, $companyId, $lines), $actorId, $existing);
            } else {
                $journal = $this->journals->saveDraft($this->payload($run, $companyId, $lines), $actorId);
                $journal->forceFill(['source_type' => HrPayrollRun::class, 'source_id' => $run->id])->save();
            }

            return $this->journals->post($journal, $actorId);
        });
    }

    public function reverse(HrPayrollRun $run, int $actorId, string $date, string $reason): JournalEntry
    {
        $journal = JournalEntry::query()->findOrFail($run->journal_entry_id);

        return $this->journals->reverse($journal, $actorId, $date, __('Payroll reversal: :reason', ['reason' => $reason]));
    }

    private function assertPostable(HrPayrollRun $run): void
    {
        $origin = is_object($run->origin) ? $run->origin->value : $run->origin;
        if (! $run->is_postable || $origin !== 'native') {
            throw ValidationException::withMessages(['payroll' => __('Migrated payroll is read-only and cannot be posted.')]);
        }
        if ($run->results->isEmpty() || $run->results->contains(fn ($result) => ! $result->is_postable)) {
            throw ValidationException::withMessages(['payroll' => __('This payroll does not contain postable calculated results.')]);
        }
    }

    private function requiredAccount(string $key, int $companyId): int
    {
        $id = $this->mappings->resolveAccountId($key, $companyId);
        if (! $id) {
            throw ValidationException::withMessages(['account_mappings' => __('Missing payroll account mapping: :key.', ['key' => $key])]);
        }

        return $id;
    }

    /** @param array<string, mixed> $dimension @return array<string, mixed> */
    private function line(int $accountId, int $debitMinor, int $creditMinor, array $dimension, string $memo): array
    {
        return [...$dimension, 'account_id' => $accountId, 'debit' => $debitMinor / 100, 'credit' => $creditMinor / 100, 'memo' => $memo];
    }

    /** @param array<int, array<string, mixed>> $lines @return array<string, mixed> */
    private function payload(HrPayrollRun $run, int $companyId, array $lines): array
    {
        return ['company_id' => $companyId, 'entry_type' => 'payroll', 'entry_date' => optional($run->pay_period_end)->toDateString(),
            'memo' => __('Aggregate payroll :run', ['run' => $run->run_number]), 'lines' => $lines];
    }

    private function isUnpaidLeave(mixed $code): bool
    {
        return strtoupper((string) $code) === 'UNPAID_LEAVE';
    }

    private function isAdvance(mixed $code): bool
    {
        $code = strtoupper((string) $code);

        return str_contains($code, 'ADVANCE') || str_contains($code, 'LOAN') || str_contains($code, 'RECOVERY');
    }
}
