<?php

use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MenuItem;
use App\Services\Orders\OrderNumberService;
use App\Services\Orders\OrderTotalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('requires published menu when creating daily dish order', function () {
    // simulate component validation logic by ensuring no published menu -> error
    Livewire::test('orders.create')
        ->set('is_daily_dish', true)
        ->set('branch_id', 1)
        ->set('menu_id', null)
        ->call('save')
        ->assertHasErrors('menu_id');
});

it('allows create when published menu exists and items selected', function () {
    $menu = DailyDishMenu::create([
        'branch_id' => 1,
        'service_date' => '2025-01-10',
        'status' => 'published',
    ]);
    $mi = MenuItem::factory()->create(['selling_price_per_unit' => 10]);
    DailyDishMenuItem::create([
        'daily_dish_menu_id' => $menu->id,
        'menu_item_id' => $mi->id,
        'role' => 'main',
    ]);

    Livewire::test('orders.create')
        ->set('branch_id', 1)
        ->set('is_daily_dish', true)
        ->set('menu_id', $menu->id)
        ->set('selected_items', [
            ['menu_item_id' => $mi->id, 'quantity' => 1, 'unit_price' => 10],
        ])
        ->set('scheduled_date', '2025-01-10')
        ->call('save')
        ->assertHasNoErrors();
});

