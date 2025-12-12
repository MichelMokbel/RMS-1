<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ap_invoices')) {
            Schema::table('ap_invoices', function (Blueprint $table) {
                $this->addIndex($table, 'ap_invoices_supplier_id_index', 'supplier_id');
                $this->addIndex($table, 'ap_invoices_status_index', 'status');
                $this->addIndex($table, 'ap_invoices_invoice_date_index', 'invoice_date');
                $this->addIndex($table, 'ap_invoices_due_date_index', 'due_date');
                $this->addIndex($table, 'ap_invoices_po_id_index', 'purchase_order_id');
                $this->addIndex($table, 'ap_invoices_category_id_index', 'category_id');
            });

            // Unique per supplier: invoice_number
            $hasDupes = false;
            try {
                $dupes = DB::table('ap_invoices')
                    ->select('supplier_id', 'invoice_number', DB::raw('COUNT(*) as c'))
                    ->groupBy('supplier_id', 'invoice_number')
                    ->havingRaw('COUNT(*) > 1')
                    ->limit(1)
                    ->get();
                $hasDupes = $dupes->count() > 0;
            } catch (\Throwable $e) {
                $hasDupes = true;
            }

            if (! $hasDupes && ! $this->hasIndex('ap_invoices', 'ap_invoices_supplier_invoice_unique')) {
                try {
                    Schema::table('ap_invoices', function (Blueprint $table) {
                        $table->unique(['supplier_id', 'invoice_number'], 'ap_invoices_supplier_invoice_unique');
                    });
                } catch (\Throwable $e) {
                    // skip if fails
                }
            }

            try {
                if (Schema::hasTable('suppliers') && ! $this->hasForeign('ap_invoices', 'ap_invoices_supplier_id_foreign')) {
                    Schema::table('ap_invoices', function (Blueprint $table) {
                        $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('restrict');
                    });
                }
            } catch (\Throwable $e) {
            }

            try {
                if (Schema::hasTable('purchase_orders') && ! $this->hasForeign('ap_invoices', 'ap_invoices_purchase_order_id_foreign')) {
                    Schema::table('ap_invoices', function (Blueprint $table) {
                        $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
            }

            try {
                if (Schema::hasTable('users') && ! $this->hasForeign('ap_invoices', 'ap_invoices_created_by_foreign')) {
                    Schema::table('ap_invoices', function (Blueprint $table) {
                        $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
            }

            try {
                if (Schema::hasTable('expense_categories') && ! $this->hasForeign('ap_invoices', 'ap_invoices_category_id_foreign')) {
                    Schema::table('ap_invoices', function (Blueprint $table) {
                        $table->foreign('category_id')->references('id')->on('expense_categories')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
            }
        }

        if (Schema::hasTable('ap_invoice_items')) {
            Schema::table('ap_invoice_items', function (Blueprint $table) {
                $this->addIndex($table, 'ap_invoice_items_invoice_id_index', 'invoice_id');
            });
            try {
                if (Schema::hasTable('ap_invoices') && ! $this->hasForeign('ap_invoice_items', 'ap_invoice_items_invoice_id_foreign')) {
                    Schema::table('ap_invoice_items', function (Blueprint $table) {
                        $table->foreign('invoice_id')->references('id')->on('ap_invoices')->onDelete('cascade');
                    });
                }
            } catch (\Throwable $e) {
            }
        }

        if (Schema::hasTable('ap_payments')) {
            Schema::table('ap_payments', function (Blueprint $table) {
                $this->addIndex($table, 'ap_payments_supplier_id_index', 'supplier_id');
                $this->addIndex($table, 'ap_payments_payment_date_index', 'payment_date');
                $this->addIndex($table, 'ap_payments_created_by_index', 'created_by');
            });
            try {
                if (Schema::hasTable('suppliers') && ! $this->hasForeign('ap_payments', 'ap_payments_supplier_id_foreign')) {
                    Schema::table('ap_payments', function (Blueprint $table) {
                        $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('restrict');
                    });
                }
            } catch (\Throwable $e) {
            }
            try {
                if (Schema::hasTable('users') && ! $this->hasForeign('ap_payments', 'ap_payments_created_by_foreign')) {
                    Schema::table('ap_payments', function (Blueprint $table) {
                        $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
                    });
                }
            } catch (\Throwable $e) {
            }
        }

        if (Schema::hasTable('ap_payment_allocations')) {
            Schema::table('ap_payment_allocations', function (Blueprint $table) {
                $this->addIndex($table, 'ap_payment_allocations_payment_id_index', 'payment_id');
                $this->addIndex($table, 'ap_payment_allocations_invoice_id_index', 'invoice_id');
            });

            try {
                if (Schema::hasTable('ap_payments') && ! $this->hasForeign('ap_payment_allocations', 'ap_payment_allocations_payment_id_foreign')) {
                    Schema::table('ap_payment_allocations', function (Blueprint $table) {
                        $table->foreign('payment_id')->references('id')->on('ap_payments')->onDelete('cascade');
                    });
                }
            } catch (\Throwable $e) {
            }

            try {
                if (Schema::hasTable('ap_invoices') && ! $this->hasForeign('ap_payment_allocations', 'ap_payment_allocations_invoice_id_foreign')) {
                    Schema::table('ap_payment_allocations', function (Blueprint $table) {
                        $table->foreign('invoice_id')->references('id')->on('ap_invoices')->onDelete('cascade');
                    });
                }
            } catch (\Throwable $e) {
            }
        }
    }

    public function down(): void
    {
        // Keep schema non-destructive
    }

    private function addIndex(Blueprint $table, string $name, string $column): void
    {
        if (! $this->hasIndex($table->getTable(), $name)) {
            $table->index($column, $name);
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
