# 0008. Covered membership booking and correction

**Date**: 2026-08-31
**Status**: In Progress
**Scope**: Feature 10. Queue projection, covered quote and confirmation, exact sequential funding, paid invoice allocation, direct invoice void restoration, standing-generation guards, customer change/cancel, pause correction, booking history, and customer confirmations were implemented behind disabled launch flags by 2026-09-06. Website surfaces, legacy opening, and full release verification remain pending.

## Summary

Returning members see how many meals they have selected and how many remain, then book from their existing allowance without another payment. Main dish quantities consume the oldest available funded allowance; sides and delivery are included. Before the saved cutoff they can change or cancel a booking, while RMS invoice voids and pauses restore the original funding correctly.

## Requirements

**User stories**:

* As a member, I want to return weeks later, book meals for myself or family and never pay for the same allowance again.
* As the operator, I want invoice voiding and pauses to update bookings, allocations and meals together without managing delivery states.

**Acceptance criteria**:

* **AC-1**: Owned queue reads show total, invoice based used, reserved, selected, available and ordered remaining allowance without double counting future paid invoices. Pending free requests and unreconciled legacy balances are separate.
* **AC-2**: Confirming eligible selections creates actual orders and daily invoice funding using main quantities and the oldest free block positions. One booking can cross blocks and payments. Included sides count zero; no new purchase payment, promo use, conversion or delivery charge occurs.
* **AC-3**: New online selections exclude Qatar today and earlier dates. Mixed carts return today exclusions and contact for explicit review while eligible future dates can proceed. Booking begins on server confirmation, not first selection; no unselected date is generated automatically.
* **AC-4**: Customer change or cancellation is allowed strictly before the previous calendar day at the saved cutoff, default 23:00 Asia/Qatar. At or after it, reject without side effects and show contact. A setting/profile change does not alter an existing snapshot.
* **AC-5**: Authorized invoice void, including an edited invoice, releases actual allocations, cancels its linked subscription order and restores original block quantities/positions exactly once. Invoice edit alone does none of these. No delivery status or extra cancellation classification is required.
* **AC-6**: An RMS pause voids/cancels future bookings in its inclusive period, releases allocations and restores balances atomically, despite paid invoice fulfillment. Finance locks remain enforced; customer cutoff does not block staff pause correction.
* **AC-7**: Retries and concurrent bookings, voids, changes, admin allocations and merged customer writes cannot overbook, reuse occupied positions, spend committed funds, duplicate orders or restore twice.
* **AC-8**: The website shows covered booking and Buy another membership separately, preserves customer scoped drafts, obtains status from RMS and supports reload/another device. Booking confirmations are durable after commit and do not control entitlement.
* **AC-9**: Existing standing subscriptions, manual invoice workflows and published menu availability remain compatible. Quantity counter/listener/generation changes are limited to verified queue mode. Both app and diagnostic tests are required before launch.

## Decision

**Chosen option**: One queue booking service coordinates existing orders, subscription order mappings, AR invoices and the funding rows defined in 0001. A changed booking retains its old cancelled order and invoice history and creates a replacement revision, not an individual meal entity or a new kitchen workflow.

## Rationale

See [rationale.md](rationale.md).

## Feature design

### Data model

