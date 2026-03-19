<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipe_items')) {
            return;
        }

        Schema::table('recipe_items', function (Blueprint $table) {
            if (! Schema::hasColumn('recipe_items', 'sub_recipe_id')) {
                $table->integer('sub_recipe_id')->nullable()->after('inventory_item_id');
                $table->index('sub_recipe_id', 'recipe_items_sub_recipe_id_index');
                $table->foreign('sub_recipe_id', 'recipe_items_sub_recipe_id_foreign')
                    ->references('id')
                    ->on('recipes')
                    ->nullOnDelete();
            }
        });

        if (Schema::hasColumn('recipe_items', 'inventory_item_id')) {
            Schema::table('recipe_items', function (Blueprint $table) {
                $table->integer('inventory_item_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('recipe_items')) {
            return;
        }

        if (Schema::hasColumn('recipe_items', 'sub_recipe_id')) {
            Schema::table('recipe_items', function (Blueprint $table) {
                $table->dropForeign('recipe_items_sub_recipe_id_foreign');
                $table->dropIndex('recipe_items_sub_recipe_id_index');
                $table->dropColumn('sub_recipe_id');
            });
        }

        if (Schema::hasColumn('recipe_items', 'inventory_item_id')) {
            Schema::table('recipe_items', function (Blueprint $table) {
                $table->integer('inventory_item_id')->nullable(false)->change();
            });
        }
    }
};
