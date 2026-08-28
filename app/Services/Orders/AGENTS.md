# Orders, kitchen workflow, and portal submissions

## Overview

This area owns ordinary orders, daily dish orders, subscription generation, kitchen transitions, and portal submission history. You can distinguish these paths through source, service date, branch, and daily dish fields.

## Key files

| File | Owns |
|---|---|
| `OrderCreateService.php` | Manual and subscription related order creation. |
| `CustomerDailyDishOrderService.php` | Customer daily dish submission. |
| `CustomerPortalOrderIdempotencyService.php` | Submission replay cache and payload matching. |
| `CustomerPortalOrderAuditService.php` | Portal submission audit records. |
| `OrderWorkflowService.php`, `OrderStatusService.php` | Distinct status changing paths. |
| `OrderTotalsService.php`, `OrderNumberService.php` | Totals and numbering. |
| `SubscriptionOrderGenerationService.php` | Dated subscription order generation and run logs. |

## Conventions

* `OrderWorkflowService` owns the kitchen transition map, item cascades, row locks, and `OpsEvent` records. `OrderStatusService` is a separate, less restrictive path, so you can inspect callers before choosing one.
* Manual daily dish creation requires a published menu for the selected branch and date. Subscription orders retain their distinct source and mapping records.
* Portal replay keys are scoped to the user. A supplied `client_uuid` must match the normalized payload when the result is cached.
* Generated order and invoice based subscription usage are different accounting paths. The tracking flag prevents generation from also consuming invoice tracked meals.

## Gotchas

* Portal replay uses a 600 second cache lifetime and a cache lock. It is not durable payment or provider event deduplication.
* Order source alone does not distinguish all daily dish paths. You can also inspect `is_daily_dish`, portion fields, subscription mappings, and user ownership.
* Relevant suites are `tests/Feature/Orders/`, `tests/Feature/KitchenOps/`, `tests/Feature/SubscriptionGeneration/`, and `tests/Feature/CustomerPortal/`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
