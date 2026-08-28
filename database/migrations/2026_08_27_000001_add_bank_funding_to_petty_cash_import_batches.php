<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petty_cash_import_batches', function (Blueprint $table): void {
            $table->string('funding_source', 30)->default('petty_cash')->after('default_paid');
            $table->unsignedBigInteger('default_bank_account_id')->nullable()->after('funding_source');
            $table->index(
                ['company_id', 'funding_source', 'default_bank_account_id'],
                'pc_import_company_funding_bank_idx'
            );
        });
    }

    /** Import lineage is retained; production rollback is intentionally non-destructive. */
    public function down(): void
    {
        // No-op.
    }
};
