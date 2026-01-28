<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransfer;
use App\Models\InventoryTransferLine;
use App\Services\Ledger\SubledgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryTransferService
{
    public function createAndPostBulk(
        int $fromBranchId,
        int $toBranchId,
        array $lines,
        int $userId,
        ?string $notes = null,
        ?string $transferDate = null
    ): InventoryTransfer {
        $fromBranchId = $fromBranchId > 0 ? $fromBranchId : (int) config('inventory.default_branch_id', 1);
        $toBranchId = $toBranchId > 0 ? $toBranchId : (int) config('inventory.default_branch_id', 1);

        if ($fromBranchId === $toBranchId) {
            throw ValidationException::withMessages(['branch' => __('From and To branches must be different.')]);
        }

        $normalized = [];
        foreach ($lines as $index => $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $qty = round((float) ($line['quantity'] ?? 0), 3);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $normalized[] = ['index' => $index, 'item_id' => $itemId, 'quantity' => $qty];
        }

        if (empty($normalized)) {
            throw ValidationException::withMessages(['lines' => __('At least one line is required.')]);
        }

        return DB::transaction(function () use ($fromBranchId, $toBranchId, $normalized, $userId, $notes, $transferDate) {
            $itemIds = collect($normalized)->pluck('item_id')->unique()->values()->all();
            $items = InventoryItem::whereIn('id', $itemIds)->lockForUpdate()->get()->keyBy('id');

            if ($items->count() !== count($itemIds)) {
                throw ValidationException::withMessages(['lines' => __('One or more items are invalid.')]);
            }

            $transfer = InventoryTransfer::create([
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'transfer_date' => $transferDate ?? now()->toDateString(),
                'status' => 'draft',
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            foreach ($normalized as $row) {
                $item = $items->get($row['item_id']);
                $quantity = $row['quantity'];
                $lineIndex = $row['index'] ?? null;

                $fromStock = InventoryStock::where('inventory_item_id', $item->id)
                    ->where('branch_id', $fromBranchId)
                    ->lockForUpdate()
                    ->first();

                if (! $fromStock) {
                    $key = $lineIndex !== null ? 'lines.'.$lineIndex.'.item_id' : 'lines';
                    throw ValidationException::withMessages([$key => __('Item is not available in the source branch.')]);
                }

                $toStock = InventoryStock::where('inventory_item_id', $item->id)
                    ->where('branch_id', $toBranchId)
                    ->lockForUpdate()
                    ->first();

                if (! $toStock) {
                    $toStock = InventoryStock::create([
                        'inventory_item_id' => $item->id,
                        'branch_id' => $toBranchId,
                        'current_stock' => 0,
                    ]);

                    $toStock = InventoryStock::where('inventory_item_id', $item->id)
                        ->where('branch_id', $toBranchId)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $currentFrom = (float) ($fromStock->current_stock ?? 0);
                if (! config('inventory.allow_negative_stock', false) && $currentFrom - $quantity < -0.0005) {
                    $key = $lineIndex !== null ? 'lines.'.$lineIndex.'.quantity' : 'quantity';
                    throw ValidationException::withMessages([$key => __('Insufficient stock in source branch.')]);
                }

                $unitCost = $item->cost_per_unit !== null ? (float) $item->cost_per_unit : null;
                $lineTotal = $unitCost !== null ? round($unitCost * $quantity, 4) : null;

                InventoryTransferLine::create([
                    'transfer_id' => $transfer->id,
                    'inventory_item_id' => $item->id,
                    'quantity' => $quantity,
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost' => $lineTotal,
                ]);

                $fromStock->current_stock = round($currentFrom - $quantity, 3);
                $fromStock->save();

                $toStock->current_stock = round((float) ($toStock->current_stock ?? 0) + $quantity, 3);
                $toStock->save();

                $outCost = $unitCost !== null ? round($unitCost * $quantity * -1, 4) : null;
                $inCost = $unitCost !== null ? round($unitCost * $quantity, 4) : null;

                InventoryTransaction::create([
                    'item_id' => $item->id,
                    'branch_id' => $fromBranchId,
                    'transaction_type' => 'out',
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $outCost,
                    'reference_type' => 'transfer',
                    'reference_id' => $transfer->id,
                    'user_id' => $userId,
                    'notes' => trim('Transfer to branch '.$toBranchId.' '.($notes ?? '')),
                    'transaction_date' => now(),
                ]);

                InventoryTransaction::create([
                    'item_id' => $item->id,
                    'branch_id' => $toBranchId,
                    'transaction_type' => 'in',
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $inCost,
                    'reference_type' => 'transfer',
                    'reference_id' => $transfer->id,
                    'user_id' => $userId,
                    'notes' => trim('Transfer from branch '.$fromBranchId.' '.($notes ?? '')),
                    'transaction_date' => now(),
                ]);
            }

            $transfer->status = 'posted';
            $transfer->posted_by = $userId;
            $transfer->posted_at = now();
            $transfer->save();

            app(SubledgerService::class)->recordInventoryTransfer($transfer->fresh(['lines']), $userId);

            return $transfer->fresh(['lines']);
        });
    }

    public function createAndPost(
        InventoryItem $item,
        int $fromBranchId,
        int $toBranchId,
        float $quantity,
        int $userId,
        ?string $notes = null,
        ?string $transferDate = null
    ): InventoryTransfer {
        return $this->createAndPostBulk(
            $fromBranchId,
            $toBranchId,
            [['item_id' => $item->id, 'quantity' => $quantity]],
            $userId,
            $notes,
            $transferDate
        );
    }
}
