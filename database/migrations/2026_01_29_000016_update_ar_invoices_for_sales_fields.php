<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('ar_invoices', 'payment_type')) {
                $table->string('payment_type', 10)->nullable()->after('status');
            }
            if (! Schema::hasColumn('ar_invoices', 'payment_term_days')) {
                $table->unsignedInteger('payment_term_days')->default(0)->after('payment_type');
            }
            if (! Schema::hasColumn('ar_invoices', 'sales_person_id')) {
                $table->unsignedBigInteger('sales_person_id')->nullable()->after('payment_term_days');
            }
            if (! Schema::hasColumn('ar_invoices', 'lpo_reference')) {
                $table->string('lpo_reference', 255)->nullable()->after('sales_person_id');
            }
            if (! Schema::hasColumn('ar_invoices', 'invoice_discount_type')) {
                $table->string('invoice_discount_type', 10)->default('fixed')->after('discount_total_cents');
            }
            if (! Schema::hasColumn('ar_invoices', 'invoice_discount_value')) {
                $table->bigInteger('invoice_discount_value')->default(0)->after('invoice_discount_type');
            }
            if (! Schema::hasColumn('ar_invoices', 'invoice_discount_cents')) {
                $table->bigInteger('invoice_discount_cents')->default(0)->after('invoice_discount_value');
            }
        });

        Schema::table('ar_invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('ar_invoice_items', 'unit')) {
                $table->string('unit', 50)->nullable()->after('qty');
            }
            if (! Schema::hasColumn('ar_invoice_items', 'line_notes')) {
                $table->text('line_notes')->nullable()->after('line_total_cents');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'payment_type',
                'payment_term_days',
                'sales_person_id',
                'lpo_reference',
                'invoice_discount_type',
                'invoice_discount_value',
                'invoice_discount_cents',
            ]);
        });
        Schema::table('ar_invoice_items', function (Blueprint $table) {
            $table->dropColumn(['unit', 'line_notes']);
        });
    }
};
