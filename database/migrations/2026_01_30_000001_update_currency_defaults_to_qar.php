<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasTable('payments')) {
            $this->alterCurrencyDefault($driver, 'payments');
            DB::table('payments')->where('currency', 'KWD')->update(['currency' => 'QAR']);
        }

        if (Schema::hasTable('ar_invoices')) {
            $this->alterCurrencyDefault($driver, 'ar_invoices');
            DB::table('ar_invoices')->where('currency', 'KWD')->update(['currency' => 'QAR']);
        }

        if (Schema::hasTable('sales')) {
            $this->alterCurrencyDefault($driver, 'sales');
            DB::table('sales')->where('currency', 'KWD')->update(['currency' => 'QAR']);
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (Schema::hasTable('payments')) {
            $this->alterCurrencyDefault($driver, 'payments', 'KWD');
        }

        if (Schema::hasTable('ar_invoices')) {
            $this->alterCurrencyDefault($driver, 'ar_invoices', 'KWD');
        }

        if (Schema::hasTable('sales')) {
            $this->alterCurrencyDefault($driver, 'sales', 'KWD');
        }
    }

    private function alterCurrencyDefault(string $driver, string $table, string $currency = 'QAR'): void
    {
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN currency SET DEFAULT '{$currency}'");
            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE {$table} MODIFY currency VARCHAR(3) NOT NULL DEFAULT '{$currency}'");
            return;
        }
    }
};
