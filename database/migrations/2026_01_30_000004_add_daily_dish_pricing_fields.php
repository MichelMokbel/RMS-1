<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'role')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->string('role', 50)->nullable()->after('sort_order');
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'daily_dish_portion_type')) {
                    $table->string('daily_dish_portion_type', 20)->nullable()->after('is_daily_dish');
                }
                if (! Schema::hasColumn('orders', 'daily_dish_portion_quantity')) {
                    $table->unsignedInteger('daily_dish_portion_quantity')->nullable()->after('daily_dish_portion_type');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('role');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['daily_dish_portion_type', 'daily_dish_portion_quantity']);
        });
    }
};
