<?php

namespace App\Services\Purchasing;

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\ApInvoice;
use App\Models\ApInvoiceItem;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PurchaseOrderReceivingService
{
    public function receive(PurchaseOrder $po, array $lineReceipts, int $userId, ?string $notes = null, array $costOverrides = []): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $lineReceipts, $userId, $notes, $costOverrides) {
            $po = PurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();

            if ($po->status !== PurchaseOrder::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => __('Only approved purchase orders can be received.'),
                ]);
            }

            $anyReceived = false;

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

                $anyReceived = true;
                $line->received_quantity = (float) ($line->received_quantity ?? 0) + $receiveQty;
                $line->save();

                if ($line->item_id) {
                    $overrideCost = $costOverrides[$line->id] ?? null;
                    $this->updateInventory($line, $receiveQty, $userId, $po, $notes, $overrideCost);
                }
            }

            if (! $anyReceived) {
                throw ValidationException::withMessages([
                    'receive' => __('Nothing to receive.'),
                ]);
            }

            $po->refresh();
            if ($po->isFullyReceived()) {
                $po->status = PurchaseOrder::STATUS_RECEIVED;
                $po->received_date = now();
            }
            $po->save();

            if ($po->isFullyReceived()) {
                $this->maybeCreateApInvoice($po, $userId, $costOverrides);
            }

            return $po->fresh(['items']);
        });
    }

    private function updateInventory(PurchaseOrderItem $line, float $delta, int $userId, PurchaseOrder $po, ?string $notes = null, $overrideCost = null): void
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
        $unitPrice = $overrideCost !== null ? (float) $overrideCost : (float) ($line->unit_price ?? 0);

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
            'transaction_date' => now(),
        ]);

        app(SubledgerService::class)->recordInventoryTransaction($transaction, $userId);
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
            'supplier_id' => $po->supplier_id,
            'purchase_order_id' => $po->id,
            'category_id' => null,
            'is_expense' => false,
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
