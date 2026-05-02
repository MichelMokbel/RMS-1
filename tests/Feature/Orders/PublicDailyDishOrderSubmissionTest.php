<?php

use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\Customer;
use App\Models\MenuItem;
use App\Models\OpsEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

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
    Config::set('subscriptions.default_appetizer_code', 'APP-DEFAULT');
    Role::findOrCreate('customer', 'web');
    seedActiveBranch(1);
});

function actingAsVerifiedCustomer(): array
{
    $customer = Customer::factory()->create([
        'phone_verified_at' => now(),
        'email' => 'portal@example.com',
    ]);
    $user = User::factory()->create([
        'email' => 'portal@example.com',
        'customer_id' => $customer->id,
    ]);
    $user->assignRole('customer');

    Sanctum::actingAs($user, ['customer:*']);

    return [$user, $customer];
}

function createWebsiteOrderPayload(array $overrides = []): array
{
    return array_replace_recursive([
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
    ], $overrides);
}

it('rejects guest daily dish order submission', function () {
    $this->postJson('/api/public/daily-dish/orders', [
        'branch_id' => 1,
        'customerName' => 'Guest',
        'phone' => '123456',
        'email' => 'guest@example.com',
        'address' => 'Doha',
        'items' => [],
    ])->assertStatus(401);
});

