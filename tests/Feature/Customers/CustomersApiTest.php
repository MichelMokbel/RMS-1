<?php

use App\Models\Customer;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

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
