<?php

namespace App\Services\AP;

use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Models\FinanceSetting;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderInvoiceMatch;
use App\Models\PurchaseOrderItem;
use App\Services\Accounting\AccountingAuditLogService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderInvoiceMatchingService
{
    public function __construct(protected AccountingAuditLogService $auditLog)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluateInvoice(ApInvoice $invoice): array
    {
        $invoice->loadMissing(['items.purchaseOrderItem', 'purchaseOrder.items.item']);
        if (! $invoice->purchase_order_id || ! $invoice->purchaseOrder) {
            return [
                'overall_status' => 'not_applicable',
                'blocking' => false,
                'summary' => [
                    'matched_amount' => 0.0,
                    'received_value' => 0.0,
                    'invoiced_value' => round((float) ($invoice->subtotal ?? 0), 2),
                    'variance_amount' => 0.0,
                ],
                'lines' => [],
            ];
        }

        $settings = FinanceSetting::query()->find(1);
        $qtyTolerance = round((float) ($settings?->po_quantity_tolerance_percent ?? 0), 3);
        $priceTolerance = round((float) ($settings?->po_price_tolerance_percent ?? 0), 3);
        $policy = $invoice->purchaseOrder->matching_policy ?: '2_way';

        $receivedValue = 0.0;
        $matchedAmount = 0.0;
        $invoicedValue = 0.0;
        $lines = [];
        $blocking = false;

        foreach ($invoice->items as $line) {
            $poItem = $line->purchaseOrderItem;
            $qty = round((float) $line->quantity, 3);
            $lineAmount = round((float) $line->line_total, 2);
            $invoicedValue += $lineAmount;

            if (! $poItem || (int) $poItem->purchase_order_id !== (int) $invoice->purchase_order_id) {
                $blocking = true;
                $lines[] = [
                    'invoice_item_id' => (int) $line->id,
                    'purchase_order_item_id' => null,
                    'status' => 'mismatch',
                    'matched_quantity' => 0.0,
                    'matched_amount' => 0.0,
                    'received_value' => 0.0,
                    'invoiced_value' => $lineAmount,
                    'price_variance' => $lineAmount,
                    'reason' => __('Invoice line is not linked to a purchase order line.'),
                ];
                continue;
            }

            $expectedQty = $policy === '3_way'
                ? round((float) ($poItem->received_quantity ?? 0), 3)
                : round((float) ($poItem->quantity ?? 0), 3);
            $orderedQty = round((float) ($poItem->quantity ?? 0), 3);
            $receivedQty = round((float) ($poItem->received_quantity ?? 0), 3);
            $expectedUnitPrice = round((float) ($poItem->unit_price ?? 0), 4);
            $expectedValue = round($expectedQty * $expectedUnitPrice, 2);
            $lineUnitPrice = $qty > 0 ? round($lineAmount / $qty, 4) : 0.0;

            $qtyVariancePercent = $expectedQty > 0 ? round((abs($qty - $expectedQty) / $expectedQty) * 100, 3) : 0.0;
            $priceVariancePercent = $expectedUnitPrice > 0 ? round((abs($lineUnitPrice - $expectedUnitPrice) / $expectedUnitPrice) * 100, 3) : 0.0;
            $priceVarianceAmount = round($lineAmount - $expectedValue, 2);

            $status = 'matched';
            $reason = null;

            if ($qty > $expectedQty + 0.0005) {
                $status = 'over_invoiced';
                $reason = $policy === '3_way'
                    ? __('Invoice quantity exceeds received quantity.')
                    : __('Invoice quantity exceeds ordered quantity.');
            } elseif ($expectedQty > 0 && $qty < $expectedQty - 0.0005) {
                $status = 'partial';
                $reason = __('Invoice line is partially matched to the PO quantity.');
            }

            if ($qtyVariancePercent > $qtyTolerance || $priceVariancePercent > $priceTolerance) {
                $status = $status === 'partial' ? 'partial' : 'mismatch';
                $reason = $reason ?: __('Quantity or unit price is outside configured PO/AP tolerances.');
            }

            if ($status !== 'matched') {
                $blocking = true;
            }

            $matchedQty = min($qty, $expectedQty);
            $matchedValue = round($matchedQty * $expectedUnitPrice, 2);
            $receivedLineValue = round($receivedQty * $expectedUnitPrice, 2);

            $matchedAmount += $matchedValue;
            $receivedValue += $receivedLineValue;

            $lines[] = [
                'invoice_item_id' => (int) $line->id,
                'purchase_order_item_id' => (int) $poItem->id,
                'status' => $status,
                'matched_quantity' => $matchedQty,
                'matched_amount' => $matchedValue,
                'received_value' => $receivedLineValue,
                'invoiced_value' => $lineAmount,
                'price_variance' => $priceVarianceAmount,
                'ordered_quantity' => $orderedQty,
                'received_quantity' => $receivedQty,
                'reason' => $reason,
            ];
        }

        $overallStatus = $blocking
            ? (collect($lines)->contains(fn (array $line) => $line['status'] === 'over_invoiced') ? 'over_invoiced' : 'mismatch')
            : 'matched';

        return [
            'overall_status' => $overallStatus,
            'blocking' => $blocking,
            'summary' => [
                'matched_amount' => round($matchedAmount, 2),
                'received_value' => round($receivedValue, 2),
                'invoiced_value' => round($invoicedValue, 2),
                'variance_amount' => round($invoicedValue - $matchedAmount, 2),
            ],
            'lines' => $lines,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assertPostable(ApInvoice $invoice, int $actorId, bool $override = false, ?string $overrideReason = null): array
    {
        $evaluation = $this->evaluateInvoice($invoice);

        if (! $invoice->purchase_order_id) {
            return $evaluation;
        }

        if ($evaluation['blocking'] && ! $override) {
            throw ValidationException::withMessages([
                'purchase_order_id' => __('Invoice does not satisfy the purchase order matching policy. Resolve the mismatches before posting.'),
            ]);
        }

        $this->syncMatches($invoice, $evaluation, $override, $overrideReason, $actorId);

        return $evaluation;
    }

    /**
     * @param  array<string, mixed>  $evaluation
     */
    public function syncMatches(ApInvoice $invoice, array $evaluation, bool $override = false, ?string $overrideReason = null, ?int $actorId = null): void
    {
        if (! $invoice->purchase_order_id) {
            return;
        }

        DB::transaction(function () use ($invoice, $evaluation, $override, $overrideReason, $actorId) {
            PurchaseOrderInvoiceMatch::query()
                ->where('ap_invoice_id', $invoice->id)
                ->delete();

            foreach ((array) ($evaluation['lines'] ?? []) as $line) {
                if (empty($line['purchase_order_item_id']) || empty($line['invoice_item_id'])) {
                    continue;
                }

                PurchaseOrderInvoiceMatch::query()->create([
                    'company_id' => $invoice->company_id ?: $invoice->purchaseOrder?->company_id,
                    'purchase_order_id' => $invoice->purchase_order_id,
                    'purchase_order_item_id' => $line['purchase_order_item_id'],
                    'ap_invoice_id' => $invoice->id,
                    'ap_invoice_item_id' => $line['invoice_item_id'],
                    'matched_quantity' => $line['matched_quantity'],
                    'matched_amount' => $line['matched_amount'],
                    'received_value' => $line['received_value'],
                    'invoiced_value' => $line['invoiced_value'],
                    'price_variance' => $line['price_variance'],
                    'receipt_date' => optional($invoice->purchaseOrder?->received_date)->toDateString(),
                    'invoice_date' => optional($invoice->invoice_date)->toDateString(),
                    'status' => $line['status'],
                    'override_applied' => $override,
                    'overridden_by' => $override ? $actorId : null,
                    'overridden_at' => $override ? now() : null,
                    'override_reason' => $override ? $overrideReason : null,
                ]);
            }

            $this->auditLog->log('purchase_order_invoice.matched', $actorId, $invoice, [
                'overall_status' => $evaluation['overall_status'] ?? 'matched',
                'override' => $override,
            ], (int) ($invoice->company_id ?? 0) ?: null);
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function purchaseAccrualRows(?int $companyId = null, array $filters = []): Collection
    {
        $poQuery = PurchaseOrder::query()
            ->with(['supplier', 'items.item', 'invoiceMatches'])
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when(! empty($filters['supplier_id']), fn ($query) => $query->where('supplier_id', (int) $filters['supplier_id']))
            ->when(! empty($filters['date_to']), fn ($query) => $query->whereDate('order_date', '<=', (string) $filters['date_to']))
            ->whereIn('status', ['approved', 'received']);

        return $poQuery->get()
            ->flatMap(function (PurchaseOrder $po) {
                return $po->items->map(function (PurchaseOrderItem $item) use ($po) {
                    $receivedQty = round((float) ($item->received_quantity ?? 0), 3);
                    $matchedQty = round((float) $item->invoiceMatches->sum('matched_quantity'), 3);
                    $remainingQty = round(max($receivedQty - $matchedQty, 0), 3);
                    if ($remainingQty <= 0.0005) {
                        return null;
                    }

                    $unitPrice = round((float) ($item->unit_price ?? 0), 4);
                    $accrualValue = round($remainingQty * $unitPrice, 2);

                    return [
                        'purchase_order_id' => (int) $po->id,
                        'po_number' => $po->po_number,
                        'supplier_id' => (int) $po->supplier_id,
                        'supplier_name' => $po->supplier?->name,
                        'purchase_order_item_id' => (int) $item->id,
                        'item_name' => $item->item?->name,
                        'ordered_quantity' => round((float) ($item->quantity ?? 0), 3),
                        'received_quantity' => $receivedQty,
                        'matched_quantity' => $matchedQty,
                        'remaining_quantity' => $remainingQty,
                        'unit_price' => $unitPrice,
                        'accrual_value' => $accrualValue,
                        'received_date' => optional($po->received_date)->toDateString(),
                    ];
                })->filter();
            })
            ->values();
    }
}
