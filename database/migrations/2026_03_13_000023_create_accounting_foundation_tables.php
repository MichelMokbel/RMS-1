<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createAccountingCompanies();
        $this->createDepartments();
        $this->createFiscalYears();
        $this->createAccountingPeriods();
        $this->createPeriodLocks();
        $this->createClosingChecklists();
        $this->createBankAccounts();
        $this->createBankStatementImports();
        $this->createBankReconciliationRuns();
        $this->createBankTransactions();
        $this->createJournalEntries();
        $this->createJournalEntryLines();
        $this->createBudgetVersions();
        $this->createBudgetLines();
        $this->createJobs();
        $this->createJobPhases();
        $this->createJobCostCodes();
        $this->createJobBudgets();
        $this->createJobTransactions();
        $this->createRecurringBillTemplates();
        $this->createPurchaseOrderChangeOrders();
        $this->createWorkflowDefinitions();
        $this->createAccountingAuditLogs();
        $this->extendExistingTables();
        $this->seedDefaultAccountingRecords();
        $this->backfillExistingDimensions();
    }

    public function down(): void
    {
        $tables = [
            'accounting_audit_logs',
            'workflow_definitions',
            'purchase_order_change_orders',
            'recurring_bill_templates',
            'accounting_job_transactions',
            'accounting_job_budgets',
            'accounting_job_cost_codes',
            'accounting_job_phases',
            'accounting_jobs',
            'budget_lines',
            'budget_versions',
            'journal_entry_lines',
            'journal_entries',
            'bank_transactions',
            'bank_reconciliation_runs',
            'bank_statement_imports',
            'bank_accounts',
            'closing_checklists',
            'period_locks',
            'accounting_periods',
            'fiscal_years',
            'departments',
            'accounting_companies',
        ];

        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function createAccountingCompanies(): void
    {
        if (Schema::hasTable('accounting_companies')) {
            return;
        }

        Schema::create('accounting_companies', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 150);
            $table->string('code', 50)->unique();
            $table->string('base_currency', 10)->default('QAR');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('parent_company_id')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_default']);
        });
    }

    private function createDepartments(): void
    {
        if (Schema::hasTable('departments')) {
            return;
        }

        Schema::create('departments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('name', 120);
            $table->string('code', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
    }

    private function createFiscalYears(): void
    {
        if (Schema::hasTable('fiscal_years')) {
            return;
        }

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('name', 60);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open');
            $table->timestamps();

            $table->unique(['company_id', 'start_date', 'end_date']);
            $table->index(['company_id', 'status']);
        });
    }

    private function createAccountingPeriods(): void
    {
        if (Schema::hasTable('accounting_periods')) {
            return;
        }

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('fiscal_year_id');
            $table->string('name', 60);
            $table->integer('period_number');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'period_number'], 'acct_periods_company_year_period_unique');
            $table->index(['company_id', 'status']);
        });
    }

    private function createPeriodLocks(): void
    {
        if (Schema::hasTable('period_locks')) {
            return;
        }

        Schema::create('period_locks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('period_id')->nullable();
            $table->string('lock_type', 30)->default('soft');
            $table->string('module', 50)->default('all');
            $table->text('reason')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'module']);
        });
    }

    private function createClosingChecklists(): void
    {
        if (Schema::hasTable('closing_checklists')) {
            return;
        }

        Schema::create('closing_checklists', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('period_id');
            $table->string('task_name', 150);
            $table->string('status', 20)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'period_id', 'status']);
        });
    }

    private function createBankAccounts(): void
    {
        if (Schema::hasTable('bank_accounts')) {
            return;
        }

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('ledger_account_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name', 120);
            $table->string('code', 50);
            $table->string('account_type', 30)->default('checking');
            $table->string('bank_name', 120)->nullable();
            $table->string('account_number_last4', 8)->nullable();
            $table->string('currency_code', 10)->default('QAR');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_default', 'is_active']);
        });
    }

    private function createBankStatementImports(): void
    {
        if (Schema::hasTable('bank_statement_imports')) {
            return;
        }

        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('company_id');
            $table->string('file_name', 255);
            $table->string('storage_path', 255)->nullable();
            $table->integer('imported_rows')->default(0);
            $table->string('status', 20)->default('uploaded');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'status']);
        });
    }

    private function createBankReconciliationRuns(): void
    {
        if (Schema::hasTable('bank_reconciliation_runs')) {
            return;
        }

        Schema::create('bank_reconciliation_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('period_id')->nullable();
            $table->date('statement_date');
            $table->decimal('statement_ending_balance', 14, 2)->default(0);
            $table->decimal('book_ending_balance', 14, 2)->default(0);
            $table->decimal('variance_amount', 14, 2)->default(0);
            $table->string('status', 20)->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'statement_date']);
        });
    }

    private function createBankTransactions(): void
    {
        if (Schema::hasTable('bank_transactions')) {
            return;
        }

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('bank_account_id');
            $table->unsignedBigInteger('period_id')->nullable();
            $table->unsignedBigInteger('reconciliation_run_id')->nullable();
            $table->string('transaction_type', 30);
            $table->date('transaction_date');
            $table->decimal('amount', 14, 2);
            $table->string('direction', 10)->default('outflow');
            $table->string('status', 20)->default('open');
            $table->boolean('is_cleared')->default(false);
            $table->date('cleared_date')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('memo', 255)->nullable();
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('statement_import_id')->nullable();
            $table->timestamps();

            $table->index(['bank_account_id', 'transaction_date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    private function createJournalEntries(): void
    {
        if (Schema::hasTable('journal_entries')) {
            return;
        }

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('period_id')->nullable();
            $table->string('entry_number', 60);
            $table->string('entry_type', 30)->default('manual');
            $table->date('entry_date');
            $table->string('status', 20)->default('draft');
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('memo')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'entry_number']);
            $table->index(['company_id', 'entry_date', 'status']);
        });
    }

    private function createJournalEntryLines(): void
    {
        if (Schema::hasTable('journal_entry_lines')) {
            return;
        }

        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->index(['journal_entry_id', 'account_id']);
        });
    }

    private function createBudgetVersions(): void
    {
        if (Schema::hasTable('budget_versions')) {
            return;
        }

        Schema::create('budget_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('fiscal_year_id');
            $table->string('name', 100);
            $table->string('status', 20)->default('draft');
            $table->boolean('is_active')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'fiscal_year_id', 'name'], 'budget_versions_company_year_name_uq');
        });
    }

    private function createBudgetLines(): void
    {
        if (Schema::hasTable('budget_lines')) {
            return;
        }

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('budget_version_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->integer('period_number');
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['budget_version_id', 'period_number']);
        });
    }

    private function createJobs(): void
    {
        if (Schema::hasTable('accounting_jobs')) {
            return;
        }

        Schema::create('accounting_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name', 150);
            $table->string('code', 50);
            $table->string('status', 20)->default('active');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('estimated_revenue', 14, 2)->default(0);
            $table->decimal('estimated_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
        });
    }

    private function createJobPhases(): void
    {
        if (Schema::hasTable('accounting_job_phases')) {
            return;
        }

        Schema::create('accounting_job_phases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('job_id');
            $table->string('name', 120);
            $table->string('code', 50);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['job_id', 'code']);
        });
    }

    private function createJobCostCodes(): void
    {
        if (Schema::hasTable('accounting_job_cost_codes')) {
            return;
        }

        Schema::create('accounting_job_cost_codes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('name', 120);
            $table->string('code', 50);
            $table->unsignedBigInteger('default_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
    }

    private function createJobBudgets(): void
    {
        if (Schema::hasTable('accounting_job_budgets')) {
            return;
        }

        Schema::create('accounting_job_budgets', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('job_id');
            $table->unsignedBigInteger('job_phase_id')->nullable();
            $table->unsignedBigInteger('job_cost_code_id')->nullable();
            $table->decimal('budget_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['job_id', 'job_phase_id', 'job_cost_code_id'], 'job_budgets_lookup_idx');
        });
    }

    private function createJobTransactions(): void
    {
        if (Schema::hasTable('accounting_job_transactions')) {
            return;
        }

        Schema::create('accounting_job_transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('job_id');
            $table->unsignedBigInteger('job_phase_id')->nullable();
            $table->unsignedBigInteger('job_cost_code_id')->nullable();
            $table->unsignedBigInteger('company_id');
            $table->date('transaction_date');
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('transaction_type', 30)->default('cost');
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->index(['job_id', 'transaction_date']);
            $table->index(['source_type', 'source_id']);
        });
    }

    private function createRecurringBillTemplates(): void
    {
        if (Schema::hasTable('recurring_bill_templates')) {
            return;
        }

        Schema::create('recurring_bill_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('job_id')->nullable();
            $table->string('name', 120);
            $table->string('document_type', 40)->default('vendor_bill');
            $table->string('frequency', 30)->default('monthly');
            $table->decimal('default_amount', 14, 2)->default(0);
            $table->date('next_run_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('line_template')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_active', 'next_run_date'], 'rec_bill_templates_run_idx');
        });
    }

    private function createPurchaseOrderChangeOrders(): void
    {
        if (Schema::hasTable('purchase_order_change_orders')) {
            return;
        }

        Schema::create('purchase_order_change_orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_order_id');
            $table->unsignedBigInteger('company_id');
            $table->integer('revision_number')->default(1);
            $table->string('status', 20)->default('draft');
            $table->text('change_summary')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['purchase_order_id', 'revision_number'], 'po_change_orders_revision_idx');
        });
    }

    private function createWorkflowDefinitions(): void
    {
        if (Schema::hasTable('workflow_definitions')) {
            return;
        }

        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id');
            $table->string('workflow_type', 50);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'workflow_type', 'is_active'], 'workflow_defs_company_type_idx');
        });
    }

    private function createAccountingAuditLogs(): void
    {
        if (Schema::hasTable('accounting_audit_logs')) {
            return;
        }

        Schema::create('accounting_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'action']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    private function extendExistingTables(): void
    {
        if (Schema::hasTable('branches')) {
            Schema::table('branches', function (Blueprint $table) {
                if (! Schema::hasColumn('branches', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('id');
                }
            });
        }

        if (Schema::hasTable('suppliers')) {
            Schema::table('suppliers', function (Blueprint $table) {
                if (! Schema::hasColumn('suppliers', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('suppliers', 'payment_term_id')) {
                    $table->unsignedBigInteger('payment_term_id')->nullable()->after('status');
                }
                if (! Schema::hasColumn('suppliers', 'default_expense_account_id')) {
                    $table->unsignedBigInteger('default_expense_account_id')->nullable()->after('payment_term_id');
                }
                if (! Schema::hasColumn('suppliers', 'preferred_payment_method')) {
                    $table->string('preferred_payment_method', 30)->nullable()->after('default_expense_account_id');
                }
                if (! Schema::hasColumn('suppliers', 'hold_status')) {
                    $table->string('hold_status', 20)->default('open')->after('preferred_payment_method');
                }
                if (! Schema::hasColumn('suppliers', 'requires_1099')) {
                    $table->boolean('requires_1099')->default(false)->after('hold_status');
                }
                if (! Schema::hasColumn('suppliers', 'approval_threshold')) {
                    $table->decimal('approval_threshold', 14, 2)->nullable()->after('requires_1099');
                }
            });
        }

        if (Schema::hasTable('ledger_accounts')) {
            Schema::table('ledger_accounts', function (Blueprint $table) {
                if (! Schema::hasColumn('ledger_accounts', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('ledger_accounts', 'parent_account_id')) {
                    $table->unsignedBigInteger('parent_account_id')->nullable()->after('code');
                }
                if (! Schema::hasColumn('ledger_accounts', 'account_class')) {
                    $table->string('account_class', 30)->nullable()->after('type');
                }
                if (! Schema::hasColumn('ledger_accounts', 'detail_type')) {
                    $table->string('detail_type', 50)->nullable()->after('account_class');
                }
                if (! Schema::hasColumn('ledger_accounts', 'default_tax_code')) {
                    $table->string('default_tax_code', 30)->nullable()->after('detail_type');
                }
                if (! Schema::hasColumn('ledger_accounts', 'allow_direct_posting')) {
                    $table->boolean('allow_direct_posting')->default(true)->after('is_active');
                }
            });
        }

        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_orders', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('purchase_orders', 'department_id')) {
                    $table->unsignedBigInteger('department_id')->nullable()->after('supplier_id');
                }
                if (! Schema::hasColumn('purchase_orders', 'job_id')) {
                    $table->unsignedBigInteger('job_id')->nullable()->after('department_id');
                }
                if (! Schema::hasColumn('purchase_orders', 'matching_policy')) {
                    $table->string('matching_policy', 20)->default('2_way')->after('payment_type');
                }
                if (! Schema::hasColumn('purchase_orders', 'workflow_state')) {
                    $table->string('workflow_state', 30)->default('draft')->after('matching_policy');
                }
                if (! Schema::hasColumn('purchase_orders', 'approved_at')) {
                    $table->timestamp('approved_at')->nullable()->after('received_date');
                }
                if (! Schema::hasColumn('purchase_orders', 'approved_by')) {
                    $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
                }
                if (! Schema::hasColumn('purchase_orders', 'closed_at')) {
                    $table->timestamp('closed_at')->nullable()->after('approved_by');
                }
                if (! Schema::hasColumn('purchase_orders', 'closed_by')) {
                    $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
                }
            });
        }

        if (Schema::hasTable('ap_invoices')) {
            Schema::table('ap_invoices', function (Blueprint $table) {
                if (! Schema::hasColumn('ap_invoices', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('ap_invoices', 'branch_id')) {
                    $table->unsignedBigInteger('branch_id')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('ap_invoices', 'department_id')) {
                    $table->unsignedBigInteger('department_id')->nullable()->after('branch_id');
                }
                if (! Schema::hasColumn('ap_invoices', 'job_id')) {
                    $table->unsignedBigInteger('job_id')->nullable()->after('department_id');
                }
                if (! Schema::hasColumn('ap_invoices', 'period_id')) {
                    $table->unsignedBigInteger('period_id')->nullable()->after('job_id');
                }
                if (! Schema::hasColumn('ap_invoices', 'document_type')) {
                    $table->string('document_type', 40)->default('vendor_bill')->after('is_expense');
                }
                if (! Schema::hasColumn('ap_invoices', 'currency_code')) {
                    $table->string('currency_code', 10)->default('QAR')->after('document_type');
                }
                if (! Schema::hasColumn('ap_invoices', 'source_document_type')) {
                    $table->string('source_document_type', 50)->nullable()->after('currency_code');
                }
                if (! Schema::hasColumn('ap_invoices', 'source_document_id')) {
                    $table->unsignedBigInteger('source_document_id')->nullable()->after('source_document_type');
                }
                if (! Schema::hasColumn('ap_invoices', 'recurring_template_id')) {
                    $table->unsignedBigInteger('recurring_template_id')->nullable()->after('source_document_id');
                }
            });
        }

        if (Schema::hasTable('ap_payments')) {
            Schema::table('ap_payments', function (Blueprint $table) {
                if (! Schema::hasColumn('ap_payments', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('supplier_id');
                }
                if (! Schema::hasColumn('ap_payments', 'bank_account_id')) {
                    $table->unsignedBigInteger('bank_account_id')->nullable()->after('company_id');
                }
                if (! Schema::hasColumn('ap_payments', 'branch_id')) {
                    $table->unsignedBigInteger('branch_id')->nullable()->after('bank_account_id');
                }
                if (! Schema::hasColumn('ap_payments', 'department_id')) {
                    $table->unsignedBigInteger('department_id')->nullable()->after('branch_id');
                }
                if (! Schema::hasColumn('ap_payments', 'job_id')) {
                    $table->unsignedBigInteger('job_id')->nullable()->after('department_id');
                }
                if (! Schema::hasColumn('ap_payments', 'period_id')) {
                    $table->unsignedBigInteger('period_id')->nullable()->after('job_id');
                }
                if (! Schema::hasColumn('ap_payments', 'currency_code')) {
                    $table->string('currency_code', 10)->default('QAR')->after('payment_method');
                }
            });
        }

        if (Schema::hasTable('subledger_entries')) {
            Schema::table('subledger_entries', function (Blueprint $table) {
                if (! Schema::hasColumn('subledger_entries', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('source_id');
                }
                if (! Schema::hasColumn('subledger_entries', 'department_id')) {
                    $table->unsignedBigInteger('department_id')->nullable()->after('branch_id');
                }
                if (! Schema::hasColumn('subledger_entries', 'job_id')) {
                    $table->unsignedBigInteger('job_id')->nullable()->after('department_id');
                }
                if (! Schema::hasColumn('subledger_entries', 'period_id')) {
                    $table->unsignedBigInteger('period_id')->nullable()->after('job_id');
                }
                if (! Schema::hasColumn('subledger_entries', 'currency_code')) {
                    $table->string('currency_code', 10)->default('QAR')->after('period_id');
                }
                if (! Schema::hasColumn('subledger_entries', 'source_document_type')) {
                    $table->string('source_document_type', 50)->nullable()->after('description');
                }
                if (! Schema::hasColumn('subledger_entries', 'source_document_id')) {
                    $table->unsignedBigInteger('source_document_id')->nullable()->after('source_document_type');
                }
            });
        }

        if (Schema::hasTable('gl_batches')) {
            Schema::table('gl_batches', function (Blueprint $table) {
                if (! Schema::hasColumn('gl_batches', 'company_id')) {
                    $table->unsignedBigInteger('company_id')->nullable()->after('id');
                }
                if (! Schema::hasColumn('gl_batches', 'period_id')) {
                    $table->unsignedBigInteger('period_id')->nullable()->after('company_id');
                }
            });
        }

        if (Schema::hasTable('finance_settings')) {
            Schema::table('finance_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('finance_settings', 'default_company_id')) {
                    $table->unsignedBigInteger('default_company_id')->nullable()->after('lock_date');
                }
                if (! Schema::hasColumn('finance_settings', 'default_bank_account_id')) {
                    $table->unsignedBigInteger('default_bank_account_id')->nullable()->after('default_company_id');
                }
            });
        }
    }

    private function seedDefaultAccountingRecords(): void
    {
        if (! Schema::hasTable('accounting_companies')) {
            return;
        }

        $defaultCurrency = (string) config('pos.currency', 'QAR');
        $companyId = DB::table('accounting_companies')->where('is_default', 1)->value('id');
        if (! $companyId) {
            $companyId = DB::table('accounting_companies')->insertGetId([
                'name' => 'Layla Kitchen',
                'code' => 'LAYLA',
                'base_currency' => $defaultCurrency !== '' ? $defaultCurrency : 'QAR',
                'is_active' => 1,
                'is_default' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('departments')) {
            $exists = DB::table('departments')
                ->where('company_id', $companyId)
                ->where('code', 'GENERAL')
                ->exists();

            if (! $exists) {
                DB::table('departments')->insert([
                    'company_id' => $companyId,
                    'name' => 'General',
                    'code' => 'GENERAL',
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('fiscal_years') && Schema::hasTable('accounting_periods')) {
            foreach ([now()->year, now()->addYear()->year] as $year) {
                $fyId = DB::table('fiscal_years')
                    ->where('company_id', $companyId)
                    ->where('start_date', Carbon::create($year, 1, 1)->toDateString())
                    ->value('id');

                if (! $fyId) {
                    $fyId = DB::table('fiscal_years')->insertGetId([
                        'company_id' => $companyId,
                        'name' => 'FY '.$year,
                        'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                        'end_date' => Carbon::create($year, 12, 31)->toDateString(),
                        'status' => 'open',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                for ($month = 1; $month <= 12; $month++) {
                    $start = Carbon::create($year, $month, 1)->startOfMonth();
                    $end = $start->copy()->endOfMonth();

                    $exists = DB::table('accounting_periods')
                        ->where('company_id', $companyId)
                        ->where('fiscal_year_id', $fyId)
                        ->where('period_number', $month)
                        ->exists();

                    if (! $exists) {
                        DB::table('accounting_periods')->insert([
                            'company_id' => $companyId,
                            'fiscal_year_id' => $fyId,
                            'name' => $start->format('M Y'),
                            'period_number' => $month,
                            'start_date' => $start->toDateString(),
                            'end_date' => $end->toDateString(),
                            'status' => 'open',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }

        if (Schema::hasTable('bank_accounts')) {
            $ledgerAccountId = Schema::hasTable('ledger_accounts')
                ? DB::table('ledger_accounts')->where('code', '1000')->value('id')
                : null;

            $defaultBankId = DB::table('bank_accounts')
                ->where('company_id', $companyId)
                ->where('code', 'OPERATING')
                ->value('id');

            if (! $defaultBankId) {
                $defaultBankId = DB::table('bank_accounts')->insertGetId([
                    'company_id' => $companyId,
                    'ledger_account_id' => $ledgerAccountId,
                    'branch_id' => null,
                    'name' => 'Operating Account',
                    'code' => 'OPERATING',
                    'account_type' => 'checking',
                    'bank_name' => 'Primary Bank',
                    'account_number_last4' => '0000',
                    'currency_code' => $defaultCurrency !== '' ? $defaultCurrency : 'QAR',
                    'is_default' => 1,
                    'is_active' => 1,
                    'opening_balance' => 0,
                    'opening_balance_date' => now()->startOfYear()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('finance_settings')) {
                DB::table('finance_settings')
                    ->where('id', 1)
                    ->update([
                        'default_company_id' => $companyId,
                        'default_bank_account_id' => $defaultBankId,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    private function backfillExistingDimensions(): void
    {
        if (! Schema::hasTable('accounting_companies')) {
            return;
        }

        $companyId = (int) (DB::table('accounting_companies')->where('is_default', 1)->value('id') ?? 0);
        if ($companyId <= 0) {
            return;
        }

        $departmentId = Schema::hasTable('departments')
            ? (int) (DB::table('departments')->where('company_id', $companyId)->where('code', 'GENERAL')->value('id') ?? 0)
            : 0;

        $currentPeriodId = Schema::hasTable('accounting_periods')
            ? (int) (DB::table('accounting_periods')
                ->where('company_id', $companyId)
                ->whereDate('start_date', '<=', now()->toDateString())
                ->whereDate('end_date', '>=', now()->toDateString())
                ->value('id') ?? 0)
            : 0;

        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'company_id')) {
            DB::table('branches')->whereNull('company_id')->update(['company_id' => $companyId]);
        }

        if (Schema::hasTable('suppliers')) {
            $updates = ['company_id' => $companyId];
            if ($departmentId > 0 && Schema::hasColumn('suppliers', 'approval_threshold')) {
                // keep as-is; only company backfill is required here
            }
            DB::table('suppliers')->whereNull('company_id')->update($updates);
        }

        if (Schema::hasTable('ledger_accounts')) {
            DB::table('ledger_accounts')->whereNull('company_id')->update([
                'company_id' => $companyId,
                'account_class' => DB::raw("COALESCE(account_class, type)"),
            ]);
        }

        if (Schema::hasTable('purchase_orders')) {
            $payload = ['company_id' => $companyId];
            if (Schema::hasColumn('purchase_orders', 'department_id') && $departmentId > 0) {
                $payload['department_id'] = DB::raw('COALESCE(department_id, '.$departmentId.')');
            }
            DB::table('purchase_orders')->whereNull('company_id')->update($payload);
        }

        if (Schema::hasTable('ap_invoices')) {
            DB::table('ap_invoices')->update([
                'company_id' => DB::raw('COALESCE(company_id, '.$companyId.')'),
                'department_id' => Schema::hasColumn('ap_invoices', 'department_id') && $departmentId > 0
                    ? DB::raw('COALESCE(department_id, '.$departmentId.')')
                    : DB::raw('department_id'),
                'period_id' => Schema::hasColumn('ap_invoices', 'period_id') && $currentPeriodId > 0
                    ? DB::raw('COALESCE(period_id, '.$currentPeriodId.')')
                    : DB::raw('period_id'),
                'document_type' => DB::raw("CASE WHEN is_expense = 1 THEN 'expense' ELSE 'vendor_bill' END"),
            ]);
        }

        if (Schema::hasTable('ap_payments')) {
            $defaultBankAccountId = (int) (Schema::hasTable('finance_settings')
                ? (DB::table('finance_settings')->where('id', 1)->value('default_bank_account_id') ?? 0)
                : 0);

            DB::table('ap_payments')->update([
                'company_id' => DB::raw('COALESCE(company_id, '.$companyId.')'),
                'department_id' => Schema::hasColumn('ap_payments', 'department_id') && $departmentId > 0
                    ? DB::raw('COALESCE(department_id, '.$departmentId.')')
                    : DB::raw('department_id'),
                'period_id' => Schema::hasColumn('ap_payments', 'period_id') && $currentPeriodId > 0
                    ? DB::raw('COALESCE(period_id, '.$currentPeriodId.')')
                    : DB::raw('period_id'),
                'bank_account_id' => $defaultBankAccountId > 0
                    ? DB::raw('COALESCE(bank_account_id, '.$defaultBankAccountId.')')
                    : DB::raw('bank_account_id'),
            ]);
        }

        if (Schema::hasTable('subledger_entries')) {
            DB::table('subledger_entries')->update([
                'company_id' => DB::raw('COALESCE(company_id, '.$companyId.')'),
                'department_id' => Schema::hasColumn('subledger_entries', 'department_id') && $departmentId > 0
                    ? DB::raw('COALESCE(department_id, '.$departmentId.')')
                    : DB::raw('department_id'),
                'period_id' => Schema::hasColumn('subledger_entries', 'period_id') && $currentPeriodId > 0
                    ? DB::raw('COALESCE(period_id, '.$currentPeriodId.')')
                    : DB::raw('period_id'),
            ]);
        }

        if (Schema::hasTable('gl_batches')) {
            DB::table('gl_batches')->whereNull('company_id')->update([
                'company_id' => $companyId,
                'period_id' => $currentPeriodId > 0 ? $currentPeriodId : null,
            ]);
        }
    }
};
