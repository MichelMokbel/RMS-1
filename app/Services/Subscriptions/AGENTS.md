# Subscriptions and meal plan requests

## Overview

This area owns subscription details, pauses, cancellation, codes, payment links, and usage reconciliation. Meal plan request conversion and generated orders also write related state outside this directory.

## Key files

| File | Owns |
|---|---|
| `MealSubscriptionService.php` | Subscription details, weekdays, pauses, and lifecycle changes. |
| `MealSubscriptionCodeService.php` | Subscription codes. |
| `SubscriptionPaymentLinkService.php` | Payment links, invoice audits, and usage resynchronization. |
| `app/Services/Orders/SubscriptionOrderGenerationService.php` | Order generation by branch and service date. |
| `app/Listeners/SyncSubscriptionMealsOnInvoiceIssued.php` | Usage changes when invoices are issued. |
| `resources/views/livewire/meal-plan-requests/` | Request review and conversion. |

## Conventions

* Date range, weekdays, pause windows, status, and quota all affect eligibility. You can inspect `app/Models/MealSubscription.php` alongside the lifecycle service.
* `uses_invoice_tracking` separates generated order consumption from invoice consumption. `source_payment_id` identifies the payment linked path.
* Usage resynchronization considers subscription item quantities and payment coverage. It is not simply the count of orders or invoices.
* `config/subscriptions.php` maps plan items and generation settings. The scheduler also requires a configured system actor.

## Gotchas

* The invoice listener is discovered automatically. Registering it again can duplicate usage.
* Request conversion has existing order count behavior. Multiple subscription order mappings on one date are supported by the current migrations, so a new integration cannot assume one order per subscription per day.
* Relevant suites are `tests/Feature/Subscriptions/`, `tests/Feature/SubscriptionGeneration/`, `tests/Feature/MealPlanRequests/`, and `tests/Feature/DailyDishOps/`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
