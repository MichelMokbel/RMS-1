<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_transactions')) {
            return;
        }

        Schema::table('inventory_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_transactions', 'unit_cost')) {
                $table->decimal('unit_cost', 12, 4)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('inventory_transactions', 'total_cost')) {
                $table->decimal('total_cost', 12, 4)->nullable()->after('unit_cost');
            }
        });

        if (! $this->indexExists('inventory_transactions', 'inventory_transactions_reference_index')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->index(['reference_type', 'reference_id'], 'inventory_transactions_reference_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_transactions')) {
            return;
        }

        if ($this->indexExists('inventory_transactions', 'inventory_transactions_reference_index')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->dropIndex('inventory_transactions_reference_index');
            });
        }

        Schema::table('inventory_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_transactions', 'total_cost')) {
                $table->dropColumn('total_cost');
            }
            if (Schema::hasColumn('inventory_transactions', 'unit_cost')) {
                $table->dropColumn('unit_cost');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            if (! $database) {
                return false;
            }

            $result = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $index]
            );

            return $result !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }
        }

        return false;
    }
};
