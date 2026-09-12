<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_label_printer_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('terminal_id');
            $table->string('code', 40);
            $table->string('name', 100);
            $table->string('department', 30);
            $table->string('model_code', 100);
            $table->string('os_queue_name', 180);
            $table->string('connection_description', 255)->nullable();
            $table->unsignedSmallInteger('resolution_dpi');
            $table->string('media_mode', 20)->default('fixed');
            $table->unsignedSmallInteger('width_tenths_mm');
            $table->unsignedSmallInteger('height_tenths_mm')->nullable();
            $table->unsignedSmallInteger('min_height_tenths_mm')->nullable();
            $table->unsignedSmallInteger('max_height_tenths_mm')->nullable();
            $table->unsignedTinyInteger('default_copies')->default(1);
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->unsignedBigInteger('last_tested_by')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code'], 'label_profiles_company_code_unique');
            $table->index(['branch_id', 'department', 'is_active'], 'label_profiles_branch_department_index');
            $table->foreign('company_id')->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('terminal_id')->references('id')->on('pos_terminals')->restrictOnDelete();
        });

        Schema::create('order_label_prints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('printer_profile_id');
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id');
            $table->date('service_date')->nullable();
            $table->json('snapshot');
            $table->char('snapshot_hash', 64);
            $table->unsignedTinyInteger('copy_count')->default(1);
            $table->unsignedInteger('sequence')->default(1);
            $table->unsignedBigInteger('reprint_of_id')->nullable();
            $table->string('reprint_reason', 500)->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('pos_print_job_id')->nullable();
            $table->string('status', 20)->default('preparing');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['printer_profile_id', 'source_type', 'source_id', 'sequence'],
                'label_prints_profile_source_sequence_unique'
            );
            $table->index(['branch_id', 'service_date', 'status'], 'label_prints_branch_date_status_index');
            $table->foreign('company_id')->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('printer_profile_id')->references('id')->on('order_label_printer_profiles')->restrictOnDelete();
            $table->foreign('reprint_of_id')->references('id')->on('order_label_prints')->restrictOnDelete();
            $table->foreign('pos_print_job_id')->references('id')->on('pos_print_jobs')->nullOnDelete();
        });

        Schema::table('pos_print_jobs', function (Blueprint $table) {
            $table->uuid('server_job_uuid')->nullable()->after('client_job_id');
            $table->unsignedBigInteger('order_label_print_id')->nullable()->after('server_job_uuid');
            $table->unique('server_job_uuid', 'pos_print_jobs_server_uuid_unique');
            $table->unique('order_label_print_id', 'pos_print_jobs_label_print_unique');
            $table->foreign('order_label_print_id', 'pos_print_jobs_label_print_fk')
                ->references('id')
                ->on('order_label_prints')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pos_print_jobs', function (Blueprint $table) {
            $table->dropForeign('pos_print_jobs_label_print_fk');
            $table->dropUnique('pos_print_jobs_label_print_unique');
            $table->dropUnique('pos_print_jobs_server_uuid_unique');
            $table->dropColumn(['server_job_uuid', 'order_label_print_id']);
        });

        Schema::dropIfExists('order_label_prints');
        Schema::dropIfExists('order_label_printer_profiles');
    }
};
