# 0011. Experience and measurement

**Date**: 2026-09-08

## Summary

The order home gives customers a clear first choice, preserves their work, and uses real paid evidence for popularity. Delivery application links remain useful but visually secondary. Anonymous events show where customers stop without feeding untrusted data into business rules.

## Requirements

This child implements **AC-13**, **AC-14**, **AC-15**, **AC-16**, **AC-17**, and **AC-19**.

## Decision

Use one shallow order hub with dedicated paths for Daily Dish, normal menu, memberships, account, and payment recovery. Make the likely direct purchase obvious above the first scroll and keep one primary action per card or step.

### Route and page map

```text
/orders
  /orders/menu                         existing Daily Dish and membership selection
  /orders/daily-dish                   existing alias or path when present
  /orders/advance-menu                 new normal menu listing
  /orders/advance-menu/{item}          new normal menu item detail
  /orders/memberships
  /orders/account
  /orders/payment
```

Do not repurpose `/orders/menu`. Preserve every existing public URL with its current behavior or a compatible alias. Login and phone verification retain the intended destination and return the customer to the same advance menu review. Do not create more than one nested level below `/orders` for this release.

### Order home hierarchy

1. Show a compact active membership notice when the signed in customer has remaining meals. Its action opens membership meal selection. It does not replace the main page for guests.
2. Show Daily Dish as the primary order card with the clearest action and next available date.
3. Show Order from the Menu as the second card only when direct normal menu browsing is enabled.
4. Show Popular this week when qualified evidence exists. Otherwise show Chef picks when configured. Use the correct label and never imply ratings, scarcity, or customer counts that are not measured.
5. Show Order on delivery apps last with Talabat, Snoonu, Rafeeq, and Keeta branding and a clear notice that the selected application handles availability, payment, and confirmation.
6. Keep Memberships, Account, and active cart access in the persistent navigation.

### Normal menu experience

The listing opens with customer friendly category controls and search. Public search matches customer title, customer description, and active storefront category title only. It never searches or returns internal codes or recipe fields. Return 24 items per page and cap a requested page size at 48. Sort by profile display order, effective customer title using the canonical menu name as fallback, then menu item ID. Each card shows image, title, short description when present, QAR unit price, unit, earliest service date, quantity control, and one direct action. Item detail repeats the main action near the top and explains the preparation date before checkout.

The cart drawer or page shows one service date, line quantities, units, line totals, full total, and included delivery. The optional note appears only on the authenticated checkout review and is never written to guest persistent storage. When a new item needs a later date, move the date to the new earliest option and explain why before payment. Keep a visible menu cart badge while the customer visits Daily Dish or account pages.

Login and phone verification appear only when the customer continues to secure checkout. After successful identity work, return to the same review. Disable repeated submit actions while loading. Payment result uses the existing pending, paid processing, completed, and declined language and offers account recovery.

### Popularity rule

Use normalized target items from provider verified completed `menu_order` attempts whose payment finish date falls in the previous seven complete Qatar dates. Group by canonical menu item and count distinct linked orders. Sort descending by order count, then total quantity, then profile display order, then menu item ID. Require at least three qualifying orders in the whole period and return no more than four currently eligible direct items.

Exclude an item when its invoice is voided, its order is cancelled, its profile is hidden, its menu item or category is inactive, its branch mapping is absent, or it is application only. Do not use views, clicks, carts, Daily Dish orders, memberships, manual orders, or application exits as paid popularity evidence.

If the evidence threshold is not met, or no ranked item remains currently eligible, return eligible Chef picks by profile display order. If none exist, omit the whole section without an empty warning.

### Delivery application behavior

Resolve an enabled item URL first. Otherwise use the enabled application restaurant URL. Validate stored URLs as HTTPS and restrict channel codes to the four confirmed values. Open the destination in a safe external navigation context. Do not append customer identity, cart details, phone, email, or address. An anonymous event may contain only the channel code, item profile ID when present, source section, and event UUID.

Read delivery application items from the separate `/api/public/storefront/delivery-items` projection. Application cards omit direct price, service date, and quantity controls. The application section remains available when direct normal menu ordering is disabled, provided its independent company setting and channel are enabled.

