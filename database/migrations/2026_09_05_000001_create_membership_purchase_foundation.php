<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('code', 20);
            $table->unsignedSmallInteger('meal_count');
            $table->bigInteger('package_price_cents');
            $table->char('currency', 3)->default('QAR');
            $table->boolean('delivery_included')->default(true);
            $table->boolean('is_active')->default(true);
            $table->dateTime('effective_from', 6)->nullable();
            $table->dateTime('effective_to', 6)->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['company_id', 'code'], 'membership_plans_company_code_unique');
            $table->index(['company_id', 'is_active', 'effective_from'], 'membership_plans_active_idx');
            $table->foreign('company_id', 'membership_plans_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('created_by', 'membership_plans_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'membership_plans_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('meal_subscriptions', function (Blueprint $table): void {
            $table->string('fulfillment_mode', 30)->default('standing')->after('uses_invoice_tracking');
            $table->unsignedBigInteger('queue_company_id')->nullable()->after('fulfillment_mode');
            $table->char('queue_currency', 3)->nullable()->after('queue_company_id');
            $table->unsignedInteger('queue_revision')->default(0)->after('queue_currency');

            $table->index(
                ['customer_id', 'fulfillment_mode', 'queue_company_id', 'branch_id', 'queue_currency'],
                'meal_subscriptions_queue_scope_idx'
            );
            $table->foreign('queue_company_id', 'meal_subscriptions_queue_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
        });

        Schema::table('meal_plan_requests', function (Blueprint $table): void {
            $table->unsignedBigInteger('checkout_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('converted_subscription_id')->nullable()->after('checkout_id');
            $table->string('submission_kind', 30)->default('legacy')->after('converted_subscription_id');
            $table->longText('submission_snapshot')->nullable()->after('submission_kind');
            $table->dateTime('converted_at', 6)->nullable()->after('submission_snapshot');

            $table->unique('checkout_id', 'meal_plan_requests_checkout_unique');
            $table->index('converted_subscription_id', 'meal_plan_requests_converted_subscription_idx');
            $table->foreign('checkout_id', 'meal_plan_requests_checkout_fk')
                ->references('id')->on('payment_checkout_attempts')->restrictOnDelete();
            $table->foreign('converted_subscription_id', 'meal_plan_requests_converted_subscription_fk')
                ->references('id')->on('meal_subscriptions')->restrictOnDelete();
        });

        Schema::table('payment_checkout_targets', function (Blueprint $table): void {
            $table->date('service_date')->nullable()->change();
            $table->unsignedBigInteger('meal_plan_request_id')->nullable()->after('order_id');
            $table->unique('meal_plan_request_id', 'payment_targets_meal_plan_request_unique');
            $table->foreign('meal_plan_request_id', 'payment_targets_meal_plan_request_fk')
                ->references('id')->on('meal_plan_requests')->restrictOnDelete();
        });

        Schema::create('membership_purchase_blocks', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('subscription_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('meal_plan_request_id')->nullable();
            $table->unsignedBigInteger('company_id');
            $table->integer('branch_id');
            $table->integer('original_customer_id');
            $table->unsignedInteger('queue_position');
            $table->unsignedSmallInteger('meal_count');
            $table->bigInteger('gross_price_cents');
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('final_price_cents');
            $table->char('currency', 3)->default('QAR');
            $table->string('origin', 20)->default('checkout');
            $table->string('origin_key', 160);
            $table->char('quote_fingerprint', 64);
            $table->longText('pricing_snapshot');
            $table->longText('terms_snapshot');
            $table->unsignedSmallInteger('opening_used_quantity')->default(0);
            $table->unsignedSmallInteger('opening_released_quantity')->default(0);
            $table->json('opening_used_ranges')->nullable();
            $table->json('opening_released_ranges')->nullable();
            $table->longText('legacy_evidence_snapshot')->nullable();
            $table->dateTime('legacy_cutover_at', 6)->nullable();
            $table->dateTime('funded_at', 6);
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('cancelled_by')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique('payment_id', 'membership_blocks_payment_unique');
            $table->unique('origin_key', 'membership_blocks_origin_key_unique');
            $table->unique(['subscription_id', 'queue_position'], 'membership_blocks_queue_position_unique');
            $table->index(
                ['company_id', 'branch_id', 'original_customer_id', 'currency', 'funded_at'],
                'membership_blocks_scope_funded_idx'
            );

            $table->foreign('subscription_id', 'membership_blocks_subscription_fk')
                ->references('id')->on('meal_subscriptions')->restrictOnDelete();
            $table->foreign('plan_id', 'membership_blocks_plan_fk')
                ->references('id')->on('membership_plans')->restrictOnDelete();
            $table->foreign('payment_id', 'membership_blocks_payment_fk')
                ->references('id')->on('payments')->restrictOnDelete();
            $table->foreign('meal_plan_request_id', 'membership_blocks_request_fk')
                ->references('id')->on('meal_plan_requests')->restrictOnDelete();
            $table->foreign('company_id', 'membership_blocks_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('branch_id', 'membership_blocks_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('original_customer_id', 'membership_blocks_original_customer_fk')
                ->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('created_by', 'membership_blocks_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by', 'membership_blocks_cancelled_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        $this->addChecks();
        $this->seedDefaultPlans();
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_purchase_blocks');

        Schema::table('payment_checkout_targets', function (Blueprint $table): void {
            $table->dropForeign('payment_targets_meal_plan_request_fk');
            $table->dropUnique('payment_targets_meal_plan_request_unique');
            $table->dropColumn('meal_plan_request_id');
        });

        Schema::table('meal_plan_requests', function (Blueprint $table): void {
            $table->dropForeign('meal_plan_requests_checkout_fk');
            $table->dropForeign('meal_plan_requests_converted_subscription_fk');
            $table->dropUnique('meal_plan_requests_checkout_unique');
            $table->dropIndex('meal_plan_requests_converted_subscription_idx');
            $table->dropColumn([
                'checkout_id',
                'converted_subscription_id',
                'submission_kind',
                'submission_snapshot',
                'converted_at',
            ]);
        });

        Schema::table('meal_subscriptions', function (Blueprint $table): void {
            $table->dropForeign('meal_subscriptions_queue_company_fk');
            $table->dropIndex('meal_subscriptions_queue_scope_idx');
            $table->dropColumn(['fulfillment_mode', 'queue_company_id', 'queue_currency', 'queue_revision']);
        });

        Schema::dropIfExists('membership_plans');
    }

    private function addChecks(): void
    {
        DB::statement(
            'ALTER TABLE membership_plans ADD CONSTRAINT membership_plans_values_chk '
            ."CHECK (meal_count > 0 AND package_price_cents > 0 AND currency = 'QAR' AND delivery_included = 1)"
        );
        DB::statement(
            'ALTER TABLE meal_subscriptions ADD CONSTRAINT meal_subscriptions_fulfillment_mode_chk '
            ."CHECK (fulfillment_mode IN ('standing', 'customer_selection'))"
        );
        DB::statement(
            'ALTER TABLE meal_subscriptions ADD CONSTRAINT meal_subscriptions_queue_scope_chk '
            ."CHECK ((fulfillment_mode = 'standing' AND queue_company_id IS NULL AND queue_currency IS NULL) "
            ."OR (fulfillment_mode = 'customer_selection' AND queue_company_id IS NOT NULL AND queue_currency = 'QAR'))"
        );
        DB::statement(
            'ALTER TABLE meal_plan_requests ADD CONSTRAINT meal_plan_requests_submission_kind_chk '
            ."CHECK (submission_kind IN ('legacy', 'paid_checkout', 'promo_request'))"
        );
        DB::statement(
            'ALTER TABLE membership_purchase_blocks ADD CONSTRAINT membership_blocks_values_chk '
            .'CHECK (meal_count > 0 AND gross_price_cents > 0 AND discount_cents >= 0 '
            .'AND final_price_cents > 0 AND final_price_cents = gross_price_cents - discount_cents '
            ."AND currency = 'QAR' AND opening_used_quantity <= meal_count "
            .'AND opening_released_quantity <= opening_used_quantity)'
        );
        DB::statement(
            'ALTER TABLE membership_purchase_blocks ADD CONSTRAINT membership_blocks_origin_chk '
            ."CHECK (origin IN ('checkout', 'legacy'))"
        );
    }

    private function seedDefaultPlans(): void
    {
        $companyIds = DB::table('accounting_companies')
            ->where('is_default', true)
            ->where('is_active', true)
            ->where('base_currency', 'QAR')
            ->pluck('id');
        $now = now('UTC');

        foreach ($companyIds as $companyId) {
            foreach ([
                ['code' => '20', 'meal_count' => 20, 'package_price_cents' => 90000],
                ['code' => '26', 'meal_count' => 26, 'package_price_cents' => 120000],
            ] as $plan) {
                DB::table('membership_plans')->insertOrIgnore([
                    'company_id' => $companyId,
                    'code' => $plan['code'],
                    'meal_count' => $plan['meal_count'],
                    'package_price_cents' => $plan['package_price_cents'],
                    'currency' => 'QAR',
                    'delivery_included' => true,
                    'is_active' => true,
                    'effective_from' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
