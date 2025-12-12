<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table) {
                $table->integer('id', true);
                $table->string('po_number', 50)->unique();
                $table->integer('supplier_id')->nullable()->index();
                $table->date('order_date')->nullable();
                $table->date('expected_delivery_date')->nullable();
                $table->enum('status', ['draft', 'pending', 'approved', 'received', 'cancelled'])->nullable()->default('draft');
                $table->decimal('total_amount', 10, 2)->nullable()->default(0.00);
                $table->date('received_date')->nullable();
                $table->text('notes')->nullable();
                $table->integer('created_by')->nullable()->index();
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('purchase_order_items')) {
            Schema::create('purchase_order_items', function (Blueprint $table) {
                $table->integer('id', true);
                $table->integer('purchase_order_id')->index();
                $table->integer('item_id')->nullable()->index();
                $table->integer('quantity');
                $table->decimal('unit_price', 10, 2)->nullable()->default(0.00);
                $table->decimal('total_price', 10, 2)->nullable()->default(0.00);
                $table->integer('received_quantity')->nullable()->default(0);
                $table->timestamp('created_at')->nullable()->useCurrent();
                $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
            });
        }
    }

    public function down(): void
    {
        // keep legacy data; do not drop tables
    }
};