| Record | Contract |
|---|---|
| membership_booking_funding | Create 0001 fields and unique purchase_block_id/subscription_order_id. Positive integer main quantity equals the count in its sorted nonoverlapping compact position ranges. Invoice/allocation references are nullable while reserved; allocation is also null for a zero net row. State is reserved/invoiced/released, with original cents, dates and cutoff retained after release. A row materialized from later void of 0007 closed opening evidence also retains its manifest row/evidence key; that key and invoice are unique and the row begins released |
| meal_subscription_orders | Add nullable booking_uuid, booking_revision unsigned integer, supersedes_subscription_order_id FK and accepted_operation_uuid; encrypted notification_snapshots and bounded notification_dispatch slots for creation/change/cancellation. Unique booking_uuid/booking_revision; existing unique subscription_id/order_id remains. Legacy rows may have no booking UUID until verified opening. One logical booking's revisions retain their original root UUID |
| meal_subscription_pauses | Add nullable resumed_at and resumed_by for queue mode pauses. Preserve original date range; an explicit resume ends its future restriction without deleting the historical pause or recreating cancelled bookings |
| Existing orders and invoice items | Preserve original customer profile snapshot. Queue invoices use explicit immutable gross/discount/net and main quantity metadata, funding IDs and order ID rather than the current flat price fallback. No new kitchen quantity or per meal row |
| Existing accounting audit | Under canonical customer lock, accept a booking operation UUID, request fingerprint, input queue revision, returned booking revisions and result. Unique behavior is enforced under that lock and durable audit; add a matching indexed subject/action key if absent. Do not silently succeed when required audit is missing |

Audit history stores accepted choices by referenced order snapshot, not duplicated personal data. Queue revision increments with each quantity/funding mutation. Booking UUID is public opaque identification, not authorization. The server resolves its latest revision within the owned compatible queue. Financial FKs restrict deletion.

Subscription order mappings remain historical links. Cancelling a mapping means its actual order is Cancelled and its funding is released; do not delete the mapping or add a separate cancellation status model. Queue reference is the retained root subscription_code resolved through the canonical customer and validated company/branch/currency. A merged older reference can resolve the same logical queue without granting another customer's access.

The durable operation acceptance also retains the first intended invoice issue date and encrypted normalized choices before the financial transaction. A failed posting cannot substitute a later issue date on replay. Before an order exists these choices live only in that protected operation snapshot, not a placeholder operational order. Retain original actor/UUID identities after merge; replay searches authorized retained source history and rejects an ambiguous or changed identity rather than accepting a new mutation.

### Quantity and money

Use the 0001 counter formula: available = total minus invoice based used minus reserved quantity. Selected = used plus reserved for the active allowance, including verified opening use less opening quantities later restored by an evidenced void. Per block, remaining uses `meal_count - opening_used_quantity + opening_released_quantity - active reserved/invoiced quantity`. Upcoming booked quantity is a separate service date projection over active bookings; it overlaps used when the future invoice is already paid and is not subtracted twice. Customer copy leads with Selected and Available to choose, and labels future bookings Scheduled, not Delivered. Show cancelled historical allowance separately rather than counting it as available.

Select the lowest currently free positions of the oldest eligible block, continuing into later blocks only after exhausting earlier free positions. Opening used positions and active reserved/invoiced positions are unavailable. Released positions are reusable with their original cents. Record the queue revision and accepted attribution in audit so a later restoration does not make earlier valid later block usage look premature.

One confirmed submission groups selected main rows by service date and creates one new order per date. Several main dishes and quantities are supported on that order, and another submission can create another order on the same date under existing schema. For each block supplying that order create one funding row. Sum its position values with the 0001 integer formula, then create invoice lines grouped by block so quantity, discount and allocation can be explained. One order can have one invoice funded by several block payments; it does not require separate customer orders per block.

Create the order, mapping, funding, issued invoice and explicit block allocations in one transaction. Retain the first intended invoice issue date before financial posting, following 0001. Use canonical AR issue/payment allocation and ledger services with implicit generic advance allocation disabled on these invoices. Never call the flat membership price fallback for a funded snapshot. An issued zero balance slice requires no zero payment or zero allocation and still enters quantity usage once. Persist reserved funding during orchestration or verified legacy opening; move it to invoiced with the canonical invoice event and counters, not through an independent listener increment. No queue booking is presented as confirmed after a partially failed financial transaction.

Booking quote reads current menu availability and free allowance but reserves nothing. Confirming locks and revalidates. Stale draft prices or balances return 409 with the refreshed summary for review; insufficient allowance returns 422 and an explicit Buy another membership link, never an automatic charge. A saved positive paid purchase's initial choices use the 0007 accepted snapshot path, not fresh date/menu validation after payment.

