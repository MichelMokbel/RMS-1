<?php

use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\ArInvoice;
use App\Models\BankAccount;
use App\Models\HrPayrollPaymentBatch;
use App\Models\HrPayrollRun;
use App\Models\LedgerAccount;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\SubledgerEntry;
use App\Models\SubledgerLine;
use App\Models\User;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\DashboardCashActivityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    config(['pos.money_scale' => 100]);
    $this->travelTo(Carbon::parse('2026-08-26 12:00:00'));
});

function dashboardCashCompany(string $code, bool $active = true): AccountingCompany
{
    return AccountingCompany::query()->create([
        'name' => 'Dashboard '.$code,
        'code' => $code,
        'base_currency' => 'QAR',
        'is_active' => $active,
        'is_default' => false,
    ]);
}

function dashboardPayrollPayment(AccountingCompany $company, array $attributes = []): HrPayrollPaymentBatch
{
    $run = HrPayrollRun::query()->firstOrCreate([
        'company_id' => $company->id,
        'run_number' => 'DASHBOARD-JULY',
    ], [
        'pay_period_start' => '2026-07-01',
        'pay_period_end' => '2026-07-31',
        'paid_at' => '2026-07-31 12:00:00',
    ]);
    $bank = BankAccount::query()->firstOrCreate([
        'company_id' => $company->id,
        'code' => 'PAYROLL',
    ], ['name' => 'Payroll bank']);

    return HrPayrollPaymentBatch::query()->create([
        'company_id' => $company->id,
        'payroll_run_id' => $run->id,
        'bank_account_id' => $bank->id,
        'batch_number' => (string) Str::uuid(),
        'payment_date' => '2026-08-01',
        'total_minor' => 12345,
        'status' => 'processed',
        'processed_at' => '2026-09-01 12:00:00',
        ...$attributes,
    ]);
}

it('renders the accounting dashboard for finance users', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/accounting')
        ->assertOk()
        ->assertSee('Accounting')
        ->assertSee('Jobs')
        ->assertSee('/accounting/jobs', false);
});

it('counts customer receipts once by receipt date across payment methods, including unallocated amounts', function () {
    $company = dashboardCashCompany('RECEIPTS');
    $otherCompany = dashboardCashCompany('OTHER');
    $base = ['company_id' => $company->id, 'source' => 'ar', 'created_at' => '2026-06-01'];
    $receipts = collect(['cash', 'card', 'bank', 'cheque', 'online'])->map(
        fn (string $method, int $index) => Payment::factory()->create([
            ...$base,
            'method' => $method,
            'amount_cents' => ($index + 1) * 10001,
            'received_at' => $index === 0 ? '2026-08-01 00:00:00' : '2026-08-31 23:59:59',
        ])
    );

    foreach ([2000, 3000] as $allocatedAmount) {
        $invoice = ArInvoice::factory()->create([
            'company_id' => $company->id,
            'customer_id' => $receipts->first()->customer_id,
            'total_cents' => 50000,
            'issue_date' => '2026-07-01',
        ]);
        PaymentAllocation::factory()->create([
            'payment_id' => $receipts->first()->id,
            'allocatable_type' => ArInvoice::class,
            'allocatable_id' => $invoice->id,
            'amount_cents' => $allocatedAmount,
        ]);
    }
    ArInvoice::factory()->create(['company_id' => $company->id, 'total_cents' => 999999]);

    foreach ([
        ['voided_at' => now()],
        ['source' => 'pos'],
        ['company_id' => $otherCompany->id],
        ['company_id' => null],
        ['received_at' => null],
        ['received_at' => '2026-07-31 23:59:59'],
        ['received_at' => '2026-09-01 00:00:00'],
    ] as $excluded) {
        Payment::factory()->create([...$base, 'amount_cents' => 990000, 'received_at' => now(), ...$excluded]);
    }

    expect(app(DashboardCashActivityService::class)->forRange([$company->id], '2026-08-01', '2026-08-31'))
        ->toBe(['inflow_total' => 1500.15, 'outflow_total' => 0.0, 'net_cash_flow' => 1500.15]);
});

