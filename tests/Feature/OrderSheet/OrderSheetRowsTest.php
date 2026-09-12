<?php

use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderSheet;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->actingAs(User::factory()->create(['status' => 'active'])->assignRole('admin'));
    $this->customer = Customer::factory()->create();
    MealSubscription::factory()->create(['customer_id' => $this->customer->id]);
});

it('lists actual orders without seeding empty subscription rows', function () {
    $other = Customer::factory()->create(['name' => $this->customer->name]);
    MealSubscription::factory()->create(['customer_id' => $other->id]);
    $orders = Order::factory()->dailyDish()->count(2)->create(['customer_id' => $this->customer->id]);

    $page = Volt::test('order-sheet');
    $rows = collect($page->get('rows'))->filter(fn ($row) => filled($row['customer_name']));
    expect($rows)->toHaveCount(2)
        ->and($rows->where('customer_id', $this->customer->id)->pluck('order_id')->sort()->values()->all())->toBe($orders->modelKeys())
        ->and($rows->where('customer_id', $other->id))->toHaveCount(0);
    $page->assertSee('2 entries')->assertDontSee('2/3 filled');
});

it('cleans saved empty placeholders on reload and save without deleting orders', function () {
    $order = Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id]);
    $sheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    foreach ([null, $order->id] as $orderId) {
        $sheet->entries()->create(['customer_id' => $this->customer->id, 'customer_name' => $this->customer->name, 'order_id' => $orderId]);
    }
    $page = Volt::test('order-sheet')->assertSet('unpublishedCount', 0);
    expect(collect($page->get('rows'))->where('customer_id', $this->customer->id))->toHaveCount(1);
    $page->call('save')->assertHasNoErrors()->set('sheetDate', now()->toDateString());
    expect($sheet->entries()->count())->toBe(1)
        ->and($sheet->entries()->first()->order_id)->toBe($order->id)
        ->and(Order::whereKey($order->id)->exists())->toBeTrue();
});

it('keeps manually entered planning data alongside an existing order', function () {
    Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id]);
    $sheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    $sheet->entries()->create(['customer_id' => $this->customer->id, 'customer_name' => $this->customer->name, 'remarks' => 'Extra delivery requested']);
    $page = Volt::test('order-sheet');
    expect(collect($page->get('rows'))->where('customer_id', $this->customer->id))->toHaveCount(2);
});

it('does not include cancelled or other-date orders or empty subscribers', function () {
    Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id, 'status' => 'Cancelled']);
    Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id, 'scheduled_date' => now()->addDay()->toDateString()]);
    $page = Volt::test('order-sheet');
    $rows = collect($page->get('rows'))->where('customer_id', $this->customer->id);
    expect($rows)->toHaveCount(0);
    expect($page->get('rows'))->toHaveCount(1); // One editable blank row remains.
});

it('sums repeated dish lines instead of overwriting quantities', function () {
    $item = MenuItem::factory()->create();
    $menu = DailyDishMenu::create(['branch_id' => 1, 'service_date' => now()->toDateString(), 'status' => 'published']);
    $column = $menu->items()->create(['menu_item_id' => $item->id, 'role' => 'main', 'sort_order' => 1]);
    $order = Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id]);
    foreach ([2, 3] as $quantity) {
        $order->items()->create(['menu_item_id' => $item->id, 'description_snapshot' => $item->name, 'quantity' => $quantity, 'unit_price' => 0, 'line_total' => 0, 'status' => 'Pending']);
    }
    $page = Volt::test('order-sheet');
    $row = collect($page->get('rows'))->firstWhere('order_id', $order->id);
    expect($row['qty'][$column->id])->toBe(5);
});

it('omits saved name-only rows even when orders have no customer id and in both print reports', function () {
    $sheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    $sheet->entries()->create(['customer_id' => $this->customer->id, 'customer_name' => 'Empty subscriber']);
    $order = Order::factory()->dailyDish()->create(['customer_id' => null, 'customer_name_snapshot' => 'Actual meal order']);
    $entry = $sheet->entries()->create(['customer_name' => 'Actual meal order', 'order_id' => $order->id]);
    $item = MenuItem::factory()->create();
    $entry->extras()->create(['menu_item_id' => $item->id, 'menu_item_name' => $item->name, 'quantity' => 3]);
    $page = Volt::test('order-sheet')->assertDontSee('Empty subscriber')->assertSee('Actual meal order');
    expect(collect($page->get('rows'))->whereNotNull('order_id'))->toHaveCount(1);
    foreach (['order-sheet.print.by-order', 'order-sheet.print.by-item'] as $route) {
        $this->get(route($route, ['date' => now()->toDateString()]))->assertOk()
            ->assertViewHas('entries', fn ($entries) => $entries->count() === 1 && $entries->first()['order_id'] === $order->id)
            ->assertViewHas('extraTotals', fn ($totals) => $totals[$item->name]['quantity'] === 3);
    }
    expect($sheet->entries()->count())->toBe(2); // Viewing or printing does not delete saved data.
});

