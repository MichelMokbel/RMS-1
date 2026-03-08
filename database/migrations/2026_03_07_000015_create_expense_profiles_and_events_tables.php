<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('expense_profiles')) {
            Schema::create('expense_profiles', function (Blueprint $table) {
                $table->integer('invoice_id')->primary();
                $table->enum('channel', ['vendor', 'petty_cash', 'reimbursement'])->default('vendor');
                $table->integer('wallet_id')->nullable();
                $table->enum('approval_status', ['draft', 'submitted', 'manager_approved', 'approved', 'rejected'])->default('draft');
                $table->boolean('requires_finance_approval')->default(false);
                $table->json('exception_flags')->nullable();
                $table->integer('submitted_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->integer('manager_approved_by')->nullable();
                $table->timestamp('manager_approved_at')->nullable();
                $table->integer('finance_approved_by')->nullable();
                $table->timestamp('finance_approved_at')->nullable();
                $table->integer('rejected_by')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->enum('settlement_mode', ['manual_ap_payment', 'petty_cash_wallet', 'reimbursement'])->nullable();
                $table->timestamps();

                $table->index(['channel', 'approval_status'], 'expense_profiles_channel_status_idx');
                $table->index('wallet_id', 'expense_profiles_wallet_id_idx');

                $table->foreign('invoice_id', 'expense_profiles_invoice_fk')
                    ->references('id')
                    ->on('ap_invoices')
                    ->onDelete('cascade');

                $table->foreign('wallet_id', 'expense_profiles_wallet_fk')
                    ->references('id')
                    ->on('petty_cash_wallets')
                    ->nullOnDelete();

                $table->foreign('submitted_by', 'expense_profiles_submitted_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->foreign('manager_approved_by', 'expense_profiles_mgr_approved_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->foreign('finance_approved_by', 'expense_profiles_fin_approved_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->foreign('rejected_by', 'expense_profiles_rejected_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasTable('expense_events')) {
            Schema::create('expense_events', function (Blueprint $table) {
                $table->id();
                $table->integer('invoice_id');
                $table->string('event', 80);
                $table->integer('actor_id')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['invoice_id', 'created_at'], 'expense_events_invoice_created_idx');
                $table->index('event', 'expense_events_event_idx');

                $table->foreign('invoice_id', 'expense_events_invoice_fk')
                    ->references('id')
                    ->on('ap_invoices')
                    ->onDelete('cascade');

                $table->foreign('actor_id', 'expense_events_actor_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('expense_events')) {
            Schema::table('expense_events', function (Blueprint $table) {
                $table->dropForeign('expense_events_invoice_fk');
                $table->dropForeign('expense_events_actor_fk');
            });
            Schema::dropIfExists('expense_events');
        }

        if (Schema::hasTable('expense_profiles')) {
            Schema::table('expense_profiles', function (Blueprint $table) {
                $table->dropForeign('expense_profiles_invoice_fk');
                $table->dropForeign('expense_profiles_wallet_fk');
                $table->dropForeign('expense_profiles_submitted_by_fk');
                $table->dropForeign('expense_profiles_mgr_approved_by_fk');
                $table->dropForeign('expense_profiles_fin_approved_by_fk');
                $table->dropForeign('expense_profiles_rejected_by_fk');
            });
            Schema::dropIfExists('expense_profiles');
        }
    }
};
