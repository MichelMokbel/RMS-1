<?php

use App\Models\Customer;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

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
    expect($customer->customer_code)->toMatch('/^CUST-\d{4}$/');
});

it('search filter works', function () {
    $user = adminCustomer();
    $target = Customer::factory()->create(['name' => 'Acme Unique Name', 'email' => 'unique@example.com']);

    $this->actingAs($user);

    Volt::test('customers.index')
        ->set('search', 'Acme Unique Name')
        ->assertSee('Acme Unique Name');
});

it('preserves a structured pin when staff edit another customer field', function (): void {
    $user = adminCustomer();
    $customer = Customer::factory()->create([
        'delivery_address' => 'Villa 238, https://www.google.com/maps?q=25.285447,51.531040',
        'delivery_latitude' => '25.285447',
        'delivery_longitude' => '51.531040',
        'delivery_building' => 'Villa 238',
    ]);

    $this->actingAs($user);

    Volt::test('customers.edit', ['customer' => $customer])
        ->set('name', 'Updated customer name')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->delivery_latitude)->toBe('25.285447')
        ->and($customer->fresh()->delivery_longitude)->toBe('51.531040')
        ->and($customer->fresh()->delivery_building)->toBe('Villa 238');
});

it('clears a structured pin when staff replace its text address', function (): void {
    $user = adminCustomer();
    $customer = Customer::factory()->create([
        'delivery_address' => 'Villa 238, https://www.google.com/maps?q=25.285447,51.531040',
        'delivery_latitude' => '25.285447',
        'delivery_longitude' => '51.531040',
        'delivery_building' => 'Villa 238',
    ]);

    $this->actingAs($user);

    Volt::test('customers.edit', ['customer' => $customer])
        ->set('delivery_address', 'Replacement text address')
        ->call('save')
        ->assertHasNoErrors();

    expect($customer->fresh()->delivery_address)->toBe('Replacement text address')
        ->and($customer->fresh()->delivery_latitude)->toBeNull()
        ->and($customer->fresh()->delivery_longitude)->toBeNull()
        ->and($customer->fresh()->delivery_building)->toBeNull();
});
