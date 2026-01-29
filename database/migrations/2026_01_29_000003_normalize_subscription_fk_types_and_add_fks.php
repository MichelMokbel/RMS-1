<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('meal_subscriptions') && Schema::hasColumn('meal_subscriptions', 'created_by')) {
            DB::statement('ALTER TABLE meal_subscriptions MODIFY created_by INT NULL');
        }

        if (Schema::hasTable('meal_subscription_pauses') && Schema::hasColumn('meal_subscription_pauses', 'created_by')) {
            DB::statement('ALTER TABLE meal_subscription_pauses MODIFY created_by INT NULL');
        }

        if (Schema::hasTable('subscription_order_run_errors') && Schema::hasColumn('subscription_order_run_errors', 'subscription_id')) {
            DB::statement('ALTER TABLE subscription_order_run_errors MODIFY subscription_id BIGINT UNSIGNED NULL');
        }

        if (Schema::hasTable('subscription_order_run_errors') && Schema::hasColumn('subscription_order_run_errors', 'run_id')) {
            DB::statement('ALTER TABLE subscription_order_run_errors MODIFY run_id BIGINT UNSIGNED NOT NULL');
        }

        if (Schema::hasTable('meal_subscriptions') && Schema::hasColumn('meal_subscriptions', 'meal_plan_request_id')) {
            DB::statement('ALTER TABLE meal_subscriptions MODIFY meal_plan_request_id BIGINT UNSIGNED NULL');
        }

        $this->addForeignKeyIfClean('meal_subscriptions', 'created_by', 'users', 'id', 'meal_subscriptions_created_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('meal_subscriptions', 'meal_plan_request_id', 'meal_plan_requests', 'id', 'meal_subscriptions_mpr_fk', 'SET NULL');

        if (Schema::hasTable('branches')) {
            $this->addForeignKeyIfClean('meal_subscriptions', 'branch_id', 'branches', 'id', 'meal_subscriptions_branch_fk', 'RESTRICT');
            $this->addForeignKeyIfClean('meal_subscription_orders', 'branch_id', 'branches', 'id', 'meal_sub_orders_branch_fk', 'RESTRICT');
            $this->addForeignKeyIfClean('subscription_order_runs', 'branch_id', 'branches', 'id', 'sub_order_runs_branch_fk', 'RESTRICT');
        }

        $this->addForeignKeyIfClean('meal_subscription_pauses', 'created_by', 'users', 'id', 'meal_sub_pauses_created_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('subscription_order_runs', 'created_by', 'users', 'id', 'sub_order_runs_created_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('subscription_order_run_errors', 'run_id', 'subscription_order_runs', 'id', 'sub_order_run_errors_run_fk', 'CASCADE');
        $this->addForeignKeyIfClean('subscription_order_run_errors', 'subscription_id', 'meal_subscriptions', 'id', 'sub_order_run_errors_sub_fk', 'SET NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->dropForeignKeyIfExists('meal_subscriptions', 'meal_subscriptions_created_by_fk');
        $this->dropForeignKeyIfExists('meal_subscriptions', 'meal_subscriptions_mpr_fk');
        $this->dropForeignKeyIfExists('meal_subscriptions', 'meal_subscriptions_branch_fk');
        $this->dropForeignKeyIfExists('meal_subscription_orders', 'meal_sub_orders_branch_fk');
        $this->dropForeignKeyIfExists('subscription_order_runs', 'sub_order_runs_branch_fk');
        $this->dropForeignKeyIfExists('meal_subscription_pauses', 'meal_sub_pauses_created_by_fk');
        $this->dropForeignKeyIfExists('subscription_order_runs', 'sub_order_runs_created_by_fk');
        $this->dropForeignKeyIfExists('subscription_order_run_errors', 'sub_order_run_errors_run_fk');
        $this->dropForeignKeyIfExists('subscription_order_run_errors', 'sub_order_run_errors_sub_fk');
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
