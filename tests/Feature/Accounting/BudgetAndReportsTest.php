<?php

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\BudgetVersion;
use App\Models\FiscalYear;
use App\Models\LedgerAccount;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
});

it('creates a budget with monthly spread and reports variance against actuals', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $fiscalYear = FiscalYear::query()->where('company_id', $company->id)->orderBy('start_date')->firstOrFail();
    $period = AccountingPeriod::query()
        ->where('fiscal_year_id', $fiscalYear->id)
        ->where('period_number', 1)
        ->firstOrFail();
    $account = LedgerAccount::query()->where('type', 'expense')->orderBy('code')->firstOrFail();

    $budgetResponse = $this->actingAs($this->user)->postJson(route('api.accounting.budgets.store'), [
        'company_id' => $company->id,
        'fiscal_year_id' => $fiscalYear->id,
        'name' => 'FY26 Operations',
        'status' => 'active',
        'is_active' => true,
        'lines' => [
            [
                'account_id' => $account->id,
                'annual_amount' => 1200,
            ],
        ],
    ])->assertCreated();

    $budgetId = (int) $budgetResponse->json('budget.id');
    $budget = BudgetVersion::query()->findOrFail($budgetId);

    expect($budget->lines()->count())->toBe(12);

    $entry = SubledgerEntry::query()->create([
        'source_type' => 'test',
        'source_id' => 1,
        'company_id' => $company->id,
        'event' => 'budget.actual',
        'entry_date' => $period->start_date,
        'description' => 'Actual expense',
        'source_document_type' => 'test',
        'source_document_id' => 1,
        'branch_id' => null,
        'department_id' => null,
        'job_id' => null,
        'period_id' => $period->id,
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => $this->user->id,
    ]);

    SubledgerLine::query()->create([
        'entry_id' => $entry->id,
        'account_id' => $account->id,
        'debit' => 300,
        'credit' => 0,
        'memo' => 'Actual expense',
    ]);

    $this->actingAs($this->user)->getJson(route('api.accounting.budgets.variance', ['budgetVersion' => $budgetId]))
        ->assertOk()
        ->assertJsonPath('summary.budget_total', 1200)
        ->assertJsonPath('summary.actual_total', 300)
        ->assertJsonPath('period_totals.0.budget_amount', 100)
        ->assertJsonPath('period_totals.0.actual_amount', 300);

    $this->actingAs($this->user)->getJson(route('api.accounting.reports.summary'))
        ->assertOk()
        ->assertJsonPath('budget_variance.summary.actual_total', 300)
        ->assertJsonPath('trial_balance.entries.0.code', $account->code);
});
