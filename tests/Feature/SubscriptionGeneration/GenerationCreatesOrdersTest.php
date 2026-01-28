<?php

use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MealSubscriptionOrder;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\Orders\SubscriptionOrderGenerationService;
use App\Services\Subscriptions\MealSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function setupMenuAndSubscription(string $date, int $userId, bool $includeSalad = false, bool $includeDessert = false, string $role = 'main')
{
    $customer = Customer::factory()->create();
    $menuItem = MenuItem::factory()->create(['selling_price_per_unit' => 10]);

    $menu = DailyDishMenu::create([
        'branch_id' => 1,
        'service_date' => $date,
        'status' => 'published',
    ]);

    DailyDishMenuItem::create([
        'daily_dish_menu_id' => $menu->id,
        'menu_item_id' => $menuItem->id,
        'role' => $role,
        'sort_order' => 0,
        'is_required' => false,
    ]);

    if ($includeSalad) {
        $salad = MenuItem::factory()->create(['selling_price_per_unit' => 5]);
        DailyDishMenuItem::create([
            'daily_dish_menu_id' => $menu->id,
            'menu_item_id' => $salad->id,
            'role' => 'salad',
            'sort_order' => 1,
            'is_required' => false,
        ]);
    }

    if ($includeDessert) {
        $dessert = MenuItem::factory()->create(['selling_price_per_unit' => 6]);
        DailyDishMenuItem::create([
            'daily_dish_menu_id' => $menu->id,
            'menu_item_id' => $dessert->id,
            'role' => 'dessert',
            'sort_order' => 2,
            'is_required' => false,
        ]);
    }

    $subService = app(MealSubscriptionService::class);
    $sub = $subService->save([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => $date,
        'end_date' => null,
        'default_order_type' => 'Delivery',
        'preferred_role' => $role,
        'include_salad' => true,
        'include_dessert' => true,
        'weekdays' => [\Carbon\Carbon::parse($date)->format('N')],
    ], null, $userId);

    return $sub;
}

it('creates order and mapping for active subscription', function () {
    $date = '2025-01-06'; // Monday
    $user = User::factory()->create(['status' => 'active']);
    setupMenuAndSubscription($date, $user->id);

    $service = app(SubscriptionOrderGenerationService::class);
    $res = $service->generateForDate($date, 1, $user->id, false);

    expect($res['created_count'])->toBe(1);
    expect(DB::table('orders')->count())->toBe(1);
    expect(MealSubscriptionOrder::count())->toBe(1);
    $order = DB::table('orders')->first();
    expect($order->source)->toBe('Subscription');
    expect($order->is_daily_dish)->toBe(1);
    expect(DB::table('order_items')->count())->toBe(1);
});

it('is idempotent and skips existing mapping', function () {
    $date = '2025-01-07';
    $user = User::factory()->create(['status' => 'active']);
    setupMenuAndSubscription($date, $user->id);
    $service = app(SubscriptionOrderGenerationService::class);

    $service->generateForDate($date, 1, $user->id, false);
    $res = $service->generateForDate($date, 1, $user->id, false);

    expect($res['skipped_existing_count'])->toBeGreaterThanOrEqual(1);
    expect(MealSubscriptionOrder::count())->toBe(1);
});

it('respects salad/dessert toggles', function () {
    $date = '2025-01-08';
    $user = User::factory()->create(['status' => 'active']);
    $customer = Customer::factory()->create();
    $menu = DailyDishMenu::create([
        'branch_id' => 1,
        'service_date' => $date,
        'status' => 'published',
    ]);
    $main = MenuItem::factory()->create(['selling_price_per_unit' => 10]);
    $salad = MenuItem::factory()->create(['selling_price_per_unit' => 5]);
    $dessert = MenuItem::factory()->create(['selling_price_per_unit' => 6]);

    DailyDishMenuItem::create(['daily_dish_menu_id' => $menu->id, 'menu_item_id' => $main->id, 'role' => 'main', 'sort_order' => 0, 'is_required' => false]);
    DailyDishMenuItem::create(['daily_dish_menu_id' => $menu->id, 'menu_item_id' => $salad->id, 'role' => 'salad', 'sort_order' => 1, 'is_required' => false]);
    DailyDishMenuItem::create(['daily_dish_menu_id' => $menu->id, 'menu_item_id' => $dessert->id, 'role' => 'dessert', 'sort_order' => 2, 'is_required' => false]);

    $subService = app(MealSubscriptionService::class);
    $sub = $subService->save([
        'customer_id' => $customer->id,
        'branch_id' => 1,
        'status' => 'active',
        'start_date' => $date,
        'preferred_role' => 'main',
        'include_salad' => false,
        'include_dessert' => true,
        'default_order_type' => 'Delivery',
        'weekdays' => [\Carbon\Carbon::parse($date)->format('N')],
    ], null, $user->id);

    $service = app(SubscriptionOrderGenerationService::class);
    $service->generateForDate($date, 1, $user->id, false);

    $orderItemCount = DB::table('order_items')->count();
    expect($orderItemCount)->toBe(2); // main + dessert only
});
