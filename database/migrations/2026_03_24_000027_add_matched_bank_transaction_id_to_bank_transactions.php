<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_transactions')) {
            return;
        }

        Schema::table('bank_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('bank_transactions', 'matched_bank_transaction_id')) {
                $table->unsignedBigInteger('matched_bank_transaction_id')->nullable()->after('reconciliation_run_id');
                $table->index('matched_bank_transaction_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_transactions')) {
            return;
        }

        Schema::table('bank_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('bank_transactions', 'matched_bank_transaction_id')) {
                $table->dropIndex(['matched_bank_transaction_id']);
                $table->dropColumn('matched_bank_transaction_id');
            }
        });
    }
};
