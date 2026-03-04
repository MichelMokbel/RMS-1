<?php

use App\Models\InventoryItem;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Role::findOrCreate('admin');
    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
});

it('creates inventory item from purchase order create modal and selects it on line', function () {
    $supplier = Supplier::factory()->create();

    Volt::actingAs($this->admin);
    $component = Volt::test('purchase-orders.create')
        ->set('supplier_id', $supplier->id)
        ->call('openNewItemModal', 0)
        ->set('new_item_name', 'PO Modal Item')
        ->set('new_item_unit_of_measure', 'KG')
        ->set('new_item_units_per_package', 1)
        ->set('new_item_cost_per_unit', 12.5)
        ->call('createNewItem')
        ->assertHasNoErrors();

    $item = InventoryItem::query()->where('name', 'PO Modal Item')->first();
    expect($item)->not->toBeNull();
    expect($item?->supplier_id)->toBe($supplier->id);
    $component
        ->assertSet('lines.0.item_id', $item?->id)
        ->assertSet('lines.0.unit_price', 12.5);
});
