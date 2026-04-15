<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('source_payment_id')->nullable()->after('meal_plan_request_id');
            $table->boolean('uses_invoice_tracking')->default(false)->after('source_payment_id');

            $table->index('source_payment_id', 'meal_subscriptions_source_payment_idx');

            if (Schema::hasTable('payments')) {
                $table->foreign('source_payment_id', 'meal_subscriptions_source_payment_fk')
                    ->references('id')->on('payments')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('meal_subscriptions', function (Blueprint $table) {
            if (Schema::hasTable('payments')) {
                $table->dropForeign('meal_subscriptions_source_payment_fk');
            }
            $table->dropIndex('meal_subscriptions_source_payment_idx');
            $table->dropColumn(['source_payment_id', 'uses_invoice_tracking']);
        });
    }
};
