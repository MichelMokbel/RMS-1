<?php

namespace App\Services\Pricing;

use App\Models\Order;
use Illuminate\Support\Collection;

class DailyDishPricingService
{
    private const MAIN_ROLES = ['main', 'diet', 'vegetarian'];

    /**
     * Compute order total for one-off daily dish from stored order items and order portion fields.
     * Used by OrderTotalsService::recalc.
     */
    public function computeOneOffTotal(Order $order): float
    {
        $portionType = $order->daily_dish_portion_type;
        $portionQuantity = (int) ($order->daily_dish_portion_quantity ?? 0);

        if ($portionType === 'full' && $portionQuantity > 0) {
            $price = (float) config('pricing.daily_dish.portion_prices.full', 200);

            return $portionQuantity * $price;
        }

        if ($portionType === 'half' && $portionQuantity > 0) {
            $price = (float) config('pricing.daily_dish.portion_prices.half', 130);

            return $portionQuantity * $price;
        }

        return $this->computeBundleTotalFromItems($order->items);
    }

    /**
     * Compute total from selection (used at order create).
     * $selectedItemsWithRoles: array of ['menu_item_id' => int, 'quantity' => float, 'role' => string].
     * $portionType: 'plate' | 'full' | 'half'.
     * $portionQuantity: int when portion_type is full or half.
     */
    public function computeFromSelection(array $selectedItemsWithRoles, string $portionType, ?int $portionQuantity): float
    {
        if ($portionType === 'full' && $portionQuantity > 0) {
            $price = (float) config('pricing.daily_dish.portion_prices.full', 200);

            return $portionQuantity * $price;
        }

        if ($portionType === 'half' && $portionQuantity > 0) {
            $price = (float) config('pricing.daily_dish.portion_prices.half', 130);

            return $portionQuantity * $price;
        }

        return $this->computeBundleTotalFromSelection($selectedItemsWithRoles);
    }

    /**
     * Bundle (plate) mode: group by role, build plates (main only=50, main+one=55, main+both=65).
     */
    private function computeBundleTotalFromItems(Collection $items): float
    {
        $rows = $items->map(fn ($item) => [
            'quantity' => (float) $item->quantity,
            'role' => $this->normalizeRole($item->role),
        ])->all();

        return $this->computeBundleTotalFromSelection($rows);
    }

    private function computeBundleTotalFromSelection(array $selectedItemsWithRoles): float
    {
        $mainCount = 0;
        $saladCount = 0;
        $dessertCount = 0;

        foreach ($selectedItemsWithRoles as $row) {
            $qty = (float) ($row['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $role = $this->normalizeRole($row['role'] ?? null);
            if (in_array($role, self::MAIN_ROLES, true)) {
                $mainCount += $qty;
            } elseif ($role === 'salad') {
                $saladCount += $qty;
            } elseif ($role === 'dessert') {
                $dessertCount += $qty;
            }
        }

        $basePrices = config('pricing.meal_plan.base_prices', []);
        $priceMainOnly = (float) ($basePrices['main_only'] ?? 50);
        $priceMainPlusOne = (float) ($basePrices['main_plus_one'] ?? 55);
        $priceMainPlusBoth = (float) ($basePrices['main_plus_both'] ?? 65);

        $total = 0.0;
        $m = (int) round($mainCount);
        $s = (int) round($saladCount);
        $d = (int) round($dessertCount);

        $nFull = min($m, $s, $d);
        $total += $nFull * $priceMainPlusBoth;
        $m -= $nFull;
        $s -= $nFull;
        $d -= $nFull;

        $nMainSalad = min($m, $s);
        $total += $nMainSalad * $priceMainPlusOne;
        $m -= $nMainSalad;
        $s -= $nMainSalad;

        $nMainDessert = min($m, $d);
        $total += $nMainDessert * $priceMainPlusOne;
        $m -= $nMainDessert;
        $d -= $nMainDessert;

        $total += $m * $priceMainOnly;

        return round($total, 3);
    }

    private function normalizeRole(?string $role): string
    {
        if ($role === null || $role === '') {
            return 'main';
        }
        $role = strtolower($role);
        if ($role === 'sweet') {
            return 'dessert';
        }

        return $role;
    }
}
