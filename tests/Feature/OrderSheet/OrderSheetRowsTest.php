<?php

use App\Models\Customer;
use App\Models\DailyDishMenu;
use App\Models\MealSubscription;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderSheet;
use App\Models\OrderSheetEntry;
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
    expect($page->get('rows'))->toHaveCount(5)
        ->and(collect($page->get('rows'))->every(fn ($row) => blank($row['customer_name'])))->toBeTrue();
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
    expect($page->get('rows'))->toHaveCount(7);
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
        ['id' => 7, 'name' => 'Main dish', 'role' => 'main'],
    ], [
        ['order_id' => 23, 'customer_name' => '=Literal name', 'location' => 'Office', 'qty' => [7 => ['plate' => 2, 'half' => 0, 'full' => 0]], 'extras' => [
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
        ->and((string) $xml->xpath('//s:c[@r="I3"]/s:v')[0])->toBe('3')
        ->and((string) $xml->xpath('//s:c[@r="J3"]/s:v')[0])->toBe('5');
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
        ->assertSet('rows.1.customer_name', '')
        ->call('save')->assertHasNoErrors()->assertSee('Sheet saved at');
    expect(OrderSheet::first()->entries()->first()->customer_id)->toBe($this->customer->id);
});

it('deletes the requested row without moving another rows identity or quantities', function () {
    $item = MenuItem::factory()->create();
    $menu = DailyDishMenu::create(['branch_id' => 1, 'service_date' => now()->toDateString(), 'status' => 'published']);
    $column = $menu->items()->create(['menu_item_id' => $item->id, 'role' => 'main', 'sort_order' => 1]);
    $page = Volt::test('order-sheet')
        ->set('rows.0.customer_name', 'First customer')
        ->set("rows.0.qty.{$column->id}", 1)
        ->set('rows.1.customer_name', 'Delete this customer')
        ->set("rows.1.qty.{$column->id}", 2)
        ->set('rows.2.customer_name', 'Third customer')
        ->set("rows.2.qty.{$column->id}", 3);

    $rows = $page->get('rows');
    $firstKey = $rows[0]['row_key'];
    $deletedKey = $rows[1]['row_key'];
    $thirdKey = $rows[2]['row_key'];
    $page->call('removeRow', $deletedKey);

    $remaining = collect($page->get('rows'))->keyBy('row_key');
    expect($remaining)->toHaveKeys([$firstKey, $thirdKey])
        ->not->toHaveKey($deletedKey)
        ->and($remaining[$firstKey]['customer_name'])->toBe('First customer')
        ->and($remaining[$firstKey]['qty'][$column->id])->toBe(1)
        ->and($remaining[$thirdKey]['customer_name'])->toBe('Third customer')
        ->and($remaining[$thirdKey]['qty'][$column->id])->toBe(3);
});

it('keeps a deliberately removed order off the sheet after save and reload', function () {
    $order = Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id]);
    $sheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    $entry = $sheet->entries()->create([
        'customer_id' => $this->customer->id,
        'customer_name' => $this->customer->name,
        'order_id' => $order->id,
    ]);

    $page = Volt::test('order-sheet');
    $rowKey = collect($page->get('rows'))->firstWhere('order_id', $order->id)['row_key'];
    $page->call('removeRow', $rowKey)->call('save')->assertHasNoErrors();

    expect(Order::whereKey($order->id)->exists())->toBeTrue()
        ->and(OrderSheet::findOrFail($sheet->id)->excluded_order_ids)->toContain($order->id)
        ->and(OrderSheetEntry::whereKey($entry->id)->exists())->toBeFalse()
        ->and(collect($page->get('rows'))->pluck('order_id'))->not->toContain($order->id);

    $reloaded = Volt::test('order-sheet');
    expect(collect($reloaded->get('rows'))->pluck('order_id'))->not->toContain($order->id);
});

it('replaces row identities and item counts when the sheet date changes', function () {
    $todayItem = MenuItem::factory()->create(['name' => 'Today dish']);
    $todayMenu = DailyDishMenu::create(['branch_id' => 1, 'service_date' => now()->toDateString(), 'status' => 'published']);
    $todayColumn = $todayMenu->items()->create(['menu_item_id' => $todayItem->id, 'role' => 'main', 'sort_order' => 1]);
    $tomorrowItem = MenuItem::factory()->create(['name' => 'Tomorrow dish']);
    $tomorrowMenu = DailyDishMenu::create(['branch_id' => 1, 'service_date' => now()->addDay()->toDateString(), 'status' => 'published']);
    $tomorrowColumn = $tomorrowMenu->items()->create(['menu_item_id' => $tomorrowItem->id, 'role' => 'main', 'sort_order' => 1]);
    $todaySheet = OrderSheet::create(['sheet_date' => now()->toDateString()]);
    $todayEntry = $todaySheet->entries()->create(['customer_name' => 'Today customer']);
    $todayEntry->quantities()->create(['daily_dish_menu_item_id' => $todayColumn->id, 'quantity' => 2]);
    $tomorrowSheet = OrderSheet::create(['sheet_date' => now()->addDay()->toDateString()]);
    $tomorrowEntry = $tomorrowSheet->entries()->create(['customer_name' => 'Tomorrow customer']);
    $tomorrowEntry->quantities()->create(['daily_dish_menu_item_id' => $tomorrowColumn->id, 'quantity' => 4]);

    $page = Volt::test('order-sheet');
    $todayKey = collect($page->get('rows'))->firstWhere('customer_name', 'Today customer')['row_key'];
    $page->call('nextDay');
    $tomorrowRow = collect($page->get('rows'))->firstWhere('customer_name', 'Tomorrow customer');

    expect($tomorrowRow['row_key'])->toBe('entry-'.$tomorrowEntry->id)
        ->not->toBe($todayKey)
        ->and($tomorrowRow['qty'][$tomorrowColumn->id])->toBe(4)
        ->and($page->html())->toContain('wire:key="order-sheet-page"')
        ->and($page->html())->toContain('Tomorrow dish')
        ->not->toContain('Today dish');
});

