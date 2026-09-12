<?php

use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderSheet;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->actingAs(User::factory()->create(['status' => 'active'])->assignRole('admin'));
    $this->customer = Customer::factory()->create(['delivery_address' => 'West Bay']);
});

function editorRow(array $overrides = []): array
{
    return array_replace_recursive([
        'key' => 'test-row',
        'order_id' => null,
        'customer_id' => null,
        'customer_name' => '',
        'location' => '',
        'quantities' => [],
        'extras' => [],
        'remarks' => '',
    ], $overrides);
}

it('routes the order sheet to the replacement editor', function () {
    $this->get(route('order-sheet.index'))
        ->assertOk()
        ->assertSee('data-order-sheet-v2', false)
        ->assertSee('orderSheetV2(', false);
});

it('returns customer search results with the location required by the editor', function () {
    DB::table('meal_plan_requests')->insert([
        'customer_id' => $this->customer->id,
        'customer_name' => $this->customer->name,
        'customer_phone' => $this->customer->phone,
        'delivery_address' => 'Latest meal plan location',
        'plan_meals' => 20,
        'status' => 'new',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $portalCustomer = Customer::factory()->create([
        'name' => 'Portal location customer',
        'delivery_address' => null,
    ]);
    User::factory()->create([
        'customer_id' => $portalCustomer->id,
        'portal_delivery_address' => 'Portal profile location',
    ]);

    Volt::test('order-sheet-v2')
        ->call('searchCustomers', $this->customer->name, now()->toDateString())
        ->assertReturned(fn (array $results) => collect($results)->contains(
            fn (array $customer) => $customer['id'] === $this->customer->id
                && $customer['name'] === $this->customer->name
                && $customer['location'] === 'Latest meal plan location'
        ))
        ->call('searchCustomers', $portalCustomer->name, now()->toDateString())
        ->assertReturned(fn (array $results) => collect($results)->contains(
            fn (array $customer) => $customer['id'] === $portalCustomer->id
                && $customer['location'] === 'Portal profile location'
        ));
});

it('loads independent data when the date changes', function () {
    $tomorrow = now()->addDay()->toDateString();
    $order = Order::factory()->dailyDish()->create([
        'customer_id' => $this->customer->id,
        'scheduled_date' => $tomorrow,
    ]);

    Volt::test('order-sheet-v2')
        ->call('loadDate', $tomorrow)
        ->assertReturned(fn (array $payload) => $payload['date'] === $tomorrow
            && collect($payload['rows'])->contains(fn (array $row) => $row['order_id'] === $order->id));
});

it('saves repeatedly and replaces the previous sheet state accurately', function () {
    $item = MenuItem::factory()->create();
    $menu = DailyDishMenu::create([
        'branch_id' => 1,
        'service_date' => now()->toDateString(),
        'status' => 'published',
    ]);
    $column = $menu->items()->create([
        'menu_item_id' => $item->id,
        'role' => 'main',
        'sort_order' => 1,
    ]);
    $row = editorRow([
        'customer_id' => $this->customer->id,
        'customer_name' => $this->customer->name,
        'location' => 'West Bay',
        'quantities' => [$column->id => 2],
    ]);
    $page = Volt::test('order-sheet-v2');

    $page->call('saveSheet', now()->toDateString(), [$row], [])->assertHasNoErrors();
    $row['quantities'][$column->id] = 5;
    $row['remarks'] = 'Second save';
    $page->call('saveSheet', now()->toDateString(), [$row], [])->assertHasNoErrors();

    $sheet = OrderSheet::firstOrFail();
    expect($sheet->entries)->toHaveCount(1)
        ->and($sheet->entries->first()->remarks)->toBe('Second save')
        ->and($sheet->entries->first()->quantities()->value('quantity'))->toBe(5);
});

it('automatically keeps the subscription appetizer equal to selected main dishes', function () {
    Config::set('subscriptions.default_appetizer_code', 'APP-ORDER-SHEET');
    $appetizer = MenuItem::factory()->create([
        'code' => 'APP-ORDER-SHEET',
        'name' => 'Daily Appetizer',
        'is_active' => true,
    ]);
    $mains = collect(['Subscription Main A', 'Subscription Main B', 'Subscription Main C'])
        ->map(fn (string $name) => MenuItem::factory()->create(['name' => $name]));
    $salad = MenuItem::factory()->create(['name' => 'Subscription Salad']);
    $menu = DailyDishMenu::create([
        'branch_id' => 1,
        'service_date' => now()->toDateString(),
        'status' => 'published',
    ]);
    $mainColumns = $mains->values()->map(fn (MenuItem $main, int $index) => $menu->items()->create([
        'menu_item_id' => $main->id,
        'role' => 'main',
        'sort_order' => $index + 1,
    ]));
    $saladColumn = $menu->items()->create([
        'menu_item_id' => $salad->id,
        'role' => 'salad',
        'sort_order' => 4,
    ]);
    $subscription = MealSubscription::factory()->create(['customer_id' => $this->customer->id]);
    $subscription->days()->create(['weekday' => (int) now()->format('N')]);
    $row = editorRow([
        'customer_id' => $this->customer->id,
        'customer_name' => $this->customer->name,
        'quantities' => [
            $mainColumns[0]->id => 2,
            $mainColumns[1]->id => 3,
            $mainColumns[2]->id => 1,
            $saladColumn->id => 8,
        ],
        'extras' => [['menu_item_id' => $appetizer->id, 'name' => $appetizer->name, 'quantity' => 99]],
    ]);
    $page = Volt::test('order-sheet-v2')
        ->call('searchCustomers', $this->customer->name, now()->toDateString())
        ->assertReturned(fn (array $results) => collect($results)->contains(
            fn (array $customer) => $customer['id'] === $this->customer->id
                && $customer['has_subscription'] === true
                && $customer['subscription_appetizer']['menu_item_id'] === $appetizer->id
        ));

    $page->call('saveSheet', now()->toDateString(), [$row], [])->assertHasNoErrors();
    expect(OrderSheet::firstOrFail()->entries()->firstOrFail()->extras()->firstOrFail())
        ->menu_item_id->toBe($appetizer->id)
        ->quantity->toBe(6);

    $row['quantities'][$mainColumns[0]->id] = 1;
    $row['quantities'][$mainColumns[1]->id] = 0;
    $row['quantities'][$mainColumns[2]->id] = 2;
    $page->call('saveSheet', now()->toDateString(), [$row], [])->assertHasNoErrors();
    expect(OrderSheet::firstOrFail()->entries()->firstOrFail()->extras()->firstOrFail()->quantity)->toBe(3);
});

it('rejects a subscribed main selection when the configured appetizer is unavailable', function () {
    Config::set('subscriptions.default_appetizer_code', 'MISSING-APPETIZER');
    $main = MenuItem::factory()->create();
    $menu = DailyDishMenu::create([
        'branch_id' => 1,
        'service_date' => now()->toDateString(),
        'status' => 'published',
    ]);
    $column = $menu->items()->create([
        'menu_item_id' => $main->id,
        'role' => 'main',
        'sort_order' => 1,
    ]);
    $subscription = MealSubscription::factory()->create(['customer_id' => $this->customer->id]);
    $subscription->days()->create(['weekday' => (int) now()->format('N')]);
    $sheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    $sheet->entries()->create(['customer_name' => 'Keep this row']);
    $row = editorRow([
        'customer_id' => $this->customer->id,
        'customer_name' => $this->customer->name,
        'quantities' => [$column->id => 1],
    ]);

    Volt::test('order-sheet-v2')
        ->call('saveSheet', now()->toDateString(), [$row], [])
        ->assertHasErrors(['rows.0.extras']);

    expect($sheet->entries()->firstOrFail()->customer_name)->toBe('Keep this row');
});

it('keeps the original order sheet print format', function () {
    $this->get(route('order-sheet.index'))
        ->assertOk()
        ->assertSee('x-on:click="printSheet()"', false)
        ->assertSee('@page { size: A4 landscape; margin: 14mm; }', false)
        ->assertSee('Additional orders', false)
        ->assertSee('Array.from({ length: 2 }', false);
});

it('downloads current unsaved rows as Excel without saving them', function () {
    $row = editorRow([
        'customer_name' => '=Literal customer',
        'location' => 'Test location',
        'remarks' => 'Unsaved export',
    ]);

    Volt::test('order-sheet-v2')
        ->call('exportSheet', now()->toDateString(), [$row])
        ->assertHasNoErrors()
        ->assertFileDownloaded('order-sheet-'.now()->toDateString().'.xlsx');

    expect(OrderSheet::count())->toBe(0);
});

it('keeps a removed imported order out of later snapshots without deleting the order', function () {
    $order = Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id]);
    $page = Volt::test('order-sheet-v2');

    $page->call('saveSheet', now()->toDateString(), [], [$order->id])
        ->assertReturned(fn (array $result) => collect($result['payload']['rows'])
            ->doesntContain(fn (array $row) => $row['order_id'] === $order->id));

    expect(Order::whereKey($order->id)->exists())->toBeTrue()
        ->and(OrderSheet::firstOrFail()->excluded_order_ids)->toContain($order->id);
});

