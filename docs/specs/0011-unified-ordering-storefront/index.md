# 0011. Unified ordering storefront

**Date**: 2026-09-08
**Status**: Accepted

## Summary

Extend the current orders website into one clear storefront for Daily Dish, memberships, advance menu orders, and delivery application links. Keep each existing purchase flow intact, add the normal menu behind a safe RMS setting, and reuse the accepted SkipCash, order, invoice, payment, allocation, and customer identity contracts. The first build proves one paid menu item through every layer before adding the full catalog and discovery experience.

## Structure

* [Catalog administration](0011-catalog-administration.md) defines explicit publication, customer presentation, safe cleanup, categories, images, company scope, branch scope, and delivery channels.
* [Availability and cart](0011-availability-cart.md) defines lead time, closed dates, quantity rules, one service date, pricing, cart persistence, and server validation.
* [Checkout and accounting](0011-checkout-accounting.md) defines SkipCash start, immutable snapshots, verified completion, accounting, recovery, notification, invoice void, and later combined checkout compatibility.
* [Experience and measurement](0011-experience-measurement.md) defines the storefront hierarchy, route map, popular dishes, Chef picks, delivery application exits, responsive behavior, and anonymous funnel events.
* [Verification plan](verify.md) defines automated and manual proof required before the normal menu can be enabled.

## Requirements

**User stories**:

* As a visitor, I want to understand the available order paths immediately so I can reach the right meal without learning the RMS structure.
* As a customer, I want to browse normal menu items before login, keep my cart through login, choose one valid service date, and pay once without entering the same details again.
* As a member, I want my active membership and Daily Dish access to remain obvious and unchanged when the normal menu is introduced.
* As an administrator, I want to publish only valid customer products, control preparation rules and delivery application links, and disable new normal menu sales without stranding payments already started.
* As the operator, I want each verified payment to produce the current order and paid invoice records without a new delivery or kitchen state process.
* As the accountant, I want normal menu receipts, invoices, allocations, voids, and SkipCash clearing to follow the existing financial contract exactly.
* As the business owner, I want honest popularity and funnel data that helps improve conversion without recording customer details in analytics.

**Acceptance criteria**:

