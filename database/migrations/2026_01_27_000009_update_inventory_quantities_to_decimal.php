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

        if (Schema::hasTable('inventory_items')) {
            DB::statement('ALTER TABLE inventory_items MODIFY units_per_package DECIMAL(12,3) NOT NULL DEFAULT 1.000');
            DB::statement('ALTER TABLE inventory_items MODIFY minimum_stock DECIMAL(12,3) DEFAULT 0.000');
            if (Schema::hasColumn('inventory_items', 'current_stock')) {
                DB::statement('ALTER TABLE inventory_items MODIFY current_stock DECIMAL(12,3) DEFAULT 0.000');
            }
        }

        if (Schema::hasTable('inventory_transactions')) {
            DB::statement('ALTER TABLE inventory_transactions MODIFY quantity DECIMAL(12,3) NOT NULL');
        }

        if (Schema::hasTable('purchase_order_items')) {
            DB::statement('ALTER TABLE purchase_order_items MODIFY quantity DECIMAL(12,3) NOT NULL');
            DB::statement('ALTER TABLE purchase_order_items MODIFY received_quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (Schema::hasTable('inventory_items')) {
            DB::statement('ALTER TABLE inventory_items MODIFY units_per_package INT(11) NOT NULL DEFAULT 1');
            DB::statement('ALTER TABLE inventory_items MODIFY minimum_stock INT(11) DEFAULT 0');
            if (Schema::hasColumn('inventory_items', 'current_stock')) {
                DB::statement('ALTER TABLE inventory_items MODIFY current_stock INT(11) DEFAULT 0');
            }
        }

        if (Schema::hasTable('inventory_transactions')) {
            DB::statement('ALTER TABLE inventory_transactions MODIFY quantity INT(11) NOT NULL');
        }

        if (Schema::hasTable('purchase_order_items')) {
            DB::statement('ALTER TABLE purchase_order_items MODIFY quantity INT(11) NOT NULL');
            DB::statement('ALTER TABLE purchase_order_items MODIFY received_quantity INT(11) NOT NULL DEFAULT 0');
        }
    }
};
