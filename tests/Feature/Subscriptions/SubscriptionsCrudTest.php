<?php

use App\Models\MealSubscription;
use App\Models\Customer;
use App\Models\User;
use App\Services\Subscriptions\MealSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates subscription with weekdays and generates code', function () {
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

    expect($sub->subscription_code)->not()->toBeEmpty();
    expect($sub->days()->count())->toBe(3);
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

