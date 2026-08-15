<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('employee_number', 50);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            // branches.id is a legacy unsigned INT. Deliberately no FK.
            $table->unsignedInteger('current_branch_id')->nullable();
            $table->unsignedBigInteger('current_department_id')->nullable();
            $table->string('legal_first_name', 100);
            $table->string('legal_middle_name', 100)->nullable();
            $table->string('legal_last_name', 100);
            $table->string('display_name', 200);
            $table->string('preferred_name', 100)->nullable();
            $table->string('work_email')->nullable();
            $table->string('personal_email')->nullable();
            $table->string('work_phone', 40)->nullable();
            $table->string('personal_phone', 40)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 100)->nullable();
            $table->string('gender', 30)->nullable();
            $table->text('qid_number')->nullable();
            $table->text('passport_number')->nullable();
            $table->json('address')->nullable();
            $table->json('emergency_contact')->nullable();
            $table->string('job_title', 150)->nullable();
            $table->string('employment_type', 40)->default('full_time');
            $table->string('employment_status', 30)->default('onboarding');
            $table->date('hire_date');
            $table->date('probation_end_date')->nullable();
            $table->date('notice_date')->nullable();
            $table->date('exit_date')->nullable();
            $table->text('exit_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'employee_number'], 'hr_employee_company_number_unique');
            $table->unique(['company_id', 'user_id'], 'hr_employee_company_user_unique');
            $table->index(['company_id', 'employment_status'], 'hr_employee_company_status_idx');
            $table->index(['company_id', 'current_branch_id'], 'hr_employee_company_branch_idx');
            $table->index(['company_id', 'current_department_id'], 'hr_employee_company_department_idx');
            $table->index('manager_id', 'hr_employee_manager_idx');
        });

        Schema::create('hr_employee_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('job_title', 150)->nullable();
            $table->string('employment_type', 40)->default('full_time');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from'], 'hr_assignment_employee_start_unique');
            $table->index(['company_id', 'branch_id', 'effective_to'], 'hr_assignment_company_branch_end_idx');
            $table->index(['company_id', 'department_id', 'effective_to'], 'hr_assignment_company_department_end_idx');
            $table->index(['manager_id', 'effective_to'], 'hr_assignment_manager_end_idx');
        });

        Schema::create('hr_compensation_packages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->char('currency', 3)->default('QAR');
            $table->string('pay_frequency', 30)->default('monthly');
            $table->decimal('proration_divisor', 8, 2)->default(30);
            $table->string('bank_name', 150)->nullable();
            $table->text('beneficiary_name')->nullable();
            $table->text('bank_account_number')->nullable();
            $table->text('iban')->nullable();
            $table->text('swift_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'effective_from'], 'hr_comp_package_employee_start_unique');
            $table->index(['company_id', 'effective_from', 'effective_to'], 'hr_comp_package_company_dates_idx');
        });

        Schema::create('hr_compensation_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('compensation_package_id')->constrained('hr_compensation_packages')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 120);
            $table->string('category', 40);
            $table->bigInteger('amount_minor');
            $table->boolean('is_taxable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['compensation_package_id', 'code'], 'hr_comp_component_package_code_unique');
        });

        Schema::create('hr_document_types', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('code', 60);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->json('required_for')->nullable();
            $table->boolean('requires_issue_date')->default(false);
            $table->boolean('requires_expiry_date')->default(false);
            $table->json('expiry_warning_days')->nullable();
            $table->boolean('is_sensitive')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'hr_document_type_company_code_unique');
            $table->index(['company_id', 'is_active'], 'hr_document_type_company_active_idx');
        });

        Schema::create('hr_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained('hr_document_types')->restrictOnDelete();
            $table->text('document_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('issuing_authority', 150)->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status'], 'hr_document_company_status_idx');
            $table->index(['company_id', 'expiry_date'], 'hr_document_company_expiry_idx');
            $table->index(['employee_id', 'document_type_id'], 'hr_document_employee_type_idx');
        });

        Schema::create('hr_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('document_id')->constrained('hr_documents')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('storage_disk', 60);
            // 512 remains below MySQL's utf8mb4 composite-index byte limit with company_id.
            $table->string('object_key', 512);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('scan_status', 30)->default('pending');
            $table->json('scan_metadata')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['document_id', 'version_number'], 'hr_document_version_number_unique');
            $table->unique(['company_id', 'object_key'], 'hr_document_version_object_key_unique');
            $table->index(['company_id', 'sha256'], 'hr_document_version_company_hash_idx');
        });
    }

    /** Forward-only: HR records are intentionally never dropped by rollback. */
    public function down(): void
    {
        // No-op.
    }
};
