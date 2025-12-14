<?php

namespace App\Services\DailyDish;

use App\Models\DailyDishMenu;
use App\Models\DailyDishMenuItem;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyDishMenuService
{
    public function upsertMenu(int $branchId, string $serviceDate, array $payload, int $userId, bool $includeInactive = false): DailyDishMenu
    {
        return DB::transaction(function () use ($branchId, $serviceDate, $payload, $userId, $includeInactive) {
            $menu = DailyDishMenu::firstOrCreate(
                ['branch_id' => $branchId, 'service_date' => $serviceDate],
                ['created_by' => $userId, 'status' => 'draft']
            );

            if (! $menu->isDraft()) {
                throw ValidationException::withMessages(['status' => __('Only draft menus can be edited.')]);
            }

            $items = $payload['items'] ?? [];
            if (empty($items)) {
                throw ValidationException::withMessages(['items' => __('Menu must have at least one item.')]);
            }

            // Validate items and active menu items by default
            $menuItemIds = collect($items)->pluck('menu_item_id')->unique()->values();

            $menuItemsQuery = MenuItem::query()->whereIn('id', $menuItemIds);
            if (! $includeInactive) {
                $menuItemsQuery->where('is_active', 1);
            }

            $existing = $menuItemsQuery->pluck('id');
            $missing = $menuItemIds->diff($existing);
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['menu_item_id' => __('Some menu items are inactive or missing: :ids', ['ids' => $missing->implode(',')])]);
            }

            // Sync items
            $menu->items()->delete();
            foreach ($items as $row) {
                DailyDishMenuItem::create([
                    'daily_dish_menu_id' => $menu->id,
                    'menu_item_id' => $row['menu_item_id'],
                    'role' => $row['role'] ?? 'main',
                    'sort_order' => $row['sort_order'] ?? 0,
                    'is_required' => (bool) ($row['is_required'] ?? false),
                ]);
            }

            $menu->notes = $payload['notes'] ?? $menu->notes;
            $menu->save();

            return $menu->fresh(['items']);
        });
    }

    public function publish(DailyDishMenu $menu, int $userId): DailyDishMenu
    {
        if (! $menu->isDraft()) {
            throw ValidationException::withMessages(['status' => __('Only draft menus can be published.')]);
        }
        if ($menu->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => __('Menu must have at least one item before publishing.')]);
        }

        $menu->status = 'published';
        $menu->save();

        return $menu->fresh();
    }

    public function unpublish(DailyDishMenu $menu, int $userId): DailyDishMenu
    {
        if (! $menu->isPublished()) {
            throw ValidationException::withMessages(['status' => __('Only published menus can be reverted to draft.')]);
        }

        // If subscription orders mapping exists, check here (not implemented).
        $menu->status = 'draft';
        $menu->save();

        return $menu->fresh();
    }

    public function cloneMenu(DailyDishMenu $from, string $toDate, int $branchId, int $userId): DailyDishMenu
    {
        return DB::transaction(function () use ($from, $toDate, $branchId, $userId) {
            $target = DailyDishMenu::firstOrCreate(
                ['branch_id' => $branchId, 'service_date' => $toDate],
                ['status' => 'draft', 'created_by' => $userId]
            );

            if (! $target->isDraft()) {
                throw ValidationException::withMessages(['status' => __('Target menu must be draft to clone into.')]);
            }

            $target->items()->delete();
            foreach ($from->items as $item) {
                DailyDishMenuItem::create([
                    'daily_dish_menu_id' => $target->id,
                    'menu_item_id' => $item->menu_item_id,
                    'role' => $item->role,
                    'sort_order' => $item->sort_order,
                    'is_required' => $item->is_required,
                ]);
            }

            $target->notes = $from->notes;
            $target->save();

            return $target->fresh(['items']);
        });
    }
}

