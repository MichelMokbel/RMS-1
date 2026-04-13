<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('source_pastry_order_id')->nullable()->after('source_order_id');
            $table->foreign('source_pastry_order_id')->references('id')->on('pastry_orders')->nullOnDelete();
            $table->index('source_pastry_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->dropForeign(['source_pastry_order_id']);
            $table->dropIndex(['source_pastry_order_id']);
            $table->dropColumn('source_pastry_order_id');
        });
    }
};
