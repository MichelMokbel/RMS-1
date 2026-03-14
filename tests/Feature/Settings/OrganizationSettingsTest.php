<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Job;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
});

it('renders the organization settings page for admins', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/settings/organization')
        ->assertOk()
        ->assertSee('Organization')
        ->assertSee('Companies')
        ->assertSee('Branches')
        ->assertSee('Departments')
        ->assertSee('Jobs');
});

it('allows admins to create companies, branches, departments, and jobs', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    Volt::actingAs($user);

    Volt::test('settings.organization')
        ->set('company_name', 'Layla Kitchens West')
        ->set('company_code', 'LAYLA-W')
        ->set('company_base_currency', 'QAR')
        ->set('company_is_active', true)
        ->set('company_is_default', true)
        ->call('saveCompany')
        ->assertHasNoErrors();

    $company = AccountingCompany::query()->where('code', 'LAYLA-W')->first();

    expect($company)->not->toBeNull();

    Volt::test('settings.organization')
        ->set('branch_company_id', $company->id)
        ->set('branch_name', 'Doha West')
        ->set('branch_code', 'DWH')
        ->set('branch_is_active', true)
        ->call('saveBranch')
        ->assertHasNoErrors();

    $branch = Branch::query()->where('code', 'DWH')->first();

    expect($branch)->not->toBeNull();
    expect((int) $branch->company_id)->toBe((int) $company->id);

    Volt::test('settings.organization')
        ->set('department_company_id', $company->id)
        ->set('department_name', 'Finance')
        ->set('department_code', 'FIN')
        ->set('department_is_active', true)
        ->call('saveDepartment')
        ->assertHasNoErrors();

    $department = Department::query()->where('code', 'FIN')->first();

    expect($department)->not->toBeNull();
    expect((int) $department->company_id)->toBe((int) $company->id);

    Volt::test('settings.organization')
        ->set('job_company_id', $company->id)
        ->set('job_branch_id', $branch->id)
        ->set('job_name', 'Catering Launch')
        ->set('job_code', 'CAT-001')
        ->set('job_status', 'active')
        ->set('job_estimated_revenue', 25000)
        ->set('job_estimated_cost', 18000)
        ->call('saveJob')
        ->assertHasNoErrors();

    $job = Job::query()->where('code', 'CAT-001')->first();

    expect($job)->not->toBeNull();
    expect((int) $job->company_id)->toBe((int) $company->id);
    expect((int) $job->branch_id)->toBe((int) $branch->id);
});
