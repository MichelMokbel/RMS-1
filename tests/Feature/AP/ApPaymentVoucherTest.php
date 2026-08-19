<?php

use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ApPayment;
use App\Models\ApPaymentAllocation;
use App\Models\CompanyDocumentProfile;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    Role::findOrCreate('staff');

    $this->user = User::factory()->create(['name' => 'Finance User']);
    $this->user->assignRole('admin');
    $this->company = AccountingCompany::query()->where('is_default', true)->firstOrFail();
    $this->supplier = Supplier::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Voucher Supplier',
    ]);
    CompanyDocumentProfile::query()->updateOrCreate(
        ['company_id' => $this->company->id],
        ['legal_name_en' => 'Voucher Company W.L.L', 'address_en' => 'Doha, Qatar']
    );

    $this->invoice = ApInvoice::factory()->create([
        'company_id' => $this->company->id,
        'supplier_id' => $this->supplier->id,
        'invoice_number' => 'AP-VOUCHER-001',
        'reference_number' => 'SUP-INV-001',
        'invoice_date' => '2026-08-19',
        'subtotal' => 125,
        'total_amount' => 125,
        'status' => 'paid',
    ]);
    $this->payment = ApPayment::query()->create([
        'company_id' => $this->company->id,
        'supplier_id' => $this->supplier->id,
        'payment_date' => '2026-08-19',
        'amount' => 125,
        'payment_method' => 'bank_transfer',
        'currency_code' => 'QAR',
        'reference' => 'BANK-REF-100',
        'notes' => 'Full settlement',
        'created_by' => $this->user->id,
        'posted_by' => $this->user->id,
        'posted_at' => '2026-08-19 10:00:00',
    ]);
    ApPaymentAllocation::query()->create([
        'payment_id' => $this->payment->id,
        'invoice_id' => $this->invoice->id,
        'allocated_amount' => 125,
    ]);
});

it('renders a printable immutable voucher for an AP payment', function () {
    $response = $this->actingAs($this->user)
        ->get(route('payables.payments.voucher', $this->payment));

    $response->assertOk()
        ->assertSee('Payment Voucher')
        ->assertSee($this->payment->voucherNumber())
        ->assertSee('Voucher Company W.L.L')
        ->assertSee('Voucher Supplier')
        ->assertSee('AP-VOUCHER-001')
        ->assertSee('SUP-INV-001')
        ->assertSee('BANK-REF-100')
        ->assertSee('125.00')
        ->assertSee('QAR')
        ->assertSee('Full settlement')
        ->assertSee('window.print()', false)
        ->assertSee('Prepared By')
        ->assertSee('Authorized By')
        ->assertSee('Received By');
});

it('links the voucher from both the paid invoice and AP payment pages', function () {
    $voucherUrl = route('payables.payments.voucher', $this->payment);

    $this->actingAs($this->user)
        ->get(route('payables.index'))
        ->assertOk()
        ->assertSee('Print PV')
        ->assertSee($voucherUrl, false)
        ->assertSee('target="_blank"', false);

    $this->actingAs($this->user)
        ->get(route('payables.invoices.show', $this->invoice))
        ->assertOk()
        ->assertSee('Payment Voucher')
        ->assertSee($voucherUrl, false)
        ->assertSee('target="_blank"', false);

    $this->get(route('payables.payments.show', $this->payment))
        ->assertOk()
        ->assertSee('Print Voucher')
        ->assertSee($voucherUrl, false);
});

it('keeps voided payment vouchers printable with a void mark', function () {
    $this->payment->update([
        'voided_at' => now(),
        'voided_by' => $this->user->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('payables.payments.voucher', $this->payment))
        ->assertOk()
        ->assertSee('VOID')
        ->assertSee('Voided')
        ->assertSee('AP-VOUCHER-001');
});

it('requires authentication to print an AP payment voucher', function () {
    $this->get(route('payables.payments.voucher', $this->payment))
        ->assertRedirect(route('login'));
});

it('does not expose payment vouchers to AP staff without finance access', function () {
    Role::findByName('staff')->revokePermissionTo(Permission::findOrCreate('finance.access'));
    $staff = User::factory()->create();
    $staff->assignRole('staff');

    $this->actingAs($staff)
        ->get(route('payables.invoices.show', $this->invoice))
        ->assertOk()
        ->assertDontSee(route('payables.payments.voucher', $this->payment), false);

    $this->get(route('payables.payments.voucher', $this->payment))
        ->assertForbidden();
});
