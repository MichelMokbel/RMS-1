<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_items')) {
            return;
        }

        if (Schema::hasColumn('menu_items', 'unit')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->string('unit', 20)->default('each')->after('selling_price_per_unit');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('menu_items') || ! Schema::hasColumn('menu_items', 'unit')) {
            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