### Event contract

Use noun then action names and accept only:

| Event | Produced by | Allowed context |
|---|---|---|
| `storefront_viewed` | browser | allowlisted path code |
| `path_selected` | browser | allowlisted path code |
| `category_viewed` | browser | category ID |
| `search_used` | browser | result count and query length, never query text |
| `item_viewed` | browser | profile ID and source section |
| `item_added` | browser | profile ID, source section, and quantity bucket |
| `item_removed` | browser | profile ID |
| `cart_viewed` | browser | line count |
| `service_date_selected` | browser | lead day count from 1 through 365 |
| `delivery_app_opened` | browser | channel code, optional profile ID, source section |

The browser generates an event UUID and a random journey UUID. RMS stores an HMAC SHA256 hash of the journey UUID using the existing application key, not the submitted value. Exact event UUID retry is accepted once. Do not store customer or user foreign keys, IP address, full user agent, free text, URL query strings, provider references, checkout references, or arbitrary JSON.

Store event name, server received time, journey hash, source `browser`, and only these nullable typed columns: profile ID, category ID, channel code, path code, source section, result count, query length, quantity bucket, line count, and lead day count. Path code is one of `order_home`, `daily_dish`, `advance_menu`, `memberships`, `account`, `payment_recovery`, or `delivery_apps`. Source section is one of `order_home`, `menu_list`, `menu_search`, `menu_detail`, `popular`, `chef_picks`, `cart`, or `delivery_apps`. Quantity bucket is one of `under_one`, `one`, `two_to_three`, or `four_plus`. Result count is from 0 through 48, query length from 0 through 120, line count from 0 through 100, and lead day count from 1 through 365. The event validator rejects keys that are not allowed for the selected event. No metadata JSON is accepted. Browser event posts are limited to 60 per IP per minute without retaining the IP. Public catalog reads use the existing public limit of 120 per minute. Purge rows 180 days after receipt with a repeat safe daily command.

Checkout starts, declines, processing states, and paid completions are not analytics events. The RMS report derives them directly from canonical `payment_checkout_attempts` with purpose `menu_order`, so browser claims cannot create or alter financial outcomes.

### RMS funnel report

Show a selectable Qatar date range, defaulting to the previous 30 complete Qatar dates, with order home views, path choices, item views, adds, cart views, delivery application exits, checkout starts, declines, paid processing attempts, and paid completions. Browser stages use `received_at` converted to Qatar dates and count distinct journey hashes. Checkout starts use `started_at` converted to Qatar dates and count distinct canonical `menu_order` attempts. Outcome totals use that same start cohort and the attempt state at report time. The first report shows raw stage totals only and does not present mixed source conversion percentages.

Label browser totals as directional because blockers and abandoned tabs can reduce delivery. Attempt totals are canonical payment workflow counts. The report is company scoped and read only. It must not expose journey hashes or support tracing a journey back to a customer.

### Responsive and accessible behavior

Use the existing website design system. At about 360 px, show one column, keep the current primary action reachable, and make the cart a fixed bottom action only when it does not cover required content. At about 768 px, use compact grids. At about 1024 px or wider, keep content width controlled and avoid excessive card density.

Every control has a visible label or accessible name, keyboard focus, error association, and at least a 44 px touch target. Images have useful alternative text or are marked decorative. Loading uses stable placeholders. Empty states give the next useful action. Motion respects reduced motion preferences.

## Build slice

1. Add the order hub and `/orders/advance-menu` path around the tracer item while preserving the existing `/orders/menu` journey.
2. Add categories, search, item detail, persistent cart access, login return, and responsive states.
3. Add paid popularity from normalized target items, Chef picks, the independent application projection, application exits, and the complete browser event allowlist.
4. Add the RMS raw funnel report from browsing events and canonical attempts, retention purge, accessibility checks, and responsive browser checks.

## Rationale

A clear path choice reduces cognitive load while protecting Daily Dish as the main product. Real order evidence keeps social proof honest. First party events avoid a new analytics vendor and keep control of the data, but they remain advisory and isolated from pricing, popularity, payments, and accounting.
