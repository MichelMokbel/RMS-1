<?php

namespace App\Services\HR\Imports;

use App\Models\AccountingCompany;
use App\Models\HrCompensationPackage;
use App\Models\HrEmployee;
use App\Models\HrEmployeeAssignment;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use App\Models\HrLeaveLedgerEntry;
use App\Models\HrLeaveRequest;
use App\Models\HrLeaveRequestEvent;
use App\Models\HrPayrollResult;
use App\Models\HrPayrollResultComponent;
use App\Models\HrPayrollRun;
use App\Models\User;
use App\Services\HR\CompensationService;
use App\Services\HR\EmployeeService;
use App\Services\HR\HrAccessService;
use App\Services\HR\HrAuditLogService;
use App\Services\HR\HrNumberService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class FullHistoryCommitter
{
    public function __construct(
        protected EmployeeService $employees,
        protected CompensationService $compensation,
        protected HrNumberService $numbers,
        protected HrAccessService $access,
        protected HrAuditLogService $audit,
    ) {}

    public function commit(HrImportBatch $batch, User $actor): HrImportBatch
    {
        $this->access->assertCompany($actor, (int) $batch->company_id, 'hr.imports.manage');
        try {
            return DB::transaction(function () use ($batch, $actor): HrImportBatch {
                $batch = HrImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                if ($this->value($batch->status) === 'completed') {
                    return $batch->fresh('rows');
                }
                if ($this->value($batch->status) !== 'ready' || $batch->rows()->where('status', 'invalid')->exists()) {
                    throw ValidationException::withMessages(['import' => __('Only a fully validated consolidated import can be committed.')]);
                }
                AccountingCompany::query()->lockForUpdate()->findOrFail($batch->company_id);
                $batch->forceFill(['status' => 'committing', 'failed_at' => null, 'failure_reason' => null])->save();
                $rows = $batch->rows()->where('status', 'valid')->orderBy('row_number')->lockForUpdate()->get()->groupBy(fn ($row) => $row->payload['_sheet']);

                $employeeMap = $this->commitEmployees($batch, $rows->get('employees', collect()), $actor);
                $this->commitAssignments($batch, $rows->get('assignments', collect()), $employeeMap, $actor);
                $this->commitCompensation($rows->get('compensation', collect()), $rows->get('compensation_components', collect()), $employeeMap, $actor);
                $this->commitLeaveBalances($batch, $rows->get('leave_balances', collect()), $employeeMap, $actor);
                $this->commitLeaveHistory($batch, $rows->get('leave_history', collect()), $employeeMap, $actor);
                $this->commitPayroll($batch, $rows->get('payroll_history', collect()), $rows->get('payroll_components', collect()), $employeeMap, $actor);

                $batch->rows()->where('status', 'valid')->update(['status' => 'committed', 'updated_at' => now()]);
                $batch->forceFill(['status' => 'completed', 'committed_by' => $actor->id, 'committed_at' => now()])->save();
                $this->audit->log('hr.import.full_history_committed', (int) $actor->id, $batch, ['rows' => $batch->rows()->count()], (int) $batch->company_id);

                return $batch->fresh('rows');
            });
        } catch (Throwable $exception) {
            DB::transaction(function () use ($batch, $exception): void {
                $locked = HrImportBatch::query()->lockForUpdate()->find($batch->id);
                if ($locked && in_array($this->value($locked->status), ['ready', 'committing'], true)) {
                    $locked->forceFill(['status' => 'ready', 'failed_at' => now(), 'failure_reason' => Str::limit($exception->getMessage(), 1000, '')])->save();
                }
            });
            throw $exception;
        }
    }

    /** @return array<string,HrEmployee> */
    private function commitEmployees(HrImportBatch $batch, $rows, User $actor): array
    {
        $map = $this->existingEmployees((int) $batch->company_id);
        foreach ($rows as $row) {
            $payload = $row->payload;
            $ref = $this->key($payload['employee_ref']);
            $legacy = $this->key($payload['legacy_employee_number'] ?? null);
            $employee = $map[$ref] ?? ($legacy ? ($map[$legacy] ?? null) : null);
            $metadata = [...($employee?->metadata ?? []), 'full_history_employee_ref' => $payload['employee_ref']];
            if (filled($payload['legacy_employee_number'] ?? null)) {
                $metadata['legacy_employee_number'] = trim((string) $payload['legacy_employee_number']);
            }
            $attributes = $this->employeeAttributes($payload, $metadata, (int) $batch->company_id);
            if ($employee) {
                $employee = $this->employees->update($employee, $attributes, (int) $actor->id);
            } elseif (filled($payload['legacy_employee_number'] ?? null)) {
                $employee = $this->employees->createImportedLegacy($attributes, (string) $payload['legacy_employee_number'], (int) $actor->id);
            } else {
                $employee = $this->employees->create($attributes, (int) $actor->id);
            }
            $map[$ref] = $employee;
            if ($legacy) {
                $map[$legacy] = $employee;
            }
            $this->target($row, $employee);
        }

        return $map;
    }

    private function commitAssignments(HrImportBatch $batch, $rows, array &$employees, User $actor): void
    {
        foreach ($rows->sortBy(fn ($row) => $row->payload['effective_from']) as $row) {
            $payload = $row->payload;
            $employee = $this->employee($employees, $payload['employee_ref']);
            $assignment = HrEmployeeAssignment::query()->where('employee_id', $employee->id)->whereDate('effective_from', $payload['effective_from'])->first();
            if (! $assignment) {
                $assignment = $this->employees->assign($employee, [
                    'branch_id' => $payload['_branch_id'], 'department_id' => $payload['_department_id'] ?? null,
                    'manager_id' => filled($payload['manager_ref'] ?? null) ? $this->employee($employees, $payload['manager_ref'])->id : null,
                    'job_title' => $payload['job_title'], 'employment_type' => $payload['employment_type'],
                    'effective_from' => $payload['effective_from'], 'effective_to' => $payload['effective_to'] ?: null,
                    'notes' => $payload['notes'] ?: null,
                ], (int) $actor->id);
            }
            $this->target($row, $assignment);
            $employees[$this->key($payload['employee_ref'])] = $employee->fresh();
        }
    }

    private function commitCompensation($packages, $components, array $employees, User $actor): void
    {
        $componentGroups = $components->groupBy(fn ($row) => $this->key($row->payload['package_ref']));
        foreach ($packages->sortBy(fn ($row) => $row->payload['effective_from']) as $row) {
            $payload = $row->payload;
            $employee = $this->employee($employees, $payload['employee_ref']);
            $package = HrCompensationPackage::query()->where('employee_id', $employee->id)->whereDate('effective_from', $payload['effective_from'])->first();
            $componentRows = $componentGroups->get($this->key($payload['package_ref']), collect());
            if (! $package) {
                $package = $this->compensation->createPackage($employee, [
                    'effective_from' => $payload['effective_from'], 'effective_to' => $payload['effective_to'] ?: null,
                    'currency' => $payload['currency'], 'pay_frequency' => $payload['pay_frequency'],
                    'proration_divisor' => $payload['proration_divisor'], 'bank_name' => $payload['bank_name'] ?: null,
                    'beneficiary_name' => $payload['beneficiary_name'] ?: null, 'bank_account_number' => $payload['bank_account_number'] ?: null,
                    'iban' => $payload['iban'] ?: null, 'swift_code' => $payload['swift_code'] ?: null, 'notes' => $payload['notes'] ?: null,
                ], $componentRows->map(fn ($component) => [
                    'code' => $component->payload['component_code'], 'name' => $component->payload['component_name'],
                    'category' => $component->payload['category'], 'amount_minor' => $component->payload['amount_minor'],
                    'is_taxable' => $component->payload['is_taxable'],
                ])->values()->all(), $actor);
            }
            $this->target($row, $package);
            foreach ($componentRows as $componentRow) {
                $component = $package->components()->where('code', strtoupper((string) $componentRow->payload['component_code']))->firstOrFail();
                $this->target($componentRow, $component);
            }
        }
    }

    private function commitLeaveBalances(HrImportBatch $batch, $rows, array $employees, User $actor): void
    {
        foreach ($rows->sortBy(fn ($row) => $row->payload['effective_date']) as $row) {
            $payload = $row->payload;
            $employee = $this->employee($employees, $payload['employee_ref']);
            $key = "full-history:{$batch->id}:leave-balance:{$row->id}";
            $entry = HrLeaveLedgerEntry::query()->where('idempotency_key', $key)->first();
            if (! $entry) {
                $balance = (float) HrLeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $payload['_leave_type_id'])->lockForUpdate()->get(['days'])->sum('days') + (float) $payload['days'];
                $entry = HrLeaveLedgerEntry::query()->create([
                    'company_id' => $batch->company_id, 'employee_id' => $employee->id, 'leave_type_id' => $payload['_leave_type_id'],
                    'entry_type' => 'opening', 'days' => $payload['days'], 'effective_date' => $payload['effective_date'],
                    'source_type' => HrImportRow::class, 'source_id' => $row->id, 'idempotency_key' => $key,
                    'balance_after_days' => $balance, 'notes' => $payload['notes'] ?: null, 'created_by' => $actor->id,
                ]);
            }
            $this->target($row, $entry);
        }
    }

    private function commitLeaveHistory(HrImportBatch $batch, $rows, array $employees, User $actor): void
    {
        foreach ($rows->sortBy(fn ($row) => $row->payload['start_date']) as $row) {
            $payload = $row->payload;
            $employee = $this->employee($employees, $payload['employee_ref']);
            $request = HrLeaveRequest::query()->create([
                'company_id' => $batch->company_id, 'employee_id' => $employee->id, 'leave_type_id' => $payload['_leave_type_id'],
                'manager_id' => $employee->manager_id, 'start_date' => $payload['start_date'], 'end_date' => $payload['end_date'],
                'start_portion' => $payload['start_portion'], 'end_portion' => $payload['end_portion'],
                'requested_days' => $payload['requested_days'], 'status' => $payload['status'], 'reason' => $payload['reason'] ?: null,
                'submitted_at' => $payload['start_date'], 'decided_at' => in_array($payload['status'], ['approved', 'rejected'], true) ? $payload['end_date'] : null,
                'decided_by' => in_array($payload['status'], ['approved', 'rejected'], true) ? $actor->id : null, 'created_by' => $actor->id,
                'cancelled_at' => $payload['status'] === 'cancelled' ? $payload['end_date'] : null,
                'cancelled_by' => $payload['status'] === 'cancelled' ? $actor->id : null,
            ]);
            HrLeaveRequestEvent::query()->create([
                'company_id' => $batch->company_id, 'leave_request_id' => $request->id, 'from_status' => null,
                'to_status' => $payload['status'], 'action' => 'history_imported', 'actor_id' => $actor->id,
                'metadata' => ['import_batch_id' => $batch->id, 'import_row_id' => $row->id],
            ]);
            if (in_array($payload['status'], ['approved', 'pending_manager'], true)) {
                $days = -(float) $payload['requested_days'];
                $balance = (float) HrLeaveLedgerEntry::query()->where('employee_id', $employee->id)->where('leave_type_id', $payload['_leave_type_id'])->lockForUpdate()->get(['days'])->sum('days') + $days;
                HrLeaveLedgerEntry::query()->create([
                    'company_id' => $batch->company_id, 'employee_id' => $employee->id, 'leave_type_id' => $payload['_leave_type_id'],
                    'leave_request_id' => $request->id, 'entry_type' => $payload['status'] === 'approved' ? 'usage' : 'reservation',
                    'days' => $days, 'effective_date' => $payload['start_date'], 'source_type' => HrImportRow::class, 'source_id' => $row->id,
                    'idempotency_key' => "full-history:{$batch->id}:leave:{$row->id}", 'balance_after_days' => $balance, 'created_by' => $actor->id,
                ]);
            }
            $this->target($row, $request);
        }
    }

    private function commitPayroll(HrImportBatch $batch, $rows, $components, array $employees, User $actor): void
    {
        $componentGroups = $components->groupBy(fn ($row) => $this->key($row->payload['payroll_ref']).'|'.$this->key($row->payload['employee_ref']));
        foreach ($rows->groupBy(fn ($row) => $this->key($row->payload['payroll_ref'])) as $payrollRef => $resultRows) {
            $first = $resultRows->first()->payload;
            $run = HrPayrollRun::query()->create([
                'company_id' => $batch->company_id, 'run_number' => $this->numbers->payrollRun((int) $batch->company_id, $first['pay_period_start']),
                'pay_period_start' => $first['pay_period_start'], 'pay_period_end' => $first['pay_period_end'], 'currency' => 'QAR',
                'origin' => 'migrated', 'status' => 'paid', 'is_postable' => false, 'proration_divisor' => config('hr.proration_divisor', 30),
                'description' => $first['description'] ?: "Migrated payroll {$first['payroll_ref']}",
                'prepared_by' => $actor->id, 'paid_at' => $first['paid_at'] ?: $first['pay_period_end'], 'paid_by' => $actor->id,
            ]);
            foreach ($resultRows as $row) {
                $payload = $row->payload;
                $employee = $this->employee($employees, $payload['employee_ref']);
                $assignment = $this->assignmentAt($employee, $payload['pay_period_end']);
                $package = $this->packageAt($employee, $payload['pay_period_end']);
                $key = $payrollRef.'|'.$this->key($payload['employee_ref']);
                $resultComponents = $componentGroups->get($key, collect());
                $result = HrPayrollResult::query()->create([
                    'company_id' => $batch->company_id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
                    'assignment_id' => $assignment?->id, 'compensation_package_id' => $package?->id,
                    'branch_id' => $assignment?->branch_id ?? $employee->current_branch_id,
                    'department_id' => $assignment?->department_id ?? $employee->current_department_id,
                    'currency' => 'QAR', 'basic_minor' => $payload['basic_minor'], 'gross_minor' => $payload['gross_minor'],
                    'earnings_minor' => $payload['earnings_minor'], 'deductions_minor' => $payload['deductions_minor'], 'net_minor' => $payload['net_minor'],
                    'calendar_days' => $payload['calendar_days'] ?: 0, 'worked_days' => $payload['worked_days'] ?: 0,
                    'unpaid_leave_days' => $payload['unpaid_leave_days'] ?: 0, 'proration_divisor' => config('hr.proration_divisor', 30),
                    'snapshot' => ['import_batch_id' => $batch->id, 'payroll_ref' => $payload['payroll_ref'], 'component_detail_available' => $resultComponents->isNotEmpty()],
                    'is_postable' => false,
                ]);
                foreach ($resultComponents as $componentRow) {
                    $component = HrPayrollResultComponent::query()->create([
                        'payroll_result_id' => $result->id, 'code' => strtoupper((string) $componentRow->payload['component_code']),
                        'name' => $componentRow->payload['component_name'], 'category' => $componentRow->payload['category'],
                        'amount_minor' => $componentRow->payload['amount_minor'], 'source_type' => HrImportRow::class,
                        'source_id' => (string) $componentRow->id, 'is_taxable' => $componentRow->payload['is_taxable'],
                        'snapshot' => ['import_batch_id' => $batch->id, 'amount_qar' => $componentRow->payload['amount_qar']],
                    ]);
                    $this->target($componentRow, $component);
                }
                $this->target($row, $result);
            }
        }
    }

    /** @return array<string,HrEmployee> */
    private function existingEmployees(int $companyId): array
    {
        $map = [];
        foreach (HrEmployee::query()->where('company_id', $companyId)->get() as $employee) {
            foreach ([$employee->employee_number, $employee->metadata['legacy_employee_number'] ?? null, $employee->metadata['full_history_employee_ref'] ?? null] as $ref) {
                if (filled($ref)) {
                    $map[$this->key($ref)] = $employee;
                }
            }
        }

        return $map;
    }

    private function employeeAttributes(array $row, array $metadata, int $companyId): array
    {
        return [
            'company_id' => $companyId, 'legal_first_name' => $row['legal_first_name'], 'legal_middle_name' => $row['legal_middle_name'] ?: null,
            'legal_last_name' => $row['legal_last_name'], 'display_name' => $row['display_name'], 'preferred_name' => $row['preferred_name'] ?: null,
            'work_email' => $row['work_email'] ?: null, 'personal_email' => $row['personal_email'] ?: null,
            'work_phone' => $row['work_phone'] ?: null, 'personal_phone' => $row['personal_phone'] ?: null,
            'date_of_birth' => $row['date_of_birth'] ?: null, 'nationality' => $row['nationality'] ?: null, 'gender' => $row['gender'] ?: null,
            'qid_number' => $row['qid_number'] ?: null, 'passport_number' => $row['passport_number'] ?: null,
            'address' => array_filter(['line_1' => $row['address_line_1'], 'line_2' => $row['address_line_2'], 'city' => $row['city'], 'country' => $row['country']], 'filled'),
            'emergency_contact' => array_filter(['name' => $row['emergency_contact_name'], 'relationship' => $row['emergency_contact_relationship'], 'phone' => $row['emergency_contact_phone']], 'filled'),
            'hire_date' => $row['hire_date'], 'probation_end_date' => $row['probation_end_date'] ?: null,
            'notice_date' => $row['notice_date'] ?: null, 'exit_date' => $row['exit_date'] ?: null, 'exit_reason' => $row['exit_reason'] ?: null,
            'employment_type' => $row['employment_type'], 'employment_status' => $row['employment_status'], 'metadata' => $metadata,
        ];
    }

    private function assignmentAt(HrEmployee $employee, string $date): ?HrEmployeeAssignment
    {
        return HrEmployeeAssignment::query()->where('employee_id', $employee->id)->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->latest('effective_from')->first();
    }

    private function packageAt(HrEmployee $employee, string $date): ?HrCompensationPackage
    {
        return HrCompensationPackage::query()->where('employee_id', $employee->id)->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->latest('effective_from')->first();
    }

    private function employee(array $map, mixed $reference): HrEmployee
    {
        return $map[$this->key($reference)] ?? throw ValidationException::withMessages(['employee_ref' => __('Employee reference became unavailable during commit.')]);
    }

    private function target(HrImportRow $row, $target): void
    {
        $row->forceFill(['target_type' => $target::class, 'target_id' => $target->id])->save();
    }

    private function key(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function value(mixed $value): string
    {
        return is_object($value) ? (string) $value->value : (string) $value;
    }
}
