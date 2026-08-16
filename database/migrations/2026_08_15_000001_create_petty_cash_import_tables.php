<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->date('business_date');
            $table->integer('default_category_id')->nullable();
            $table->integer('default_wallet_id')->nullable();
            $table->string('status', 30)->default('validating');
            $table->string('source_name', 255);
            $table->string('storage_disk', 60);
            $table->string('object_key', 1024);
            $table->char('sha256', 64);
            $table->char('idempotency_key', 64);
            $table->json('stats')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->unsignedBigInteger('committed_by')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'pc_import_company_idempotency_unique');
            $table->index(['company_id', 'business_date'], 'pc_import_company_date_idx');
            $table->index(['company_id', 'status'], 'pc_import_company_status_idx');
            $table->index('sha256', 'pc_import_sha256_idx');
        });

        Schema::create('petty_cash_import_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('petty_cash_import_batches')->restrictOnDelete();
            $table->string('entry_id', 191);
            $table->char('group_key', 64);
            $table->string('status', 30)->default('valid');
            $table->longText('header')->nullable();
            $table->longText('errors')->nullable();
            $table->uuid('client_uuid');
            $table->integer('target_invoice_id')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'entry_id'], 'pc_import_invoice_batch_entry_unique');
            $table->unique(['import_batch_id', 'group_key'], 'pc_import_invoice_batch_group_unique');
            $table->unique('client_uuid', 'pc_import_invoice_client_uuid_unique');
            $table->index(['import_batch_id', 'status'], 'pc_import_invoice_batch_status_idx');
            $table->index('target_invoice_id', 'pc_import_invoice_target_idx');
        });

        Schema::create('petty_cash_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('petty_cash_import_batches')->restrictOnDelete();
            $table->foreignId('import_invoice_id')->nullable()->constrained('petty_cash_import_invoices')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_identifier', 191)->nullable();
            $table->string('status', 30)->default('valid');
            $table->longText('payload')->nullable();
            $table->longText('errors')->nullable();
            $table->char('row_hash', 64);
            $table->string('target_type', 150)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number'], 'pc_import_row_batch_number_unique');
            $table->index(['import_invoice_id', 'row_number'], 'pc_import_row_invoice_number_idx');
            $table->index(['import_batch_id', 'status'], 'pc_import_row_batch_status_idx');
            $table->index(['target_type', 'target_id'], 'pc_import_row_target_idx');
        });
    }

    /** Import lineage is retained; production rollback is intentionally non-destructive. */
    public function down(): void
    {
        // No-op.
    }
};
