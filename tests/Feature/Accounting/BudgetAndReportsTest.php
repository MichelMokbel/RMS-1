<?php

use App\Models\AccountingCompany;
use App\Models\AccountingPeriod;
use App\Models\ApInvoice;
use App\Models\ArInvoice;
use App\Models\BudgetVersion;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\LedgerAccount;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountingReportService;
use App\Services\AP\ApReportsService;
use App\Services\AR\ArInvoiceService;
use App\Services\Ledger\SubledgerService;
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

it('excludes non-posted subledger entries from the trial balance', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    $account = LedgerAccount::query()->where('type', 'expense')->orderBy('code')->firstOrFail();

    $posted = SubledgerEntry::query()->create([
        'source_type' => 'test',
        'source_id' => 10,
        'company_id' => $company->id,
        'event' => 'posted',
        'entry_date' => $period->start_date,
        'description' => 'Posted entry',
        'source_document_type' => 'test',
        'source_document_id' => 10,
        'period_id' => $period->id,
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => $this->user->id,
    ]);
    SubledgerLine::query()->create([
        'entry_id' => $posted->id,
        'account_id' => $account->id,
        'debit' => 100,
        'credit' => 0,
        'memo' => 'Posted line',
    ]);

    $draft = SubledgerEntry::query()->create([
        'source_type' => 'test',
        'source_id' => 11,
        'company_id' => $company->id,
        'event' => 'draft',
        'entry_date' => $period->start_date,
        'description' => 'Draft entry',
        'source_document_type' => 'test',
        'source_document_id' => 11,
        'period_id' => $period->id,
        'currency_code' => 'QAR',
        'status' => 'draft',
    ]);
    SubledgerLine::query()->create([
        'entry_id' => $draft->id,
        'account_id' => $account->id,
        'debit' => 999,
        'credit' => 0,
        'memo' => 'Draft line',
    ]);

    $report = app(AccountingReportService::class)->trialBalance($company->id, $period->end_date->toDateString());
    $row = collect($report['entries'])->firstWhere('code', $account->code);

    expect($row)->not->toBeNull()
        ->and((float) $row['debit_total'])->toBe(100.0);
});

it('ties ar and ap control balances to open document balances', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $period = AccountingPeriod::query()->where('company_id', $company->id)->orderBy('period_number')->firstOrFail();
    $customer = Customer::factory()->corporate()->create();
    $supplier = Supplier::factory()->create(['company_id' => $company->id]);

    /** @var ArInvoiceService $arInvoices */
    $arInvoices = app(ArInvoiceService::class);
    $arInvoice = $arInvoices->issue($arInvoices->createDraft(
        branchId: 1,
        customerId: $customer->id,
        items: [
            ['description' => 'Consulting', 'qty' => '1.000', 'unit_price_cents' => 25000, 'discount_cents' => 0, 'tax_cents' => 0, 'line_total_cents' => 25000],
        ],
        actorId: $this->user->id,
    ), $this->user->id);

    $apInvoice = ApInvoice::factory()->create([
        'company_id' => $company->id,
        'period_id' => $period->id,
        'supplier_id' => $supplier->id,
        'document_type' => 'vendor_bill',
        'invoice_number' => 'AP-TIE-OUT-1',
        'invoice_date' => $period->start_date->toDateString(),
        'due_date' => $period->end_date->toDateString(),
        'subtotal' => 180,
        'tax_amount' => 0,
        'total_amount' => 180,
        'status' => 'posted',
        'created_by' => $this->user->id,
    ]);
    $apInvoice->items()->create([
        'description' => 'Supplies',
        'quantity' => 1,
        'unit_price' => 180,
        'line_total' => 180,
    ]);
    app(SubledgerService::class)->recordApInvoice($apInvoice, $this->user->id);

    $trialBalance = app(AccountingReportService::class)->trialBalance($company->id, now()->toDateString());
    $arControl = collect($trialBalance['entries'])->firstWhere('code', '1500');
    $apControl = collect($trialBalance['entries'])->firstWhere('code', '2000');

    $openAr = round((float) (ArInvoice::query()
        ->where('company_id', $company->id)
        ->whereNull('voided_at')
        ->sum('balance_cents') / 100), 2);
    $aging = app(ApReportsService::class)->agingSummary();
    $openAp = round((float) array_sum($aging), 2);

    expect($arControl)->not->toBeNull()
        ->and((float) $arControl['debit_balance'])->toBe($openAr);
    expect($apControl)->not->toBeNull()
        ->and((float) $apControl['credit_balance'])->toBe($openAp);
    expect((int) $arInvoice->balance_cents)->toBe(25000);
});
