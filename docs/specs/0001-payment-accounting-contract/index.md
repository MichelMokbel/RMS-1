# 0001. Payment and accounting contract

**Date**: 2026-08-29
**Updated**: 2026-08-30
**Status**: Proposed

## Summary

RMS is the only authority for prices, payment status, orders, memberships, invoices, and accounting. SkipCash is recorded as its own payment method and clearing source, while the existing AR workflows continue to own invoices, customer advances, allocations, voids, and revenue. Durable checkout and provider event records make retries and recovery safe without placing accounting authority in the customer website.

## Requirements

**User stories**:

* As a customer, I want to pay once for an ordinary order or membership and receive a clear result without risking a duplicate charge.
* As a returning member, I want to use the meals I already purchased without paying again.
* As an administrator, I want to manage promotions, payment timing, and retained customer credit through controlled RMS workflows.
* As finance staff, I want every SkipCash receipt, invoice allocation, fee, settlement, reversal, and exception to be explainable from preserved records.
* As support staff, I want to find the customer visible outcome from an RMS reference even when the browser closes or provider delivery is delayed.

**Acceptance criteria**:

* **AC-1**: RMS is the price authority. The 20 meal plan costs QAR 900 and the 26 meal plan costs QAR 1,200. Delivery is included, tax is zero, one promotion may reduce only the membership price, and every monetary calculation uses integer QAR minor units with no browser supplied total trusted.
* **AC-2**: One verified ordinary checkout payment can fund one or several future dated orders. RMS creates one AR payment and one paid invoice for each dated order, allocates the payment across those invoices, recognizes revenue only when each invoice is issued, and treats the paid invoice as the internal fulfillment signal without changing routine order states or describing a future order as delivered.
* **AC-3**: Every SkipCash receipt records `payments.source = ar`, `payments.method = skipcash`, and the active SkipCash payment source. Receipt posting debits the dedicated SkipCash clearing account. It credits AR for ordinary allocated payments or customer advances for membership and retained credit. Receipt, allocation, delivery, and settlement never recognize invoice revenue again.
* **AC-4**: A positive membership purchase records one new payment for the final amount. A first purchase creates one subscription and converts its meal plan request immediately. A later purchase converts its request and appends a funded purchase block to the same customer queue. No package invoice, automatic recurring charge, saved credit use, placeholder meal order, or initial meal selection is required. Membership checkout rejects ordinary items and paid extras.
* **AC-5**: Membership purchase blocks are consumed in their confirmed sequence. A booking may cross blocks, but every main dish quantity, daily invoice amount, and payment allocation remains attributable to the block that funded it. Daily invoice gross, discount, and net values reconcile exactly to each original package snapshot.
* **AC-6**: Membership availability remains based on the existing subscription allowance and invoice based usage, extended with active bookings so a reservation and its later invoice cannot count twice. One selected main dish quantity consumes one meal, included sides consume none, several meals may use one future date, unused meals do not expire, and later covered bookings create no new payment or delivery charge. Legacy memberships remain usable after ownership and balances are established. Family quantities remain under the purchasing customer and use that customer snapshot without recipient profiles. Existing address fields remain snapshots, with no new delivery area or address validation flow.
* **AC-7**: An administrator creates and manually shares company wide membership promotion codes with fixed QAR or percentage value, required dates, a required positive total limit, a per customer limit that defaults to one, plan eligibility, and first purchase, renewal, or both eligibility. First purchase means the customer's first completed membership purchase across every plan and payment source. A pending request is not a completed purchase. One purchase accepts at most one code. A partial promotion changes price only and does not change allowance or consumption. Its completed redemption and first purchase history are never restored by cancellation or merge, while an unsuccessful paid checkout releases only its temporary reservation. A valid 100 percent promotion is always limited to one redemption per customer and creates exactly one pending meal plan request and its redemption. It creates no payment attempt, provider event, payment, subscription, purchase block, allowance, order, booking, invoice, allocation, revenue, fulfillment, or conversion. Retry, rejection, cancellation, and customer merge never restore that use.
* **AC-8**: The customer website never offers or applies saved customer credit. Only an authorized RMS administrator may allocate it through the existing AR workflow. Additional real SkipCash collections create one unallocated AR payment each, remain customer credit without expiry, and do not complete another order, membership, or promotion. Ordinary payment after checkout expiry instead completes the original purchase under AC-9. The previously agreed retained credit rule for an expired membership purchase is unchanged by this ordinary order revision. No refund action is included. An unexpected provider refund or reversal remains a traceable finance exception and never posts an automatic balance change.
* **AC-9**: RMS rejects new same day online meal selections using `Asia/Qatar`. A mixed cart excludes today only after showing the revised future selection and amount for confirmation. Checkout duration defaults to 15 minutes and is company configurable. Each attempt snapshots its server start and expiry. An unchanged valid hold may complete after midnight. For ordinary checkout, the first fully verified matching payment completes the original saved order, paid invoice, and allocation even at or after expiry; the customer receives the normal confirmation rather than expiry credit. Membership payment timing remains under the existing hold and AC-8 rules.
* **AC-10**: Membership booking changes use the booking cutoff snapshot, which defaults to 11:00 PM on the previous Qatar day and is company configurable. Before the cutoff, a valid cancellation or quantity reduction releases the original block reservation once. A pause or linked daily invoice void uses the existing invoice correction workflow, releases allocations, cancels the linked subscription order, and restores invoice based meal usage once while keeping the original payment. Editing an invoice alone has no effect.
* **AC-11**: A valid signed SkipCash webhook is the primary confirmation. RMS may query SkipCash transaction details to recover a missing or uncertain result. The browser return and the customer website never mark a payment paid and never communicate directly with SkipCash.
* **AC-12**: Customer visible payment statuses are limited to `pending`, `paid_processing`, `completed`, and `declined`. The response separately states whether the order, membership, or request was confirmed and includes a customer message and recovery reference. Internal `expired`, `payment_received_as_credit`, and `needs_review` states are never exposed as public status values.
* **AC-13**: Every mutation is safe to retry. Exact requests, provider events, invoice voids, allocations, promotions, bookings, imports, and settlements cannot duplicate money, meals, revenue, fees, or history. A paid provider transaction is never downgraded by a later event. Two distinct real captures create two payments, but only the intended first capture completes the purchase.
* **AC-14**: If SkipCash session creation fails, no order becomes an active booking. Unpaid attempt orders remain outside operational booking views. If confirmed payment processing fails, RMS returns a response other than 200 so SkipCash retries, and a scheduled recovery run checks unresolved attempts. Email failure never changes a successful result.
* **AC-15**: The default accounting company owns each checkout and that ownership is retained on every related record. Branch, customer, company, currency, invoice, payment, allocation, source, and bank relationships must align. Plans are public, while final quote, checkout, and the zero amount request require a signed in customer satisfying the server phone policy in 0002, including its explicitly accepted temporary bypass. Status and history require the owning customer.
* **AC-16**: The SkipCash XLSX import preserves the protected original file and stages sale and settlement fee rows for review. It matches a sale only from verified provider identifiers, with phone as supporting evidence. Provider branch codes require an approved mapping and are never treated as RMS branch IDs. `skipcashCoupon` is provider evidence and never an RMS promotion. Settlement debits the default bank for net payout, debits separate commission and settlement fee expense accounts, and credits SkipCash clearing for gross receipts. Duplicate imports, unsupported rows, mismatches, missing mappings, a missing bank, or a closed period do not post.
* **AC-17**: SkipCash secrets remain in deployment configuration. Webhooks require HMAC validation, strict reference, amount, currency, and ownership checks, replay protection, and rate limiting. Normalized event data is retained for audit, encrypted raw webhook bodies are removed after 90 days, settlement files use private storage, logs redact personal data, and no card number or masked card number is normalized or displayed.
* **AC-18**: Customer matching uncertainty never blocks registration, checkout, payment, or paid membership conversion. A low confidence case creates a separately owned customer and an internal review item. Gemini may rank candidates only. The existing customer merge flow resolves every current and new customer owned record to the surviving owner once under the 0002 reference matrix, moves mutable ownership without rewriting original proof or event identities, preserves snapshots and financial history, combines membership and promotion history, and leaves one usable portal account under the confirmed destination login rule.
* **AC-19**: The website presents or links the current terms before confirmation and sends email confirmations for completed payments, paid membership purchases, covered bookings, and 100 percent requests. The terms state no membership expiry, booking deadlines, no refunds, and administrator only credit use. All purchases and renewals are customer initiated.
* **AC-20**: The customer website uses the new RMS quote, checkout, and status contracts. The existing direct unpaid order endpoint remains available only behind a launch setting during transition and cannot accept customer website orders once SkipCash checkout is enabled. Covered membership booking and a valid 100 percent request remain available during a gateway outage when RMS can record them safely.

