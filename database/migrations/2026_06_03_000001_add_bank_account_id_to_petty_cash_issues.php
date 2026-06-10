<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('petty_cash_issues') || ! Schema::hasTable('bank_accounts')) {
            return;
        }

        Schema::table('petty_cash_issues', function (Blueprint $table) {
            if (! Schema::hasColumn('petty_cash_issues', 'bank_account_id')) {
                $table->unsignedBigInteger('bank_account_id')->nullable()->after('method');
                $table->index('bank_account_id');
                $table->foreign('bank_account_id')->references('id')->on('bank_accounts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('petty_cash_issues')) {
            return;
        }

        Schema::table('petty_cash_issues', function (Blueprint $table) {
            if (Schema::hasColumn('petty_cash_issues', 'bank_account_id')) {
                $table->dropForeign(['bank_account_id']);
                $table->dropIndex(['bank_account_id']);
                $table->dropColumn('bank_account_id');
            }
        });
    }
};