The saved cutoff governs customer changes and cancellations, not an additional cutoff for placing a new future date booking. For example, a new booking for tomorrow at 23:30 Qatar today is allowed under the no same day rule, but its change/cancel deadline has already passed and the confirmation must explain that. Do not silently introduce a 24 hour notice rule.

### Changes, cancellations and financial correction

The current invoice editor edits drafts; issued invoices use existing void/correction behavior. This feature does not add general posted invoice editing. Any supported edit alone leaves booking identity, meal quantity and credit positions unchanged. A later void reads saved funding, not edited invoice quantities or prices.

For a customer change, accept the whole proposed replacement for one logical booking plus its expected revision. Validate old deadline and the proposed future menu/date before making changes. A date change retains the original cutoff clock and profile snapshot and computes the new previous day deadline; confirmation must also be before that new deadline. A dish or quantity change on the same date preserves the original deadline. Preview released capacity together with the proposed selections, then void the old invoice, cancel its old order/mapping and release funding, create the replacement order/mapping revision, and issue/allocate its invoice atomically. Failure rolls everything back. Retain the logical booking UUID and original snapshot; do not erase the prior invoice. New quantity attribution selects oldest free positions including those just restored.

For customer cancellation, perform the same void/release path without a replacement. For an unissued reserved legacy booking, cancel/release without fabricating an invoice to void. Repeat operation UUID or repeated void returns the stored result; it does not act on a subsequently created replacement. Invoice void and voidAndDuplicate must both call the queue release adapter. A duplicated draft invoice is only a financial draft, not a replacement active booking; issuing it must not reinstate membership use or claim the released positions through legacy listener fallbacks. A deliberate replacement booking is a separate valid queue action.

The authoritative restoration quantity, block and positions come from unreleased funding rows. Release the invoice's actual active allocations through AR, including authorized prior changes, not the original expected allocation amount. Retain financial adjustments separately. Funds required for active unconsumed positions remain committed to their original payment. A contradictory manually adjusted balance is a visible financial exception; never silently take another payment or change quota to make it fit.

For a verified legacy invoice represented in 0007 closed opening evidence rather than an existing funding row, the same void adapter must prove the exact manifest row, invoice/order link, original position range and cents before proceeding. In the void transaction it releases the actual active allocation, cancels the linked order, creates the one already-released funding attribution, and moves that range from opening used to opening released. The manifest evidence key makes replay return the original result. Missing, conflicting or already-consumed restored evidence rejects the automatic correction for staff reconciliation; it never infers a meal from the invoice amount.

### Pause and resumed access

Extend MealSubscriptionService.pause transactionally for queue mode only. Validate its existing scope and date range, identify active future bookings with service_date greater than Qatar today and inside the inclusive pause, preview them, and use the same invoice void/release service before recording the effective pause. Today/past records are not silently reversed by a future pause; staff can use the ordinary authorized invoice correction if needed. A finance lock or invalid allocation aborts the entire pause correction and leaves its prior state intact with an actionable reason.

The current subscription show page has an optional pause_cancel_generated_orders loop that can skip failed cancellations and adjust counters by order count. Queue mode must bypass that loop and call the atomic service once; affected future booking correction is mandatory for queue pauses, not a checkbox. Preserve the optional legacy behavior for standing subscriptions.

Queue eligibility is date aware: pause periods block dates inside them, not all dates outside them merely because legacy status paused was set. After the pause end, eligible future selections remain available without automatic regeneration of cancelled choices. The existing resume action records resumed_at/resumed_by on currently unresumed pauses and returns the subscription to active through its canonical service. For booking decisions after that action, those ranges no longer restrict new future selections; historical checks retain the original restriction as it stood when each decision was made. Resume does not recreate orders or allocations. No new customer pause button, standing schedule or automatic backfill of unselected dates is introduced.

### Locking and complete writer coverage

