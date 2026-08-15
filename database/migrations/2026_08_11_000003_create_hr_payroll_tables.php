<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('run_number', 60);
            $table->unsignedBigInteger('period_id')->nullable();
            $table->date('pay_period_start');
            $table->date('pay_period_end');
            $table->date('scheduled_payment_date')->nullable();
            $table->char('currency', 3)->default('QAR');
            $table->string('origin', 20)->default('native');
            $table->string('status', 30)->default('draft');
            $table->boolean('is_postable')->default(true);
            $table->decimal('proration_divisor', 8, 2)->default(30);
            $table->text('description')->nullable();
            $table->unsignedBigInteger('prepared_by')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->unsignedBigInteger('calculated_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'run_number'], 'hr_payroll_run_company_number_unique');
            $table->index(['company_id', 'status', 'pay_period_start'], 'hr_payroll_run_company_status_start_idx');
            $table->index(['company_id', 'origin'], 'hr_payroll_run_company_origin_idx');
        });

        Schema::create('hr_payroll_results', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->unsignedBigInteger('compensation_package_id')->nullable();
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->char('currency', 3)->default('QAR');
            $table->bigInteger('basic_minor')->default(0);
            $table->bigInteger('gross_minor')->default(0);
            $table->bigInteger('earnings_minor')->default(0);
            $table->bigInteger('deductions_minor')->default(0);
            $table->bigInteger('net_minor')->default(0);
            $table->decimal('calendar_days', 8, 2)->default(0);
            $table->decimal('worked_days', 8, 2)->default(0);
            $table->decimal('unpaid_leave_days', 8, 2)->default(0);
            $table->decimal('proration_divisor', 8, 2)->default(30);
            $table->json('snapshot')->nullable();
            $table->unsignedBigInteger('payslip_document_id')->nullable();
            $table->boolean('is_postable')->default(true);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id'], 'hr_payroll_result_run_employee_unique');
            $table->index(['company_id', 'branch_id', 'department_id'], 'hr_payroll_result_dimensions_idx');
        });

        Schema::create('hr_payroll_result_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_result_id')->constrained('hr_payroll_results')->restrictOnDelete();
            $table->string('code', 60);
            $table->string('name', 120);
            $table->string('category', 40);
            $table->bigInteger('amount_minor');
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->boolean('is_taxable')->default(false);
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->index(['payroll_result_id', 'category'], 'hr_payroll_component_result_category_idx');
        });

        Schema::create('hr_payroll_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('code', 60);
            $table->string('description', 200);
            $table->unsignedBigInteger('amount_minor');
            $table->string('source_type', 100)->nullable();
            $table->string('source_id', 100)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'idempotency_key'], 'hr_payroll_adjustment_company_idempotency_unique');
            $table->index(['payroll_run_id', 'employee_id'], 'hr_payroll_adjustment_run_employee_idx');
        });

        Schema::create('hr_payroll_status_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->restrictOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at'], 'hr_payroll_event_company_created_idx');
        });

        Schema::create('hr_payroll_payment_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->restrictOnDelete();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('batch_number', 60);
            $table->date('payment_date');
            $table->char('currency', 3)->default('QAR');
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->unsignedInteger('item_count')->default(0);
            $table->string('status', 30)->default('draft');
            $table->string('reference', 120)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            $table->unsignedBigInteger('bank_transaction_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'batch_number'], 'hr_payroll_payment_company_number_unique');
            $table->index(['company_id', 'status', 'payment_date'], 'hr_payroll_payment_company_status_date_idx');
        });

        Schema::create('hr_payroll_payment_batch_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_batch_id')->constrained('hr_payroll_payment_batches')->restrictOnDelete();
            $table->foreignId('payroll_result_id')->constrained('hr_payroll_results')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->text('beneficiary_name')->nullable();
            $table->text('bank_account_number')->nullable();
            $table->text('iban')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['payment_batch_id', 'payroll_result_id'], 'hr_payroll_payment_item_result_unique');
        });
    }

    /** Forward-only: payroll and payment records are financial evidence. */
    public function down(): void
    {
        // No-op.
    }
};
