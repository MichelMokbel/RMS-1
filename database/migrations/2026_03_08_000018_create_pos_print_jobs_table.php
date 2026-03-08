<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_print_jobs')) {
            return;
        }

        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('client_job_id', 100);
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('target_terminal_id');
            $table->string('job_type', 60)->default('receipt');
            $table->json('payload');
            $table->json('metadata')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique('client_job_id', 'pos_print_jobs_client_job_id_unique');
            $table->index(['target_terminal_id', 'status', 'next_retry_at', 'created_at'], 'pos_print_jobs_pull_index');
            $table->index('claim_expires_at', 'pos_print_jobs_claim_expires_at_index');
            $table->index(['branch_id', 'created_at'], 'pos_print_jobs_branch_created_at_index');

            $table->foreign('target_terminal_id', 'pos_print_jobs_target_terminal_fk')
                ->references('id')
                ->on('pos_terminals')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_print_jobs');
    }
};
