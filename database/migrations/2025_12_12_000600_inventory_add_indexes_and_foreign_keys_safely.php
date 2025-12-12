<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // inventory_items indexes / fks
        if (Schema::hasTable('inventory_items')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                $table->index('category_id', 'inventory_items_category_id_index');
                $table->index('supplier_id', 'inventory_items_supplier_id_index');
                $table->index('status', 'inventory_items_status_index');
                if (! $this->hasIndex('inventory_items', 'inventory_items_name_index')) {
                    $table->index('name', 'inventory_items_name_index');
                }
            });

            try {
                if (Schema::hasTable('categories') && ! $this->hasForeign('inventory_items', 'inventory_items_category_id_foreign')) {
                    Schema::table('inventory_items', function (Blueprint $table) {
                        $table->foreign('category_id')->references('id')->on('categories')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
                // ignore if constraint already exists / fails
            }

            try {
                if (Schema::hasTable('suppliers') && ! $this->hasForeign('inventory_items', 'inventory_items_supplier_id_foreign')) {
                    Schema::table('inventory_items', function (Blueprint $table) {
                        $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // inventory_transactions indexes / fks
        if (Schema::hasTable('inventory_transactions')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                $table->index('item_id', 'inventory_transactions_item_id_index');
                $table->index('user_id', 'inventory_transactions_user_id_index');
                $table->index('transaction_type', 'inventory_transactions_type_index');
                $table->index('reference_type', 'inventory_transactions_reference_type_index');
                $table->index('transaction_date', 'inventory_transactions_date_index');
            });

            try {
                if (Schema::hasTable('inventory_items') && ! $this->hasForeign('inventory_transactions', 'inventory_transactions_item_id_foreign')) {
                    Schema::table('inventory_transactions', function (Blueprint $table) {
                        $table->foreign('item_id')->references('id')->on('inventory_items')->onDelete('cascade');
                    });
                }
            } catch (\Throwable $e) {
                // ignore
            }

            try {
                if (Schema::hasTable('users') && ! $this->hasForeign('inventory_transactions', 'inventory_transactions_user_id_foreign')) {
                    Schema::table('inventory_transactions', function (Blueprint $table) {
                        $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_transactions')) {
            Schema::table('inventory_transactions', function (Blueprint $table) {
                if ($this->hasForeign('inventory_transactions', 'inventory_transactions_item_id_foreign')) {
                    $table->dropForeign('inventory_transactions_item_id_foreign');
                }
                if ($this->hasForeign('inventory_transactions', 'inventory_transactions_user_id_foreign')) {
                    $table->dropForeign('inventory_transactions_user_id_foreign');
                }
                $table->dropIndex('inventory_transactions_item_id_index');
                $table->dropIndex('inventory_transactions_user_id_index');
                $table->dropIndex('inventory_transactions_type_index');
                $table->dropIndex('inventory_transactions_reference_type_index');
                $table->dropIndex('inventory_transactions_date_index');
            });
        }

        if (Schema::hasTable('inventory_items')) {
            Schema::table('inventory_items', function (Blueprint $table) {
                if ($this->hasForeign('inventory_items', 'inventory_items_category_id_foreign')) {
                    $table->dropForeign('inventory_items_category_id_foreign');
                }
                if ($this->hasForeign('inventory_items', 'inventory_items_supplier_id_foreign')) {
                    $table->dropForeign('inventory_items_supplier_id_foreign');
                }
                $table->dropIndex('inventory_items_category_id_index');
                $table->dropIndex('inventory_items_supplier_id_index');
                $table->dropIndex('inventory_items_status_index');
                if ($this->hasIndex('inventory_items', 'inventory_items_name_index')) {
                    $table->dropIndex('inventory_items_name_index');
                }
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
