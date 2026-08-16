<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ap_invoices') || Schema::hasColumn('ap_invoices', 'reference_number')) {
            return;
        }

        Schema::table('ap_invoices', function (Blueprint $table): void {
            $table->string('reference_number', 100)
                ->nullable()
                ->after('invoice_number')
                ->index('ap_invoices_reference_number_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ap_invoices') || ! Schema::hasColumn('ap_invoices', 'reference_number')) {
            return;
        }

        Schema::table('ap_invoices', function (Blueprint $table): void {
            $table->dropIndex('ap_invoices_reference_number_index');
            $table->dropColumn('reference_number');
        });
    }
};
