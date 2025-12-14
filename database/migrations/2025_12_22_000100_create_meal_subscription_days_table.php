<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meal_subscription_days')) {
            Schema::create('meal_subscription_days', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('subscription_id');
                $table->tinyInteger('weekday');
                $table->timestamp('created_at')->nullable()->useCurrent();

                $table->unique(['subscription_id', 'weekday'], 'meal_subscription_days_unique');
                $table->index('subscription_id', 'meal_subscription_days_sub_id_idx');
            });
        }

        if (Schema::hasTable('meal_subscription_days') && Schema::hasTable('meal_subscriptions')) {
            Schema::table('meal_subscription_days', function (Blueprint $table) {
                $table->foreign('subscription_id', 'meal_subscription_days_sub_fk')
                    ->references('id')->on('meal_subscriptions')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive
    }
};

