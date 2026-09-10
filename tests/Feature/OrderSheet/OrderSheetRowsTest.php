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

it('replaces the subscription placeholder with existing orders and retains customers without orders', function () {
    $other = Customer::factory()->create(['name' => $this->customer->name]);
    MealSubscription::factory()->create(['customer_id' => $other->id]);
    $orders = Order::factory()->dailyDish()->count(2)->create(['customer_id' => $this->customer->id]);

    $page = Volt::test('order-sheet');
    $rows = collect($page->get('rows'))->filter(fn ($row) => filled($row['customer_name']));
    expect($rows)->toHaveCount(3)
        ->and($rows->where('customer_id', $this->customer->id)->pluck('order_id')->sort()->values()->all())->toBe($orders->modelKeys())
        ->and($rows->where('customer_id', $other->id)->first()['order_id'])->toBeNull();
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

it('does not suppress a placeholder for cancelled or other-date orders', function () {
    Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id, 'status' => 'Cancelled']);
    Order::factory()->dailyDish()->create(['customer_id' => $this->customer->id, 'scheduled_date' => now()->addDay()->toDateString()]);
    $page = Volt::test('order-sheet');
    $rows = collect($page->get('rows'))->where('customer_id', $this->customer->id);
    expect($rows)->toHaveCount(1)->and($rows->first()['order_id'])->toBeNull();
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
