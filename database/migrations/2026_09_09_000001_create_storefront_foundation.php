<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_settings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->integer('portal_branch_id');
            $table->boolean('normal_menu_enabled')->default(false);
            $table->time('menu_cutoff_time')->default('23:00:00');
            $table->string('timezone', 40)->default('Asia/Qatar');
            $table->boolean('delivery_apps_enabled')->default(false);
            $table->unsignedBigInteger('revision')->default(1);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps(6);

            $table->unique('company_id', 'storefront_settings_company_unique');
            $table->foreign('company_id', 'storefront_settings_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('portal_branch_id', 'storefront_settings_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('created_by', 'storefront_settings_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'storefront_settings_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('storefront_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('slug', 120);
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->integer('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps(6);
            $table->softDeletes('deleted_at', 6);

            $table->unique(['company_id', 'slug'], 'storefront_categories_company_slug_unique');
            $table->index(['company_id', 'is_active', 'display_order'], 'storefront_categories_public_idx');
            $table->foreign('company_id', 'storefront_categories_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('created_by', 'storefront_categories_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'storefront_categories_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('storefront_item_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->integer('branch_id');
            $table->integer('menu_item_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('customer_title', 120)->nullable();
            $table->string('short_description', 280)->nullable();
            $table->string('image_disk', 40)->nullable();
            $table->string('image_path', 500)->nullable();
            $table->boolean('direct_order_enabled')->default(false);
            $table->unsignedSmallInteger('advance_days')->default(1);
            $table->decimal('minimum_quantity', 12, 3)->default(1);
            $table->decimal('quantity_increment', 12, 3)->default(1);
            $table->decimal('maximum_quantity', 12, 3)->nullable();
            $table->boolean('is_chef_pick')->default(false);
            $table->integer('display_order')->default(0);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps(6);

            $table->unique(
                ['company_id', 'branch_id', 'menu_item_id'],
                'storefront_profiles_scope_item_unique'
            );
            $table->index(
                ['company_id', 'branch_id', 'direct_order_enabled', 'display_order'],
                'storefront_profiles_public_idx'
            );
            $table->foreign('company_id', 'storefront_profiles_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('branch_id', 'storefront_profiles_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('menu_item_id', 'storefront_profiles_menu_item_fk')
                ->references('id')->on('menu_items')->restrictOnDelete();
            $table->foreign('category_id', 'storefront_profiles_category_fk')
                ->references('id')->on('storefront_categories')->restrictOnDelete();
            $table->foreign('created_by', 'storefront_profiles_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'storefront_profiles_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('storefront_closed_dates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->integer('branch_id');
            $table->date('service_date');
            $table->string('reason', 255)->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps(6);

            $table->unique(
                ['company_id', 'branch_id', 'service_date'],
                'storefront_closed_dates_scope_date_unique'
            );
            $table->foreign('company_id', 'storefront_closed_dates_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('branch_id', 'storefront_closed_dates_branch_fk')
                ->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('created_by', 'storefront_closed_dates_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'storefront_closed_dates_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('storefront_delivery_channels', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('code', 20);
            $table->string('label', 80);
            $table->boolean('is_enabled')->default(false);
            $table->string('restaurant_url', 2048)->nullable();
            $table->integer('display_order')->default(0);
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps(6);

            $table->unique(['company_id', 'code'], 'storefront_channels_company_code_unique');
            $table->foreign('company_id', 'storefront_channels_company_fk')
                ->references('id')->on('accounting_companies')->restrictOnDelete();
            $table->foreign('created_by', 'storefront_channels_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'storefront_channels_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('storefront_item_channels', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('channel_id');
            $table->boolean('is_enabled')->default(false);
            $table->string('item_url', 2048)->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps(6);

            $table->unique(['profile_id', 'channel_id'], 'storefront_item_channels_pair_unique');
            $table->foreign('profile_id', 'storefront_item_channels_profile_fk')
                ->references('id')->on('storefront_item_profiles')->cascadeOnDelete();
            $table->foreign('channel_id', 'storefront_item_channels_channel_fk')
                ->references('id')->on('storefront_delivery_channels')->cascadeOnDelete();
            $table->foreign('created_by', 'storefront_item_channels_created_by_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by', 'storefront_item_channels_updated_by_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('payment_checkout_target_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('target_id');
            $table->unsignedSmallInteger('sequence');
            $table->integer('menu_item_id');
            $table->string('title', 120);
            $table->string('description', 280)->nullable();
            $table->string('unit', 20);
            $table->decimal('quantity', 12, 3);
            $table->bigInteger('unit_price_cents');
            $table->bigInteger('line_total_cents');
            $table->timestamps(6);

            $table->unique(['target_id', 'sequence'], 'payment_target_items_target_sequence_unique');
            $table->index(['menu_item_id', 'target_id'], 'payment_target_items_menu_target_idx');
            $table->foreign('target_id', 'payment_target_items_target_fk')
                ->references('id')->on('payment_checkout_targets')->restrictOnDelete();
            $table->foreign('menu_item_id', 'payment_target_items_menu_item_fk')
                ->references('id')->on('menu_items')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE storefront_settings ADD CONSTRAINT storefront_settings_timezone_chk '
            ."CHECK (timezone = 'Asia/Qatar')"
        );
        DB::statement(
            'ALTER TABLE storefront_item_profiles ADD CONSTRAINT storefront_profiles_quantity_chk '
            .'CHECK (advance_days >= 1 AND minimum_quantity > 0 AND quantity_increment > 0 '
            .'AND (maximum_quantity IS NULL OR maximum_quantity >= minimum_quantity))'
        );
        DB::statement(
            'ALTER TABLE storefront_delivery_channels ADD CONSTRAINT storefront_channels_code_chk '
            ."CHECK (code IN ('talabat', 'snoonu', 'rafeeq', 'keeta'))"
        );
        DB::statement(
            'ALTER TABLE payment_checkout_target_items ADD CONSTRAINT payment_target_items_values_chk '
            .'CHECK (sequence >= 1 AND quantity > 0 AND unit_price_cents > 0 AND line_total_cents > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_checkout_target_items');
        Schema::dropIfExists('storefront_item_channels');
        Schema::dropIfExists('storefront_delivery_channels');
        Schema::dropIfExists('storefront_closed_dates');
        Schema::dropIfExists('storefront_item_profiles');
        Schema::dropIfExists('storefront_categories');
        Schema::dropIfExists('storefront_settings');
    }
};
