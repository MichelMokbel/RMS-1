<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table) {
            // Generic indexes to speed up status/name lookups.
            $table->index('status', 'suppliers_status_index');
            $table->index('name', 'suppliers_name_index');
        });

        // Attempt a unique index on name only if there are no duplicates.
        $hasDuplicates = DB::table('suppliers')
            ->select('name')
            ->groupBy('name')
            ->havingRaw('COUNT(*) > 1')
            ->limit(1)
            ->exists();

        if (! $hasDuplicates) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->unique('name', 'suppliers_name_unique');
            });
        }
        // If duplicates exist we intentionally skip the DB unique index and rely on validation instead.
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropIndex('suppliers_status_index');
            $table->dropIndex('suppliers_name_index');
            $table->dropUnique('suppliers_name_unique');
        });
    }
};
