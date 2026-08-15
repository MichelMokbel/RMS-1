<?php

namespace App\Services\HR;

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Models\HrEmployee;
use App\Models\HrEmployeeAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeService
{
    public function __construct(
        protected HrAuditLogService $audit,
        protected HrAccessService $access,
        protected HrNumberService $numbers,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, int $actorId): HrEmployee
    {
        if (array_key_exists('employee_number', $attributes)) {
            throw ValidationException::withMessages([
                'employee_number' => __('Employee numbers are system assigned. Use the legacy import pathway for historical identifiers.'),
            ]);
        }

        return $this->createRecord($attributes, $actorId);
    }

    /**
     * Import-only pathway that records a historical identifier as provenance.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createImportedLegacy(array $attributes, string $legacyEmployeeNumber, int $actorId): HrEmployee
    {
        $legacyEmployeeNumber = trim($legacyEmployeeNumber);
        if ($legacyEmployeeNumber === '' || mb_strlen($legacyEmployeeNumber) > 50 || preg_match('/[\x00-\x1F\x7F]/', $legacyEmployeeNumber)) {
            throw ValidationException::withMessages(['legacy_employee_number' => __('The legacy employee number is invalid.')]);
        }

        unset($attributes['employee_number'], $attributes['legacy_employee_number']);

        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
        $attributes['metadata'] = [...$metadata, 'legacy_employee_number' => $legacyEmployeeNumber];

        return $this->createRecord($attributes, $actorId, $legacyEmployeeNumber);
    }

    /** @param array<string, mixed> $attributes */
    private function createRecord(array $attributes, int $actorId, ?string $legacyEmployeeNumber = null): HrEmployee
    {
        return DB::transaction(function () use ($attributes, $actorId, $legacyEmployeeNumber): HrEmployee {
            $companyId = (int) ($attributes['company_id'] ?? 0);
            $actor = User::query()->findOrFail($actorId);
            $this->access->assertCompany($actor, $companyId, 'hr.employees.manage');
            $this->assertCompany($companyId);
            $this->assertOrganizationScope($companyId, $attributes);
            $this->assertManagerScope($companyId, $attributes['manager_id'] ?? null);

            $employeeNumber = $this->numbers->employee($companyId);
            if (HrEmployee::query()->where('company_id', $companyId)->where('employee_number', $employeeNumber)->exists()) {
                throw ValidationException::withMessages([
                    'employee_number' => __('The generated employee number already exists in this company.'),
                ]);
            }

            $assignment = $this->assignmentPayload($attributes);
            $employee = HrEmployee::query()->create([
                ...$attributes,
                'employee_number' => $employeeNumber,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            if (($assignment['branch_id'] ?? null) || ($assignment['department_id'] ?? null)) {
                $this->createAssignment($employee, $assignment, $actorId, false);
            }

            $this->audit->log($legacyEmployeeNumber === null ? 'hr.employee.created' : 'hr.employee.legacy_imported', $actorId, $employee, [
                'employee_id' => (int) $employee->id,
                'status' => $this->statusValue($employee->employment_status),
                'identifier_source' => $legacyEmployeeNumber === null ? 'system_sequence' : 'legacy_import',
            ], $companyId);

            return $employee->fresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(HrEmployee $employee, array $attributes, int $actorId): HrEmployee
    {
        if (array_key_exists('employee_number', $attributes)) {
            throw ValidationException::withMessages(['employee_number' => __('An employee number cannot be changed after creation.')]);
        }

        return DB::transaction(function () use ($employee, $attributes, $actorId): HrEmployee {
            $employee = HrEmployee::query()->lockForUpdate()->findOrFail($employee->id);
            $this->access->assertEmployee(User::query()->findOrFail($actorId), $employee, 'hr.employees.manage');
            $companyId = (int) $employee->company_id;

            if (isset($attributes['company_id']) && (int) $attributes['company_id'] !== $companyId) {
                throw ValidationException::withMessages(['company_id' => __('An employee cannot be moved between legal companies.')]);
            }

            $this->assertOrganizationScope($companyId, $attributes);
            $this->assertManagerScope($companyId, $attributes['manager_id'] ?? null, (int) $employee->id);
            $employee->fill([...$attributes, 'updated_by' => $actorId])->save();
            $changedFields = array_values(array_intersect(array_keys($employee->getChanges()), array_keys($attributes)));

            $this->audit->log('hr.employee.updated', $actorId, $employee, [
                'changed_fields' => $changedFields,
            ], $companyId);

            return $employee->fresh();
        });
    }

    public function transition(HrEmployee $employee, string $status, int $actorId, ?string $reason = null): HrEmployee
    {
        $allowed = [
            'onboarding' => ['active', 'archived'],
            'active' => ['suspended', 'notice', 'exited'],
            'suspended' => ['active', 'notice', 'exited'],
            'notice' => ['active', 'exited'],
            'exited' => ['archived'],
            'archived' => [],
        ];

        return DB::transaction(function () use ($employee, $status, $actorId, $reason, $allowed): HrEmployee {
            $employee = HrEmployee::query()->lockForUpdate()->findOrFail($employee->id);
            $this->access->assertEmployee(User::query()->findOrFail($actorId), $employee, 'hr.employees.manage');
            $current = $this->statusValue($employee->employment_status);
            if (! in_array($status, $allowed[$current] ?? [], true)) {
                throw ValidationException::withMessages(['status' => __('Invalid employee status transition from :from to :to.', ['from' => $current, 'to' => $status])]);
            }

            $employee->forceFill(['employment_status' => $status]);
            if ($status === 'exited' && ! $employee->exit_date) {
                $employee->exit_date = now()->toDateString();
            }
            $employee->save();

            $this->audit->log('hr.employee.status_changed', $actorId, $employee, [
                'from' => $current,
                'to' => $status,
                'reason' => $reason,
            ], (int) $employee->company_id);

            return $employee->fresh();
        });
    }

    /** @param array<string, mixed> $attributes */
    public function assign(HrEmployee $employee, array $attributes, int $actorId): HrEmployeeAssignment
    {
        $this->access->assertEmployee(User::query()->findOrFail($actorId), $employee, 'hr.employees.manage');

        return DB::transaction(fn (): HrEmployeeAssignment => $this->createAssignment(
            HrEmployee::query()->lockForUpdate()->findOrFail($employee->id),
            $attributes,
            $actorId,
            true,
        ));
    }

    /** @param array<string, mixed> $attributes */
    private function createAssignment(HrEmployee $employee, array $attributes, int $actorId, bool $audit): HrEmployeeAssignment
    {
        $companyId = (int) $employee->company_id;
        $this->assertOrganizationScope($companyId, $attributes);
        $this->assertManagerScope($companyId, $attributes['manager_id'] ?? null, (int) $employee->id);
        $effectiveFrom = Carbon::parse($attributes['effective_from'] ?? now())->toDateString();
        if ($audit && Carbon::parse($effectiveFrom)->isFuture()) {
            throw ValidationException::withMessages([
                'effective_from' => __('Phase 1 assignments must be recorded on or after their effective date.'),
            ]);
        }
        $effectiveTo = filled($attributes['effective_to'] ?? null) ? Carbon::parse($attributes['effective_to'])->toDateString() : null;
        if ($effectiveTo && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages(['effective_to' => __('The assignment end date must be after its start date.')]);
        }

        $overlaps = HrEmployeeAssignment::query()
            ->where('employee_id', $employee->id)
            ->whereDate('effective_from', '<=', $effectiveTo ?: '9999-12-31')
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $effectiveFrom))
            ->lockForUpdate()
            ->get();

        foreach ($overlaps as $overlap) {
            if (Carbon::parse($overlap->effective_from)->gte($effectiveFrom) || $effectiveTo) {
                throw ValidationException::withMessages(['effective_from' => __('The assignment overlaps an existing assignment.')]);
            }
            $overlap->forceFill(['effective_to' => Carbon::parse($effectiveFrom)->subDay()->toDateString(), 'is_primary' => false])->save();
        }

        $assignment = HrEmployeeAssignment::query()->create([
            ...$attributes,
            'employee_id' => $employee->id,
            'company_id' => $companyId,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'is_primary' => true,
            'created_by' => $actorId,
        ]);

        if (Carbon::parse($effectiveFrom)->lte(now()->startOfDay())
            && ($effectiveTo === null || Carbon::parse($effectiveTo)->gte(now()->startOfDay()))) {
            $employee->forceFill([
                'current_branch_id' => $assignment->branch_id,
                'current_department_id' => $assignment->department_id,
                'manager_id' => $assignment->manager_id,
                'job_title' => $assignment->job_title,
            ])->save();
        }

        if ($audit) {
            $this->audit->log('hr.employee.assignment_changed', $actorId, $employee, [
                'assignment_id' => (int) $assignment->id,
                'branch_id' => $assignment->branch_id,
                'department_id' => $assignment->department_id,
                'effective_from' => $effectiveFrom,
            ], $companyId);
        }

        return $assignment;
    }

    private function assertCompany(int $companyId): void
    {
        if ($companyId <= 0 || ! AccountingCompany::query()->whereKey($companyId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['company_id' => __('A valid active company is required.')]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function assertOrganizationScope(int $companyId, array $attributes): void
    {
        $branchId = $attributes['branch_id'] ?? $attributes['current_branch_id'] ?? null;
        $departmentId = $attributes['department_id'] ?? $attributes['current_department_id'] ?? null;
        if ($branchId && ! Branch::query()->whereKey($branchId)->where('company_id', $companyId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => __('The branch must belong to the employee company.')]);
        }
        if ($departmentId && ! Department::query()->whereKey($departmentId)->where('company_id', $companyId)->exists()) {
            throw ValidationException::withMessages(['department_id' => __('The department must belong to the employee company.')]);
        }
    }

    private function assertManagerScope(int $companyId, mixed $managerId, ?int $employeeId = null): void
    {
        if (! $managerId) {
            return;
        }
        if ((int) $managerId === $employeeId || ! HrEmployee::query()->whereKey($managerId)->where('company_id', $companyId)->exists()) {
            throw ValidationException::withMessages(['manager_id' => __('The manager must be another employee in the same company.')]);
        }
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function assignmentPayload(array $attributes): array
    {
        $payload = collect($attributes)->only([
            'branch_id', 'department_id', 'manager_id', 'job_title', 'employment_type', 'effective_from', 'effective_to',
        ])->all();
        $payload['branch_id'] ??= $attributes['current_branch_id'] ?? null;
        $payload['department_id'] ??= $attributes['current_department_id'] ?? null;
        $payload['effective_from'] ??= $attributes['hire_date'] ?? now()->toDateString();

        return $payload;
    }

    private function statusValue(mixed $status): string
    {
        return is_object($status) && isset($status->value) ? (string) $status->value : (string) $status;
    }
}