it('keeps saved manual dish quantities and extras without an order', function () {
    $item = MenuItem::factory()->create();
    $menu = DailyDishMenu::create(['branch_id' => 1, 'service_date' => now()->toDateString(), 'status' => 'published']);
    $column = $menu->items()->create(['menu_item_id' => $item->id, 'role' => 'main', 'sort_order' => 1]);
    $sheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    $entry = $sheet->entries()->create(['customer_name' => 'Manual meal']);
    $entry->quantities()->create(['daily_dish_menu_item_id' => $column->id, 'quantity' => 2]);
    $extra = $sheet->entries()->create(['customer_name' => 'Manual extra']);
    $extra->extras()->create(['menu_item_id' => $item->id, 'menu_item_name' => $item->name, 'quantity' => 4]);
    $page = Volt::test('order-sheet')->assertSee('Manual meal')->assertSee('Manual extra');
    expect($page->get('rows'))->toHaveCount(3);
    $this->get(route('order-sheet.print.by-order'))->assertOk()
        ->assertViewHas('dishTotals', fn ($totals) => $totals[$column->id]['quantity'] === 2)
        ->assertViewHas('extraTotals', fn ($totals) => $totals[$item->name]['quantity'] === 4);
});

it('downloads the current sheet including unsaved edits as Excel without saving orders', function () {
    $page = Volt::test('order-sheet');
    $page->set('rows.0.customer_name', '=Literal customer')
        ->set('rows.0.extras', [['menu_item_id' => 1, 'menu_item_name' => 'Extra meal', 'quantity' => 3]])
        ->call('exportExcel')->assertHasNoErrors()
        ->assertFileDownloaded('order-sheet-'.now()->toDateString().'.xlsx');
    expect(OrderSheet::count())->toBe(0)->and(Order::count())->toBe(0);
});

it('rejects Excel export for a user outside the order sheet roles', function () {
    $page = Volt::test('order-sheet');
    $this->actingAs(User::factory()->create(['status' => 'active']));
    $page->call('exportExcel')->assertForbidden();
});

it('exports numeric dish totals and literal text in a valid Excel workbook', function () {
    $response = app(\App\Services\OrderSheet\OrderSheetExcelExport::class)->download('2026-09-10', [
        ['id' => 7, 'name' => 'Main dish'],
    ], [
        ['order_id' => 23, 'customer_name' => '=Literal name', 'location' => 'Office', 'qty' => [7 => 2], 'extras' => [
            ['menu_item_name' => 'Salad', 'quantity' => 3],
        ], 'remarks' => 'No onions'],
        ['customer_name' => ''],
    ]);
    $path = $response->getFile()->getPathname();
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
    $zip->close();
    unlink($path);
    $xml->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    expect($xml->xpath('//s:row'))->toHaveCount(3)
        ->and((string) $xml->xpath('//s:c[@r="C2"]/s:is/s:t')[0])->toBe('=Literal name')
        ->and($xml->xpath('//s:f'))->toHaveCount(0)
        ->and((string) $xml->xpath('//s:c[@r="E3"]/s:v')[0])->toBe('2')
        ->and((string) $xml->xpath('//s:c[@r="G3"]/s:v')[0])->toBe('3')
        ->and((string) $xml->xpath('//s:c[@r="H3"]/s:v')[0])->toBe('5');
});

it('shows online delivery locations on the page and print while preserving manual sheet locations', function () {
    $order = Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id, 'delivery_address_snapshot' => 'Building 12, West Bay']);
    $page = Volt::test('order-sheet');
    expect(collect($page->get('rows'))->firstWhere('order_id', $order->id)['location'])->toBe('Building 12, West Bay');
    $sheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    $entry = $sheet->entries()->create(['order_id' => $order->id, 'customer_name' => 'Delivery customer']);
    $response = $this->get(route('order-sheet.print.by-order'))->assertOk()->assertSee('Building 12, West Bay');
    expect(substr_count($response->getContent(), '<section class="blank-sheet">'))->toBe(2);
    $entry->update(['location' => 'Reception desk']);
    $this->get(route('order-sheet.print.by-order'))->assertOk()->assertSee('Reception desk')->assertDontSee('Building 12, West Bay');
    expect(collect(Volt::test('order-sheet')->get('rows'))->firstWhere('order_id', $order->id)['location'])->toBe('Reception desk');
});

