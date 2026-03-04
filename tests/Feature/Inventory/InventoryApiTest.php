<?php

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\User;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

function inventoryApiAdmin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);
    return $user;
}

it('lists inventory items via api', function () {
    InventoryItem::factory()->count(2)->create();
    $user = inventoryApiAdmin();

    $res = actingAs($user)->getJson('/api/inventory');
    $res->assertOk()->assertJsonStructure(['data']);
});

it('lists inventory items sorted by item code', function () {
    InventoryItem::factory()->create(['item_code' => 'ITEM-200', 'name' => 'Zeta']);
    InventoryItem::factory()->create(['item_code' => 'ITEM-010', 'name' => 'Alpha']);
    InventoryItem::factory()->create(['item_code' => 'ITEM-050', 'name' => 'Beta']);
    $user = inventoryApiAdmin();

    $res = actingAs($user)->getJson('/api/inventory?per_page=10');
    $res->assertOk();

    $codes = collect($res->json('data'))->pluck('item_code')->take(3)->values()->all();
    expect($codes)->toBe(['ITEM-010', 'ITEM-050', 'ITEM-200']);
});

it('adjust endpoint updates stock', function () {
    $user = inventoryApiAdmin();
    $item = InventoryItem::factory()->create();
    InventoryStock::where('inventory_item_id', $item->id)
        ->where('branch_id', (int) config('inventory.default_branch_id', 1))
        ->update(['current_stock' => 1]);

    $res = actingAs($user)->postJson("/api/inventory/{$item->id}/adjustments", [
        'direction' => 'increase',
        'quantity' => 2,
    ]);

    $res->assertOk();
    expect((float) $item->fresh()->current_stock)->toBe(3.0);
});
