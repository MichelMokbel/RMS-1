<?php

use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedActiveBranch(int $id = 1): void
{
    DB::table('branches')->updateOrInsert(
        ['id' => $id],
        ['name' => 'Main Branch', 'code' => 'MAIN', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()]
    );
}

function createPublishedMenuForDate(string $date, int $branchId, MenuItem $main, MenuItem $salad, MenuItem $dessert): DailyDishMenu
{
    $menu = DailyDishMenu::create([
        'branch_id' => $branchId,
        'service_date' => $date,
        'status' => 'published',
    ]);

    DailyDishMenuItem::create([
        'daily_dish_menu_id' => $menu->id,
        'menu_item_id' => $main->id,
        'role' => 'main',
        'sort_order' => 1,
        'is_required' => 1,
    ]);
    DailyDishMenuItem::create([
        'daily_dish_menu_id' => $menu->id,
        'menu_item_id' => $salad->id,
        'role' => 'salad',
        'sort_order' => 2,
        'is_required' => 1,
    ]);
    DailyDishMenuItem::create([
        'daily_dish_menu_id' => $menu->id,
        'menu_item_id' => $dessert->id,
        'role' => 'dessert',
        'sort_order' => 3,
        'is_required' => 1,
    ]);

    return $menu;
}

beforeEach(function () {
    Config::set('services.recaptcha.enabled', false);
    Config::set('subscriptions.default_appetizer_code', 'APP-DEFAULT');
    $userId = User::factory()->create()->id;
    Config::set('app.system_user_id', $userId);
    seedActiveBranch(1);
});

it('creates only subscription orders with fixed 40 total and auto appetizer for mealPlan 20', function () {
    $appetizer = MenuItem::factory()->create(['code' => 'APP-DEFAULT', 'name' => 'Default Appetizer', 'is_active' => true]);

    $main1 = MenuItem::factory()->create(['code' => 'MAIN-001', 'name' => 'Chicken Biryani']);
    $salad1 = MenuItem::factory()->create(['code' => 'SALAD-001', 'name' => 'Fattoush']);
    $dessert1 = MenuItem::factory()->create(['code' => 'DES-001', 'name' => 'Cake']);
    createPublishedMenuForDate('2026-03-01', 1, $main1, $salad1, $dessert1);

    $main2 = MenuItem::factory()->create(['code' => 'MAIN-002', 'name' => 'Beef Stroganoff']);
    $salad2 = MenuItem::factory()->create(['code' => 'SALAD-002', 'name' => 'Greek Salad']);
    $dessert2 = MenuItem::factory()->create(['code' => 'DES-002', 'name' => 'Brownies']);
    createPublishedMenuForDate('2026-03-02', 1, $main2, $salad2, $dessert2);

    $payload = [
        'branch_id' => 1,
        'customerName' => 'John Doe',
        'phone' => '123456',
        'email' => 'john@example.com',
        'address' => 'Doha',
        'mealPlan' => '20',
        'items' => [
            [
                'key' => '2026-03-01',
                'mains' => [['name' => 'Chicken Biryani', 'portion' => 'plate', 'qty' => 1]],
                'salad_qty' => 1,
                'dessert_qty' => 1,
            ],
            [
                'key' => '2026-03-02',
                'mains' => [['name' => 'Beef Stroganoff', 'portion' => 'plate', 'qty' => 1]],
                'salad_qty' => 1,
                'dessert_qty' => 1,
            ],
        ],
    ];

    $this->postJson('/api/public/daily-dish/orders', $payload)
        ->assertOk()
        ->assertJson(['success' => true]);

    $orders = Order::query()->orderBy('id')->get();
    expect($orders)->toHaveCount(2);
    expect($orders->pluck('source')->unique()->values()->all())->toBe(['Subscription']);
    expect((float) $orders[0]->total_amount)->toBe(40.0);
    expect((float) $orders[1]->total_amount)->toBe(40.0);

    foreach ($orders as $order) {
        $hasApp = $order->items()->where('menu_item_id', $appetizer->id)->exists();
        expect($hasApp)->toBeTrue();
    }
});

it('uses fixed 42.3 total for mealPlan 26', function () {
    MenuItem::factory()->create(['code' => 'APP-DEFAULT', 'name' => 'Default Appetizer', 'is_active' => true]);
    $main = MenuItem::factory()->create(['code' => 'MAIN-026', 'name' => 'Fish Fillet']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-026', 'name' => 'Tabbouleh']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-026', 'name' => 'Tarte']);
    createPublishedMenuForDate('2026-03-03', 1, $main, $salad, $dessert);

    $payload = [
        'branch_id' => 1,
        'customerName' => 'Jane Doe',
        'phone' => '123456',
        'email' => 'jane@example.com',
        'address' => 'Doha',
        'mealPlan' => '26',
        'items' => [[
            'key' => '2026-03-03',
            'mains' => [['name' => 'Fish Fillet', 'portion' => 'plate', 'qty' => 1]],
            'salad_qty' => 1,
            'dessert_qty' => 1,
        ]],
    ];

    $this->postJson('/api/public/daily-dish/orders', $payload)
        ->assertOk()
        ->assertJson(['success' => true]);

    $order = Order::query()->firstOrFail();
    expect($order->source)->toBe('Subscription');
    expect((float) $order->total_amount)->toBe(42.3);
});

it('returns 422 when subscription appetizer code is not configured to an active menu item', function () {
    Config::set('subscriptions.default_appetizer_code', 'MISSING-APP');

    $main = MenuItem::factory()->create(['code' => 'MAIN-ERR', 'name' => 'Main Dish']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-ERR', 'name' => 'Salad Dish']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-ERR', 'name' => 'Dessert Dish']);
    createPublishedMenuForDate('2026-03-04', 1, $main, $salad, $dessert);

    $payload = [
        'branch_id' => 1,
        'customerName' => 'No App',
        'phone' => '123456',
        'email' => 'noapp@example.com',
        'address' => 'Doha',
        'mealPlan' => '20',
        'items' => [[
            'key' => '2026-03-04',
            'mains' => [['name' => 'Main Dish', 'portion' => 'plate', 'qty' => 1]],
            'salad_qty' => 1,
            'dessert_qty' => 1,
        ]],
    ];

    $this->postJson('/api/public/daily-dish/orders', $payload)
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Default appetizer item is not configured.']);

    expect(Order::query()->count())->toBe(0);
});

it('creates website order and trusts submitted day_total for non-subscription requests', function () {
    $main = MenuItem::factory()->create(['code' => 'MAIN-WEB', 'name' => 'Website Main']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-WEB', 'name' => 'Website Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-WEB', 'name' => 'Website Dessert']);
    createPublishedMenuForDate('2026-03-05', 1, $main, $salad, $dessert);

    $payload = [
        'branch_id' => 1,
        'customerName' => 'Website User',
        'phone' => '123456',
        'email' => 'web@example.com',
        'address' => 'Doha',
        'mealPlan' => null,
        'items' => [[
            'key' => '2026-03-05',
            'mains' => [['name' => 'Website Main', 'portion' => 'plate', 'qty' => 1]],
            'salad_qty' => 1,
            'dessert_qty' => 1,
            'day_total' => 123.45,
        ]],
    ];

    $this->postJson('/api/public/daily-dish/orders', $payload)
        ->assertOk()
        ->assertJson(['success' => true]);

    $order = Order::query()->firstOrFail();
    expect($order->source)->toBe('Website');
    expect((float) $order->total_amount)->toBe(123.45);
});

