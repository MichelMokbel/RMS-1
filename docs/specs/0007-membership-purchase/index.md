# 0007. Membership purchase and sequential funding

**Date**: 2026-08-31
**Status**: In Progress
**Scope**: Feature 7. Design confirmed on 2026-09-01. The positive empty purchase tracer, repeat queue funding and late payment credit path are implemented behind disabled launch flags. Initial booking, promotions, legacy opening and release gates remain pending.

## Summary

A customer buys 20 meals for QAR 900 or 26 meals for QAR 1,200, including delivery, and can choose all, some or none immediately. Verified payment records the receipt, converts the request and adds the allowance immediately. Later purchases extend the same ordered allowance, and actual meal invoices draw from the payment that funded their meals.

## Requirements

**User stories**:

* As a customer, I want to pay for my full membership once and choose its meals over later visits without expiry.
* As the operator, I want one ordered allowance and traceable daily invoice funding, including discounted purchases and old memberships.

**Acceptance criteria**:

* **AC-1**: RMS quotes active plan 20 at 90000 cents or plan 26 at 120000 cents in QAR. Delivery is included. Selecting fewer or no meals does not reduce the package price. No package invoice, recurring charge or website credit spend is created.
* **AC-2**: A matching positive payment finished before the saved expiry creates exactly one AR advance receipt with method skipcash, one converted request and one funded block. First purchase creates the subscription; repeat purchase appends to its logical queue immediately without staff approval.
* **AC-3**: No selection requires no menu, placeholder order, invoice or usage. Initial selections count main quantities, not dates or sides, and use the shared booking service. Unsupported portions, paid extras and mixed ordinary items are rejected.
* **AC-4**: Each booking uses oldest available positions across funded blocks. Existing block money remains committed to its allowance. New purchase payment is not used to settle unrelated invoices. Daily values sum exactly to each block's gross, discount and paid net, including zero net meal slices.
* **AC-5**: A paid membership finished at or after expiry becomes actual unallocated customer credit without automatic conversion, booking or promo use. Unknown outcomes retain their recovery hold; delayed evidence of an on time payment completes the saved purchase.
* **AC-6**: Positive partial discounts change money only, not meal count. A zero net quote routes to 0010 and creates only a pending request, never a payment or allowance.
* **AC-7**: Unused paid allowance has no time expiry. Exhaustion, an effective pause and explicit cancellation remain distinct. Customer selection memberships are not filled by standing order generation. Invoice edits alone do not change allowance.
* **AC-8**: Legacy opening is evidence based, restart safe and preceded by a dry run. Preserve used meals, dates, invoices, money and customer access. Unsupported legacy funding remains on the existing workflow without forced repurchase.
* **AC-9**: Customer authorization, company/branch/currency, request replay, concurrent purchase/booking, canonical merge ownership, source uniqueness, required audit and period locks hold throughout both applications.
* **AC-10**: Website welcome, purchase, recovery and account views show full package price, partial/empty choices, purchase outcome, ordered balance and separate pending free requests. Confirmation failure does not affect completion. Checks and returning member booking are ready before membership enablement.

## Decision

**Chosen option**: Add funded purchase blocks and a queue mode to the existing subscription, using the shared checkout and AR services plus the booking service in 0008. Do not replace subscriptions with individual meal records or competing active purchases.

## Rationale

See [rationale.md](rationale.md).

## Feature design

### Current source boundary

MealSubscription holds one source_payment_id. SubscriptionPaymentLinkService currently derives usage partly from allocation value; generated invoices and manual order creation also write usage. Request conversion lives inside a Volt action and counts linked order dates. None supplies the proposed quantity based, multiple payment queue. The enhancement must extract reusable conversion orchestration and route queue mode writers through one service, retaining legacy behavior for records not opted in.

### Additive data model

Use the shared fields and constraints in 0001. All money is checked integer cents, times are UTC instants, and calendar eligibility uses Asia/Qatar. FK types match the effective MySQL schema and deletion is restricted.

