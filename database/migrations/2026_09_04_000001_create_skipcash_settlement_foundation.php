<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_settlement_imports', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('payment_source_id');
            $table->integer('imported_by');
            $table->string('private_disk', 80);
            $table->string('object_key', 255);
            $table->string('original_name', 255);
            $table->char('file_hash', 64);
            $table->string('parser_version', 40);
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->date('report_period_start')->nullable();
            $table->date('report_period_end')->nullable();
            $table->bigInteger('gross_cents')->default(0);
            $table->bigInteger('commission_cents')->default(0);
            $table->bigInteger('settlement_fee_cents')->default(0);
            $table->bigInteger('net_cents')->default(0);
            $table->boolean('totals_complete')->default(false);
            $table->string('review_state', 30)->default('draft');
            $table->string('posting_state', 30)->default('unposted');
            $table->unsignedInteger('revision')->default(1);
            $table->longText('review_snapshots')->nullable();
            $table->longText('evidence_manifest')->nullable();
            $table->longText('saved_post_operations')->nullable();
            $table->integer('reviewed_by')->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['payment_source_id', 'file_hash'], 'gateway_imports_source_hash_unique');
            $table->index(['company_id', 'created_at'], 'gateway_imports_company_created_idx');
            $table->index(['payment_source_id', 'posting_state'], 'gateway_imports_source_posting_idx');

            $table->foreign('company_id', 'gateway_imports_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('payment_source_id', 'gateway_imports_source_fk')
                ->references('id')->on('payment_sources')->restrictOnDelete();
            $table->foreign('imported_by', 'gateway_imports_imported_by_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by', 'gateway_imports_reviewed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('gateway_settlement_rows', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('import_id');
            $table->unsignedBigInteger('payment_source_id');
            $table->unsignedInteger('row_sequence');
            $table->string('worksheet', 120);
            $table->unsignedInteger('physical_row');
            $table->string('order_type', 40)->nullable();
            $table->string('status', 40)->nullable();
            $table->string('payout_reference', 160)->nullable();
            $table->string('row_reference', 160)->nullable();
            $table->char('economic_identity', 64)->nullable();
            $table->char('financial_content_hash', 64)->nullable();
            $table->longText('source_evidence');
            $table->longText('customer_phone')->nullable();
            $table->char('customer_phone_hash', 64)->nullable();
            $table->string('merchant', 160)->nullable();
            $table->string('branch_code', 120)->nullable();
            $table->integer('branch_id')->nullable();
            $table->dateTime('transaction_at', 6)->nullable();
            $table->date('explicit_bank_date')->nullable();
            $table->bigInteger('sales_cents')->nullable();
            $table->bigInteger('gross_cents')->nullable();
            $table->bigInteger('variable_commission_cents')->nullable();
            $table->bigInteger('fixed_commission_cents')->nullable();
            $table->bigInteger('total_commission_cents')->nullable();
            $table->bigInteger('settlement_fee_cents')->nullable();
            $table->bigInteger('net_cents')->nullable();
            $table->string('match_state', 30)->default('unmatched');
            $table->unsignedBigInteger('matched_provider_transaction_id')->nullable();
            $table->unsignedBigInteger('duplicate_of_row_id')->nullable();
            $table->string('evidence_reference', 80)->nullable();
            $table->integer('reviewed_by')->nullable();
            $table->dateTime('reviewed_at', 6)->nullable();
            $table->string('match_reason', 255)->nullable();
            $table->string('error_code', 80)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['import_id', 'row_sequence'], 'gateway_rows_import_sequence_unique');
            $table->index(['payment_source_id', 'economic_identity'], 'gateway_rows_source_identity_idx');
            $table->index(['payment_source_id', 'payout_reference'], 'gateway_rows_source_payout_idx');
            $table->index(['import_id', 'match_state'], 'gateway_rows_import_match_idx');
            $table->index('customer_phone_hash', 'gateway_rows_phone_hash_idx');

            $table->foreign('import_id', 'gateway_rows_import_fk')
                ->references('id')->on('gateway_settlement_imports')->restrictOnDelete();
            $table->foreign('payment_source_id', 'gateway_rows_source_fk')
                ->references('id')->on('payment_sources')->restrictOnDelete();
            $table->foreign('branch_id', 'gateway_rows_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('matched_provider_transaction_id', 'gateway_rows_provider_tx_fk')
                ->references('id')->on('payment_provider_transactions')->restrictOnDelete();
            $table->foreign('duplicate_of_row_id', 'gateway_rows_duplicate_fk')
                ->references('id')->on('gateway_settlement_rows')->restrictOnDelete();
            $table->foreign('reviewed_by', 'gateway_rows_reviewed_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        DB::statement("ALTER TABLE ar_clearing_settlements MODIFY settlement_method ENUM('card', 'cheque', 'skipcash') NOT NULL");
        DB::statement('ALTER TABLE ar_clearing_settlements MODIFY amount_cents BIGINT NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE ar_clearing_settlement_items MODIFY amount_cents BIGINT NOT NULL DEFAULT 0');

        Schema::table('ar_clearing_settlements', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_source_id')->nullable()->after('company_id');
            $table->unsignedBigInteger('gateway_import_id')->nullable()->after('payment_source_id');
            $table->unsignedBigInteger('evidence_bank_transaction_id')->nullable()->after('bank_account_id');
            $table->bigInteger('commission_cents')->default(0)->after('amount_cents');
            $table->bigInteger('settlement_fee_cents')->default(0)->after('commission_cents');
            $table->bigInteger('net_cents')->nullable()->after('settlement_fee_cents');
            $table->string('payout_reference', 160)->nullable()->after('reference');
            $table->char('reviewed_fingerprint', 64)->nullable()->after('payout_reference');
            $table->longText('evidence_snapshot')->nullable()->after('reviewed_fingerprint');
            $table->longText('original_clearing_breakdown')->nullable()->after('evidence_snapshot');
            $table->unsignedBigInteger('commission_expense_account_id')->nullable()->after('original_clearing_breakdown');
            $table->unsignedBigInteger('settlement_fee_expense_account_id')->nullable()->after('commission_expense_account_id');
            $table->unsignedBigInteger('bank_ledger_account_id')->nullable()->after('settlement_fee_expense_account_id');

            $table->unique(['payment_source_id', 'payout_reference'], 'ar_settlements_source_payout_unique');
            $table->unique('evidence_bank_transaction_id', 'ar_settlements_bank_evidence_unique');
            $table->index('gateway_import_id', 'ar_settlements_gateway_import_idx');

            $table->foreign('payment_source_id', 'ar_settlements_source_fk')
                ->references('id')->on('payment_sources')->restrictOnDelete();
            $table->foreign('gateway_import_id', 'ar_settlements_gateway_import_fk')
                ->references('id')->on('gateway_settlement_imports')->restrictOnDelete();
            $table->foreign('evidence_bank_transaction_id', 'ar_settlements_bank_evidence_fk')
                ->references('id')->on('bank_transactions')->restrictOnDelete();
            $table->foreign('commission_expense_account_id', 'ar_settlements_commission_expense_fk')
                ->references('id')->on('ledger_accounts')->restrictOnDelete();
            $table->foreign('settlement_fee_expense_account_id', 'ar_settlements_fee_expense_fk')
                ->references('id')->on('ledger_accounts')->restrictOnDelete();
            $table->foreign('bank_ledger_account_id', 'ar_settlements_bank_ledger_fk')
                ->references('id')->on('ledger_accounts')->restrictOnDelete();
        });

        Schema::table('ar_clearing_settlement_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('provider_transaction_id')->nullable()->after('payment_id');
            $table->unsignedBigInteger('gateway_settlement_row_id')->nullable()->after('provider_transaction_id');
            $table->unique('provider_transaction_id', 'ar_settlement_items_provider_tx_unique');
            $table->unique('gateway_settlement_row_id', 'ar_settlement_items_gateway_row_unique');

            $table->foreign('provider_transaction_id', 'ar_settlement_items_provider_tx_fk')
                ->references('id')->on('payment_provider_transactions')->restrictOnDelete();
            $table->foreign('gateway_settlement_row_id', 'ar_settlement_items_gateway_row_fk')
                ->references('id')->on('gateway_settlement_rows')->restrictOnDelete();
        });

        Schema::create('ar_clearing_settlement_adjustments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('settlement_id');
            $table->unsignedBigInteger('payment_source_id');
            $table->unsignedBigInteger('expense_account_id');
            $table->unsignedBigInteger('gateway_settlement_row_id')->nullable();
            $table->string('adjustment_type', 30);
            $table->char('economic_identity', 64);
            $table->bigInteger('amount_cents');
            $table->longText('account_snapshot');
            $table->longText('evidence_snapshot');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(
                ['payment_source_id', 'adjustment_type', 'economic_identity'],
                'ar_settlement_adjustments_economic_unique'
            );
            $table->index('settlement_id', 'ar_settlement_adjustments_settlement_idx');

            $table->foreign('settlement_id', 'ar_settlement_adjustments_settlement_fk')
                ->references('id')->on('ar_clearing_settlements')->restrictOnDelete();
            $table->foreign('payment_source_id', 'ar_settlement_adjustments_source_fk')
                ->references('id')->on('payment_sources')->restrictOnDelete();
            $table->foreign('expense_account_id', 'ar_settlement_adjustments_expense_fk')
                ->references('id')->on('ledger_accounts')->restrictOnDelete();
            $table->foreign('gateway_settlement_row_id', 'ar_settlement_adjustments_gateway_row_fk')
                ->references('id')->on('gateway_settlement_rows')->restrictOnDelete();
        });

        Schema::table('payment_provider_transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_clearing_settlement_id')->nullable()->after('payment_id');
            $table->index('active_clearing_settlement_id', 'provider_transactions_active_settlement_idx');
            $table->foreign('active_clearing_settlement_id', 'provider_transactions_active_settlement_fk')
                ->references('id')->on('ar_clearing_settlements')->restrictOnDelete();
        });

        $this->addCheckConstraints();
    }

    /**
     * Financial and retained evidence columns are forward only. Disable the feature instead of
     * rolling back schema that may explain an already posted settlement.
     */
    public function down(): void
    {
        // No-op by design.
    }

    private function addCheckConstraints(): void
    {
        DB::statement(
            'ALTER TABLE gateway_settlement_imports ADD CONSTRAINT gateway_import_amounts_chk '
            .'CHECK (gross_cents >= 0 AND commission_cents >= 0 AND settlement_fee_cents >= 0)'
        );
        DB::statement(
            'ALTER TABLE gateway_settlement_rows ADD CONSTRAINT gateway_row_commissions_chk '
            .'CHECK ((variable_commission_cents IS NULL OR variable_commission_cents >= 0) '
            .'AND (fixed_commission_cents IS NULL OR fixed_commission_cents >= 0) '
            .'AND (total_commission_cents IS NULL OR total_commission_cents >= 0) '
            .'AND (settlement_fee_cents IS NULL OR settlement_fee_cents >= 0))'
        );
        DB::statement(
            'ALTER TABLE ar_clearing_settlements ADD CONSTRAINT ar_settlement_gateway_amounts_chk '
            .'CHECK (commission_cents >= 0 AND settlement_fee_cents >= 0 '
            .'AND (net_cents IS NULL OR net_cents >= 0))'
        );
        DB::statement(
            'ALTER TABLE ar_clearing_settlement_adjustments ADD CONSTRAINT ar_settlement_adjustment_amount_chk '
            .'CHECK (amount_cents > 0)'
        );
        DB::statement(
            'ALTER TABLE ar_clearing_settlement_adjustments ADD CONSTRAINT ar_settlement_adjustment_type_chk '
            ."CHECK (adjustment_type IN ('commission', 'settlement_fee'))"
        );
    }
};
