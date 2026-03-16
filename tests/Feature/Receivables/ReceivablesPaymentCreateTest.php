<?php

use App\Models\ArInvoice;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
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
