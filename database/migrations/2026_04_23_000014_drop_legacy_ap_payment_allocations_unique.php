<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ap_payment_allocations')) {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return;
        }

        $legacyIndex = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics '
            .'WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, 'ap_payment_allocations', 'uniq_payment_invoice']
        );

        if ($legacyIndex) {
            DB::statement('ALTER TABLE ap_payment_allocations DROP INDEX uniq_payment_invoice');
        }
    }

    public function down(): void
    {
        // Legacy unique index intentionally not restored.
    }
};
