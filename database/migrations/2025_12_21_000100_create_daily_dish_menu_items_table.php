<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_dish_menu_items')) {
            Schema::create('daily_dish_menu_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('daily_dish_menu_id');
                $table->integer('menu_item_id');
                $table->enum('role', ['main','diet','vegetarian','salad','dessert','addon'])->default('main');
                $table->integer('sort_order')->default(0);
                $table->boolean('is_required')->default(false);
                $table->timestamp('created_at')->nullable()->useCurrent();

                $table->index('daily_dish_menu_id', 'ddmi_menu_id_index');
                $table->index('menu_item_id', 'ddmi_menu_item_id_index');
                $table->index('role', 'ddmi_role_index');
                $table->unique(['daily_dish_menu_id', 'menu_item_id', 'role'], 'ddmi_unique_menu_item_role');
            });
        }

        if (Schema::hasTable('daily_dish_menu_items')) {
            Schema::table('daily_dish_menu_items', function (Blueprint $table) {
                if (Schema::hasTable('daily_dish_menus')) {
                    $table->foreign('daily_dish_menu_id', 'ddmi_daily_dish_menu_fk')
                        ->references('id')->on('daily_dish_menus')
                        ->onDelete('cascade');
                }

                if (Schema::hasTable('menu_items')) {
                    $table->foreign('menu_item_id', 'ddmi_menu_item_fk')
                        ->references('id')->on('menu_items')
                        ->onDelete('restrict');
                }
            });
        }
    }

    public function down(): void
    {
        // Non-destructive: do not drop legacy tables.
    }
};

