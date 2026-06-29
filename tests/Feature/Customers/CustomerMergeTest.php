<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeCustomerMergeAdmin(): User
{
    $role = Role::firstOrCreate(['name' => 'admin'], ['guard_name' => 'web']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole($role);

    return $user;
}

it('deactivates the source portal user when the target already has one', function () {
    $admin = makeCustomerMergeAdmin();
    $service = app(CustomerMergeService::class);

    $source = Customer::factory()->create(['name' => 'Source Customer']);
    $target = Customer::factory()->create(['name' => 'Target Customer']);

    $sourceUser = User::factory()->create([
        'customer_id' => $source->id,
        'status' => 'active',
    ]);

    User::factory()->create([
        'customer_id' => $target->id,
        'status' => 'active',
    ]);

    $service->merge($source, $target, $admin->id);

    expect($sourceUser->fresh()->customer_id)->toBeNull();
    expect($sourceUser->fresh()->status)->toBe('inactive');
    expect($source->fresh()->is_active)->toBeFalse();
    expect($target->fresh()->is_active)->toBeTrue();
});

it('moves the source portal user to the target when the target has no user', function () {
    $admin = makeCustomerMergeAdmin();
    $service = app(CustomerMergeService::class);

    $source = Customer::factory()->create(['name' => 'Source Customer']);
    $target = Customer::factory()->create(['name' => 'Target Customer']);

    $sourceUser = User::factory()->create([
        'customer_id' => $source->id,
        'status' => 'active',
    ]);

    $service->merge($source, $target, $admin->id);

    expect($sourceUser->fresh()->customer_id)->toBe($target->id);
    expect($sourceUser->fresh()->status)->toBe('active');
    expect($source->fresh()->is_active)->toBeFalse();
});
