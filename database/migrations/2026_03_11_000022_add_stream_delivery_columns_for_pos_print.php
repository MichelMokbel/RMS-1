<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_terminals') && ! Schema::hasColumn('pos_terminals', 'print_stream_seen_at')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->timestamp('print_stream_seen_at')->nullable()->after('print_agent_seen_at');
                $table->index('print_stream_seen_at', 'pos_terminals_print_stream_seen_at_index');
            });
        }

        if (! Schema::hasTable('pos_print_stream_events')) {
            return;
        }

        if (! Schema::hasColumn('pos_print_stream_events', 'job_id')) {
            Schema::table('pos_print_stream_events', function (Blueprint $table) {
                $table->unsignedBigInteger('job_id')->nullable()->after('terminal_id');
            });
        }

        if (! Schema::hasColumn('pos_print_stream_events', 'claim_token')) {
            Schema::table('pos_print_stream_events', function (Blueprint $table) {
                $table->string('claim_token', 120)->nullable()->after('job_id');
            });
        }

        try {
            Schema::table('pos_print_stream_events', function (Blueprint $table) {
                $table->index('job_id', 'pos_print_stream_events_job_id_index');
            });
        } catch (\Throwable) {
            // already exists / unsupported by driver
        }

        try {
            Schema::table('pos_print_stream_events', function (Blueprint $table) {
                $table->index(['terminal_id', 'job_id', 'id'], 'pos_print_stream_events_terminal_job_id_index');
            });
        } catch (\Throwable) {
            // already exists / unsupported by driver
        }

        try {
            Schema::table('pos_print_stream_events', function (Blueprint $table) {
                $table->foreign('job_id', 'pos_print_stream_events_job_fk')
                    ->references('id')
                    ->on('pos_print_jobs')
                    ->nullOnDelete();
            });
        } catch (\Throwable) {
            // already exists / unsupported by driver
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_terminals') && Schema::hasColumn('pos_terminals', 'print_stream_seen_at')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->dropIndex('pos_terminals_print_stream_seen_at_index');
                $table->dropColumn('print_stream_seen_at');
            });
        }

        if (! Schema::hasTable('pos_print_stream_events')) {
            return;
        }

        try {
            Schema::table('pos_print_stream_events', function (Blueprint $table) {
                $table->dropForeign('pos_print_stream_events_job_fk');
            });
        } catch (\Throwable) {
            // already dropped / unsupported by driver
        }

        foreach ([
            'pos_print_stream_events_job_id_index',
            'pos_print_stream_events_terminal_job_id_index',
        ] as $indexName) {
            try {
                Schema::table('pos_print_stream_events', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            } catch (\Throwable) {
                // already dropped / unsupported by driver
            }
        }

        if (Schema::hasColumn('pos_print_stream_events', 'claim_token')) {
            Schema::table('pos_print_stream_events', function (Blueprint $table) {
                $table->dropColumn('claim_token');
            });
        }

        if (Schema::hasColumn('pos_print_stream_events', 'job_id')) {
            Schema::table('pos_print_stream_events', function (Blueprint $table) {
                $table->dropColumn('job_id');
            });
        }
    }
};