it('creates a customer for manual entry and rejects unauthorized creation', function () {
    $page = Volt::test('order-sheet-v2')
        ->call('createCustomer', 'Fast customer', '5551234')
        ->assertReturned(fn (array $customer) => $customer['name'] === 'Fast customer' && $customer['phone'] === '5551234');
    expect(Customer::where('name', 'Fast customer')->exists())->toBeTrue();

    $this->actingAs(User::factory()->create(['status' => 'active']));
    Volt::test('order-sheet-v2')->call('createCustomer', 'Forbidden', '5559999')->assertForbidden();
});

it('rejects editor data actions for users outside the order sheet roles', function () {
    $this->actingAs(User::factory()->create(['status' => 'active']));

    Volt::test('order-sheet-v2')
        ->call('loadDate', now()->toDateString())
        ->assertForbidden();
    Volt::test('order-sheet-v2')
        ->call('searchCustomers', $this->customer->name, now()->toDateString())
        ->assertForbidden();
    Volt::test('order-sheet-v2')
        ->call('searchDishes', 'dish')
        ->assertForbidden();
});

it('rejects invalid quantities before replacing saved data', function () {
    $sheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    $sheet->entries()->create(['customer_name' => 'Keep me', 'remarks' => 'Saved']);
    $row = editorRow(['customer_name' => 'Invalid', 'quantities' => [999 => -1]]);

    Volt::test('order-sheet-v2')
        ->call('saveSheet', now()->toDateString(), [$row], [])
        ->assertHasErrors(['rows.0.quantities.999']);

    expect($sheet->entries()->first()->customer_name)->toBe('Keep me');
});
