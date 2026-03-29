<?php

use App\Models\Customer;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

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
