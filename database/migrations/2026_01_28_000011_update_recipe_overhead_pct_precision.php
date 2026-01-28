<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('recipes') || ! Schema::hasColumn('recipes', 'overhead_pct')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE recipes MODIFY overhead_pct DECIMAL(6,2) NOT NULL DEFAULT 0.00');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('recipes') || ! Schema::hasColumn('recipes', 'overhead_pct')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE recipes MODIFY overhead_pct DECIMAL(5,4) NOT NULL DEFAULT 0.0000');
        }
    }
};
