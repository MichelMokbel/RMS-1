<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_promotions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('code', 16)->charset('ascii')->collation('ascii_bin');
            $table->string('discount_type', 20);
            $table->bigInteger('fixed_amount_cents')->nullable();
            $table->unsignedSmallInteger('percentage_basis_points')->nullable();
            $table->string('purchase_eligibility', 20);
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->unsignedInteger('total_limit');
            $table->unsignedInteger('per_customer_limit')->default(1);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('first_activated_at', 6)->nullable();
            $table->dateTime('expired_at', 6)->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();

            $table->unique(['company_id', 'code'], 'membership_promotions_company_code_unique');
            $table->index(
                ['company_id', 'status', 'starts_at', 'ends_at'],
                'membership_promotions_admin_idx'
            );
            $table->foreign('company_id', 'membership_promotions_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('created_by', 'membership_promotions_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'membership_promotions_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('membership_promotion_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('promotion_id');
            $table->unsignedBigInteger('membership_plan_id');

            $table->primary(['promotion_id', 'membership_plan_id'], 'membership_promotion_plans_primary');
            $table->foreign('promotion_id', 'membership_promotion_plans_promotion_fk')
                ->references('id')->on('membership_promotions')->cascadeOnDelete();
            $table->foreign('membership_plan_id', 'membership_promotion_plans_plan_fk')
                ->references('id')->on('membership_plans')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE membership_promotions ADD CONSTRAINT membership_promotions_discount_chk '
            ."CHECK ((discount_type = 'fixed' AND fixed_amount_cents IS NOT NULL AND fixed_amount_cents > 0 AND percentage_basis_points IS NULL) "
            ."OR (discount_type = 'percentage' AND fixed_amount_cents IS NULL AND percentage_basis_points BETWEEN 1 AND 10000))"
        );
        DB::statement(
            'ALTER TABLE membership_promotions ADD CONSTRAINT membership_promotions_lifecycle_chk '
            ."CHECK (purchase_eligibility IN ('first', 'renewal', 'both') "
            ."AND status IN ('draft', 'active', 'paused', 'expired') AND revision > 0)"
        );
        DB::statement(
            'ALTER TABLE membership_promotions ADD CONSTRAINT membership_promotions_limits_chk '
            .'CHECK (total_limit > 0 AND per_customer_limit > 0 AND per_customer_limit <= total_limit)'
        );
        DB::statement(
            'ALTER TABLE membership_promotions ADD CONSTRAINT membership_promotions_dates_chk '
            .'CHECK (ends_at > starts_at)'
        );
        DB::statement(
            'ALTER TABLE membership_promotions ADD CONSTRAINT membership_promotions_code_chk '
            ."CHECK (code REGEXP '^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{12}$')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_promotion_plans');
        Schema::dropIfExists('membership_promotions');
    }
};
