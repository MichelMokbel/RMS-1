# Customer storefront catalog and normal-menu ordering

## Overview

This area owns the RMS-managed public storefront: feature flags, curated customer-facing categories and profiles, date-aware availability, discovery sections, delivery-application links, privacy-bounded funnel events, normal-menu checkout, and the one-category checkout upsell shared by all supported order paths.

## Key files

| File | Owns |
|---|---|
| `StorefrontAdministrationService.php` | Company-scoped settings, categories, item profiles, delivery channels, closed dates, and audit history. |
| `StorefrontAvailabilityService.php`, `StorefrontCatalogService.php` | Published eligibility, advance-day rules, branch availability, current RMS pricing, search, and pagination. |
| `StorefrontContextService.php`, `StorefrontDiscoveryService.php` | Public flags, category navigation, Popular this week, Chef picks, and checkout upsell configuration. |
| `StorefrontMenuQuoteService.php`, `StorefrontMenuCheckoutService.php` | One-service-date server quote and SkipCash checkout retention. |
| `StorefrontMenuOrderCreationService.php`, `StorefrontMenuOrderActivationService.php` | Verified-payment order, invoice, allocation, and confirmation creation. |
| `StorefrontUpsellService.php` | The selected upsell category, per-date eligibility, and full-price add-on quotes. |
| `StorefrontEventService.php`, `StorefrontFunnelReportService.php` | Allowlisted, pseudonymous events and administrator funnel reporting. |

## Conventions

* `menu_items` remains the operational source. A storefront profile is an explicit publication layer, so erroneous raw-material rows do not become public merely because they exist in `menu_items`.
* Normal-menu ordering and checkout upsells have separate RMS toggles. Enabling either never bypasses item publication, active category, branch, closed-date, or item-specific advance-day rules.
* Normal-menu checkout contains exactly one service date. Keep its quote/checkout contracts separable from Daily Dish so a later combined cart can orchestrate both without rewriting their domain services.
* Checkout upsells use exactly one selected active storefront category, appear before final review, and always allow `No thanks`. Empty or unavailable results silently preserve the existing checkout flow.
* Add-ons are selected per service date, priced from the current RMS menu item price, and added to the same SkipCash payment and dated order/invoice. They never consume membership meal credits and membership promo codes never discount them.
* Delivery applications are external destinations only: Talabat, Snoonu, Rafeeq, and Keeta. The public portal does not claim to own their checkout or fulfilment.
* Public events accept only allowlisted bounded fields and pseudonymous journey hashes. Never add customer names, phones, email, addresses, notes, auth tokens, or free-form search text.

## Gotchas

* Archiving or changing a menu item must respect `MenuItemUsageService`; historical orders and invoices retain their snapshots.
* Paid completion must stay idempotent across provider webhook, recovery, and manual consistency paths.
* Popular ranking uses canonical paid activity; browser events are useful funnel signals, not financial truth.
* Relevant suites are `tests/Feature/Storefront/`, `tests/Feature/Payments/SkipCashOrdinaryOrderTracerTest.php`, and `tests/Feature/Subscriptions/MembershipCoveredBookingTracerTest.php`.