Before any mutable financial row is locked, resolve and lock the canonical customer; during merge lock customer IDs ascending then users as in 0002. Lock compatible subscriptions in ID order, blocks in canonical queue order and affected mappings/funding in ID order. Then use the canonical finance services for invoices, payments and allocations. All queue related entry points, including direct invoice void, voidAndDuplicate, manual allocations, resync, request conversion and generation, enter this context before taking their existing finance locks. Recheck ownership after locks and retry bounded deadlocks from the beginning with the same operation UUID.

This outer lock serializes queue and finance mutations across different child lock sequences; it must not be added after acquiring an invoice or payment lock. Unrelated legacy finance operations stay unchanged but any operation touching a block's payment or linked invoice must resolve this context first. Do not perform network or email calls while locked. Inventory all writers in 0007 before implementation; tests must cover direct service calls as well as UI routes.

### API contract

All mutations require active customer token, server phone policy and canonical owned queue. The selected branch is the existing customer website order/menu branch context defined in 0007; there is no new branch selector or availability rule. RMS validates it as an active public branch of the default company and derives company, currency and customer. The accepted quote snapshots the branch. Use translated domain errors and exclude personal input from logs.

| Surface | Inputs | Output and errors |
|---|---|---|
| GET /api/customer/memberships | Selected branch | 200 with the one compatible logical queue reference/revision or null, totals, selected/available/upcoming, per block remainder, pause periods, same-scope pending requests and legacy readiness. No incompatible block enters totals; invalid/inactive/outside-default-company branch is 422 `branch_unavailable` |
| GET /api/customer/membership-bookings | Queue reference, future/history filter, cursor | Owned current bookings, linked invoice state, main quantities, saved cutoff/deadline, allowed actions and reason; paginated 20 |
| POST /api/customer/membership-bookings/quote | Selected branch, queue reference, selections; optional booking reference/revision for replacement | Accepted future choices, excluded today choices, contact, quantity, zero payable, branch-scoped balance projection, menu fingerprint, effective terms snapshot; 422 `membership_not_available_for_branch` when no compatible queue |
| POST /api/customer/membership-bookings | Client UUID, queue revision, quote fingerprint, reviewed choices, accepted terms version | Operation and new booking references, zero payable and fresh queue; 409 stale/replay conflict, 422 insufficient/invalid choices |
| PUT /api/customer/membership-bookings/{reference} | Client UUID, expected booking revision, replacement quote and choices | New revision and refreshed balance; 409 stale, 422 cutoff/date/menu/finance rejection |
| DELETE /api/customer/membership-bookings/{reference} | Client UUID and expected revision | Cancelled revision, released quantity and refreshed balance; same rejection rules |

Quotes return result_kind covered_booking and payable_amount_cents zero. They return 422 for empty covered selections; empty purchase remains valid in 0007. Pure today selections offer contact, not a payment link. Server returns current Qatar date and deadline text; browser time only renders it. General credit fields, promotion codes, client block IDs and positive payable amounts are rejected on covered booking endpoints.

A queue reference owned by the customer but incompatible with the selected branch/company/currency returns 404 on booking reads and mutations. A configured site-branch change after quote returns 409 `scope_changed` with a fresh summary; the website must not carry its old draft or totals across branch scope. The existing `/api/customer/subscriptions` account history can label records it already authorizes as unavailable for this branch under 0007, but this booking service neither discloses nor spends them. The error includes the configured support phone and explicit Buy another membership action, never an automatic checkout.

### Website and confirmation

Add owned membership state to welcome, menu, review and account using existing PHP proxies and orders-core.js. After login fetch RMS balance for the configured site branch; default the returning member path to Book remaining meals while leaving Buy another membership explicit. Do not force the plan picker before covered booking. A draft is scoped by current user, selected branch, canonical queue and mode, with quote revalidation on restoration. Never silently turn a covered draft into a purchase when it exceeds the balance or belongs to another branch.

