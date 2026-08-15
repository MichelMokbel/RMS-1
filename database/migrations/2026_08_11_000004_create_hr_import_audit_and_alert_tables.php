<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('type', 30);
            $table->string('status', 30)->default('uploaded');
            $table->string('source_name', 255);
            $table->string('storage_disk', 60);
            $table->string('object_key', 1024);
            $table->string('archive_object_key', 1024)->nullable();
            $table->char('sha256', 64);
            $table->json('options')->nullable();
            $table->json('stats')->nullable();
            $table->unsignedBigInteger('initiated_by')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->unsignedBigInteger('committed_by')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'type', 'sha256'], 'hr_import_company_type_hash_unique');
            $table->index(['company_id', 'status'], 'hr_import_company_status_idx');
        });

        Schema::create('hr_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('hr_import_batches')->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('source_identifier', 191)->nullable();
            $table->string('status', 30)->default('pending');
            // Import rows and validation errors may contain PII; encrypted casts require text columns.
            $table->longText('payload')->nullable();
            $table->longText('errors')->nullable();
            $table->char('row_hash', 64);
            $table->string('target_type', 150)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number'], 'hr_import_row_batch_number_unique');
            $table->index(['import_batch_id', 'status'], 'hr_import_row_batch_status_idx');
            $table->index(['target_type', 'target_id'], 'hr_import_row_target_idx');
        });

        Schema::create('hr_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 150)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->uuid('request_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            // Callers must keep raw PII and secrets out of this payload.
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at'], 'hr_audit_company_created_idx');
            $table->index(['subject_type', 'subject_id'], 'hr_audit_subject_idx');
            $table->index(['actor_id', 'created_at'], 'hr_audit_actor_created_idx');
            $table->index('request_id', 'hr_audit_request_idx');
        });

        Schema::create('hr_alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id')->nullable();
            $table->string('type', 60);
            $table->string('severity', 20)->default('warning');
            $table->string('status', 30)->default('open');
            $table->string('subject_type', 150)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('dedupe_key', 191);
            $table->timestamp('due_at')->nullable();
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'dedupe_key'], 'hr_alert_company_dedupe_unique');
            $table->index(['company_id', 'status', 'due_at'], 'hr_alert_company_status_due_idx');
            $table->index(['employee_id', 'status'], 'hr_alert_employee_status_idx');
            $table->index(['subject_type', 'subject_id'], 'hr_alert_subject_idx');
        });
    }

    /** Forward-only: import lineage and audit evidence must be retained. */
    public function down(): void
    {
        // No-op.
    }
};
