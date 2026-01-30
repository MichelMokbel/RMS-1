<?php

use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MealSubscription;
use App\Models\MealSubscriptionDay;
use App\Models\MenuItem;
use App\Models\OpsEvent;
use App\Models\SubscriptionOrderRun;
use App\Services\Orders\SubscriptionOrderGenerationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates run logs and ops events for subscription generation (dry run and real run)', function () {
    $date = '2025-01-10';
    $user = User::factory()->create();

    $menu = DailyDishMenu::create([
        'branch_id' => 1,
        'service_date' => $date,
        'status' => 'published',
        'created_by' => null,
    ]);

    $main = MenuItem::factory()->create(['selling_price_per_unit' => 10, 'is_active' => true]);
    DailyDishMenuItem::create([
        'daily_dish_menu_id' => $menu->id,
        'menu_item_id' => $main->id,
        'role' => 'main',
        'sort_order' => 0,
        'is_required' => false,
    ]);

    $addon = MenuItem::factory()->create(['selling_price_per_unit' => 1, 'is_active' => true]);
    DailyDishMenuItem::create([
        'daily_dish_menu_id' => $menu->id,
        'menu_item_id' => $addon->id,
        'role' => 'addon',
        'sort_order' => 1,
        'is_required' => true,
    ]);

    $customer = Customer::factory()->create();
    $sub = MealSubscription::factory()->create([
        'branch_id' => 1,
        'status' => 'active',
        'customer_id' => $customer->id,
        'start_date' => '2025-01-01',
        'preferred_role' => 'main',
        'include_salad' => false,
        'include_dessert' => false,
        'default_order_type' => 'Delivery',
    ]);

    // 2025-01-10 is Friday => N=5
    MealSubscriptionDay::factory()->create([
        'subscription_id' => $sub->id,
        'weekday' => 5,
    ]);

    $service = app(SubscriptionOrderGenerationService::class);

    $dry = $service->generateForDate($date, 1, $user->id, dryRun: true);
    expect($dry)->toHaveKey('run_id');
    expect(SubscriptionOrderRun::query()->count())->toBe(1);
    expect(OpsEvent::query()->where('event_type', 'subscription_generated')->count())->toBe(1);

    $real = $service->generateForDate($date, 1, $user->id, dryRun: false);
    expect($real)->toHaveKey('run_id');
    expect(SubscriptionOrderRun::query()->count())->toBe(2);
    expect(OpsEvent::query()->where('event_type', 'subscription_generated')->count())->toBe(2);

    // Real run should have created one order mapping row
    $run = SubscriptionOrderRun::query()->orderByDesc('id')->first();
    expect($run->created_count)->toBeGreaterThanOrEqual(1);
});

it('logs failed run when menu is missing', function () {
    $date = '2025-01-10';
    $user = User::factory()->create();

    $service = app(SubscriptionOrderGenerationService::class);
    $res = $service->generateForDate($date, 1, $user->id, dryRun: false);

    $run = SubscriptionOrderRun::query()->latest('id')->first();
    expect($run)->not->toBeNull();
    expect($run->status)->toBe('failed');
    expect($run->skipped_no_menu_count)->toBe(1);
    expect($run->error_summary)->not->toBeNull();

    expect(OpsEvent::query()->where('event_type', 'subscription_generated')->exists())->toBeTrue();
});