* **AC-1**: An authorized administrator can configure the default company storefront, portal branch, normal menu setting, 11:00 PM Qatar cutoff, unavailable service dates, storefront categories, published items, Chef picks, quantity rules, images, and delivery application links. Every change is company scoped, permission checked, version checked, and audited.
* **AC-2**: The public catalog returns only explicitly published items that are active, available in the configured portal branch, assigned to an active storefront category, and valid for direct ordering. It never exposes recipes, costs, internal codes, raw materials, audit fields, or inactive records.
* **AC-3**: Catalog cleanup produces a reviewable reference report. An unused mistaken item can be deleted. A referenced mistaken item is removed from customer sale and disabled without deleting historical orders, invoices, daily menus, recipes, or audit evidence.
* **AC-4**: Minimum advance days use Qatar calendar dates. Before 11:00 PM the current date is the order date. At or after 11:00 PM preparation starts from the following date. Direct normal menu items require at least one advance day. An unavailable service date moves the earliest choice to the next open date.
* **AC-5**: One normal menu checkout has one service date. The date must satisfy every selected item, so the item with the longest lead time controls the earliest cart date. A changed previously quoted cart returns the complete current quote and revised earliest date without clearing valid selections.
* **AC-6**: Quantity validation uses each item minimum, increment, and optional maximum. The authenticated checkout review supports one optional order note. It does not add variants, modifiers, extras, kitchen capacity, or inventory reservations.
* **AC-7**: RMS is the price authority. The displayed and charged unit price comes from the menu item selling price converted to QAR cents. Each line is calculated with decimal safe arithmetic and half up rounding. A line and cart must each produce at least one payable cent and satisfy the existing payment amount bounds. Tax, delivery charge, discount, membership credit, promotion, and customer saved credit are zero or unavailable for this checkout.
* **AC-8**: Before SkipCash opens, RMS revalidates company, branch, feature state, item publication, quantity, service date, price, terms, customer ownership, phone verification, and payment source. It then retains an immutable cart, price, item, date, customer, delivery address, support contact, terms, source account, and notification snapshot.
* **AC-9**: A verified matching SkipCash payment atomically creates one non Daily Dish order, one issued and fully paid AR invoice, one SkipCash payment, and one exact allocation. Invoice issue recognizes revenue once. Payment receipt uses SkipCash clearing. Settlement, commission, and bank payout remain governed by the existing clearing flow.
* **AC-10**: Client retries, provider retries, browser return, webhook delivery, and scheduled recovery cannot duplicate an order, invoice, payment, allocation, email intent, or ledger entry. Browser return data alone never proves payment.
* **AC-11**: Disabling the normal menu hides new browsing and blocks new menu quotes and payment starts. An attempt whose provider dispatch has started can still verify, recover, complete, notify, and account from its retained snapshot.
* **AC-12**: Every authorized void of the original linked invoice, including void and duplicate correction, releases its allocation, leaves the collected amount as unallocated customer credit, and marks the order and all lines cancelled once from any current state. A replacement invoice does not restore the order. It does not issue a refund. Editing the invoice alone does not change the order, payment, allocation, or cart snapshot.
* **AC-13**: A guest cart survives refresh, login, and path changes for seven days without storing personal data, free text notes, or trusted prices. The note is collected only during authenticated checkout review. Daily Dish and normal menu carts remain separate and clearly labelled. Switching paths does not discard either cart or combine their payment.
* **AC-14**: The order home shows an active membership notice when relevant, Daily Dish as the main order path, normal menu as the second path when enabled, honest Popular this week or Chef picks after the main choices, and delivery applications last. Daily Dish, membership purchase, membership booking, account history, and payment recovery remain easy to reach.
* **AC-15**: Popular this week uses the previous seven complete Qatar dates and counts distinct paid direct normal menu orders. Quantity breaks ties. It shows at most four eligible items only when the period has at least three qualifying orders. Otherwise the website shows manually ordered Chef picks or omits the section. Voided, hidden, inactive, unavailable, internal, and delivery application only items never appear as popular.
* **AC-16**: Talabat, Snoonu, Rafeeq, and Keeta have independent administrator toggles, restaurant URLs, optional item URLs, and display order. An item may be direct only, application only, or both. An item URL wins over its restaurant URL. Application cards omit direct checkout controls, and the application section can remain available when direct normal menu ordering is disabled. An outbound application click creates no RMS order or payment.
* **AC-17**: RMS records an allowlisted anonymous storefront funnel without names, phone numbers, email addresses, delivery addresses, customer IDs, IP addresses, full user agents, provider data, or arbitrary client metadata. Checkout starts and outcomes come from canonical RMS attempts, never client claims. The initial report shows raw stage totals rather than mixing browser and attempt counts into conversion percentages. Raw journey events expire after 180 days.
* **AC-18**: Customer and administrator confirmation emails use retained RMS values after financial commit. Delivery failure remains independently retryable and cannot change payment success or repeat financial effects. The completed order, invoice, and payment appear in the existing customer account.
* **AC-19**: The website works at about 360 px, 768 px, and 1024 px or wider, keeps primary actions visible, uses at least 44 px touch targets, gives controls accessible names, supports keyboard use, and displays useful empty, loading, validation, payment, and recovery states.
* **AC-20**: Existing Daily Dish, membership, flexible order, payment operations, settlement, customer merge, and non gateway accounting contracts remain compatible. The existing `/orders/menu` journey is not repurposed. New normal menu pages use `/orders/advance-menu`. The new checkout uses a versioned order group and existing payment targets so a later mixed checkout can combine target groups without replacing the catalog, order, invoice, payment, or allocation workflows.
* **AC-21**: The published payment terms and customer confirmation explain normal menu advance ordering, the chosen service date, included delivery, no customer refund, retained customer credit after an authorized cancellation, and the fact that external delivery applications own their own checkout and confirmation. A started attempt retains the accepted terms version.

## Decision

**Chosen option**: Extend the existing RMS and orders website behind a company storefront setting.

Keep `menu_items` as the canonical product record. Add a separate customer presentation and sales channel layer, a dedicated menu checkout purpose, and a versioned order group snapshot. Reuse existing identity, SkipCash, order, AR, allocation, clearing, recovery, and mail services instead of building a second commerce system.

**Implementation skills**: `marketing-skills:page-cro` (`alirezarezvani/claude-marketing-skills`, `$CODEX_HOME/skills/marketing-skills/page-cro/`) · `marketing-skills:site-architecture` (`alirezarezvani/claude-marketing-skills`, `$CODEX_HOME/skills/marketing-skills/site-architecture/`) · `marketing-skills:analytics-tracking` (`alirezarezvani/claude-marketing-skills`, `$CODEX_HOME/skills/marketing-skills/analytics-tracking/`)

