# Daily dish and meal plan pricing

## Overview

This area calculates daily dish selection prices and meal plan prices from configuration. You can reuse it across order creation and recalculation to keep the same selection priced consistently.

## Key files

| File | Owns |
|---|---|
| `DailyDishPricingService.php` | Plate bundles and full or half portion totals. |
| `MealPlanPricingService.php` | Plan totals, bundle prices, portion labels, and add ons. |
| `config/pricing.php` | Price and label configuration. |
| `app/Services/Orders/OrderTotalsService.php` | Recalculation from stored order items. |

## Conventions

* Full and half portions use configured portion prices when a positive portion count is supplied.
* Plate bundles group main, salad, and dessert quantities. The normalization treats `diet` and `vegetarian` as main roles and `sweet` as dessert.
* Subscription plan totals use configured plan keys where present, otherwise the selected salad and dessert combination determines the fallback price.
* These services return decimal values. Converting into AR or POS minor units belongs at the consuming boundary.

## Gotchas

* Browser supplied totals are not a pricing authority. You can recompute from validated selections before persisting an order.
* Default prices in code are fallbacks, not evidence of production pricing.
* Existing behavior is exercised through `tests/Feature/Orders/PublicDailyDishOrderSubmissionTest.php`, `tests/Feature/Subscriptions/`, and `tests/Feature/SubscriptionGeneration/`. There is no dedicated Pricing test directory.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
