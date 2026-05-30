<?php

use App\Models\MealSubscription;
use App\Models\MealSubscriptionPause;
use App\Models\MealSubscriptionDay;
use App\Models\Customer;
use App\Models\User;
use App\Services\Subscriptions\MealSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedSubscriptionBranch(int $id = 1): void
{
    DB::table('branches')->updateOrInsert(
        ['id' => $id],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
}

function makeActiveSub(): MealSubscription
{
    seedSubscriptionBranch();

    $service = app(MealSubscriptionService::class);
    $customer = Customer::factory()->create();
    $user = User::factory()->create();
    return $service->save([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
        'preferred_role' => 'main',
        'default_order_type' => 'Delivery',
        'weekdays' => [1,2,3,4,5],
    ], null, $user->id);
}

it('is active on a valid weekday within range', function () {
    $sub = makeActiveSub();
    expect($sub->isActiveOn('2025-01-06'))->toBeTrue(); // Monday
});

it('is not active outside date range', function () {
    $sub = makeActiveSub();
    expect($sub->isActiveOn('2024-12-31'))->toBeFalse();
    expect($sub->isActiveOn('2025-02-01'))->toBeFalse();
});

it('is not active when paused status', function () {
    $sub = makeActiveSub();
    $sub->status = 'paused';
    $sub->save();
    expect($sub->fresh()->isActiveOn('2025-01-06'))->toBeFalse();
});

it('is not active during pause range', function () {
    $sub = makeActiveSub();
    MealSubscriptionPause::create([
        'subscription_id' => $sub->id,
        'pause_start' => '2025-01-10',
        'pause_end' => '2025-01-12',
    ]);

    $sub = $sub->fresh(['pauses', 'days']);
    expect($sub->isActiveOn('2025-01-11'))->toBeFalse();
    expect($sub->isActiveOn('2025-01-09'))->toBeTrue();
});

it('weekdayEnabled respects configured days', function () {
    $sub = makeActiveSub();
    expect($sub->weekdayEnabled(6))->toBeFalse(); // Saturday
    expect($sub->weekdayEnabled(1))->toBeTrue();  // Monday
});

it('marks an expired subscription as renewed when the same customer has a later subscription', function () {
    seedSubscriptionBranch();

    $customer = Customer::factory()->create();

    $expired = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
        'created_at' => '2025-01-01 09:00:00',
    ]);

    $renewal = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-02-05',
        'end_date' => null,
        'created_at' => '2025-02-01 09:00:00',
    ]);

    $resolved = MealSubscription::query()->withRenewalState()->findOrFail($expired->id);

    expect($resolved->is_renewed)->toBeTrue();
    expect($resolved->is_expired_not_renewed)->toBeFalse();
    expect($resolved->renewal_subscription_id)->toBe($renewal->id);
});

it('marks an expired subscription as not renewed when no later subscription exists', function () {
    seedSubscriptionBranch();

    $customer = Customer::factory()->create();

    $expired = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
    ]);

    $resolved = MealSubscription::query()->withRenewalState()->findOrFail($expired->id);

    expect($resolved->is_renewed)->toBeFalse();
    expect($resolved->is_expired_not_renewed)->toBeTrue();
});

it('does not treat cancelled subscriptions as renewed', function () {
    seedSubscriptionBranch();

    $customer = Customer::factory()->create();

    $cancelled = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'cancelled',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
    ]);

    MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-02-05',
    ]);

    $resolved = MealSubscription::query()->withRenewalState()->findOrFail($cancelled->id);

    expect($resolved->is_renewed)->toBeFalse();
    expect($resolved->is_expired_not_renewed)->toBeFalse();
});

it('uses the legacy fallback when an expired subscription has no end date', function () {
    seedSubscriptionBranch();

    $customer = Customer::factory()->create();

    $expired = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'expired',
        'start_date' => '2025-01-01',
        'end_date' => null,
        'created_at' => '2025-01-01 09:00:00',
    ]);

    $renewal = MealSubscription::factory()->create([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-10',
        'created_at' => '2025-01-10 09:00:00',
    ]);

    $resolved = MealSubscription::query()->withRenewalState()->findOrFail($expired->id);

    expect($resolved->is_renewed)->toBeTrue();
    expect($resolved->renewal_subscription_id)->toBe($renewal->id);
});
