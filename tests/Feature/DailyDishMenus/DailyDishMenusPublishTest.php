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

function dd_admin_user(): User
{
    $u = User::factory()->create(['status' => 'active']);
    $u->assignRole('admin');
    return $u;
}

it('clones menu into a draft target', function () {
    $user = dd_admin_user();
    $service = app(DailyDishMenuService::class);
    $mi = MenuItem::factory()->create(['status' => 'active']);

    $source = $service->upsertMenu(1, '2025-02-01', [
        'items' => [
            ['menu_item_id' => $mi->id, 'role' => 'diet', 'sort_order' => 2, 'is_required' => true],
        ],
        'notes' => 'source',
    ], $user->id);

    $cloned = $service->cloneMenu($source, '2025-02-02', 1, $user->id);

    expect($cloned->status)->toBe('draft');
    expect($cloned->items()->count())->toBe(1);
    expect($cloned->items()->first()->role)->toBe('diet');
    expect($cloned->notes)->toBe('source');
});

it('rejects publish if no items', function () {
    $user = dd_admin_user();
    $menu = DailyDishMenu::create([
        'branch_id' => 1,
        'service_date' => '2025-03-01',
        'status' => 'draft',
    ]);

    $service = app(DailyDishMenuService::class);
    expect(fn () => $service->publish($menu, $user->id))->toThrow(ValidationException::class);
});

it('unpublishes back to draft', function () {
    $user = dd_admin_user();
    $mi = MenuItem::factory()->create(['status' => 'active']);
    $service = app(DailyDishMenuService::class);

    $menu = $service->upsertMenu(1, '2025-03-02', [
        'items' => [
            ['menu_item_id' => $mi->id, 'role' => 'main', 'sort_order' => 0, 'is_required' => false],
        ],
    ], $user->id);

    $service->publish($menu, $user->id);
    $unpublished = $service->unpublish($menu, $user->id);

    expect($unpublished->status)->toBe('draft');
});

