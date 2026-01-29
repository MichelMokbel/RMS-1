<?php

namespace App\Services\Menu;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MenuItemBranchAvailabilityService
{
    public function setAvailability(int $menuItemId, int $branchId, bool $enabled): void
    {
        if ($menuItemId <= 0 || $branchId <= 0) {
            return;
        }
        if (! Schema::hasTable('menu_item_branches')) {
            return;
        }

        if ($enabled) {
            DB::table('menu_item_branches')->updateOrInsert(
                ['menu_item_id' => $menuItemId, 'branch_id' => $branchId],
                ['created_at' => now(), 'updated_at' => now()]
            );
            return;
        }

        DB::table('menu_item_branches')
            ->where('menu_item_id', $menuItemId)
            ->where('branch_id', $branchId)
            ->delete();
    }
}

