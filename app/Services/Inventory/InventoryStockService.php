<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryStockService
{
    public function adjustStock(InventoryItem $item, float $delta, string $notes = null, ?int $userId = null, ?int $branchId = null): InventoryTransaction
    {
        $delta = round($delta, 3);
        if (abs($delta) < 0.0005) {
            throw ValidationException::withMessages(['quantity' => __('Quantity must not be zero.')]);
        }

        $branchId = $this->resolveBranchId($branchId);

        return DB::transaction(function () use ($item, $delta, $notes, $userId, $branchId) {
            $locked = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $stock = $this->lockStockRow($locked, $branchId);
            $currentStock = (float) ($stock->current_stock ?? 0);
            $newStock = round($currentStock + $delta, 3);

            if (! config('inventory.allow_negative_stock', false) && $newStock < 0) {
                throw ValidationException::withMessages(['quantity' => __('Stock cannot go negative.')]);
            }

            $stock->current_stock = $newStock;
            $stock->save();


            $unitCost = $locked->cost_per_unit !== null ? (float) $locked->cost_per_unit : null;
            $totalCost = $unitCost !== null ? round($unitCost * $delta, 4) : null;

            $transaction = InventoryTransaction::create([
                'item_id' => $locked->id,
                'branch_id' => $branchId,
                'transaction_type' => 'adjustment',
                'quantity' => $delta,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'reference_type' => 'manual',
                'reference_id' => null,
                'user_id' => $userId,
                'notes' => $notes,
                'transaction_date' => now(),
            ]);

            app(SubledgerService::class)->recordInventoryTransaction($transaction, $userId);

            return $transaction;
        });
    }

    public function recordMovement(
        InventoryItem $item,
        string $type,
        float $quantity,
        string $referenceType = 'manual',
        ?int $referenceId = null,
        ?string $notes = null,
        ?int $userId = null,
        ?float $unitCost = null,
        ?int $branchId = null
    ): InventoryTransaction {
        if (! in_array($type, ['in', 'out'], true)) {
            throw ValidationException::withMessages(['transaction_type' => __('Invalid transaction type.')]);
        }

        $quantity = round($quantity, 3);
        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Quantity must be greater than zero.')]);
        }

        $delta = $type === 'in' ? $quantity : -$quantity;

        $branchId = $this->resolveBranchId($branchId);

        return DB::transaction(function () use ($item, $delta, $type, $referenceType, $referenceId, $notes, $userId, $quantity, $unitCost, $branchId) {
            $locked = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $stock = $this->lockStockRow($locked, $branchId);
            $currentStock = (float) ($stock->current_stock ?? 0);
            $newStock = round($currentStock + $delta, 3);

            if (! config('inventory.allow_negative_stock', false) && $newStock < 0) {
                throw ValidationException::withMessages(['quantity' => __('Stock cannot go negative.')]);
            }

            $stock->current_stock = $newStock;
            $stock->save();


            $effectiveUnitCost = $unitCost ?? ($locked->cost_per_unit !== null ? (float) $locked->cost_per_unit : null);
            $direction = $type === 'out' ? -1 : 1;
            $totalCost = $effectiveUnitCost !== null ? round($effectiveUnitCost * $quantity * $direction, 4) : null;

            $transaction = InventoryTransaction::create([
                'item_id' => $locked->id,
                'branch_id' => $branchId,
                'transaction_type' => $type,
                'quantity' => abs($delta),
                'unit_cost' => $effectiveUnitCost,
                'total_cost' => $totalCost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => $userId,
                'notes' => $notes,
                'transaction_date' => now(),
            ]);

            app(SubledgerService::class)->recordInventoryTransaction($transaction, $userId);

            return $transaction;
        });
    }

    private function resolveBranchId(?int $branchId): int
    {
        $branchId = $branchId ?? (int) config('inventory.default_branch_id', 1);

        return $branchId > 0 ? $branchId : 1;
    }

    private function lockStockRow(InventoryItem $item, int $branchId): InventoryStock
    {
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
                ->firstOrFail();
        }

        return $stock;
    }
}
