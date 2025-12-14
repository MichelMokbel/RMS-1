<?php

use App\Models\Customer;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Livewire\Volt\Volt;

function adminCustomer(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    return $user;
}

it('admin can create retail customer with credit forced to zero', function () {
    $user = adminCustomer();
    $payload = Customer::factory()->make([
        'customer_type' => Customer::TYPE_RETAIL,
        'credit_limit' => 500,
        'credit_terms_days' => 30,
    ])->toArray();

    $this->actingAs($user);

    Volt::test('customers.create')
        ->set('name', $payload['name'])
        ->set('customer_type', $payload['customer_type'])
        ->set('credit_limit', $payload['credit_limit'])
        ->set('credit_terms_days', $payload['credit_terms_days'])
        ->set('is_active', true)
        ->call('create')
        ->assertHasNoErrors();

    $customer = Customer::where('name', $payload['name'])->first();
    // credit_limit is stored as DECIMAL and cast as 'decimal:3' (string) for precision.
    // Assert via float conversion for business logic expectation.
    expect((float) $customer->credit_limit)->toBe(0.0);
    expect($customer->credit_terms_days)->toBe(0);
});

it('search filter works', function () {
    $user = adminCustomer();
    $target = Customer::factory()->create(['name' => 'Acme Unique Name', 'email' => 'unique@example.com']);

    $this->actingAs($user);

    Volt::test('customers.index')
        ->set('search', 'Acme Unique Name')
        ->assertSee('Acme Unique Name');
});
