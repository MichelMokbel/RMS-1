<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_items') || ! Schema::hasColumn('inventory_items', 'current_stock')) {
            return;
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('current_stock');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_items') || Schema::hasColumn('inventory_items', 'current_stock')) {
            return;
        }

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('current_stock', 12, 3)->default(0)->after('minimum_stock');
        });
    }
};
