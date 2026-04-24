<?php

use App\Models\AccountingCompany;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\LedgerAccountMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $mappingService = app(LedgerAccountMappingService::class);
    $this->cashAccount = $mappingService->resolveAccount('cash', $this->company->id)
        ?? LedgerAccount::query()->where('code', '1000')->first()
        ?? LedgerAccount::factory()->create([
            'company_id' => $this->company->id,
            'code' => '1000',
            'name' => 'Cash',
            'type' => 'asset',
        ]);
});

it('sums subledger debits as inflows and credits as outflows for cash accounts', function () {
    $customer = Customer::factory()->corporate()->create();

    $payment = Payment::query()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'company_id' => $this->company->id,
        'source' => 'ar',
        'method' => 'cash',
        'amount_cents' => 100000,
        'currency' => 'QAR',
        'received_at' => now(),
        'created_by' => 1,
    ]);

    $entry = SubledgerEntry::query()->create([
        'source_type' => 'ar_payment',
        'source_id' => $payment->id,
        'company_id' => $this->company->id,
        'event' => 'payment',
        'entry_date' => now()->toDateString(),
        'description' => 'AR cash receipt',
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    SubledgerLine::query()->create(['entry_id' => $entry->id, 'account_id' => $this->cashAccount->id, 'debit' => 1000, 'credit' => 0, 'memo' => 'Cash in']);
    SubledgerLine::query()->create(['entry_id' => $entry->id, 'account_id' => $this->cashAccount->id, 'debit' => 0, 'credit' => 600, 'memo' => 'Cash out']);

    $result = app(AccountingReportService::class)->cashFlow($this->company->id, now()->toDateString());

    expect($result['inflow_total'])->toBe(1000.0)
        ->and($result['outflow_total'])->toBe(600.0)
        ->and($result['net_cash_flow'])->toBe(400.0);
});

it('excludes voided subledger entries from cash flow', function () {
    $customer = Customer::factory()->corporate()->create();

    $payment = Payment::query()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'company_id' => $this->company->id,
        'source' => 'ar',
        'method' => 'cash',
        'amount_cents' => 100000,
        'currency' => 'QAR',
        'received_at' => now(),
        'created_by' => 1,
    ]);

    $posted = SubledgerEntry::query()->create([
        'source_type' => 'ar_payment',
        'source_id' => $payment->id,
        'company_id' => $this->company->id,
        'event' => 'payment',
        'entry_date' => now()->toDateString(),
        'description' => 'Posted cash receipt',
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    SubledgerLine::query()->create(['entry_id' => $posted->id, 'account_id' => $this->cashAccount->id, 'debit' => 1000, 'credit' => 600, 'memo' => 'Posted lines']);

    // Insert voided entry directly (bypassing the model guard).
    $voidedId = DB::table('subledger_entries')->insertGetId([
        'source_type' => 'ar_payment',
        'source_id' => 3,
        'company_id' => $this->company->id,
        'event' => 'payment',
        'entry_date' => now()->toDateString(),
        'description' => 'Voided entry',
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
        'voided_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('subledger_lines')->insert([
        'entry_id' => $voidedId,
        'account_id' => $this->cashAccount->id,
        'debit' => 500,
        'credit' => 0,
        'memo' => 'Voided — must not count',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(AccountingReportService::class)->cashFlow($this->company->id, now()->toDateString());

    expect($result['inflow_total'])->toBe(1000.0)
        ->and($result['outflow_total'])->toBe(600.0)
        ->and($result['net_cash_flow'])->toBe(400.0);
});

it('does not count non-cash account subledger lines in cash flow', function () {
    $customer = Customer::factory()->corporate()->create();

    $arAccount = LedgerAccount::query()->where('code', '1500')->first()
        ?? LedgerAccount::factory()->create([
            'company_id' => $this->company->id,
            'code' => '1500-x',
            'name' => 'AR Control (test)',
            'type' => 'asset',
        ]);

    $payment = Payment::query()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'company_id' => $this->company->id,
        'source' => 'ar',
        'method' => 'cash',
        'amount_cents' => 30000,
        'currency' => 'QAR',
        'received_at' => now(),
        'created_by' => 1,
    ]);

    $entry = SubledgerEntry::query()->create([
        'source_type' => 'ar_payment',
        'source_id' => $payment->id,
        'company_id' => $this->company->id,
        'event' => 'payment',
        'entry_date' => now()->toDateString(),
        'description' => 'Mixed entry — cash vs AR',
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    SubledgerLine::query()->create(['entry_id' => $entry->id, 'account_id' => $this->cashAccount->id, 'debit' => 300, 'credit' => 0, 'memo' => 'Cash debit']);
    SubledgerLine::query()->create(['entry_id' => $entry->id, 'account_id' => $arAccount->id, 'debit' => 0, 'credit' => 300, 'memo' => 'AR credit — not cash']);

    $result = app(AccountingReportService::class)->cashFlow($this->company->id, now()->toDateString());

    expect($result['inflow_total'])->toBe(300.0)
        ->and($result['outflow_total'])->toBe(0.0)
        ->and($result['net_cash_flow'])->toBe(300.0);
});

it('excludes entries whose source payment has been voided', function () {
    $customer = Customer::factory()->corporate()->create();

    $payment = Payment::query()->create([
        'branch_id' => 1,
        'customer_id' => $customer->id,
        'company_id' => $this->company->id,
        'source' => 'ar',
        'method' => 'cash',
        'amount_cents' => 50000,
        'currency' => 'QAR',
        'received_at' => now(),
        'created_by' => 1,
        'voided_at' => now(),
        'voided_by' => 1,
        'void_reason' => 'Payment voided',
    ]);

    $entry = SubledgerEntry::query()->create([
        'source_type' => 'ar_payment',
        'source_id' => $payment->id,
        'company_id' => $this->company->id,
        'event' => 'payment',
        'entry_date' => now()->toDateString(),
        'description' => 'Voided source payment',
        'currency_code' => 'QAR',
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    SubledgerLine::query()->create(['entry_id' => $entry->id, 'account_id' => $this->cashAccount->id, 'debit' => 500, 'credit' => 0, 'memo' => 'Should be excluded']);

    $result = app(AccountingReportService::class)->cashFlow($this->company->id, now()->toDateString());

    expect($result['inflow_total'])->toBe(0.0)
        ->and($result['outflow_total'])->toBe(0.0)
        ->and($result['net_cash_flow'])->toBe(0.0);
});
