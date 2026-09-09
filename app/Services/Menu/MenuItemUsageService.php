<?php

namespace App\Services\Menu;

use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MenuItemUsageService
{
    /** @var array<string, array{table:string,column:string,type_column?:string,type?:string}> */
    private const REFERENCES = [
        'orders' => ['table' => 'order_items', 'column' => 'menu_item_id'],
        'daily_dish_menus' => ['table' => 'daily_dish_menu_items', 'column' => 'menu_item_id'],
        'pastry_orders' => ['table' => 'pastry_order_items', 'column' => 'menu_item_id'],
        'quotations' => ['table' => 'quotation_items', 'column' => 'menu_item_id'],
        'order_sheets' => ['table' => 'order_sheet_entry_extras', 'column' => 'menu_item_id'],
        'sales' => ['table' => 'sale_items', 'column' => 'sellable_id', 'type_column' => 'sellable_type', 'type' => MenuItem::class],
        'invoices' => ['table' => 'ar_invoice_items', 'column' => 'sellable_id', 'type_column' => 'sellable_type', 'type' => MenuItem::class],
        'storefront_profiles' => ['table' => 'storefront_item_profiles', 'column' => 'menu_item_id'],
        'payment_target_items' => ['table' => 'payment_checkout_target_items', 'column' => 'menu_item_id'],
    ];

    public function isMenuItemUsed(int $menuItemId): bool
    {
        return (int) $this->usageReport($menuItemId)['total_references'] > 0;
    }

    /** @return array{references:array<string,int>,recipe_link:int,total_references:int} */
    public function usageReport(int $menuItemId): array
    {
        $references = [];
        foreach (self::REFERENCES as $key => $definition) {
            if (! Schema::hasTable($definition['table']) || ! Schema::hasColumn($definition['table'], $definition['column'])) {
                $references[$key] = 0;

                continue;
            }
            $query = DB::table($definition['table'])->where($definition['column'], $menuItemId);
            if (isset($definition['type_column'], $definition['type'])
                && Schema::hasColumn($definition['table'], $definition['type_column'])) {
                $query->where($definition['type_column'], $definition['type']);
            }
            $references[$key] = $query->count();
        }
        $recipeLink = Schema::hasColumn('menu_items', 'recipe_id')
            ? (int) DB::table('menu_items')->where('id', $menuItemId)->whereNotNull('recipe_id')->exists()
            : 0;

        return [
            'references' => $references,
            'recipe_link' => $recipeLink,
            'total_references' => array_sum($references) + $recipeLink,
        ];
    }

    public function lockReferencesForUpdate(int $menuItemId): void
    {
        foreach (self::REFERENCES as $definition) {
            if (! Schema::hasTable($definition['table']) || ! Schema::hasColumn($definition['table'], $definition['column'])) {
                continue;
            }
            $query = DB::table($definition['table'])->where($definition['column'], $menuItemId);
            if (isset($definition['type_column'], $definition['type'])
                && Schema::hasColumn($definition['table'], $definition['type_column'])) {
                $query->where($definition['type_column'], $definition['type']);
            }
            $query->lockForUpdate()->get();
        }
    }
}
