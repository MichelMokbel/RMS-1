<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (! $this->hasIndex('purchase_orders', 'purchase_orders_supplier_id_index')) {
                    $table->index('supplier_id', 'purchase_orders_supplier_id_index');
                }
                if (! $this->hasIndex('purchase_orders', 'purchase_orders_status_index')) {
                    $table->index('status', 'purchase_orders_status_index');
                }
                if (! $this->hasIndex('purchase_orders', 'purchase_orders_order_date_index')) {
                    $table->index('order_date', 'purchase_orders_order_date_index');
                }
                if (! $this->hasIndex('purchase_orders', 'purchase_orders_created_by_index')) {
                    $table->index('created_by', 'purchase_orders_created_by_index');
                }
            });

            try {
                if (Schema::hasTable('suppliers') && ! $this->hasForeign('purchase_orders', 'purchase_orders_supplier_id_foreign')) {
                    Schema::table('purchase_orders', function (Blueprint $table) {
                        $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
                // ignore constraint issues
            }

            try {
                if (Schema::hasTable('users') && ! $this->hasForeign('purchase_orders', 'purchase_orders_created_by_foreign')) {
                    Schema::table('purchase_orders', function (Blueprint $table) {
                        $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if (Schema::hasTable('purchase_order_items')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                if (! $this->hasIndex('purchase_order_items', 'purchase_order_items_purchase_order_id_index')) {
                    $table->index('purchase_order_id', 'purchase_order_items_purchase_order_id_index');
                }
                if (! $this->hasIndex('purchase_order_items', 'purchase_order_items_item_id_index')) {
                    $table->index('item_id', 'purchase_order_items_item_id_index');
                }
            });

            try {
                if (Schema::hasTable('purchase_orders') && ! $this->hasForeign('purchase_order_items', 'purchase_order_items_purchase_order_id_foreign')) {
                    Schema::table('purchase_order_items', function (Blueprint $table) {
                        $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->onDelete('cascade');
                    });
                }
            } catch (\Throwable $e) {
                // ignore
            }

            try {
                if (Schema::hasTable('inventory_items') && ! $this->hasForeign('purchase_order_items', 'purchase_order_items_item_id_foreign')) {
                    Schema::table('purchase_order_items', function (Blueprint $table) {
                        $table->foreign('item_id')->references('id')->on('inventory_items')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('purchase_order_items')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                if ($this->hasForeign('purchase_order_items', 'purchase_order_items_purchase_order_id_foreign')) {
                    $table->dropForeign('purchase_order_items_purchase_order_id_foreign');
                }
                if ($this->hasForeign('purchase_order_items', 'purchase_order_items_item_id_foreign')) {
                    $table->dropForeign('purchase_order_items_item_id_foreign');
                }
                $table->dropIndex('purchase_order_items_purchase_order_id_index');
                $table->dropIndex('purchase_order_items_item_id_index');
            });
        }

        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if ($this->hasForeign('purchase_orders', 'purchase_orders_supplier_id_foreign')) {
                    $table->dropForeign('purchase_orders_supplier_id_foreign');
                }
                if ($this->hasForeign('purchase_orders', 'purchase_orders_created_by_foreign')) {
                    $table->dropForeign('purchase_orders_created_by_foreign');
                }
                $table->dropIndex('purchase_orders_supplier_id_index');
                $table->dropIndex('purchase_orders_status_index');
                $table->dropIndex('purchase_orders_order_date_index');
                $table->dropIndex('purchase_orders_created_by_index');
            });
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $rows = DB::select('SHOW INDEX FROM '.$table);
        foreach ($rows as $row) {
            if (isset($row->Key_name) && $row->Key_name === $index) {
                return true;
            }
        }
        return false;
    }

    private function hasForeign(string $table, string $foreign): bool
    {
        $rows = DB::select('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?', [$table, 'FOREIGN KEY']);
        foreach ($rows as $row) {
            if (isset($row->CONSTRAINT_NAME) && $row->CONSTRAINT_NAME === $foreign) {
                return true;
            }
        }
        return false;
    }
};