it('returns customer results without rendering the full sheet', function () {
    Volt::test('order-sheet')
        ->call('searchCustomers', $this->customer->name)
        ->assertReturned(fn (array $results) => collect($results)->contains(
            fn (array $customer) => $customer['id'] === $this->customer->id
                && $customer['name'] === $this->customer->name
        ));
});

it('renders a new blank fallback when the last available row receives a customer', function () {
    $page = Volt::test('order-sheet');
    $rows = $page->get('rows');
    $lastRowKey = $rows[array_key_last($rows)]['row_key'];

    $page->call('selectCustomerAndAppend', $this->customer->id, $lastRowKey)->assertHasNoErrors();

    $updatedRows = $page->get('rows');
    expect($updatedRows)->toHaveCount(count($rows) + 1)
        ->and(collect($updatedRows)->firstWhere('row_key', $lastRowKey)['customer_name'])->toBe($this->customer->name)
        ->and($updatedRows[array_key_last($updatedRows)]['customer_name'])->toBe('');
});

it('targets extra dishes by stable row identity after another row is removed', function () {
    $extra = MenuItem::factory()->create(['name' => 'Stable extra']);
    $page = Volt::test('order-sheet')
        ->set('rows.0.customer_name', 'Remove me')
        ->set('rows.1.customer_name', 'Keep me');
    $removedKey = $page->get('rows.0.row_key');
    $keptKey = $page->get('rows.1.row_key');

    $page->call('removeRow', $removedKey)
        ->call('addExtra', $keptKey, $extra->id, $extra->name)
        ->assertHasNoErrors();

    $keptRow = collect($page->get('rows'))->firstWhere('row_key', $keptKey);
    expect($keptRow['customer_name'])->toBe('Keep me')
        ->and($keptRow['extras'])->toHaveCount(1)
        ->and($keptRow['extras'][0]['menu_item_id'])->toBe($extra->id);
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

it('opens quick customer creation on the first visible blank row', function () {
    Volt::test('order-sheet')->call('startCustomerCreation')
        ->assertSet('newCustomerRow', 0)
        ->assertSet('showCustomerForm', true);
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

it('renders only the active layout and keeps common row actions local', function () {
    $desktop = Volt::test('order-sheet');
    expect(substr_count($desktop->html(), 'wire:key="desktop-row-'))->toBe(5)
        ->and($desktop->html())->not->toContain('wire:key="mobile-row-')
        ->and($desktop->html())->toContain('data-order-sheet-customer-search')
        ->not->toContain('wire:focus="focusCustomerSearch')
        ->not->toContain('wire:model.live.debounce.250ms="rows.')
        ->and($desktop->html())->toContain('loadCustomerResults(')
        ->and($desktop->html())->toContain('x-on:click.stop="openExtraSearch(')
        ->and($desktop->html())->toContain('removeRowImmediately(')
        ->and($desktop->html())->toContain('quantity(')
        ->not->toContain('$wire.entangle(\'rows.')
        ->and($desktop->html())->toContain('x-on:click="revealRow()"')
        ->not->toContain('wire:click="bump(');

    $mobile = Volt::test('order-sheet')->call('setMobileLayout', true);
    expect($mobile->html())->toContain('wire:key="mobile-row-')
        ->not->toContain('wire:key="desktop-row-')
        ->not->toContain('wire:focus="focusCustomerSearch')
        ->and($mobile->html())->toContain('x-on:click.stop="openExtraSearch(')
        ->and($mobile->html())->toContain('x-on:click="revealRow()"')
        ->and($mobile->html())->toContain('buildOrderSheetPrintTable()');
});

it('preserves pending quantities through layout changes and save', function () {
    $item = MenuItem::factory()->create();
    $menu = DailyDishMenu::create(['branch_id' => 1, 'service_date' => now()->toDateString(), 'status' => 'published']);
    $column = $menu->items()->create(['menu_item_id' => $item->id, 'role' => 'main', 'sort_order' => 1]);

    $page = Volt::test('order-sheet')
        ->set('rows.0.customer_id', $this->customer->id)
        ->set('rows.0.customer_name', $this->customer->name)
        ->set("rows.0.qty.{$column->id}", 3)
        ->call('setMobileLayout', true)
        ->assertSet("rows.0.qty.{$column->id}", 3)
        ->call('setMobileLayout', false)
        ->assertSet("rows.0.qty.{$column->id}", 3)
        ->call('save')
        ->assertHasNoErrors();

    expect(OrderSheet::first()->entries()->first()->quantities()->value('quantity'))->toBe(3);
    $page->set('sheetDate', now()->toDateString());
    expect($page->get("rows.0.qty.{$column->id}"))->toBe(3);
});
