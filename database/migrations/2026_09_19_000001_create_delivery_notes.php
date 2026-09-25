<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->integer('customer_id')->unsigned(false);
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreignId('source_invoice_id')->nullable()->unique()->constrained('ar_invoices')->nullOnDelete();
            $table->string('delivery_note_number', 32)->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('delivery_date');
            $table->string('customer_name_snapshot');
            $table->text('delivery_address_snapshot')->nullable();
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'delivery_note_number'], 'delivery_notes_branch_number_unique');
            $table->index(['branch_id', 'status', 'delivery_date'], 'delivery_notes_branch_status_date_index');
            $table->index(['customer_id', 'delivery_date'], 'delivery_notes_customer_date_index');
        });

        Schema::create('delivery_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_note_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('qty', 12, 3)->default(1);
            $table->string('unit', 30)->nullable();
            $table->bigInteger('unit_price_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->string('sellable_type', 150)->nullable();
            $table->unsignedBigInteger('sellable_id')->nullable();
            $table->string('name_snapshot')->nullable();
            $table->string('sku_snapshot', 100)->nullable();
            $table->text('line_notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['sellable_type', 'sellable_id'], 'delivery_note_items_sellable_index');
        });

        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->foreignId('source_delivery_note_id')->nullable()->unique()->after('source_quotation_version_id')
                ->constrained('delivery_notes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_delivery_note_id');
        });
        Schema::dropIfExists('delivery_note_items');
        Schema::dropIfExists('delivery_notes');
    }
};
