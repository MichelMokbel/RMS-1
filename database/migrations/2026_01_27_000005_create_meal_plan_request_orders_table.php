<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meal_plan_request_orders')) {
            Schema::create('meal_plan_request_orders', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('meal_plan_request_id');
                $table->integer('order_id');
                $table->timestamps();
                $table->unique(['meal_plan_request_id', 'order_id'], 'mpr_orders_unique');
                $table->index('order_id', 'mpr_orders_order_id_index');
            });
        }

        if (Schema::hasTable('meal_plan_request_orders')) {
            $this->backfillFromLegacyJson();
            $this->addForeignKeysIfClean();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('meal_plan_request_orders')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropForeignKeyIfExists('meal_plan_request_orders', 'mpr_orders_request_fk');
            $this->dropForeignKeyIfExists('meal_plan_request_orders', 'mpr_orders_order_fk');
        }

        Schema::dropIfExists('meal_plan_request_orders');
    }

    private function backfillFromLegacyJson(): void
    {
        if (! Schema::hasTable('meal_plan_requests')) {
            return;
        }

        $requests = DB::table('meal_plan_requests')->select('id', 'order_ids')->get();
        foreach ($requests as $req) {
            $raw = $req->order_ids;
            $ids = [];
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $ids = is_array($decoded) ? $decoded : [];
            } elseif (is_array($raw)) {
                $ids = $raw;
            }

            $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
            foreach ($ids as $orderId) {
                DB::table('meal_plan_request_orders')->updateOrInsert([
                    'meal_plan_request_id' => $req->id,
                    'order_id' => $orderId,
                ], [
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function addForeignKeysIfClean(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->addForeignKeyIfClean(
            'meal_plan_request_orders',
            'meal_plan_request_id',
            'meal_plan_requests',
            'id',
            'mpr_orders_request_fk',
            'CASCADE'
        );

        $this->addForeignKeyIfClean(
            'meal_plan_request_orders',
            'order_id',
            'orders',
            'id',
            'mpr_orders_order_fk',
            'CASCADE'
        );
    }

    private function addForeignKeyIfClean(string $table, string $column, string $refTable, string $refColumn, string $constraint, string $onDelete): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        if (! Schema::hasTable($refTable) || ! Schema::hasColumn($refTable, $refColumn)) {
            return;
        }
        if ($this->foreignKeyExists($table, $constraint)) {
            return;
        }

        $orphans = DB::selectOne(
            "SELECT COUNT(*) AS c FROM {$table} t LEFT JOIN {$refTable} r ON t.{$column} = r.{$refColumn} WHERE t.{$column} IS NOT NULL AND r.{$refColumn} IS NULL"
        );
        if (($orphans->c ?? 0) > 0) {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) REFERENCES {$refTable}({$refColumn}) ON DELETE {$onDelete} ON UPDATE CASCADE");
    }

    private function dropForeignKeyIfExists(string $table, string $constraint): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (! $this->foreignKeyExists($table, $constraint)) {
            return;
        }

        DB::statement("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}");
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = "FOREIGN KEY" LIMIT 1',
            [$database, $table, $constraint]
        );

        return $row !== null;
    }
};
