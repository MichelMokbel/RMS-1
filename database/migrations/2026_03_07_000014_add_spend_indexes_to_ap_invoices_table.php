<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ap_invoices')) {
            return;
        }

        Schema::table('ap_invoices', function (Blueprint $table) {
            if (! $this->hasIndex('ap_invoices', 'ap_invoices_is_expense_invoice_date_index')) {
                $table->index(['is_expense', 'invoice_date'], 'ap_invoices_is_expense_invoice_date_index');
            }

            if (! $this->hasIndex('ap_invoices', 'ap_invoices_is_expense_status_index')) {
                $table->index(['is_expense', 'status'], 'ap_invoices_is_expense_status_index');
            }

            if (! $this->hasIndex('ap_invoices', 'ap_invoices_category_invoice_date_index')) {
                $table->index(['category_id', 'invoice_date'], 'ap_invoices_category_invoice_date_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ap_invoices')) {
            return;
        }

        Schema::table('ap_invoices', function (Blueprint $table) {
            if ($this->hasIndex('ap_invoices', 'ap_invoices_is_expense_invoice_date_index')) {
                $table->dropIndex('ap_invoices_is_expense_invoice_date_index');
            }

            if ($this->hasIndex('ap_invoices', 'ap_invoices_is_expense_status_index')) {
                $table->dropIndex('ap_invoices_is_expense_status_index');
            }

            if ($this->hasIndex('ap_invoices', 'ap_invoices_category_invoice_date_index')) {
                $table->dropIndex('ap_invoices_category_invoice_date_index');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $result = DB::select('SHOW INDEX FROM `'.$table.'` WHERE Key_name = ?', [$indexName]);

        return ! empty($result);
    }
};
