<?php

use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\DailyDish\DailyDishMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::findOrCreate('admin', 'web');
});

function dd_admin(): User
{
    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('admin');
    return $user;
}

it('enforces unique branch-date', function () {
    $user = dd_admin();
    $service = app(DailyDishMenuService::class);
    $mi = MenuItem::factory()->create(['status' => 'active']);

    $service->upsertMenu(1, '2025-01-01', [
        'items' => [
            ['menu_item_id' => $mi->id, 'role' => 'main', 'sort_order' => 0, 'is_required' => false],
        ],
    ], $user->id);

    // Second upsert should update same record, not create new
    $service->upsertMenu(1, '2025-01-01', [
        'items' => [
            ['menu_item_id' => $mi->id, 'role' => 'main', 'sort_order' => 1, 'is_required' => true],
        ],
    ], $user->id);

    expect(DailyDishMenu::where('branch_id',1)->whereDate('service_date','2025-01-01')->count())->toBe(1);
    $menu = DailyDishMenu::where('branch_id',1)->whereDate('service_date','2025-01-01')->first();
    expect($menu->items()->count())->toBe(1);
});

it('blocks editing when published', function () {
    $user = dd_admin();
    $service = app(DailyDishMenuService::class);
    $mi = MenuItem::factory()->create(['status' => 'active']);

    $menu = $service->upsertMenu(1, '2025-01-02', [
        'items' => [
            ['menu_item_id' => $mi->id, 'role' => 'main', 'sort_order' => 0, 'is_required' => false],
        ],
    ], $user->id);

    $service->publish($menu, $user->id);

    expect(fn () => $service->upsertMenu(1, '2025-01-02', [
        'items' => [
            ['menu_item_id' => $mi->id, 'role' => 'main', 'sort_order' => 1, 'is_required' => false],
        ],
    ], $user->id))->toThrow(ValidationException::class);
});

it('publish requires at least one item', function () {
    $user = dd_admin();
    $menu = DailyDishMenu::create([
        'branch_id' => 1,
        'service_date' => '2025-01-03',
        'status' => 'draft',
    ]);

    $service = app(DailyDishMenuService::class);

    expect(fn () => $service->publish($menu, $user->id))->toThrow(ValidationException::class);
});

