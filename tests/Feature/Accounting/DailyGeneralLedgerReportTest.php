<?php

use App\Models\AccountingCompany;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\User;
use App\Services\Accounting\AccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
});

it('shows only posted non-voided ledger activity on the daily general ledger report', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    AccountingCompany::query()->update(['is_default' => false]);

    $company = AccountingCompany::query()->create([
        'name' => 'Main Company',
        'code' => 'MAIN',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Doha',
        'code' => 'DOH',
        'is_active' => true,
    ]);

    $cash = LedgerAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '9100',
        'name' => 'Cash',
        'type' => 'asset',
        'account_class' => 'asset',
    ]);

    $revenue = LedgerAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '9101',
        'name' => 'Sales Revenue',
        'type' => 'revenue',
        'account_class' => 'income',
    ]);

    $posted = SubledgerEntry::query()->create([
        'source_type' => 'ar_invoice',
        'source_id' => 501,
        'company_id' => $company->id,
        'event' => 'issue',
        'entry_date' => now()->toDateString(),
        'description' => 'Posted invoice batch',
        'source_document_type' => 'ar_invoice',
        'source_document_id' => 501,
        'branch_id' => $branch->id,
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    SubledgerLine::query()->create([
        'entry_id' => $posted->id,
        'account_id' => $cash->id,
        'debit' => 150.00,
        'credit' => 0,
        'memo' => 'Cash collected',
    ]);

    SubledgerLine::query()->create([
        'entry_id' => $posted->id,
        'account_id' => $revenue->id,
        'debit' => 0,
        'credit' => 150.00,
        'memo' => 'Revenue recognised',
    ]);

    $draft = SubledgerEntry::query()->create([
        'source_type' => 'journal_entry',
        'source_id' => 777,
        'company_id' => $company->id,
        'event' => 'post',
        'entry_date' => now()->toDateString(),
        'description' => 'Draft entry should not show',
        'source_document_type' => 'journal_entry',
        'source_document_id' => 777,
        'branch_id' => $branch->id,
        'currency_code' => 'QAR',
        'status' => 'draft',
    ]);

    SubledgerLine::query()->create([
        'entry_id' => $draft->id,
        'account_id' => $cash->id,
        'debit' => 25.00,
        'credit' => 0,
        'memo' => 'Draft line',
    ]);

    $voided = SubledgerEntry::query()->create([
        'source_type' => 'journal_entry',
        'source_id' => 778,
        'company_id' => $company->id,
        'event' => 'post',
        'entry_date' => now()->toDateString(),
        'description' => 'Voided entry should not show',
        'source_document_type' => 'journal_entry',
        'source_document_id' => 778,
        'branch_id' => $branch->id,
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
        'voided_at' => now(),
    ]);

    SubledgerLine::query()->create([
        'entry_id' => $voided->id,
        'account_id' => $cash->id,
        'debit' => 50.00,
        'credit' => 0,
        'memo' => 'Voided line',
    ]);

    $this->actingAs($user)
        ->get(route('reports.accounting-daily-general-ledger'))
        ->assertOk()
        ->assertSee('Daily General Ledger')
        ->assertSee('9100')
        ->assertSee('Cash')
        ->assertSee('Ar Invoice #501')
        ->assertSee('Posted invoice batch')
        ->assertSee(route('invoices.show', $posted->source_id), false)
        ->assertDontSee('Draft entry should not show')
        ->assertDontSee('Voided entry should not show');
});

it('shows entries whose source payment has been voided but excludes them from daily general ledger totals', function () {
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    AccountingCompany::query()->update(['is_default' => false]);

    $company = AccountingCompany::query()->create([
        'name' => 'Main Company',
        'code' => 'MAIN',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Doha',
        'code' => 'DOH',
        'is_active' => true,
    ]);

    $customer = Customer::factory()->corporate()->create();

    $cash = LedgerAccount::factory()->create([
        'company_id' => $company->id,
        'code' => '9200',
        'name' => 'Other Clearing',
        'type' => 'asset',
        'account_class' => 'asset',
    ]);

    $payment = Payment::query()->create([
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'company_id' => $company->id,
        'source' => 'ar',
        'method' => 'cash',
        'amount_cents' => 16500,
        'currency' => 'QAR',
        'received_at' => now(),
        'created_by' => $user->id,
        'voided_at' => now(),
        'voided_by' => $user->id,
        'void_reason' => 'Payment voided',
    ]);

    $entry = SubledgerEntry::query()->create([
        'source_type' => 'ar_payment',
        'source_id' => $payment->id,
        'company_id' => $company->id,
        'event' => 'delete',
        'entry_date' => now()->toDateString(),
        'description' => 'AR payment delete '.$payment->id,
        'source_document_type' => 'ar_payment',
        'source_document_id' => $payment->id,
        'branch_id' => $branch->id,
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    SubledgerLine::query()->create([
        'entry_id' => $entry->id,
        'account_id' => $cash->id,
        'debit' => 0,
        'credit' => 165.00,
        'memo' => 'Reversal: Cash received',
    ]);

    $report = app(AccountingReportService::class)->dailyGeneralLedger($company->id, now()->toDateString(), now()->toDateString());

    expect($report['totals']['debit_total'])->toBe(0.0)
        ->and($report['totals']['credit_total'])->toBe(0.0)
        ->and($report['totals']['audit_only_entry_count'])->toBe(1)
        ->and(collect($report['groups'])->first()['accounts'][0]['sources'][0]['status_label'])->toBe('Voided / Audit only');

    $this->actingAs($user)
        ->get(route('reports.accounting-daily-general-ledger'))
        ->assertOk()
        ->assertSee('AR payment delete '.$payment->id)
        ->assertSee('Ar Payment #'.$payment->id)
        ->assertSee('Reversal: Cash received')
        ->assertSee('Voided / Audit only')
        ->assertSee('Excluded from totals');
});