it('counts posted supplier and expense payments by payment date without invoice or allocation duplication', function () {
    $company = dashboardCashCompany('OUTFLOW');
    $otherCompany = dashboardCashCompany('OTHER');
    $base = ['company_id' => $company->id, 'posted_at' => now(), 'payment_date' => '2026-08-31'];
    $payments = collect(['cash', 'bank_transfer', 'card', 'cheque', 'petty_cash'])->map(
        fn (string $method, int $index) => ApPayment::factory()->create([
            ...$base,
            'payment_method' => $method,
            'amount' => ($index + 1) * 10.01,
            'payment_date' => $index === 0 ? '2026-08-01' : '2026-08-31',
        ])
    );

    foreach ([1.00, 2.00] as $allocatedAmount) {
        ApPaymentAllocation::factory()->create([
            'payment_id' => $payments->first()->id,
            'invoice_id' => ApInvoice::factory()->create([
                'company_id' => $company->id,
                'supplier_id' => $payments->first()->supplier_id,
                'invoice_date' => '2026-07-01',
                'total_amount' => 1000,
            ])->id,
            'allocated_amount' => $allocatedAmount,
        ]);
    }
    ApPaymentAllocation::factory()->create([
        'payment_id' => $payments->last()->id,
        'invoice_id' => ApInvoice::factory()->create([
            'company_id' => $company->id,
            'supplier_id' => $payments->last()->supplier_id,
            'is_expense' => true,
            'status' => 'partially_paid',
            'total_amount' => 1000,
        ])->id,
        'allocated_amount' => 50.05,
    ]);
    ApInvoice::factory()->create(['company_id' => $company->id, 'total_amount' => 9900]);

    foreach ([
        ['posted_at' => null],
        ['voided_at' => now()],
        ['company_id' => $otherCompany->id],
        ['payment_date' => '2026-07-31'],
        ['payment_date' => '2026-09-01'],
    ] as $excluded) {
        ApPayment::factory()->create([...$base, 'amount' => 9900, ...$excluded]);
    }

    expect(app(DashboardCashActivityService::class)->forRange([$company->id], '2026-08-01', '2026-08-31'))
        ->toBe(['inflow_total' => 0.0, 'outflow_total' => 150.15, 'net_cash_flow' => -150.15]);
});

it('includes only processed unreversed payroll batches using their payment date and minor units', function () {
    $company = dashboardCashCompany('PAYROLL');
    $otherCompany = dashboardCashCompany('OTHER');
    dashboardPayrollPayment($company);
    dashboardPayrollPayment($company, ['payment_date' => '2026-08-31', 'total_minor' => 1]);
    dashboardPayrollPayment($otherCompany);

    foreach ([
        ['status' => 'draft'],
        ['status' => 'partially_failed'],
        ['status' => 'reversed'],
        ['reversed_at' => now()],
        ['payment_date' => '2026-07-31'],
        ['payment_date' => '2026-09-01'],
    ] as $excluded) {
        dashboardPayrollPayment($company, ['total_minor' => 990000, ...$excluded]);
    }

    expect(app(DashboardCashActivityService::class)->forRange([$company->id], '2026-08-01', '2026-08-31'))
        ->toBe(['inflow_total' => 0.0, 'outflow_total' => 123.46, 'net_cash_flow' => -123.46]);
});

it('excludes internal transfers from dashboard activity while retaining them in the ledger cash flow report', function () {
    $company = dashboardCashCompany('TRANSFER');
    $entry = SubledgerEntry::query()->create([
        'company_id' => $company->id,
        'source_type' => 'journal_entry',
        'source_id' => 501,
        'event' => 'post',
        'entry_date' => '2026-08-10',
        'status' => 'posted',
        'posted_at' => now(),
    ]);
    foreach (['MAIN' => [0, 1000], 'PETTY' => [1000, 0]] as $code => [$debit, $credit]) {
        $account = LedgerAccount::factory()->create(['company_id' => $company->id]);
        BankAccount::query()->create([
            'company_id' => $company->id,
            'ledger_account_id' => $account->id,
            'name' => $code,
            'code' => $code,
        ]);
        SubledgerLine::query()->create([
            'entry_id' => $entry->id,
            'account_id' => $account->id,
            'debit' => $debit,
            'credit' => $credit,
        ]);
    }

    $ledger = app(AccountingReportService::class)->cashFlowForRange($company->id, '2026-08-01', '2026-08-31');
    expect($ledger['inflow_total'])->toBe(1000.0)
        ->and($ledger['outflow_total'])->toBe(1000.0)
        ->and(app(DashboardCashActivityService::class)->forRange([$company->id], '2026-08-01', '2026-08-31'))
        ->toBe(['inflow_total' => 0.0, 'outflow_total' => 0.0, 'net_cash_flow' => 0.0]);
});

it('uses the configured receipt money scale without changing AP or payroll units', function () {
    config(['pos.money_scale' => 1000]);
    $company = dashboardCashCompany('SCALE');
    Payment::factory()->create(['company_id' => $company->id, 'source' => 'ar', 'amount_cents' => 123450]);
    ApPayment::factory()->create(['company_id' => $company->id, 'posted_at' => now(), 'amount' => 10.25]);
    dashboardPayrollPayment($company, ['total_minor' => 2500]);

    expect(app(DashboardCashActivityService::class)->forRange([$company->id], '2026-08-01', '2026-08-31'))
        ->toBe(['inflow_total' => 123.45, 'outflow_total' => 35.25, 'net_cash_flow' => 88.2]);
});

