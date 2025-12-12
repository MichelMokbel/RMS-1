<?php

use App\Models\MenuItem;
use App\Models\User;
use App\Services\Menu\MenuItemUsageService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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
    // create temp order_items table for the test if missing
    if (! Schema::hasTable('order_items')) {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('menu_item_id')->nullable();
        });
    }

    $item = MenuItem::factory()->create(['is_active' => true]);
    \DB::table('order_items')->insert(['menu_item_id' => $item->id]);

    $user = menuManager();
    Volt::test('menu-items.index')
        ->actingAs($user)
        ->call('toggleStatus', $item->id);

    expect(MenuItem::find($item->id)->is_active)->toBeTrue();
});
