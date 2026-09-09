# 0011. Unified ordering storefront verification

## Purpose

This plan proves the storefront without risking existing customer journeys or financial records. Run automated tests only against the isolated `store_test` database. Use sandbox provider credentials for provider facing tests.

## Gate 1: Schema and defaults

1. Run all new migrations on an empty test database and on a representative schema snapshot.
2. Confirm every new foreign key type matches its parent, every unique constraint rejects duplicates, and the target item menu foreign key restricts deletion.
3. Confirm the normal menu, delivery application section, channel toggles, and all direct item publication flags default off.
4. Confirm rollback leaves existing menu, order, payment, AR, and customer records intact.

Evidence: migration test output and schema inspection. Covers **AC-1**, **AC-2**, **AC-20**.

## Gate 2: Administration and cleanup

1. Test an allowed administrator and a denied staff user.
2. Test cross company and cross branch rejection.
3. Test version conflict, audit values, publication blockers, image replacement, category state, closed dates, and every delivery channel.
4. Run the cleanup report against fixtures for unused, ordered, daily menu, recipe, subscription, quotation, sale, pastry, storefront profile, and immutable checkout target item references.
5. Prove only the unused fixture can be deleted. Prove a concurrent checkout claim and cleanup deletion lock the affected menu items in ascending ID order and cannot produce an orphaned target line.

Suggested suites: `tests/Feature/Storefront/StorefrontAdministrationTest.php` and `tests/Feature/Storefront/MenuItemCleanupTest.php`. Covers **AC-1**, **AC-2**, **AC-3**, **AC-16**.

## Gate 3: Dates, quantities, and pricing

1. Freeze time before, at, and after 11:00 PM Qatar.
2. Test one day and several day lead times across month and year boundaries.
3. Test closed dates and a cart with different lead times.
4. Test minimum, increment, maximum, three decimal quantities, and invalid decimal input.
5. Test three decimal RMS prices converted to cents and line rounding without binary floating point. Reject every line or cart below one cent and outside the existing payment amount bounds.
6. Test the first invalid quote as 422 with a complete current quote. Submit its fingerprint, change item, date, quantity, or price, and assert 409 with the complete replacement quote while preserving valid cart lines.

Suggested suite: `tests/Feature/Storefront/MenuOrderQuoteTest.php`. Covers **AC-4**, **AC-5**, **AC-6**, **AC-7**.

## Gate 4: Paid tracer and accounting

1. Publish one item and complete one menu checkout with the provider fake.
2. Assert one order, one invoice, one payment, one allocation, balanced ledger entries, one activated target, and one completed attempt.
3. Assert one normalized immutable target item exists and that exact retained title, description, unit, quantity, unit cents, line cents, customer address, support contact, and total are written to the order and invoice.
4. Assert revenue appears at invoice issue and receipt uses SkipCash clearing, with no settlement or second revenue entry.
5. Replay client create, provider event, browser return, status recovery, activation, and notification jobs.
6. Force a financial lock and prove public `paid_processing` with no partial records, then recover with the retained financial dates.
7. Change the current catalog, customer profile, address, support contact, and finance defaults after provider dispatch. Assert activation and confirmations still use the retained values and no generic recalculation changes the paid order or invoice.
8. Test current terms acceptance, stale terms rejection, retained started terms, and confirmation content without delivered language.

Suggested suites: `tests/Feature/Payments/SkipCashMenuOrderTracerTest.php` and `tests/Feature/Storefront/MenuOrderAccountingTest.php`. Covers **AC-8**, **AC-9**, **AC-10**, **AC-18**, **AC-21**.

## Gate 5: Disable, correction, and recovery

1. Disable before quote and assert no public menu and no new payment start.
2. Commit an attempt as `not_sent`, disable before the dispatch claim, and assert it becomes declined, releases its target, and never calls SkipCash.
3. Disable after the dispatch claim with provider create `in_flight`, `created`, and `unknown`, then verify each can recover from the retained provider request UUID and complete. Prove an ambiguous `in_flight` result never creates a replacement provider checkout automatically.
4. Change price, item state, category, branch state, cutoff, and closed date after dispatch, then complete from the retained snapshot.
5. Edit the invoice and assert no storefront side effect.
6. From every reachable order and line status, run ordinary invoice void and `voidAndDuplicate()`. Assert one allocation release, one unallocated customer credit balance, the order and all lines cancelled, no refund, and no restoration when the replacement invoice is issued.

Suggested suite: `tests/Feature/Storefront/MenuOrderRecoveryAndVoidTest.php`. Covers **AC-10**, **AC-11**, **AC-12**.

## Gate 6: Discovery and analytics

1. Test Popular this week over exact Qatar window boundaries and tie rules.
2. Exclude voided, cancelled, hidden, inactive, wrong branch, application only, Daily Dish, membership, and manual orders.
3. Test the three order threshold, top four limit, Chef pick fallback, and empty fallback.
4. Test direct and delivery application projections separately. Prove application cards omit direct price, date, and quantity, item URL wins over restaurant URL, disabled channels disappear, the application section can remain available while direct ordering is off, and an exit creates no RMS order.
5. Test every browser event against its exact typed columns, reject unknown keys and personal fields, accept one duplicate UUID only once, enforce throttle, and purge after 180 days.
6. Test popularity uses normalized paid target items, falls back to Chef picks when the threshold fails or all ranked items become ineligible, and otherwise omits an empty section.
7. Test the report defaults to the previous 30 complete Qatar dates, uses `received_at` for browser stages, uses the `started_at` cohort and current state for canonical checkout outcomes, counts distinct journey hashes and attempts respectively, and shows raw totals without mixed source conversion percentages.

Suggested suites: `tests/Feature/Storefront/StorefrontDiscoveryTest.php` and `tests/Feature/Storefront/StorefrontAnalyticsTest.php`. Covers **AC-15**, **AC-16**, **AC-17**.

## Gate 7: Website experience

1. Verify order home hierarchy as guest, customer without membership, and customer with remaining membership meals. Confirm the current `/orders/menu` journey is unchanged and normal menu pages use `/orders/advance-menu`.
2. Verify separate Daily Dish and normal menu carts, seven day persistence, login and phone verification return, item conflicts, and all payment states.
3. Verify keyboard path, focus, accessible names, errors, reduced motion, and 44 px targets.
4. Verify about 360 px, 768 px, and 1024 px or wider in a real browser.
5. Confirm the new guest cart and analytics implementation adds no personal values to browser storage, analytics requests, URLs, or logs. Confirm the order note is absent from guest storage and appears only in authenticated review.

Evidence: website automated tests, browser screenshots, and one recorded sandbox journey. Covers **AC-13**, **AC-14**, **AC-19**.

## Gate 8: Regression and launch

1. Run the full affected RMS suites for menu, categories, orders, customer portal, customer identity, AR, payment, settlement, mail, membership purchase, membership booking, promotions, and consistency.
2. Run `./vendor/bin/pint --dirty`, `npm run build`, and `git diff --check` in RMS.
3. Run the customer website test and build commands from its own `AGENTS.md`.
4. Deploy with the normal menu off and verify current Daily Dish, membership, account, email, and payment recovery journeys.
5. Run the catalog cleanup dry run and obtain explicit approval for each deletion.
6. In an isolated staging environment, enable only the staging storefront and complete one sandbox payment before production rollout. Keep the production flag off until the evidence is approved and do not add a production preview bypass.

Evidence: command output, cleanup approval, sandbox reference, and responsive screenshots. Covers **AC-1** through **AC-21**.
