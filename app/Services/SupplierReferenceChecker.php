<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupplierReferenceChecker
{
    /**
    * Tables and foreign keys that may reference suppliers.
    *
    * @var array<int, array{table:string, column:string}>
    */
    protected array $references = [
        ['table' => 'purchase_orders', 'column' => 'supplier_id'],
        ['table' => 'ap_invoices', 'column' => 'supplier_id'],
        ['table' => 'ap_payments', 'column' => 'supplier_id'],
        ['table' => 'expenses', 'column' => 'supplier_id'],
        ['table' => 'petty_cash_issues', 'column' => 'supplier_id'],
        ['table' => 'petty_cash', 'column' => 'supplier_id'],
        ['table' => 'inventory_items', 'column' => 'supplier_id'],
    ];

    public function isSupplierReferenced(int $supplierId): bool
    {
        foreach ($this->references as $reference) {
            if (! Schema::hasTable($reference['table'])) {
                continue;
            }
            if (! Schema::hasColumn($reference['table'], $reference['column'])) {
                continue;
            }

            if (DB::table($reference['table'])->where($reference['column'], $supplierId)->exists()) {
                return true;
            }
        }

        return false;
    }
}
