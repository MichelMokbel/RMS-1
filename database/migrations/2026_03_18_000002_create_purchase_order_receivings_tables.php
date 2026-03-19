<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_order_receivings')) {
            Schema::create('purchase_order_receivings', function (Blueprint $table) {
                $table->id();
                $table->integer('purchase_order_id');
                $table->dateTime('received_at');
                $table->text('notes')->nullable();
                $table->integer('created_by')->nullable();
                $table->timestamps();

                $table->index(['purchase_order_id', 'received_at'], 'po_receivings_order_date_index');
                $table->index('created_by', 'po_receivings_created_by_index');

                $table->foreign('purchase_order_id', 'po_receivings_order_fk')
                    ->references('id')
                    ->on('purchase_orders')
                    ->cascadeOnDelete();
                $table->foreign('created_by', 'po_receivings_created_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('purchase_order_receiving_lines')) {
            Schema::create('purchase_order_receiving_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchase_order_receiving_id');
                $table->integer('purchase_order_item_id');
                $table->integer('inventory_item_id')->nullable();
                $table->decimal('received_quantity', 12, 3);
                $table->decimal('unit_cost', 12, 4)->nullable();
                $table->decimal('total_cost', 12, 4)->nullable();
                $table->timestamps();

                $table->index('purchase_order_item_id', 'po_receiving_lines_po_item_index');
                $table->index('inventory_item_id', 'po_receiving_lines_item_index');

                $table->foreign('purchase_order_receiving_id', 'po_receiving_lines_receiving_fk')
                    ->references('id')
                    ->on('purchase_order_receivings')
                    ->cascadeOnDelete();
                $table->foreign('purchase_order_item_id', 'po_receiving_lines_po_item_fk')
                    ->references('id')
                    ->on('purchase_order_items')
                    ->cascadeOnDelete();
                $table->foreign('inventory_item_id', 'po_receiving_lines_item_fk')
                    ->references('id')
                    ->on('inventory_items')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_receiving_lines');
        Schema::dropIfExists('purchase_order_receivings');
    }
};
