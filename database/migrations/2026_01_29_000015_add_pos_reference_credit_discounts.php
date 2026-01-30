<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'pos_reference')) {
                $table->string('pos_reference', 32)->nullable()->after('reference');
            }
            if (! Schema::hasColumn('sales', 'global_discount_type')) {
                $table->string('global_discount_type', 10)->default('fixed')->after('global_discount_cents');
            }
            if (! Schema::hasColumn('sales', 'global_discount_value')) {
                $table->bigInteger('global_discount_value')->default(0)->after('global_discount_type');
            }
            if (! Schema::hasColumn('sales', 'is_credit')) {
                $table->boolean('is_credit')->default(false)->after('global_discount_value');
            }
            if (! Schema::hasColumn('sales', 'credit_invoice_id')) {
                $table->unsignedBigInteger('credit_invoice_id')->nullable()->after('is_credit');
            }
            if (! Schema::hasColumn('sales', 'pos_date')) {
                $table->date('pos_date')->nullable()->after('is_credit');
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'discount_type')) {
                $table->string('discount_type', 10)->default('fixed')->after('discount_cents');
            }
            if (! Schema::hasColumn('sale_items', 'discount_value')) {
                $table->bigInteger('discount_value')->default(0)->after('discount_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'pos_reference',
                'global_discount_type',
                'global_discount_value',
                'is_credit',
                'credit_invoice_id',
                'pos_date',
            ]);
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['discount_type', 'discount_value']);
        });
    }
};
