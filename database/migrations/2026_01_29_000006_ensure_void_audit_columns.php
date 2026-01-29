<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    }

    public function down(): void
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
};
