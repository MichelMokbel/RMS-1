<?php

use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderReceivingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('manager', 'web');
    $this->manager = User::factory()->create(['status' => 'active']);
    $this->manager->assignRole('manager');
});

it('inventory report filters by parent category and shows hierarchical labels', function () {
    $parent = Category::create(['name' => 'Produce']);
    $child = Category::create(['name' => 'Leafy', 'parent_id' => $parent->id]);
    InventoryItem::factory()->create([
        'item_code' => 'LET-001',
        'name' => 'Lettuce',
        'category_id' => $child->id,
    ]);

    $response = $this->actingAs($this->manager)->get(route('reports.inventory.print', [
        'category_id' => $parent->id,
    ]));

    $response->assertOk();
    $response->assertSeeText('Lettuce');
    $response->assertSeeText('Produce > Leafy');
    $response->assertSee('padding: 0 0 18mm 0 !important;', false);
});

it('inventory transactions report lists different reference types with hierarchical category labels', function () {
    $parent = Category::create(['name' => 'Dry Goods']);
    $child = Category::create(['name' => 'Spices', 'parent_id' => $parent->id]);
    $item = InventoryItem::factory()->create([
        'item_code' => 'SP-001',
        'name' => 'Sumac',
        'category_id' => $child->id,
    ]);

    InventoryTransaction::factory()->create([
        'item_id' => $item->id,
        'branch_id' => 1,
        'transaction_type' => 'in',
        'reference_type' => 'manual',
        'reference_id' => 10,
        'transaction_date' => now(),
    ]);
    InventoryTransaction::factory()->create([
        'item_id' => $item->id,
        'branch_id' => 1,
        'transaction_type' => 'in',
        'reference_type' => 'purchase_order',
        'reference_id' => 20,
        'transaction_date' => now()->subMinute(),
    ]);
    InventoryTransaction::factory()->create([
        'item_id' => $item->id,
        'branch_id' => 1,
        'transaction_type' => 'out',
        'reference_type' => 'recipe',
        'reference_id' => 30,
        'transaction_date' => now()->subMinutes(2),
    ]);
    InventoryTransaction::factory()->create([
        'item_id' => $item->id,
        'branch_id' => 1,
        'transaction_type' => 'adjustment',
        'reference_type' => 'transfer',
        'reference_id' => 40,
        'transaction_date' => now()->subMinutes(3),
    ]);

    $response = $this->actingAs($this->manager)->get(route('reports.inventory-transactions.print'));

    $response->assertOk();
    $response->assertSeeText('manual 10');
    $response->assertSeeText('purchase_order 20');
    $response->assertSeeText('recipe 30');
    $response->assertSeeText('transfer 40');
    $response->assertSeeText('Dry Goods > Spices');
});

it('purchase receiving and supplier purchases reports render received purchase order data', function () {
    $supplier = Supplier::factory()->create(['name' => 'Fresh Farm']);
    $item = InventoryItem::factory()->create([
        'item_code' => 'VEG-101',
        'name' => 'Parsley',
    ]);

    $approvedPo = PurchaseOrder::factory()->approved()->create([
        'po_number' => 'PO-RPT-1',
        'supplier_id' => $supplier->id,
        'order_date' => '2026-03-10',
    ]);
    $approvedLine = $approvedPo->items()->create([
        'item_id' => $item->id,
        'quantity' => 2,
        'unit_price' => 5,
        'total_price' => 10,
        'received_quantity' => 0,
    ]);

    $cancelledPo = PurchaseOrder::factory()->create([
        'po_number' => 'PO-RPT-2',
        'supplier_id' => $supplier->id,
        'order_date' => '2026-03-12',
        'status' => 'cancelled',
    ]);
    $cancelledPo->items()->create([
        'item_id' => $item->id,
        'quantity' => 100,
        'unit_price' => 50,
        'total_price' => 5000,
        'received_quantity' => 0,
    ]);

    app(PurchaseOrderReceivingService::class)->receive(
        $approvedPo->fresh(),
        [$approvedLine->id => 2],
        $this->manager->id,
        'Report receipt',
        [],
        '2026-03-11 09:00:00',
    );

    $receivingResponse = $this->actingAs($this->manager)->get(route('reports.purchase-order-receiving.print'));
    $receivingResponse->assertOk();
    $receivingResponse->assertSeeText('PO-RPT-1');
    $receivingResponse->assertSeeText('Fresh Farm');
    $receivingResponse->assertSeeText('Parsley');

    $supplierResponse = $this->actingAs($this->manager)->get(route('reports.supplier-purchases.print', [
        'supplier_id' => $supplier->id,
        'item_id' => $item->id,
    ]));
    $supplierResponse->assertOk();
    $supplierResponse->assertSeeText('Fresh Farm');
    $supplierResponse->assertSeeText('Parsley');
    $supplierResponse->assertSeeText('2.000');
    $supplierResponse->assertSeeText('10.00');
    $supplierResponse->assertDontSeeText('5000.00');
});
