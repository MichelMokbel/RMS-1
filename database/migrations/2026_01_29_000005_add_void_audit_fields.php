<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'ap_payments',
            'expense_payments',
            'ap_payment_allocations',
            'petty_cash_issues',
            'petty_cash_reconciliations',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $after = null;
            if (Schema::hasColumn($table, 'created_at')) {
                $after = 'created_at';
            } elseif (Schema::hasColumn($table, 'updated_at')) {
                $after = 'updated_at';
            }

            Schema::table($table, function (Blueprint $tableBlueprint) use ($table) {
                if (! Schema::hasColumn($table, 'voided_at')) {
                    $tableBlueprint->timestamp('voided_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'voided_by')) {
                    $tableBlueprint->integer('voided_by')->nullable();
                }
            });

            if ($after) {
                Schema::table($table, function (Blueprint $tableBlueprint) use ($table, $after) {
                    if (Schema::hasColumn($table, 'voided_at')) {
                        $tableBlueprint->timestamp('voided_at')->nullable()->after($after)->change();
                    }
                    if (Schema::hasColumn($table, 'voided_by')) {
                        $tableBlueprint->integer('voided_by')->nullable()->after('voided_at')->change();
                    }
                });
            }
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->addForeignKeyIfClean('ap_payments', 'voided_by', 'users', 'id', 'ap_payments_voided_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('expense_payments', 'voided_by', 'users', 'id', 'expense_payments_voided_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('ap_payment_allocations', 'voided_by', 'users', 'id', 'ap_payment_allocations_voided_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('petty_cash_issues', 'voided_by', 'users', 'id', 'petty_cash_issues_voided_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('petty_cash_reconciliations', 'voided_by', 'users', 'id', 'petty_cash_reconciliations_voided_by_fk', 'SET NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropForeignKeyIfExists('ap_payments', 'ap_payments_voided_by_fk');
            $this->dropForeignKeyIfExists('expense_payments', 'expense_payments_voided_by_fk');
            $this->dropForeignKeyIfExists('ap_payment_allocations', 'ap_payment_allocations_voided_by_fk');
            $this->dropForeignKeyIfExists('petty_cash_issues', 'petty_cash_issues_voided_by_fk');
            $this->dropForeignKeyIfExists('petty_cash_reconciliations', 'petty_cash_reconciliations_voided_by_fk');
        }

        $tables = [
            'ap_payments',
            'expense_payments',
            'ap_payment_allocations',
            'petty_cash_issues',
            'petty_cash_reconciliations',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $tableBlueprint) use ($table) {
                if (Schema::hasColumn($table, 'voided_by')) {
                    $tableBlueprint->dropColumn('voided_by');
                }
                if (Schema::hasColumn($table, 'voided_at')) {
                    $tableBlueprint->dropColumn('voided_at');
                }
            });
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
