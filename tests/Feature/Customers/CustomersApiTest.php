<?php

use App\Models\Customer;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

function adminCustomerUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    return $user;
}

it('returns light customers list by default', function () {
    Customer::factory()->count(2)->create(['is_active' => true]);
    Customer::factory()->count(1)->inactive()->create();

    $user = adminCustomerUser();

    $response = actingAs($user)->getJson('/api/customers');
    $response->assertOk();
    $data = $response->json();
    expect($data)->toBeArray();
    expect(collect($data)->every(fn (array $row) => (bool) ($row['is_active'] ?? false) === true))->toBeTrue();
});

it('does not return inactive customers from the api list', function () {
    $active = Customer::factory()->create(['name' => 'Active Customer', 'is_active' => true]);
    $inactive = Customer::factory()->create(['name' => 'Inactive Customer', 'is_active' => false]);

    $user = adminCustomerUser();

    $response = actingAs($user)->getJson('/api/customers');
    $response->assertOk();

    $ids = collect($response->json())->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($ids)->toContain((int) $active->id);
    expect($ids)->not->toContain((int) $inactive->id);
});

it('returns single customer', function () {
    $customer = Customer::factory()->create();
    $user = adminCustomerUser();

    actingAs($user)->getJson('/api/customers/'.$customer->id)
        ->assertOk()
        ->assertJsonFragment(['id' => $customer->id]);
});

it('auto-generates customer code when api payload omits it', function () {
    $user = adminCustomerUser();

    actingAs($user)->postJson('/api/customers', [
        'name' => 'API Generated Customer',
        'customer_type' => Customer::TYPE_RETAIL,
        'phone' => '12345678',
        'credit_limit' => 0,
        'credit_terms_days' => 0,
        'is_active' => true,
    ])->assertCreated();

    $customer = Customer::query()->where('name', 'API Generated Customer')->firstOrFail();

    expect($customer->customer_code)->toBe('CUST-0001');
});

it('clears a structured pin when staff replace the delivery address with text', function (): void {
    $user = adminCustomerUser();
    $customer = Customer::factory()->create([
        'delivery_address' => 'Pinned address',
        'delivery_latitude' => '25.285447',
        'delivery_longitude' => '51.531040',
        'delivery_place_id' => 'place-id',
        'delivery_building' => 'Building 12',
        'delivery_unit' => 'Floor 3',
        'delivery_instructions' => 'Call on arrival',
    ]);

    actingAs($user)->putJson('/api/customers/'.$customer->id, [
        'customer_code' => $customer->customer_code,
        'name' => $customer->name,
        'customer_type' => $customer->customer_type,
        'phone' => $customer->phone,
        'email' => $customer->email,
        'delivery_address' => 'Replacement text address',
        'credit_limit' => $customer->credit_limit,
        'credit_terms_days' => $customer->credit_terms_days,
        'is_active' => true,
    ])->assertOk();

    expect($customer->fresh()->delivery_address)->toBe('Replacement text address')
        ->and($customer->fresh()->delivery_latitude)->toBeNull()
        ->and($customer->fresh()->delivery_longitude)->toBeNull()
        ->and($customer->fresh()->delivery_place_id)->toBeNull()
        ->and($customer->fresh()->delivery_building)->toBeNull();
});
