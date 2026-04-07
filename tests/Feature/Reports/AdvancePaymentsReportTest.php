<?php

use App\Models\ArInvoice;
use App\Models\AccountingCompany;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function makeAdvancePaymentsManager(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate(['name' => 'manager'], ['guard_name' => 'web']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

it('shows advance payments in the accounts reports list', function () {
    $user = makeAdvancePaymentsManager();

    $this->actingAs($user)
        ->get(route('reports.index', ['category' => 'accounts']))
        ->assertOk()
        ->assertSee('Advance Payments');
});

it('shows only unallocated ar payments on the advance payments report', function () {
    $user = makeAdvancePaymentsManager();
    $customer = Customer::factory()->create(['name' => 'Advance Customer']);

    $visibleAdvance = Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'amount_cents' => 15000,
        'received_at' => now()->toDateTimeString(),
    ]);

    $fullyAllocated = Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'amount_cents' => 12000,
        'received_at' => now()->subDay()->toDateTimeString(),
    ]);

    $invoice = ArInvoice::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'issued',
        'total_cents' => 12000,
        'balance_cents' => 12000,
    ]);

    PaymentAllocation::query()->create([
        'payment_id' => $fullyAllocated->id,
        'allocatable_type' => ArInvoice::class,
        'allocatable_id' => $invoice->id,
        'amount_cents' => 12000,
        'allocated_at' => now(),
        'created_by' => $user->id,
        'voided_at' => null,
        'voided_by' => null,
        'void_reason' => null,
    ]);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'pos',
        'amount_cents' => 18000,
        'received_at' => now()->subDays(2)->toDateTimeString(),
    ]);

    $this->actingAs($user)
        ->get(route('reports.customer-advances'))
        ->assertOk()
        ->assertSee('Advance Payments')
        ->assertSee('Advance Customer')
        ->assertSee('#'.$visibleAdvance->id)
        ->assertDontSee('#'.$fullyAllocated->id)
        ->assertDontSee('No advance payments found.');
});

it('filters advance payments by accounting company on screen and csv export', function () {
    $user = makeAdvancePaymentsManager();

    $companyA = AccountingCompany::query()->create([
        'name' => 'Company A',
        'code' => 'COMP-A',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => true,
    ]);

    $companyB = AccountingCompany::query()->create([
        'name' => 'Company B',
        'code' => 'COMP-B',
        'base_currency' => 'QAR',
        'is_active' => true,
        'is_default' => false,
    ]);

    $customer = Customer::factory()->create(['name' => 'Scoped Customer']);

    $visible = Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'company_id' => $companyB->id,
        'amount_cents' => 15000,
        'received_at' => now()->toDateTimeString(),
    ]);

    $hidden = Payment::factory()->create([
        'customer_id' => $customer->id,
        'source' => 'ar',
        'company_id' => $companyA->id,
        'amount_cents' => 9000,
        'received_at' => now()->subDay()->toDateTimeString(),
    ]);

    $this->actingAs($user)
        ->get(route('reports.customer-advances', ['company_id' => $companyB->id]))
        ->assertOk()
        ->assertSee('#'.$visible->id)
        ->assertDontSee('#'.$hidden->id);

    $response = $this->actingAs($user)
        ->get(route('reports.customer-advances.csv', ['company_id' => $companyB->id]));

    $response->assertOk();
    expect($response->streamedContent())->toContain((string) $visible->id)
        ->not->toContain((string) $hidden->id);
});
