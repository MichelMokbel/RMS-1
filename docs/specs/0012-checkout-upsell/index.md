# 0012. Checkout add-on upsell

**Date**: 2026-09-09
**Status**: Accepted

## Summary

Add one optional checkout add-on step to the Daily Dish and advance menu journeys. RMS administrators choose one existing storefront category and independently enable the upsell. The portal shows only currently published and eligible items from that category. Customers may skip it without friction or add items for each service date.

The server remains the authority for availability, price, membership entitlement, and payment. Add-ons use the current RMS menu price. They never consume membership meals and membership promotion codes never discount them.

## Confirmed experience

* The upsell appears immediately before the existing final review.
* A visible **No thanks** action is always available and continues the existing workflow unchanged.
* Flexible Daily Dish, membership purchase, covered membership booking, and advance menu checkout may show the upsell.
* Customers choose add-ons independently for each service date.
* If the setting is off, no category is selected, or no eligible item exists for the selected dates, the portal silently skips the step.
* A covered membership booking with no add-ons remains a no-payment booking. A covered booking with add-ons starts SkipCash for the add-on total.
* Normal menu checkout remains separate from Daily Dish checkout in this release.

## Accounting and membership contract

* One successful checkout creates one SkipCash payment for the server quoted total.
* Flexible Daily Dish and advance menu add-ons become ordinary priced lines on the same dated orders and invoices and are paid by the same exact payment allocation.
* A membership purchase payment contains the discounted plan amount plus full-price add-ons. The purchase block records only the plan gross amount, plan discount, and plan net amount. The remaining add-on portion is allocated to the dated invoices that contain the selected meals and add-ons.
* A covered membership booking with add-ons creates the same dated membership orders and invoices as the existing booking flow. Membership purchase-block payments fund only the membership meal portions. The new SkipCash payment funds only the add-on portions.
* The dated invoice contains the membership meal funding lines and the full-price add-on lines. It is paid only after all required membership and add-on allocations are present.
* Add-ons never change `plan_meals_total`, `meals_used`, purchase-block positions, or queue order. Only selected main dishes consume membership meals.
* A membership promotion changes only the plan portion. It never discounts an add-on.
* A 100% membership promotion keeps the existing request-only behavior: no payment, membership, booking, invoice, or add-on order is created.
* Voiding the dated invoice follows the existing membership rule for its meal funding and the existing payment allocation void rule for its add-ons. No refund is created. Collected money remains customer credit.
* SkipCash clearing, provider verification, settlement, fees, and default bank payout use the existing payment source and clearing workflow.

## Availability and pricing

An upsell item must be an active menu item, assigned to the configured active storefront category, published for direct ordering in the configured portal branch, available to that branch, and priced above zero. The selected service date must satisfy the item advance-day rule and must not be a closed storefront date. RMS rechecks every rule before creating a payment attempt.

The customer submits only profile identifiers and decimal quantities. RMS validates minimum, increment, and maximum quantities and calculates each line from `menu_items.selling_price_per_unit` using the existing integer QAR cent rules. A stale quote returns the current quote for review.

## Data and state design

`storefront_settings` gains `checkout_upsell_enabled` and nullable `upsell_category_id`. Both are company scoped, revision checked, permission protected, and audited.

Payment targets retain immutable add-on lines in their encrypted snapshot and searchable retained target items. Membership booking payment targets also retain the main quantity and queue root used as a temporary entitlement hold. Open held targets reduce available membership balance. Activation converts the hold to the existing booking funding rows. Decline or expiry releases it. Retry and recovery reuse the same attempt and cannot duplicate meals, orders, invoices, payments, allocations, or notifications.

## API changes

* `GET /api/public/storefront/upsell-items?service_dates[]=YYYY-MM-DD&path_code=...` returns the selected category and eligible items grouped by requested date, using the branch that owns the requested checkout path. It returns an empty data set when the upsell should be skipped.
* Existing Daily Dish ordinary quote and checkout payloads accept an `add_ons` list inside each dated cart item.
* Existing membership purchase quote and checkout payloads accept dated meal selections with `add_ons`.
* Existing covered membership booking quote and creation payloads accept `add_ons`. When the add-on total is positive, creation returns the existing hosted payment status shape instead of completing the booking immediately.
* Existing advance menu checkout simply includes selected upsell profiles in its one dated `menu_order` group. The retained line role marks them as checkout add-ons for reporting, while the order and invoice remain one sale.

Unknown fields remain rejected. Browser return data never proves payment.

## Analytics

The anonymous storefront event allowlist adds modal viewed, skipped, and item added events with the source journey and bounded item count only. It records no names, phone numbers, email addresses, customer IDs, addresses, notes, provider data, IP addresses, or arbitrary metadata. Paid add-on value comes from canonical completed checkout snapshots and provider verified attempts, not from a browser event.

## Acceptance criteria

* **AC-1**: An authorized administrator can choose exactly one active storefront category and independently enable or disable checkout upsell. Unauthorized and cross-company changes are rejected and audited.
* **AC-2**: The portal silently skips the modal when disabled, misconfigured, or empty. Otherwise it presents eligible items before final review with an always available No thanks action.
* **AC-3**: Selections are per service date. The server rejects hidden, unpublished, early, closed-date, incorrectly incremented, zero-price, stale-price, or cross-company items.
* **AC-4**: Flexible Daily Dish and advance menu checkout create the same dated order and invoice with their add-ons and one exact SkipCash payment allocation.
* **AC-5**: A membership purchase charges the plan net plus full-price add-ons in one payment. Its purchase block contains only plan economics. Selected main dishes consume the new sequential allowance and dated invoices receive exact membership and add-on funding.
* **AC-6**: A covered booking without add-ons remains instant and free. With add-ons it holds the requested meal quantity, takes payment, then creates the same dated paid booking invoices. Expiry and decline release the hold.
* **AC-7**: Add-ons never consume meal credits and a membership promotion never discounts them.
* **AC-8**: Exact retries, provider retries, recovery, and browser refresh cannot duplicate any commercial or financial record.
* **AC-9**: Voiding an add-on membership invoice restores only its main meal funding through the current rule, releases all invoice allocations, cancels the linked order, and creates no refund.
* **AC-10**: Anonymous analytics distinguish Daily Dish, membership purchase, covered membership booking, and advance menu modal use. Paid value is calculated only from completed RMS checkout data.

## Verification

Automated tests cover settings authorization and revision conflicts, public eligibility, every quote boundary, stale quote handling, membership promotion isolation, sequential meal funding, held entitlement concurrency, activation, exact allocation by payment source, expiry release, idempotent callback recovery, invoice void restoration, and analytics privacy. Existing Daily Dish, membership, advance menu, clearing, customer merge, and consistency suites must continue to pass.

Manual checks cover about 360 px, 768 px, and desktop widths, keyboard access, No thanks, empty-state skipping, each service-date selector, back navigation, hosted checkout return, paid confirmation, and account history.
