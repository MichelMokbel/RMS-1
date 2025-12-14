<?php

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('subscription orders cannot edit items via edit component', function () {
    $order = Order::factory()->subscription()->create([
        'status' => 'Confirmed',
    ]);
    $item = OrderItem::factory()->create([
        'order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 10,
        'line_total' => 10,
    ]);

    Livewire::test('orders.edit', ['order' => $order])
        ->set('items.0.quantity', 2)
        ->call('save')
        ->assertHasNoErrors();

    $item->refresh();
    expect((float) $item->quantity)->toBe(1.0);
});