Retain existing included sides and branch menus. Show selection quantity rather than distinct day count. List current bookings with deadline and change/cancel actions only when eligible; server rejects stale tabs anyway. Use existing responsive components, accessible labels and disabled/loading states. Profile details are informational snapshots, not a delivery area validation workflow.

Booking creation/change/cancellation persists a durable encrypted email intent on its mapping revision with operation UUID, actual outcome, dates and retained customer recipient in the successful transaction, then dispatches after commit. Reuse 0003 EmailLog/known failure/unknown delivery mechanics. A booking without a checkout uses its typed 0006 finding for failed delivery, not a fabricated checkout in 0005. No resend feature beyond existing approved scope is implied. Before claiming unsent mail, suppress a superseded creation/change notice and retain its audit; the current replacement/cancellation notice remains eligible. Missing email or failed send never rolls back valid booking and never asks the customer to submit again. Do not call this a second payment confirmation.

### Value sourcing

| Value | Source |
|---|---|
| Balances and source block ordering | Locked queue adapter, verified opening and funding states |
| Selected branch and spendable projection | 0007 configured website order/menu branch, default-company validation and only compatible queue roots |
| Incompatible history label | Existing authorized customer subscriptions response plus branch eligibility; never included in booking totals |
| Allowed dish, portion and included sides | Existing published branch/date menu and membership normalization |
| Price and allocation per block | Saved ranges, 0001 apportionment, actual payment and current canonical allocations |
| Current date, cutoff and contact | RMS Qatar clock; existing booking snapshot or company settings at new acceptance |
| Profile and terms | Customer snapshot at initial booking; current versioned terms accepted for a new booking; existing revision snapshot for changes |
| Public booking/invoice state | Current mapping/order, canonical AR status and release history; no provider call |
| Idempotency and returned revision | Accepted customer operation audit and existing logical booking UUID |
| Confirmation content | Committed booking operation and encrypted recipient snapshot |

### Critical test scenarios

See [verify.md](verify.md). Quantity, exact cents, original block restoration, cutoff equality, pause rollback and direct invoice entry points are release gates (AC-1 through AC-9).

## Migration plan

### Strategy

Add funding and nullable revision fields, then opt in only queue mode subscriptions with verified attribution. Keep existing multiple orders per date schema.

### Phases

1. Add constraints and writer guards with new paths disabled.
2. Create new queue bookings with funding; populate legacy mappings and any later evidenced opening release only through the 0007 verified manifest contract.
3. Enable the returning website route together with paid membership, after invoices/voids/pauses and scheduler exclusion work from every entry point.

### Rollback

Disable new booking/change entry points if needed; retain current bookings, history and authorized correction/recovery. Do not revert schema or use old resync on funded queues. Restore service with a forward fix.

### Risks

Current InvoiceIssued and void fallbacks can count one order instead of actual main quantity. Existing automatic advance allocation can spend the wrong payment. Both must use the queue adapter for funded invoices before enablement.

## Build plan

1. Add funding/revision schema, owned queue projection, shared lock context and exact position allocation (AC-1, AC-2, AC-7, AC-9).
2. Deliver covered quote and confirm, canonical order/invoice funding and initial paid purchase reuse (AC-2, AC-3, AC-7, AC-9).
3. Implement cutoff snapshots, replacement revisions, invoice void and pause adapters across all existing writers (AC-4, AC-5, AC-6, AC-7, AC-9).
4. Add website returning member screens, scoped drafts, zero payment confirmations and existing account compatibility (AC-1, AC-3, AC-4, AC-8).
5. Verify quantity/finance/concurrency/merge matrix, 0006 rules, both application UI and rollout gates (AC-1, AC-2, AC-3, AC-4, AC-5, AC-6, AC-7, AC-8, AC-9).

## Consequences

Orders and invoices remain the real operational records. A replacement keeps financial history rather than mutating a posted invoice. Staff still do not need delivery state updates, and the customer never chooses a funding block or pays again for covered meals.
