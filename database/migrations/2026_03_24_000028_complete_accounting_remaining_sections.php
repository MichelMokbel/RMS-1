<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recurring_bill_templates')) {
            Schema::table('recurring_bill_templates', function (Blueprint $table) {
                if (! Schema::hasColumn('recurring_bill_templates', 'branch_id')) {
                    $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('recurring_bill_templates', 'start_date')) {
                    $table->date('start_date')->nullable()->after('frequency');
                }
                if (! Schema::hasColumn('recurring_bill_templates', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
                if (! Schema::hasColumn('recurring_bill_templates', 'due_day_offset')) {
                    $table->integer('due_day_offset')->default(30)->after('default_amount');
                }
                if (! Schema::hasColumn('recurring_bill_templates', 'last_run_date')) {
                    $table->date('last_run_date')->nullable()->after('next_run_date');
                }
                if (! Schema::hasColumn('recurring_bill_templates', 'notes')) {
                    $table->text('notes')->nullable()->after('line_template');
                }
            });
        }

        if (! Schema::hasTable('recurring_bill_template_lines')) {
            Schema::create('recurring_bill_template_lines', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('recurring_bill_template_id');
                $table->unsignedBigInteger('purchase_order_item_id')->nullable();
                $table->string('description', 255);
                $table->decimal('quantity', 14, 3)->default(1);
                $table->decimal('unit_price', 14, 4)->default(0);
                $table->decimal('line_total', 14, 2)->default(0);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index(['recurring_bill_template_id', 'sort_order'], 'rec_bill_template_lines_template_sort_idx');
            });
        }

        if (Schema::hasTable('ap_invoice_items')) {
            Schema::table('ap_invoice_items', function (Blueprint $table) {
                if (! Schema::hasColumn('ap_invoice_items', 'purchase_order_item_id')) {
                    $table->unsignedBigInteger('purchase_order_item_id')->nullable()->after('invoice_id');
                }
            });
        }

        if (! Schema::hasTable('purchase_order_invoice_matches')) {
            Schema::create('purchase_order_invoice_matches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('purchase_order_id');
                $table->unsignedBigInteger('purchase_order_item_id');
                $table->unsignedBigInteger('ap_invoice_id');
                $table->unsignedBigInteger('ap_invoice_item_id');
                $table->decimal('matched_quantity', 14, 3)->default(0);
                $table->decimal('matched_amount', 14, 2)->default(0);
                $table->decimal('received_value', 14, 2)->default(0);
                $table->decimal('invoiced_value', 14, 2)->default(0);
                $table->decimal('price_variance', 14, 2)->default(0);
                $table->date('receipt_date')->nullable();
                $table->date('invoice_date')->nullable();
                $table->string('status', 30)->default('matched');
                $table->boolean('override_applied')->default(false);
                $table->unsignedBigInteger('overridden_by')->nullable();
                $table->timestamp('overridden_at')->nullable();
                $table->text('override_reason')->nullable();
                $table->timestamps();

                $table->index(['purchase_order_id', 'purchase_order_item_id'], 'po_invoice_matches_po_item_idx');
                $table->index(['ap_invoice_id', 'ap_invoice_item_id'], 'po_invoice_matches_ap_item_idx');
            });
        }

        if (Schema::hasTable('finance_settings')) {
            Schema::table('finance_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('finance_settings', 'po_quantity_tolerance_percent')) {
                    $table->decimal('po_quantity_tolerance_percent', 8, 3)->default(0)->after('default_bank_account_id');
                }
                if (! Schema::hasColumn('finance_settings', 'po_price_tolerance_percent')) {
                    $table->decimal('po_price_tolerance_percent', 8, 3)->default(0)->after('po_quantity_tolerance_percent');
                }
                if (! Schema::hasColumn('finance_settings', 'purchase_price_variance_account_id')) {
                    $table->unsignedBigInteger('purchase_price_variance_account_id')->nullable()->after('po_price_tolerance_percent');
                }
            });
        }

        if (Schema::hasTable('ar_invoices')) {
            Schema::table('ar_invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('ar_invoices', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('branch_id');
                }
                if (! Schema::hasColumn('ar_invoices', 'job_id')) {
                    $table->unsignedBigInteger('job_id')->nullable()->after('company_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ar_invoices')) {
            Schema::table('ar_invoices', function (Blueprint $table) {
                foreach (['job_id', 'company_id'] as $column) {
                    if (Schema::hasColumn('ar_invoices', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('finance_settings')) {
            Schema::table('finance_settings', function (Blueprint $table) {
                foreach (['purchase_price_variance_account_id', 'po_price_tolerance_percent', 'po_quantity_tolerance_percent'] as $column) {
                    if (Schema::hasColumn('finance_settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Schema::dropIfExists('purchase_order_invoice_matches');

        if (Schema::hasTable('ap_invoice_items')) {
            Schema::table('ap_invoice_items', function (Blueprint $table) {
                if (Schema::hasColumn('ap_invoice_items', 'purchase_order_item_id')) {
                    $table->dropColumn('purchase_order_item_id');
                }
            });
        }

        Schema::dropIfExists('recurring_bill_template_lines');

        if (Schema::hasTable('recurring_bill_templates')) {
            Schema::table('recurring_bill_templates', function (Blueprint $table) {
                foreach (['notes', 'last_run_date', 'due_day_offset', 'end_date', 'start_date', 'branch_id'] as $column) {
                    if (Schema::hasColumn('recurring_bill_templates', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
