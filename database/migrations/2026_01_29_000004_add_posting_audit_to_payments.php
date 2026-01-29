<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ap_payments')) {
            Schema::table('ap_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('ap_payments', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('created_by');
                }
                if (! Schema::hasColumn('ap_payments', 'posted_by')) {
                    $table->integer('posted_by')->nullable()->after('posted_at');
                }
            });
        }

        if (Schema::hasTable('expense_payments')) {
            Schema::table('expense_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('expense_payments', 'posted_at')) {
                    $table->timestamp('posted_at')->nullable()->after('created_by');
                }
                if (! Schema::hasColumn('expense_payments', 'posted_by')) {
                    $table->integer('posted_by')->nullable()->after('posted_at');
                }
            });
        }

        if (Schema::hasTable('ap_payments') && Schema::hasColumn('ap_payments', 'posted_at') && Schema::hasColumn('ap_payments', 'posted_by')) {
            DB::statement('UPDATE ap_payments SET posted_at = COALESCE(posted_at, created_at), posted_by = COALESCE(posted_by, created_by)');
        }

        if (Schema::hasTable('expense_payments') && Schema::hasColumn('expense_payments', 'posted_at') && Schema::hasColumn('expense_payments', 'posted_by')) {
            DB::statement('UPDATE expense_payments SET posted_at = COALESCE(posted_at, created_at), posted_by = COALESCE(posted_by, created_by)');
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->addForeignKeyIfClean('ap_payments', 'posted_by', 'users', 'id', 'ap_payments_posted_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('expense_payments', 'posted_by', 'users', 'id', 'expense_payments_posted_by_fk', 'SET NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropForeignKeyIfExists('ap_payments', 'ap_payments_posted_by_fk');
            $this->dropForeignKeyIfExists('expense_payments', 'expense_payments_posted_by_fk');
        }

        if (Schema::hasTable('ap_payments')) {
            Schema::table('ap_payments', function (Blueprint $table) {
                if (Schema::hasColumn('ap_payments', 'posted_by')) {
                    $table->dropColumn('posted_by');
                }
                if (Schema::hasColumn('ap_payments', 'posted_at')) {
                    $table->dropColumn('posted_at');
                }
            });
        }

        if (Schema::hasTable('expense_payments')) {
            Schema::table('expense_payments', function (Blueprint $table) {
                if (Schema::hasColumn('expense_payments', 'posted_by')) {
                    $table->dropColumn('posted_by');
                }
                if (Schema::hasColumn('expense_payments', 'posted_at')) {
                    $table->dropColumn('posted_at');
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
