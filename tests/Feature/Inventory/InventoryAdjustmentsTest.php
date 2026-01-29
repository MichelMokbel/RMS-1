<?php

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

function inventoryAdmin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);
    return $user;
}

it('increase adjustment updates stock and creates transaction', function () {
    $user = inventoryAdmin();
    $item = InventoryItem::factory()->create();

    $service = app(InventoryStockService::class);
    $service->adjustStock($item, 3, 'test', $user->id);

    $item->refresh();
    expect((float) $item->current_stock)->toBe(3.0);
    expect($item->transactions()->count())->toBe(1);
});

it('prevent negative stock when disallowed', function () {
    config()->set('inventory.allow_negative_stock', false);

    $user = inventoryAdmin();
    $item = InventoryItem::factory()->create();
    InventoryStock::where('inventory_item_id', $item->id)
        ->where('branch_id', (int) config('inventory.default_branch_id', 1))
        ->update(['current_stock' => 1]);
    $service = app(InventoryStockService::class);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    $service->adjustStock($item, -5, 'test', $user->id);
});

it('adjust via show component decreases stock', function () {
    $user = inventoryAdmin();
    $item = InventoryItem::factory()->create();
    InventoryStock::where('inventory_item_id', $item->id)
        ->where('branch_id', (int) config('inventory.default_branch_id', 1))
        ->update(['current_stock' => 5]);

    Volt::actingAs($user);
    Volt::test('inventory.show', ['item' => $item])
        ->set('direction', 'decrease')
        ->set('quantity', 2)
        ->call('adjust')
        ->assertHasNoErrors();

    expect((float) $item->fresh()->current_stock)->toBe(3.0);
});
