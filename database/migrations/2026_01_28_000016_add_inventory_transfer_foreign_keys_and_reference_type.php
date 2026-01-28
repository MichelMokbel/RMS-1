<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasTable('inventory_transactions') && Schema::hasColumn('inventory_transactions', 'reference_type')) {
                DB::statement("ALTER TABLE inventory_transactions MODIFY reference_type ENUM('purchase_order','work_order','manual','recipe','transfer') NOT NULL DEFAULT 'manual'");
            }

            $this->addForeignKeyIfClean('inventory_transfers', 'from_branch_id', 'branches', 'id', 'inventory_transfers_from_branch_fk', 'RESTRICT');
            $this->addForeignKeyIfClean('inventory_transfers', 'to_branch_id', 'branches', 'id', 'inventory_transfers_to_branch_fk', 'RESTRICT');
            $this->addForeignKeyIfClean('inventory_transfers', 'created_by', 'users', 'id', 'inventory_transfers_created_by_fk', 'SET NULL');
            $this->addForeignKeyIfClean('inventory_transfers', 'posted_by', 'users', 'id', 'inventory_transfers_posted_by_fk', 'SET NULL');

            $this->addForeignKeyIfClean('inventory_transfer_lines', 'transfer_id', 'inventory_transfers', 'id', 'inventory_transfer_lines_transfer_fk', 'CASCADE');
            $this->addForeignKeyIfClean('inventory_transfer_lines', 'inventory_item_id', 'inventory_items', 'id', 'inventory_transfer_lines_item_fk', 'RESTRICT');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropForeignKeyIfExists('inventory_transfer_lines', 'inventory_transfer_lines_transfer_fk');
            $this->dropForeignKeyIfExists('inventory_transfer_lines', 'inventory_transfer_lines_item_fk');
            $this->dropForeignKeyIfExists('inventory_transfers', 'inventory_transfers_from_branch_fk');
            $this->dropForeignKeyIfExists('inventory_transfers', 'inventory_transfers_to_branch_fk');
            $this->dropForeignKeyIfExists('inventory_transfers', 'inventory_transfers_created_by_fk');
            $this->dropForeignKeyIfExists('inventory_transfers', 'inventory_transfers_posted_by_fk');

            if (Schema::hasTable('inventory_transactions') && Schema::hasColumn('inventory_transactions', 'reference_type')) {
                DB::statement("ALTER TABLE inventory_transactions MODIFY reference_type ENUM('purchase_order','work_order','manual','recipe') NOT NULL DEFAULT 'manual'");
            }
        }
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
