<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ap_payments') || ! Schema::hasColumn('ap_payments', 'payment_method')) {
            return;
        }

        DB::statement("ALTER TABLE ap_payments MODIFY payment_method ENUM('cash','bank_transfer','card','cheque','other','petty_cash') NULL DEFAULT 'bank_transfer'");
    }

    public function down(): void
    {
        if (! Schema::hasTable('ap_payments') || ! Schema::hasColumn('ap_payments', 'payment_method')) {
            return;
        }

        DB::statement("UPDATE ap_payments SET payment_method = 'other' WHERE payment_method = 'petty_cash'");
        DB::statement("ALTER TABLE ap_payments MODIFY payment_method ENUM('cash','bank_transfer','card','cheque','other') NULL DEFAULT 'bank_transfer'");
    }
};
