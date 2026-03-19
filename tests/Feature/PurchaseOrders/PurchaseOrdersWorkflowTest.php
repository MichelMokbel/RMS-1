<?php

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderReceiving;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderPersistService;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('creates draft purchase order with lines', function () {
    $supplier = Supplier::factory()->create();
    $item = InventoryItem::factory()->create(['units_per_package' => 1, 'cost_per_unit' => 0]);

    $response = $this->actingAs($this->admin)->post('/api/purchase-orders', [
        'po_number' => 'PO-TEST',
        'supplier_id' => $supplier->id,
        'order_date' => now()->toDateString(),
        'status' => 'draft',
        'lines' => [
            ['item_id' => $item->id, 'quantity' => 2, 'unit_price' => 10.00],
        ],
    ]);

    $response->assertCreated();
    $this->assertDatabaseHas('purchase_orders', ['po_number' => 'PO-TEST', 'status' => 'draft']);
    $poId = $response->json('id');
    expect($poId)->not->toBeNull();
    $qty = \App\Models\PurchaseOrderItem::where('purchase_order_id', $poId)->value('quantity');
    expect((float) $qty)->toBe(2.0);
});

it('approves and receives purchase order', function () {
    $supplier = Supplier::factory()->create();
    $item = InventoryItem::factory()->create(['units_per_package' => 1, 'cost_per_unit' => 0]);

    $po = PurchaseOrder::factory()->pending()->create([
        'po_number' => 'PO-REC',
        'supplier_id' => $supplier->id,
    ]);

    $line = $po->items()->create([
        'item_id' => $item->id,
        'quantity' => 3,
        'unit_price' => 5.00,
        'total_price' => 15.00,
        'received_quantity' => 0,
    ]);

    $this->actingAs($this->admin)->post("/api/purchase-orders/{$po->id}/approve")->assertOk();

    $service = app(PurchaseOrderReceivingService::class);
    $service->receive($po->fresh(), [$line->id => 2], $this->admin->id, null);

    $po->refresh();
    expect($po->status)->toBe(PurchaseOrder::STATUS_APPROVED);
    $item->refresh();
    expect((float) $item->current_stock)->toBe(2.0);
});

it('allows approved purchase-order edits with incremental revision numbers', function () {
    $supplier = Supplier::factory()->create();
    $itemA = InventoryItem::factory()->create(['item_code' => 'ITM-A', 'cost_per_unit' => 10]);
    $itemB = InventoryItem::factory()->create(['item_code' => 'ITM-B', 'cost_per_unit' => 20]);

    $po = PurchaseOrder::factory()->approved()->create([
        'po_number' => 'PO-900001',
        'supplier_id' => $supplier->id,
        'order_date' => now()->toDateString(),
    ]);

    $po->items()->create([
        'item_id' => $itemA->id,
        'quantity' => 1,
        'unit_price' => 10,
        'total_price' => 10,
        'received_quantity' => 0,
    ]);

    /** @var PurchaseOrderPersistService $persist */
    $persist = app(PurchaseOrderPersistService::class);

    $first = $persist->update($po->fresh(), [
        'po_number' => 'SHOULD-BE-IGNORED',
        'supplier_id' => $supplier->id,
        'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addDay()->toDateString(),
        'notes' => 'edit-1',
        'payment_terms' => 'Credit',
        'payment_type' => 'Credit',
        'lines' => [
            ['item_id' => $itemA->id, 'quantity' => 2, 'unit_price' => 11],
        ],
    ], PurchaseOrder::STATUS_DRAFT);

    expect($first->status)->toBe(PurchaseOrder::STATUS_APPROVED);
    expect($first->po_number)->toBe('PO-900001V1');

    $second = $persist->update($first->fresh(), [
        'po_number' => 'SHOULD-BE-IGNORED-2',
        'supplier_id' => $supplier->id,
        'order_date' => now()->toDateString(),
        'expected_delivery_date' => now()->addDays(2)->toDateString(),
        'notes' => 'edit-2',
        'payment_terms' => 'Credit',
        'payment_type' => 'Credit',
        'lines' => [
            ['item_id' => $itemB->id, 'quantity' => 3, 'unit_price' => 20],
        ],
    ], PurchaseOrder::STATUS_PENDING);

    expect($second->status)->toBe(PurchaseOrder::STATUS_APPROVED);
    expect($second->po_number)->toBe('PO-900001V2');
});

it('stores purchase order receiving events and lines with the received timestamp', function () {
    $supplier = Supplier::factory()->create();
    $item = InventoryItem::factory()->create(['units_per_package' => 1, 'cost_per_unit' => 0]);

    $po = PurchaseOrder::factory()->approved()->create([
        'po_number' => 'PO-REC-HISTORY',
        'supplier_id' => $supplier->id,
    ]);

    $line = $po->items()->create([
        'item_id' => $item->id,
        'quantity' => 4,
        'unit_price' => 6.50,
        'total_price' => 26.00,
        'received_quantity' => 0,
    ]);

    $receivedAt = '2026-03-16 14:15:00';

    app(PurchaseOrderReceivingService::class)->receive(
        $po->fresh(),
        [$line->id => 3],
        $this->admin->id,
        'Morning delivery',
        [],
        $receivedAt,
    );

    $receiving = PurchaseOrderReceiving::query()->first();

    expect($receiving)->not->toBeNull();
    expect($receiving->received_at?->format('Y-m-d H:i:s'))->toBe($receivedAt);

    $this->assertDatabaseHas('purchase_order_receivings', [
        'purchase_order_id' => $po->id,
        'created_by' => $this->admin->id,
        'notes' => 'Morning delivery',
    ]);
    $this->assertDatabaseHas('purchase_order_receiving_lines', [
        'purchase_order_receiving_id' => $receiving->id,
        'purchase_order_item_id' => $line->id,
        'inventory_item_id' => $item->id,
    ]);

    expect((float) $item->fresh()->current_stock)->toBe(3.0);
    expect(\App\Models\InventoryTransaction::query()->latest('id')->first()->transaction_date?->format('Y-m-d H:i:s'))->toBe($receivedAt);
});