it('falls back to saved meal plan subscription and profile addresses when the order address is empty', function () {
    $this->customer->update(['delivery_address' => 'Profile location']);
    $order = Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id, 'delivery_address_snapshot' => null]);
    $service = app(\App\Services\OrderSheet\OrderSheetLocationService::class);
    expect($service->forOrders(Order::whereKey($order->id)->get())->get($order->id))->toBe('Profile location');
    $sub = MealSubscription::factory()->create(['customer_id' => $this->customer->id, 'address_snapshot' => 'Subscription location']);
    \App\Models\MealSubscriptionOrder::create(['subscription_id' => $sub->id, 'order_id' => $order->id, 'service_date' => now()->toDateString(), 'branch_id' => 1]);
    expect($service->forOrders(Order::whereKey($order->id)->get())->get($order->id))->toBe('Subscription location');
    $plan = \App\Models\MealPlanRequest::create(['customer_name' => 'Online customer', 'customer_phone' => '12345678', 'plan_meals' => 20, 'status' => 'new', 'delivery_address' => 'Request location']);
    $plan->orders()->attach($order);
    $order->update(['customer_id' => null]);
    expect($service->forOrders(Order::whereKey($order->id)->get())->get($order->id))->toBe('Request location');
    expect(collect(Volt::test('order-sheet')->get('rows'))->firstWhere('order_id', $order->id)['location'])->toBe('Request location');
});

it('selects the customer into the requested row and saves with visible feedback', function () {
    $this->customer->update(['delivery_address' => 'West Bay']);
    $page = Volt::test('order-sheet')
        ->set('rows.0.customer_search', 'partial')
        ->call('selectCustomer', $this->customer->id, 0)
        ->assertSet('rows.0.customer_name', $this->customer->name)
        ->assertSet('rows.0.customer_search', $this->customer->name)
        ->assertSet('rows.0.location', 'West Bay')
        ->call('save')->assertHasNoErrors()->assertSee('Sheet saved at');
    expect(OrderSheet::first()->entries()->first()->customer_id)->toBe($this->customer->id);
});

it('creates a customer with name and phone and inserts them into the sheet', function () {
    $page = Volt::test('order-sheet')->set('rows.0.customer_search', 'New guest')
        ->call('startCustomerCreation', 0)->assertSet('newCustomerName', 'New guest')
        ->set('newCustomerPhone', '5551234567')->call('createCustomer')->assertHasNoErrors()
        ->assertSet('rows.0.customer_name', 'New guest')->assertSet('newCustomerRow', null)
        ->assertSee('Customer created and added');
    $customer = Customer::findOrFail($page->get('rows.0.customer_id'));
    expect($customer->phone)->toBe('5551234567')->and($customer->customer_code)->not->toBeEmpty();
    $page->set('rows.0.remarks', 'Manual delivery')->call('save')->assertHasNoErrors();
    expect(OrderSheet::first()->entries()->first()->customer_id)->toBe($customer->id);
});

it('validates quick customer creation before inserting a customer', function () {
    $count = Customer::count();
    Volt::test('order-sheet')->call('startCustomerCreation', 0)->call('createCustomer')
        ->assertHasErrors(['newCustomerName', 'newCustomerPhone']);
    expect(Customer::count())->toBe($count);
});

it('preserves customer creation permissions for order sheet staff', function () {
    Role::findOrCreate('staff');
    $this->actingAs(User::factory()->create(['status' => 'active'])->assignRole('staff'));
    Volt::test('order-sheet')->call('startCustomerCreation', 0)->assertForbidden();
    Volt::test('order-sheet')->set('newCustomerRow', 0)->set('newCustomerName', 'Forbidden')
        ->set('newCustomerPhone', '5550000')->call('createCustomer')->assertForbidden();
});

it('rejects invalid quantities without replacing the saved sheet', function () {
    $sheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    $entry = $sheet->entries()->create(['customer_name' => 'Keep me', 'remarks' => 'Saved']);
    Volt::test('order-sheet')->set('rows.0.qty.999', -1)->call('save')->assertHasErrors(['rows.0.qty.999']);
    expect($entry->fresh()->remarks)->toBe('Saved');
});
