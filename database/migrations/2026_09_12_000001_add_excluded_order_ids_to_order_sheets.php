<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_sheets', function (Blueprint $table) {
            $table->json('excluded_order_ids')->nullable()->after('sheet_date');
        });
    }

    public function down(): void
    {
        Schema::table('order_sheets', function (Blueprint $table) {
            $table->dropColumn('excluded_order_ids');
        });
    }
};
