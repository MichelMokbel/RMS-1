<?php

use App\Models\AccountingAuditLog;
use App\Models\Customer;
use App\Models\DeliveryNote;
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

    $source = Customer::factory()->create([
        'name' => 'Source Customer',
        'delivery_latitude' => '25.300000',
        'delivery_longitude' => '51.500000',
        'delivery_building' => 'Source Villa',
    ]);
    $target = Customer::factory()->create([
        'name' => 'Target Customer',
        'delivery_latitude' => '25.285447',
        'delivery_longitude' => '51.531040',
        'delivery_building' => 'Destination Villa',
    ]);

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
    expect($source->fresh()->merged_into_customer_id)->toBe($target->id);
    expect($target->fresh()->is_active)->toBeTrue();
    expect($target->fresh()->delivery_latitude)->toBe('25.285447');
    expect($target->fresh()->delivery_longitude)->toBe('51.531040');
    expect($target->fresh()->delivery_building)->toBe('Destination Villa');
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
    expect($source->fresh()->merged_into_customer_id)->toBe($target->id);
});

it('moves delivery notes to the destination customer', function () {
    $admin = makeCustomerMergeAdmin();
    $service = app(CustomerMergeService::class);
    $source = Customer::factory()->create(['name' => 'Source Customer']);
    $target = Customer::factory()->create(['name' => 'Target Customer']);
    $deliveryNote = DeliveryNote::query()->create([
        'branch_id' => 1,
        'customer_id' => $source->id,
        'status' => 'issued',
        'delivery_date' => now()->toDateString(),
        'customer_name_snapshot' => $source->name,
        'issued_at' => now(),
        'issued_by' => $admin->id,
    ]);

    $service->merge($source, $target, $admin->id);

    expect($deliveryNote->fresh()->customer_id)->toBe($target->id);
});

it('keeps the original merge outcome when the same source and destination are submitted again', function () {
    $admin = makeCustomerMergeAdmin();
    $service = app(CustomerMergeService::class);
    $source = Customer::factory()->create(['name' => 'Source Customer']);
    $target = Customer::factory()->create(['name' => 'Target Customer']);

    $service->merge($source, $target, $admin->id);
    $service->merge($source, $target, $admin->id);

    expect($source->fresh()->is_active)->toBeFalse()
        ->and($source->fresh()->merged_into_customer_id)->toBe($target->id)
        ->and(AccountingAuditLog::query()->where('action', 'customer.merged')->count())->toBe(1);
});
