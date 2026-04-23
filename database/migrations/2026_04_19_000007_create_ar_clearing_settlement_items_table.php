<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ar_clearing_settlement_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('settlement_id');
            $table->unsignedBigInteger('payment_id');
            $table->integer('amount_cents')->default(0);
            $table->timestamps();

            $table->unique(['settlement_id', 'payment_id'], 'uq_acs_item_payment');
            $table->index('payment_id');
            $table->foreign('settlement_id')->references('id')->on('ar_clearing_settlements');
            $table->foreign('payment_id')->references('id')->on('payments');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_clearing_settlement_items');
    }
};
