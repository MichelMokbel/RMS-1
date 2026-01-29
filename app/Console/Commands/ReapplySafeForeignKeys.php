<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReapplySafeForeignKeys extends Command
{
    protected $signature = 'data:reapply-safe-fks {--dry-run : Report which foreign keys would be applied}';

    protected $description = 'Re-apply safe foreign keys that were skipped due to dirty data';

    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->warn('Safe foreign key reapply is only supported on MySQL.');
            return Command::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($this->constraints() as $fk) {
            $status = $this->applyIfClean($fk, $dryRun);
            $rows[] = [$fk['label'], $status];
        }

        $this->table(['Constraint', 'Status'], $rows);

        $blocked = collect($rows)->contains(fn ($row) => $row[1] === 'ORPHANS');

        return $blocked ? Command::FAILURE : Command::SUCCESS;
    }

    private function constraints(): array
    {
        return [
            ['label' => 'order_items.order_id -> orders.id', 'table' => 'order_items', 'column' => 'order_id', 'refTable' => 'orders', 'refColumn' => 'id', 'constraint' => 'order_items_order_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'order_items.menu_item_id -> menu_items.id', 'table' => 'order_items', 'column' => 'menu_item_id', 'refTable' => 'menu_items', 'refColumn' => 'id', 'constraint' => 'order_items_menu_item_fk', 'onDelete' => 'RESTRICT'],
            ['label' => 'meal_subscription_orders.subscription_id -> meal_subscriptions.id', 'table' => 'meal_subscription_orders', 'column' => 'subscription_id', 'refTable' => 'meal_subscriptions', 'refColumn' => 'id', 'constraint' => 'meal_sub_orders_subscription_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'meal_subscription_orders.order_id -> orders.id', 'table' => 'meal_subscription_orders', 'column' => 'order_id', 'refTable' => 'orders', 'refColumn' => 'id', 'constraint' => 'meal_sub_orders_order_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'daily_dish_menu_items.daily_dish_menu_id -> daily_dish_menus.id', 'table' => 'daily_dish_menu_items', 'column' => 'daily_dish_menu_id', 'refTable' => 'daily_dish_menus', 'refColumn' => 'id', 'constraint' => 'dd_menu_items_menu_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'daily_dish_menu_items.menu_item_id -> menu_items.id', 'table' => 'daily_dish_menu_items', 'column' => 'menu_item_id', 'refTable' => 'menu_items', 'refColumn' => 'id', 'constraint' => 'dd_menu_items_item_fk', 'onDelete' => 'RESTRICT'],
            ['label' => 'purchase_order_items.purchase_order_id -> purchase_orders.id', 'table' => 'purchase_order_items', 'column' => 'purchase_order_id', 'refTable' => 'purchase_orders', 'refColumn' => 'id', 'constraint' => 'po_items_po_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'purchase_order_items.item_id -> inventory_items.id', 'table' => 'purchase_order_items', 'column' => 'item_id', 'refTable' => 'inventory_items', 'refColumn' => 'id', 'constraint' => 'po_items_item_fk', 'onDelete' => 'RESTRICT'],
            ['label' => 'inventory_transactions.item_id -> inventory_items.id', 'table' => 'inventory_transactions', 'column' => 'item_id', 'refTable' => 'inventory_items', 'refColumn' => 'id', 'constraint' => 'inv_tx_item_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'ap_invoice_items.invoice_id -> ap_invoices.id', 'table' => 'ap_invoice_items', 'column' => 'invoice_id', 'refTable' => 'ap_invoices', 'refColumn' => 'id', 'constraint' => 'ap_invoice_items_invoice_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'orders.customer_id -> customers.id', 'table' => 'orders', 'column' => 'customer_id', 'refTable' => 'customers', 'refColumn' => 'id', 'constraint' => 'orders_customer_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'orders.created_by -> users.id', 'table' => 'orders', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'orders_created_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'orders.branch_id -> branches.id', 'table' => 'orders', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id', 'constraint' => 'orders_branch_id_fk', 'onDelete' => 'RESTRICT'],
            ['label' => 'ops_events.order_id -> orders.id', 'table' => 'ops_events', 'column' => 'order_id', 'refTable' => 'orders', 'refColumn' => 'id', 'constraint' => 'ops_events_order_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'ops_events.order_item_id -> order_items.id', 'table' => 'ops_events', 'column' => 'order_item_id', 'refTable' => 'order_items', 'refColumn' => 'id', 'constraint' => 'ops_events_order_item_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'ops_events.actor_user_id -> users.id', 'table' => 'ops_events', 'column' => 'actor_user_id', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'ops_events_actor_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'ops_events.branch_id -> branches.id', 'table' => 'ops_events', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id', 'constraint' => 'ops_events_branch_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'daily_dish_menus.created_by -> users.id', 'table' => 'daily_dish_menus', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'daily_dish_menus_created_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'daily_dish_menus.branch_id -> branches.id', 'table' => 'daily_dish_menus', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id', 'constraint' => 'daily_dish_menus_branch_id_fk', 'onDelete' => 'RESTRICT'],
            ['label' => 'subscription_order_runs.created_by -> users.id', 'table' => 'subscription_order_runs', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'sub_order_runs_created_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'subscription_order_runs.branch_id -> branches.id', 'table' => 'subscription_order_runs', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id', 'constraint' => 'sub_order_runs_branch_fk', 'onDelete' => 'RESTRICT'],
            ['label' => 'subscription_order_run_errors.run_id -> subscription_order_runs.id', 'table' => 'subscription_order_run_errors', 'column' => 'run_id', 'refTable' => 'subscription_order_runs', 'refColumn' => 'id', 'constraint' => 'sub_order_run_errors_run_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'subscription_order_run_errors.subscription_id -> meal_subscriptions.id', 'table' => 'subscription_order_run_errors', 'column' => 'subscription_id', 'refTable' => 'meal_subscriptions', 'refColumn' => 'id', 'constraint' => 'sub_order_run_errors_sub_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'meal_subscriptions.created_by -> users.id', 'table' => 'meal_subscriptions', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'meal_subscriptions_created_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'meal_subscriptions.meal_plan_request_id -> meal_plan_requests.id', 'table' => 'meal_subscriptions', 'column' => 'meal_plan_request_id', 'refTable' => 'meal_plan_requests', 'refColumn' => 'id', 'constraint' => 'meal_subscriptions_mpr_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'meal_subscriptions.branch_id -> branches.id', 'table' => 'meal_subscriptions', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id', 'constraint' => 'meal_subscriptions_branch_fk', 'onDelete' => 'RESTRICT'],
            ['label' => 'meal_subscription_orders.branch_id -> branches.id', 'table' => 'meal_subscription_orders', 'column' => 'branch_id', 'refTable' => 'branches', 'refColumn' => 'id', 'constraint' => 'meal_sub_orders_branch_fk', 'onDelete' => 'RESTRICT'],
            ['label' => 'meal_subscription_pauses.created_by -> users.id', 'table' => 'meal_subscription_pauses', 'column' => 'created_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'meal_sub_pauses_created_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'meal_plan_request_orders.meal_plan_request_id -> meal_plan_requests.id', 'table' => 'meal_plan_request_orders', 'column' => 'meal_plan_request_id', 'refTable' => 'meal_plan_requests', 'refColumn' => 'id', 'constraint' => 'mpr_orders_request_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'meal_plan_request_orders.order_id -> orders.id', 'table' => 'meal_plan_request_orders', 'column' => 'order_id', 'refTable' => 'orders', 'refColumn' => 'id', 'constraint' => 'mpr_orders_order_fk', 'onDelete' => 'CASCADE'],
            ['label' => 'ap_payments.posted_by -> users.id', 'table' => 'ap_payments', 'column' => 'posted_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'ap_payments_posted_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'expense_payments.posted_by -> users.id', 'table' => 'expense_payments', 'column' => 'posted_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'expense_payments_posted_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'ap_payments.voided_by -> users.id', 'table' => 'ap_payments', 'column' => 'voided_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'ap_payments_voided_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'expense_payments.voided_by -> users.id', 'table' => 'expense_payments', 'column' => 'voided_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'expense_payments_voided_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'ap_payment_allocations.voided_by -> users.id', 'table' => 'ap_payment_allocations', 'column' => 'voided_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'ap_payment_allocations_voided_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'petty_cash_issues.voided_by -> users.id', 'table' => 'petty_cash_issues', 'column' => 'voided_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'petty_cash_issues_voided_by_fk', 'onDelete' => 'SET NULL'],
            ['label' => 'petty_cash_reconciliations.voided_by -> users.id', 'table' => 'petty_cash_reconciliations', 'column' => 'voided_by', 'refTable' => 'users', 'refColumn' => 'id', 'constraint' => 'petty_cash_reconciliations_voided_by_fk', 'onDelete' => 'SET NULL'],
        ];
    }

    private function applyIfClean(array $fk, bool $dryRun): string
    {
        if (! Schema::hasTable($fk['table']) || ! Schema::hasColumn($fk['table'], $fk['column'])) {
            return 'SKIP';
        }
        if (! Schema::hasTable($fk['refTable']) || ! Schema::hasColumn($fk['refTable'], $fk['refColumn'])) {
            return 'SKIP';
        }

        if ($this->foreignKeyExists($fk['table'], $fk['constraint'])) {
            return 'EXISTS';
        }
        if ($this->foreignKeyExistsForColumn($fk['table'], $fk['column'], $fk['refTable'], $fk['refColumn'])) {
            return 'EXISTS';
        }

        $orphans = DB::selectOne(
            "SELECT COUNT(*) AS c FROM {$fk['table']} t LEFT JOIN {$fk['refTable']} r ON t.{$fk['column']} = r.{$fk['refColumn']} WHERE t.{$fk['column']} IS NOT NULL AND r.{$fk['refColumn']} IS NULL"
        );
        if (($orphans->c ?? 0) > 0) {
            return 'ORPHANS';
        }

        if ($dryRun) {
            return 'READY';
        }

        DB::statement(
            "ALTER TABLE {$fk['table']} ADD CONSTRAINT {$fk['constraint']} FOREIGN KEY ({$fk['column']}) REFERENCES {$fk['refTable']}({$fk['refColumn']}) ON DELETE {$fk['onDelete']} ON UPDATE CASCADE"
        );

        return 'ADDED';
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

    private function foreignKeyExistsForColumn(string $table, string $column, string $refTable, string $refColumn): bool
    {
        $database = DB::connection()->getDatabaseName();
        if (! $database) {
            return false;
        }

        $row = DB::selectOne(
            'SELECT 1 FROM information_schema.key_column_usage WHERE table_schema = ? AND table_name = ? AND column_name = ? AND referenced_table_name = ? AND referenced_column_name = ? LIMIT 1',
            [$database, $table, $column, $refTable, $refColumn]
        );

        return $row !== null;
    }
}