## Rationale

Reasoning, alternatives, source evidence, and current system diagnosis: see [rationale.md](rationale.md).

## Feature design

### Cross part contract

The RMS API is the only authority for public eligibility, earliest service date, price, checkout state, and paid completion. The website may cache display data and keep an untrusted local cart, but it may not decide whether an item is sellable or what SkipCash should charge.

The first release has separate Daily Dish and normal menu carts and payment starts. A normal menu checkout contains exactly one versioned `menu_order` group and one service date. Payment completion activates one existing payment target. A later combined checkout may add more groups and targets to one attempt, but this release adds no mixed checkout user interface and changes no existing Daily Dish request shape.

Delivery application availability is independent from direct normal menu availability. Turning off direct normal menu sales does not hide valid delivery application links, Daily Dish, memberships, or account history.

### Data model sketch

| Record | Purpose | Main constraints |
|---|---|---|
| `storefront_settings` | Company storefront and portal branch settings | One row per company. Normal menu and delivery application sections default off. Qatar timezone is fixed. Cutoff defaults to `23:00`. Version increases on every change |
| `storefront_categories` | Customer friendly menu sections | Company scoped unique slug. Soft deletion. Active and ordered |
| `storefront_item_profiles` | Customer presentation and direct sale rules for one canonical menu item | Unique company, branch, and menu item. `direct_order_enabled` is the explicit publication state and defaults off. Positive price required at publication. Advance days at least one. Quantity rules must form a valid range |
| `storefront_closed_dates` | Dates unavailable for normal menu service | Unique company, branch, and service date |
| `storefront_delivery_channels` | Talabat, Snoonu, Rafeeq, and Keeta restaurant links and state | Unique company and allowlisted code |
| `storefront_item_channels` | Optional item link and visibility for one application | Unique profile and channel |
| `storefront_events` | Anonymous browsing events | Unique event UUID, event name, journey hash, received time, and nullable allowlisted dimension columns. No free JSON and no customer foreign key. Purge after 180 days |
| `payment_checkout_attempts` | Existing durable payment attempt | Add purpose `menu_order`. Reuse company, branch, customer, source, amounts, fingerprints, immutable snapshots, and recovery state |
| `payment_checkout_targets` | Existing atomic purchase target | One `order` target for the versioned menu group and service date. Reuse linked order and invoice fields |
| `payment_checkout_target_items` | Searchable immutable menu lines for one target | Restrict delete menu item FK. Unique target and line sequence. Retained title, description, unit, decimal quantity, unit cents, and line cents |
| `orders`, `order_items` | Existing operational sale | Store snapshots from the paid target. Set `is_daily_dish = false`, source `Website`, type `Delivery`, and initial status `Draft` |
| Existing AR and payment records | Invoice, receipt, allocation, ledger, clearing, and correction | Follow specs 0001, 0003, 0004, 0005, and 0006 |

All new identifiers use matching unsigned integer foreign keys. Money uses integer QAR cents at the checkout boundary. Quantities use decimal strings with at most three decimal places. Instants use UTC with microseconds. Business dates and cutoffs use `Asia/Qatar`.

### State transitions

```text
catalog profile: hidden -> published -> hidden
checkout: initiating with provider not_sent -> pending -> paid_processing -> completed
checkout: initiating or pending -> declined or expired
dispatch claim: not_sent -> in_flight -> created, rejected, or unknown
started paid attempt: disabled catalog or changed item -> paid_processing -> completed from snapshot
invoice: issued and paid -> voided
linked order after invoice void: any current status -> Cancelled
```

Catalog state affects new actions only. Initial attempt commit uses `provider_create_outcome = not_sent`. Immediately before the provider call, RMS locks the attempt, resolves the default company again, rechecks the storefront flag, and atomically claims `in_flight`. A claim committed as `in_flight`, `created`, or `unknown`, or any existing provider transaction, is started and remains recoverable after the feature is disabled. A committed `not_sent` attempt cannot dispatch after disable and becomes declined with its target released.

