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

        $this->addForeignKeyIfClean('orders', 'customer_id', 'customers', 'id', 'orders_customer_fk', 'SET NULL');
        $this->addForeignKeyIfClean('orders', 'created_by', 'users', 'id', 'orders_created_by_fk', 'SET NULL');

        $this->addForeignKeyIfClean('ops_events', 'order_id', 'orders', 'id', 'ops_events_order_fk', 'SET NULL');
        $this->addForeignKeyIfClean('ops_events', 'order_item_id', 'order_items', 'id', 'ops_events_order_item_fk', 'SET NULL');
        $this->addForeignKeyIfClean('ops_events', 'actor_user_id', 'users', 'id', 'ops_events_actor_fk', 'SET NULL');

        if (Schema::hasTable('branches')) {
            $this->addForeignKeyIfClean('ops_events', 'branch_id', 'branches', 'id', 'ops_events_branch_fk', 'SET NULL');
        }

        if (Schema::hasTable('daily_dish_menus') && Schema::hasColumn('daily_dish_menus', 'created_by')) {
            DB::statement('ALTER TABLE daily_dish_menus MODIFY created_by INT NULL');
        }
        $this->addForeignKeyIfClean('daily_dish_menus', 'created_by', 'users', 'id', 'daily_dish_menus_created_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('subscription_order_runs', 'created_by', 'users', 'id', 'sub_order_runs_created_by_fk', 'SET NULL');
        $this->addForeignKeyIfClean('subscription_order_run_errors', 'run_id', 'subscription_order_runs', 'id', 'sub_order_run_errors_run_fk', 'CASCADE');
        if (Schema::hasTable('subscription_order_run_errors') && Schema::hasColumn('subscription_order_run_errors', 'subscription_id')) {
            DB::statement('ALTER TABLE subscription_order_run_errors MODIFY subscription_id BIGINT UNSIGNED NULL');
        }
        $this->addForeignKeyIfClean('subscription_order_run_errors', 'subscription_id', 'meal_subscriptions', 'id', 'sub_order_run_errors_sub_fk', 'SET NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->dropForeignKeyIfExists('orders', 'orders_customer_fk');
        $this->dropForeignKeyIfExists('orders', 'orders_created_by_fk');
        $this->dropForeignKeyIfExists('ops_events', 'ops_events_order_fk');
        $this->dropForeignKeyIfExists('ops_events', 'ops_events_order_item_fk');
        $this->dropForeignKeyIfExists('ops_events', 'ops_events_actor_fk');
        $this->dropForeignKeyIfExists('ops_events', 'ops_events_branch_fk');
        $this->dropForeignKeyIfExists('daily_dish_menus', 'daily_dish_menus_created_by_fk');
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
