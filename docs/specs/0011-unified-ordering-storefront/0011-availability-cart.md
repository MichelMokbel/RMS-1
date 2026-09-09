# 0011. Availability and cart

**Date**: 2026-09-08

## Summary

Normal menu availability is calculated by RMS from one service date, item lead times, one Qatar cutoff, and closed dates. The browser keeps a convenient cart, while RMS revalidates every trusted value before payment.

## Requirements

This child implements **AC-4**, **AC-5**, **AC-6**, **AC-7**, and **AC-13**.

## Decision

Use calendar lead days with a company cutoff of 11:00 PM Qatar time. Direct items require at least one day. The longest item lead controls the cart and closed dates are unavailable for service.

### Availability algorithm

Let `order_day` be the current Qatar date before 11:00 PM. At or after 11:00 PM, let it be the following Qatar date. For an item with `advance_days`, its candidate date is `order_day + advance_days`. If that date is closed, move forward until an open date is found. The cart earliest date is the latest candidate across its items, then moved forward if needed until open.

Advance days are calendar days, not working days. Closed dates block service but do not change how preparation days are counted. A later setting change affects a new quote only. A started checkout retains the accepted date and cutoff snapshot.

### Quantity and price

The API accepts quantities as canonical decimal strings with at most three decimal places. A quantity is valid when it is at least the item minimum, no greater than the optional maximum, and `(quantity - minimum)` is an exact multiple of the increment using scaled integer arithmetic.

Accept units only from `MenuItem::unitOptions()`. Convert `selling_price_per_unit` to integer QAR cents with decimal safe half up rounding for public display and checkout. Multiply those cents by the scaled quantity, round half up once per line, and sum line cents. Do not use binary floating point. Each line and the cart total must be at least one cent and pass the existing checkout amount guard, including its exact JSON integer and provider bounds. Return the exact quantity, unit, unit price cents, line cents, and total cents.

There is no tax, delivery charge, discount, promotion, membership credit, or customer saved credit. The website cannot submit unit prices or totals.

### Cart contract

The first release group schema is `menu-order-v1`:

```json
{
  "version": "menu-order-v1",
  "service_date": "YYYY-MM-DD",
  "items": [
    {"menu_item_id": "123", "quantity": "2.000"}
  ],
  "note": null
}
```

Canonicalize items by numeric menu item ID, reject duplicates, reject unsupported keys, trim outer note whitespace, and limit the note to 500 characters. A group must contain at least one item. The note is absent from the guest cart and is added only during authenticated review before the first quote.

The browser stores only version, item IDs, quantities, chosen date, and last update time. It stores no note, name, phone, email, address, customer ID, trusted price, terms, payment value, or provider value. Expire it seven days after the last cart change. Rehydrate display fields from RMS and mark changed or unavailable items for review.

Daily Dish and normal menu use different storage keys, cart badges, and checkout actions. Switching paths preserves both. Login returns the customer to the normal menu review when that is where the purchase started.

### Conflict behavior

The first quote omits `previous_quote_fingerprint`. Malformed or currently invalid first quote input returns 422. A successful first quote returns the canonical group, current lines, current earliest date, terms, total, and fingerprint.

A later quote may submit the prior fingerprint with the same intended group. If current canonical values produce a different fingerprint, return 409 with code `MENU_CART_CHANGED` and the complete current quote, current valid items, removed item IDs, earliest service date, total cents, and new fingerprint. The browser compares that response with its displayed prior quote and shows the changes. RMS never invents a replacement quantity, silently charges a new amount, or clears valid lines. Checkout start performs the same comparison against its required quote fingerprint and returns the complete current quote on conflict.

The fingerprint is SHA256 over `menu-order-quote-v1`, default company ID, portal branch ID, canonical group, company storefront revision, resolved profile values, canonical menu item activity, branch availability, unit, unit price cents, quantity rules, lead days, relevant closed date result, terms version and hash, line cents, and total cents. The fingerprint is evidence of the quoted values, not payment authority.

Return 404 with `MENU_ORDER_DISABLED` when new normal menu browsing or quoting is disabled. Return 422 for a malformed first request, malformed quantity, unsupported unit, unavailable item, empty cart, invalid date, invalid note, or a value that rounds below one cent. Return 409 only when a previously successful quote or checkout start has changed.

## Build slice

1. Implement the Qatar clock and lead date service with boundary tests at 10:59 PM and 11:00 PM.
2. Implement closed dates, mixed lead carts, scaled quantity validation, cents pricing, and quote fingerprints.
3. Add the local cart adapter, seven day expiry, login continuation, separate badges, and conflict review.

## Rationale

One service date keeps production and invoice behavior aligned with the confirmed first release. A local guest cart removes early login friction, while server validation prevents stale browser data from deciding availability or money. Full capacity and weekday calendars would add an operating system the business does not use today.
