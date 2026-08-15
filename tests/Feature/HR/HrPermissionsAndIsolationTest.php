<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\HrEmployee;
use App\Models\HrEmployeeAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function hrIsolationCompany(string $code): AccountingCompany
{
    return AccountingCompany::query()->create([
        'name' => 'HR Isolation '.$code,
        'code' => $code,
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);
}

function hrRolePermissions(string $role): array
{
    return Role::findByName($role, 'web')
        ->permissions()
        ->where('name', 'like', 'hr.%')
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

it('seeds the exact HR permission matrix for operational roles', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $all = Permission::query()
        ->where('guard_name', 'web')
        ->where('name', 'like', 'hr.%')
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    expect($all)->toHaveCount(23)
        ->and(hrRolePermissions('admin'))->toBe($all)
        ->and(hrRolePermissions('hr'))->toBe(collect([
            'hr.access',
            'hr.audit.view',
            'hr.documents.download',
            'hr.documents.export',
            'hr.documents.manage',
            'hr.documents.view',
            'hr.employees.export',
            'hr.employees.manage',
            'hr.employees.view',
            'hr.imports.manage',
            'hr.leave.approve',
            'hr.leave.manage',
            'hr.leave.view',
            'hr.payroll.approve',
            'hr.payroll.export',
            'hr.payroll.prepare',
            'hr.payroll.view',
            'hr.reports.export',
            'hr.reports.view',
            'hr.settings.manage',
        ])->sort()->values()->all())
        ->and(hrRolePermissions('manager'))->toBe([
            'hr.access',
            'hr.employees.view',
            'hr.leave.approve',
            'hr.leave.view',
        ])
        ->and(hrRolePermissions('accounting'))->toBe([
            'hr.access',
            'hr.payroll.export',
            'hr.payroll.pay',
            'hr.payroll.post',
            'hr.payroll.reverse',
            'hr.payroll.view',
            'hr.reports.export',
            'hr.reports.view',
        ])
        ->and(hrRolePermissions('cashier'))->toBe([]);
});

it('scopes employee numbers by company while rejecting cross-company branches', function () {
    $companyA = hrIsolationCompany('HR-ISO-A');
    $companyB = hrIsolationCompany('HR-ISO-B');
    $branchA = Branch::query()->create([
        'company_id' => $companyA->id,
        'name' => 'Company A Branch',
        'code' => 'HR-A',
        'is_active' => true,
    ]);
    $branchB = Branch::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Company B Branch',
        'code' => 'HR-B',
        'is_active' => true,
    ]);

    $employeeA = HrEmployee::factory()->create([
        'company_id' => $companyA->id,
        'employee_number' => 'SHARED-001',
        'current_branch_id' => $branchA->id,
    ]);
    $employeeB = HrEmployee::factory()->create([
        'company_id' => $companyB->id,
        'employee_number' => 'SHARED-001',
        'current_branch_id' => $branchB->id,
    ]);

    expect($employeeA->currentBranch->is($branchA))->toBeTrue()
        ->and($employeeB->currentBranch->is($branchB))->toBeTrue();

    expect(fn () => HrEmployee::factory()->create([
        'company_id' => $companyA->id,
        'employee_number' => 'SHARED-001',
    ]))->toThrow(QueryException::class);

    expect(fn () => HrEmployee::factory()->create([
        'company_id' => $companyA->id,
        'employee_number' => 'CROSS-BRANCH',
        'current_branch_id' => $branchB->id,
    ]))->toThrow(ValidationException::class);
});

it('rejects cross-company employee and branch references in assignment history', function () {
    $companyA = hrIsolationCompany('HR-ASGN-A');
    $companyB = hrIsolationCompany('HR-ASGN-B');
    $branchA = Branch::query()->create([
        'company_id' => $companyA->id,
        'name' => 'Assignment A',
        'code' => 'ASGN-A',
        'is_active' => true,
    ]);
    $branchB = Branch::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Assignment B',
        'code' => 'ASGN-B',
        'is_active' => true,
    ]);
    $employeeA = HrEmployee::factory()->create([
        'company_id' => $companyA->id,
        'employee_number' => 'ASGN-EMP-A',
    ]);
    $employeeB = HrEmployee::factory()->create([
        'company_id' => $companyB->id,
        'employee_number' => 'ASGN-EMP-B',
    ]);

    $assignment = HrEmployeeAssignment::query()->create([
        'company_id' => $companyA->id,
        'employee_id' => $employeeA->id,
        'branch_id' => $branchA->id,
        'effective_from' => '2026-01-01',
    ]);

    expect($assignment->employee->is($employeeA))->toBeTrue()
        ->and($assignment->branch->is($branchA))->toBeTrue();

    expect(fn () => HrEmployeeAssignment::query()->create([
        'company_id' => $companyA->id,
        'employee_id' => $employeeA->id,
        'branch_id' => $branchB->id,
        'effective_from' => '2026-02-01',
    ]))->toThrow(ValidationException::class);

    expect(fn () => HrEmployeeAssignment::query()->create([
        'company_id' => $companyA->id,
        'employee_id' => $employeeB->id,
        'branch_id' => $branchA->id,
        'effective_from' => '2026-02-01',
    ]))->toThrow(ValidationException::class);
});
