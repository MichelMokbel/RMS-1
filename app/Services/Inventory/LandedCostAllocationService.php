<?php

namespace App\Services\Inventory;

use App\Models\ApInvoice;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LandedCostAllocationService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function allocate(ApInvoice $invoice, int $actorId): array
    {
        $invoice->loadMissing(['purchaseOrder.items.item']);
        $po = $invoice->purchaseOrder;

        if (! $po) {
            throw ValidationException::withMessages([
                'purchase_order_id' => __('Landed cost adjustments require a linked purchase order.'),
            ]);
        }

        $stockLines = $po->items->filter(fn ($line) => $line->item_id && (float) ($line->received_quantity ?? 0) > 0.0005);
        if ($stockLines->isEmpty()) {
            throw ValidationException::withMessages([
                'purchase_order_id' => __('The linked PO has no received stock lines available for landed cost allocation.'),
            ]);
        }

        $totalBase = (float) $stockLines->sum(fn ($line) => round((float) ($line->received_quantity ?? 0) * (float) ($line->unit_price ?? 0), 2));
        $landedCost = round((float) ($invoice->subtotal ?? 0), 2);
        if ($landedCost <= 0) {
            return [];
        }

        $allocations = [];

        DB::transaction(function () use ($stockLines, $totalBase, $landedCost, $invoice, $actorId, &$allocations) {
            $remaining = $landedCost;
            $count = $stockLines->count();

            foreach ($stockLines->values() as $index => $line) {
                $item = InventoryItem::query()->lockForUpdate()->findOrFail($line->item_id);
                $receivedQty = round((float) ($line->received_quantity ?? 0), 3);
                $baseValue = round($receivedQty * (float) ($line->unit_price ?? 0), 2);

                if ($index === $count - 1) {
                    $allocated = round($remaining, 2);
                } else {
                    $allocated = $totalBase > 0
                        ? round(($baseValue / $totalBase) * $landedCost, 2)
                        : round($landedCost / $count, 2);
                    $remaining = round($remaining - $allocated, 2);
                }

                $perUnit = $receivedQty > 0 ? round($allocated / $receivedQty, 4) : 0.0;
                $item->cost_per_unit = round((float) ($item->cost_per_unit ?? 0) + $perUnit, 4);
                $item->last_cost_update = now();
                $item->save();

                InventoryTransaction::query()->create([
                    'item_id' => $item->id,
                    'branch_id' => $po->branch_id ?? config('inventory.default_branch_id', 1),
                    'transaction_type' => 'adjustment',
                    'quantity' => 0,
                    'unit_cost' => $perUnit,
                    'total_cost' => $allocated,
                    'reference_type' => 'landed_cost_adjustment',
                    'reference_id' => $invoice->id,
                    'user_id' => $actorId,
                    'notes' => __('Landed cost adjustment for AP invoice :invoice', ['invoice' => $invoice->invoice_number]),
                    'transaction_date' => $invoice->invoice_date ?? now(),
                ]);

                $allocations[] = [
                    'purchase_order_item_id' => (int) $line->id,
                    'item_id' => (int) $item->id,
                    'item_name' => $item->name,
                    'allocated_cost' => $allocated,
                    'quantity' => $receivedQty,
                ];
            }
        });

        return $allocations;
    }
}