| Record | Feature owned additions or precise use |
|---|---|
| membership_plans | Create the 0001 model. Unique company_id/code; code 20 or 26; positive meal_count and price; QAR; delivery_included true; active and effective timestamps; audit actor. Seed only missing configured rows, never overwrite a live price on deployment |
| meal_subscriptions | Add fulfillment_mode with legacy default standing and opt in customer_selection, nullable queue_company_id and queue_currency, queue_revision unsigned integer default 0. Existing counters remain authoritative cached aggregates. source_payment_id stays legacy only and is null for new queue subscriptions; uses_invoice_tracking is true |
| membership_purchase_blocks | Create all 0001 fields, unique payment_id and subscription_id/queue_position. Add immutable origin checkout/legacy, origin_key unique, quote fingerprint, original customer and pricing/terms evidence. New request and plan are required; verified legacy references may be null. Retain original subscription IDs after merge |
| membership_opening_manifests | UUID, company, branch, currency, cutover instant, rules version, immutable source upper boundaries, canonical content hash, state draft/reviewed/applying/applied/failed/invalidated, classification and apply counts, reviewed/applied actor and time, and sanitized failure code. Exact content hashes are unique within the scope |
| membership_opening_manifest_rows | Manifest and source subscription, original and scan-time canonical customer, scope, source payment/request/plan references, stable legacy origin key, original funding time and tie breaker, source fingerprint, classification, bounded exception codes, source quota/used, mapped invoiced/reserved, opening used, unassigned quantities, actual gross/discount/net and their closed/mapped/residual components, compact closed-usage evidence, current booking mapping snapshot, apply state, applied block and time. Manifest/subscription and an applied origin key are unique |
| meal_plan_requests | Add nullable checkout_id unique, converted_subscription_id FK, submission_kind legacy/paid_checkout/promo_request, encrypted submission snapshot and converted_at. Keep current status new/contacted/converted/closed. Repeat purchases point to the surviving queue subscription without replacing its original meal_plan_request_id |
| payment_checkout_targets | One meal_plan_request target with encrypted proposed selections; activation links the converted request and block through typed snapshot references. Child orders are linked to the request by the existing pivot. No untyped ID becomes authorization |
| Existing audit | Retain purchase intent, conversion, queue revision and block attribution changes with actor, source event, before/after quantities and IDs. No customer contact data in plain audit |

### Legacy opening manifest and classification

The opening tool is two explicit, audited actions: generate a read-only manifest, then apply only the reviewed manifest rows. An active Administrator holding `subscriptions.queue.migrate` and the matching company/branch scope may perform both actions; a second operator is not required. Generation records fixed source upper boundaries and uses only records at or below them. Review freezes the row set and canonical content hash. Apply rejects a draft, changed hash, changed source fingerprint, invalidated manifest or record outside the actor's scope.

Every row uses this decision table. Exception codes are an allowlist such as `missing_payment`, `voided_payment`, `mixed_funding`, `customer_scope_mismatch`, `branch_scope_mismatch`, `currency_mismatch`, `invalid_quota`, `used_exceeds_quota`, `booking_without_order`, `booking_without_invoice_evidence`, `allocation_mismatch`, `price_unexplained`, `residual_mismatch`, `overlapping_usage`, `changed_after_scan` and `already_applied_origin`; free-text customer or provider payloads are not stored.

| Classification | Exact rule and effect |
|---|---|
| verified | Canonical owner is unambiguous; company, branch and QAR align; quota is positive; source used is between zero and quota; a real nonvoided payment and its allocation/invoice evidence explain the retained gross, discount and net; every mapped booking belongs to that subscription, owner and branch and has a legitimate unissued reservation or linked nonvoided invoice/allocation; mapped ranges are disjoint; `opening_used_quantity = source_meals_used - mapped_invoiced_quantity` is nonnegative; `source_meals_used + mapped_reserved_quantity <= quota`; closed usage, mapped booking values and residual values reconcile exactly under 0001; and the source fingerprint is stable. Only this classification is eligible to apply |
| incomplete | The owner/scope is known but a repairable payment link, booking link, price component, allocation, residual or required staff evidence is missing or ambiguous. Do not apply it; retain the existing subscription and access while staff use existing correction/merge workflows, then generate a new manifest |
| unsupported | Funding is mixed or unidentifiable, scope/currency conflicts, quota/usage is invalid, real funding is absent, or the source graph is structurally contradictory. Do not apply it and do not expose it as zero or require repurchase; retain the existing workflow and allow a separately valid new purchase |
| already_applied | The stable origin key and source fingerprint match an existing applied row and legacy block. Return those references without another block, counter change, allocation or posting. A conflicting origin is an error, never an update |

`mapped_invoiced_quantity` contains only current mapped booking quantities already represented in `source_meals_used`; `mapped_reserved_quantity` contains legitimate unissued reservations and is not subtracted from source used. Each main quantity is represented once. Closed usage evidence retains the linked invoice/order IDs, compact original position ranges and actual gross/discount/net values for the remainder of source used. Any invoice that remains eligible for ordinary void is retained in this evidence or mapped directly; neither category is inferred from a date label alone.

