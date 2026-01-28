<?php

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\MenuItem;
use App\Services\DailyDish\DailyDishOpsQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prep totals include only Confirmed and InProduction orders', function () {
    $date = '2025-01-10';

    $confirmed = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'scheduled_date' => $date,
        'status' => 'Confirmed',
        'source' => 'Backoffice',
    ]);
    $inProd = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'scheduled_date' => $date,
        'status' => 'InProduction',
        'source' => 'Backoffice',
    ]);
    $ready = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'scheduled_date' => $date,
        'status' => 'Ready',
        'source' => 'Backoffice',
    ]);

    $menuItem = MenuItem::factory()->create();

    // same menu item ID across orders should sum
    OrderItem::factory()->create([
        'order_id' => $confirmed->id,
        'menu_item_id' => $menuItem->id,
        'description_snapshot' => 'Main A',
        'quantity' => 1.000,
        'line_total' => 0,
    ]);
    OrderItem::factory()->create([
        'order_id' => $inProd->id,
        'menu_item_id' => $menuItem->id,
        'description_snapshot' => 'Main A',
        'quantity' => 2.000,
        'line_total' => 0,
    ]);
    OrderItem::factory()->create([
        'order_id' => $ready->id,
        'menu_item_id' => $menuItem->id,
        'description_snapshot' => 'Main A',
        'quantity' => 5.000,
        'line_total' => 0,
    ]);

    $service = app(DailyDishOpsQueryService::class);
    $totals = $service->getPrepTotals(1, $date, [
        'department' => 'DailyDish',
        'statuses' => ['Confirmed', 'InProduction'],
        'include_subscription' => true,
        'include_manual' => true,
    ]);

    $row = $totals->firstWhere('menu_item_id', $menuItem->id);
    expect($row)->not->toBeNull();
    expect((float) $row->total_quantity)->toBe(3.0);
});

it('prep totals can exclude subscription or manual orders', function () {
    $date = '2025-01-10';

    $subOrder = Order::factory()->subscription()->create([
        'branch_id' => 1,
        'scheduled_date' => $date,
        'status' => 'Confirmed',
    ]);
    $manualOrder = Order::factory()->dailyDish()->create([
        'branch_id' => 1,
        'scheduled_date' => $date,
        'status' => 'Confirmed',
        'source' => 'Backoffice',
    ]);

    $subMenuItem = MenuItem::factory()->create();
    $manualMenuItem = MenuItem::factory()->create();

    OrderItem::factory()->create([
        'order_id' => $subOrder->id,
        'menu_item_id' => $subMenuItem->id,
        'description_snapshot' => 'Sub Item',
        'quantity' => 1.000,
        'line_total' => 0,
    ]);
    OrderItem::factory()->create([
        'order_id' => $manualOrder->id,
        'menu_item_id' => $manualMenuItem->id,
        'description_snapshot' => 'Manual Item',
        'quantity' => 1.000,
        'line_total' => 0,
    ]);

    $service = app(DailyDishOpsQueryService::class);

    $onlyManual = $service->getPrepTotals(1, $date, [
        'department' => 'DailyDish',
        'include_subscription' => false,
        'include_manual' => true,
    ]);
    expect($onlyManual->firstWhere('menu_item_id', $subMenuItem->id))->toBeNull();
    expect($onlyManual->firstWhere('menu_item_id', $manualMenuItem->id))->not->toBeNull();

    $onlySub = $service->getPrepTotals(1, $date, [
        'department' => 'DailyDish',
        'include_subscription' => true,
        'include_manual' => false,
    ]);
    expect($onlySub->firstWhere('menu_item_id', $manualMenuItem->id))->toBeNull();
    expect($onlySub->firstWhere('menu_item_id', $subMenuItem->id))->not->toBeNull();
});

