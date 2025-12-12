<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            if (! $this->hasIndex('menu_items', 'menu_items_display_order_index')) {
                $table->index('display_order', 'menu_items_display_order_index');
            }
            if (! $this->hasIndex('menu_items', 'menu_items_active_order_index')) {
                $table->index(['is_active', 'display_order'], 'menu_items_active_order_index');
            }
        });

        // Foreign keys guarded
        try {
            if (Schema::hasTable('categories') && ! $this->hasForeign('menu_items', 'menu_items_category_id_foreign')) {
                Schema::table('menu_items', function (Blueprint $table) {
                    $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
                });
            }
        } catch (\Throwable $e) {
            // ignore
        }

        try {
            if (Schema::hasTable('recipes') && ! $this->hasForeign('menu_items', 'menu_items_recipe_id_foreign')) {
                Schema::table('menu_items', function (Blueprint $table) {
                    $table->foreign('recipe_id')->references('id')->on('recipes')->onDelete('set null');
                });
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            if ($this->hasForeign('menu_items', 'menu_items_category_id_foreign')) {
                $table->dropForeign('menu_items_category_id_foreign');
            }
            if ($this->hasForeign('menu_items', 'menu_items_recipe_id_foreign')) {
                $table->dropForeign('menu_items_recipe_id_foreign');
            }
            if ($this->hasIndex('menu_items', 'menu_items_display_order_index')) {
                $table->dropIndex('menu_items_display_order_index');
            }
            if ($this->hasIndex('menu_items', 'menu_items_active_order_index')) {
                $table->dropIndex('menu_items_active_order_index');
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        $rows = DB::select('SHOW INDEX FROM '.$table);
        foreach ($rows as $row) {
            if (isset($row->Key_name) && $row->Key_name === $index) {
                return true;
            }
        }
        return false;
    }

    private function hasForeign(string $table, string $foreign): bool
    {
        $rows = DB::select('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?', [$table, 'FOREIGN KEY']);
        foreach ($rows as $row) {
            if (isset($row->CONSTRAINT_NAME) && $row->CONSTRAINT_NAME === $foreign) {
                return true;
            }
        }
        return false;
    }
};