## Decision

**Chosen option**: Option 2: RMS owned checkout ledger with dedicated SkipCash clearing

Add durable RMS checkout, provider transaction, provider event, payment source, membership funding, and settlement records. Reuse the canonical AR invoice, payment, allocation, void, ledger, bank, audit, customer, and subscription services as the only writers of their records. The customer website remains a presentation and proxy layer. (basis: `AGENTS.md`, the AR and ledger service guides, idempotent consumer practice, and the official SkipCash integration documents)

## Rationale

Reasoning and options: see [rationale.md](rationale.md).

## Feature design

### Data model sketch

The tables below are the coherent target. Tracer Bullet delivery adds them in the slices that first use them rather than through one large unused migration.

| Entity | Required contract |
|---|---|
| `payment_sources` | `company_id`, unique company `code`, `name`, `method`, `clearing_account_id`, `is_active`, timestamps, and audit actor. Seed one `skipcash` row for the default company with method `skipcash`. The clearing account belongs to the same company. |
| `membership_plans` | Unique company `code`, `meal_count`, `package_price_cents`, `currency`, `delivery_included`, `is_active`, effective timestamps, and audit actor. Seed 20 meals at 90000 cents and 26 meals at 120000 cents in QAR. |
| `payment_settings` | One row per company with `checkout_duration_minutes`, `booking_cutoff_time`, `timezone`, `order_support_phone`, actor, and timestamps. Defaults are 15 minutes, 11:00 PM, `Asia/Qatar`, and the confirmed operational contact. Checkout duration accepts 5 through 60 whole minutes. Cutoff accepts a valid local clock minute. Timezone is fixed to `Asia/Qatar` for this release, not editable. |
| `payment_checkout_attempts` | UUID public reference, `company_id`, `branch_id`, `customer_id`, portal user ID, `payment_source_id`, purpose, currency, gross, discount, payable amount, quote fingerprint, client UUID, request fingerprint, internal status, server start, expiry, completed time, immutable cart and price snapshots, terms acceptance snapshot, provider create dispatch time, create outcome, and timestamps. Unique portal user plus client UUID. Create outcome is `not_sent`, `in_flight`, `created`, `rejected`, or `unknown`. |
| `payment_checkout_targets` | Attempt ID, target type, target ID, service date, sequence, expected amount, immutable target snapshot, hold state, held time, activation time, release time, intended invoice issue date, and timestamps. Types are `order` and `meal_plan_request`. Order ID is null until paid activation creates the order; a positive membership request ID may exist before conversion. Attempt target sequence is unique. |
| `payment_provider_transactions` | Attempt ID, payment source ID, provider payment ID, merchant transaction ID, amount, currency, raw status ID, normalized status, provider finish time, finish time evidence source, verified paid time, detail checked time, encrypted hosted pay URL, nullable provider session expiry, Visa ID, card type, classification, resulting RMS payment ID, and timestamps. Provider payment ID is unique within a payment source. Merchant transaction ID is not unique because SkipCash may create another provider payment ID for the same merchant attempt. |
| `payment_provider_events` | Payment source ID, provider payment ID, payload hash, normalized status and amounts, verified signature key reference, processing state and error, received time, encrypted raw body, raw body removal time, and timestamps. Payment source plus payload hash is unique. A failed processing record remains retryable. An invalidly signed body is rejected before this inbox and receives only a sanitized security log entry. |
| `payments` | Add nullable `payment_source_id`. SkipCash rows remain immutable AR payments with method `skipcash`. Each provider transaction can create at most one payment and each payment links to at most one provider transaction. |
| `membership_purchase_blocks` | Subscription ID, plan ID, payment ID, meal plan request ID, queue position, meal count, gross price, discount, final price, currency, company, branch, promotion and pricing snapshots, funded time, optional cancellation time, and audit actor. Subscription plus queue position and payment ID are unique. A `legacy` origin additionally retains a stable import identity, cutover time, opening used quantity, opening released quantity defaulting to zero, compact opening used/released position ranges, financial totals, and evidence references. Only verified legacy plan and request references may be null; a linked real payment is required for new automatic invoice funding. |
| `membership_booking_funding` | Purchase block ID, subscription order ID, main dish quantity, stable credit position ranges, invoice ID, intended invoice issue date, invoice gross, discount and net amounts, payment allocation ID, state (`reserved`, `invoiced`, or `released`), state timestamps, booking cutoff time, booking timezone, deadline, and audit actor. Purchase block plus subscription order is unique. One order may have several rows only when it crosses blocks. Credit ranges are compact attribution on this row, not individual meal records. |
| `meal_subscriptions` | Keep `plan_meals_total` and `meals_used` as the customer level balance fields used by the current application. Do not create an individual membership meal table. Purchase blocks provide funding sequence and booking funding rows provide attribution. |
| `gateway_settlement_imports` | Payment source ID, company ID, file hash, private storage path, report period, imported totals, review state, posting state, actor, and timestamps. Payment source plus file hash is unique. |
| `gateway_settlement_rows` | Import ID, row sequence, payment source ID, order type, payment reference, row reference, stable economic identity, content fingerprint, duplicate or conflicting row reference, provider identifiers, provider branch code, transaction time, bank date, gross, variable commission, fixed commission, total commission, settlement fee, net amount, normalized phone evidence, match state, matched provider transaction ID, and review notes. Import plus row sequence is unique. Repeated rows remain evidence, not new posting authority. Literal `Null` and blanks normalize to missing values. |
| `ar_clearing_settlements` | Extend the existing record with `payment_source_id`, import ID, payout reference, bank settlement date, date evidence reference, gross amount, commission amount, settlement fee amount, and net bank amount. Existing `amount_cents` remains the gross amount for compatibility. Settlement method accepts `skipcash`. Retain posted account, bank, and date snapshots. |
| `ar_clearing_settlement_adjustments` | Settlement ID, payment source ID, adjustment type, amount, expense account ID, source report row ID, stable economic identity, and timestamps. Adjustment type is commission or settlement fee. Payment source plus adjustment type plus economic identity is unique across imports. Existing settlement payment links also enforce one active settlement per provider transaction. A reversal retains these identities and requires the established explicit correction path. |
| Accounting mappings | Add company mappings for `skipcash_commission_expense` and `skipcash_settlement_fee_expense`. The SkipCash clearing account comes from the payment source. The resolved accounts are retained on posted records. |

