<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryStockService
{
    public function adjustStock(InventoryItem $item, int $delta, string $notes = null, ?int $userId = null): InventoryTransaction
    {
        if ($delta === 0) {
            throw ValidationException::withMessages(['quantity' => __('Quantity must not be zero.')]);
        }

        return DB::transaction(function () use ($item, $delta, $notes, $userId) {
            $locked = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $newStock = ($locked->current_stock ?? 0) + $delta;

            if (! config('inventory.allow_negative_stock', false) && $newStock < 0) {
                throw ValidationException::withMessages(['quantity' => __('Stock cannot go negative.')]);
            }

            $locked->current_stock = $newStock;
            $locked->save();

            return InventoryTransaction::create([
                'item_id' => $locked->id,
                'transaction_type' => 'adjustment',
                'quantity' => $delta,
                'reference_type' => 'manual',
                'reference_id' => null,
                'user_id' => $userId,
                'notes' => $notes,
                'transaction_date' => now(),
            ]);
        });
    }

    public function recordMovement(
        InventoryItem $item,
        string $type,
        int $quantity,
        string $referenceType = 'manual',
        ?int $referenceId = null,
        ?string $notes = null,
        ?int $userId = null
    ): InventoryTransaction {
        if (! in_array($type, ['in', 'out'], true)) {
            throw ValidationException::withMessages(['transaction_type' => __('Invalid transaction type.')]);
        }

        if ($quantity <= 0) {
            throw ValidationException::withMessages(['quantity' => __('Quantity must be greater than zero.')]);
        }

        $delta = $type === 'in' ? $quantity : -$quantity;

        return DB::transaction(function () use ($item, $delta, $type, $referenceType, $referenceId, $notes, $userId) {
            $locked = InventoryItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $newStock = ($locked->current_stock ?? 0) + $delta;

            if (! config('inventory.allow_negative_stock', false) && $newStock < 0) {
                throw ValidationException::withMessages(['quantity' => __('Stock cannot go negative.')]);
            }

            $locked->current_stock = $newStock;
            $locked->save();

            return InventoryTransaction::create([
                'item_id' => $locked->id,
                'transaction_type' => $type,
                'quantity' => abs($delta),
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'user_id' => $userId,
                'notes' => $notes,
                'transaction_date' => now(),
            ]);
        });
    }
}
