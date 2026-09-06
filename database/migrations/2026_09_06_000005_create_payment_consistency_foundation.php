<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_consistency_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('reference', 36)->unique('payment_consistency_runs_reference_unique');
            $table->unsignedBigInteger('company_id');
            $table->integer('branch_id')->nullable();
            $table->string('kind', 20);
            $table->string('state', 20)->default('queued');
            $table->integer('requested_by')->nullable();
            $table->string('rule_code', 80)->nullable();
            $table->json('rule_versions');
            $table->char('registry_hash', 64);
            $table->string('trigger_key', 190);
            $table->unsignedBigInteger('parent_run_id')->nullable();
            $table->dateTime('not_before', 6)->nullable();
            $table->string('target_type', 80)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('upper_bound')->nullable();
            $table->json('cursors')->nullable();
            $table->dateTime('heartbeat_at', 6)->nullable();
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('completed_at', 6)->nullable();
            $table->unsignedInteger('checked_count')->default(0);
            $table->unsignedInteger('deferred_count')->default(0);
            $table->unsignedInteger('open_count')->default(0);
            $table->unsignedInteger('resolved_count')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamps(6);

            $table->unique(['company_id', 'trigger_key'], 'payment_consistency_runs_trigger_unique');
            $table->index(['state', 'heartbeat_at', 'id'], 'payment_consistency_runs_state_heartbeat_idx');
            $table->index(['company_id', 'created_at', 'id'], 'payment_consistency_runs_company_created_idx');
            $table->index(['target_type', 'target_id'], 'payment_consistency_runs_target_idx');

            $table->foreign('company_id', 'payment_consistency_runs_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('branch_id', 'payment_consistency_runs_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('requested_by', 'payment_consistency_runs_requester_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('parent_run_id', 'payment_consistency_runs_parent_fk')
                ->references('id')->on('payment_consistency_runs')->restrictOnDelete();
        });

        Schema::create('payment_consistency_findings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->integer('branch_id')->nullable();
            $table->string('rule_code', 80);
            $table->unsignedSmallInteger('rule_version');
            $table->string('subject_type', 80);
            $table->unsignedBigInteger('subject_id');
            $table->char('episode_uuid', 36);
            $table->string('state', 20)->default('open');
            $table->dateTime('first_seen_at', 6);
            $table->dateTime('last_seen_at', 6);
            $table->dateTime('resolved_at', 6)->nullable();
            $table->unsignedBigInteger('last_run_id');
            $table->unsignedBigInteger('checkout_id')->nullable();
            $table->json('expected');
            $table->json('observed');
            $table->char('evidence_fingerprint', 64);
            $table->json('alert_dispatch')->nullable();
            $table->timestamps(6);

            $table->unique(
                ['subject_type', 'subject_id', 'rule_code'],
                'payment_consistency_findings_subject_rule_unique'
            );
            $table->index(
                ['company_id', 'branch_id', 'state', 'last_seen_at'],
                'payment_consistency_findings_scope_state_idx'
            );
            $table->index('episode_uuid', 'payment_consistency_findings_episode_idx');

            $table->foreign('company_id', 'payment_consistency_findings_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('branch_id', 'payment_consistency_findings_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('last_run_id', 'payment_consistency_findings_run_fk')
                ->references('id')->on('payment_consistency_runs')->restrictOnDelete();
            $table->foreign('checkout_id', 'payment_consistency_findings_checkout_fk')
                ->references('id')->on('payment_checkout_attempts')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE payment_consistency_runs ADD CONSTRAINT payment_consistency_runs_kind_chk '
            ."CHECK (kind IN ('targeted', 'catchup', 'full', 'manual'))"
        );
        DB::statement(
            'ALTER TABLE payment_consistency_runs ADD CONSTRAINT payment_consistency_runs_state_chk '
            ."CHECK (state IN ('queued', 'running', 'completed', 'failed'))"
        );
        DB::statement(
            'ALTER TABLE payment_consistency_findings ADD CONSTRAINT payment_consistency_findings_state_chk '
            ."CHECK (state IN ('open', 'resolved'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_consistency_findings');
        Schema::dropIfExists('payment_consistency_runs');
    }
};
