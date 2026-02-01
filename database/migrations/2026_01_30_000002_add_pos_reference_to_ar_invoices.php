<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ar_invoices')) {
            return;
        }

        Schema::table('ar_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('ar_invoices', 'pos_reference')) {
                $table->string('pos_reference', 64)->nullable()->after('source_sale_id');
                $table->index('pos_reference', 'ar_invoices_pos_reference_index');
            }
            if (! Schema::hasColumn('ar_invoices', 'source')) {
                $table->string('source', 20)->default('dashboard')->after('pos_reference');
                $table->index('source', 'ar_invoices_source_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ar_invoices')) {
            return;
        }

        Schema::table('ar_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('ar_invoices', 'pos_reference')) {
                $table->dropIndex('ar_invoices_pos_reference_index');
                $table->dropColumn('pos_reference');
            }
            if (Schema::hasColumn('ar_invoices', 'source')) {
                $table->dropIndex('ar_invoices_source_index');
                $table->dropColumn('source');
            }
        });
    }
};
