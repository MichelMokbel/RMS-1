<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ap_invoices')) {
            return;
        }

        Schema::table('ap_invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('ap_invoices', 'posted_at')) {
                $table->timestamp('posted_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('ap_invoices', 'posted_by')) {
                $table->integer('posted_by')->nullable()->after('posted_at');
            }
            if (! Schema::hasColumn('ap_invoices', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('posted_by');
            }
            if (! Schema::hasColumn('ap_invoices', 'voided_by')) {
                $table->integer('voided_by')->nullable()->after('voided_at');
            }
        });

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->addForeignKeyIfClean('ap_invoices', 'posted_by', 'users', 'id', 'ap_invoices_posted_by_fk');
            $this->addForeignKeyIfClean('ap_invoices', 'voided_by', 'users', 'id', 'ap_invoices_voided_by_fk');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ap_invoices')) {
            return;
        }

        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropForeignKeyIfExists('ap_invoices', 'ap_invoices_posted_by_fk');
            $this->dropForeignKeyIfExists('ap_invoices', 'ap_invoices_voided_by_fk');
        }

        Schema::table('ap_invoices', function (Blueprint $table) {
            if (Schema::hasColumn('ap_invoices', 'voided_by')) {
                $table->dropColumn('voided_by');
            }
            if (Schema::hasColumn('ap_invoices', 'voided_at')) {
                $table->dropColumn('voided_at');
            }
            if (Schema::hasColumn('ap_invoices', 'posted_by')) {
                $table->dropColumn('posted_by');
            }
            if (Schema::hasColumn('ap_invoices', 'posted_at')) {
                $table->dropColumn('posted_at');
            }
        });
    }

    private function addForeignKeyIfClean(string $table, string $column, string $refTable, string $refColumn, string $constraint): void
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

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} FOREIGN KEY ({$column}) REFERENCES {$refTable}({$refColumn}) ON DELETE SET NULL ON UPDATE CASCADE");
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
