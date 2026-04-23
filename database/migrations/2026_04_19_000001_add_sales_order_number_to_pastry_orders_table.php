<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pastry_orders', function (Blueprint $table) {
            $table->string('sales_order_number')->nullable()->after('order_number');
        });
    }

    public function down(): void
    {
        Schema::table('pastry_orders', function (Blueprint $table) {
            $table->dropColumn('sales_order_number');
        });
    }
};
