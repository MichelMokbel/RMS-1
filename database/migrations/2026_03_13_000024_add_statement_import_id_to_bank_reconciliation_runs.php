<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_reconciliation_runs')) {
            return;
        }

        Schema::table('bank_reconciliation_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('bank_reconciliation_runs', 'statement_import_id')) {
                $table->unsignedBigInteger('statement_import_id')->nullable()->after('period_id');
                $table->index('statement_import_id', 'bank_reco_runs_statement_import_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_reconciliation_runs')) {
            return;
        }

        Schema::table('bank_reconciliation_runs', function (Blueprint $table) {
            if (Schema::hasColumn('bank_reconciliation_runs', 'statement_import_id')) {
                $table->dropIndex('bank_reco_runs_statement_import_idx');
                $table->dropColumn('statement_import_id');
            }
        });
    }
};
