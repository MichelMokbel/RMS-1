<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_sources', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('code', 50);
            $table->string('name', 120);
            $table->string('method', 20);
            $table->unsignedBigInteger('clearing_account_id');
            $table->boolean('is_active')->default(false);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $this->timestamps($table);

            $table->unique(['company_id', 'code'], 'payment_sources_company_code_unique');
            $table->index(['company_id', 'method', 'is_active'], 'payment_sources_company_method_active_idx');

            $table->foreign('company_id', 'payment_sources_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('clearing_account_id', 'payment_sources_clearing_fk')
                ->references('id')->on('ledger_accounts')->restrictOnDelete();
            $table->foreign('created_by', 'payment_sources_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'payment_sources_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('payment_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id')->unique('payment_settings_company_unique');
            $table->unsignedTinyInteger('checkout_duration_minutes')->default(15);
            $table->time('booking_cutoff_time')->default('23:00:00');
            $table->string('timezone', 64)->default('Asia/Qatar');
            $table->string('order_support_phone', 50)->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $this->timestamps($table);

            $table->foreign('company_id', 'payment_settings_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('created_by', 'payment_settings_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'payment_settings_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('payment_checkout_attempts', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('reference', 36)->unique('payment_attempts_reference_unique');
            $table->unsignedBigInteger('company_id');
            $table->integer('branch_id');
            $table->integer('customer_id');
            $table->integer('portal_user_id');
            $table->unsignedBigInteger('payment_source_id');
            $table->char('client_uuid', 36);
            $table->string('purpose', 40);
            $table->char('currency', 3)->default('QAR');
            $table->bigInteger('gross_amount_cents');
            $table->bigInteger('discount_amount_cents')->default(0);
            $table->bigInteger('payable_amount_cents');
            $table->char('cart_fingerprint', 64);
            $table->char('quote_fingerprint', 64);
            $table->char('request_fingerprint', 64);
            $table->char('recovery_fingerprint', 64);
            $table->string('state', 40)->default('initiating');
            $table->dateTime('started_at', 6);
            $table->dateTime('expires_at', 6);
            $table->dateTime('completed_at', 6)->nullable();
            $table->longText('cart_snapshot');
            $table->longText('customer_snapshot');
            $table->longText('pricing_snapshot');
            $table->longText('terms_snapshot');
            $table->longText('request_snapshot');
            $table->longText('notification_snapshots')->nullable();
            $table->longText('source_account_snapshot');
            $table->json('notification_dispatch')->nullable();
            $table->json('financial_intent')->nullable();
            $table->char('provider_request_uuid', 36);
            $table->string('provider_create_outcome', 20)->default('not_sent');
            $table->dateTime('provider_dispatched_at', 6)->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->dateTime('next_recovery_at', 6)->nullable();
            $this->timestamps($table);

            $table->unique(['portal_user_id', 'client_uuid'], 'payment_attempts_user_client_unique');
            $table->index(['customer_id', 'created_at', 'id'], 'payment_attempts_customer_created_idx');
            $table->index(
                ['customer_id', 'recovery_fingerprint', 'state'],
                'payment_attempts_customer_recovery_state_idx'
            );
            $table->index(['state', 'next_recovery_at', 'id'], 'payment_attempts_recovery_due_idx');

            $table->foreign('company_id', 'payment_attempts_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('branch_id', 'payment_attempts_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('customer_id', 'payment_attempts_customer_fk')
                ->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('portal_user_id', 'payment_attempts_portal_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('payment_source_id', 'payment_attempts_source_fk')
                ->references('id')->on('payment_sources')->restrictOnDelete();
        });

        Schema::create('payment_checkout_targets', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedSmallInteger('sequence');
            $table->string('target_type', 40)->default('order');
            $table->date('service_date');
            $table->bigInteger('expected_amount_cents');
            $table->longText('item_snapshot');
            $table->string('hold_state', 20)->default('held');
            $table->dateTime('held_at', 6);
            $table->dateTime('activated_at', 6)->nullable();
            $table->dateTime('released_at', 6)->nullable();
            $table->date('intended_invoice_issue_date')->nullable();
            $table->integer('order_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $this->timestamps($table);

            $table->unique(['attempt_id', 'sequence'], 'payment_targets_attempt_sequence_unique');
            $table->unique('order_id', 'payment_targets_order_unique');
            $table->unique('invoice_id', 'payment_targets_invoice_unique');
            $table->index(['service_date', 'hold_state'], 'payment_targets_service_hold_idx');

            $table->foreign('attempt_id', 'payment_targets_attempt_fk')
                ->references('id')->on('payment_checkout_attempts')->restrictOnDelete();
            $table->foreign('order_id', 'payment_targets_order_fk')
                ->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('invoice_id', 'payment_targets_invoice_fk')
                ->references('id')->on('ar_invoices')->restrictOnDelete();
        });

        Schema::create('payment_provider_transactions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('attempt_id');
            $table->unsignedBigInteger('payment_source_id');
            $table->string('provider_payment_id', 120);
            $table->string('merchant_transaction_id', 120);
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('raw_status', 80)->nullable();
            $table->string('normalized_status', 30)->default('pending');
            $table->dateTime('finished_at', 6)->nullable();
            $table->string('finished_at_evidence_source', 40)->nullable();
            $table->dateTime('verified_paid_at', 6)->nullable();
            $table->bigInteger('verified_amount_cents')->nullable();
            $table->char('verified_currency', 3)->nullable();
            $table->dateTime('verified_finished_at', 6)->nullable();
            $table->dateTime('details_checked_at', 6)->nullable();
            $table->longText('pay_url')->nullable();
            $table->dateTime('provider_expires_at', 6)->nullable();
            $table->string('visa_id', 120)->nullable();
            $table->string('card_type', 50)->nullable();
            $table->string('classification', 30)->default('pending');
            $table->date('receipt_date')->nullable();
            $table->char('receipt_client_uuid', 36)->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $this->timestamps($table);

            $table->unique(
                ['payment_source_id', 'provider_payment_id'],
                'provider_transactions_source_payment_unique'
            );
            $table->unique('payment_id', 'provider_transactions_payment_unique');
            $table->unique('receipt_client_uuid', 'provider_transactions_receipt_uuid_unique');
            $table->index(
                ['payment_source_id', 'merchant_transaction_id'],
                'provider_transactions_source_merchant_idx'
            );
            $table->index(['attempt_id', 'classification'], 'provider_transactions_attempt_class_idx');

            $table->foreign('attempt_id', 'provider_transactions_attempt_fk')
                ->references('id')->on('payment_checkout_attempts')->restrictOnDelete();
            $table->foreign('payment_source_id', 'provider_transactions_source_fk')
                ->references('id')->on('payment_sources')->restrictOnDelete();
            $table->foreign('payment_id', 'provider_transactions_payment_fk')
                ->references('id')->on('payments')->restrictOnDelete();
        });

        Schema::create('payment_provider_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('payment_source_id');
            $table->unsignedBigInteger('provider_transaction_id')->nullable();
            $table->string('provider_payment_id', 120);
            $table->char('payload_hash', 64);
            $table->string('merchant_transaction_id', 120)->nullable();
            $table->bigInteger('amount_cents')->nullable();
            $table->string('raw_status', 80)->nullable();
            $table->string('normalized_status', 30)->default('unknown');
            $table->longText('normalized_snapshot');
            $table->string('signature_key_reference', 80);
            $table->string('processing_state', 30)->default('pending');
            $table->string('error_code', 80)->nullable();
            $table->dateTime('received_at', 6);
            $table->dateTime('processing_started_at', 6)->nullable();
            $table->dateTime('processed_at', 6)->nullable();
            $table->dateTime('next_retry_at', 6)->nullable();
            $table->longText('raw_body')->nullable();
            $table->dateTime('raw_body_removed_at', 6)->nullable();
            $this->timestamps($table);

            $table->unique(
                ['payment_source_id', 'payload_hash'],
                'provider_events_source_payload_unique'
            );
            $table->index(
                ['processing_state', 'next_retry_at', 'id'],
                'provider_events_processing_due_idx'
            );
            $table->index(
                ['payment_source_id', 'provider_payment_id'],
                'provider_events_source_payment_idx'
            );

            $table->foreign('payment_source_id', 'provider_events_source_fk')
                ->references('id')->on('payment_sources')->restrictOnDelete();
            $table->foreign('provider_transaction_id', 'provider_events_transaction_fk')
                ->references('id')->on('payment_provider_transactions')->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_source_id')->nullable()->after('bank_account_id');
            $table->index('payment_source_id', 'payments_payment_source_idx');
            $table->foreign('payment_source_id', 'payments_payment_source_fk')
                ->references('id')->on('payment_sources')->restrictOnDelete();
        });

        $this->addCheckConstraints();
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign('payments_payment_source_fk');
            $table->dropIndex('payments_payment_source_idx');
            $table->dropColumn('payment_source_id');
        });

        Schema::dropIfExists('payment_provider_events');
        Schema::dropIfExists('payment_provider_transactions');
        Schema::dropIfExists('payment_checkout_targets');
        Schema::dropIfExists('payment_checkout_attempts');
        Schema::dropIfExists('payment_settings');
        Schema::dropIfExists('payment_sources');
    }

    private function timestamps(Blueprint $table): void
    {
        $table->dateTime('created_at', 6)->nullable();
        $table->dateTime('updated_at', 6)->nullable();
    }

    private function addCheckConstraints(): void
    {
        DB::statement(
            'ALTER TABLE payment_settings ADD CONSTRAINT payment_settings_duration_chk '
            .'CHECK (checkout_duration_minutes BETWEEN 5 AND 60)'
        );
        DB::statement(
            'ALTER TABLE payment_settings ADD CONSTRAINT payment_settings_timezone_chk '
            ."CHECK (timezone = 'Asia/Qatar')"
        );
        DB::statement(
            'ALTER TABLE payment_checkout_attempts ADD CONSTRAINT payment_attempts_amounts_chk '
            .'CHECK (gross_amount_cents >= 0 AND discount_amount_cents >= 0 '
            .'AND payable_amount_cents >= 0 '
            .'AND payable_amount_cents = gross_amount_cents - discount_amount_cents)'
        );
        DB::statement(
            'ALTER TABLE payment_checkout_attempts ADD CONSTRAINT payment_attempts_currency_chk '
            ."CHECK (currency = 'QAR')"
        );
        DB::statement(
            'ALTER TABLE payment_checkout_attempts ADD CONSTRAINT payment_attempts_state_chk '
            ."CHECK (state IN ('initiating', 'pending', 'paid_processing', 'completed', "
            ."'expired', 'declined', 'payment_received_as_credit', 'needs_review'))"
        );
        DB::statement(
            'ALTER TABLE payment_checkout_attempts ADD CONSTRAINT payment_attempts_create_outcome_chk '
            ."CHECK (provider_create_outcome IN ('not_sent', 'in_flight', 'created', 'rejected', 'unknown'))"
        );
        DB::statement(
            'ALTER TABLE payment_checkout_targets ADD CONSTRAINT payment_targets_amount_chk '
            .'CHECK (expected_amount_cents >= 0)'
        );
        DB::statement(
            'ALTER TABLE payment_checkout_targets ADD CONSTRAINT payment_targets_type_chk '
            ."CHECK (target_type IN ('order', 'meal_plan_request'))"
        );
        DB::statement(
            'ALTER TABLE payment_checkout_targets ADD CONSTRAINT payment_targets_hold_state_chk '
            ."CHECK (hold_state IN ('held', 'activated', 'released'))"
        );
        DB::statement(
            'ALTER TABLE payment_provider_transactions ADD CONSTRAINT provider_transactions_amount_chk '
            .'CHECK (amount_cents >= 0 AND (verified_amount_cents IS NULL OR verified_amount_cents >= 0))'
        );
        DB::statement(
            'ALTER TABLE payment_provider_transactions ADD CONSTRAINT provider_transactions_status_chk '
            ."CHECK (normalized_status IN ('pending', 'paid', 'unpaid_terminal', 'reversal_exception', 'unknown'))"
        );
        DB::statement(
            'ALTER TABLE payment_provider_transactions ADD CONSTRAINT provider_transactions_class_chk '
            ."CHECK (classification IN ('pending', 'purchase', 'retained_credit', 'needs_review'))"
        );
        DB::statement(
            'ALTER TABLE payment_provider_events ADD CONSTRAINT provider_events_amount_chk '
            .'CHECK (amount_cents IS NULL OR amount_cents >= 0)'
        );
        DB::statement(
            'ALTER TABLE payment_provider_events ADD CONSTRAINT provider_events_status_chk '
            ."CHECK (normalized_status IN ('pending', 'paid', 'unpaid_terminal', 'reversal_exception', 'unknown'))"
        );
        DB::statement(
            'ALTER TABLE payment_provider_events ADD CONSTRAINT provider_events_processing_chk '
            ."CHECK (processing_state IN ('pending', 'processing', 'processed', 'retryable', 'quarantined'))"
        );
    }
};
