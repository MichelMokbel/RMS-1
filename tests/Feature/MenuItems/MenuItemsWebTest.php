<?php

use App\Models\MenuItem;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

function menuAdmin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user->assignRole($role);
    return $user;
}

it('admin can create menu item', function () {
    $user = menuAdmin();
    $item = MenuItem::factory()->make();

    Volt::actingAs($user);
    Volt::test('menu-items.create')
        ->set('code', $item->code)
        ->set('name', $item->name)
        ->set('selling_price_per_unit', $item->selling_price_per_unit)
        ->set('tax_rate', $item->tax_rate)
        ->set('is_active', true)
        ->set('display_order', 1)
        ->call('save')
        ->assertHasNoErrors();

    expect(MenuItem::where('code', $item->code)->exists())->toBeTrue();
});

it('search works on code and name', function () {
    $user = menuAdmin();
    $target = MenuItem::factory()->create(['name' => 'Special Pizza', 'code' => 'PZ-001']);

    Volt::actingAs($user);
    Volt::test('menu-items.index')
        ->set('search', 'PZ-001')
        ->assertSee('Special Pizza');
});
