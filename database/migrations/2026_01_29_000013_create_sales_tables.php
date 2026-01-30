<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales')) {
            Schema::create('sales', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedInteger('branch_id');
                $table->unsignedBigInteger('pos_shift_id')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();

                $table->string('sale_number', 32)->nullable(); // assigned on close (sequence)
                $table->string('status', 20)->default('open'); // draft|open|closed|voided|refunded

                $table->string('currency', 3)->default('KWD');

                // Money amounts are integers in minor units ("cents").
                $table->bigInteger('subtotal_cents')->default(0);
                $table->bigInteger('discount_total_cents')->default(0);
                $table->bigInteger('tax_total_cents')->default(0);
                $table->bigInteger('total_cents')->default(0);
                $table->bigInteger('paid_total_cents')->default(0);
                $table->bigInteger('due_total_cents')->default(0);

                $table->text('notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();

                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 255)->nullable();

                $table->timestamps();

                $table->unique(['branch_id', 'sale_number'], 'sales_branch_sale_number_unique');
                $table->index(['branch_id', 'status', 'created_at'], 'sales_branch_status_created_at_index');
                $table->index(['customer_id', 'status'], 'sales_customer_status_index');
                $table->index(['pos_shift_id'], 'sales_pos_shift_id_index');
            });
        }

        if (! Schema::hasTable('sale_items')) {
            Schema::create('sale_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('sale_id');

                $table->string('sellable_type', 150)->nullable();
                $table->unsignedBigInteger('sellable_id')->nullable();

                $table->string('name_snapshot', 255);
                $table->string('sku_snapshot', 100)->nullable();

                $table->unsignedInteger('tax_rate_bps')->default(0); // basis points (100 = 1%)

                $table->decimal('qty', 12, 3)->default(1);
                $table->bigInteger('unit_price_cents')->default(0);
                $table->bigInteger('discount_cents')->default(0);
                $table->bigInteger('tax_cents')->default(0);
                $table->bigInteger('line_total_cents')->default(0);

                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['sale_id'], 'sale_items_sale_id_index');
                $table->index(['sellable_type', 'sellable_id'], 'sale_items_sellable_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};

