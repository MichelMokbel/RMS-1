<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sheets', function (Blueprint $t) {
            $t->id();
            $t->date('sheet_date')->unique();
            $t->timestamps();
        });

        Schema::create('order_sheet_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_sheet_id')->constrained()->cascadeOnDelete();
            $t->integer('customer_id')->unsigned(false)->nullable();
            $t->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $t->string('customer_name');
            $t->string('location')->nullable();
            $t->text('remarks')->nullable();
            $t->timestamps();
        });

        Schema::create('order_sheet_entry_quantities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_sheet_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('daily_dish_menu_item_id')->constrained('daily_dish_menu_items')->cascadeOnDelete();
            $t->unsignedInteger('quantity');
        });

        Schema::create('order_sheet_entry_extras', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_sheet_entry_id')->constrained()->cascadeOnDelete();
            $t->integer('menu_item_id');
            $t->foreign('menu_item_id')->references('id')->on('menu_items')->cascadeOnDelete();
            $t->string('menu_item_name');
            $t->unsignedInteger('quantity')->default(1);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sheet_entry_extras');
        Schema::dropIfExists('order_sheet_entry_quantities');
        Schema::dropIfExists('order_sheet_entries');
        Schema::dropIfExists('order_sheets');
    }
};