Applying a verified row runs in its own transaction under the canonical customer and queue locks. It rechecks the manifest hash, source revision/fingerprint and stable origin key, creates the one opening block plus the saved booking funding mappings, and records the exact result on the row. It never creates or rewrites a receipt, invoice, allocation, journal or historical order. A crash can leave other rows pending, but retry returns applied rows unchanged and resumes pending rows. The manifest becomes applied only when every verified row is applied or already applied; a changed source invalidates its row and requires a new dry run and review.

Opening used ranges remain immutable evidence. If a later authorized invoice void matches retained closed-usage evidence, the 0008 queue adapter materializes the original attribution once as a released funding row and atomically adds that exact subset to the block's `opening_released_quantity` and released ranges. Missing or conflicting evidence stops that automatic restoration for staff reconciliation. No opening action or later void guesses money, quantity or positions.

Queue scope is canonical customer plus company, branch and currency. For a new scope, lock the canonical customer before finding or creating its subscription. The service ensures one new queue root in that scope; no global unique customer constraint is added to historical subscriptions. After merge, resolve retained compatible queue roots as one logical queue, order blocks by funded_at then stable block ID, and select the earliest retained queue root for subsequent purchases. Existing block and booking references never move simply to flatten history. Incompatible branches or currencies are never spent together.

The logical queue sums retained roots' counters once; per root counters reconcile to that root's opening history and funding rows. A mapping order may be owned by the selected queue root and funded across compatible retained roots. Each block still owns its original subscription reference and money. Queue locks cover all participating roots in ID order. Display one logical balance, not a choice of subscriptions.

### Purchase lifecycle and boundaries

1. Resolve authenticated canonical customer and default company; validate branch through existing availability rules. Quote the full active package, current terms and at most one promo. Initial main quantity is between zero and that purchased plan's meal count. Normalize included sides using current menu rules. Zero selections skip menu lookup entirely.
2. At checkout creation, revalidate quote and terms, lock the customer and any promotion reservation, persist the immutable attempt and pending request. Do not create an allowance, active booking or kitchen order. Reuse 0003 dispatch and recovery, never call the provider inside a database transaction.
3. Record accepted paid evidence durably, then apply 0001 finish time and accounting date rules. Verify the payment source, company and period before the financial transaction. Record the positive receipt as an unallocated advance, with auto allocation to unrelated invoices disabled for this workflow.
4. Under the shared customer/queue lock context, create or resolve the subscription, append one paid block, mark the request converted and update counters. New queue start_date is the purchase Qatar date, end_date is null; all weekdays can be selected subject to actual published menus and pause rules. It does not use standing schedule defaults to create orders.
5. If choices exist, call the same 0008 booking workflow using saved paid targets; create their real orders and daily invoices with allocations from the oldest available blocks. Conversion, initial bookings and accounting commit atomically. Persist confirmation intents only after a complete result and send outside the transaction.

Initial choices do not reserve old funded positions while payment is pending. Their quantity cannot exceed the new package, so the newly paid block guarantees capacity even if another valid booking uses earlier free positions before completion. At successful conversion, select the then oldest free positions across the queue including the new block. Thus initial meals can be funded by an older payment while the newly received payment remains committed to later meals. This is normal membership funding, not use of discretionary saved credit. An unresolved paid transaction with an internal posting failure retains its issue and snapshots; no partial receipt or entitlement is exposed as available.

Do not reprice or reject saved on time initial selections because callback delivery crossed midnight, the menu changed or terms changed after the hold. Quote and first creation enforce the new selection rules; completion honors the accepted snapshot. The timer begins when checkout is accepted, not when the first dish is selected. The ordinary late completion exception does not extend to membership activation.

For a customer with no completed membership history, maintain one unresolved positive first purchase intent across codes and no code. A second initiation returns the existing recoverable reference, not a second first purchase claim. Once it completes or is proven unpaid/late with its reservation released, a deliberate new purchase may start. This protects first purchase eligibility and avoids a second payment prompt while the first outcome is unknown. Customers with completed history can deliberately purchase again; exact request retries still return their original result. Apply 0002 merged ownership to the first intent lookup.

### Lifecycle compatibility

For customer_selection mode, queue capacity rather than end_date determines exhaustion. Leave unused positions valid indefinitely. Legacy status expired is not a new customer label for elapsed time; new queue queries compute exhausted when remaining quantity is zero without imposing an expiry date. Paid invoice issuance moves reserved funding to invoiced and updates quantities once; legacy fractional payment resync and InvoiceIssued increment fallbacks skip these rows and delegate to the queue service. A partly unpaid edited invoice never automatically restores a meal. Existing permission controlled financial correction remains distinct from selection changes.

