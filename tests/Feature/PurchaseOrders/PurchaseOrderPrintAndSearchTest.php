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

it('returns searchable purchase-order items', function () {
    $item = InventoryItem::factory()->create([
        'item_code' => '16003',
        'name' => 'Chicken Strips Regular 6x1KG',
        'status' => 'active',
    ]);

    $this->actingAs($this->admin)
        ->get(route('purchase-orders.items.search', ['q' => 'chicken']))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $item->id,
            'name' => 'Chicken Strips Regular 6x1KG',
            'code' => '16003',
        ]);
});

it('matches purchase-order items regardless of token order', function () {
    $item = InventoryItem::factory()->create([
        'item_code' => '16003',
        'name' => 'Chicken Strips Regular 6x1KG',
        'status' => 'active',
    ]);

    $this->actingAs($this->admin)
        ->get(route('purchase-orders.items.search', ['q' => 'regular chicken']))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $item->id,
            'name' => 'Chicken Strips Regular 6x1KG',
            'code' => '16003',
        ]);
});

it('returns searchable purchase-order suppliers', function () {
    $supplier = Supplier::factory()->create([
        'name' => 'TREDOS TRADING',
        'email' => 'ameen@tredostrading.com',
        'status' => 'active',
    ]);

    $this->actingAs($this->admin)
        ->get(route('purchase-orders.suppliers.search', ['q' => 'tredos']))
        ->assertOk()
        ->assertJsonFragment([
            'id' => $supplier->id,
            'name' => 'TREDOS TRADING',
            'email' => 'ameen@tredostrading.com',
        ]);
});

it('prints a single purchase order in purchase-order layout', function () {
    $supplier = Supplier::factory()->create(['name' => 'TREDOS TRADING']);
    $item = InventoryItem::factory()->create([
        'item_code' => '16003',
        'name' => 'Chicken Strips Regular 6x1KG',
        'unit_of_measure' => 'EA',
    ]);

    $po = PurchaseOrder::factory()->create([
        'po_number' => '800315',
        'supplier_id' => $supplier->id,
        'order_date' => '2026-01-19',
        'expected_delivery_date' => '2026-01-19',
        'total_amount' => 280.00,
        'created_by' => $this->admin->id,
    ]);

    $po->items()->create([
        'item_id' => $item->id,
        'quantity' => 2,
        'unit_price' => 140,
        'total_price' => 280,
        'received_quantity' => 0,
    ]);

    $this->actingAs($this->admin)
        ->get(route('purchase-orders.document-print', $po))
        ->assertOk()
        ->assertSee('Purchase Order')
        ->assertSee('800315')
        ->assertSee('TREDOS TRADING')
        ->assertSee('Chicken Strips Regular 6x1KG');
});
