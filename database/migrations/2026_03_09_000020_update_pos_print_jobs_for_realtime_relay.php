<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_print_jobs')) {
            return;
        }

        if (! Schema::hasColumn('pos_print_jobs', 'source_terminal_id')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->unsignedBigInteger('source_terminal_id')->nullable()->after('client_job_id');
            });
        }

        if (! Schema::hasColumn('pos_print_jobs', 'target')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->string('target', 100)->nullable()->after('target_terminal_id');
            });
        }

        if (! Schema::hasColumn('pos_print_jobs', 'doc_type')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->string('doc_type', 60)->nullable()->after('target');
            });
        }

        if (! Schema::hasColumn('pos_print_jobs', 'payload_base64')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->longText('payload_base64')->nullable()->after('doc_type');
            });
        }

        if (! Schema::hasColumn('pos_print_jobs', 'client_created_at')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->timestamp('client_created_at')->nullable()->after('payload_base64');
            });
        }

        if (! Schema::hasColumn('pos_print_jobs', 'claimed_by_terminal_id')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->unsignedBigInteger('claimed_by_terminal_id')->nullable()->after('claimed_at');
            });
        }

        if (! Schema::hasColumn('pos_print_jobs', 'claim_token')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->string('claim_token', 120)->nullable()->after('claimed_by_terminal_id');
            });
        }

        if (! Schema::hasColumn('pos_print_jobs', 'processing_ms')) {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->unsignedInteger('processing_ms')->nullable()->after('acked_at');
            });
        }

        DB::table('pos_print_jobs')
            ->whereNull('source_terminal_id')
            ->update(['source_terminal_id' => DB::raw('target_terminal_id')]);

        DB::table('pos_print_jobs')
            ->where('status', 'pending')
            ->update(['status' => 'queued']);
        DB::table('pos_print_jobs')
            ->where('status', 'completed')
            ->update(['status' => 'printed']);

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->dropUnique('pos_print_jobs_client_job_id_unique');
            });
        } catch (\Throwable) {
            // already dropped / unsupported by driver
        }

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->unique(['source_terminal_id', 'client_job_id'], 'pos_print_jobs_source_client_job_unique');
            });
        } catch (\Throwable) {
            // already exists
        }

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->index(['target_terminal_id', 'status', 'created_at'], 'pos_print_jobs_terminal_status_created_index');
            });
        } catch (\Throwable) {
            // already exists
        }

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->index(['target_terminal_id', 'status', 'claim_expires_at'], 'pos_print_jobs_terminal_claim_expiry_index');
            });
        } catch (\Throwable) {
            // already exists
        }

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->foreign('source_terminal_id', 'pos_print_jobs_source_terminal_fk')
                    ->references('id')
                    ->on('pos_terminals')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // already exists / unsupported by driver
        }

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->foreign('claimed_by_terminal_id', 'pos_print_jobs_claimed_by_terminal_fk')
                    ->references('id')
                    ->on('pos_terminals')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // already exists / unsupported by driver
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_print_jobs')) {
            return;
        }

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->dropForeign('pos_print_jobs_source_terminal_fk');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->dropForeign('pos_print_jobs_claimed_by_terminal_fk');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->dropUnique('pos_print_jobs_source_client_job_unique');
            });
        } catch (\Throwable) {
        }

        try {
            Schema::table('pos_print_jobs', function (Blueprint $table) {
                $table->unique('client_job_id', 'pos_print_jobs_client_job_id_unique');
            });
        } catch (\Throwable) {
        }

        foreach ([
            'pos_print_jobs_terminal_status_created_index',
            'pos_print_jobs_terminal_claim_expiry_index',
        ] as $index) {
            try {
                Schema::table('pos_print_jobs', function (Blueprint $table) use ($index) {
                    $table->dropIndex($index);
                });
            } catch (\Throwable) {
            }
        }

        foreach ([
            'source_terminal_id',
            'target',
            'doc_type',
            'payload_base64',
            'client_created_at',
            'claimed_by_terminal_id',
            'claim_token',
            'processing_ms',
        ] as $column) {
            if (! Schema::hasColumn('pos_print_jobs', $column)) {
                continue;
            }
            Schema::table('pos_print_jobs', function (Blueprint $table) use ($column) {
                $table->dropColumn($column);
            });
        }
    }
};
