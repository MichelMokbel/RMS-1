<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_transfer_lines')) {
            return;
        }

        Schema::create('inventory_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transfer_id');
            $table->integer('inventory_item_id');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost_snapshot', 12, 4)->nullable();
            $table->decimal('total_cost', 12, 4)->nullable();
            $table->timestamps();

            $table->index(['transfer_id'], 'inventory_transfer_lines_transfer_index');
            $table->index(['inventory_item_id'], 'inventory_transfer_lines_item_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transfer_lines');
    }
};
