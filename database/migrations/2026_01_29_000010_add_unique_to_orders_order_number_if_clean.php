<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'order_number')) {
            return;
        }

        if ($this->indexExists('orders', 'orders_order_number_unique')) {
            return;
        }

        // Only add unique index if data is clean.
        $dup = DB::selectOne(
            "SELECT COUNT(*) AS c FROM (
                SELECT order_number FROM orders
                WHERE order_number IS NOT NULL AND order_number <> ''
                GROUP BY order_number
                HAVING COUNT(*) > 1
            ) t"
        );

        if (($dup->c ?? 0) > 0) {
            return;
        }

        DB::statement('ALTER TABLE orders ADD UNIQUE orders_order_number_unique (order_number)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! $this->indexExists('orders', 'orders_order_number_unique')) {
            return;
        }

        DB::statement('ALTER TABLE orders DROP INDEX orders_order_number_unique');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return $row !== null;
    }
};

