<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pastry_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pastry_order_id');
            $table->unsignedInteger('menu_item_id')->nullable();
            $table->string('description_snapshot');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 3);
            $table->decimal('discount_amount', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3);
            $table->string('status')->default('Pending');
            $table->integer('sort_order')->default(0);

            $table->foreign('pastry_order_id')->references('id')->on('pastry_orders')->cascadeOnDelete();
            // No FK on menu_item_id — matching project convention for int(11) parent tables

            $table->index('pastry_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pastry_order_items');
    }
};
