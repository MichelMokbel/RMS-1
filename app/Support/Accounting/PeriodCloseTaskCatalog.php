<?php

namespace App\Support\Accounting;

class PeriodCloseTaskCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            ['key' => 'bank_accounts_reconciled', 'name' => 'All active bank accounts reconciled through period end', 'type' => 'system', 'required' => true],
            ['key' => 'no_open_bank_reconciliation_runs', 'name' => 'No open bank reconciliation runs for the period', 'type' => 'system', 'required' => true],
            ['key' => 'no_unposted_gl_batches', 'name' => 'No unposted GL batches for the period', 'type' => 'system', 'required' => true],
            ['key' => 'no_draft_manual_journals', 'name' => 'No draft manual journals dated in the period', 'type' => 'system', 'required' => true],
            ['key' => 'no_draft_ap_bills', 'name' => 'No draft AP bills dated in the period', 'type' => 'system', 'required' => true],
            ['key' => 'no_draft_expenses', 'name' => 'No draft expenses or reimbursements dated in the period', 'type' => 'system', 'required' => true],
            ['key' => 'no_pending_expense_approvals', 'name' => 'No submitted or partially approved expenses dated in the period', 'type' => 'system', 'required' => true],
            ['key' => 'no_ap_documents_missing_dimensions', 'name' => 'No AP documents with missing company or period assignment', 'type' => 'system', 'required' => true],
            ['key' => 'no_unbalanced_gl_batches', 'name' => 'No out-of-balance GL batches for the period', 'type' => 'system', 'required' => true],
            ['key' => 'ap_aging_reviewed', 'name' => 'AP aging reviewed', 'type' => 'manual', 'required' => true],
            ['key' => 'ar_aging_reviewed', 'name' => 'AR aging reviewed', 'type' => 'manual', 'required' => true],
            ['key' => 'inventory_valuation_reviewed', 'name' => 'Inventory valuation reviewed', 'type' => 'manual', 'required' => true],
            ['key' => 'purchase_accruals_reviewed', 'name' => 'Purchase accruals reviewed', 'type' => 'manual', 'required' => true],
            ['key' => 'payroll_journals_reviewed', 'name' => 'Payroll journals reviewed and posted', 'type' => 'manual', 'required' => true],
            ['key' => 'trial_balance_reviewed', 'name' => 'Trial balance reviewed', 'type' => 'manual', 'required' => true],
            ['key' => 'profit_and_loss_reviewed', 'name' => 'P&L reviewed', 'type' => 'manual', 'required' => true],
            ['key' => 'balance_sheet_reviewed', 'name' => 'Balance sheet reviewed', 'type' => 'manual', 'required' => true],
            ['key' => 'tax_review_completed', 'name' => 'Tax/VAT review completed', 'type' => 'manual', 'required' => true],
            ['key' => 'financial_statements_approved', 'name' => 'Financial statements approved', 'type' => 'manual', 'required' => true],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function keyed(): array
    {
        $definitions = [];

        foreach (self::definitions() as $definition) {
            $definitions[$definition['key']] = $definition;
        }

        return $definitions;
    }
}
