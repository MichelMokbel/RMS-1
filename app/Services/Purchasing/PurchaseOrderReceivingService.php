<?php

namespace App\Services\Purchasing;

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderReceiving;
use App\Models\PurchaseOrderReceivingLine;
use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Services\Accounting\AccountingAuditLogService;
use App\Services\Accounting\AccountingContextService;
use App\Services\Accounting\JobCostingService;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PurchaseOrderReceivingService
{
    public function __construct(
        protected AccountingContextService $accountingContext,
        protected AccountingAuditLogService $auditLog
    ) {
    }

    public function receive(PurchaseOrder $po, array $lineReceipts, int $userId, ?string $notes = null, array $costOverrides = [], mixed $receivedAt = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $lineReceipts, $userId, $notes, $costOverrides, $receivedAt) {
            $po = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            if ($po->status !== PurchaseOrder::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => __('Only approved purchase orders can be received.'),
                ]);
            }

            $receivedAt = $receivedAt ? Carbon::parse($receivedAt) : now();
            $pendingLines = [];

            foreach ($lineReceipts as $lineId => $receiveQty) {
                $line = PurchaseOrderItem::where('id', $lineId)->lockForUpdate()->first();
                if (! $line || $line->purchase_order_id !== $po->id) {
                    continue;
                }

                $receiveQty = round((float) $receiveQty, 3);
                if ($receiveQty < 0) {
                    throw ValidationException::withMessages([
                        'receive' => __('Receive quantity must be zero or greater.'),
                    ]);
                }

                if ($receiveQty === 0.0) {
                    continue;
                }

                $remaining = $line->remainingToReceive();
                if ($receiveQty - $remaining > 0.0005) {
                    throw ValidationException::withMessages([
                        'receive' => __('Cannot receive more than remaining quantity for line :line', ['line' => $lineId]),
                    ]);
                }

                $pendingLines[] = [
                    'line' => $line,
                    'receive_qty' => $receiveQty,
                    'override_cost' => $costOverrides[$line->id] ?? null,
                ];
            }

            if ($pendingLines === []) {
                throw ValidationException::withMessages([
                    'receive' => __('Nothing to receive.'),
                ]);
            }

            $receiving = PurchaseOrderReceiving::create([
                'purchase_order_id' => $po->id,
                'received_at' => $receivedAt,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            foreach ($pendingLines as $pending) {
                /** @var PurchaseOrderItem $line */
                $line = $pending['line'];
                $receiveQty = (float) $pending['receive_qty'];
                $overrideCost = $pending['override_cost'];

                $line->received_quantity = (float) ($line->received_quantity ?? 0) + $receiveQty;
                $line->save();

                $unitCost = $overrideCost !== null ? (float) $overrideCost : (float) ($line->unit_price ?? 0);

                PurchaseOrderReceivingLine::create([
                    'purchase_order_receiving_id' => $receiving->id,
                    'purchase_order_item_id' => $line->id,
                    'inventory_item_id' => $line->item_id,
                    'received_quantity' => $receiveQty,
                    'unit_cost' => $unitCost,
                    'total_cost' => round($unitCost * $receiveQty, 4),
                ]);

                if ($line->item_id) {
                    $this->updateInventory($line, $receiveQty, $userId, $po, $notes, $unitCost, $receivedAt);
                }
            }

            $po->refresh();
            if ($po->isFullyReceived()) {
                $po->status = PurchaseOrder::STATUS_RECEIVED;
                $po->received_date = $receivedAt->toDateString();
            }
            $po->save();

            if ($po->isFullyReceived()) {
                $this->maybeCreateApInvoice($po, $userId, $costOverrides);
            }

            $this->auditLog->log('purchase_order.received', $userId, $po, [
                'line_receipts' => $lineReceipts,
            ], (int) ($po->company_id ?? 0) ?: null);

            return $po->fresh(['items']);
        });
    }

    private function updateInventory(
        PurchaseOrderItem $line,
        float $delta,
        int $userId,
        PurchaseOrder $po,
        ?string $notes = null,
        ?float $unitPrice = null,
        mixed $receivedAt = null
    ): void
    {
        $item = InventoryItem::where('id', $line->item_id)->lockForUpdate()->first();
        if (! $item) {
            return;
        }

        $branchId = $this->resolveBranchId($po);
        $stock = InventoryStock::where('inventory_item_id', $item->id)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            InventoryStock::create([
                'inventory_item_id' => $item->id,
                'branch_id' => $branchId,
                'current_stock' => 0,
            ]);

            $stock = InventoryStock::where('inventory_item_id', $item->id)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();
        }

        $oldStock = (float) InventoryStock::where('inventory_item_id', $item->id)->lockForUpdate()->sum('current_stock');
        $branchStock = (float) ($stock->current_stock ?? 0);
        $oldCost = (float) ($item->cost_per_unit ?? 0);
        $unitPrice = $unitPrice ?? (float) ($line->unit_price ?? 0);

        $newCost = $oldStock > 0
            ? (($oldStock * $oldCost) + ($delta * $unitPrice)) / ($oldStock + $delta)
            : $unitPrice;

        $stock->current_stock = round($branchStock + $delta, 3);
        $stock->save();

        $item->cost_per_unit = round($newCost, 4);
        $item->last_cost_update = now();
        $item->save();

        $transaction = InventoryTransaction::create([
            'item_id' => $item->id,
            'branch_id' => $branchId,
            'transaction_type' => 'in',
            'quantity' => $delta,
            'unit_cost' => $unitPrice,
            'total_cost' => round($unitPrice * $delta, 4),
            'reference_type' => 'purchase_order',
            'reference_id' => $po->id,
            'user_id' => $userId,
            'notes' => trim('PO '.$po->po_number.' '.($notes ?? '')),
            'transaction_date' => $receivedAt ?: now(),
        ]);

        app(SubledgerService::class)->recordInventoryTransaction($transaction, $userId);

        if ($po->job_id && $po->job) {
            app(JobCostingService::class)->recordSourceTransaction($po->job, [
                'transaction_date' => optional($transaction->transaction_date)->toDateString() ?? now()->toDateString(),
                'amount' => round((float) ($transaction->total_cost ?? ($unitPrice * $delta)), 2),
                'transaction_type' => 'cost',
                'memo' => __('Inventory receipt from PO :po for :item', [
                    'po' => $po->po_number,
                    'item' => $item->name,
                ]),
            ], PurchaseOrder::class, (int) $po->id, $userId);
        }
    }

    private function resolveBranchId(PurchaseOrder $po): int
    {
        $branchId = (int) ($po->branch_id ?? 0);
        if ($branchId <= 0) {
            $branchId = (int) config('inventory.default_branch_id', 1);
        }

        return $branchId > 0 ? $branchId : 1;
    }

    private function maybeCreateApInvoice(PurchaseOrder $po, int $userId, array $costOverrides = []): void
    {
        if (! Schema::hasTable('ap_invoices') || ! Schema::hasTable('ap_invoice_items')) {
            return;
        }

        $exists = ApInvoice::where('purchase_order_id', $po->id)->exists();
        if ($exists) {
            return;
        }

        $invoiceNumberBase = 'PO-'.$po->po_number;
        $invoiceNumber = $invoiceNumberBase;
        $suffix = 1;
        while (ApInvoice::where('supplier_id', $po->supplier_id)->where('invoice_number', $invoiceNumber)->exists()) {
            $invoiceNumber = $invoiceNumberBase.'-'.$suffix;
            $suffix++;
        }

        $invoice = ApInvoice::create([
            'company_id' => $po->company_id ?: $this->accountingContext->resolveCompanyId((int) ($po->branch_id ?? 0)),
            'branch_id' => $po->branch_id ?? null,
            'department_id' => $po->department_id ?? null,
            'job_id' => $po->job_id ?? null,
            'period_id' => $this->accountingContext->resolvePeriodId(now()->toDateString(), (int) ($po->company_id ?? 0)),
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'category_id' => null,
            'is_expense' => false,
            'document_type' => 'vendor_bill',
            'currency_code' => config('pos.currency', 'QAR'),
            'source_document_type' => PurchaseOrder::class,
            'source_document_id' => $po->id,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'status' => 'draft',
            'notes' => __('Auto-generated from PO :po', ['po' => $po->po_number]),
            'created_by' => $userId,
        ]);

        $subtotal = 0;
        $po->loadMissing('items');
        foreach ($po->items as $poItem) {
            $lineQty = (float) ($poItem->received_quantity ?? $poItem->quantity);
            if ($lineQty <= 0.0005) {
                continue;
            }
            $unit = isset($costOverrides[$poItem->id]) ? (float) $costOverrides[$poItem->id] : (float) $poItem->unit_price;
            $lineTotal = round($lineQty * $unit, 2);
            $subtotal += $lineTotal;
            ApInvoiceItem::create([
                'invoice_id' => $invoice->id,
                'purchase_order_item_id' => $poItem->id,
                'description' => 'PO '.$po->po_number.' item '.$poItem->item_id,
                'quantity' => $lineQty,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
            ]);
        }

        $invoice->subtotal = round($subtotal, 2);
        $invoice->total_amount = round($subtotal + (float) $invoice->tax_amount, 2);
        $invoice->save();
    }
}
