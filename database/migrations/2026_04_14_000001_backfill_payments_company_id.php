<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('payments')
            ->whereNull('company_id')
            ->update(['company_id' => 1]);
    }

    public function down(): void
    {
        // Intentionally not reversible — we cannot distinguish which records
        // were NULL before the backfill from those legitimately assigned to 1.
    }
};
