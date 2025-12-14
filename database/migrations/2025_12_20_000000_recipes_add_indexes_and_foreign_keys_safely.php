<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // recipes table
        if (Schema::hasTable('recipes')) {
            Schema::table('recipes', function (Blueprint $table) {
                if (! $this->hasIndex('recipes', 'recipes_category_id_index')) {
                    $table->index('category_id', 'recipes_category_id_index');
                }
                if (! $this->hasIndex('recipes', 'recipes_name_index')) {
                    $table->index('name', 'recipes_name_index');
                }

                if (Schema::hasTable('categories') && ! $this->hasForeign('recipes', 'recipes_category_id_foreign')) {
                    $table->foreign('category_id', 'recipes_category_id_foreign')
                        ->references('id')->on('categories')
                        ->onDelete('set null');
                }
            });
        }

        // recipe_items table
        if (Schema::hasTable('recipe_items')) {
            Schema::table('recipe_items', function (Blueprint $table) {
                if (! $this->hasIndex('recipe_items', 'recipe_items_recipe_id_index')) {
                    $table->index('recipe_id', 'recipe_items_recipe_id_index');
                }
                if (! $this->hasIndex('recipe_items', 'recipe_items_inventory_item_id_index')) {
                    $table->index('inventory_item_id', 'recipe_items_inventory_item_id_index');
                }
                if (! $this->hasIndex('recipe_items', 'recipe_items_cost_type_index')) {
                    $table->index('cost_type', 'recipe_items_cost_type_index');
                }

                if (Schema::hasTable('recipes') && ! $this->hasForeign('recipe_items', 'recipe_items_recipe_id_foreign')) {
                    $table->foreign('recipe_id', 'recipe_items_recipe_id_foreign')
                        ->references('id')->on('recipes')
                        ->onDelete('cascade');
                }

                if (Schema::hasTable('inventory_items') && ! $this->hasForeign('recipe_items', 'recipe_items_inventory_item_id_foreign')) {
                    $table->foreign('inventory_item_id', 'recipe_items_inventory_item_id_foreign')
                        ->references('id')->on('inventory_items')
                        ->onDelete('restrict');
                }
            });
        }

        // recipe_productions table
        if (Schema::hasTable('recipe_productions')) {
            Schema::table('recipe_productions', function (Blueprint $table) {
                if (! $this->hasIndex('recipe_productions', 'recipe_productions_recipe_id_index')) {
                    $table->index('recipe_id', 'recipe_productions_recipe_id_index');
                }
                if (! $this->hasIndex('recipe_productions', 'recipe_productions_production_date_index')) {
                    $table->index('production_date', 'recipe_productions_production_date_index');
                }
                if (! $this->hasIndex('recipe_productions', 'recipe_productions_created_by_index')) {
                    $table->index('created_by', 'recipe_productions_created_by_index');
                }

                if (Schema::hasTable('recipes') && ! $this->hasForeign('recipe_productions', 'recipe_productions_recipe_id_foreign')) {
                    $table->foreign('recipe_id', 'recipe_productions_recipe_id_foreign')
                        ->references('id')->on('recipes')
                        ->onDelete('cascade');
                }

                if (Schema::hasTable('users') && ! $this->hasForeign('recipe_productions', 'recipe_productions_created_by_foreign')) {
                    $table->foreign('created_by', 'recipe_productions_created_by_foreign')
                        ->references('id')->on('users')
                        ->onDelete('set null');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No destructive changes; indexes/fks remain.
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $db = config('database.connections.' . config('database.default') . '.database');
        $result = DB::selectOne(
            'SELECT COUNT(*) as cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$db, $table, $indexName]
        );

        return ($result->cnt ?? 0) > 0;
    }

    private function hasForeign(string $table, string $foreignName): bool
    {
        $db = config('database.connections.' . config('database.default') . '.database');
        $result = DB::selectOne(
            'SELECT COUNT(*) as cnt FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$db, $table, $foreignName]
        );

        return ($result->cnt ?? 0) > 0;
    }
};