Pause uses 0008 inclusive date range correction. An explicit RMS cancellation of a queue subscription uses the existing action, enhanced to void/cancel its future linked bookings, release allocations and cancel the remaining affected blocks atomically under finance locks. Remove only unconsumed entitlement, retain consumed history, mark cancellations and make released real unallocated money discretionary credit. No promo use or completed purchase history is restored. There is no customer cancel membership/refund action or new block management dashboard in this feature. If a merged logical queue has multiple retained roots, the action identifies the existing subscription being cancelled and previews its affected blocks rather than silently cancelling other roots.

For counter reconciliation, a cancelled block contributes only its retained consumed quantity to total and used; it contributes no remaining reservable positions. Active blocks contribute their full original quota. Preserve the cancelled block's original meal_count and price as history. A later financial correction cannot silently reactivate cancelled allowance or recreate a purchase; its real released money follows the existing retained credit rule.

### API and website contract

The selected branch is the existing customer website order/menu branch context, currently the same branch used by `orders-core.js`; this feature adds no branch picker and does not change menu availability. The website proxy forwards that configured branch, and RMS validates it as an active public branch of the default company at quote and again at mutation. A browser value is never authority to cross company or branch. The accepted quote/attempt snapshots the branch, and a changed site branch or menu scope requires a fresh quote.

The booking projection contains only queue roots whose canonical owner, default company, selected branch and QAR currency all match. It never includes another branch's block in totals, sequence or available quantity. The existing owned subscriptions history endpoint remains the source for account history: when called with a valid selected branch, each record it already authorizes may add `booking_eligibility` as `available_here`, `unavailable_for_selected_branch`, `legacy_review` or `history_only`. This label does not expose any new customer or record and does not make an incompatible balance spendable. Omitting the optional branch preserves the existing response contract.

| Surface | Inputs | Outputs and rules |
|---|---|---|
| GET /api/public/membership-plans | None | Current active code, quantity, full package cents, QAR, delivery included; public throttled, 503 unavailable |
| GET /api/customer/subscriptions | Existing filters plus optional selected branch | Existing authorized history; with a valid branch, add the nonauthoritative booking eligibility label above. Invalid/inactive/outside-default-company branch returns 422 `branch_unavailable` |
| POST /api/customer/checkouts/quote | purpose membership, selected branch, plan_code, zero or more selections, optional promo_code | Existing 0001 quote fields, main quantity, purchase allowance and current compatible queue summary; no browser total or scope authority |
| POST /api/customer/checkouts | Existing UUID/fingerprint/terms contract and reviewed membership payload | Existing attempt or new positive checkout; 409 changed quote/request, 422 invalid selection or zero amount endpoint mismatch |
| GET /api/customer/checkouts/{reference} | Owned reference | Four public statuses from 0001, purchase_confirmed, distinct paid/confirmed/credit amounts, request/block references and current queue link |
| GET /api/customer/memberships | Selected branch | Only the compatible 0008 logical queue, pending requests in that scope and legacy readiness projection; 200 with null queue when none, 422 `branch_unavailable` for invalid scope; current token ownership, never browser customer ID |

A queue reference valid for the customer but not for the selected branch/company/currency is 404 on a booking mutation so the endpoint does not disclose cross-scope data. A valid branch with no compatible spendable queue returns 422 `membership_not_available_for_branch` from covered quote/confirm, with the configured support phone and an explicit Buy another membership action; it never starts payment automatically. If the configured site branch changes between quote and confirmation, return 409 `scope_changed` with a fresh branch-scoped summary. An incompatible membership remains visible only through the existing account-history authorization above.

Keep the existing ordinary order API compatible. When payment mode is enabled, the website membership purchase path cannot fall back to the legacy public endpoint that creates unpaid requests/orders. Gate old website membership submissions on the server with an actionable use checkout response, while preserving explicitly authorized existing RMS manual request/conversion workflows. Do not disable legacy ordinary order clients globally as a side effect.

In orders.php and orders-core.js, distinguish purchase from covered booking in state. Replace partial per day price totals with the full RMS package quote in purchase mode, allow Continue without selecting meals and show Choose meals later. Existing plate only selection and included sides remain. Welcome and account offer Book remaining meals and Buy another membership as separate actions. Never infer entitlement from localStorage or a success redirect. Clear ownership scoped cached balances on logout/merge and fetch RMS again on another device. API PHP proxies forward authenticated requests and safe response codes, never provider secrets. Payment polling and recovery follow 0003 without browser supplied status.

### Value sourcing

