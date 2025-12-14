<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meal_subscription_orders')) {
            Schema::create('meal_subscription_orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('subscription_id');
                $table->integer('order_id');
                $table->date('service_date');
                $table->integer('branch_id');
                $table->timestamp('created_at')->nullable()->useCurrent();

                $table->unique(['subscription_id', 'service_date', 'branch_id'], 'meal_sub_orders_unique');
                $table->index('order_id', 'meal_sub_orders_order_id_idx');
                $table->index('service_date', 'meal_sub_orders_service_date_idx');
            });
        }

        if (Schema::hasTable('meal_subscription_orders')) {
            Schema::table('meal_subscription_orders', function (Blueprint $table) {
                if (Schema::hasTable('meal_subscriptions')) {
                    $table->foreign('subscription_id', 'meal_sub_orders_sub_fk')
                        ->references('id')->on('meal_subscriptions')
                        ->onDelete('cascade');
                }

                if (Schema::hasTable('orders')) {
                    $table->foreign('order_id', 'meal_sub_orders_order_fk')
                        ->references('id')->on('orders')
                        ->onDelete('cascade');
                }
            });
        }
    }

    public function down(): void
    {
        // Non-destructive
    }
};

