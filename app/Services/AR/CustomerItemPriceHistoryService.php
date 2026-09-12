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
        $scopeEligibleInvoices = function ($query, string $invoiceAlias) use ($branchId, $companyId, $customerId, $excludeInvoiceId) {
            return $query
                ->where("{$invoiceAlias}.branch_id", $branchId)
                ->where(function ($query) use ($invoiceAlias, $companyId) {
                    // Legacy invoices have no company; their branch still scopes them.
                    $query->whereNull("{$invoiceAlias}.company_id");
                    if ($companyId) {
                        $query->orWhere("{$invoiceAlias}.company_id", $companyId);
                    }
                })
                ->where("{$invoiceAlias}.customer_id", $customerId)
                ->where("{$invoiceAlias}.currency", (string) config('pos.currency'))
                ->where("{$invoiceAlias}.type", 'invoice')
                ->whereIn("{$invoiceAlias}.status", ['issued', 'partially_paid', 'paid'])
                ->whereNull("{$invoiceAlias}.voided_at")
                ->whereNotNull("{$invoiceAlias}.issue_date")
                ->when($excludeInvoiceId, fn ($query) => $query->where("{$invoiceAlias}.id", '<>', $excludeInvoiceId));
        };

        $history = DB::table('ar_invoice_items as item')
            ->join('ar_invoices as invoice', 'invoice.id', '=', 'item.invoice_id')
            ->where('item.sellable_type', MenuItem::class)
            ->whereIn('item.sellable_id', $itemIds)
            ->where('item.id', '=', function ($query) use ($scopeEligibleInvoices) {
                $query->select('candidate_item.id')
                    ->from('ar_invoice_items as candidate_item')
                    ->join('ar_invoices as candidate_invoice', 'candidate_invoice.id', '=', 'candidate_item.invoice_id')
                    ->whereColumn('candidate_item.sellable_type', 'item.sellable_type')
                    ->whereColumn('candidate_item.sellable_id', 'item.sellable_id');

                $scopeEligibleInvoices($query, 'candidate_invoice')
                    ->orderByDesc('candidate_invoice.issue_date')
                    ->orderByDesc('candidate_invoice.id')
                    ->orderByDesc('candidate_item.id')
                    ->limit(1);
            })
            ->select('item.sellable_id', 'item.unit_price_cents', 'item.unit', 'invoice.issue_date', 'invoice.currency');

        $scopeEligibleInvoices($history, 'invoice');

        return $history
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->sellable_id => [
                'unit_price_cents' => (int) $row->unit_price_cents,
                'unit' => $row->unit,
                'issue_date' => $row->issue_date,
                'currency' => $row->currency,
            ]])->all();
    }
}