Promotion code and redemption fields are defined by the promotion specifications. This contract requires a stable redemption reference, immutable discount snapshot, and terms acceptance snapshot on the request or completed purchase. Customer match review fields are defined by the customer matching specification. This contract requires all new customer references to participate in the existing merge workflow.

### State transitions

**Checkout attempt internal state**:

```text
initiating → pending → paid_processing → completed
initiating or pending → expired or declined
initiating or pending or expired or declined → paid_processing
paid_processing → payment_received_as_credit
paid_processing → needs_review
needs_review → paid_processing
```

`completed` and `payment_received_as_credit` are terminal purchase outcomes. A verified paid provider transaction cannot move to an unpaid state. Recovery uses verified provider evidence, not the previously displayed unpaid state. A first matching ordinary capture completes the original purchase regardless of expiry and never enters `payment_received_as_credit` solely because of timing. For an expired membership purchase, its late capture creates its payment before that retained credit state under the unchanged membership rule. Additional captures after completion are separate retained payments and do not change the confirmed purchase. Review and recovery use row locks and the same idempotent completion service.

**Public payment status mapping**:

| Internal state | Public status | Purchase confirmed |
|---|---|---|
| `initiating`, `pending` | `pending` | false |
| `paid_processing`, `needs_review` | `paid_processing` | false |
| `completed` | `completed` | true |
| `payment_received_as_credit` | `completed` | false |
| `declined`, unpaid `expired` | `declined` | false |

**Settlement import state**:

```text
staged → reviewed → posted
staged or reviewed → needs_review
```

A posted settlement is corrected only through the existing void or reversal workflow. It is never edited or imported again.

