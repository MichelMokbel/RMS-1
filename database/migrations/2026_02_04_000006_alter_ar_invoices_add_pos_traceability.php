<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ar_invoices')) {
            return;
        }

        Schema::table('ar_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('ar_invoices', 'terminal_id')) {
                $table->unsignedBigInteger('terminal_id')->nullable()->after('branch_id');
            }
            if (! Schema::hasColumn('ar_invoices', 'pos_shift_id')) {
                $table->unsignedBigInteger('pos_shift_id')->nullable()->after('terminal_id');
            }
            if (! Schema::hasColumn('ar_invoices', 'client_uuid')) {
                $table->char('client_uuid', 36)->nullable()->after('pos_reference');
                $table->unique(['client_uuid'], 'ar_invoices_client_uuid_unique');
            }
            if (! Schema::hasColumn('ar_invoices', 'restaurant_table_id')) {
                $table->unsignedBigInteger('restaurant_table_id')->nullable()->after('client_uuid');
            }
            if (! Schema::hasColumn('ar_invoices', 'table_session_id')) {
                $table->unsignedBigInteger('table_session_id')->nullable()->after('restaurant_table_id');
                $table->index(['table_session_id'], 'ar_invoices_table_session_id_index');
            }
            if (! Schema::hasColumn('ar_invoices', 'meta')) {
                $table->json('meta')->nullable()->after('notes');
            }
        });

        // Indexes / constraints that depend on existing columns.
        Schema::table('ar_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('ar_invoices', 'terminal_id') && Schema::hasColumn('ar_invoices', 'issue_date')
                && ! $this->indexExists('ar_invoices', 'ar_invoices_terminal_issue_date_index')) {
                $table->index(['terminal_id', 'issue_date'], 'ar_invoices_terminal_issue_date_index');
            }
            if (Schema::hasColumn('ar_invoices', 'branch_id') && Schema::hasColumn('ar_invoices', 'pos_reference')
                && ! $this->indexExists('ar_invoices', 'ar_invoices_branch_pos_reference_unique')) {
                $table->unique(['branch_id', 'pos_reference'], 'ar_invoices_branch_pos_reference_unique');
            }
        });

        // Foreign keys (best-effort; tables may not exist in some installs).
        if (Schema::hasTable('pos_terminals')) {
            Schema::table('ar_invoices', function (Blueprint $table) {
                if (Schema::hasColumn('ar_invoices', 'terminal_id')) {
                    $table->foreign('terminal_id', 'ar_invoices_terminal_fk')
                        ->references('id')
                        ->on('pos_terminals')
                        ->nullOnDelete();
                }
            });
        }
        if (Schema::hasTable('pos_shifts')) {
            Schema::table('ar_invoices', function (Blueprint $table) {
                if (Schema::hasColumn('ar_invoices', 'pos_shift_id')) {
                    $table->foreign('pos_shift_id', 'ar_invoices_shift_fk')
                        ->references('id')
                        ->on('pos_shifts')
                        ->nullOnDelete();
                }
            });
        }
        if (Schema::hasTable('restaurant_tables')) {
            Schema::table('ar_invoices', function (Blueprint $table) {
                if (Schema::hasColumn('ar_invoices', 'restaurant_table_id')) {
                    $table->foreign('restaurant_table_id', 'ar_invoices_restaurant_table_fk')
                        ->references('id')
                        ->on('restaurant_tables')
                        ->nullOnDelete();
                }
            });
        }
        if (Schema::hasTable('restaurant_table_sessions')) {
            Schema::table('ar_invoices', function (Blueprint $table) {
                if (Schema::hasColumn('ar_invoices', 'table_session_id')) {
                    $table->foreign('table_session_id', 'ar_invoices_table_session_fk')
                        ->references('id')
                        ->on('restaurant_table_sessions')
                        ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ar_invoices')) {
            return;
        }

        Schema::table('ar_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('ar_invoices', 'client_uuid')) {
                $table->dropUnique('ar_invoices_client_uuid_unique');
                $table->dropColumn('client_uuid');
            }
            if (Schema::hasColumn('ar_invoices', 'branch_id') && Schema::hasColumn('ar_invoices', 'pos_reference')) {
                $table->dropUnique('ar_invoices_branch_pos_reference_unique');
            }
            if (Schema::hasColumn('ar_invoices', 'terminal_id')) {
                $table->dropForeign('ar_invoices_terminal_fk');
                $table->dropIndex('ar_invoices_terminal_issue_date_index');
                $table->dropColumn('terminal_id');
            }
            if (Schema::hasColumn('ar_invoices', 'pos_shift_id')) {
                $table->dropForeign('ar_invoices_shift_fk');
                $table->dropColumn('pos_shift_id');
            }
            if (Schema::hasColumn('ar_invoices', 'restaurant_table_id')) {
                $table->dropForeign('ar_invoices_restaurant_table_fk');
                $table->dropColumn('restaurant_table_id');
            }
            if (Schema::hasColumn('ar_invoices', 'table_session_id')) {
                $table->dropForeign('ar_invoices_table_session_fk');
                $table->dropIndex('ar_invoices_table_session_id_index');
                $table->dropColumn('table_session_id');
            }
            if (Schema::hasColumn('ar_invoices', 'meta')) {
                $table->dropColumn('meta');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return $row !== null;
    }
};

