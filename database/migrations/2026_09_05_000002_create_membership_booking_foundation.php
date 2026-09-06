<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_subscription_orders', function (Blueprint $table): void {
            $table->uuid('booking_uuid')->nullable()->after('branch_id');
            $table->unsignedInteger('booking_revision')->default(0)->after('booking_uuid');
            $table->unsignedBigInteger('supersedes_subscription_order_id')->nullable()->after('booking_revision');
            $table->uuid('accepted_operation_uuid')->nullable()->after('supersedes_subscription_order_id');
            $table->longText('notification_snapshots')->nullable()->after('accepted_operation_uuid');
            $table->longText('notification_dispatch')->nullable()->after('notification_snapshots');

            $table->unique(['booking_uuid', 'booking_revision'], 'meal_sub_orders_booking_revision_unique');
            $table->index('accepted_operation_uuid', 'meal_sub_orders_operation_idx');
            $table->foreign('supersedes_subscription_order_id', 'meal_sub_orders_supersedes_fk')
                ->references('id')->on('meal_subscription_orders')->restrictOnDelete();
        });

        Schema::create('membership_booking_operations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('client_uuid');
            $table->integer('portal_user_id');
            $table->integer('customer_id');
            $table->unsignedBigInteger('company_id');
            $table->integer('branch_id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedInteger('input_queue_revision');
            $table->char('request_fingerprint', 64);
            $table->string('state', 20)->default('completed');
            $table->longText('request_snapshot');
            $table->longText('terms_snapshot');
            $table->longText('result_snapshot');
            $table->dateTime('completed_at', 6);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['portal_user_id', 'client_uuid'], 'membership_booking_operations_user_uuid_unique');
            $table->index(['customer_id', 'company_id', 'branch_id'], 'membership_booking_operations_scope_idx');
            $table->foreign('portal_user_id', 'membership_booking_operations_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('customer_id', 'membership_booking_operations_customer_fk')
                ->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('company_id', 'membership_booking_operations_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('branch_id', 'membership_booking_operations_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('subscription_id', 'membership_booking_operations_subscription_fk')
                ->references('id')->on('meal_subscriptions')->restrictOnDelete();
        });

        Schema::create('membership_booking_funding', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_block_id');
            $table->unsignedBigInteger('subscription_order_id');
            $table->unsignedSmallInteger('main_quantity');
            $table->json('position_ranges');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->date('intended_invoice_issue_date');
            $table->bigInteger('invoice_gross_cents');
            $table->bigInteger('invoice_discount_cents')->default(0);
            $table->bigInteger('invoice_net_cents');
            $table->unsignedBigInteger('payment_allocation_id')->nullable();
            $table->string('state', 20)->default('reserved');
            $table->dateTime('reserved_at', 6);
            $table->dateTime('invoiced_at', 6)->nullable();
            $table->dateTime('released_at', 6)->nullable();
            $table->time('booking_cutoff_time');
            $table->string('booking_timezone', 40)->default('Asia/Qatar');
            $table->dateTime('change_deadline_at', 6);
            $table->integer('created_by')->nullable();
            $table->integer('released_by')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['purchase_block_id', 'subscription_order_id'], 'membership_booking_funding_block_order_unique');
            $table->unique('payment_allocation_id', 'membership_booking_funding_allocation_unique');
            $table->index(['invoice_id', 'state'], 'membership_booking_funding_invoice_state_idx');
            $table->index(['purchase_block_id', 'state'], 'membership_booking_funding_block_state_idx');

            $table->foreign('purchase_block_id', 'membership_booking_funding_block_fk')
                ->references('id')->on('membership_purchase_blocks')->restrictOnDelete();
            $table->foreign('subscription_order_id', 'membership_booking_funding_order_fk')
                ->references('id')->on('meal_subscription_orders')->restrictOnDelete();
            $table->foreign('invoice_id', 'membership_booking_funding_invoice_fk')
                ->references('id')->on('ar_invoices')->restrictOnDelete();
            $table->foreign('payment_allocation_id', 'membership_booking_funding_allocation_fk')
                ->references('id')->on('payment_allocations')->restrictOnDelete();
            $table->foreign('created_by', 'membership_booking_funding_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('released_by', 'membership_booking_funding_released_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE membership_booking_operations ADD CONSTRAINT membership_booking_operations_state_chk '
            ."CHECK (state = 'completed')"
        );
        DB::statement(
            'ALTER TABLE membership_booking_funding ADD CONSTRAINT membership_booking_funding_values_chk '
            .'CHECK (main_quantity > 0 AND invoice_gross_cents > 0 AND invoice_discount_cents >= 0 '
            .'AND invoice_net_cents >= 0 AND invoice_net_cents = invoice_gross_cents - invoice_discount_cents)'
        );
        DB::statement(
            'ALTER TABLE membership_booking_funding ADD CONSTRAINT membership_booking_funding_state_chk '
            ."CHECK (state IN ('reserved', 'invoiced', 'released'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_booking_funding');
        Schema::dropIfExists('membership_booking_operations');

        Schema::table('meal_subscription_orders', function (Blueprint $table): void {
            $table->dropForeign('meal_sub_orders_supersedes_fk');
            $table->dropUnique('meal_sub_orders_booking_revision_unique');
            $table->dropIndex('meal_sub_orders_operation_idx');
            $table->dropColumn([
                'booking_uuid',
                'booking_revision',
                'supersedes_subscription_order_id',
                'accepted_operation_uuid',
                'notification_snapshots',
                'notification_dispatch',
            ]);
        });
    }
};
