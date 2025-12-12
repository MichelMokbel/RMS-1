<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_orders', 'payment_terms')) {
                    $table->string('payment_terms', 255)->nullable()->after('notes');
                }
                if (! Schema::hasColumn('purchase_orders', 'payment_type')) {
                    $table->string('payment_type', 100)->nullable()->after('payment_terms');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (Schema::hasColumn('purchase_orders', 'payment_type')) {
                    $table->dropColumn('payment_type');
                }
                if (Schema::hasColumn('purchase_orders', 'payment_terms')) {
                    $table->dropColumn('payment_terms');
                }
            });
        }
    }
};
