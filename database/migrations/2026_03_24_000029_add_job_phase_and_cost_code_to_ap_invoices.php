<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ap_invoices')) {
            return;
        }

        Schema::table('ap_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('ap_invoices', 'job_phase_id')) {
                $table->unsignedBigInteger('job_phase_id')->nullable()->after('job_id');
            }

            if (! Schema::hasColumn('ap_invoices', 'job_cost_code_id')) {
                $table->unsignedBigInteger('job_cost_code_id')->nullable()->after('job_phase_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ap_invoices')) {
            return;
        }

        Schema::table('ap_invoices', function (Blueprint $table) {
            foreach (['job_cost_code_id', 'job_phase_id'] as $column) {
                if (Schema::hasColumn('ap_invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