it('allows newly registered bypassed customers to order because they are marked verified', function () {
    Config::set('customers.verification_bypass', true);

    seedActiveBranch(1);

    $main = MenuItem::factory()->create(['code' => 'MAIN-BYPASS', 'name' => 'Website Main']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-BYPASS', 'name' => 'Website Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-BYPASS', 'name' => 'Website Dessert']);
    createPublishedMenuForDate('2026-03-05', 1, $main, $salad, $dessert);

    $register = $this->postJson('/api/customer/auth/register/start', [
        'name' => 'Portal Customer',
        'email' => 'portal@example.com',
        'password' => 'password123',
        'phone' => '55123456',
        'address' => 'West Bay',
    ])->assertCreated();

    $user = User::query()->where('email', 'portal@example.com')->firstOrFail();
    Sanctum::actingAs($user, ['customer:*']);

    $this->postJson('/api/public/daily-dish/orders', createWebsiteOrderPayload())
        ->assertOk()
        ->assertJson(['success' => true]);

    expect($user->customer_id)->toBeNull();
    expect($user->fresh()->portal_phone_verified_at)->not->toBeNull();
});

it('creates only subscription orders with fixed 40 total and auto appetizer for mealPlan 20', function () {
    [, $customer] = actingAsVerifiedCustomer();
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
    expect($orders->pluck('customer_id')->unique()->values()->all())->toBe([$customer->id]);
    expect((float) $orders[0]->total_amount)->toBe(40.0);
    expect((float) $orders[1]->total_amount)->toBe(40.0);

    foreach ($orders as $order) {
        $hasApp = $order->items()->where('menu_item_id', $appetizer->id)->exists();
        expect($hasApp)->toBeTrue();
        expect($order->items()->where('role', 'salad')->exists())->toBeTrue();
        expect($order->items()->where('role', 'dessert')->exists())->toBeTrue();
    }
});

it('uses fixed 42.3 total for mealPlan 26', function () {
    actingAsVerifiedCustomer();
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
    expect($order->items()->where('role', 'salad')->exists())->toBeTrue();
    expect($order->items()->where('role', 'dessert')->exists())->toBeTrue();
    expect($order->items()->where('role', 'appetizer')->exists())->toBeTrue();
});

it('multiplies subscription totals and appetizer quantity by the selected meals for the day', function () {
    actingAsVerifiedCustomer();
    $appetizer = MenuItem::factory()->create(['code' => 'APP-DEFAULT', 'name' => 'Default Appetizer', 'is_active' => true]);
    $main = MenuItem::factory()->create(['code' => 'MAIN-MULTI', 'name' => 'Beef Stroganoff']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-MULTI', 'name' => 'Beetroot Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-MULTI', 'name' => 'Chocolate Cake']);
    createPublishedMenuForDate('2026-03-06', 1, $main, $salad, $dessert);

    $payload = [
        'branch_id' => 1,
        'customerName' => 'Multi Meal Customer',
        'phone' => '123456',
        'email' => 'multi@example.com',
        'address' => 'Doha',
        'mealPlan' => '26',
        'items' => [[
            'key' => '2026-03-06',
            'mains' => [['name' => 'Beef Stroganoff', 'portion' => 'plate', 'qty' => 2]],
            'salad_qty' => 2,
            'dessert_qty' => 2,
        ]],
    ];

    $this->postJson('/api/public/daily-dish/orders', $payload)
        ->assertOk()
        ->assertJson(['success' => true]);

    $order = Order::query()->firstOrFail();
    $appetizerLine = $order->items()->where('role', 'appetizer')->firstOrFail();
    $mainLine = $order->items()->where('role', 'main')->firstOrFail();

    expect((float) $order->total_amount)->toBe(84.6);
    expect((float) $mainLine->quantity)->toBe(2.0);
    expect((float) $appetizerLine->quantity)->toBe(2.0);
    expect((int) $appetizerLine->menu_item_id)->toBe($appetizer->id);
});

it('returns 422 when subscription appetizer code is not configured to an active menu item', function () {
    actingAsVerifiedCustomer();
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
    [, $customer] = actingAsVerifiedCustomer();
    $main = MenuItem::factory()->create(['code' => 'MAIN-WEB', 'name' => 'Website Main']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-WEB', 'name' => 'Website Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-WEB', 'name' => 'Website Dessert']);
    createPublishedMenuForDate('2026-03-05', 1, $main, $salad, $dessert);

    $payload = createWebsiteOrderPayload();

    $this->postJson('/api/public/daily-dish/orders', $payload)
        ->assertOk()
        ->assertJson(['success' => true]);

    $order = Order::query()->firstOrFail();
    expect($order->source)->toBe('Website');
    expect((int) $order->customer_id)->toBe($customer->id);
    expect((float) $order->total_amount)->toBe(123.45);
    expect($order->items()->where('role', 'salad')->exists())->toBeTrue();
    expect($order->items()->where('role', 'dessert')->exists())->toBeTrue();

    $saladItem = $order->items()->where('role', 'salad')->first();
    $dessertItem = $order->items()->where('role', 'dessert')->first();

    expect((float) $saladItem->unit_price)->toBe(0.0);
    expect((float) $dessertItem->unit_price)->toBe(0.0);
});

it('creates user-owned website orders for verified unlinked portal users and persists per-day notes', function () {
    $user = User::factory()->create([
        'email' => 'portal@example.com',
        'customer_id' => null,
        'portal_name' => 'Portal Customer',
        'portal_phone' => '55123456',
        'portal_phone_e164' => '+97455123456',
        'portal_delivery_address' => 'West Bay',
        'portal_phone_verified_at' => now(),
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);

    $main = MenuItem::factory()->create(['code' => 'MAIN-USER', 'name' => 'Website Main']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-USER', 'name' => 'Website Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-USER', 'name' => 'Website Dessert']);
    createPublishedMenuForDate('2026-03-05', 1, $main, $salad, $dessert);

    $payload = createWebsiteOrderPayload([
        'items' => [[
            'key' => '2026-03-05',
            'notes' => 'Leave at reception',
            'mains' => [['name' => 'Website Main', 'portion' => 'plate', 'qty' => 1]],
            'salad_qty' => 1,
            'dessert_qty' => 1,
            'day_total' => 123.45,
        ]],
    ]);

    $this->postJson('/api/public/daily-dish/orders', $payload)
        ->assertOk()
        ->assertJson(['success' => true]);

    $order = Order::query()->firstOrFail();
    expect($order->customer_id)->toBeNull();
    expect((int) $order->user_id)->toBe($user->id);
    expect($order->notes)->toBe('Leave at reception');
});

it('allows verified customer orders without branch access and forces branch 1', function () {
    [, $customer] = actingAsVerifiedCustomer();
    seedActiveBranch(99);

    $main = MenuItem::factory()->create(['code' => 'MAIN-WEB', 'name' => 'Website Main']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-WEB', 'name' => 'Website Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-WEB', 'name' => 'Website Dessert']);
    createPublishedMenuForDate('2026-03-05', 1, $main, $salad, $dessert);

    $payload = createWebsiteOrderPayload([
        'branch_id' => 99,
        'phone' => '66752347',
        'email' => 'portal@example.com',
    ]);

    $this->postJson('/api/public/daily-dish/orders', $payload)
        ->assertOk()
        ->assertJson(['success' => true]);

    $order = Order::query()->latest('id')->firstOrFail();
    expect((int) $order->customer_id)->toBe($customer->id);
    expect((int) $order->branch_id)->toBe(1);
});

it('rejects unverified customer order submission', function () {
    $customer = Customer::factory()->create([
        'phone_verified_at' => null,
        'email' => 'portal@example.com',
    ]);
    $user = User::factory()->create([
        'email' => 'portal@example.com',
        'customer_id' => $customer->id,
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);

    $this->postJson('/api/public/daily-dish/orders', [
        'branch_id' => 1,
        'customerName' => 'Portal User',
        'phone' => '55123456',
        'email' => 'portal@example.com',
        'address' => 'Doha',
        'items' => [[
            'key' => '2026-03-05',
            'mains' => [['name' => 'Website Main', 'portion' => 'plate', 'qty' => 1]],
            'salad_qty' => 1,
            'dessert_qty' => 1,
            'day_total' => 123.45,
        ]],
    ])->assertStatus(403)->assertJson(['code' => 'PHONE_NOT_VERIFIED']);
});

it('uses the authenticated user customer link for verification even when another customer has the same phone', function () {
    $phone = '66752347';

    Customer::factory()->create([
        'phone' => $phone,
        'phone_e164' => '+974'.$phone,
        'phone_verified_at' => null,
        'email' => 'guest-duplicate@example.com',
    ]);

    $customer = Customer::factory()->create([
        'phone' => $phone,
        'phone_e164' => '+974'.$phone,
        'phone_verified_at' => now(),
        'email' => 'verified-linked@example.com',
    ]);

    $user = User::factory()->create([
        'email' => 'verified-linked@example.com',
        'customer_id' => $customer->id,
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);

    $main = MenuItem::factory()->create(['code' => 'MAIN-DUP', 'name' => 'Duplicate Main']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-DUP', 'name' => 'Duplicate Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-DUP', 'name' => 'Duplicate Dessert']);
    createPublishedMenuForDate('2026-03-06', 1, $main, $salad, $dessert);

    $this->postJson('/api/public/daily-dish/orders', [
        'branch_id' => 1,
        'customerName' => 'Verified Linked',
        'phone' => $phone,
        'email' => 'verified-linked@example.com',
        'address' => 'Doha',
        'items' => [[
            'key' => '2026-03-06',
            'mains' => [['name' => 'Duplicate Main', 'portion' => 'plate', 'qty' => 1]],
            'salad_qty' => 1,
            'dessert_qty' => 1,
            'day_total' => 18.5,
        ]],
    ])->assertOk()->assertJson(['success' => true]);

    $order = Order::query()->latest('id')->firstOrFail();
    expect((int) $order->customer_id)->toBe($customer->id);
});

it('writes a submission audit trail for successful customer portal orders', function () {
    $user = User::factory()->create([
        'email' => 'portal@example.com',
        'customer_id' => null,
        'portal_name' => 'Portal Customer',
        'portal_phone' => '55123456',
        'portal_phone_e164' => '+97455123456',
        'portal_delivery_address' => 'West Bay',
        'portal_phone_verified_at' => now(),
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);

    $main = MenuItem::factory()->create(['code' => 'MAIN-AUDIT', 'name' => 'Website Main']);
    $salad = MenuItem::factory()->create(['code' => 'SALAD-AUDIT', 'name' => 'Website Salad']);
    $dessert = MenuItem::factory()->create(['code' => 'DES-AUDIT', 'name' => 'Website Dessert']);
    createPublishedMenuForDate('2026-03-07', 1, $main, $salad, $dessert);

    $response = $this->postJson('/api/public/daily-dish/orders', createWebsiteOrderPayload([
        'items' => [[
            'key' => '2026-03-07',
            'notes' => 'Leave at reception',
            'mains' => [['name' => 'Website Main', 'portion' => 'plate', 'qty' => 1]],
            'salad_qty' => 1,
            'dessert_qty' => 1,
            'day_total' => 123.45,
        ]],
    ]))->assertOk();

    $auditId = $response->json('audit_id');
    expect($auditId)->not->toBeEmpty();

    $received = OpsEvent::query()->where('event_type', 'customer_portal_order_submission_received')->firstOrFail();
    $completed = OpsEvent::query()->where('event_type', 'customer_portal_order_submission_completed')->firstOrFail();

    expect(data_get($received->metadata_json, 'audit_id'))->toBe($auditId);
    expect(data_get($received->metadata_json, 'link_status'))->toBe('unlinked');
    expect(data_get($received->metadata_json, 'service_dates'))->toBe(['2026-03-07']);

    expect(data_get($completed->metadata_json, 'audit_id'))->toBe($auditId);
    expect(data_get($completed->metadata_json, 'link_status'))->toBe('unlinked');
    expect(data_get($completed->metadata_json, 'order_count'))->toBe(1);
    expect(data_get($completed->metadata_json, 'order_ids'))->toHaveCount(1);
});

it('writes a failed submission audit trail when order creation is rejected after request validation', function () {
    $user = User::factory()->create([
        'email' => 'portal@example.com',
        'customer_id' => null,
        'portal_name' => 'Portal Customer',
        'portal_phone' => '55123456',
        'portal_phone_e164' => '+97455123456',
        'portal_delivery_address' => 'West Bay',
        'portal_phone_verified_at' => now(),
    ]);
    $user->assignRole('customer');
    Sanctum::actingAs($user, ['customer:*']);

    $this->postJson('/api/public/daily-dish/orders', createWebsiteOrderPayload([
        'items' => [[
            'key' => '2026-03-08',
            'mains' => [['name' => 'Missing Menu Main', 'portion' => 'plate', 'qty' => 1]],
            'salad_qty' => 1,
            'dessert_qty' => 1,
            'day_total' => 123.45,
        ]],
    ]))->assertStatus(422);

    expect(OpsEvent::query()->where('event_type', 'customer_portal_order_submission_received')->count())->toBe(1);
    expect(OpsEvent::query()->where('event_type', 'customer_portal_order_submission_completed')->exists())->toBeFalse();

    $failed = OpsEvent::query()->where('event_type', 'customer_portal_order_submission_failed')->firstOrFail();
    expect(data_get($failed->metadata_json, 'stage'))->toBe('create');
    expect(data_get($failed->metadata_json, 'error_class'))->toBe(\Illuminate\Validation\ValidationException::class);
    expect(data_get($failed->metadata_json, 'service_dates'))->toBe(['2026-03-08']);
});
