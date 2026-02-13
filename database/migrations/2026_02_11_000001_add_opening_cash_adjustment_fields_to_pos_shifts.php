<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_shifts')) {
            return;
        }

        Schema::table('pos_shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_shifts', 'opening_cash_adjusted_at')) {
                $table->timestamp('opening_cash_adjusted_at')->nullable()->after('opening_cash_cents');
            }
            if (! Schema::hasColumn('pos_shifts', 'opening_cash_adjusted_by')) {
                $table->unsignedBigInteger('opening_cash_adjusted_by')->nullable()->after('opening_cash_adjusted_at');
            }
            if (! Schema::hasColumn('pos_shifts', 'opening_cash_adjustment_reason')) {
                $table->string('opening_cash_adjustment_reason', 255)->nullable()->after('opening_cash_adjusted_by');
            }
        });

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->addForeignKeyIfClean(
            'pos_shifts',
            'opening_cash_adjusted_by',
            'users',
            'id',
            'pos_shifts_opening_cash_adjusted_by_fk',
            'SET NULL'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropForeignKeyIfExists('pos_shifts', 'pos_shifts_opening_cash_adjusted_by_fk');
        }

        if (! Schema::hasTable('pos_shifts')) {
            return;
        }

        Schema::table('pos_shifts', function (Blueprint $table) {
            if (Schema::hasColumn('pos_shifts', 'opening_cash_adjustment_reason')) {
                $table->dropColumn('opening_cash_adjustment_reason');
            }
            if (Schema::hasColumn('pos_shifts', 'opening_cash_adjusted_by')) {
                $table->dropColumn('opening_cash_adjusted_by');
            }
            if (Schema::hasColumn('pos_shifts', 'opening_cash_adjusted_at')) {
                $table->dropColumn('opening_cash_adjusted_at');
            }
        });
    }

    private function addForeignKeyIfClean(
        string $table,
        string $column,
        string $refTable,
        string $refColumn,
        string $constraint,
        string $onDelete
    ): void {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        if (! Schema::hasTable($refTable) || ! Schema::hasColumn($refTable, $refColumn)) {
            return;
        }
        if (! $this->tableEngineIsInnoDb($table) || ! $this->tableEngineIsInnoDb($refTable)) {
            return;
        }
        if (! $this->columnsAreCompatible($table, $column, $refTable, $refColumn)) {
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

    private function tableEngineIsInnoDb(string $table): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT ENGINE FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            [$database, $table]
        );

        return isset($row->ENGINE) && strcasecmp((string) $row->ENGINE, 'InnoDB') === 0;
    }

    private function columnsAreCompatible(string $table, string $column, string $refTable, string $refColumn): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $left = DB::selectOne(
            'SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1',
            [$database, $table, $column]
        );
        $right = DB::selectOne(
            'SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1',
            [$database, $refTable, $refColumn]
        );

        if (! isset($left->COLUMN_TYPE) || ! isset($right->COLUMN_TYPE)) {
            return false;
        }

        return (string) $left->COLUMN_TYPE === (string) $right->COLUMN_TYPE;
    }
};
