<?php

use App\Models\MealPlanRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedMealPlanRequestReportBranch(int $id = 1): void
{
    DB::table('branches')->updateOrInsert(
        ['id' => $id],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
}

it('prints a meal plan request report with daily totals and grand total', function () {
    seedMealPlanRequestReportBranch(1);

    $user = User::factory()->create();
    $this->actingAs($user);

    $request = MealPlanRequest::create([
        'customer_name' => 'Report Customer',
        'customer_phone' => '55512345',
        'customer_email' => 'report@example.com',
        'delivery_address' => 'Doha',
        'notes' => 'report notes',
        'plan_meals' => 20,
        'status' => 'new',
    ]);

    $order1 = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'source' => 'Website',
        'scheduled_date' => '2026-03-10',
        'total_amount' => 40.000,
    ]);
    $order2 = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'source' => 'Website',
        'scheduled_date' => '2026-03-10',
        'total_amount' => 42.300,
    ]);
    $order3 = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'source' => 'Website',
        'scheduled_date' => '2026-03-11',
        'total_amount' => 18.000,
    ]);

    foreach ([$order1, $order2, $order3] as $order) {
        DB::table('meal_plan_request_orders')->insert([
            'meal_plan_request_id' => $request->id,
            'order_id' => $order->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    OrderItem::create([
        'order_id' => $order1->id,
        'description_snapshot' => 'Daily Dish (Main) - Chicken Biryani',
        'quantity' => 1,
        'unit_price' => 20.000,
        'discount_amount' => 0,
        'line_total' => 20.000,
        'status' => 'Pending',
        'sort_order' => 1,
        'role' => 'main',
    ]);
    OrderItem::create([
        'order_id' => $order1->id,
        'description_snapshot' => 'Daily Dish (Dessert) - Cake',
        'quantity' => 1,
        'unit_price' => 20.000,
        'discount_amount' => 0,
        'line_total' => 20.000,
        'status' => 'Pending',
        'sort_order' => 2,
        'role' => 'dessert',
    ]);
    OrderItem::create([
        'order_id' => $order2->id,
        'description_snapshot' => 'Daily Dish (Main) - Fish Fillet',
        'quantity' => 1,
        'unit_price' => 42.300,
        'discount_amount' => 0,
        'line_total' => 42.300,
        'status' => 'Pending',
        'sort_order' => 1,
        'role' => 'main',
    ]);
    OrderItem::create([
        'order_id' => $order3->id,
        'description_snapshot' => 'Daily Dish (Main) - Salad Plate',
        'quantity' => 1,
        'unit_price' => 18.000,
        'discount_amount' => 0,
        'line_total' => 18.000,
        'status' => 'Pending',
        'sort_order' => 1,
        'role' => 'salad',
    ]);

    $this->get(route('meal-plan-requests.print', $request))
        ->assertOk()
        ->assertSee('Meal Plan Request Report')
        ->assertSee('2026-03-10')
        ->assertSee('2026-03-11')
        ->assertSee('Chicken Biryani')
        ->assertSee('Fish Fillet')
        ->assertSee('Day Total: 82.300')
        ->assertSee('Day Total: 18.000')
        ->assertSee('Total Amount of Cart: 100.300');
});
