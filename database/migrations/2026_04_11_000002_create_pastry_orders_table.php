<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pastry_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->unsignedInteger('branch_id')->nullable();
            $table->string('status')->default('Draft');
            $table->string('type')->default('Pickup');
            $table->unsignedInteger('customer_id')->nullable();
            $table->string('customer_name_snapshot');
            $table->string('customer_phone_snapshot')->nullable();
            $table->text('delivery_address_snapshot')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();
            $table->text('notes')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_disk')->default('s3');
            $table->decimal('order_discount_amount', 12, 3)->default(0);
            $table->decimal('total_before_tax', 12, 3)->default(0);
            $table->decimal('tax_amount', 12, 3)->default(0);
            $table->decimal('total_amount', 12, 3)->default(0);
            $table->datetime('invoiced_at')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            // No FK constraints on branch_id/customer_id/created_by — matching project convention

            $table->index('branch_id');
            $table->index('status');
            $table->index('scheduled_date');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pastry_orders');
    }
};