| Value | Source |
|---|---|
| Package price, allowance, delivery and effective version | Active membership_plans row and retained quote |
| Discount, net and permanent use | 0010 validated promotion snapshot and redemption |
| Selected branch and menu scope | Existing website order/menu branch configuration, then active public branch validation in the default company; retained on quote, attempt and booking |
| Identity, receipt method/date and actor | 0002 canonical owner, verified provider evidence and configured system actor |
| Queue root, sequence and next available positions | Locked compatible subscriptions and retained blocks/funding, stable funded time ordering |
| Account history booking eligibility | Existing customer subscriptions authorization plus selected branch/company/currency comparison; never used to calculate spendable balance |
| Daily invoice cents and supplying payment | 0001 cumulative apportionment and original block payment, not current daily unit price |
| Counters and lifecycle labels | Opening quantities and current funding states, explicit cancellation and pause records |
| Empty/partial purchase confirmation and recipient | Immutable converted request, actual paid block, initial booking references and encrypted customer snapshot |
| Terms, contact, deadline and expiry | 0001 versioned terms and company settings copied at the relevant acceptance |

### Security and configuration

Use existing customer middleware with server phone proof policy, active account and current ownership. Default company owns the source and settings; no request chooses ledger accounts or financial actor. Feature flags for membership purchase and legacy queue exposure default off. Recovery of started attempts remains enabled when new purchases are disabled. New protected RMS cancellation/conversion behavior retains its existing permission plus scope; no new customer cancellation authority is implied. Audit failure rolls back required local effects.

### Critical test scenarios

The matrix in [verify.md](verify.md) includes empty, partial, complete, repeat, discounted, late, merged and legacy purchases; exact money and quantity assertions, isolation and no extra payment are mandatory (AC-1 through AC-10).

## Migration plan

### Strategy

Add schema first and opt in only verified queue records. Never rewrite old migrations or recompute all legacy usage with new rules.

### Phases

1. Inventory every counter, source_payment_id, generation, request conversion, cancellation and invoice writer. Add queue adapters and fixture coverage before toggling mode.
2. Generate the authorized read-only manifest above at a fixed cutover and source upper boundaries. Persist its exact classification, source fingerprint, evidence references, quantities, actual financial totals, current mapping snapshot and residuals. Protected output contains exception references, not copied customer dumps.
3. Review the immutable manifest hash, then apply only verified rows with the same stable legacy origin keys. Recheck source fingerprints, preserve posted records, create opening blocks and map current bookings atomically per row and compatible queue. Restart returns existing identical results; changed evidence invalidates the row and requires a new generated manifest and review.
4. Keep proven closed historical usage in immutable opening ranges and current or still voidable mapped bookings in funding rows. Distribute only verified residual money over unassigned positions using 0001. Incomplete or unsupported evidence is excluded with existing service access intact. A separately valid new block can still be bought and used without pretending excluded history is its funding.
5. Enable membership purchase and returning booking together only after the legacy report, checks and cross application tests pass. Existing unsupported balances remain clearly identified for staff help, not shown as zero or as a forced repurchase.

### Rollback

Disable new queue checkout and legacy opt in before traffic. Keep recovery, account history and all posted records. Before a queue has new activity an unused opening can be reversed only by a reviewed inverse manifest; after activity, retain schema and use a forward correction. Never drop funding history or restore a database snapshot over real payments.

### Risks

Legacy date based status, one payment links and invoice listener fallbacks can silently double count. The shared writer inventory and isolated legacy fixtures are release gates. Missing real legacy evidence is an operational reconciliation input, not permission to fabricate opening money.

## Build plan

1. Add plan, queue/block and request linkage schema; extract conversion and queue service, with all legacy writer guards (AC-1, AC-2, AC-4, AC-7, AC-9).
2. Prove positive empty purchase through quote, hosted payment, receipt, immediate conversion, repeat block and confirmation; add partial/full initial booking via 0008 (AC-1, AC-2, AC-3, AC-4, AC-9, AC-10).
3. Add late/unknown recovery, promotion hooks, exact apportioned funding and cancellation/merge compatibility (AC-4, AC-5, AC-6, AC-7, AC-9).
4. Implement dry run and restart safe opening, website purchase/account states and compatibility gates (AC-3, AC-8, AC-9, AC-10).
5. Run all owning domain suites, legacy fixtures, 0006 rules and both application release checks with 0008 enabled (AC-1, AC-2, AC-3, AC-4, AC-5, AC-6, AC-7, AC-8, AC-9, AC-10).

## Consequences

The existing subscription remains the balance interface, but its queue mode uses explicit funding rather than guessing usage from payment amounts. Repeat payments remain separate and daily revenue follows issued meal invoices. Existing standing subscriptions remain compatible until their verified opening is deliberately enabled.
