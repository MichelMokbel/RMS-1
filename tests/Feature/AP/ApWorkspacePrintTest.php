<?php

use App\Models\AccountingCompany;
use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\ApPayment;
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

it('offers an individual print action on every AP row and prints its line items', function () {
    $invoice = ApInvoice::factory()->create([
        'company_id' => $this->company->id,
        'supplier_id' => $this->supplier->id,
        'invoice_number' => 'AP-PRINT-001',
        'reference_number' => 'SUP-PRINT-001',
        'document_type' => 'vendor_bill',
        'currency_code' => 'QAR',
        'invoice_date' => '2026-08-19',
        'subtotal' => 42.50,
        'total_amount' => 42.50,
    ]);
    ApInvoiceItem::factory()->create([
        'invoice_id' => $invoice->id,
        'description' => 'Printable kitchen supplies',
        'quantity' => 2,
        'unit_price' => 21.25,
        'line_total' => 42.50,
    ]);

    $printUrl = route('payables.invoices.print', $invoice);

    $this->actingAs($this->admin)
        ->get(route('payables.index'))
        ->assertOk()
        ->assertSee($printUrl, false)
        ->assertSee('target="_blank"', false);

    $this->get($printUrl)
        ->assertOk()
        ->assertSee('AP-PRINT-001')
        ->assertSee('SUP-PRINT-001')
        ->assertSee('Printable kitchen supplies')
        ->assertSee('42.50')
        ->assertSee('window.print()', false);
});

it('prints every filtered AP document rather than only the current page', function () {
    foreach (range(1, 13) as $number) {
        ApInvoice::factory()->create([
            'company_id' => $this->company->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => sprintf('FILTERED-AP-%02d', $number),
            'document_type' => 'vendor_bill',
            'is_expense' => false,
            'invoice_date' => '2026-08-19',
            'status' => 'posted',
            'notes' => 'Daily print batch',
        ]);
    }

    $otherSupplier = Supplier::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Excluded Supplier',
    ]);
    ApInvoice::factory()->create([
        'company_id' => $this->company->id,
        'supplier_id' => $otherSupplier->id,
        'invoice_number' => 'EXCLUDED-AP-01',
        'document_type' => 'vendor_bill',
        'invoice_date' => '2026-08-19',
        'status' => 'posted',
        'notes' => 'Daily print batch',
    ]);

    $url = route('payables.filtered.print', [
        'type' => 'documents',
        'tab' => 'bills',
        'supplier_id' => $this->supplier->id,
        'document_type' => 'vendor_bill',
        'workflow_state' => 'posted',
        'payment_state' => 'open',
        'date_from' => '2026-08-19',
        'date_to' => '2026-08-19',
        'search' => 'Daily print batch',
    ]);

    $this->actingAs($this->admin)
        ->get($url)
        ->assertOk()
        ->assertSee('13 records')
        ->assertSee('FILTERED-AP-01')
        ->assertSee('FILTERED-AP-13')
        ->assertDontSee('EXCLUDED-AP-01');
});

it('shows payment voucher actions and prints every filtered payment beyond 25 rows', function () {
    $payments = collect(range(1, 26))->map(fn (int $number) => ApPayment::factory()->create([
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

    $paymentsPage = $this->actingAs($this->admin)
        ->get(route('payables.index', ['tab' => 'payments']));
    $paymentsPage->assertOk()
        ->assertSee(route('payables.payments.voucher', $payments->last()), false)
        ->assertSee('Print PV')
        ->assertSee('Print All')
        ->assertSee('target="_blank"', false);

    $url = route('payables.filtered.print', [
        'type' => 'payments',
        'payment_supplier_id' => $this->supplier->id,
        'payment_method' => 'cash',
        'payment_date_from' => '2026-08-19',
        'payment_date_to' => '2026-08-19',
    ]);

    $this->get($url)
        ->assertOk()
        ->assertSee('26 records')
        ->assertSee('FILTERED-PAY-01')
        ->assertSee('FILTERED-PAY-26')
        ->assertDontSee('EXCLUDED-PAY-01');
});

it('uses 25 rows per page in the payments tab and protects payment print all', function () {
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
        ->get(route('payables.filtered.print', ['type' => 'payments']))
        ->assertForbidden();
});