### API surface

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/api/public/membership-plans` | GET | none | active plan code, meals, package price, currency, delivery included | Public and rate limited | 404 no configured plans, 503 pricing unavailable |
| `/api/customer/checkouts/quote` | POST | purpose, cart or plan code, optional promo code | quote fingerprint, accepted items, excluded today items, gross, discount, payable amount, currency, terms version, terms URL and content hash | Sanctum customer portal with satisfied server phone policy from 0002 | 409 cart changed, 422 invalid mode or eligibility, 503 pricing or terms unavailable |
| `/api/customer/checkouts` | POST | client UUID, quote fingerprint, purpose, reviewed cart, accepted terms version | attempt reference, public status, nullable SkipCash pay URL, server expiry, totals, replay or recovery result | Sanctum customer portal with satisfied server phone policy from 0002 | 409 changed request or quote, 422 invalid cart or terms, 503 unavailable before attempt creation |
| `/api/customer/checkouts/{reference}` | GET | attempt reference | public status, purchase confirmed, message, recovery reference, payable amount, paid amount, confirmed amount, retained credit amount, expiry, nullable usable pay URL, confirmed targets | Owning customer | 403 wrong customer, 404 missing, 409 ownership conflict |
| `/api/customer/membership-requests` | POST | client UUID, quote fingerprint, plan code, 100 percent promo code, proposed future choices, accepted terms version | existing or new pending request reference, public result, message | Sanctum customer portal with satisfied server phone policy from 0002 | 409 redemption conflict or quote change, 422 invalid or nonzero quote or terms, 503 RMS unavailable |
| `/api/integrations/skipcash/webhook` | POST | signed SkipCash body | accepted or duplicate result | Public HMAC endpoint with rate limit | 400 malformed, 401 invalid signature, response other than 200 when processing must retry |
| `/api/accounting/payment-settings` | GET | company context | checkout duration, booking cutoff, timezone, support phone | Dedicated settings permission | 403 denied, 404 company missing |
| `/api/accounting/payment-settings` | PUT | duration, cutoff, support phone | updated settings and audit reference | Dedicated settings permission | 403 denied, 409 company mismatch, 422 unsafe value or changed timezone |
| `/api/accounting/gateway-settlement-imports` | GET | page, status, date filters | paginated imports, totals, exceptions | Dedicated settlement review permission | 403 denied, 422 invalid filter |
| `/api/accounting/gateway-settlement-imports` | POST | XLSX file, payment source | staged import, totals, review state | Dedicated settlement import permission | 409 duplicate, 422 invalid report, 503 private storage failure |
| `/api/accounting/gateway-settlement-imports/{import}/post` | POST | client UUID, reviewed payout references, required date evidence reference if report lacks a bank date | settlement, evidenced bank settlement date, gross, deductions, net, bank transaction | Dedicated payout posting permission | 409 not reviewed or already posted, 422 mismatch or missing evidence or mapping, 423 closed period |

The standalone customer website proxies customer calls to RMS and passes the customer token through its existing secure proxy pattern. It never receives SkipCash credentials. The exact website PHP filenames are implementation details and are not a second business API.

### Value sourcing

| Action | Value produced or displayed | Source |
|---|---|---|
| Show membership plans | Meal count, price, currency, delivery included | Active `membership_plans` row owned by the default accounting company |
| Quote an ordinary order | Accepted items, service dates, portions, quantities, subtotal, excluded today items | Current RMS menu and pricing services plus the server Qatar date |
| Quote a membership | Plan amount, allowance, delivery, promotion saving, final amount | `membership_plans`, one validated promotion, and completed customer membership history |
| Build a quote fingerprint | Stable quote identity | Canonical server normalized customer, purpose, accepted cart, plan, promotion, price version, currency, company, branch, and terms version |
| Resolve checkout ownership | Customer, portal user, branch, company, currency | Sanctum user to customer link, selected order branch, default accounting company, and active payment source |
| Set the hold | Start, duration, expiry | RMS server time plus the current company `payment_settings`, copied to the attempt |
| Start SkipCash | Merchant transaction ID, amount, customer data, return and webhook URLs | Checkout UUID, payable amount, customer snapshot, and deployment configuration |
| Receive or replay SkipCash session | Provider payment ID, provider status, hosted pay URL, provider expiry if supplied | Persisted create response or verified transaction detail recovery. No assumed provider expiry and no new create call on an exact retry |
| Confirm payment time | Verified provider finish time and its evidence source | Authenticated SkipCash transaction detail `finishedDate`; a webhook timestamp is usable only if its exact field is covered by the verified signature |
| Show customer status | Public status, purchase confirmed, message, recovery reference, amounts | Internal state mapping, canonical payments and completion records, server translation keys, and checkout UUID. Amount meanings are defined below |
| Record terms acceptance | Version, URL, content hash, actor, and acceptance time | Effective RMS terms configuration and retained content matched to the submitted version, authenticated portal user, and server acceptance time |
| Record an ordinary payment | Amount, method, source, customer, company, branch, received time | Verified provider transaction and the attempt snapshot |
| Issue ordinary invoices | Invoice dates, amounts, lines, allocations | Target issue date retained at first verified completion processing, immutable target prices, and the canonical AR issue service. Service date is separate |
| Record a membership purchase | Payment, request conversion, subscription, block sequence and allowance | Verified provider transaction, validated plan and promotion snapshot, and locked customer subscription queue |
| Record a 100 percent request | Pending request, redemption, proposed choices, and result message | Revalidated zero amount quote, locked customer promotion history, request payload, authenticated customer, and attempt independent customer plus promotion uniqueness |
| Price a membership meal | Gross, discount, and net amount | Cumulative integer apportionment of the purchase block totals across its ordered meal credits |
| Compute one apportioned meal amount | Gross, discount, and net for credit position `k` | Bounded gross and discount apportionment defined below, with net derived as gross less discount |
| Show membership availability | Total, used, booked, available, and queue order | Existing subscription fields, verified legacy opening quantities, and funding states using the single counting formula below |
| Release a membership booking | Block quantity, allocation, invoice, order and restored balance | Unreleased funding rows and original credit positions locked through the invoice void or authorized booking workflow |
| Record retained credit | Unallocated amount and recovery reference | Verified additional payment, or late membership payment under AC-8, less active allocations; not ordinary payment solely after checkout expiry |
| Stage a settlement report | Row types, references, dates, gross, commission, fee and net | Protected XLSX workbook and merchant QAR and timezone configuration |
| Post a SkipCash settlement | Gross clearing credit, net bank debit, expense debits, posting date | Reviewed unique economic rows, source clearing account, mapped expense accounts, company default bank, and evidenced bank settlement date |
| Read or change payment settings | Duration, cutoff, timezone, support phone, and audit result | Company `payment_settings`, authenticated actor, server time, and prior retained values |
| Send customer confirmations | Recipient, amount, purchase result, booking dates, request reference | Customer and immutable attempt or booking snapshots after database commit |
| Show the same day contact | Contact message and phone | Company `payment_settings.order_support_phone` |

### Checkout replay and unpaid holds

The client UUID identifies one immutable request, not permission to create another provider payment. RMS commits the attempt and a single create dispatch claim before calling SkipCash outside the database transaction. A crashed or timed out dispatch becomes `unknown`; a recovery worker never repeats that create call merely because its lease elapsed. SkipCash merchant transaction IDs are correlation values, not a guaranteed provider idempotency feature.

| Stored result | Exact retry response and action |
|---|---|
| `not_sent` | Claim the one dispatch under a lock, then send it once. A concurrent request receives the existing reference |
| `in_flight` or `unknown` | Return HTTP 202 with public `pending`, the same reference, and no invented pay URL. Recover by known provider payment ID or a verified webhook. If the ID is unknown, keep an operations exception until provider evidence resolves it |
| Known pending session | Return HTTP 200 with the same reference and stored URL while both the RMS hold and any known provider expiry permit it. A provider detail response may recover that URL; it must not create a replacement session |
| Verified paid, completed, or terminal unpaid result | Return HTTP 200 with the current mapped result and no new provider call or payment link |

A changed payload using the same client UUID returns 409. A deliberate new purchase uses a new UUID and fresh quote. An unresolved earlier attempt for the same intended purchase is surfaced for recovery rather than silently replaced. An exact retry still returns its original result after prices, settings, or terms change. Hosted URLs are secrets for the owning customer, never log fields.

Before payment, ordinary targets are immutable checkout snapshots, not rows in `orders`, invoices, kitchen lists, order sheets, or membership bookings. Existing order creation is called only during successful paid completion, one order per target, with the new IDs linked in the same transaction as invoices, payment, and allocations. This reuses the current order workflow without adding staff managed states or a second cancellation mechanism. A positive membership request may exist pending payment, but no entitlement, active booking, or conversion exists yet.

Hold state is `held`, `activated`, or `released`. Activation requires fully verified payment, matching immutable targets, and successful atomic accounting completion. For ordinary orders, expiry is not an activation cutoff: a first matching capture may activate the original held or previously released unpaid target once, with its saved selections, dates, prices, and terms. No current repricing, new same day validation, expiry credit, or customer resubmission is required for that existing purchase. The RMS timer still limits unclaimed initiation and payment link display. If a dispatched provider outcome is pending or unknown when the timer ends, show recovery without a new payment prompt; expiry alone is not proof of failure.

Membership activation retains its before expiry requirement. Failure or expiry releases only unpaid membership holds and temporary promo reservations under the existing membership rules. Before releasing scarce reservations, recovery checks the provider outcome. An unknown result or missing completion time is not proof of nonpayment; retain the unresolved reservation for recovery without extending its deadline. A proven late membership capture follows retained credit. A delayed on time membership capture uses the original hold and does not rerun the new same day rule. Preserve all target snapshots for audit and recovery, never as unpaid operational orders. Exact retries cannot activate or release twice.

### Provider facts and accounting dates

| SkipCash status ID | RMS normalization and effect |
|---|---|
| `0`, `1`, `12` | `pending`, no payment authority |
| `2` | `paid`, subject to signature or authenticated detail verification and amount, reference, ownership, and currency checks |
| `3`, `4`, `5` | `unpaid_terminal`, no receipt; cannot downgrade a previously verified paid fact |
| `6`, `7`, `8` | `reversal_exception`, retain evidence for finance review, never automate a refund or reverse a receipt |
| Any other value | `unknown`, recover or review, never infer payment |

Store the original status ID separately from the normalized value. Webhook HMAC proves only the fields actually signed. In particular, do not trust a finish time or currency merely because it appears beside signed fields. Recover missing or unsigned required facts through authenticated transaction details for the same provider payment ID. Interpret an explicit timestamp offset as supplied; an offsetless provider time uses the fixed merchant timezone `Asia/Qatar`. Store the instant and evidence source. Reject invalid, future, or inconsistent finish times for review.

For ordinary checkout, the first fully verified matching capture completes the original purchase before, exactly at, or after `attempt.expires_at`; finish time still determines the receipt date. Membership hold eligibility remains `finished_at < attempt.expires_at`, with equality late under its existing rule. Webhook arrival time, browser time, import time, and job execution time never replace provider finish time. A verified paid fact with no reliable finish time stays public `paid_processing`, with an internal exception and retained evidence. Do not declare it unpaid, complete the purchase, or ask for another payment. Retry detail recovery through the same completion service.

| Financial value | Authoritative date and retry rule |
|---|---|
| Payment `received_at` and receipt posting date | Verified provider finish instant and its Qatar calendar date |
| Ordinary invoice issue date | Qatar date when verified completion first durably schedules that target for issue, retained on the target before the posting transaction; not the future service date |
| Membership daily invoice issue date | Qatar date when the canonical invoice workflow first schedules issue, retained on the funding row; not the package payment date or a fabricated service date |
| Allocation or reversal date | Date of that canonical allocation or correction event, retained for replay, with existing finance checks |
| Settlement and bank transaction date | Evidenced bank settlement date under the settlement rules below |

Existing issue and posting dates never change on replay. Check all applicable period and finance locks before the accounting transaction. If a historical receipt or another required posting date is locked, retain the verified provider fact immediately, keep the attempt in internal review and public `paid_processing`, and make no partial invoice, allocation, or entitlement changes. Resolve the exception using existing authorized finance controls, then replay with the same event dates. Never silently substitute today's date, bypass a lock, or invent a suspense account. This exception does not change the normal immediate paid conversion rule.

Status amounts use cents and have distinct meanings: `payable_amount_cents` is the snapshotted quote, `paid_amount_cents` is the sum of verified real captures, `confirmed_amount_cents` is the capture assigned to a confirmed purchase or zero until confirmation, and `retained_credit_amount_cents` is the currently unallocated balance of captures classified as retained credit. A payment still awaiting accounting can therefore be verified paid without being available credit. For a QAR 100 ordinary payment completed after checkout expiry and posted successfully, these values are 10000, 10000, 10000, and 0. If a distinct second QAR 100 collection for that same attempt is posted as retained credit, they become 10000, 20000, 10000, and 10000. A later administrator allocation reduces the last value, not the historical paid amount. Unused funds committed to an active membership are not general available credit.

### Membership counting, funding, and legacy opening

Funding states describe accounting attribution, not a new order workflow:

* `reserved` holds main dish quantity against its original block and is not yet included in invoice based usage.
* `invoiced` means that quantity has entered the established paid invoice usage calculation. Moving from reserved to invoiced updates the funding state and `meals_used` together, so it never counts twice.
* `released` retains the historical attribution but supplies neither a reservation nor current usage. Invoice void releases the actual active allocation and restores the row's original quantity and credit positions exactly once. An invoice edit alone changes neither quantity nor positions.

For a reconciled queue, `available = plan_meals_total - meals_used - sum(reserved main dish quantity)`. Invoiced funding is already in `meals_used`, not subtracted again. Show future invoiced meals as scheduled, not delivered. Per block, `remaining = meal_count - opening_used_quantity + opening_released_quantity - sum(current reserved or invoiced quantity)`. Opening released ranges are a nonoverlapping subset of opening used ranges, and neither overlaps current funding. The same owning service reconciles listeners and scheduled jobs; no second counter writer is added. A mismatch raises an exception rather than hiding it by clamping the result to zero.

Lock the owning subscription, blocks in queue order, affected bookings and funding rows, and relevant payments and allocations using the existing finance lock ordering. All competing writers, including administrator allocation and invoice void, use the same order. Select free credit positions from the oldest block first. Store compact position ranges on funding rows so release and reuse retain the same gross, discount, and net cents. Released history is not overwritten; a new booking receives new attribution. Funds backing remaining active membership positions remain committed and cannot be allocated by an administrator to an unrelated invoice.

Each confirmed booking stores `booking_cutoff_time`, `booking_timezone = Asia/Qatar`, and its calculated deadline on its funding rows. Rows for one booking share these values. The deadline is the previous calendar day at the saved clock time. A later setting change does not affect it. A valid date change uses the same saved clock time with the new service date. A pause and invoice void retain their established authorized correction behavior.

Before enabling the new covered booking path for legacy balances, run a restart safe reconciliation with a recorded cutover time. Preserve the original customer, company, branch, currency, completed purchase order, quota, consumed quantity, bookings, and actual payment links. Use the existing `source_payment_id`, payment allocations, subscription items, request history, and historical price evidence. Never infer an old price from today's plans or a payment solely from a matching amount or phone.

Create legacy opening blocks only from the reviewed 0007 manifest, ordered by original funding time with a stable existing record ID tie breaker. `opening_used_quantity` is the source consumed quantity less the mapped invoiced booking quantity already included in that source counter; mapped unissued reservations are tracked separately and never subtracted from consumed use. Retain compact ranges and closed-usage invoice/order evidence; map current bookings to funding rows without also counting their invoiced quantity as opening use. Preserve those bookings' existing financial values. The opening fields and mapped history together explain the original allowance and paid balance. After subtracting opening history and mapped bookings, snapshot the verified residual gross, discount, and net amounts and distribute only that residual across unassigned positions using the bounded rule below. Do not reprice an existing invoice, repost history, create synthetic receipts, issue zero value replacement invoices, or restore used meals during opening.

If an authorized later void targets retained closed legacy evidence, the queue void adapter locks the block, proves the exact manifest invoice/order evidence and original position range, materializes that attribution once as an already released funding row, and increments `opening_released_quantity` plus its released ranges in the same transaction. Replay returns the same release. Missing or conflicting manifest evidence blocks that automatic restoration for staff reconciliation; it never guesses a quantity, alters the original opening snapshot, or recreates an operational order.

Any missing payment, mixed funding, inconsistent price, or unexplained balance remains on the existing RMS workflow for staff reconciliation; do not falsely expose it as a newly funded block or send the customer to buy again. This readiness check is about financial evidence, not matching confidence. It does not block a separately valid new purchase. Preserve existing customer access and confirmed balances while staff resolve the affected legacy record. The membership implementation specification must provide a dry run report and verified opening fixtures before this path is enabled. This contract authorizes no data migration now.

### Promotion rounding and exact invoice values

Calculate one discount on the gross membership package before distributing it. Fixed discounts are integer cents. Percentage codes store integer basis points, where 10000 means 100 percent, and accept at most two percentage decimal places. Compute `discount_cents = min(gross_cents, intdiv(gross_cents * basis_points + 5000, 10000))`, rounding half a cent upward. Reject negative values or percentages above 100 rather than silently changing the code. Cap a valid fixed discount at the package price. Net is gross less discount. Zero net uses only the existing 100 percent request path, including when rounding or a fixed code reaches zero. This adds no credit and changes no allowance rule.

For a positive funded block, let `G` be gross cents, `D` be discount cents, and `N` be its meal count. For each original credit position `k` from zero through `N`, calculate:

```text
gross_cumulative(k) = intdiv(G * k, N)
discount_cumulative(k) = intdiv(D * gross_cumulative(k), G)
gross(k) = gross_cumulative(k) - gross_cumulative(k - 1)
discount(k) = discount_cumulative(k) - discount_cumulative(k - 1)
net(k) = gross(k) - discount(k)
```

The final three lines apply to positions one through `N`. Use checked integer arithmetic. Every slice satisfies `0 <= discount(k) <= gross(k)` and `net(k) >= 0`; all slices sum exactly to `G`, `D`, and `G - D`. For legacy unassigned positions, use the retained residual gross, discount, and position count as `G`, `D`, and `N`, not the original full price again. Positive unassigned quantity requires `G > 0` and `0 <= D < G`; zero unassigned positions require zero unexplained residual. Invalid residuals require reconciliation. Aggregate the saved positions for each dated invoice and funding block. Never distribute gross and net independently. On a QAR 1,200 package discounted by QAR 0.01, this avoids the negative one cent discounts produced by the prior formula.

A positive package can contain a zero net meal slice after a very large partial discount. It still consumes its main dish quantity once through the linked issued invoice, with zero outstanding balance and no zero value payment or allocation. This is not a 100 percent request because the package itself was paid. Existing invoice corrections remain separately auditable and do not change the original positions or reprice future selections. Purchase based invoice snapshots, including any verified legacy opening values, must reconcile exactly; an unrelated manual financial adjustment is not hidden as a rounding remainder.

### Settlement identity and posting date

File hashes detect an identical upload, not an identical economic event. Stage each report unchanged. Derive a row identity from payment source, `orderType`, `paymentRef`, and `referenceNumber`, excluding file name, row position, formatting, and phone. Keep a separate normalized financial content hash. A repeated identity with the same content links to its existing row and has no new posting effect. Different content for the same identity requires review, not a new fee or receipt.

A matched provider transaction also has one active settlement claim across all files, even if an export changes its row reference. Fee posting uses the source and stable fee identity across imports. If a fee has no reliable reference, do not guess from equal amounts or row order; resolve its identity from retained provider evidence before posting. Two real equal fees with distinct verified identities remain distinct expenses.

Treat the source and payout reference as the batch identity. Review complete batch totals, including duplicates already linked as evidence. A batch with existing posted claims cannot be posted again or have its remaining rows silently treated as a new net deposit. A changed or partial overlap follows the existing explicit correction workflow. Acquire the batch and transaction claims in the posting transaction with database uniqueness as the final guard. Sale commission posts only once from `totalCommission`; a separate fee row posts only once from its own identity.

The posting date is the report's explicit bank settlement date, not sale time, file date, upload time, or a free date entered at posting. If the report lacks it, use the transaction date of the matched imported bank statement deposit or retained bank or provider remittance evidence reviewed by an authorized actor. Persist the evidence reference, extracted date, reviewer, and review time before posting. Conflicting dates, multiple payout dates in one batch, or absent evidence keep the batch unposted for review. A reviewed date may be corrected only with replacement evidence and an audit reason before posting; the post endpoint cannot override it. Apply period and finance locks to this evidenced date. Posted corrections use the existing reversal workflow, never a date rewrite or a new customer receipt.

### Terms version source

Use versioned RMS configuration in `config/payment_terms.php`, not a new terms editor. Each immutable published entry has a version, UTC effective time, canonical customer URL, content hash, and a retained versioned content file. The latest effective entry is the current version. Quote returns that version, URL, and hash; RMS rejects first creation of an attempt or request if its accepted version differs from the currently effective entry, even when it matched an older quote. A missing published entry blocks new checkout or request confirmation with an actionable configuration error.

The attempt, or the pending request for a zero amount promotion, retains the version, URL, hash, actor, and server acceptance time. A started attempt completes with its accepted snapshot even if newer terms become effective. Exact retries return that same record without demanding acceptance again. A fresh attempt must accept the new version. Retain earlier content for audit; a URL pointing only at changing text is not sufficient evidence.

### Key invariants

* All payment amounts use integer minor units. SkipCash amounts have at most two decimal places and currency is QAR.
* The attempt owns the immutable company, branch, customer, price, currency, date, terms, and timing snapshots used for completion.
* Checkout duration is a whole number from 5 through 60 minutes. Booking cutoff is a valid `HH:MM` Qatar clock value. Changes apply only to later attempts or bookings. Timezone remains fixed to `Asia/Qatar`.
* `payments.source` remains the AR module identity. `payments.method` is `skipcash`. Card, Apple Pay, Google Pay, Visa ID, and card type are provider details, not RMS payment methods.
* One provider transaction creates at most one RMS payment. One attempt may have several provider transactions because SkipCash can create a new provider payment ID after a failed attempt.
* A payment is recorded only from a valid signed event or a verified provider detail response. The return page is never evidence of payment.
* A verified provider paid state is monotonic. Later failed, cancelled, rejected, or out of order events cannot downgrade it.
* Deactivating a payment source or membership plan blocks new attempts only. An existing attempt continues from its retained source, price, promotion, and timing snapshots.
* A checkout contains either ordinary orders or one membership purchase. It never mixes the two or accepts separately priced extras in membership mode. Included side quantities derive from accepted main dish quantities.
* A signed permanent mismatch is recorded for review and acknowledged without repeated provider delivery. A response other than 200 is reserved for a transient processing failure that a retry can resolve.
* Payment, invoice, allocation, membership block, provider event, and settlement creation use database transactions and row locks where balances or sequence can change.
* The attempt is committed before the outbound SkipCash call. No database transaction remains open across provider network activity.
* Active allocations never exceed the payment amount or invoice balance. All entries balance as debits equal credits.
* Ordinary invoice issue debits AR and credits revenue. Its payment debits SkipCash clearing and credits AR.
* Membership capture debits SkipCash clearing and credits customer advances. Daily invoice issue debits AR and credits revenue. Allocation debits customer advances and credits AR.
* Settlement debits the default bank for net payout, debits commission and settlement fee expenses, and credits SkipCash clearing for gross receipts.
* A sale `totalCommission` is the posted commission expense. Its variable and fixed components are evidence and are not posted again. A separate settlement fee row reduces net payout once.
* No tax, package invoice, delivery fee, refund, automatic recurring charge, or customer initiated credit allocation exists in this contract.
* The original apportioned gross, discount, and net invoice values for a block, including verified legacy opening history, equal its package snapshots exactly. Manual invoice corrections retain separate evidence and never rewrite the remaining entitlement values.
* The oldest funded block with remaining capacity is always used first. A browser never supplies or chooses a funding block.
* A reservation and its paid invoice count one main dish quantity once. Included sides, calendar dates, and order row count do not add meal usage.
* This contract adds no kitchen quantity model, individual membership meal record, recipient profile, delivery service area, or second cancellation model.
* Unused paid meals and unallocated customer credit do not expire.
* A 100 percent request remains outside every financial and membership entitlement table until staff later uses the existing conversion workflow.
* A 100 percent redemption is unique for customer plus promotion independent of browser idempotency keys, so a later submission returns the original pending request.
* Invoice edit has no cancellation effect. Invoice void releases its active allocation and block funding once and drives linked subscription order cancellation and meal balance correction.
* Missing settlement prerequisites never change a verified customer payment or membership allowance.
* Bank statement matching confirms the net payout deposit and never creates another customer receipt.
* Provider email or RMS email delivery is outside the financial transaction and cannot cause a second financial effect.
* Customer matching confidence never grants access to another customer and never delays an otherwise valid new customer purchase.

### Security model

SkipCash uses hosted payment entry. RMS and the customer website must never collect or store the primary account number, security code, or a normalized masked card number. Card type may be retained only as provider transaction metadata. PCI DSS responsibility and the provider hosted checkout scope must be confirmed with SkipCash and the acquirer before production use.

Public plan reads and the webhook are rate limited. Quote, checkout, and zero amount request mutations require Sanctum, active customer portal middleware, a satisfied server phone policy, server resolved customer ownership, and idempotency. Status and history require the owning portal customer but remain readable if that account phone verification state changes later.

The accepted [customer matching and signup design](../0002-customer-matching-signup/index.md) defines this identity boundary. Use its phone_verification.satisfied result, including the explicitly accepted temporary CUSTOMER_PHONE_VERIFICATION_BYPASS exception. Bypass is not SMS proof; disabling it invalidates bypass evidence and old fabricated timestamps for gated actions without undoing links or purchases. An existing valid unlinked account keeps its session and cart; resolve its customer before producing a quote or checkout. Turning off historical matching still permits owned fallback creation. None of these rules replaces provider payment verification.

Merge follows the 0002 reference ownership matrix. Preserve original SMS subjects, checkout user and client UUID keys, provider evidence, promotion identities, and financial snapshots. Resolve current ownership through the approved merge chain instead of rewriting those facts. Preserve the destination login when both customers have one and invalidate the source login; transfer the source login only if the destination has none. Every enabled payment slice must prove these mappings before live collection.

The webhook validates HMAC before trusting the body. Processing also verifies payment source, provider payment ID, merchant transaction ID, amount, currency, customer, company, and attempt state. Errors and logs omit secrets, signatures, full provider bodies, email, phone, and address. Encrypted raw bodies are accessible only to a dedicated support or payment operations permission and are purged after 90 days.

Gemini is advisory for candidate ranking only. Under the approved 0002 boundary, it receives names and temporary candidate labels only. Phones, email, addresses, permanent IDs, payment, invoice, provider, promotion, and customer history stay inside RMS. A model result can never grant customer ownership or merge records.

Dedicated permissions control gateway settings, promotion management, settlement import, settlement review, settlement posting, support inspection, and saved credit allocation. The Administrator role receives them initially. Saved credit allocation remains administrator only. Every money or settings mutation records actor, company, before and after context, time, and source reference. Audit records are mandatory.

All customer and finance reads and writes enforce company and branch scope on the server. The system actor from `SYSTEM_USER_ID` owns verified provider processing. Deployment must provide an active noninteractive system user with the necessary narrowly scoped permissions.

### Configuration required

* `SKIPCASH_ENABLED`: enables new checkout traffic for the deployment.
* `SKIPCASH_ENVIRONMENT`: selects sandbox or production without a dashboard switch.
* `SKIPCASH_BASE_URL`: approved API base URL for the selected environment.
* `SKIPCASH_CLIENT_ID`: merchant Client ID used for transaction detail requests.
* `SKIPCASH_KEY_ID`: merchant key identifier used in request authorization.
* `SKIPCASH_SECRET_KEY`: secret used to sign outbound payment creation requests.
* `SKIPCASH_WEBHOOK_SECRET`: secret used to verify webhook HMAC signatures.
* `SKIPCASH_RETURN_URL`: informational customer return URL.
* `SKIPCASH_WEBHOOK_URL`: public RMS webhook URL registered with SkipCash.
* `SKIPCASH_CURRENCY`: fixed to QAR for this integration.
* `SKIPCASH_TIMEZONE`: fixed to `Asia/Qatar` for merchant report and service date interpretation.
* `SKIPCASH_RAW_EVENT_RETENTION_DAYS`: defaults to 90.
* `CUSTOMER_DIRECT_ORDER_ENABLED`: temporary launch control for the old direct unpaid website endpoint. It is false when SkipCash checkout is live.
* `SYSTEM_USER_ID`: existing RMS system actor used for provider initiated accounting and audit events.

Provider credentials, the webhook key, production URLs, the active SkipCash payment source, its clearing account, both expense mappings, an active default bank, the system actor, the support number, and the Terms and Conditions version are production prerequisites.

`config/payment_terms.php` and its retained versioned content are deployment prerequisites, not secrets or editable payment settings. Validate the configured hash and effective time before publishing. No new provider, content management service, or terms administration screen is required.

### Critical test scenarios

* Happy path: one future ordinary order travels through website quote, SkipCash sandbox payment, signed webhook, order, invoice, payment, allocation, ledger, public status, email, settlement import, fee posting, and bank transaction, verifying **AC-1**, **AC-2**, **AC-3**, **AC-11**, **AC-12**, **AC-15**, **AC-16**, and **AC-20**.
* Membership path: one 20 meal purchase with five main dishes creates a QAR 900 payment and first block, then a later covered booking uses no new payment and a second purchase appends behind it, verifying **AC-4**, **AC-5**, and **AC-6**.
* Promotion path: partial and 100 percent codes preserve allowance rules, exact totals, permanent redemption history, and the request only boundary, verifying **AC-1**, **AC-7**, and **AC-19**.
* Timing boundaries: today and mixed carts still require the approved future only review. A first matching ordinary payment before, at, or after expiry or across Qatar midnight completes the saved purchase once, even after an unpaid target release. Membership expiry retains its separate AC-8 outcome. These cases verify **AC-8**, **AC-9**, **AC-12**, and **AC-14**.
* Concurrency failure: duplicate webhooks, two real captures, two devices spending final membership credits, repeated invoice void, and overlapping report imports create each effect at most once, verifying **AC-5**, **AC-10**, and **AC-13**.
* Accounting failure: missing bank, account mapping, closed period, mismatched report, and unexpected reversal leave settlement unposted without changing the customer payment, verifying **AC-3**, **AC-8**, and **AC-16**.
* Identity and permission: another customer, a customer without genuine proof when bypass is disabled, support staff attempting credit allocation, a cross company administrator, and an invalid webhook are denied. The accepted server bypass can satisfy the phone gate without weakening payment verification, and low confidence signup continues with a new owned customer, verifying **AC-15**, **AC-17**, and **AC-18**.
* Outage recovery: provider session failure, closed browser, delayed event, database failure, status recovery, and email failure preserve one explainable result without a second payment, verifying **AC-11**, **AC-12**, **AC-13**, **AC-14**, and **AC-20**.
* Replay contract: lost create response, crash before saving the URL, changed payload under the same UUID, exact retry after expiry, and unresolved provider ID never issue another create call or leak held orders into operations, verifying **AC-2**, **AC-9**, **AC-13**, and **AC-14**.
* Date authority: unsigned or missing finish time, paid status after a declined event, closed historical receipt date, a later terms version, and an attempted settlement date override retain the original evidence and reject unsafe completion, verifying **AC-9**, **AC-11**, **AC-12**, **AC-16**, and **AC-19**.
* Membership accounting boundary: reserved to invoiced transition, invoice edit then void, restored credit positions, legacy opening reconciliation, a one cent package discount, and zero net slices in a positive package keep quantity and funding exact, verifying **AC-1**, **AC-5**, **AC-6**, **AC-8**, and **AC-10**.
* Settlement identity: reordered or overlapping exports, changed contents under one row reference, equal real fees with distinct IDs, and missing fee identity cannot duplicate or invent a posting, verifying **AC-13**, **AC-16**, and **AC-17**.

Detailed release checks: see [verify.md](verify.md).

## Build plan

The order follows the Tracer Bullet approach. Each slice proves a real path through the website, RMS, provider boundary, accounting, recovery, and tests before breadth is added.

These are shared contracts for the linked implementation specifications, not permission to enable an incomplete slice in production. Early tracer runs stay in sandbox. Customer identity, merge coverage, and payment recovery must be ready before live collection. Covered returning member bookings and legacy readiness checks must be ready before membership checkout goes live, as already required by scope.

1. Add the shared payment source, membership plan, checkout, provider event, and settings foundation with QAR pricing, SkipCash mappings, system actor validation, provider fake, durable create dispatch, and migration coverage, satisfies **AC-1**, **AC-3**, **AC-9**, **AC-13**, **AC-15**, and **AC-17**.
2. Prove one future dated ordinary order from website quote and retained terms through sandbox payment, signed webhook, snapshot activation, AR invoice, AR payment, allocation, ledger, customer status, and email, satisfies **AC-2**, **AC-3**, **AC-11**, **AC-12**, **AC-14**, **AC-15**, **AC-17**, **AC-19**, and **AC-20**.
3. Add several dated orders, exact session replay, server hold snapshots, verified provider status and finish times, durable posting dates, closed period recovery, mixed cart review, ordinary completion after expiry, unchanged late membership credit, distinct duplicate capture handling, and safe release of unpaid attempts, satisfies **AC-2**, **AC-8**, **AC-9**, **AC-11**, **AC-12**, **AC-13**, and **AC-14**.
4. Add paid membership purchase blocks, zero selection purchase, immediate first conversion, sequential later purchases, bounded daily invoice apportionment, funding state transitions, saved cutoff and credit positions, crossing blocks, invoice void restoration, pause integration, and a verified legacy opening dry run, satisfies **AC-4**, **AC-5**, **AC-6**, **AC-10**, and **AC-13**.
5. Add promotion administration and quote validation with exact basis point rounding and price caps, then prove partial discount accounting, zero net meal slices in a paid package, and the isolated 100 percent request path with permanent one customer redemption, satisfies **AC-1**, **AC-7**, **AC-13**, **AC-15**, **AC-18**, and **AC-19**.
6. Extend registration matching and the existing customer merge to every new checkout, provider, payment source, membership funding, settlement review, and promotion reference, satisfies **AC-13**, **AC-15**, and **AC-18**.
7. Add protected XLSX staging, reviewed sale matching, stable transaction and fee identities across exports, separate expense mappings, evidenced bank dates, guarded batch posting, net default bank posting, and bank reconciliation evidence, satisfies **AC-3**, **AC-13**, **AC-15**, **AC-16**, and **AC-17**.
8. Add RMS operations pages, dedicated permissions, recovery runs, raw event purge, exception reporting, consistency checks, audited settings, and administrator credit allocation, satisfies **AC-8**, **AC-9**, **AC-11**, **AC-12**, **AC-13**, **AC-14**, **AC-15**, **AC-16**, **AC-17**, and **AC-18**.
9. Complete customer website return, account, existing membership, booking change, terms, email, accessibility, and outage journeys. Disable the legacy direct order path for website traffic and execute the full release matrix, satisfies **AC-6**, **AC-9**, **AC-10**, **AC-12**, **AC-14**, **AC-19**, and **AC-20**.

## Consequences

**Positive**:

* RMS keeps one accounting truth and reuses established financial controls.
* Retries, late events, duplicate captures, and closed browsers have explicit recovery outcomes.
* Membership price, allowance, booking, invoice, and funding remain explainable across several purchases.
* Provider fees and bank payout no longer distort customer receipts or revenue.

**Negative and tradeoffs**:

* The durable contract adds several tables, permissions, scheduled checks, and operational review screens.
* The first release needs a reviewed XLSX settlement import because automatic payout retrieval is not included.
* No refund support means some customer cases remain retained credit that an administrator must resolve.
* Ordinary completion after expiry still creates its original orders and dates. The timer cannot be treated as a guarantee that an existing purchase will never complete.
* A public `completed` payment may still have `purchase_confirmed = false` for a late membership capture under AC-8, so shared website screens must continue to read both fields.
* Keeping routine order states out of fulfillment simplifies one person operations but postpones physical delivery tracking.
* An ambiguous provider create call or locked historical posting can require operations recovery. The system retains evidence rather than retrying a possible charge or moving its accounting date.
* Legacy financial records with missing evidence need reconciliation before the new automatic funding path can use them. Existing access and valid new purchases remain separate from that readiness check.

**Neutral**:

* Existing AR payment, invoice, allocation, void, ledger, bank, and audit records remain canonical.
* Existing membership balance fields remain in use even though purchase blocks add funding attribution.
* Provider wallet or card type is metadata only and does not change the RMS payment method from `skipcash`.
* Later scope specifications refine each implementation slice while preserving the shared financial contract. The accepted 0002 specification defines the customer identity refinement.

## Follow-up

* [x] Customer matching design accepted on 2026-08-30 in [0002](../0002-customer-matching-signup/index.md). Its temporary bypass, account continuity, names only AI, and merge evidence rules refine this identity boundary; implementation is still pending.
* [ ] Write the remaining linked specifications already listed in scope for paid order checkout, settlement, operations, consistency checks, membership purchase, existing membership booking, promotion administration, and promotion redemption before their respective slices begin.
* [ ] Finance must confirm the requested invoice issue revenue policy for advance ordinary orders before production use.
* [ ] Confirm that `55683442` is the production support number and publish one Terms and Conditions version covering no expiry, cutoff, no refunds, and administrator only credit allocation.
* [ ] Confirm the deployed default company, active default bank, `Asia/Qatar` behavior, system actor, ledger mappings, and SkipCash sandbox and production credentials before live collection.
* [ ] Confirm hosted checkout PCI DSS responsibilities with SkipCash and the merchant acquirer before production use.

## Verification

The acceptance matrix and safe test environment requirements are in [verify.md](verify.md).
