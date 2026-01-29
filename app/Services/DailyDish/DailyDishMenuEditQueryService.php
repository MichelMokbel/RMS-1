<?php

namespace App\Services\DailyDish;

use App\Models\DailyDishMenu;
use App\Models\MenuItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DailyDishMenuEditQueryService
{
    public function loadMenu(int $branchId, string $serviceDate): ?DailyDishMenu
    {
        return DailyDishMenu::where('branch_id', $branchId)
            ->whereDate('service_date', $serviceDate)
            ->with('items')
            ->first();
    }

    public function menuItemsForBranch(int $branchId): Collection
    {
        if (! Schema::hasTable('menu_items')) {
            return collect();
        }

        return MenuItem::where('is_active', 1)
            ->availableInBranch($branchId)
            ->orderBy('name')
            ->get();
    }
}

