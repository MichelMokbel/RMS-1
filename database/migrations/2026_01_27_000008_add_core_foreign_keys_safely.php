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

        $this->addForeignKeyIfClean('order_items', 'order_id', 'orders', 'id', 'order_items_order_fk', 'CASCADE');
        $this->addForeignKeyIfClean('order_items', 'menu_item_id', 'menu_items', 'id', 'order_items_menu_item_fk', 'RESTRICT');

        $this->addForeignKeyIfClean('meal_subscription_orders', 'subscription_id', 'meal_subscriptions', 'id', 'meal_sub_orders_subscription_fk', 'CASCADE');
        $this->addForeignKeyIfClean('meal_subscription_orders', 'order_id', 'orders', 'id', 'meal_sub_orders_order_fk', 'CASCADE');

        $this->addForeignKeyIfClean('daily_dish_menu_items', 'daily_dish_menu_id', 'daily_dish_menus', 'id', 'dd_menu_items_menu_fk', 'CASCADE');
        $this->addForeignKeyIfClean('daily_dish_menu_items', 'menu_item_id', 'menu_items', 'id', 'dd_menu_items_item_fk', 'RESTRICT');

        $this->addForeignKeyIfClean('purchase_order_items', 'purchase_order_id', 'purchase_orders', 'id', 'po_items_po_fk', 'CASCADE');
        $this->addForeignKeyIfClean('purchase_order_items', 'item_id', 'inventory_items', 'id', 'po_items_item_fk', 'RESTRICT');

        $this->addForeignKeyIfClean('inventory_transactions', 'item_id', 'inventory_items', 'id', 'inv_tx_item_fk', 'CASCADE');

        $this->addForeignKeyIfClean('ap_invoice_items', 'invoice_id', 'ap_invoices', 'id', 'ap_invoice_items_invoice_fk', 'CASCADE');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $this->dropForeignKeyIfExists('order_items', 'order_items_order_fk');
        $this->dropForeignKeyIfExists('order_items', 'order_items_menu_item_fk');
        $this->dropForeignKeyIfExists('meal_subscription_orders', 'meal_sub_orders_subscription_fk');
        $this->dropForeignKeyIfExists('meal_subscription_orders', 'meal_sub_orders_order_fk');
        $this->dropForeignKeyIfExists('daily_dish_menu_items', 'dd_menu_items_menu_fk');
        $this->dropForeignKeyIfExists('daily_dish_menu_items', 'dd_menu_items_item_fk');
        $this->dropForeignKeyIfExists('purchase_order_items', 'po_items_po_fk');
        $this->dropForeignKeyIfExists('purchase_order_items', 'po_items_item_fk');
        $this->dropForeignKeyIfExists('inventory_transactions', 'inv_tx_item_fk');
        $this->dropForeignKeyIfExists('ap_invoice_items', 'ap_invoice_items_invoice_fk');
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
