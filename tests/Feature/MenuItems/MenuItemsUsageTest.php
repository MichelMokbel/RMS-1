<?php

use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Menu\MenuItemUsageService;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

function menuManager(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $role = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
    $user->assignRole($role);
    return $user;
}

it('blocks deactivation when order_items references the menu item', function () {
    $item = MenuItem::factory()->create(['is_active' => true]);
    $order = Order::factory()->create();
    OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $item->id,
    ]);

    $user = menuManager();
    Volt::actingAs($user);
    Volt::test('menu-items.index')
        ->call('toggleStatus', $item->id);

    expect(MenuItem::find($item->id)->is_active)->toBeTrue();
});
