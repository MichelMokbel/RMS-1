<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('code', 60);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_paid')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'hr_leave_type_company_code_unique');
            $table->index(['company_id', 'is_active'], 'hr_leave_type_company_active_idx');
        });

        Schema::create('hr_leave_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->string('name', 150);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->decimal('entitlement_days', 8, 2)->default(0);
            $table->decimal('accrual_rate_days', 8, 4)->default(0);
            $table->string('accrual_frequency', 30)->default('annual');
            $table->decimal('carryover_limit_days', 8, 2)->default(0);
            $table->decimal('max_balance_days', 8, 2)->nullable();
            $table->unsignedInteger('waiting_period_days')->default(0);
            $table->boolean('allow_negative')->default(false);
            $table->boolean('requires_attachment')->default(false);
            $table->boolean('counts_calendar_days')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('rules')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'leave_type_id', 'is_active'], 'hr_leave_policy_company_type_active_idx');
            $table->index(['company_id', 'effective_from', 'effective_to'], 'hr_leave_policy_company_dates_idx');
        });

        Schema::create('hr_leave_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('start_portion', 20)->default('full');
            $table->string('end_portion', 20)->default('full');
            $table->decimal('requested_days', 8, 2);
            $table->string('status', 30)->default('draft');
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('attachment_document_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('decision_reason')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'start_date'], 'hr_leave_request_company_status_start_idx');
            $table->index(['employee_id', 'start_date', 'end_date'], 'hr_leave_request_employee_dates_idx');
            $table->index(['manager_id', 'status'], 'hr_leave_request_manager_status_idx');
        });

        Schema::create('hr_leave_request_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('leave_request_id')->constrained('hr_leave_requests')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('action', 50);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at'], 'hr_leave_event_company_created_idx');
        });

        Schema::create('hr_leave_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->unsignedBigInteger('policy_id')->nullable();
            $table->unsignedBigInteger('leave_request_id')->nullable();
            $table->string('entry_type', 30);
            // Signed: credits are positive; usage/reservations are negative.
            $table->decimal('days', 8, 2);
            $table->date('effective_date');
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->decimal('balance_after_days', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'idempotency_key'], 'hr_leave_ledger_company_idempotency_unique');
            $table->index(['employee_id', 'leave_type_id', 'effective_date'], 'hr_leave_ledger_employee_type_date_idx');
            $table->index('leave_request_id', 'hr_leave_ledger_request_idx');
        });
    }

    /** Forward-only: leave history is an auditable business record. */
    public function down(): void
    {
        // No-op.
    }
};
