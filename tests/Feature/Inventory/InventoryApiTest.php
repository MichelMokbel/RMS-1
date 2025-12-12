<?php

use App\Models\InventoryItem;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
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

it('adjust endpoint updates stock', function () {
    $user = inventoryApiAdmin();
    $item = InventoryItem::factory()->create(['current_stock' => 1]);

    $res = actingAs($user)->postJson("/api/inventory/{$item->id}/adjustments", [
        'direction' => 'increase',
        'quantity' => 2,
    ]);

    $res->assertOk();
    expect($item->fresh()->current_stock)->toBe(3);
});
