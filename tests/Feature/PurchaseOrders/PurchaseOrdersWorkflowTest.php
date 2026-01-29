<?php

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
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
