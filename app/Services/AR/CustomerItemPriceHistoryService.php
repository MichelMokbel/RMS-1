<?php

namespace App\Services\AR;

use App\Models\MenuItem;
use App\Models\User;
use App\Services\Accounting\AccountingContextService;
use App\Services\Security\BranchAccessService;
use Illuminate\Support\Facades\DB;

class CustomerItemPriceHistoryService
{
    public function __construct(
        private readonly BranchAccessService $branchAccess,
        private readonly AccountingContextService $context,
    ) {}

    /** Latest issued unit price per selected item, before discounts and tax. */
    public function latest(User $actor, int $branchId, int $customerId, array $itemIds, ?int $excludeInvoiceId = null): array
    {
        if (! $actor->isActive()
            || ! ($actor->hasAnyRole(['admin', 'manager']) || $actor->can('receivables.access'))
            || ! $this->branchAccess->canAccessBranch($actor, $branchId)
            || $customerId <= 0 || $itemIds === []) {
            return [];
        }

        $companyId = $this->context->resolveCompanyId($branchId);
        $ranked = DB::table('ar_invoice_items as item')
            ->join('ar_invoices as invoice', 'invoice.id', '=', 'item.invoice_id')
            ->where('invoice.branch_id', $branchId)
            ->where(function ($query) use ($companyId) {
                // Legacy invoices have no company; their branch still scopes them.
                $query->whereNull('invoice.company_id');
                if ($companyId) {
                    $query->orWhere('invoice.company_id', $companyId);
                }
            })
            ->where('invoice.customer_id', $customerId)
            ->where('invoice.currency', (string) config('pos.currency'))
            ->where('invoice.type', 'invoice')
            ->whereIn('invoice.status', ['issued', 'partially_paid', 'paid'])
            ->whereNull('invoice.voided_at')
            ->whereNotNull('invoice.issue_date')
            ->when($excludeInvoiceId, fn ($query) => $query->where('invoice.id', '<>', $excludeInvoiceId))
            ->where('item.sellable_type', MenuItem::class)
            ->whereIn('item.sellable_id', $itemIds)
            ->select('item.sellable_id', 'item.unit_price_cents', 'item.unit', 'invoice.issue_date', 'invoice.currency')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY item.sellable_id ORDER BY invoice.issue_date DESC, invoice.id DESC, item.id DESC) as price_rank');

        return DB::query()->fromSub($ranked, 'history')
            ->where('price_rank', 1)
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->sellable_id => [
                'unit_price_cents' => (int) $row->unit_price_cents,
                'unit' => $row->unit,
                'issue_date' => $row->issue_date,
                'currency' => $row->currency,
            ]])->all();
    }
}
