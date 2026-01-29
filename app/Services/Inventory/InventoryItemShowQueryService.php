<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use Illuminate\Support\Collection;

class InventoryItemShowQueryService
{
    private InventoryItemFormQueryService $forms;
    private InventoryItemStockQueryService $stocks;

    public function __construct(InventoryItemFormQueryService $forms, InventoryItemStockQueryService $stocks)
    {
        $this->forms = $forms;
        $this->stocks = $stocks;
    }

    public function showData(InventoryItem $item, ?int $branchId, int $transactionLimit = 50): array
    {
        $branchId = (int) ($branchId ?? config('inventory.default_branch_id', 1));
        if ($branchId <= 0) {
            $branchId = 1;
        }

        $branchStock = $this->stocks->branchStock($item->id, $branchId);
        $globalStock = $this->stocks->globalStock($item->id);
        $availability = $this->stocks->availabilityBranchIds($item->id)->values()->all();

        return [
            'transactions' => $item->transactions()
                ->when($branchId > 0, fn ($q) => $q->where('branch_id', $branchId))
                ->limit($transactionLimit)
                ->get(),
            'branches' => $this->forms->branches(),
            'branch_stock' => $branchStock,
            'is_low_stock' => $branchStock <= (float) ($item->minimum_stock ?? 0),
            'global_stock' => $globalStock,
            'availability' => $availability,
            'resolved_branch_id' => $branchId,
        ];
    }

    /**
     * Small payload used by edit page (no transactions/availability).
     */
    public function stockSummary(InventoryItem $item, ?int $branchId): array
    {
        $branchId = (int) ($branchId ?? config('inventory.default_branch_id', 1));
        if ($branchId <= 0) {
            $branchId = 1;
        }

        return [
            'branches' => $this->forms->branches(),
            'branch_stock' => $this->stocks->branchStock($item->id, $branchId),
            'global_stock' => $this->stocks->globalStock($item->id),
            'resolved_branch_id' => $branchId,
        ];
    }
}

