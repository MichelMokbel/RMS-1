<?php

use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
});

function daily_dish_admin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');

    return $user;
}

it('seeds fixed slots in the planner drawer for a new menu', function () {
    $user = daily_dish_admin();

    Volt::actingAs($user);

    $component = Volt::test('daily-dish.menus.index')
        ->call('openMenuDrawer', '2026-04-01')
        ->assertSet('showMenuDrawer', true);

    $rows = $component->get('drawer_items');

    expect($rows)->toHaveCount(5);
    expect(array_column($rows, 'slot_label'))->toBe(['Main 1', 'Main 2', 'Main 3', 'Salad', 'Dessert']);
    expect(array_column($rows, 'role'))->toBe(['main', 'main', 'main', 'salad', 'dessert']);
});

it('creates a simple menu item from the planner drawer and assigns it to the target slot', function () {
    $user = daily_dish_admin();

    Volt::actingAs($user);

    $component = Volt::test('daily-dish.menus.index')
        ->call('openMenuDrawer', '2026-04-02')
        ->call('openDrawerMenuItemForm', 1)
        ->set('new_menu_item_name', 'Drawer Created Main')
        ->set('new_menu_item_price', '12.500')
        ->call('createDrawerMenuItem')
        ->assertHasNoErrors();

    $item = MenuItem::query()->where('name', 'Drawer Created Main')->firstOrFail();

    expect($item->selling_price_per_unit)->toBe('12.500');
    expect($component->get('drawer_items.1.menu_item_id'))->toBe($item->id);
});

it('publishes and advances the planner drawer to the next date', function () {
    $user = daily_dish_admin();
    $items = MenuItem::factory()->count(5)->create();

    Volt::actingAs($user);

    Volt::test('daily-dish.menus.index')
        ->call('openMenuDrawer', '2026-01-31')
        ->set('drawer_items.0.menu_item_id', $items[0]->id)
        ->set('drawer_items.1.menu_item_id', $items[1]->id)
        ->set('drawer_items.2.menu_item_id', $items[2]->id)
        ->set('drawer_items.3.menu_item_id', $items[3]->id)
        ->set('drawer_items.4.menu_item_id', $items[4]->id)
        ->call('publishDrawerMenuAndNextDate')
        ->assertHasNoErrors()
        ->assertSet('drawer_service_date', '2026-02-01')
        ->assertSet('month', '02')
        ->assertSet('year', '2026');

    $menu = DailyDishMenu::query()
        ->where('branch_id', 1)
        ->whereDate('service_date', '2026-01-31')
        ->firstOrFail();

    expect($menu->status)->toBe('published');
    expect($menu->items()->count())->toBe(5);
});

it('renders the simplified editor without notes and with visible action buttons', function () {
    $user = daily_dish_admin();

    $this->actingAs($user)
        ->get(route('daily-dish.menus.edit', [1, '2026-04-03']))
        ->assertOk()
        ->assertDontSee('Notes')
        ->assertSee('Create Item')
        ->assertSee('Clear');
});
