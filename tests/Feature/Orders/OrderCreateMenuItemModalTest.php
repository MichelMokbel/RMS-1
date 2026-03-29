<?php

use App\Models\MenuItem;
use App\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

it('auto-generates menu item code from order create modal', function () {
    $user = User::factory()->create(['status' => 'active']);
    Role::findOrCreate('admin', 'web');
    $user->assignRole('admin');

    Volt::actingAs($user);

    Volt::test('orders.create')
        ->call('prepareMenuItemModal')
        ->set('menu_item_name', 'Order Modal Menu Item')
        ->set('menu_item_price', 12)
        ->set('menu_item_is_active', true)
        ->call('createMenuItem')
        ->assertHasNoErrors();

    $item = MenuItem::query()->where('name', 'Order Modal Menu Item')->firstOrFail();
    expect($item->code)->toBe('MI-000001');
});
