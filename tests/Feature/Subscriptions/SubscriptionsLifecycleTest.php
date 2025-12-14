<?php

use App\Models\MealSubscription;
use App\Models\MealSubscriptionPause;
use App\Models\MealSubscriptionDay;
use App\Services\Subscriptions\MealSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeActiveSub(): MealSubscription
{
    $service = app(MealSubscriptionService::class);
    return $service->save([
        'customer_id' => 1,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-01',
        'end_date' => '2025-01-31',
        'preferred_role' => 'main',
        'default_order_type' => 'Delivery',
        'weekdays' => [1,2,3,4,5],
    ], null, 1);
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

