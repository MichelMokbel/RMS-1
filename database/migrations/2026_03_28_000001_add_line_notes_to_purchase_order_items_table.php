<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_order_items')) {
            return;
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'line_notes')) {
                $table->text('line_notes')->nullable()->after('total_price');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('purchase_order_items') || ! Schema::hasColumn('purchase_order_items', 'line_notes')) {
            return;
        }

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('line_notes');
        });
    }
};
