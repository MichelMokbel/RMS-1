<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->string('daily_dish_plan_variant', 20)
                ->default('balanced')
                ->after('delivery_apps_enabled');
        });

        Schema::table('storefront_events', function (Blueprint $table): void {
            $table->string('experiment_variant', 20)->nullable()->after('lead_day_count');
            $table->string('plan_code', 20)->nullable()->after('experiment_variant');
            $table->index(
                ['company_id', 'event_name', 'experiment_variant', 'received_at'],
                'storefront_events_experiment_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('storefront_events', function (Blueprint $table): void {
            $table->dropIndex('storefront_events_experiment_idx');
            $table->dropColumn(['experiment_variant', 'plan_code']);
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn('daily_dish_plan_variant');
        });
    }
};
