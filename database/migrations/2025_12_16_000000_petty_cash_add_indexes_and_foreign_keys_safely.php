<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('petty_cash_wallets')) {
            Schema::table('petty_cash_wallets', function (Blueprint $table) {
                foreach (['driver_id', 'active'] as $col) {
                    $indexName = "petty_cash_wallets_{$col}_index";
                    if (! $this->hasIndex('petty_cash_wallets', $indexName)) {
                        $table->index($col);
                    }
                }
                $this->addFk($table, 'petty_cash_wallets', 'created_by', 'users', 'id', 'set null');
            });
        }

        if (Schema::hasTable('petty_cash_issues')) {
            Schema::table('petty_cash_issues', function (Blueprint $table) {
                foreach (['wallet_id', 'issue_date', 'issued_by'] as $col) {
                    $indexName = "petty_cash_issues_{$col}_index";
                    if (! $this->hasIndex('petty_cash_issues', $indexName)) {
                        $table->index($col);
                    }
                }
                $this->addFk($table, 'petty_cash_issues', 'wallet_id', 'petty_cash_wallets', 'id', 'cascade');
                $this->addFk($table, 'petty_cash_issues', 'issued_by', 'users', 'id', 'set null');
            });
        }

        if (Schema::hasTable('petty_cash_expenses')) {
            Schema::table('petty_cash_expenses', function (Blueprint $table) {
                foreach (['wallet_id', 'category_id', 'status', 'expense_date', 'submitted_by', 'approved_by'] as $col) {
                    $indexName = "petty_cash_expenses_{$col}_index";
                    if (! $this->hasIndex('petty_cash_expenses', $indexName)) {
                        $table->index($col);
                    }
                }

                $this->addFk($table, 'petty_cash_expenses', 'wallet_id', 'petty_cash_wallets', 'id', 'cascade');
                $this->addFk($table, 'petty_cash_expenses', 'category_id', 'expense_categories', 'id', 'restrict');
                $this->addFk($table, 'petty_cash_expenses', 'submitted_by', 'users', 'id', 'set null');
                $this->addFk($table, 'petty_cash_expenses', 'approved_by', 'users', 'id', 'set null');
            });
        }

        if (Schema::hasTable('petty_cash_reconciliations')) {
            Schema::table('petty_cash_reconciliations', function (Blueprint $table) {
                foreach (['wallet_id', 'period_start', 'period_end', 'reconciled_by'] as $col) {
                    $indexName = "petty_cash_reconciliations_{$col}_index";
                    if (! $this->hasIndex('petty_cash_reconciliations', $indexName)) {
                        $table->index($col);
                    }
                }

                $this->addFk($table, 'petty_cash_reconciliations', 'wallet_id', 'petty_cash_wallets', 'id', 'cascade');
                $this->addFk($table, 'petty_cash_reconciliations', 'reconciled_by', 'users', 'id', 'set null');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive rollback intentionally omitted
    }

    private function hasIndex(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        try {
            if (method_exists(Schema::getConnection(), 'getDoctrineSchemaManager')) {
                return collect(Schema::getConnection()->getDoctrineSchemaManager()->listTableIndexes($table))->has($index);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return false;
    }

    private function addFk(Blueprint $table, string $fromTable, string $column, string $toTable, string $toColumn, string $onDelete): void
    {
        if (! Schema::hasTable($toTable)) {
            return;
        }

        try {
            $table->foreign($column)->references($toColumn)->on($toTable)->onDelete($onDelete);
        } catch (\Throwable $e) {
            // ignore duplicate/invalid FKs
        }
    }
};
