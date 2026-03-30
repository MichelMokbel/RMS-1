<?php

use App\Models\MealSubscription;
use App\Models\Customer;
use App\Models\User;
use App\Services\Subscriptions\MealSubscriptionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates subscription with weekdays and generates code', function () {
    Carbon::setTestNow('2026-03-29 09:00:00');

    $service = app(MealSubscriptionService::class);
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    $sub = $service->save([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-01',
        'end_date' => null,
        'default_order_type' => 'Delivery',
        'preferred_role' => 'main',
        'include_salad' => true,
        'include_dessert' => true,
        'weekdays' => [1,2,3],
    ], null, $user->id);

    expect($sub->subscription_code)->toBe('SUB-2026-000001');
    expect(MealSubscription::query()->count())->toBe(1);
    expect($sub->days()->count())->toBe(3);

    $sub2 = $service->save([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-02',
        'end_date' => null,
        'default_order_type' => 'Delivery',
        'preferred_role' => 'main',
        'include_salad' => true,
        'include_dessert' => true,
        'weekdays' => [4,5],
    ], null, $user->id);

    expect($sub2->subscription_code)->toBe('SUB-2026-000002');

    Carbon::setTestNow();
});

it('requires at least one weekday', function () {
    $service = app(MealSubscriptionService::class);
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    expect(fn () => $service->save([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-01',
        'weekdays' => [],
    ], null, $user->id))->toThrow(ValidationException::class);
});

it('enforces end date after start date', function () {
    $service = app(MealSubscriptionService::class);
    $customer = Customer::factory()->create();
    $user = User::factory()->create();

    expect(fn () => $service->save([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-10',
        'end_date' => '2025-01-05',
        'weekdays' => [1],
    ], null, $user->id))->toThrow(ValidationException::class);
});
