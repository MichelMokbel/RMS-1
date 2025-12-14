<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('meal_subscriptions', 'plan_meals_total')) {
                $table->integer('plan_meals_total')->nullable()->after('end_date'); // 20/26, etc.
            }
            if (! Schema::hasColumn('meal_subscriptions', 'meals_used')) {
                $table->integer('meals_used')->default(0)->after('plan_meals_total');
            }
            if (! Schema::hasColumn('meal_subscriptions', 'meal_plan_request_id')) {
                $table->unsignedBigInteger('meal_plan_request_id')->nullable()->after('meals_used');
                $table->index('meal_plan_request_id', 'meal_subscriptions_mpr_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meal_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('meal_subscriptions', 'meal_plan_request_id')) {
                $table->dropIndex('meal_subscriptions_mpr_id_idx');
                $table->dropColumn('meal_plan_request_id');
            }
            if (Schema::hasColumn('meal_subscriptions', 'meals_used')) {
                $table->dropColumn('meals_used');
            }
            if (Schema::hasColumn('meal_subscriptions', 'plan_meals_total')) {
                $table->dropColumn('plan_meals_total');
            }
        });
    }
};


