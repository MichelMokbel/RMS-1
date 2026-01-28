<?php

use App\Models\InventoryItem;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Livewire\Volt\Volt;

function adminInventoryUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    return $user;
}

it('admin can create item with unique code', function () {
    $user = adminInventoryUser();
    $item = InventoryItem::factory()->make();

    Volt::actingAs($user);
    Volt::test('inventory.create')
        ->set('item_code', $item->item_code)
        ->set('name', $item->name)
        ->set('units_per_package', 1)
        ->set('minimum_stock', 0)
        ->set('current_stock', 0)
        ->set('status', 'active')
        ->call('save')
        ->assertHasNoErrors();

    expect(InventoryItem::where('item_code', $item->item_code)->exists())->toBeTrue();
});

it('initial stock creates adjustment and updates current stock', function () {
    $user = adminInventoryUser();
    $item = InventoryItem::factory()->make();

    Volt::actingAs($user);
    Volt::test('inventory.create')
        ->set('item_code', $item->item_code)
        ->set('name', $item->name)
        ->set('units_per_package', 1)
        ->set('minimum_stock', 0)
        ->set('current_stock', 5)
        ->call('save')
        ->assertHasNoErrors();

    $created = InventoryItem::where('item_code', $item->item_code)->first();
    expect((float) $created->current_stock)->toBe(5.0);
});
