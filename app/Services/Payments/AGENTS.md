# Payments, SkipCash, and consistency operations

## Overview

This area owns secure checkout quoting and activation, SkipCash provider communication, payment operations, settlement import and posting, terms acceptance, saved-credit allocation, and post-payment consistency checks. Payment completion can create ordinary orders, normal-menu orders, memberships, covered membership bookings, invoices, allocations, and notifications.

## Key files

| File | Owns |
|---|---|
| `MembershipQuoteService.php`, `MembershipCheckoutService.php`, `MembershipCheckoutActivationService.php` | Paid membership price, retained checkout, verified activation, and initial meal selections. |
| `MembershipBookingCheckoutService.php`, `MembershipBookingCheckoutActivationService.php` | Covered-booking meal holds and optional paid add-ons. |
| `OrdinaryOrderQuoteService.php`, `OrdinaryOrderCheckoutService.php`, `OrdinaryOrderActivationService.php` | Flexible Daily Dish quote, checkout, and dated order/invoice activation. |
| `SkipCashProvider.php`, `HttpSkipCashProvider.php`, `SkipCashWebhookService.php` | Provider boundary, signed requests, verified callbacks, and idempotent completion. |
| `GatewaySettlement*`, `SkipCashSettlementReportParser.php` | SkipCash report evidence, fees, clearing, review, and bank settlement posting. |
| `PaymentConsistency*`, `PaymentOperations*` | Automated and administrator-visible detection, evidence, recovery, and resend operations. |
| `PaymentTermsService.php`, `config/payment_terms.php`, `resources/legal/payment-terms/` | Published legal versions and immutable acceptance evidence. |

## Conventions

* RMS is the source of truth for payment state. A browser return URL never completes a payment without a verified provider result.
* Retried checkout starts, provider events, activations, imports, and consistency runs must not duplicate payments, orders, memberships, invoices, allocations, redemptions, or messages.
* Checkout quote fingerprints cover every server-priced input. Requote and require a fresh review when availability, current menu price, promotion terms, add-ons, or legal terms change.
* SkipCash is its own payment method and posts through its configured clearing account. Settlement fees and commissions are recorded from imported provider evidence before the net amount reaches the default bank.
* A membership package payment remains one payment. Dated membership invoices consume the package through allocations; full-price checkout add-ons do not consume meal credits and are allocated as their own invoice value.
* One verified checkout may fund the main purchase and its checkout add-ons, but every allocation must still reconcile exactly to its dated invoice and company/customer ownership.

## Gotchas

* Do not put external provider requests or notification delivery inside a financial database transaction without the existing retry design.
* A successful payment with a failed email remains a successful payment. Resends use retained snapshots and operations permissions.
* One-hundred-percent membership promotions create only the request/redemption path; they do not create payment, subscription, order, invoice, allocation, or add-on records.
* Relevant suites are `tests/Feature/Payments/`, `tests/Feature/Storefront/`, and `tests/Feature/Subscriptions/MembershipCoveredBookingTracerTest.php`.
