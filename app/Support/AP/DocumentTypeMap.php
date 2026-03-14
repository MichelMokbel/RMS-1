<?php

namespace App\Support\AP;

use App\Models\ApInvoice;
use Illuminate\Support\Str;

class DocumentTypeMap
{
    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'vendor_bill' => 'Vendor Bill',
            'expense' => 'Vendor Expense',
            'reimbursement' => 'Employee Reimbursement',
            'vendor_credit' => 'Vendor Credit',
            'debit_memo' => 'Debit Memo',
            'landed_cost_adjustment' => 'Landed Cost Adjustment',
            'recurring_bill' => 'Recurring Bill',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return array_keys(self::labels());
    }

    public static function normalizeDocumentType(?string $documentType): string
    {
        $documentType = (string) $documentType;

        return in_array($documentType, self::types(), true) ? $documentType : 'vendor_bill';
    }

    public static function normalizeExpenseChannel(string $documentType, ?string $expenseChannel): ?string
    {
        $documentType = self::normalizeDocumentType($documentType);
        $expenseChannel = $expenseChannel !== null ? (string) $expenseChannel : null;

        if ($documentType === 'reimbursement') {
            return 'reimbursement';
        }

        if ($documentType !== 'expense') {
            return null;
        }

        return in_array($expenseChannel, ['vendor', 'petty_cash'], true) ? $expenseChannel : 'vendor';
    }

    /**
     * @return array{is_expense: bool, expense_channel: ?string}
     */
    public static function derive(string $documentType, ?string $expenseChannel = null): array
    {
        $documentType = self::normalizeDocumentType($documentType);
        $normalizedChannel = self::normalizeExpenseChannel($documentType, $expenseChannel);

        return [
            'is_expense' => in_array($documentType, ['expense', 'reimbursement'], true),
            'expense_channel' => $normalizedChannel,
        ];
    }

    public static function isExpense(string $documentType): bool
    {
        return self::derive($documentType)['is_expense'];
    }

    public static function requiresCategory(string $documentType): bool
    {
        return in_array(self::normalizeDocumentType($documentType), ['expense', 'reimbursement'], true);
    }

    public static function requiresWallet(string $documentType, ?string $expenseChannel): bool
    {
        return self::normalizeDocumentType($documentType) === 'expense'
            && self::normalizeExpenseChannel($documentType, $expenseChannel) === 'petty_cash';
    }

    public static function label(?string $documentType): string
    {
        $documentType = self::normalizeDocumentType($documentType);

        return self::labels()[$documentType] ?? Str::headline(str_replace('_', ' ', $documentType));
    }

    public static function approvalStatus(ApInvoice $invoice): string
    {
        if (! $invoice->is_expense) {
            return 'n/a';
        }

        return (string) ($invoice->expenseProfile?->approval_status ?? 'draft');
    }

    public static function workflowState(ApInvoice $invoice): string
    {
        if ($invoice->status === 'void') {
            return 'void';
        }

        if (! $invoice->is_expense) {
            return match ($invoice->status) {
                'draft' => 'draft',
                'posted' => 'posted',
                'partially_paid' => 'partially_paid',
                'paid' => 'closed',
                default => $invoice->status,
            };
        }

        $approvalStatus = (string) ($invoice->expenseProfile?->approval_status ?? 'draft');

        if ($approvalStatus === 'rejected') {
            return 'rejected';
        }

        if (in_array($approvalStatus, ['draft', 'submitted', 'manager_approved'], true)) {
            return $approvalStatus;
        }

        if ($approvalStatus === 'approved' && $invoice->status === 'draft') {
            return 'approved_pending_post';
        }

        if (in_array($invoice->status, ['posted', 'partially_paid'], true) && ! $invoice->expenseProfile?->settled_at) {
            return 'posted_pending_settlement';
        }

        if ($invoice->status === 'paid') {
            return 'closed';
        }

        return $invoice->status;
    }

    public static function paymentState(ApInvoice $invoice): string
    {
        if ($invoice->status === 'paid') {
            return 'paid';
        }

        if ($invoice->status === 'partially_paid') {
            return 'partially_paid';
        }

        if ($invoice->is_expense && $invoice->expenseProfile?->settled_at) {
            return 'settled';
        }

        if (in_array($invoice->status, ['posted', 'partially_paid'], true)) {
            return 'open';
        }

        if ($invoice->status === 'void') {
            return 'void';
        }

        return 'pending';
    }
}
