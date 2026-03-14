<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
});

it('tracks job profitability from recorded transactions', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $branch = Branch::query()->orderBy('id')->firstOrFail();

    $jobResponse = $this->actingAs($this->user)->postJson(route('api.accounting.jobs.store'), [
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'name' => 'Kitchen Refit',
        'code' => 'JOB-KITCHEN',
        'status' => 'active',
        'estimated_revenue' => 500,
        'estimated_cost' => 250,
    ])->assertCreated();

    $jobId = (int) $jobResponse->json('job.id');

    $this->actingAs($this->user)->postJson(route('api.accounting.jobs.transactions.store', ['job' => $jobId]), [
        'transaction_date' => '2026-03-11',
        'amount' => 100,
        'transaction_type' => 'cost',
        'memo' => 'Labour',
    ])->assertCreated();

    $this->actingAs($this->user)->postJson(route('api.accounting.jobs.transactions.store', ['job' => $jobId]), [
        'transaction_date' => '2026-03-12',
        'amount' => 250,
        'transaction_type' => 'revenue',
        'memo' => 'Milestone billing',
    ])->assertCreated();

    $this->actingAs($this->user)->getJson(route('api.accounting.jobs.profitability', ['job' => $jobId]))
        ->assertOk()
        ->assertJsonPath('actual_cost', 100)
        ->assertJsonPath('actual_revenue', 250)
        ->assertJsonPath('actual_margin', 150);

    $this->actingAs($this->user)
        ->get(route('accounting.reports'))
        ->assertOk()
        ->assertSee('Accounting Reports');
});
