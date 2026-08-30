<?php

use App\Models\MealSubscription;
use App\Models\Order;
use App\Services\Pricing\DailyDishPricingService;
use App\Services\Pricing\MealPlanPricingService;

uses(Tests\TestCase::class);

it('uses current configured rates for requests and subscriptions', function (string $key, float $rate) {
    $pricing = app(MealPlanPricingService::class);

    expect($pricing->planPriceForKey($key))->toBe($rate);
    expect($pricing->subscriptionPrice(new MealSubscription(['plan_meals_total' => (int) $key])))->toBe($rate);
})->with([
    '20 meals' => ['20', 45.0],
    '26 meals' => ['26', 46.15],
]);

it('rounds only completed meal plans', function (string $key, int $count, ?float $total) {
    expect(app(MealPlanPricingService::class)->planTotalForMeals($key, $count))->toBe($total);
})->with([
    'complete 20' => ['20', 20, 900.0],
    'complete 26' => ['26', 26, 1200.0],
    'partial 20' => ['20', 3, 135.0],
    'partial 26' => ['26', 25, 1153.75],
    'above 26' => ['26', 27, 1246.05],
    'no meals' => ['26', 0, 0.0],
    'unsupported plan' => ['30', 30, null],
]);

it('uses configuration instead of separate fixed subscription rates', function () {
    config(['pricing.meal_plan.plan_prices.20' => 47.5]);

    expect(app(MealPlanPricingService::class)->planTotalForMeals('20', 20))->toBe(950.0);
});

it('prices full portions at 240 for selections and recalculation', function (bool $useFallback) {
    $pricing = app(DailyDishPricingService::class);
    expect(app(MealPlanPricingService::class)->portionPrice('full'))->toBe(240.0);

    if ($useFallback) {
        config(['pricing.daily_dish.portion_prices' => []]);
    }

    expect($pricing->computeFromSelection([], 'full', 2))->toBe(480.0);
    expect($pricing->computeOneOffTotal(new Order([
        'daily_dish_portion_type' => 'full',
        'daily_dish_portion_quantity' => 1,
    ])))->toBe(240.0);
})->with([false, true]);
