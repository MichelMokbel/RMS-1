<?php

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('shows aggregated purchase-order item quantities without purchase-order columns', function () {
    $supplier = Supplier::factory()->create(['name' => 'Fresh Source']);
    $item = InventoryItem::factory()->create([
        'item_code' => 'ITEM-100',
        'name' => 'Frozen Mixed Vegetables',
        'unit_of_measure' => 'kg',
    ]);

    $firstPo = PurchaseOrder::factory()->approved()->create([
        'po_number' => 'PO-000001',
        'supplier_id' => $supplier->id,
        'order_date' => '2026-04-01',
        'created_by' => $this->admin->id,
    ]);
    $firstPo->items()->create([
        'item_id' => $item->id,
        'quantity' => 5,
        'unit_price' => 10,
        'total_price' => 50,
        'received_quantity' => 0,
    ]);

    $secondPo = PurchaseOrder::factory()->approved()->create([
        'po_number' => 'PO-000002',
        'supplier_id' => $supplier->id,
        'order_date' => '2026-04-02',
        'created_by' => $this->admin->id,
    ]);
    $secondPo->items()->create([
        'item_id' => $item->id,
        'quantity' => 3,
        'unit_price' => 11,
        'total_price' => 33,
        'received_quantity' => 0,
    ]);

    $response = $this->actingAs($this->admin)
        ->get(route('reports.purchase-order-inventory-list', [
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
        ]));

    $response->assertOk()
        ->assertSee('PO Inventory List')
        ->assertSee('Frozen Mixed Vegetables')
        ->assertSee('ITEM-100')
        ->assertSee('8.000')
        ->assertDontSee('PO-000001')
        ->assertDontSee('PO-000002');
});
