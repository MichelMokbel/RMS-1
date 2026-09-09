<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->boolean('checkout_upsell_enabled')->default(false)->after('normal_menu_enabled');
            $table->unsignedBigInteger('upsell_category_id')->nullable()->after('checkout_upsell_enabled');
            $table->foreign('upsell_category_id', 'storefront_settings_upsell_category_fk')
                ->references('id')->on('storefront_categories')->nullOnDelete();
        });

        Schema::table('payment_checkout_targets', function (Blueprint $table): void {
            $table->unsignedBigInteger('membership_subscription_id')->nullable()->after('meal_plan_request_id');
            $table->unsignedInteger('membership_main_quantity')->nullable()->after('membership_subscription_id');
            $table->index(
                ['membership_subscription_id', 'hold_state'],
                'payment_targets_membership_hold_idx'
            );
            $table->foreign('membership_subscription_id', 'payment_targets_membership_subscription_fk')
                ->references('id')->on('meal_subscriptions')->restrictOnDelete();
        });

        Schema::table('payment_checkout_target_items', function (Blueprint $table): void {
            $table->string('line_role', 30)->default('menu_item')->after('sequence');
        });
    }

    public function down(): void
    {
        Schema::table('payment_checkout_target_items', function (Blueprint $table): void {
            $table->dropColumn('line_role');
        });

        Schema::table('payment_checkout_targets', function (Blueprint $table): void {
            $table->dropForeign('payment_targets_membership_subscription_fk');
            $table->dropIndex('payment_targets_membership_hold_idx');
            $table->dropColumn(['membership_subscription_id', 'membership_main_quantity']);
        });

        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropForeign('storefront_settings_upsell_category_fk');
            $table->dropColumn(['checkout_upsell_enabled', 'upsell_category_id']);
        });
    }
};
