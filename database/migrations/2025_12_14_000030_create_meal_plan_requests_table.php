<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('meal_plan_requests')) {
            return;
        }

        Schema::create('meal_plan_requests', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name', 255);
            $table->string('customer_phone', 50);
            $table->string('customer_email', 255)->nullable();
            $table->text('delivery_address')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('plan_meals'); // 20 or 26
            $table->string('status', 30)->default('new'); // new/contacted/converted/closed
            $table->json('order_ids')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plan_requests');
    }
};