### API surface

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/api/public/storefront` | GET | none | navigation state, Qatar date, category summary, popular or Chef picks, application links | public | 503 configuration unavailable |
| `/api/public/storefront/menu-items` | GET | category, search, page | published cards, unit price, quantity rules, earliest date | public | 404 menu disabled, 422 invalid filter |
| `/api/public/storefront/menu-items/{profile}` | GET | profile ID | published item detail and channel links | public | 404 hidden or invalid |
| `/api/public/storefront/delivery-items` | GET | channel, page | enabled application item cards and destinations without direct checkout fields | public | 404 application section disabled, 422 invalid channel |
| `/api/public/storefront/events` | POST | event UUID, journey UUID, event name, allowlisted context | accepted | public, throttled | 202 ignored duplicate, 422 invalid event, 429 limited |
| `/api/customer/checkouts/quote` | POST | purpose `menu_order`, group version, service date, item IDs and quantities, optional note, optional previous fingerprint | server totals, earliest date, fingerprint, terms | customer plus verified phone | 404 disabled, 409 changed previous quote, 422 malformed or invalid first quote |
| `/api/customer/checkouts` | POST | client UUID, purpose `menu_order`, group, quote fingerprint, terms version, journey UUID | checkout reference, state, pay URL | customer plus verified phone | 409 changed request or quote, 422 invalid cart, 503 payment unavailable |
| `/api/customer/checkouts/{reference}` | GET | reference | existing public payment status contract | owning customer | 403 wrong customer, 404 unknown |
| Existing SkipCash webhook | POST | provider event | acknowledged processing result | provider signature | existing provider error contract |

RMS administration remains server rendered Livewire under authenticated web routes. It uses the new `storefront.manage` permission and existing company access checks.

### Value sourcing

| Action | Value produced or displayed | Source |
|---|---|---|
| Build order home | path visibility and ordering | `storefront_settings`, active membership API, and the fixed hierarchy in this spec |
| Build Daily Dish card | next available date and state | existing public published Daily Dish menu response and its current Qatar rules |
| List a menu card | title, description, image, order, category | `storefront_item_profiles` and `storefront_categories`, with menu item name fallback and a standard placeholder |
| List a menu card | unit and unit price | `menu_items.unit` and `menu_items.selling_price_per_unit`, converted to QAR cents by RMS |
| Resolve public company | company and portal branch | existing default company resolver, then that company's `storefront_settings.portal_branch_id`; public input cannot override either |
| Decide direct visibility | sellable item | normal menu enabled, `direct_order_enabled`, active menu item, active category, configured branch pivot, valid quantity rules, allowed `MenuItem::unitOptions()` unit, and positive payable price |
| Calculate earliest item date | earliest service date | Qatar date and time, `storefront_settings.menu_cutoff_time`, profile advance days, and `storefront_closed_dates` |
| Calculate cart date | earliest service date | maximum item earliest date, moved forward through closed service dates |
| Calculate quote | line and checkout totals | retained item price cents multiplied by validated decimal quantity, rounded half up per line, then summed |
| Start payment | immutable purchase intent | authenticated customer, versioned group input, current quote, company storefront revision, current resolved catalog values, payment terms, payment source, source account, and notification recipients |
| Complete purchase | order, invoice, payment, allocation, and accounting dates | verified provider evidence and the retained attempt and target snapshots under specs 0001 and 0003 |
| Complete purchase | delivery address snapshot | address retained at checkout start from portal profile, then canonical customer delivery address fallback, otherwise null |
| Send confirmations | customer and administrator recipients | retained customer email and `MailSettingsService::adminRecipientsForCompany()` at checkout start |
| Show Popular this week | ranked item cards | distinct completed `menu_order` purchases during the previous seven complete Qatar dates, excluding voided invoices and currently ineligible items |
| Show Chef picks | ordered item cards | eligible profiles with `is_chef_pick = true` and profile display order |
| Open delivery application | destination | enabled item URL, otherwise enabled channel restaurant URL |
| Report funnel | anonymous browsing stages and checkout outcomes | distinct journey hashes from allowlisted browser events, plus distinct canonical `menu_order` attempts for checkout start and outcome |

### Key invariants

* A menu item being active is not permission to publish it. `direct_order_enabled` is the sole direct publication flag and defaults off.
* The configured branch belongs to the configured company. Every profile, closed date, checkout, order, invoice, payment, source, and ledger entry agrees on company and branch ownership.
* Public catalog queries apply every eligibility rule on the server. Hidden fields are selected out, not merely removed in the browser.
* One first release menu checkout contains one group, one target, one service date, and at least one valid item.
* A new menu checkout cannot use a service date earlier than its server calculated cart date.
* A started provider attempt completes from its immutable snapshot. Current catalog changes cannot change its amount or strand a verified payment.
* One provider transaction creates at most one RMS payment. One target creates at most one order and invoice. Active allocations never exceed the receipt or invoice balance.
* Revenue is recognized by invoice issue. Receipt debits SkipCash clearing and credits AR. Settlement does not create revenue again.
* Invoice edit has no storefront side effect. Invoice void releases allocation and cancels the order once. No customer refund is issued.
* Popularity is derived only from paid direct menu orders. Delivery application clicks and Daily Dish or membership sales never masquerade as paid menu evidence.
* Client analytics never controls catalog, popularity, money, order, accounting, or checkout outcome counts.

### Security model

Public callers can read only explicitly published display fields and submit allowlisted anonymous events. Public reads are throttled and use fixed query limits. Event input rejects arbitrary keys and never stores request IP, customer identity, contact data, free text, provider values, or a full user agent.

Authenticated customer checkout keeps the existing Sanctum, customer portal, phone verification, ownership, merge, and rate limit boundaries. A customer can read only attempts and records owned by the surviving canonical customer identity. The hosted SkipCash page remains the only place card information is entered, so RMS and the orders website do not receive card details.

RMS writes require an active staff account, `storefront.manage`, access to the owning company, and the configured branch. Setting, publication, date, channel, image, Chef pick, and cleanup actions are audited with actor, company, subject, before values, and after values. Cleanup cannot delete a referenced item.

### Configuration required

No new secret or environment variable is required. Storefront behavior is stored in audited RMS settings. Existing application storage, public URL, customer portal, mail, SkipCash, payment source, clearing account, and queue configuration remain required.

### Critical test scenarios

* Happy path: publish one item, browse as a guest, keep it through login, quote one valid date, complete SkipCash, and assert one retained target line, order, paid invoice, payment, allocation, ledger result, email intent, account result, and canonical completed attempt, verifies **AC-1**, **AC-2**, **AC-5**, **AC-7**, **AC-8**, **AC-9**, **AC-10**, **AC-13**, **AC-18**.
* Cutoff: at 10:59 PM Qatar a one day item starts from today, while at 11:00 PM it starts from tomorrow, and a closed date moves the result to the next open date, verifies **AC-4**.
* Cart conflict: combine items with different advance days and reject an earlier date with the correct revised earliest date without clearing the cart, verifies **AC-5**, **AC-6**.
* Feature state: commit a `not_sent` attempt, disable the menu, prove no provider call occurs, then complete a separately claimed `in_flight` paid attempt from its snapshot, verifies **AC-11**.
* Financial replay: deliver the return and webhook repeatedly and assert one financial result, then exercise ordinary void and void and duplicate across reachable order states and assert one release, one cancelled order, and no restoration from a replacement invoice, verifies **AC-9**, **AC-10**, **AC-12**.
* Catalog safety: prove an active but unpublished raw material is absent, a cross company manager is denied, and a referenced item cannot be deleted, verifies **AC-2**, **AC-3**.
* Discovery: rank eligible items from paid orders, exclude voided and hidden items, switch to Chef picks below the sample threshold, and resolve item and restaurant application links correctly, verifies **AC-15**, **AC-16**.
* Analytics privacy: reject unknown events and personal fields, accept an exact event retry once, purge old events, and count checkout outcomes only from canonical attempts, verifies **AC-17**.
* Experience: verify the agreed hierarchy, separate cart labels, accessible actions, and responsive layouts at the three target widths, verifies **AC-13**, **AC-14**, **AC-19**.
* Regression: run Daily Dish, membership, customer identity, SkipCash, settlement, payment operations, AR void, and consistency suites with the normal menu both off and on, verifies **AC-20**.
* Terms: reject a new attempt with an old accepted terms version, retain the accepted version on a started attempt, and render the confirmed normal menu rules in the published terms and email, verifies **AC-21**.

## Build plan

The project uses a Tracer Bullet approach. The first slice proves one real menu item through RMS publication, the public API, the website cart, SkipCash, order creation, AR, and account history. Later slices widen the same path.

1. Add the minimal company setting, one item profile, permission, public catalog read, `menu_order` quote, normalized payment target line, `not_sent` provider claim, and snapshot aware paid activation for a single item, all defaulted off, satisfies **AC-1**, **AC-2**, **AC-7**, **AC-8**, **AC-9**, **AC-10**, **AC-11**.
2. Add `/orders/advance-menu` and the local cart through login without changing `/orders/menu`, then prove the tracer with provider fakes and the existing sandbox path, satisfies **AC-5**, **AC-13**, **AC-14**, **AC-18**, **AC-19**, **AC-20**.
3. Complete storefront categories, presentation fields, image storage, publication checks, quantity rules, and the safe cleanup report and actions, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-6**.
4. Add Qatar lead time calculation, the 11:00 PM cutoff, unavailable dates, mixed lead time cart validation, and conflict responses, satisfies **AC-4**, **AC-5**, **AC-7**.
5. Complete checkout snapshot versioning, feature disable recovery, account presentation, confirmation retries, invoice void adaptation, published terms, and financial consistency checks, satisfies **AC-8**, **AC-9**, **AC-10**, **AC-11**, **AC-12**, **AC-18**, **AC-20**, **AC-21**.
6. Complete the storefront home, route hierarchy, guest search, category navigation, item detail, persistent separate carts, loading and empty states, accessibility, and responsive behavior, satisfies **AC-13**, **AC-14**, **AC-19**.
7. Add Popular this week, Chef picks, delivery channel administration, outbound application behavior, anonymous browsing event storage, canonical checkout outcome reporting, and retention purge, satisfies **AC-15**, **AC-16**, **AC-17**.
8. Run the cleanup dry run, approve the launch catalog and terms, complete the verification plan, deploy with the production flag off, test one staging checkout with its isolated flag enabled, then enable production normal menu browsing, satisfies **AC-1** through **AC-21**.

## Migration plan

**Strategy**: Feature flag rollout with additive migrations and a strangler change to the existing portal.

**Phases**:

1. Add nullable or safely defaulted storefront tables, permission, service code, and API behavior while the normal menu remains off.
2. Publish one reviewed item in development and prove the complete payment and accounting tracer.
3. Add the full customer experience, delivery channels, analytics, and cleanup tooling without changing existing paths.
4. Run the cleanup report. Delete only approved unused mistakes. Disable referenced mistakes and retain their history.
5. Deploy with the normal menu off. Verify Daily Dish, membership, account, email, and payment recovery paths. Enable the menu only after the launch catalog and sandbox evidence pass.

**Rollback**: Turn off the normal menu setting to block new browsing and payment starts. Keep webhook, status, recovery, notification, void, and accounting code active for every started attempt. The additive schema remains until retained financial and audit records no longer depend on it.

**Risks**: A mistaken publication could expose an internal item. A wrong lead time could promise an impossible date. A price conversion error could charge a different total. A flag implementation that blocks recovery could strand collected money. Publication checks, server pricing, immutable snapshots, default off settings, and the verification gates address these risks.

## Consequences

**Positive**:

* Customers gain a clear route to Daily Dish, memberships, future menu orders, and delivery applications without separate websites or repeated account work.
* The operator keeps the present paid invoice definition of fulfillment and does not gain a new kitchen, delivery, capacity, or stock workflow.
* The catalog remains one product source while customer presentation and channels become explicit and safe.
* Existing payment and accounting evidence remains authoritative and reusable for a future combined checkout.

**Negative and tradeoffs**:

* Administrators must prepare customer content and explicitly publish each item before launch.
* Separate first release carts may make a customer complete two payments when buying Daily Dish and a menu item together.
* First party event storage requires retention cleanup and careful allowlists.
* Quantity rules and closed dates add configuration that must be correct before an item is published.

**Neutral**:

* Immediate normal menu ordering stays with Talabat, Snoonu, Rafeeq, and Keeta because direct items require at least one advance day.
* Popularity starts empty and uses Chef picks until enough paid evidence exists.
* No new tax, delivery fee, refund, promotion, saved credit, modifier, stock reservation, capacity, or delivery state system is introduced.

## Follow-up

* [ ] Prepare customer titles, descriptions, images, storefront categories, quantity rules, lead times, and channel links for the approved launch items before enabling the setting.
* [ ] Review the cleanup report and explicitly approve every hard deletion. Referenced mistaken rows remain disabled for history.
* [ ] Establish a conversion baseline after enough traffic exists. Test hierarchy or copy only after the baseline is trustworthy.
* [ ] Consider combined checkout only after real customer behavior shows that two separate purchases materially reduce conversion.
* [ ] Add the three marketing skill pointers to the appropriate repository context file if future agents should apply those conventions without an explicit request.
