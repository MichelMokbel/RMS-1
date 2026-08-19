<?php

use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('staff');

    $this->admin = User::factory()->create(['name' => 'AP Printer']);
    $this->admin->assignRole('admin');
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->supplier = Supplier::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Print Supplier',
    ]);
});

it('shows print actions only in the payments tab', function () {
    $invoice = ApInvoice::factory()->create([
        'company_id' => $this->company->id,
        'supplier_id' => $this->supplier->id,
        'invoice_number' => 'NO-DOCUMENT-PRINT',
        'document_type' => 'vendor_bill',
        'invoice_date' => '2026-08-19',
        'status' => 'paid',
    ]);
    $payment = ApPayment::factory()->create([
        'company_id' => $this->company->id,
        'supplier_id' => $this->supplier->id,
        'payment_date' => '2026-08-19',
        'amount' => 50,
        'payment_method' => 'cash',
        'currency_code' => 'QAR',
        'created_by' => $this->admin->id,
    ]);
    ApPaymentAllocation::query()->create([
        'payment_id' => $payment->id,
        'invoice_id' => $invoice->id,
        'allocated_amount' => 50,
    ]);

    $voucherUrl = route('payables.payments.voucher', $payment);
    $printAllUrl = route('payables.payments.print-all');

    foreach (['all', 'bills', 'expenses', 'reimbursements', 'approvals'] as $tab) {
        $this->actingAs($this->admin)
            ->get(route('payables.index', ['tab' => $tab]))
            ->assertOk()
            ->assertDontSee($printAllUrl, false)
            ->assertDontSee($voucherUrl, false)
            ->assertDontSee('Print All');
    }

    $this->get(route('payables.index', ['tab' => 'payments']))
        ->assertOk()
        ->assertSee($printAllUrl, false)
        ->assertSee($voucherUrl, false)
        ->assertSee('Print All')
        ->assertSee('Print PV')
        ->assertSee('target="_blank"', false);
});

it('prints every filtered payment rather than only the current page', function () {
    collect(range(1, 26))->each(fn (int $number) => ApPayment::factory()->create([
        'company_id' => $this->company->id,
        'supplier_id' => $this->supplier->id,
        'payment_date' => '2026-08-19',
        'amount' => 10 + $number,
        'payment_method' => 'cash',
        'currency_code' => 'QAR',
        'reference' => sprintf('FILTERED-PAY-%02d', $number),
        'created_by' => $this->admin->id,
    ]));
    ApPayment::factory()->create([
        'company_id' => $this->company->id,
        'supplier_id' => $this->supplier->id,
        'payment_date' => '2026-08-19',
        'payment_method' => 'bank_transfer',
        'reference' => 'EXCLUDED-PAY-01',
    ]);

    $url = route('payables.payments.print-all', [
        'payment_supplier_id' => $this->supplier->id,
        'payment_method' => 'cash',
        'payment_date_from' => '2026-08-19',
        'payment_date_to' => '2026-08-19',
    ]);

    $this->actingAs($this->admin)
        ->get($url)
        ->assertOk()
        ->assertSee('26 records')
        ->assertSee('FILTERED-PAY-01')
        ->assertSee('FILTERED-PAY-26')
        ->assertDontSee('EXCLUDED-PAY-01')
        ->assertSee('window.print()', false);
});

it('uses 25 rows per page and protects the filtered payment print', function () {
    ApPayment::factory()->count(26)->create([
        'company_id' => $this->company->id,
        'supplier_id' => $this->supplier->id,
        'payment_date' => '2026-08-19',
        'created_by' => $this->admin->id,
    ]);

    Volt::actingAs($this->admin);
    $component = Volt::test('payables.index')->set('tab', 'payments');
    $page = $component->viewData('paymentPage');

    expect($page->perPage())->toBe(25)
        ->and($page->count())->toBe(25)
        ->and($page->total())->toBe(26);

    $staff = User::factory()->create();
    Role::findByName('staff')->revokePermissionTo(Permission::findOrCreate('finance.access'));
    $staff->assignRole('staff');

    $this->actingAs($staff)
        ->get(route('payables.payments.print-all'))
        ->assertForbidden();
});
