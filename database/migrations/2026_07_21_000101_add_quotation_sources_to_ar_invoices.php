<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('source_quotation_id')->nullable()->after('source_pastry_order_id');
            $table->unsignedBigInteger('source_quotation_version_id')->nullable()->after('source_quotation_id');

            $table->foreign('source_quotation_id')->references('id')->on('quotations')->nullOnDelete();
            $table->foreign('source_quotation_version_id')->references('id')->on('quotation_versions')->nullOnDelete();
            $table->unique('source_quotation_id', 'ar_invoices_source_quotation_unique');
            $table->unique('source_quotation_version_id', 'ar_invoices_source_quotation_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ar_invoices', function (Blueprint $table) {
            $table->dropForeign(['source_quotation_id']);
            $table->dropForeign(['source_quotation_version_id']);
            $table->dropUnique('ar_invoices_source_quotation_unique');
            $table->dropUnique('ar_invoices_source_quotation_version_unique');
            $table->dropColumn(['source_quotation_id', 'source_quotation_version_id']);
        });
    }
};
