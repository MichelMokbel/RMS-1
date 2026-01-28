<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('customers', 'name', 'customers_name_index');
        $this->addIndex('customers', 'customer_code', 'customers_customer_code_index');

        $this->addIndex('suppliers', 'contact_person', 'suppliers_contact_person_index');
        $this->addIndex('suppliers', 'email', 'suppliers_email_index');
        $this->addIndex('suppliers', 'phone', 'suppliers_phone_index');
        $this->addIndex('suppliers', 'qid_cr', 'suppliers_qid_cr_index');

        $this->addIndex('inventory_items', 'item_code', 'inventory_items_item_code_index');
        $this->addIndex('inventory_items', 'location', 'inventory_items_location_index');

        $this->addIndex('categories', 'name', 'categories_name_index');
        $this->addIndex('categories', 'parent_id', 'categories_parent_id_index');
    }

    public function down(): void
    {
        $this->dropIndex('customers', 'customers_name_index');
        $this->dropIndex('customers', 'customers_customer_code_index');

        $this->dropIndex('suppliers', 'suppliers_contact_person_index');
        $this->dropIndex('suppliers', 'suppliers_email_index');
        $this->dropIndex('suppliers', 'suppliers_phone_index');
        $this->dropIndex('suppliers', 'suppliers_qid_cr_index');

        $this->dropIndex('inventory_items', 'inventory_items_item_code_index');
        $this->dropIndex('inventory_items', 'inventory_items_location_index');

        $this->dropIndex('categories', 'categories_name_index');
        $this->dropIndex('categories', 'categories_parent_id_index');
    }

    private function addIndex(string $table, string $column, string $index): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }
        if ($this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column, $index) {
            $table->index($column, $index);
        });
    }

    private function dropIndex(string $table, string $index): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($index) {
            $table->dropIndex($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $database = $connection->getDatabaseName();
            if (! $database) {
                return false;
            }

            $result = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $index]
            );

            return $result !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $index) {
                    return true;
                }
            }
        }

        return false;
    }
};
