<?php

namespace App\Services\Recipes;

use App\Models\Recipe;
use Illuminate\Support\Collection;

class RecipeCostingService
{
    /**
    * Compute costing details for a recipe.
    *
    * @return array{base_cost_total:float, overhead_rate:float, overhead_amount:float, total_cost_with_overhead:float, yield_quantity:float, cost_per_yield_unit:float, cost_per_yield_unit_display:float, selling_price_per_unit:float|null, margin_amount_per_unit:float|null, margin_pct:float|null, items:array, grouped_by_cost_type:array}
    */
    public function compute(Recipe $recipe): array
    {
        $recipe->loadMissing(['items.inventoryItem']);

        $items = $recipe->items->map(function ($item) {
            $inv = $item->inventoryItem;
            $unitsPerPackage = (float) ($inv->units_per_package ?? 0);
            $packageCost = (float) ($inv->cost_per_unit ?? 0);

            $perUnitCost = $unitsPerPackage > 0
                ? $packageCost / $unitsPerPackage
                : $packageCost; // fallback

            if ($item->quantity_type === 'package') {
                $lineCost = (float) $item->quantity * $packageCost;
            } else {
                $lineCost = (float) $item->quantity * $perUnitCost;
            }

            return [
                'inventory_item_id' => $item->inventory_item_id,
                'item_name' => $inv->name ?? null,
                'units_per_package' => $unitsPerPackage,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'quantity_type' => $item->quantity_type,
                'cost_type' => $item->cost_type,
                'package_cost' => $packageCost,
                'per_unit_cost' => $perUnitCost,
                'line_cost' => round($lineCost, 2),
            ];
        });

        $baseCostTotal = round($items->sum('line_cost'), 2);
        $overheadRate = $recipe->normalizedOverheadRate();
        $overheadAmount = round($baseCostTotal * $overheadRate, 2);
        $totalCostWithOverhead = round($baseCostTotal + $overheadAmount, 2);
        $yieldQty = (float) ($recipe->yield_quantity ?? 0);

        $costPerYield = $yieldQty > 0 ? $totalCostWithOverhead / $yieldQty : 0;

        $selling = $recipe->selling_price_per_unit !== null ? (float) $recipe->selling_price_per_unit : null;
        $marginAmount = $selling !== null ? round($selling - $costPerYield, 2) : null;
        $marginPct = ($selling !== null && $selling > 0)
            ? ($marginAmount / $selling)
            : null;

        $withPct = $this->addCostPercentages($items, $baseCostTotal);
        $grouped = $this->groupByCostType($withPct);

        return [
            'base_cost_total' => $baseCostTotal,
            'overhead_rate' => $overheadRate,
            'overhead_amount' => $overheadAmount,
            'total_cost_with_overhead' => $totalCostWithOverhead,
            'yield_quantity' => $yieldQty,
            'cost_per_yield_unit' => $costPerYield,
            'cost_per_yield_unit_display' => round($costPerYield, 2),
            'selling_price_per_unit' => $selling,
            'margin_amount_per_unit' => $marginAmount,
            'margin_pct' => $marginPct,
            'items' => $withPct->toArray(),
            'grouped_by_cost_type' => $grouped,
        ];
    }

    private function addCostPercentages(Collection $items, float $baseCostTotal): Collection
    {
        return $items->map(function ($row) use ($baseCostTotal) {
            $row['base_cost_pct'] = $baseCostTotal > 0
                ? ($row['line_cost'] / $baseCostTotal)
                : 0;

            return $row;
        });
    }

    private function groupByCostType(Collection $items): array
    {
        return $items->groupBy('cost_type')->map(function ($group) {
            $total = $group->sum('line_cost');

            return [
                'line_cost' => round($total, 2),
                'base_cost_pct' => $group->avg('base_cost_pct'),
            ];
        })->toArray();
    }
}

