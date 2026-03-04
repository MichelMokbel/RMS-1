<?php

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Livewire\Volt\Volt;

function adminInventoryUser(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    return $user;
}

it('admin can create item with sequential auto-generated code', function () {
    $user = adminInventoryUser();
    InventoryItem::factory()->create(['item_code' => 'ITEM-009']);
    $name = 'Auto Item '.Str::uuid();

    Volt::actingAs($user);
    Volt::test('inventory.create')
        ->set('name', $name)
        ->set('units_per_package', 1)
        ->set('minimum_stock', 0)
        ->set('current_stock', 0)
        ->set('status', 'active')
        ->call('save')
        ->assertHasNoErrors();

    $created = InventoryItem::query()->where('name', $name)->first();
    expect($created)->not->toBeNull();
    expect($created?->item_code)->toBe('ITEM-010');
});

it('initial stock creates adjustment and updates current stock', function () {
    $user = adminInventoryUser();
    $item = InventoryItem::factory()->make();

    Volt::actingAs($user);
    Volt::test('inventory.create')
        ->set('name', $item->name)
        ->set('units_per_package', 1)
        ->set('minimum_stock', 0)
        ->set('current_stock', 5)
        ->call('save')
        ->assertHasNoErrors();

    $created = InventoryItem::where('name', $item->name)->latest('id')->first();
    expect((float) $created->current_stock)->toBe(5.0);
});
