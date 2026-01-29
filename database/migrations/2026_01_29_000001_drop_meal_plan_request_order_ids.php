<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meal_plan_requests') || ! Schema::hasColumn('meal_plan_requests', 'order_ids')) {
            return;
        }

        Schema::table('meal_plan_requests', function (Blueprint $table) {
            $table->dropColumn('order_ids');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('meal_plan_requests') || Schema::hasColumn('meal_plan_requests', 'order_ids')) {
            return;
        }

        Schema::table('meal_plan_requests', function (Blueprint $table) {
            $table->json('order_ids')->nullable()->after('status');
        });
    }
};