it('shows six calendar months for active companies and keeps the current month card limited to today', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $company = dashboardCashCompany('MAIN');
    $secondCompany = dashboardCashCompany('SECOND');
    $inactiveCompany = dashboardCashCompany('INACTIVE', false);

    foreach ([$company, $secondCompany, $inactiveCompany] as $paymentCompany) {
        Payment::factory()->create([
            'company_id' => $paymentCompany->id,
            'source' => 'ar',
            'amount_cents' => 10000,
            'received_at' => '2026-08-26 23:59:59',
        ]);
        ApPayment::factory()->create([
            'company_id' => $paymentCompany->id,
            'posted_at' => now(),
            'amount' => 25,
            'payment_date' => '2026-08-26',
        ]);
        dashboardPayrollPayment($paymentCompany, ['total_minor' => 1000, 'payment_date' => '2026-08-26']);
    }
    foreach (['2026-02-28', '2026-03-01', '2026-07-31', '2026-08-27'] as $date) {
        Payment::factory()->create([
            'company_id' => $company->id, 'source' => 'ar', 'amount_cents' => 12000, 'received_at' => $date,
        ]);
        ApPayment::factory()->create([
            'company_id' => $company->id, 'posted_at' => now(), 'amount' => 40, 'payment_date' => $date,
        ]);
        dashboardPayrollPayment($company, ['total_minor' => 1000, 'payment_date' => $date]);
    }

    Volt::actingAs($user)->test('accounting.dashboard')
        ->assertSee('Customer receipts vs payments made')
        ->assertViewHas('stats', function (array $stats) {
            expect($stats['month_inflow'])->toBe(200.0)
                ->and($stats['month_outflow'])->toBe(70.0)
                ->and($stats['month_net'])->toBe(130.0);

            return true;
        })
        ->assertViewHas('trend', function (Collection $trend) {
            expect($trend->map(fn (array $row) => array_intersect_key($row, array_flip(['label', 'inflow', 'outflow', 'net'])))->all())
                ->toBe([
                    ['label' => 'Mar', 'inflow' => 120.0, 'outflow' => 50.0, 'net' => 70.0],
                    ['label' => 'Apr', 'inflow' => 0.0, 'outflow' => 0.0, 'net' => 0.0],
                    ['label' => 'May', 'inflow' => 0.0, 'outflow' => 0.0, 'net' => 0.0],
                    ['label' => 'Jun', 'inflow' => 0.0, 'outflow' => 0.0, 'net' => 0.0],
                    ['label' => 'Jul', 'inflow' => 120.0, 'outflow' => 50.0, 'net' => 70.0],
                    ['label' => 'Aug', 'inflow' => 200.0, 'outflow' => 70.0, 'net' => 130.0],
                ]);

            return true;
        });
});

it('returns zero payment activity when no companies are selected', function () {
    $company = dashboardCashCompany('NO-SCOPE');
    Payment::factory()->create(['company_id' => $company->id, 'source' => 'ar', 'amount_cents' => 10000]);
    ApPayment::factory()->create(['company_id' => $company->id, 'posted_at' => now(), 'amount' => 50]);
    dashboardPayrollPayment($company);

    expect(app(DashboardCashActivityService::class)->forRange([], '2026-08-01', '2026-08-31'))
        ->toBe(['inflow_total' => 0.0, 'outflow_total' => 0.0, 'net_cash_flow' => 0.0]);
});

it('limits payment method mixes to business dates through today and uses the configured receipt scale', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    config(['pos.money_scale' => 1000]);
    $company = dashboardCashCompany('MIX');
    $inactive = dashboardCashCompany('MIX-INACTIVE', false);
    $receipt = ['company_id' => $company->id, 'source' => 'ar', 'method' => 'cash', 'amount_cents' => 123450, 'received_at' => '2026-08-01', 'created_at' => '2026-01-01'];
    $payment = ['company_id' => $company->id, 'posted_at' => now(), 'amount' => 25, 'payment_method' => 'cash', 'payment_date' => '2026-08-01', 'created_at' => '2026-01-01'];
    Payment::factory()->create($receipt);
    ApPayment::factory()->create($payment);
    foreach ([['received_at' => '2026-08-27'], ['received_at' => '2026-06-01'], ['voided_at' => now()], ['company_id' => $inactive->id]] as $excluded) {
        Payment::factory()->create([...$receipt, ...$excluded]);
    }
    foreach ([['payment_date' => '2026-08-27'], ['payment_date' => '2026-06-01'], ['posted_at' => null], ['voided_at' => now()], ['company_id' => $inactive->id]] as $excluded) {
        ApPayment::factory()->create([...$payment, ...$excluded]);
    }

    Volt::actingAs($user)->test('accounting.dashboard')
        ->assertViewHas('arPaymentMix', fn (Collection $mix) => $mix->sum('total') === 123.45)
        ->assertViewHas('apPaymentMix', fn (Collection $mix) => $mix->sum('total') === 25.0);
});
