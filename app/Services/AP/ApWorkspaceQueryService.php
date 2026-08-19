<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApPayment;
use Illuminate\Database\Eloquent\Builder;

class ApWorkspaceQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function documents(array $filters): Builder
    {
        $query = ApInvoice::query()
            ->with(['supplier', 'category', 'expenseProfile.wallet', 'period', 'allocations.payment'])
            ->withSum('allocations as paid_sum', 'allocated_amount');

        $query
            ->when($filters['supplier_id'] ?? null, fn (Builder $q, $value) => $q->where('supplier_id', $value))
            ->when($filters['branch_id'] ?? null, fn (Builder $q, $value) => $q->where('branch_id', $value))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $value) => $q->where('department_id', $value))
            ->when($filters['job_id'] ?? null, fn (Builder $q, $value) => $q->where('job_id', $value))
            ->when($filters['date_from'] ?? null, fn (Builder $q, $value) => $q->whereDate('invoice_date', '>=', $value))
            ->when($filters['date_to'] ?? null, fn (Builder $q, $value) => $q->whereDate('invoice_date', '<=', $value))
            ->when(trim((string) ($filters['search'] ?? '')), function (Builder $q, string $value) {
                $search = '%'.trim($value).'%';
                $q->where(function (Builder $sub) use ($search) {
                    $sub->where('invoice_number', 'like', $search)
                        ->orWhere('reference_number', 'like', $search)
                        ->orWhere('notes', 'like', $search)
                        ->orWhereHas('supplier', fn (Builder $supplier) => $supplier->where('name', 'like', $search));
                });
            })
            ->when(($filters['document_type'] ?? 'all') !== 'all', fn (Builder $q) => $q->where('document_type', $filters['document_type']))
            ->when(($filters['approval_status'] ?? 'all') !== 'all', fn (Builder $q) => $q->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', $filters['approval_status'])))
            ->when(($filters['expense_channel'] ?? 'all') !== 'all', fn (Builder $q) => $q->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('channel', $filters['expense_channel'])));

        $this->applyTabFilter($query, (string) ($filters['tab'] ?? 'all'));
        $this->applyWorkflowStateFilter($query, (string) ($filters['workflow_state'] ?? 'all'));
        $this->applyPaymentStateFilter($query, (string) ($filters['payment_state'] ?? 'all'));

        return $query->orderByDesc('invoice_date')->orderByDesc('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function payments(array $filters): Builder
    {
        return ApPayment::query()
            ->with(['supplier', 'company'])
            ->withSum('allocations as alloc_sum', 'allocated_amount')
            ->when($filters['payment_supplier_id'] ?? null, fn (Builder $q, $value) => $q->where('supplier_id', $value))
            ->when($filters['payment_method'] ?? null, fn (Builder $q, $value) => $q->where('payment_method', $value))
            ->when($filters['payment_date_from'] ?? null, fn (Builder $q, $value) => $q->whereDate('payment_date', '>=', $value))
            ->when($filters['payment_date_to'] ?? null, fn (Builder $q, $value) => $q->whereDate('payment_date', '<=', $value))
            ->orderByDesc('payment_date')
            ->orderByDesc('id');
    }

    private function applyTabFilter(Builder $query, string $tab): void
    {
        match ($tab) {
            'bills' => $query->where('is_expense', false),
            'expenses' => $query->where('document_type', 'expense'),
            'reimbursements' => $query->where('document_type', 'reimbursement'),
            'approvals' => $query->where('is_expense', true)
                ->whereHas('expenseProfile', fn (Builder $profile) => $profile->whereIn('approval_status', ['draft', 'submitted', 'manager_approved', 'approved'])),
            default => null,
        };
    }

    private function applyWorkflowStateFilter(Builder $query, string $state): void
    {
        match ($state) {
            'draft' => $query->where('status', 'draft')->where(function (Builder $sub) {
                $sub->where('is_expense', false)
                    ->orWhereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'draft'));
            }),
            'submitted' => $query->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'submitted')),
            'manager_approved' => $query->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'manager_approved')),
            'approved_pending_post' => $query->where('is_expense', true)->where('status', 'draft')
                ->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'approved')),
            'posted' => $query->where('status', 'posted'),
            'posted_pending_settlement' => $query->where('is_expense', true)
                ->whereIn('status', ['posted', 'partially_paid'])
                ->whereHas('expenseProfile', fn (Builder $profile) => $profile->whereNull('settled_at')),
            'partially_paid' => $query->where('status', 'partially_paid'),
            'closed' => $query->where('status', 'paid'),
            'rejected' => $query->whereHas('expenseProfile', fn (Builder $profile) => $profile->where('approval_status', 'rejected')),
            'void' => $query->where('status', 'void'),
            default => null,
        };
    }

    private function applyPaymentStateFilter(Builder $query, string $state): void
    {
        match ($state) {
            'pending' => $query->where('status', 'draft'),
            'open' => $query->where('status', 'posted'),
            'partially_paid' => $query->where('status', 'partially_paid'),
            'paid' => $query->where('status', 'paid'),
            'settled' => $query->where('is_expense', true)
                ->whereHas('expenseProfile', fn (Builder $profile) => $profile->whereNotNull('settled_at')),
            'void' => $query->where('status', 'void'),
            default => null,
        };
    }
}
