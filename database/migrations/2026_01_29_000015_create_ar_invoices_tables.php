<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ar_invoices')) {
            Schema::create('ar_invoices', function (Blueprint $table) {
                $table->bigIncrements('id');

                $table->unsignedInteger('branch_id');
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('source_sale_id')->nullable();

                $table->string('type', 20)->default('invoice'); // invoice|credit_note
                $table->string('invoice_number', 32)->nullable(); // assigned on issue (sequence)
                $table->string('status', 30)->default('draft'); // draft|issued|partially_paid|paid|voided

                $table->date('issue_date')->nullable();
                $table->date('due_date')->nullable();

                $table->string('currency', 3)->default('KWD');

                // Money amounts are integers in minor units ("cents").
                $table->bigInteger('subtotal_cents')->default(0);
                $table->bigInteger('discount_total_cents')->default(0);
                $table->bigInteger('tax_total_cents')->default(0);
                $table->bigInteger('total_cents')->default(0);
                $table->bigInteger('paid_total_cents')->default(0);
                $table->bigInteger('balance_cents')->default(0);

                $table->text('notes')->nullable();

                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 255)->nullable();

                $table->timestamps();

                $table->unique(['branch_id', 'invoice_number'], 'ar_invoices_branch_invoice_number_unique');
                $table->index(['branch_id', 'status', 'issue_date'], 'ar_invoices_branch_status_issue_date_index');
                $table->index(['customer_id', 'status'], 'ar_invoices_customer_status_index');
                $table->index(['source_sale_id'], 'ar_invoices_source_sale_id_index');
            });
        }

        if (! Schema::hasTable('ar_invoice_items')) {
            Schema::create('ar_invoice_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('invoice_id');

                $table->string('description', 255);
                $table->decimal('qty', 12, 3)->default(1);
                $table->bigInteger('unit_price_cents')->default(0);
                $table->bigInteger('discount_cents')->default(0);
                $table->bigInteger('tax_cents')->default(0);
                $table->bigInteger('line_total_cents')->default(0);

                $table->string('sellable_type', 150)->nullable();
                $table->unsignedBigInteger('sellable_id')->nullable();
                $table->string('name_snapshot', 255)->nullable();
                $table->string('sku_snapshot', 100)->nullable();
                $table->json('meta')->nullable();

                $table->timestamps();

                $table->index(['invoice_id'], 'ar_invoice_items_invoice_id_index');
                $table->index(['sellable_type', 'sellable_id'], 'ar_invoice_items_sellable_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ar_invoice_items');
        Schema::dropIfExists('ar_invoices');
    }
};

