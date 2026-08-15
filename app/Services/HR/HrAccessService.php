<?php

namespace App\Services\HR;

use App\Models\Branch;
use App\Models\HrDocument;
use App\Models\HrEmployee;
use App\Models\HrLeaveRequest;
use App\Models\HrPayrollResult;
use App\Models\HrPayrollRun;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class HrAccessService
{
    public function assertEmployee(User $actor, HrEmployee $employee, string $permission = 'hr.employees.view'): void
    {
        $this->assertPermission($actor, $permission);
        if ($actor->isAdmin() || $this->isSelf($actor, $employee) || $this->isDirectManager($actor, $employee)) {
            return;
        }

        if (! $employee->current_branch_id || ! in_array((int) $employee->current_branch_id, $actor->allowedBranchIds(), true)) {
            throw new AuthorizationException(__('You do not have access to this employee.'));
        }
    }

    public function assertDocument(User $actor, HrDocument $document, string $permission = 'hr.documents.view'): void
    {
        $this->assertPermission($actor, $permission);
        $employee = HrEmployee::query()->findOrFail($document->employee_id);
        $this->assertEmployeeScope($actor, $employee);
    }

    public function assertLeave(User $actor, HrLeaveRequest $request, string $permission = 'hr.leave.view'): void
    {
        $this->assertPermission($actor, $permission);
        $employee = HrEmployee::query()->findOrFail($request->employee_id);
        if ($actor->isAdmin() || (int) $request->manager_id === $this->employeeIdForUser($actor)) {
            return;
        }
        $this->assertEmployeeScope($actor, $employee);
    }

    public function assertPayroll(User $actor, HrPayrollRun $run, string $permission = 'hr.payroll.view'): void
    {
        $this->assertPermission($actor, $permission);
        $this->assertPayrollCompanyScope($actor, (int) $run->company_id);
        if ($actor->isAdmin()) {
            return;
        }

        $allowed = $actor->allowedBranchIds();
        $containsForbiddenBranch = HrPayrollResult::query()
            ->where('payroll_run_id', $run->id)
            ->whereNotNull('branch_id')
            ->whereNotIn('branch_id', $allowed ?: [0])
            ->exists();
        if ($containsForbiddenBranch) {
            throw new AuthorizationException(__('This payroll includes branches outside your access.'));
        }
    }

    public function assertPayrollCompany(User $actor, int $companyId, string $permission = 'hr.payroll.view'): void
    {
        $this->assertPermission($actor, $permission);
        $this->assertPayrollCompanyScope($actor, $companyId);
    }

    public function assertCompany(User $actor, int $companyId, string $permission): void
    {
        $this->assertPermission($actor, $permission);
        if ($actor->isAdmin()) {
            return;
        }
        if (! Branch::query()->where('company_id', $companyId)->whereIn('id', $actor->allowedBranchIds() ?: [0])->exists()) {
            throw new AuthorizationException(__('You do not have access to this company.'));
        }
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! $actor->isAdmin() && ! $actor->can($permission)) {
            throw new AuthorizationException(__('You are not authorized to perform this HR action.'));
        }
    }

    private function assertPayrollCompanyScope(User $actor, int $companyId): void
    {
        if ($actor->isAdmin()) {
            return;
        }
        $companyBranches = Branch::query()->where('company_id', $companyId)->where('is_active', true)
            ->pluck('id')->map(fn ($id) => (int) $id);
        if ($companyBranches->isEmpty() || $companyBranches->diff($actor->allowedBranchIds())->isNotEmpty()) {
            throw new AuthorizationException(__('Company-wide payroll requires access to every active company branch.'));
        }
    }

    private function assertEmployeeScope(User $actor, HrEmployee $employee): void
    {
        if ($actor->isAdmin() || $this->isSelf($actor, $employee) || $this->isDirectManager($actor, $employee)) {
            return;
        }
        if (! $employee->current_branch_id || ! in_array((int) $employee->current_branch_id, $actor->allowedBranchIds(), true)) {
            throw new AuthorizationException(__('You do not have access to this employee.'));
        }
    }

    private function isSelf(User $actor, HrEmployee $employee): bool
    {
        return $employee->user_id && (int) $employee->user_id === (int) $actor->id;
    }

    private function isDirectManager(User $actor, HrEmployee $employee): bool
    {
        $managerEmployeeId = $this->employeeIdForUser($actor);

        return $managerEmployeeId !== null && (int) $employee->manager_id === $managerEmployeeId;
    }

    private function employeeIdForUser(User $actor): ?int
    {
        $id = HrEmployee::query()->where('user_id', $actor->id)->value('id');

        return $id ? (int) $id : null;
    }
}
