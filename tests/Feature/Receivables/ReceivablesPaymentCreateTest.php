<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function makeReceivablesManager(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate(['name' => 'manager'], ['guard_name' => 'web']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

function makeReceivablesCreditAdmin(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Permission::findOrCreate('payments.credit.allocate', 'web');
    $role = Role::findOrCreate('admin', 'web');
    $role->givePermissionTo('payments.credit.allocate');

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

it('loads add payment allocations unselected and can select all', function () {
    $user = makeReceivablesManager();
    $customer = Customer::factory()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-SEL-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 12500,
        'balance_cents' => 12500,
    ]);

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'partially_paid',
        'invoice_number' => 'INV-SEL-002',
        'issue_date' => now()->subDay()->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(),
        'total_cents' => 9000,
        'paid_total_cents' => 2500,
        'balance_cents' => 6500,
    ]);

    Volt::actingAs($user);

    Volt::test('receivables.payments.create')
        ->call('selectCustomer', $customer->id)
        ->assertSet('select_all_allocations', false)
        ->assertSet('allocations.0.selected', false)
        ->assertSet('allocations.1.selected', false)
        ->assertSet('amount', '0.00')
        ->set('select_all_allocations', true)
        ->assertSet('allocations.0.selected', true)
        ->assertSet('allocations.1.selected', true)
        ->assertSet('select_all_allocations', true);
});

it('loads payment create invoices from oldest to newest', function () {
    $user = makeReceivablesManager();
    $customer = Customer::factory()->create();

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-NEW-001',
        'issue_date' => '2026-06-10',
        'due_date' => '2026-06-17',
        'total_cents' => 10000,
        'balance_cents' => 10000,
    ]);

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-OLD-001',
        'issue_date' => '2026-05-10',
        'due_date' => '2026-05-17',
        'total_cents' => 10000,
        'balance_cents' => 10000,
    ]);

    Volt::actingAs($user);

    Volt::test('receivables.payments.create')
        ->call('selectCustomer', $customer->id)
        ->assertSet('allocations.0.invoice_number', 'INV-OLD-001')
        ->assertSet('allocations.1.invoice_number', 'INV-NEW-001');
});

it('prefills customer invoices from query params on payment create', function () {
    $user = makeReceivablesCreditAdmin();
    $customer = Customer::factory()->create([
        'name' => 'Prefill Customer',
        'phone' => '55110022',
    ]);

    ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-PREFILL-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 5000,
        'balance_cents' => 5000,
    ]);

    $this->actingAs($user)
        ->get(route('receivables.payments.create', ['customer_id' => $customer->id, 'branch_id' => 2]))
        ->assertOk()
        ->assertSee('INV-PREFILL-001')
        ->assertSee('Create Credit Note');
});

it('loads payment view allocations from oldest to newest', function () {
    $user = makeReceivablesCreditAdmin();
    $customer = Customer::factory()->create();

    $newerInvoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-NEW-002',
        'issue_date' => '2026-06-15',
        'due_date' => '2026-06-22',
        'total_cents' => 7000,
        'balance_cents' => 7000,
    ]);

    $olderInvoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-OLD-002',
        'issue_date' => '2026-05-15',
        'due_date' => '2026-05-22',
        'total_cents' => 9000,
        'balance_cents' => 9000,
    ]);

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'source' => 'ar',
        'currency' => 'QAR',
        'amount_cents' => 16000,
    ]);

    // Insert allocations in reverse order to verify the view sorts by invoice date.
    $payment->allocations()->create([
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $newerInvoice->id,
        'amount_cents' => 7000,
    ]);

    $payment->allocations()->create([
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $olderInvoice->id,
        'amount_cents' => 9000,
    ]);

    Volt::actingAs($user);

    Volt::test('receivables.payments.show', ['payment' => $payment])
        ->assertSeeInOrder(['INV-OLD-002', 'INV-NEW-002']);
});

it('creates and applies a credit note from payment create', function () {
    $user = makeReceivablesManager();
    $customer = Customer::factory()->create();

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-CN-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 12500,
        'balance_cents' => 12500,
    ]);

    Volt::actingAs($user);

    Volt::test('receivables.payments.create')
        ->call('selectCustomer', $customer->id)
        ->set('allocations.0.selected', true)
        ->set('credit_note_amount', '25.00')
        ->call('createCreditNote')
        ->assertHasNoErrors();

    expect($invoice->fresh()->balance_cents)->toBe(10000);
    expect(ArInvoice::query()->where('type', 'credit_note')->count())->toBe(1);
    expect(Payment::query()->where('method', 'voucher')->count())->toBe(1);
});

it('shows payment actions according to saved credit authority', function () {
    $user = makeReceivablesManager();
    $payment = Payment::factory()->create([
        'customer_id' => Customer::factory()->create()->id,
        'branch_id' => 3,
        'source' => 'ar',
    ]);

    $this->actingAs($user)
        ->get(route('receivables.payments.show', $payment))
        ->assertOk()
        ->assertDontSee('Allocate Payment')
        ->assertSee('Create New Payment')
        ->assertSee(route('receivables.payments.create', ['customer_id' => $payment->customer_id, 'branch_id' => $payment->branch_id]));

    $admin = makeReceivablesCreditAdmin();

    $this->actingAs($admin)
        ->get(route('receivables.payments.show', $payment))
        ->assertOk()
        ->assertSee('Allocate Payment');
});

it('allocates an existing payment to an invoice from payment show', function () {
    $user = makeReceivablesCreditAdmin();
    $customer = Customer::factory()->create();

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'source' => 'ar',
        'currency' => 'QAR',
        'amount_cents' => 8000,
    ]);

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'currency' => 'QAR',
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-ALLOC-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 8000,
        'balance_cents' => 8000,
    ]);

    Volt::actingAs($user);

    Volt::test('receivables.payments.show', ['payment' => $payment])
        ->set('allocations.0.selected', true)
        ->set('allocations.0.amount', '30.00')
        ->call('allocateInvoices')
        ->assertHasNoErrors();

    expect($invoice->fresh()->balance_cents)->toBe(5000);
    expect($payment->fresh()->unallocatedCents())->toBe(5000);
});

it('blocks allocation changes on a voided payment', function () {
    $user = makeReceivablesCreditAdmin();
    $customer = Customer::factory()->create();

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'source' => 'ar',
        'currency' => 'QAR',
        'amount_cents' => 8000,
        'voided_at' => now(),
        'void_reason' => 'Payment voided',
    ]);

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'currency' => 'QAR',
        'type' => 'invoice',
        'status' => 'issued',
        'invoice_number' => 'INV-VOIDED-001',
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(7)->toDateString(),
        'total_cents' => 8000,
        'balance_cents' => 8000,
    ]);

    Volt::actingAs($user);

    Volt::test('receivables.payments.show', ['payment' => $payment])
        ->call('allocateInvoices')
        ->assertHasErrors(['allocations']);

    expect($invoice->fresh()->balance_cents)->toBe(8000);
});
