# Membership promotions

## Overview

This area owns administrator-generated membership promo codes, immutable activated terms, first-purchase and renewal eligibility, bounded use, checkout reservations, permanent redemptions, request-only 100% offers, and consistency projections.

## Key files

| File | Owns |
|---|---|
| `MembershipPromotionService.php` | Draft creation, activation, pause/resume/expiry, copying, and safe limit increases. |
| `MembershipPromotionQuoteService.php` | Company, plan, date, eligibility, capacity, per-customer, and discount evaluation. |
| `MembershipPromotionReservationService.php` | Paid-checkout holds, release, and permanent redemption. |
| `MembershipPromotionRequestService.php` | One-hundred-percent request-only redemption. |
| `PromotionUsageProjectionService.php`, `PromotionUsageConsistencyRule.php` | Counters and cross-record consistency evidence. |

## Conventions

* Administrators generate and manually share codes. Customers may submit one promo code; customer credit is not a public checkout input.
* Each code configures first-purchase, renewal, or both eligibility plus plan scope, UTC validity, total limit, and per-customer limit.
* Activated offer terms are immutable. Copy an offer to change commercial terms; lifecycle changes and limit increases use expected revisions and operation UUIDs.
* The default company owns promotions. Customer merges provide the established resolution path for duplicate customer identities.
* Membership promotions discount only the membership package. Optional checkout add-ons remain at the current RMS menu price and never consume promotion value or meal allowance.
* A 100% promotion is redeemable once per customer and creates only a meal-plan request with permanent redemption evidence.

## Gotchas

* Capacity checks include completed redemptions and live reservations. Use locking and the existing reservation service on paid checkout paths.
* Promotion consistency compares membership-only gross, discount, and net values even when the checkout payment also includes full-price add-ons.
* Relevant suites are `tests/Feature/Subscriptions/MembershipPromotion*` and the promotion scenarios in `tests/Feature/Payments/SkipCashMembershipPurchaseTracerTest.php`.
