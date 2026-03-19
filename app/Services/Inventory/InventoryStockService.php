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
    public function postTransaction(
        InventoryItem $item,
        string $transactionType,
        float $quantity,
        ?string $notes = null,
        ?int $userId = null,
        ?int $branchId = null,
        ?float $unitCost = null,
        string $referenceType = 'manual',
        ?int $referenceId = null,
        mixed $transactionDate = null
    ): InventoryTransaction {
        $normalizedType = $transactionType === 'adjust' ? 'adjustment' : $transactionType;

        if (! in_array($normalizedType, ['in', 'out', 'adjustment'], true)) {
            throw ValidationException::withMessages(['transaction_type' => __('Invalid transaction type.')]);
        }

        $quantity = round($quantity, 3);
        if (abs($quantity) < 0.0005) {
            throw ValidationException::withMessages(['quantity' => __('Quantity must not be zero.')]);
        }

        if (in_array($normalizedType, ['in', 'out'], true) && $quantity < 0) {
            throw ValidationException::withMessages(['quantity' => __('Quantity must be greater than zero.')]);
        }

        $delta = match ($normalizedType) {
            'in' => abs($quantity),
            'out' => -abs($quantity),
            default => $quantity,
        };

        $storedQuantity = $normalizedType === 'adjustment' ? $delta : abs($quantity);
        $branchId = $this->resolveBranchId($branchId);
        $effectiveDate = $transactionDate ?: now();

        return DB::transaction(function () use (
            $item,
            $delta,
            $storedQuantity,
            $normalizedType,
            $notes,
            $userId,
            $branchId,
            $unitCost,
            $referenceType,
            $referenceId,
            $effectiveDate
        ) {
            $locked = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $stock = $this->lockStockRow($locked, $branchId);
            $currentStock = (float) ($stock->current_stock ?? 0);
            $newStock = round($currentStock + $delta, 3);

            if (! config('inventory.allow_negative_stock', false) && $newStock < -0.0005) {
                throw ValidationException::withMessages(['quantity' => __('Stock cannot go negative.')]);
            }

            $stock->current_stock = $newStock;
            $stock->save();

            $effectiveUnitCost = $unitCost ?? ($locked->cost_per_unit !== null ? (float) $locked->cost_per_unit : null);
            $totalCost = $effectiveUnitCost !== null ? round($effectiveUnitCost * $delta, 4) : null;

            $transaction = InventoryTransaction::create([
                'item_id' => $locked->id,
                'branch_id' => $branchId,
                'transaction_type' => $normalizedType,
                'quantity' => $storedQuantity,
                'unit_cost' => $effectiveUnitCost,
                'total_cost' => $totalCost,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => $userId,
                'notes' => $notes,
                'transaction_date' => $effectiveDate,
            ]);

            app(SubledgerService::class)->recordInventoryTransaction($transaction, $userId);

            return $transaction;
        });
    }

    public function adjustStock(InventoryItem $item, float $delta, string $notes = null, ?int $userId = null, ?int $branchId = null): InventoryTransaction
    {
        return $this->postTransaction(
            $item,
            'adjustment',
            $delta,
            $notes,
            $userId,
            $branchId
        );
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
        return $this->postTransaction(
            $item,
            $type,
            $quantity,
            $notes,
            $userId,
            $branchId,
            $unitCost,
            $referenceType,
            $referenceId
        );
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
