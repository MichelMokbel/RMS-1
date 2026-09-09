# 0011. Unified ordering storefront rationale

## Context

The current customer website is centered on Daily Dish and membership journeys. Customers also need to order ordinary menu items that require different preparation lead times. Adding those items to the current first screen without a clear information structure would make Daily Dish harder to find and would increase choice friction.

The RMS already owns menu items, branch availability, orders, customer identity, SkipCash checkout, AR invoices, payments, allocations, clearing, settlement, corrections, mail, and consistency checks. The `menu_items` table also contains mistaken raw materials and internal rows. Public use therefore needs an explicit publication boundary and a safe cleanup process, not a second product source or trust in the existing active flag.

The operation is managed by one person. A new kitchen capacity, stock reservation, delivery area, delivery state, modifier, tax, promotion, or refund system would add work without serving the confirmed release. Paid invoice remains the operational fulfillment signal. Invoice void remains the cancellation signal and collected money remains customer credit.

This feature handles hosted payment and personal account data. Existing customer isolation, provider verification, secret redaction, and financial audit rules remain mandatory. The website never receives card details.

## Current system diagnosis

`MenuItem` currently carries operational name, Arabic name, category, recipe, selling price, unit, active state, display order, and branch availability. It has no customer description, storefront image, publication flag, lead time, quantity rule, Chef pick, or delivery application mapping. `is_active` serves wider operational uses and cannot safely mean public sale.

`OrderCreateService` already creates non Daily Dish orders with item snapshots, but it recalculates totals and reads current values. A paid immutable checkout therefore needs a narrow snapshot aware writer beneath the existing transaction owner. The current SkipCash foundation already retains durable attempts and targets, verifies provider evidence, creates payments and allocations, recovers interrupted attempts, and presents customer status. Existing ordinary payment code is currently shaped around flexible Daily Dish carts, so the new menu group needs its own schema, normalized retained target lines, and activation adapter rather than another gateway integration.

The existing `/orders/menu` route already belongs to Daily Dish and membership selection. Normal menu pages therefore use `/orders/advance-menu` and preserve the current route contract.

The portal branch and company boundaries already exist. Payment settings already establish `Asia/Qatar`, checkout duration, support phone, and the accepted payment source. Storefront settings belong in their own audited record because publication and navigation are not payment provider configuration.

## Options considered

### Option 1: Extend in place behind a storefront setting

Keep the canonical menu, order, payment, and accounting records. Add customer profiles, safe publication, a menu checkout adapter, and website routes beside the current paths. (basis: RMS service boundaries, specs 0001 through 0010, and feature flag rollout)

**Pros**:

* Reuses tested money, identity, and order behavior.
* Ships one thin real purchase before expanding.
* Turning the feature off contains launch risk without blocking collected payments.

**Cons**:

* Adds adapters and profile records to an already broad monolith.
* Requires careful regression testing across both repositories.

### Option 2: Add a separate commerce catalog and checkout beside RMS

Copy sellable products into a dedicated storefront model and synchronize paid results back to RMS. Retire the new duplicate only if RMS later becomes the sole commerce source. (basis: strangler pattern for isolated replacements)

**Pros**:

* Customer presentation can evolve independently.
* Storefront queries are isolated from messy operational data.

**Cons**:

* Product, price, availability, customer, and payment identity can drift.
* The operator would manage synchronization and reconciliation failures.

### Option 3: Replace the current customer portal and order flow directly

Build one new portal, catalog, cart, checkout, and order workflow, then move every journey in one release. (basis: direct replacement pattern)

**Pros**:

* Produces one uniform client architecture immediately.
* Avoids temporary route and component differences.

**Cons**:

* Expands risk across working Daily Dish, membership, identity, payment, and account journeys.
* Delays useful normal menu ordering until the full replacement is complete.

## Rationale

Option 1 is the smallest design that preserves every confirmed business rule. Money and customer identity already have accepted contracts, so duplicating them would create more failure modes than value. A customer presentation profile solves the real catalog safety problem without treating mistaken data as an architectural product model.

The feature setting and immutable checkout snapshot serve different failure boundaries. The setting stops new exposure immediately. The snapshot lets provider work already started finish even if catalog configuration changes. This is essential because a visibility control must never become a payment recovery blocker.

The route hierarchy, persistent guest cart, one service date, honest popularity, and anonymous funnel follow the conversion goal without inventing new operational states. The Tracer Bullet order proves the riskiest path first, from publication through payment and accounting, before investing in discovery breadth.

## References

**Project sources**:

* `AGENTS.md`, project priorities, build approach, money rules, isolation, tests, and responsive UI contract.
* `app/Services/Menu/AGENTS.md`, canonical menu item, branch availability, snapshots, and usage checks.
* `app/Services/Orders/AGENTS.md`, order creation and lifecycle ownership.
* `app/Services/AR/AGENTS.md`, invoice, allocation, void, and customer advance behavior.
* `routes/AGENTS.md`, public and customer API boundaries.
* `resources/views/AGENTS.md`, Livewire and settings page conventions.
* `database/AGENTS.md`, additive migrations and safe constraints.
* Specs 0001, 0002, 0003, 0004, 0005, 0006, 0007, 0008, 0009, and 0010.
* `marketing-skills:page-cro`, value clarity, action hierarchy, scanability, friction reduction, and trust rules.
* `marketing-skills:site-architecture`, shallow paths, clear navigation, and human readable route structure.
* `marketing-skills:analytics-tracking`, event taxonomy, deduplication, conversion authority, and data quality.

**Practices and standards**:

* Feature flag rollout for changes with customer and financial blast radius.
* Strangler pattern for extending a live portal without replacing working journeys.
* Server authoritative pricing and idempotency for payment mutations.
* Immutable payment intent snapshots and monotonic verified payment facts.
* Data minimization and purpose limited anonymous analytics.
* Honest social proof based on observed paid behavior.
