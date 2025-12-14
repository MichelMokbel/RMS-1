<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // expense_categories
        if (Schema::hasTable('expense_categories')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                if (! $this->hasIndex('expense_categories', 'expense_categories_active_index')) {
                    $table->index('active');
                }
            });

            // Optional unique on name if safe
            $hasDupes = DB::table('expense_categories')
                ->select('name', DB::raw('COUNT(*) as cnt'))
                ->groupBy('name')
                ->havingRaw('COUNT(*) > 1')
                ->exists();

            if (! $hasDupes && ! $this->hasIndex('expense_categories', 'expense_categories_name_unique')) {
                Schema::table('expense_categories', function (Blueprint $table) {
                    $table->unique('name');
                });
            }
        }

        if (Schema::hasTable('expenses')) {
            Schema::table('expenses', function (Blueprint $table) {
                foreach ([
                    'category_id',
                    'supplier_id',
                    'expense_date',
                    'payment_status',
                    'payment_method',
                    'created_by',
                ] as $col) {
                    if (! $this->hasIndex('expenses', "expenses_{$col}_index")) {
                        $table->index($col);
                    }
                }
            });

            Schema::table('expenses', function (Blueprint $table) {
                $this->addFk($table, 'expenses', 'supplier_id', 'suppliers', 'id', 'set null');
                $this->addFk($table, 'expenses', 'category_id', 'expense_categories', 'id', 'restrict');
                $this->addFk($table, 'expenses', 'created_by', 'users', 'id', 'set null');
            });
        }

        if (Schema::hasTable('expense_attachments')) {
            Schema::table('expense_attachments', function (Blueprint $table) {
                foreach (['expense_id', 'uploaded_by'] as $col) {
                    if (! $this->hasIndex('expense_attachments', "expense_attachments_{$col}_index")) {
                        $table->index($col);
                    }
                }
                $this->addFk($table, 'expense_attachments', 'expense_id', 'expenses', 'id', 'cascade');
                $this->addFk($table, 'expense_attachments', 'uploaded_by', 'users', 'id', 'set null');
            });
        }

        if (Schema::hasTable('expense_payments')) {
            Schema::table('expense_payments', function (Blueprint $table) {
                foreach (['expense_id', 'payment_date', 'created_by'] as $col) {
                    if (! $this->hasIndex('expense_payments', "expense_payments_{$col}_index")) {
                        $table->index($col);
                    }
                }
                $this->addFk($table, 'expense_payments', 'expense_id', 'expenses', 'id', 'cascade');
                $this->addFk($table, 'expense_payments', 'created_by', 'users', 'id', 'set null');
            });
        }
    }

    public function down(): void
    {
        // non-destructive rollback omitted intentionally
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
            // ignore if exists
        }
    }
};
