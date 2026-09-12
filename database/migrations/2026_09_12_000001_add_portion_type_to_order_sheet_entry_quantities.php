<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_sheet_entry_quantities', function (Blueprint $table) {
            $table->string('portion_type', 20)->default('plate')->after('daily_dish_menu_item_id');
            $table->index(
                ['order_sheet_entry_id', 'daily_dish_menu_item_id', 'portion_type'],
                'order_sheet_entry_quantity_portion_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_sheet_entry_quantities', function (Blueprint $table) {
            $table->dropIndex('order_sheet_entry_quantity_portion_index');
            $table->dropColumn('portion_type');
        });
    }
};
