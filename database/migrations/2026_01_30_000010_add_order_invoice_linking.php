<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add source_order_id to ar_invoices to link invoice to order
        if (Schema::hasTable('ar_invoices') && ! Schema::hasColumn('ar_invoices', 'source_order_id')) {
            Schema::table('ar_invoices', function (Blueprint $table) {
                $table->unsignedBigInteger('source_order_id')->nullable()->after('source_sale_id');
                $table->index('source_order_id', 'ar_invoices_source_order_id_index');
            });
        }

        // Add invoiced_at to orders to track when an order was invoiced
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'invoiced_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('invoiced_at')->nullable()->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ar_invoices') && Schema::hasColumn('ar_invoices', 'source_order_id')) {
            Schema::table('ar_invoices', function (Blueprint $table) {
                $table->dropIndex('ar_invoices_source_order_id_index');
                $table->dropColumn('source_order_id');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'invoiced_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('invoiced_at');
            });
        }
    }
};
