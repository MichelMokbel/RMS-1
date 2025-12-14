<?php

use App\Models\OpsEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('enforces valid order transitions and logs ops events', function () {
    $order = Order::factory()->create([
        'status' => 'Confirmed',
        'type' => 'Delivery',
        'scheduled_date' => '2025-01-10',
        'branch_id' => 1,
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'status' => 'Pending']);

    $svc = app(OrderWorkflowService::class);
    $svc->advanceOrder($order, 'InProduction', 1);

    $order->refresh();
    expect($order->status)->toBe('InProduction');
    expect(OpsEvent::query()->where('event_type', 'order_status_changed')->where('order_id', $order->id)->exists())->toBeTrue();

    $svc->advanceOrder($order, 'Ready', 1);
    $order->refresh();
    expect($order->status)->toBe('Ready');

    // Delivery can go OutForDelivery -> Delivered
    $svc->advanceOrder($order, 'OutForDelivery', 1);
    $order->refresh();
    expect($order->status)->toBe('OutForDelivery');

    $svc->advanceOrder($order, 'Delivered', 1);
    $order->refresh();
    expect($order->status)->toBe('Delivered');
});

it('enforces valid item transitions and logs ops events', function () {
    $order = Order::factory()->create([
        'status' => 'Confirmed',
        'scheduled_date' => '2025-01-10',
        'branch_id' => 1,
    ]);
    $item = OrderItem::factory()->create(['order_id' => $order->id, 'status' => 'Pending']);

    $svc = app(OrderWorkflowService::class);
    $svc->setItemStatus($item, 'InProduction', 1);
    $item->refresh();
    expect($item->status)->toBe('InProduction');

    $svc->setItemStatus($item, 'Ready', 1);
    $item->refresh();
    expect($item->status)->toBe('Ready');

    $svc->setItemStatus($item, 'Completed', 1);
    $item->refresh();
    expect($item->status)->toBe('Completed');

    expect(OpsEvent::query()->where('event_type', 'item_status_changed')->where('order_item_id', $item->id)->exists())->toBeTrue();
});


