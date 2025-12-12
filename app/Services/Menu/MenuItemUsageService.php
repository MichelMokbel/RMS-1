<?php

namespace App\Services\Menu;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MenuItemUsageService
{
    public function isMenuItemUsed(int $menuItemId): bool
    {
        if (! Schema::hasTable('order_items')) {
            return false;
        }

        return DB::table('order_items')->where('menu_item_id', $menuItemId)->exists();
    }
}
