<?php

use App\Models\AccountingCompany;
use App\Models\LedgerAccount;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\User;
use App\Services\Accounting\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function profitLossEntry(int $companyId, LedgerAccount $account, string $date, float $amount, array $attributes = []): void
{
    $entry = SubledgerEntry::query()->create([
        'company_id' => $companyId,
        'source_type' => 'test',
        'source_id' => random_int(1, 100000000),
        'event' => 'post',
        'entry_date' => $date,
        'created_at' => '2026-09-05',
        'status' => 'posted',
        ...$attributes,
    ]);
    $income = in_array($account->type, ['income', 'revenue'], true);
    $debit = ($income ? -1 : 1) * $amount;
    SubledgerLine::query()->create([
        'entry_id' => $entry->id, 'account_id' => $account->id,
        'debit' => max(0, $debit), 'credit' => max(0, -$debit),
    ]);
    $offset = LedgerAccount::factory()->create(['company_id' => $companyId, 'type' => 'asset']);
    SubledgerLine::query()->create([
        'entry_id' => $entry->id, 'account_id' => $offset->id,
        'debit' => max(0, -$debit), 'credit' => max(0, $debit),
    ]);
}

it('reports period movements by business date including credits and cross-period reversals', function () {
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $other = AccountingCompany::query()->create(['name' => 'Other P&L', 'code' => 'OTHER-PL', 'base_currency' => 'QAR']);
    $income = LedgerAccount::factory()->create(['company_id' => $company->id, 'type' => 'income']);
    $expense = LedgerAccount::factory()->create(['company_id' => $company->id, 'type' => 'expense']);

    profitLossEntry($company->id, $income, '2026-07-31', 1000);
    profitLossEntry($company->id, $income, '2026-08-01', 300);
    profitLossEntry($company->id, $income, '2026-08-31', -50, ['event' => 'reversal']);
    profitLossEntry($company->id, $expense, '2026-08-10', 80);
    profitLossEntry($company->id, $expense, '2026-08-11', -20);
    profitLossEntry($company->id, $income, '2026-09-01', -1000, ['event' => 'reversal']);
    profitLossEntry($company->id, $income, '2026-08-05', 9000, ['status' => 'draft']);
    profitLossEntry($company->id, $income, '2026-08-05', 9000, ['voided_at' => now()]);
    profitLossEntry($other->id, $income, '2026-08-05', 9000);

    $service = app(AccountingReportService::class);
    $august = $service->profitAndLoss($company->id, '2026-08-31', '2026-08-01');
    expect($august['revenue_total'])->toBe(250.0)
        ->and($august['expense_total'])->toBe(60.0)
        ->and($august['net_income'])->toBe(190.0)
        ->and($service->profitAndLoss($company->id, '2026-09-30', '2026-09-01')['revenue_total'])->toBe(-1000.0)
        ->and($service->profitAndLoss($company->id, '2026-08-31')['revenue_total'])->toBe(1250.0);
});

it('uses the same selected period on the profit and loss screen and CSV', function () {
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $income = LedgerAccount::factory()->create(['company_id' => $company->id, 'type' => 'income']);
    profitLossEntry($company->id, $income, '2026-07-31', 9876);
    profitLossEntry($company->id, $income, '2026-08-01', 123.45);

    Volt::actingAs($user)->test('reports.accounting-report')
        ->set('report_section', 'profit_and_loss')
        ->set('company_id', $company->id)
        ->set('date_from', '2026-08-01')
        ->set('date_to', '2026-08-31')
        ->assertSee('Date From')
        ->assertViewHas('report', fn (array $report) => $report['profit_and_loss']['revenue_total'] === 123.45);

    $response = $this->actingAs($user)->get(route('reports.accounting.export.csv', [
        'report' => 'profit-loss', 'company_id' => $company->id,
        'date_from' => '2026-08-01', 'date_to' => '2026-08-31',
    ]))->assertOk();
    expect($response->streamedContent())->toContain('123.45')->not->toContain('9999.45');
});

it('limits the accounting summary profit and loss while retaining cumulative balance reports', function () {
    Role::findOrCreate('admin', 'web');
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');
    $company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $income = LedgerAccount::factory()->create(['company_id' => $company->id, 'type' => 'income']);
    profitLossEntry($company->id, $income, '2026-07-31', 1000);
    profitLossEntry($company->id, $income, '2026-08-01', 250);

    Volt::actingAs($user)->test('accounting.reports')
        ->set('company_id', $company->id)
        ->set('date_from', '2026-08-01')
        ->set('date_to', '2026-08-31')
        ->assertSee('P&L From')
        ->assertViewHas('report', function (array $report) {
            expect($report['profit_and_loss']['revenue_total'])->toBe(250.0)
                ->and($report['balance_sheet']['asset_total'])->toBe(1250.0)
                ->and($report['trial_balance']['totals']['debit_total'])->toBe(1250.0);

            return true;
        });
    expect(app(AccountingReportService::class)->summary($company->id, '2026-08-31')['profit_and_loss']['revenue_total'])->toBe(1250.0);
});
