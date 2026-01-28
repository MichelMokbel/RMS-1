<?php

use App\Models\Customer;
use App\Models\MealSubscription;
use App\Models\User;
use App\Services\Orders\SubscriptionOrderGenerationService;
use App\Services\Subscriptions\MealSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('skips when no published menu', function () {
    $user = User::factory()->create(['status' => 'active']);
    $customer = Customer::factory()->create();
    $subService = app(MealSubscriptionService::class);
    $subService->save([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => '2025-01-05',
        'default_order_type' => 'Delivery',
        'preferred_role' => 'main',
        'weekdays' => [1,2,3,4,5,6,7],
    ], null, $user->id);

    $service = app(SubscriptionOrderGenerationService::class);
    $res = $service->generateForDate('2025-01-05', 1, $user->id, false);

    expect($res['skipped_no_menu_count'])->toBe(1);
});
