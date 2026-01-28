<?php

namespace App\Services\Pricing;

use App\Models\MealSubscription;

class MealPlanPricingService
{
    public function subscriptionPrice(MealSubscription $subscription): float
    {
        $planTotal = $subscription->plan_meals_total;
        if ($planTotal !== null) {
            $planKey = (string) (int) $planTotal;
            $planPrices = config('pricing.meal_plan.plan_prices', []);
            if (array_key_exists($planKey, $planPrices)) {
                return (float) $planPrices[$planKey];
            }
        }

        $base = config('pricing.meal_plan.base_prices', []);
        $hasSalad = (bool) $subscription->include_salad;
        $hasDessert = (bool) $subscription->include_dessert;

        if ($hasSalad && $hasDessert) {
            return (float) ($base['main_plus_both'] ?? 65.0);
        }

        if ($hasSalad || $hasDessert) {
            return (float) ($base['main_plus_one'] ?? 55.0);
        }

        return (float) ($base['main_only'] ?? 50.0);
    }

    public function planPriceForKey(?string $key): ?float
    {
        if (! $key) {
            return null;
        }

        $planPrices = config('pricing.meal_plan.plan_prices', []);
        if (! array_key_exists($key, $planPrices)) {
            return null;
        }

        return (float) $planPrices[$key];
    }

    public function portionPrice(string $portion): ?float
    {
        $prices = config('pricing.daily_dish.portion_prices', []);
        if (! array_key_exists($portion, $prices)) {
            return null;
        }

        return (float) $prices[$portion];
    }

    public function addonPrice(string $addon): ?float
    {
        $prices = config('pricing.daily_dish.addon_prices', []);
        if (! array_key_exists($addon, $prices)) {
            return null;
        }

        return (float) $prices[$addon];
    }

    public function portionLabel(string $portion): string
    {
        $labels = config('pricing.daily_dish.portion_labels', []);
        return (string) ($labels[$portion] ?? 'Plate');
    }

    public function bundlePrice(string $bundleType): ?float
    {
        $base = config('pricing.meal_plan.base_prices', []);

        return match ($bundleType) {
            'full' => isset($base['main_plus_both']) ? (float) $base['main_plus_both'] : null,
            'mainSalad', 'mainDessert' => isset($base['main_plus_one']) ? (float) $base['main_plus_one'] : null,
            'mainOnly' => isset($base['main_only']) ? (float) $base['main_only'] : null,
            default => null,
        };
    }
}
