<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_stocks')) {
            Schema::create('inventory_stocks', function (Blueprint $table) {
                $table->id();
                $table->integer('inventory_item_id');
                $table->integer('branch_id');
                $table->decimal('current_stock', 12, 3)->default(0);
                $table->timestamps();

                $table->unique(['inventory_item_id', 'branch_id'], 'inventory_stocks_item_branch_unique');
                $table->index(['branch_id'], 'inventory_stocks_branch_index');
                $table->index(['inventory_item_id'], 'inventory_stocks_item_index');
            });
        }

        if (Schema::hasTable('inventory_items') && Schema::hasTable('inventory_stocks')) {
            DB::statement(
                'INSERT INTO inventory_stocks (inventory_item_id, branch_id, current_stock, created_at, updated_at) '
                .'SELECT i.id, 1, i.current_stock, NOW(), NOW() '
                .'FROM inventory_items i '
                .'WHERE NOT EXISTS (SELECT 1 FROM inventory_stocks s WHERE s.inventory_item_id = i.id AND s.branch_id = 1)'
            );
        }

        if (Schema::hasTable('inventory_transactions') && ! Schema::hasColumn('inventory_transactions', 'branch_id')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->integer('branch_id')->default(1)->after('item_id');
                $table->index('branch_id', 'inventory_transactions_branch_index');
            });
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            if (Schema::hasTable('inventory_stocks')) {
                $this->addForeignKeyIfClean('inventory_stocks', 'inventory_item_id', 'inventory_items', 'id', 'inventory_stocks_item_fk', 'CASCADE');
                if (Schema::hasTable('branches')) {
                    $this->addForeignKeyIfClean('inventory_stocks', 'branch_id', 'branches', 'id', 'inventory_stocks_branch_fk', 'RESTRICT');
                }
            }

            if (Schema::hasTable('inventory_transactions') && Schema::hasColumn('inventory_transactions', 'branch_id') && Schema::hasTable('branches')) {
                $this->addForeignKeyIfClean('inventory_transactions', 'branch_id', 'branches', 'id', 'inventory_transactions_branch_fk', 'RESTRICT');
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropForeignKeyIfExists('inventory_transactions', 'inventory_transactions_branch_fk');
            $this->dropForeignKeyIfExists('inventory_stocks', 'inventory_stocks_item_fk');
            $this->dropForeignKeyIfExists('inventory_stocks', 'inventory_stocks_branch_fk');
        }

        if (Schema::hasTable('inventory_transactions') && Schema::hasColumn('inventory_transactions', 'branch_id')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }

        Schema::dropIfExists('inventory_stocks');
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
