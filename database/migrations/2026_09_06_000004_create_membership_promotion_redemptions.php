<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plan_requests', function (Blueprint $table): void {
            $table->char('client_uuid', 36)->nullable()->after('submission_kind');
            $table->unsignedBigInteger('promotion_id')->nullable()->after('client_uuid');
            $table->longText('proposed_selections_snapshot')->nullable()->after('submission_snapshot');
            $table->longText('promotion_terms_snapshot')->nullable()->after('proposed_selections_snapshot');
            $table->longText('notification_snapshots')->nullable()->after('promotion_terms_snapshot');
            $table->json('notification_dispatch')->nullable()->after('notification_snapshots');

            $table->unique(['user_id', 'client_uuid'], 'meal_plan_requests_user_client_unique');
            $table->index('promotion_id', 'meal_plan_requests_promotion_idx');
            $table->foreign('promotion_id', 'meal_plan_requests_promotion_fk')
                ->references('id')->on('membership_promotions')->restrictOnDelete();
        });

        Schema::create('membership_promotion_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('company_id');
            $table->integer('branch_id');
            $table->integer('original_customer_id');
            $table->integer('original_user_id');
            $table->unsignedBigInteger('checkout_id');
            $table->string('status', 20)->default('held');
            $table->longText('offer_snapshot');
            $table->longText('eligibility_snapshot');
            $table->bigInteger('gross_cents');
            $table->bigInteger('discount_cents');
            $table->bigInteger('net_cents');
            $table->dateTime('starts_at', 6);
            $table->dateTime('expires_at', 6);
            $table->dateTime('redeemed_at', 6)->nullable();
            $table->dateTime('released_at', 6)->nullable();
            $table->string('release_reason', 80)->nullable();
            $table->timestamps(6);

            $table->unique('checkout_id', 'membership_promo_reservations_checkout_unique');
            $table->index(
                ['promotion_id', 'status', 'created_at'],
                'membership_promo_reservations_status_idx'
            );
            $table->index(
                ['original_customer_id', 'promotion_id'],
                'membership_promo_reservations_customer_idx'
            );

            $table->foreign('promotion_id', 'membership_promo_reservations_promotion_fk')
                ->references('id')->on('membership_promotions')->restrictOnDelete();
            $table->foreign('company_id', 'membership_promo_reservations_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('branch_id', 'membership_promo_reservations_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('original_customer_id', 'membership_promo_reservations_customer_fk')
                ->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('original_user_id', 'membership_promo_reservations_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('checkout_id', 'membership_promo_reservations_checkout_fk')
                ->references('id')->on('payment_checkout_attempts')->restrictOnDelete();
        });

        Schema::create('membership_promotion_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('company_id');
            $table->integer('branch_id');
            $table->integer('original_customer_id');
            $table->integer('original_user_id');
            $table->string('kind', 20);
            $table->unsignedBigInteger('checkout_id')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('meal_plan_request_id');
            $table->unsignedBigInteger('purchase_block_id')->nullable();
            $table->longText('offer_snapshot');
            $table->longText('eligibility_snapshot');
            $table->bigInteger('gross_cents');
            $table->bigInteger('discount_cents');
            $table->bigInteger('net_cents');
            $table->dateTime('redeemed_at', 6);
            $table->char('zero_subject_key', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamps(6);

            $table->unique('checkout_id', 'membership_promo_redemptions_checkout_unique');
            $table->unique('reservation_id', 'membership_promo_redemptions_reservation_unique');
            $table->unique('meal_plan_request_id', 'membership_promo_redemptions_request_unique');
            $table->unique('purchase_block_id', 'membership_promo_redemptions_block_unique');
            $table->unique('zero_subject_key', 'membership_promo_redemptions_zero_subject_unique');
            $table->index(
                ['promotion_id', 'kind', 'redeemed_at'],
                'membership_promo_redemptions_usage_idx'
            );
            $table->index(
                ['original_customer_id', 'promotion_id'],
                'membership_promo_redemptions_customer_idx'
            );

            $table->foreign('promotion_id', 'membership_promo_redemptions_promotion_fk')
                ->references('id')->on('membership_promotions')->restrictOnDelete();
            $table->foreign('company_id', 'membership_promo_redemptions_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('branch_id', 'membership_promo_redemptions_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('original_customer_id', 'membership_promo_redemptions_customer_fk')
                ->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('original_user_id', 'membership_promo_redemptions_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('checkout_id', 'membership_promo_redemptions_checkout_fk')
                ->references('id')->on('payment_checkout_attempts')->restrictOnDelete();
            $table->foreign('reservation_id', 'membership_promo_redemptions_reservation_fk')
                ->references('id')->on('membership_promotion_reservations')->restrictOnDelete();
            $table->foreign('meal_plan_request_id', 'membership_promo_redemptions_request_fk')
                ->references('id')->on('meal_plan_requests')->restrictOnDelete();
            $table->foreign('purchase_block_id', 'membership_promo_redemptions_block_fk')
                ->references('id')->on('membership_purchase_blocks')->restrictOnDelete();
        });

        Schema::table('meal_plan_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('redemption_id')->nullable()->after('promotion_id');
            $table->unique('redemption_id', 'meal_plan_requests_redemption_unique');
            $table->foreign('redemption_id', 'meal_plan_requests_redemption_fk')
                ->references('id')->on('membership_promotion_redemptions')->restrictOnDelete();
        });

        $this->addChecks();
    }

    public function down(): void
    {
        Schema::table('meal_plan_requests', function (Blueprint $table): void {
            $table->dropForeign('meal_plan_requests_redemption_fk');
            $table->dropUnique('meal_plan_requests_redemption_unique');
            $table->dropColumn('redemption_id');
        });

        Schema::dropIfExists('membership_promotion_redemptions');
        Schema::dropIfExists('membership_promotion_reservations');

        Schema::table('meal_plan_requests', function (Blueprint $table): void {
            $table->dropForeign('meal_plan_requests_promotion_fk');
            $table->dropUnique('meal_plan_requests_user_client_unique');
            $table->dropIndex('meal_plan_requests_promotion_idx');
            $table->dropColumn([
                'client_uuid',
                'promotion_id',
                'proposed_selections_snapshot',
                'promotion_terms_snapshot',
                'notification_snapshots',
                'notification_dispatch',
            ]);
        });
    }

    private function addChecks(): void
    {
        DB::statement(
            'ALTER TABLE membership_promotion_reservations ADD CONSTRAINT membership_promo_reservations_money_chk '
            .'CHECK (gross_cents > 0 AND discount_cents > 0 AND net_cents > 0 '
            .'AND gross_cents = discount_cents + net_cents)'
        );
        DB::statement(
            'ALTER TABLE membership_promotion_reservations ADD CONSTRAINT membership_promo_reservations_dates_chk '
            .'CHECK (expires_at > starts_at)'
        );
        DB::statement(
            'ALTER TABLE membership_promotion_reservations ADD CONSTRAINT membership_promo_reservations_state_chk '
            ."CHECK ((status = 'held' AND redeemed_at IS NULL AND released_at IS NULL AND release_reason IS NULL) "
            ."OR (status = 'redeemed' AND redeemed_at IS NOT NULL AND released_at IS NULL AND release_reason IS NULL) "
            ."OR (status = 'released' AND redeemed_at IS NULL AND released_at IS NOT NULL AND release_reason IS NOT NULL))"
        );
        DB::statement(
            'ALTER TABLE membership_promotion_redemptions ADD CONSTRAINT membership_promo_redemptions_money_chk '
            .'CHECK (gross_cents > 0 AND discount_cents > 0 AND net_cents >= 0 '
            .'AND gross_cents = discount_cents + net_cents)'
        );
        DB::statement(
            'ALTER TABLE membership_promotion_redemptions ADD CONSTRAINT membership_promo_redemptions_kind_chk '
            ."CHECK ((kind = 'paid_purchase' AND checkout_id IS NOT NULL AND reservation_id IS NOT NULL "
            .'AND purchase_block_id IS NOT NULL AND zero_subject_key IS NULL AND net_cents > 0) '
            ."OR (kind = 'zero_request' AND checkout_id IS NULL AND reservation_id IS NULL "
            .'AND purchase_block_id IS NULL AND zero_subject_key IS NOT NULL AND net_cents = 0))'
        );
    }
};
