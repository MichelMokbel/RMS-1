<?php

use App\Models\MealPlanRequest;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('subscriptions.default_appetizer_code', 'APP-DEFAULT');

    DB::table('branches')->updateOrInsert(
        ['id' => 1],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
});

it('backfills draft public subscription daily dish orders using the linked meal plan price and main quantity', function () {
    $appetizer = MenuItem::factory()->create(['code' => 'APP-DEFAULT', 'name' => 'Default Appetizer', 'is_active' => true]);
    $main = MenuItem::factory()->create(['code' => 'MAIN-BF', 'name' => 'Beef Stroganoff']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-BF', 'name' => 'Beetroot Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-BF', 'name' => 'Chocolate Cake']);

    $draftOrder = Order::factory()->subscription()->create([
        'status' => 'Draft',
        'total_before_tax' => 42.300,
        'total_amount' => 42.300,
    ]);
    $confirmedOrder = Order::factory()->subscription()->create([
        'status' => 'Confirmed',
        'total_before_tax' => 42.300,
        'total_amount' => 42.300,
    ]);

    $mealPlanRequest = MealPlanRequest::create([
        'customer_name' => 'Portal Customer',
        'customer_phone' => '12345678',
        'customer_email' => 'portal@example.com',
        'delivery_address' => 'Doha',
        'plan_meals' => 26,
        'status' => 'new',
    ]);

    foreach ([$draftOrder, $confirmedOrder] as $order) {
        DB::table('meal_plan_request_orders')->insert([
            'meal_plan_request_id' => $mealPlanRequest->id,
            'order_id' => $order->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        OrderItem::factory()->create([
            'order_id' => $order->id,
            'menu_item_id' => $main->id,
            'description_snapshot' => 'Daily Dish (Full Meal) - MAIN-BF Beef Stroganoff',
            'quantity' => 2,
            'unit_price' => 0,
            'line_total' => 0,
            'sort_order' => 0,
            'role' => 'main',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'menu_item_id' => $salad->id,
            'description_snapshot' => 'Daily Dish (Salad) - SALAD-BF Beetroot Salad',
            'quantity' => 2,
            'unit_price' => 0,
            'line_total' => 0,
            'sort_order' => 1,
            'role' => 'salad',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'menu_item_id' => $dessert->id,
            'description_snapshot' => 'Daily Dish (Dessert) - DES-BF Chocolate Cake',
            'quantity' => 2,
            'unit_price' => 0,
            'line_total' => 0,
            'sort_order' => 2,
            'role' => 'dessert',
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'menu_item_id' => $appetizer->id,
            'description_snapshot' => 'Daily Dish (Appetizer) - APP-DEFAULT Default Appetizer',
            'quantity' => 1,
            'unit_price' => 0,
            'line_total' => 0,
            'sort_order' => 3,
            'role' => 'appetizer',
        ]);
    }

    Artisan::call('orders:backfill-public-subscription-daily-dish');

    expect((float) $draftOrder->fresh()->total_amount)->toBe(84.6);
    expect((float) $draftOrder->fresh()->total_before_tax)->toBe(84.6);
    expect((float) $draftOrder->items()->where('role', 'appetizer')->firstOrFail()->quantity)->toBe(2.0);

    expect((float) $confirmedOrder->fresh()->total_amount)->toBe(42.3);
    expect((float) $confirmedOrder->items()->where('role', 'appetizer')->firstOrFail()->quantity)->toBe(1.0);
});
