# 0003. SkipCash paid ordinary orders

**Date**: 2026-08-31
**Status**: Proposed

## Summary

Customers review an ordinary order priced by RMS, pay on SkipCash in the same browser tab, and return to an RMS payment result. A verified successful collection creates the original orders, one payment, and one paid invoice for each selected service date, even if payment finishes after checkout expiry. Saved checkout records recover interrupted visits without creating another charge or exposing unpaid orders to operations.

## Requirements

**User stories**:

* As a customer, I want to pay for my selected dates once and recover the result if my connection or browser closes.
* As the operator, I want confirmed orders and paid invoices without managing extra order states or manually linking every new account.
* As the accountant, I want the gross receipt in SkipCash clearing, exact invoice allocations, and preserved evidence for later fee and payout reconciliation.

**Acceptance criteria**:

* **AC-1**: RMS prices ordinary Daily Dish selections using the current portion, bundle, and extra side rules. It validates menu IDs, dates, roles, and positive whole main quantities. Browser prices are not authoritative. All gateway amounts are integer QAR cents, discount and tax are zero, and delivery adds no charge. Membership, promotion, and saved credit inputs cannot enter this ordinary checkout.
* **AC-2**: Quote and first checkout creation resolve the active customer's ownership and phone policy through 0002, including the accepted temporary server bypass. Matching uncertainty does not create a staff approval gate. Default company, current public order branch, payment source, currency, invoice, and payment ownership agree.
* **AC-3**: RMS excludes today using the Qatar date, shows the excluded selections and configured contact, and requires review of the future only cart and new total. Today only and empty carts cannot start payment. First creation accepts the current retained terms version. A started checkout keeps its original selections, prices, terms, start, and expiry.
* **AC-4**: Each submitted request has a durable client UUID and immutable request fingerprint. Exact retries return the original reference and outcome. Changed requests under that UUID return 409. At most one provider create dispatch occurs for that attempt; a timeout or crash after dispatch never permits an automatic replacement create call.
* **AC-5**: Only verified SkipCash evidence can establish payment. RMS checks the signature, provider identity, merchant reference, amount, currency, and reliable finish time. Browser return parameters prove nothing. Paid evidence is not downgraded by delayed unpaid events.
* **AC-6**: One eligible verified capture atomically creates one ordinary order and one issued, fully paid AR invoice per dated target, one RMS payment, and exact allocations. Before completion there are no operational orders, invoices, meal plan requests, subscriptions, or meal usage effects. Completion failure rolls back all those effects while retaining provider evidence.
* **AC-7**: The payment records source `ar`, method `skipcash`, and the approved payment source. Its receipt debits that source's clearing account and credits AR. Each invoice issue records revenue once. No bank deposit, commission, fee, settlement, or second revenue entry is created by this slice.
* **AC-8**: Gateway invoice issue does not automatically consume older customer advances. Only the new checkout payment funds these invoices. Existing administrator allocation and other invoice workflows retain their behavior. Later invoice edits or voids and payment corrections cannot cause a repeated webhook to recreate or refill the original purchase.
* **AC-9**: The first fully verified matching capture completes the original ordinary purchase even when payment finishes at or after checkout expiry or confirmation arrives after Qatar midnight. Use the saved selections, service dates, prices, and terms; expiry alone cannot turn this payment into retained credit or a support case. A distinct additional collection for the same attempt creates one unallocated receipt, not another purchase. Missing, inconsistent, or mismatched evidence remains an exception. No refund or recurring charge is introduced.
* **AC-10**: Receipt, invoice issue, allocation, and reversal dates follow 0001 and remain stable on retry. All required period and finance locks apply. A locked or unavailable accounting path leaves verified payment public `paid_processing`, with no partial order, invoice, allocation, or invented posting date.
* **AC-11**: The website uses only `pending`, `paid_processing`, `completed`, and `declined`, together with `purchase_confirmed`, amounts, message, and recovery reference. It preserves the submitted cart until RMS confirms the purchase, supports same tab return and Account recovery, and never presents a future order as delivered.
* **AC-12**: Completed orders produce customer and administrator confirmations through existing mail infrastructure after the financial commit. Their recipients, amounts, dates, and references come from retained RMS data. Email failure is independently retryable and cannot change payment success or repeat accounting.
* **AC-13**: Customer and staff access is enforced on the server. New references follow the 0002 merge matrix and destination login rule. Logs, errors, analytics, and queued payloads disclose no provider secrets or customer contact data. Audit history, encrypted provider evidence, and the 90 day raw body purge are enforced.
* **AC-14**: Bounded scheduled recovery handles lost dispatch, delayed confirmation, incomplete accounting, and failed confirmation delivery. It never retries an ambiguous provider create or silently repairs financial history. Settings changes are authorized, company scoped, audited, and do not change active attempts.
* **AC-15**: Additive migrations, both website base paths, existing account and order views, and non gateway financial behavior remain compatible. Live cutover rejects the old direct unpaid customer order route and preserves every advertised membership journey through its separately completed slice. Disabling new checkout never disables verification and completion of money already collected.

## Decision

**Chosen option**: Add a durable RMS checkout alongside the current submission route, then cut over the website after verification.

Reuse the accepted records and financial authority in [0001](../0001-payment-accounting-contract/index.md), the identity boundary in [0002](../0002-customer-matching-signup/index.md), Laravel services, database transactions, queues, and the existing PHP website. New payment endpoints own orchestration; existing domain services remain the writers of orders and accounting. (basis: both accepted designs, the repository service guides, and the strangler pattern for changing a live system)

## Rationale

Reasoning, alternatives, provider research, and source evidence: see [rationale.md](rationale.md).

## Feature design

### Boundary and delivery

This is scope feature 2, not another shared accounting design. It implements ordinary flexible Daily Dish purchases only. Membership purchase, covered booking, promotions, settlement import, fee posting, full operations screens, and the wider consistency dashboard remain separately owned scope features. The payment source and basic recovery foundation added here will be reused by those features.

The same tab handoff and this subset of the 0001 record structure were confirmed on 2026-08-30. Data storage refinements below name the fields needed for replay, dispatch, and notification recovery within those records. They introduce no individual meal records, delivery workflow, customer wallet, or replacement invoice model.

Design confirmed on 2026-08-31 after the four approved review fixes and withdrawal of the speculative extra payment email proposal. The normal flow creates one provider session per checkout and processes its successful payment once. Repeated requests or notifications are not additional collections. Multiple successful charges within one hosted session have not been established as provider behavior, so no dedicated customer journey, email, or additional launch gate is added for that scenario. The existing rules for independently verified financial evidence remain defensive accounting safeguards, not a claimed customer payment path.

### Records and constraints

All new records use integer primary keys, timestamps, and explicit casts. Foreign key types match their referenced tables. Monetary columns are signed `BIGINT` cents with nonnegative validation, except existing reversal records with their established sign rules. API amounts must also remain within exact JSON integer range. UUIDs use canonical lowercase text; SHA256 hashes use lowercase 64 character hex. New instants use UTC `datetime(6)`; business dates use `date`. Private structured snapshots use encrypted text casts; searchable identifiers, states, dates, and amounts remain separate columns.

| Record | Keys and relationships | Stored values and constraints |
|---|---|---|
| `payment_sources` | PK `id`; FK company and clearing account; one company has many sources | Unique `(company_id, code)`. Code and method `skipcash`, name, `is_active`, creator/updater. Account belongs to that company. Do not cascade delete financial history |
| `payment_settings` | PK `id`; unique FK `company_id` | Duration 5 through 60 whole minutes, default 15; cutoff default `23:00`; timezone fixed `Asia/Qatar`; support phone; actor. Cutoff is stored for the later membership slice, not applied as a new ordinary ordering rule |
| `payment_checkout_attempts` | PK `id`; unique public `reference`; FK company, branch, current customer, original portal user, source | Unique `(portal_user_id, client_uuid)`. Purpose `ordinary_order`, QAR, gross/discount/payable cents, quote/cart/request/recovery fingerprints, state, start/expiry/completion, encrypted cart/customer/pricing/terms/request/notification snapshots, source account snapshot, provider request UUID, dispatch time/outcome, last error code, next recovery time, nonpersonal confirmation dispatch markers |
| `payment_checkout_targets` | PK `id`; FK attempt; unique `(attempt_id, sequence)`; nullable linked order and invoice IDs | Type `order`; service date; expected cents; encrypted immutable item snapshot; hold state `held`, `activated`, or `released`; state times; intended invoice issue date. One target per selected date. Order and invoice IDs remain null before activation and cannot link to a second target |
| `payment_provider_transactions` | PK `id`; FK attempt/source; unique `(payment_source_id, provider_payment_id)`; nullable unique FK `payment_id` | Merchant transaction ID, amount/currency, raw and normalized status, verified finish instant/evidence source, nullable `verified_paid_at` acceptance marker, detail check time, encrypted pay URL, nullable evidenced provider expiry, Visa ID/card type metadata, classification, retained receipt date and receipt client UUID. Merchant transaction ID is deliberately not unique |
| `payment_provider_events` | PK `id`; FK source; nullable FK provider transaction; unique `(payment_source_id, payload_hash)` | Provider payment ID, normalized values, verified signature key reference, state/error code, receive/process/next retry times, encrypted raw body and removal time. A valid event may precede the saved create response; do not require the transaction FK before retaining it |
| Existing `payments` | Add nullable FK/index `payment_source_id` | Required for new SkipCash receipts, immutable after creation, otherwise null for existing methods. Keep existing UUID uniqueness, active allocation uniqueness, and subledger source event uniqueness |

Use ordinary indexes for attempt `(customer_id, created_at, id)`, `(customer_id, recovery_fingerprint, state)`, and due recovery `(state, next_recovery_at, id)`; provider merchant reference lookup `(payment_source_id, merchant_transaction_id)`; and event `(processing_state, next_retry_at, id)`. Preserve events, target snapshots, and normalized financial evidence; the purge removes only eligible encrypted raw bodies. No cascading deletion or general checkout deletion job is included.

The source account snapshot is the same company clearing account approved when the attempt starts. A later configuration change cannot redirect an existing receipt. An unavailable or invalid account creates an exception rather than falling back to card or other clearing. Source deactivation blocks new attempts; it does not erase evidence or change the account of an already started attempt.

The attempt retains two notification slots, `customer_confirmation` and `admin_confirmation`. Their immutable recipients and message snapshots live in `notification_snapshots`, an encrypted text cast, never plain JSON. A separate structured `notification_dispatch` field holds only each slot's state, claim UUID/time, retry count/time, sanitized error code, and nullable successful EmailLog ID. Searchable delivery markers contain no names, addresses, or message text. Lock the attempt to claim a slot; queue only its ID and slot key. This is a durable delivery intent using existing mail infrastructure, not another outbox or a claim that external email delivery can be exactly once.

Attempt states and transitions are those in 0001: initiating to pending to paid_processing to completed; unpaid initiating/pending may become declined or expired; later verified paid evidence may enter paid_processing from either unpaid result. Review uses needs_review and may resume paid_processing. Ordinary payment after expiry completes through the normal purchase path; it does not enter payment_received_as_credit. That shared internal state remains available to other purposes in 0001. A completed purchase is terminal, and additional receipts do not replace it. Provider create outcome is separately not_sent, in_flight, created, rejected, or unknown; it is never itself proof of payment. Provider normalized status is pending, paid, unpaid_terminal, reversal_exception, or unknown. Event processing state is pending, processing, processed, retryable, or quarantined.

Completion intent names its storage explicitly: attempt `financial_intent` retains allocation_date and selected provider transaction ID; each target retains intended_invoice_issue_date; the transaction retains receipt_date and receipt_client_uuid. Generate that receipt UUID once when paid evidence is retained, not from the attempt UUID, so two real collections cannot share it. Provider classification is pending, purchase, retained_credit, or needs_review; the retained credit reason for this ordinary slice is additional capture, never checkout expiry.

### Canonical cart and prices

Quote input uses purpose `ordinary_order` and `cart.items[]`. Each day supplies `key` as `YYYY-MM-DD`, `mains[]` with `menu_item_id`, `portion` (`plate`, `half`, `full`), and positive integer `qty`, nonnegative integer `salad_qty` and `dessert_qty`, and optional notes. Empty main lists are invalid. Reject duplicate date entries; combine repeated identical main ID and portion rows, then sort days by date and mains by ID and portion. Reject unsupported keys that request plan, promotion, credit, price, currency, source, or account overrides.

Validate calendar syntax first. For quotes and genuinely new attempts, dates before the current Qatar day return 422; today's selections enter the excluded set before menu lookup, so a missing menu for today cannot block valid future selections. Creation resolves exact replay and equivalent unresolved checkout recovery before these mutable date/menu rules. Quote returns the allowed submission cart separately from priced/display details. Checkout resubmits only that allowed cart, not server generated price or display fields.

Resolve the published `DailyDishMenu` for the current public order branch and date. Each main ID must belong to its main role and be selectable by the existing menu rules. Resolve selected sides from that menu's salad and dessert roles. If a role cannot resolve uniquely, return an actionable menu configuration error, not a guessed side. Names, descriptions, roles, and prices come from RMS, not submitted display labels. Availability stays as implemented today; do not add kitchen capacity, stock reservations, delivery area checks, or a new lead time.

Read prices from the existing `pricing.meal_plan.base_prices` and `pricing.daily_dish.portion_prices` / `addon_prices` configuration used by `MealPlanPricingService`. Convert each unit price to cents once with decimal safe half up rounding. Then multiply and sum integers:

* For all plate mains, allocate included sides using the existing bundle rule: main with both sides first, then one side, then main only. Excess salads and desserts are separate extras. Canonical main ordering makes line attribution deterministic.
* If any main on the date is half or full, price every main on that date at its portion rate and every selected side separately, matching the current website rule.
* Current checked in examples are plate 50, half 130, full 240; main plus one side 55; main plus both 65; extra salad or dessert 15 QAR. These are examples, not a second price source or evidence of production configuration.
* Quote day totals sum to the payable total. Gross equals net, tax and discount are zero, and delivery is included. Reject nonpositive totals and overflow before starting a provider session.

Keep order decimal fields compatible by formatting integer cents as exact decimal strings, including trailing zero precision where needed. Quote snapshots include every priced component, menu identity, description, quantity, date, and total. Persisted order line totals and header total must reconcile to the snapshot. Ordinary Daily Dish invoices retain the existing summary line format and source order link, with `is_subscription = false` and no plan sellable metadata.

### Quote, review, and request identity

There is no new quote table. A quote is a recomputable server result and does not create an attempt or start its payment clock. After replay and unresolved checkout recovery, genuinely new checkout creation recomputes the price and menu result under current terms and Qatar date before comparing the quote fingerprint. A changed quote returns 409 with a revised quote for explicit review; it cannot silently charge the new amount.

Use one versioned canonical encoding helper: ordered JSON arrays, compact UTF8, unescaped Unicode and slashes, no floats, integer quantities and cents, decimal string database IDs, explicit null for absent optional values, ISO dates, and LF line endings for notes. Trim outer note whitespace but preserve internal text. The canonical day tuple is `[date, [[main_id, portion, qty], ...], salad_id_or_null, salad_qty, dessert_id_or_null, dessert_qty, notes_or_null]`.

* `cart_fingerprint` is SHA256 of `["ordinary-cart-v1", company_id, branch_id, canonical_day_tuples]`. It identifies the resolved cart snapshot, not equivalent checkout recovery. It excludes customer contact data and prices.
* `recovery_fingerprint` is SHA256 of `["ordinary-recovery-v1", company_id, branch_id, normalized_submitted_cart]`. It identifies the customer's submitted selections without resolved side IDs, prices, terms, or profile values. Query it only within the authorized canonical customer and approved merge aliases, with the same company and branch.
* `pricing_version` is SHA256 of the ordered applicable price keys and their integer values, plus the pricing algorithm version `ordinary-pricing-v1`.
* `quote_fingerprint` is SHA256 of `["ordinary-quote-v1", customer_id, company_id, branch_id, "QAR", canonical_day_tuples, pricing_version, day_totals_cents, payable_cents, terms_version, terms_hash]`.
* `request_fingerprint` is SHA256 of `["ordinary-request-v1", "ordinary_order", normalized_submitted_cart, submitted_quote_fingerprint, accepted_terms_version, separate_purchase_from_or_null]`. Store that normalized submitted request as well as the authoritative snapshot. It excludes current mutable prices, customer profile values, and current merge ownership.

For request and recovery fingerprints, normalized_submitted_cart is the ordered list of `[date, [[main_id, portion, qty], ...], salad_qty, dessert_qty, notes_or_null]`. Its normalization is syntax only and requires no current menu lookup or comparison with today's date. Therefore recovery still matches after menu sides, prices, terms, or the Qatar day change. Resolved side IDs remain in the quote fingerprint and immutable target snapshots. Order applicable pricing keys lexically when calculating pricing_version.

Create `client_uuid` once when the customer confirms that reviewed purchase. Keep that UUID and exact normalized request through transport retries. Resolve an existing `(original portal user, client_uuid)` before repricing, checking current terms/profile details, or creating provider work. Recheck active login and ownership, then return its saved result using its saved provider customer snapshot. A changed fingerprint returns `REQUEST_CHANGED`, HTTP 409. The 600 second legacy order cache is not payment deduplication.

Under a canonical customer lock, look up `recovery_fingerprint` before current menu, date, pricing, terms, or profile validation for a new attempt. An equivalent unresolved cart with a different UUID returns HTTP 409 `EXISTING_CHECKOUT` and the owning recovery reference, without creating an attempt or dispatching anything. A changed dated salad or dessert cannot bypass this guard. The website offers recovery first. The already approved deliberate repeat purchase action may submit a fresh quote and UUID with `separate_purchase_from` equal to that owned unresolved reference; save that explicit choice in the request snapshot. Only this explicit separate purchase proceeds through current validation to a new attempt. It never cancels or replaces the earlier attempt. Transport retries do not invent this field. This guard is a recovery aid, not a ban on buying identical orders.

On merge, original portal user and request identity remain unchanged. The surviving login reads or resumes the existing reference through canonical customer ownership; it does not rewrite the original user key or send a new create request on its behalf. A new deliberate purchase uses the surviving user's own UUID namespace. A precreation quote whose ownership changes requires a new quote; an existing attempt keeps its accepted snapshot.

### API surface

New customer routes reuse Sanctum, active customer role/ability checks, and existing JSON envelopes. Quote and first creation use the 0002 phone policy; owned status/history remain readable after bypass shutdown. Register literal `quote` before the reference route.

| Endpoint | Method | Inputs | Outputs | Access and errors |
|---|---|---|---|---|
| `/api/customer/checkouts/quote` | POST | Purpose and cart | Canonical accepted cart, excluded today items, day totals, gross/discount/payable cents, QAR, quote fingerprint, current terms version/URL/hash, support phone, `can_checkout` | Customer with satisfied server phone policy; 422 invalid cart, 503 missing pricing/terms/context |
| `/api/customer/checkouts` | POST | Client UUID, purpose, reviewed cart, quote fingerprint, accepted terms version, optional explicit `separate_purchase_from` | Reference, public status, confirmation flag, message, amounts, expiry, nullable pay URL, replay flag | Same policy for first creation; 409 changed request/quote or unresolved equivalent checkout, 422 invalid terms/mode or required profile fields, 503 unavailable before creation. New queued attempt returns 202; known saved result returns 200 or 202 as below |
| `/api/customer/checkouts` | GET | Optional public status filter, page, per page default 20/max 100 | Owned checkout summaries ordered newest first, pagination, recovery references | Active owning customer; 401/403 denied, 422 filter. No provider raw body or hosted URL in list results |
| `/api/customer/checkouts/{reference}` | GET | Public checkout UUID | Full owned status, amounts, dates, usable pay URL or null, retained review cart, confirmed order/invoice references and current invoice statuses, support phone | Active owning customer; 403 wrong customer, 404 missing, 409 broken ownership chain |
| `/api/integrations/skipcash/webhook` | POST | Signed provider JSON and Authorization header | Accepted/duplicate or sanitized retry response | Verified provider HMAC; 400 malformed, 401 invalid signature, 409 semantic conflict, 503 temporary processing failure |
| `/api/accounting/payment-settings` | GET, PUT | Resolved company; PUT duration, cutoff, support phone | Current settings and audit result | Dedicated `payments.settings.manage` permission, initially Administrator; 403 denied, 409 company conflict, 422 invalid values or timezone change |

GET list is the compatible recovery refinement to the previously accepted POST path. Detail and list never trust a client supplied customer/company/branch ID. The active website `api/orders` proxies these contracts with authorization forwarding, no caching, and preserved upstream statuses. Do not move business validation into PHP wrappers or use the older `api/daily-dish` copies as a new integration.

Status payload field names are `reference`, `status`, `purchase_confirmed`, `message`, `recovery_reference`, `currency`, `payable_amount_cents`, `paid_amount_cents`, `confirmed_amount_cents`, `retained_credit_amount_cents`, `expires_at`, `pay_url`, and `confirmed_targets`. Detail also includes `reviewed_cart` and `support_phone`. Each confirmed target identifies order/invoice IDs and numbers, service_date, total_amount_cents, invoice_status, and invoice_balance_cents. List uses the same summary meanings but omits private cart, targets, and pay_url. Quote uses `cart`, `excluded_today`, `day_totals`, `gross_amount_cents`, `discount_amount_cents`, `payable_amount_cents`, `currency`, `quote_fingerprint`, `terms_version`, `terms_url`, `terms_content_hash`, `support_phone`, and `can_checkout`.

A mixed quote has `can_checkout = true` only when future items remain; a today only quote has false and payable zero. The UI displays both sets and requires another review of only the accepted cart. A create request still containing excluded dates returns 409 and does not silently remove them. A same day test uses RMS `Asia/Qatar`, never device time.

### Provider adapter and one dispatch

Use a small provider interface implemented with the installed Laravel HTTP client and PHP HMAC functions. No new SDK, payment platform, scheduler, or frontend framework is needed. Queue payloads carry attempt or event IDs only.

Before first attempt insertion, resolve and validate every required provider customer field. Use the authenticated user's `portal_name`, falling back to `users.name` when absent, not the linked customer's display name. Trim and collapse Unicode whitespace while preserving case, accents, punctuation, and word order. Require valid UTF8, a nonblank resulting name, and no remaining control characters; do not invent an ASCII or letters only restriction. Derive the outbound name fields using the wire rule below and validate the actual phone/email against the provider limits. A missing or unusable value returns HTTP 422 with `PROFILE_REQUIRED` and stable `profile.name`, `profile.phone`, or `profile.email` field errors directing the customer to correct their account. Keep the cart; create no attempt, targets, or provider work. Never derive a name from email/phone or fill required fields with dummy values. Exact replay and owned recovery run first and use retained values, so later profile changes cannot strand an existing payment.

1. Within a database transaction, lock the customer recovery boundary, resolve replay/recovery, validate genuinely new request/context and provider profile values, then insert the attempt/targets and fixed provider request UUID. Snapshot the validated provider fields, terms/customer/prices/source account, and mark create outcome `not_sent`. Start and expiry are server instants; duration is copied from settings.
2. After commit, dispatch initiation on the existing queue. A lost queue dispatch is recoverable from `not_sent`; the controller returns 202 with the durable reference, never a fabricated payment URL.
3. The worker locks and claims `not_sent` exactly once, changes it to `in_flight`, retains dispatch time, and commits before HTTP. Do not send if the hold has already expired or new initiation was disabled before this claim.
4. Make one create call, outside database transactions, with no HTTP retry middleware for POST. Store a valid response under the attempt lock. A transport timeout, uncertain response, or worker crash after the claim becomes `unknown`. Recovery cannot reset it to `not_sent`.
5. Recover a known provider ID with authenticated details, or an unknown ID through a valid webhook correlated to the merchant reference. Without evidence, retain an operations exception. Never assume the merchant reference prevents provider duplicates.

Default create connect/total timeouts are 2/8 seconds, with a 30 second worker timeout and a 60 second stale claim threshold. Crossing the threshold makes the outcome unknown; it does not prove no request was sent. A definitive provider validation rejection sets outcome rejected and unpaid declined without another create. A never claimed attempt disabled or expired before dispatch releases its targets without a provider call. A claimed attempt requires provider recovery even after the hold deadline.

**Wire contract from the earlier official provider research**:

* POST `/api/v1/payments` to the configured environment base URL. Mandatory values are `Uid`, `KeyId`, `Amount`, `FirstName`, `LastName`, `Phone`, and `Email`; also send `TransactionId`, `ReturnUrl`, and `WebhookUrl`. Do not request tokenization or recurring payment, and do not send optional address or custom fields in this slice.
* `Uid` is the attempt's saved provider request UUID. `TransactionId` is its public UUID with hyphens removed, a deterministic 32 character merchant reference. It is correlation, not authorization or provider idempotency.
* `Amount` is the payable cents formatted with a dot and exactly two fractional digits. Customer name comes from the validated authenticated account snapshot above. Split its normalized whitespace at the first word for FirstName and remaining words for LastName; for one word, repeat that actual word in both required fields. Limit each to 60 Unicode characters in provider fields only, preserving the full RMS name. Store the final outbound fields before initiation; the worker does not reread a mutable profile.
* Phone comes from the effective normalized phone in the 0002 policy, email from the authenticated account. Use that customer's real values, not a shared dummy. Validate the provider length/format limits before first attempt insertion (phone maximum 15 characters and email maximum 255 in the reviewed documentation). Do not silently truncate phone/email. Retain the existing address in the order snapshot without new delivery validation; do not send it to SkipCash.
* Create Authorization is Base64 of binary HMAC SHA256 with the merchant Key Secret. Concatenate the supplied nonempty signed values in this exact order as comma separated `key=value`: `Uid,KeyId,Amount,FirstName,LastName,Phone,Email,TransactionId`. Use exact outgoing UTF8 field text, no body reserialization hash and no whitespace inserted between pairs. ReturnUrl and WebhookUrl are not added to that documented signed field set.
* Read `resultObj.id`, `payUrl`, merchant reference, amount, currency, and status from a validated successful create response. Retain only the allowed normalized metadata. A malformed success is an unknown create outcome, not permission to send again.
* GET `/api/v1/payments/{id}` uses the configured Merchant Client ID in Authorization, not the create HMAC. It supplies independent status, `finishedDate`, merchant reference, amount/currency, and reconciliation evidence.
* Webhook Authorization is Base64 of binary HMAC SHA256 with the WebHook Key over nonempty fields in the exact order `PaymentId,Amount,StatusId,TransactionId,Custom1,VisaId`. Preserve `0` as a value. Compare decoded signatures in constant time. Verify against the documented fields before normalizing any monetary text. Unexpected nonempty Custom1 is a semantic conflict because this slice sends none.

The prior research found inconsistent examples for empty optional signing fields and mandatory Phone. This payload always supplies Phone and all required signed values and omits optional address/custom fields. A provider confirmed signature vector and sandbox test of this exact payload are an integration gate before live collection, not a new business decision or permission to guess an alternate signature after a failure.

Store only allowlisted HTTPS hosted URLs from the configured SkipCash host list. Do not accept browser return URLs, follow provider HTTP redirects to arbitrary hosts, or log the hosted URL. Build ReturnUrl from the configured website payment page and RMS reference query parameter, independent of any provider appended query fields. Do not use the provider's date only expiry field to represent the RMS minute deadline; no such equivalence was established.

The return query key is `checkout_reference`, containing the attempt's public UUID. Construct it server side with a URL encoder and preserve it through the website's asset version redirect. It identifies a lookup only and grants no access without the owning login.

### Confirmation, dates, and accounting

HMAC authenticates only the signed fields. Always obtain authenticated transaction details when currency or finish time is not authenticated by the webhook. Validate source, provider ID, merchant reference, amount, and QAR against the attempt. A phone match is never a payment match. A verified webhook may create the missing provider transaction row if its source and reference resolve exactly to the attempt.

| Provider status ID | Treatment |
|---|---|
| 0, 1, 12 | Pending; no receipt authority |
| 2 | Paid fact, subject to complete independent verification |
| 3, 4, 5 | Unpaid terminal result, never a downgrade of verified paid evidence |
| 6, 7, 8 | Reversal/refund exception for finance, no automatic financial reversal |
| Other | Preserve evidence and recover/review, no guessed success |

Store verified finish time as an instant with its evidence source. Honor an explicit offset; interpret offsetless provider time as Qatar. Invalid, missing, future, or contradictory finish times stay unresolved because the receipt still needs an evidenced accounting date. For ordinary orders, `finished_at < expires_at` is not an acceptance condition: a fully verified matching capture before, exactly at, or after expiry completes the same saved purchase. Under the normal completion lock, an unpaid target previously marked released can activate once from its retained snapshot. Do not reprice, substitute items, change service dates, rerun the new same day test, or require the customer to submit again. The no same day rule still applies to new or changed selections. These holds reserve no kitchen stock or capacity.

Persist verified provider facts, a stable receipt client UUID, receipt Qatar date, and each target's intended invoice issue date before entering the financial completion transaction. Invoice issue date is the Qatar date when verified processing first durably schedules that target for issue, not its service date. The allocation date is the first scheduled allocation event date, retained alongside that completion intent. Use those dates explicitly through the canonical AR/ledger boundaries; do not rely on the application's default timezone or `now()` fallbacks.

The first fully verified eligible capture to claim an uncompleted attempt under lock funds the purchase. Later evidence cannot replace that assignment. Distinct real additional collections get their own transaction and receipt, even if an earlier provider finish time arrives later. All captures must pass ownership, amount, currency, and date verification; mismatched money is not silently assigned as ordinary customer credit.

Set a transaction's `verified_paid_at` only after authenticated paid evidence passes all expected source, provider ID, merchant reference, canonical customer/company/branch, exact payable amount, QAR, and reliable finish time checks. Save the first RMS verification instant in UTC with the accepted evidence before accounting; it is not the provider finish time or the receipt date. This is acceptance of a matching capture, not merely a valid signature or provider status 2. Retain that marker and its accepted amount, currency, finish time, and attempt association unchanged. Conflicting later payloads remain separate event evidence for review and cannot overwrite or erase this accepted fact. Pending, incomplete, or quarantined evidence without that marker has no public paid amount authority.

Save the selected provider transaction ID in financial_intent under the attempt lock before accounting starts. That assignment survives rollback and finance review. A second eligible collection is additional even while the chosen transaction is still processing; it cannot take the first transaction's place.

The completion service runs the following as one database transaction:

1. Resolve and lock the current owner using the 0002 merge lock boundary; then lock attempt, provider transaction, targets in sequence, and financial records in canonical service order. Recheck completion, source/company/branch/currency, retained account, and all required finance dates. Preserve the established invoice before payment lock order used by AR allocation services; no reverse path may acquire a customer lock after those financial locks.
2. Activate each target through a reusable persistence path in `CustomerDailyDishOrderService`. Extract that path from the legacy wrapper so gateway activation does not create its legacy MealPlanRequest or send inline mail. Use original snapshots, normal numbering, source `Website`, ordinary Daily Dish fields, existing item roles, and current routine default states. Missing required referenced records produce an exception, not replacement menu choices.
3. Create the AR draft through `ArInvoiceService::createFromOrder`, retain source links and target invoice ID, and issue with an explicit internal allocation policy `none`. Default policy stays `legacy_auto` for existing callers. Neither policy is accepted from customer HTTP input. Issue uses the preserved date and canonical invoice revenue/audit behavior.
4. Call `ArPaymentService::createPaymentWithAllocations` with the verified amount, receipt UUID, source `ar`, method `skipcash`, payment source, original receipt instant/date, and exactly these invoice balances. Extend source and date parameters without changing legacy defaults. Require total allocations to equal payable cents, every invoice paid with zero balance, and payment unapplied amount zero. A capped or partial allocation is a completion failure, not partial success.
5. Receipt posting resolves the snapshotted SkipCash clearing account, not method fallback `other_clearing` or `card_clearing`. It records debit clearing and credit AR for the full receipt. No separate advance application entry is needed for this allocated receipt. Existing `BankTransactionService` must produce no bank transaction for method `skipcash`.
6. Link provider transaction to payment, mark targets activated and attempt completed, retain confirmation delivery intents, and commit the audit. A rollback leaves only the earlier durable provider evidence and processing intent. Dispatch email work only after this outer commit.

For a distinct verified additional collection after the funding transaction has been selected, use the same retained date/context and canonical advance creation path once, with no additional order/invoice/automatic allocation. This additional receipt debits SkipCash clearing and credits customer advances. The selected capture uses the ordinary order, invoice, payment, and allocation transaction above regardless of checkout expiry, with receipt debit to SkipCash clearing and credit to AR. Extra capture does not change an already confirmed purchase. A locked date, disabled ledger, missing mapping, missing actor, or accounting exception keeps public `paid_processing`; it cannot produce a partial receipt or silently skip subledger posting.

That paid_processing rule applies while the purchase outcome is unresolved. If an additional collection needs accounting recovery after the purchase is already completed, keep its completed status and purchase_confirmed true. Track the additional transaction exception separately, include a neutral additional payment review message, and exclude its unposted amount from retained credit. Neither that exception nor a later correction downgrades the original purchase.

Issued invoices recognize revenue under the owner's selected policy. Existing invoice edit, void, allocation release, and payment correction services remain canonical and retain their period checks. A repeated completion event reads its original completed links even after a subsequent correction; it cannot recreate a voided invoice or reapply its released allocation. Invoice issue metadata here cannot consume membership usage. A paid invoice is the financial fulfillment signal, not a change to Delivered or another operational order state.

### Public state and website recovery

| Internal result | Public status | Purchase confirmed | Website behavior |
|---|---|---|---|
| Initiating, pending, or unknown create | `pending` | false | Show reference, check again, and Resume payment only when RMS supplies a usable URL |
| Verified payment awaiting accounting, missing evidence, or review | `paid_processing` | false | Explain that payment is being checked/recorded; no second payment prompt |
| Purchase committed | `completed` | true | Show payment and order/invoice references; future dates are booked, not delivered |
| Purchase committed with an additional retained receipt | `completed` | true | Keep the original confirmed orders visible and distinguish the extra amount; never create another order for the same attempt |
| Declined or expired with no verified paid fact | `declined` | false | Keep selections recoverable and offer a fresh reviewed purchase, not silent resubmission |

Evaluate a saved terminal outcome or verified paid evidence before the create outcome. Otherwise an exact retry returns HTTP 202 for `not_sent`, `in_flight`, or `unknown` with no usable URL, and HTTP 200 for a known saved pending session. Terminal outcomes return HTTP 200 even if dispatch never occurred. An attempt with verified paid evidence returns its current processing/completed result, never another create. The same stored URL is returned only for an unpaid pending session while both RMS expiry and any evidenced provider expiry permit it. Missing provider expiry does not invent one. The RMS timer stops unclaimed initiation and hides the payment link; it does not reject money subsequently verified. If a dispatched provider outcome is still pending or unknown, keep public pending recovery and no new payment prompt instead of treating the timer alone as proof of failure. A definitively unpaid expired result may offer a fresh reviewed purchase; a later matching paid fact still completes the original attempt once.

Status amounts retain the 0001 meanings:

* `payable_amount_cents` is the immutable quote.
* `paid_amount_cents` sums the accepted amounts of distinct source/provider transactions with `verified_paid_at`, including matching captures awaiting accounting. Do not sum raw events, signed but mismatched amounts, partial payments, wrong currency/reference evidence, or incomplete verification. Those remain internal review evidence, not customer totals. A later refund/reversal exception or conflicting event cannot subtract or erase an already accepted historical capture or downgrade a committed purchase.
* `confirmed_amount_cents` is the historical amount assigned to the committed purchase, or zero until that commit.
* `retained_credit_amount_cents` is the current unallocated balance of posted captures classified as retained credit. Verified money awaiting accounting is not spendable credit.

Current invoice statuses and balances are separate from historical purchase confirmation. Detail and list use this same projection; no additional public status is introduced.

Add `orders-payment.php` at `/orders/payment`, using existing page helpers/styles and matching Apache/local router mappings. It receives only the RMS reference, requires sign in to load private details, ignores provider paid/amount query parameters, and obtains the result from RMS. Use no store headers, `noindex, nofollow`, and `Referrer-Policy: no-referrer`; do not add this private result to sitemap, public structured data, or third party analytics. Keep root and `/laylakitchen` installations working.

The menu review requests an RMS quote before payment and displays its totals, selected dates, terms link, and explicit consent. Local pricing remains a browsing estimate. Store an owner scoped submitted envelope containing client UUID, request, cart revision, and RMS reference before redirect. A storage failure must leave a durable reference visible and recovery available through Account, not create another attempt. Do not persist hosted URLs or provider bodies in browser storage.

Maintain a submitted cart revision separately from later edits. Clear only that unchanged revision once `purchase_confirmed` is true. If the customer edited selections while payment was open, preserve the newer draft and show which purchase completed; do not blindly clear or subtract the entire current cart. Decline, unknown payment, logout, session expiry, and provider return never clear it. Local state is a convenience, not payment or ownership evidence.

Account obtains pending/recent checkouts from the owned list endpoint even on a different device. Resume loads the retained review and status by reference. Refresh `me` using 0002; a valid unlinked session continues to quote ownership resolution without staff approval. Revoked source login after merge shows the neutral existing sign in/support message and no destination account details. Clear private cached account data when ownership changes.

Poll status every 3 seconds for the first 30 seconds while the page is visible, then every 10 seconds for up to two minutes. Stop automatic polling on terminal result, offline, hidden page, or that limit; keep a manual Check status action and Account recovery. Honor 429/Retry-After. A status read does not issue a provider request on every poll; scheduled recovery owns detail queries.

Use current account/order components with accessible loading and error text, live status announcements, keyboard focus, visible primary actions, and at least 44 px targets at 360, 768, and desktop widths. Ordinary history may derive `booked` or `cancelled` from linked invoice state; an invoice void must not keep a misleading booked badge. Do not claim delivered status from a paid invoice.

Keep existing `order_submitted` analytics semantics tied to RMS purchase confirmation, never return navigation or a 202 response. Use the RMS reference as its transaction ID on the normal menu/account surface, deduplicate within browser storage, and send only the existing permitted nonpersonal fields. Analytics is not an exactly once financial record, and the private return page emits no third party events.

### Email, recovery, and security

Reuse `DailyDishOrderCustomerMail`, `DailyDishOrderAdminMail`, and `EmailLogService`, extending their data for paid status, total, reference, and service dates. Customer recipient is the authenticated account email snapshotted at checkout. Administrator recipients come from the existing configured recipient resolver snapshotted at completion. Save both in the encrypted notification snapshots defined above; never place recipients in dispatch markers, job payloads, operational logs, or error text. A missing admin address creates a delivery exception, not a failed purchase. New paid messages use stored content rather than mutable cart or current pricing. An ordinary payment completed after checkout expiry uses this same order confirmation after commit, not an expiry credit email or a request to contact support solely because it was late.

After commit, a job claims the unsent notification slot, sends outside the transaction, then records the result and EmailLog reference. Retry known failures with bounded backoff. A crash after possible mail acceptance but before the sent marker becomes `unknown`; show that mail exception and do not blindly resend. An authorized deliberate resend can later be supplied by the operations slice and may duplicate the email, never financial effects. No email is sent for an uncommitted order.

Notification state is pending, sending, sent, failed, or unknown. Automatic retries after a known nonacceptance use 1, 5, 15, then 60 minutes, with five total sends before failed requires operator attention. A transport exception after possible acceptance is unknown, not a known failure. A send claim older than the configured 60 second mail worker timeout plus a 60 second safety margin becomes unknown. Never classify every thrown mail exception as permission to resend.

Register `payments:recover-skipcash` on the existing scheduler every minute with overlap prevention. Process at most 100 due IDs per run in stable order and lock each before dispatch. Recover not_sent initiation when still permitted, stale in_flight as unknown without another create, known provider details, retryable event/accounting work, and confirmation slots with known failures. Provider GET uses bounded timeouts and backoff of 1, 5, 15, then 60 minutes, capped at hourly thereafter. Unknown provider IDs and semantic conflicts remain visible operations exceptions, never fabricated unpaid results.

The scheduler selects and dispatches IDs; provider HTTP work runs in claimed jobs, not a long sequential scheduler request. A new not_sent attempt is due immediately; a saved pending session is first due one minute after its last provider check. Detail GET connect/total timeouts default to 2/5 seconds, with no inline retry during a webhook. Unknown IDs and quarantined semantic conflicts are reported without useless GET calls; newly received valid evidence can make them eligible again.

Register `payments:prune-skipcash-events` daily, bounded and restart safe, to null encrypted raw bodies at 90 days from receive time and record removal time. Do not delete normalized evidence, hashes, or financial links. Both commands are harmless on an uninstalled/empty feature. Disabling new checkout must not stop existing event recovery, receipt completion, or evidence purge.

Webhook success is HTTP 200 only after the required handling succeeds or that event was already handled. Retain a valid event before attempting accounting. Use short provider detail timeouts within the provider's documented 10 second callback window; if completion cannot finish promptly, return 503 and schedule recovery. Signed mismatches are retained with HTTP 409 and an internal exception. Invalid signatures are rejected before inbox persistence. Unpaid events and preserved reversal exceptions can be acknowledged after their nonfinancial handling commits.

Require active customer role and token ability on every private route. Enforce `payments.settings.manage` for settings and `payments.support.view` for protected operational evidence, initially Administrator only. Customer reads scope through the canonical owner; staff reads also apply permitted company and branch. Use the active noninteractive `SYSTEM_USER_ID` for provider accounting. No customer may choose this actor, company, branch, clearing account, or payment method.

Default throttles are 60/minute per customer for quote, 10/minute for first create, 120/minute for status/list, and 300/minute per IP for webhook ingress. Apply provider HMAC validation independently of IP. Limit request/body size to 1 MiB before parsing; reject excessive nesting and overflow. Exact accepted create replays still use bounded request handling and cannot bypass ownership checks.

Record audit transitions with attempt/provider/payment/order IDs, actor, company, branch, reason code, event dates, and before/after state. Queue payloads, failed jobs, errors, and metrics contain IDs and sanitized codes only. Do not log Authorization, signature inputs, URL tokens, phone, email, address, notes, full provider bodies, or card/token fields. Restrict decryption of raw evidence, audit access, and purge under the accepted retention rule. Card entry remains hosted; this specification does not certify PCI DSS compliance.

Apply the 0002 ownership matrix to every added reference: mutable current customer ownership resolves to the destination, original portal user/client UUID and snapshots remain evidence, and provider identities and financial dates never change. Include delayed capture during merge and source login revocation tests before live use. Current completion must resolve ownership before entering AR services, not patch financial rows afterward.

### Value sourcing

| Action | Value | Source |
|---|---|---|
| Resolve customer | Owner, original actor, effective phone | Authenticated User, 0002 resolver and phone policy, canonical merge chain |
| Resolve context | Company, branch, currency, source | Default accounting company, current public Daily Dish branch 1 rule, explicit QAR, active company SkipCash source; fail on disagreement |
| Display contact | Name/email/address | `CustomerPortalAccountService::serializeAccount` and authenticated email, with effective phone replaced by 0002 policy result; immutable checkout snapshot |
| Validate provider profile | Actual name, FirstName/LastName, phone, email | Authenticated `users.portal_name` with `users.name` fallback, whitespace and split rules above, 0002 effective phone, authenticated `users.email`; validate before insertion and retain the final fields in the encrypted attempt snapshot |
| Calculate quote | Menus, roles, portions, quantities, component and date totals | Validated cart, dated published menu, existing pricing configuration, integer algorithm above |
| Explain excluded today | Qatar date, excluded items, support phone | RMS clock in Asia/Qatar and company payment settings |
| Confirm terms | Version, URL/hash, actor, time | Effective `config/payment_terms.php`, retained versioned content, authenticated user, server acceptance time |
| Retain identity | Fingerprints, client UUID, replay/recovery reference | Versioned canonical tuples above; recovery uses syntax only submitted cart, not resolved side IDs; browser's persisted submitted UUID, server generated attempt UUID |
| Start provider | Uid, merchant ID, amount, customer fields, URLs, signature | Saved request UUID, reference with hyphens removed, quoted cents, customer snapshot, deployment config, specified signing algorithm |
| Verify collection | Provider ID, status, amount, currency, finish time | Verified webhook signed fields and authenticated same ID transaction details; never phone or browser parameters |
| Post financial records | Receipt/invoice/allocation dates and clearing account | Durable completion intent dates and original source account snapshot, validated by canonical finance services |
| Present result | Status, confirmed flag, amounts, current invoice state | Saved completion state, accepted immutable capture amounts with `verified_paid_at`, committed payment/allocation links, linked invoice reads, mapping above |
| Restore website | Submitted selections, reference, newer draft | Owned RMS target snapshots and separately revisioned browser cart; browser storage is not financial authority |
| Send confirmation | Recipients, paid amount, dates, references, delivery state | Encrypted `notification_snapshots`, committed linked records, nonpersonal `notification_dispatch` slots, existing EmailLog |
| Recover and diagnose | Due IDs, error reason, counters, purge deadline | Indexed saved recovery timestamps/states, sanitized audit codes, event receive time plus retention |

### Configuration and operational prerequisites

Use the 0001 `SKIPCASH_ENABLED`, environment/base URL, Client ID, Key ID, Secret Key, WebHook Secret, return/webhook URLs, QAR, Qatar timezone, 90 day retention, `CUSTOMER_DIRECT_ORDER_ENABLED`, and `SYSTEM_USER_ID` settings. Bind secrets in Laravel configuration only. Website wrappers require only their existing RMS base URL and customer token.

Add a required `SKIPCASH_PAY_URL_HOSTS` allowlist verified against the merchant sandbox and production responses. Optional bounded timeout/recovery defaults belong in `config/payments.php`, not a new settings UI. Credential rotation retains explicitly configured prior verification keys for unresolved events; audit the matched key identifier, never the key.

Initial source setup uses a supplied `SKIPCASH_CLEARING_ACCOUNT_ID` and the resolved default company in an idempotent deployment seeder. Verify the existing account and actor; create no guessed ledger account. Create the unique source only when the account is valid. An existing source is authoritative: a conflicting seed value fails without editing historical mappings. Settings can seed their agreed defaults, but new checkout stays disabled until support contact and terms are confirmed.

Preflight requires the supported QAR AR money scale of 100, active default company aligned with the public order branch, available ledger with AR/revenue/advance mappings, validated source clearing account, active system actor, queue/scheduler, retained terms, keys, callback connectivity, and hosted URL allowlist. Never infer these from .env.example. A mismatch blocks first creation before a provider call. Payout bank and expense mappings remain additional production gates from 0001, although this slice creates no payout.

### Critical test scenarios

The detailed release matrix is in [verify.md](verify.md).

* Price tampering, bundle/portion parity, extra sides, and unsupported modes prove **AC-1** and **AC-8**.
* Legacy unlinked login, accepted bypass, bypass shutdown, cross customer access, and merge during capture prove **AC-2** and **AC-13**.
* Today only and mixed cart review, terms changes, and the same successful ordinary completion before, at, and after expiry or Qatar midnight prove **AC-3**, **AC-9**, and **AC-10**. Include a released target, delayed callback, exact replay, and unchanged saved dates and prices.
* Lost create response, concurrent UUID replay, queue loss, unknown dispatch, and deliberate repeat purchase prove **AC-4**, **AC-11**, and **AC-14**.
* Required provider profile validation before insertion, immutable profile replay, equivalent checkout recovery after side menu changes, encrypted recipient storage, and accepted capture only public totals prove **AC-2**, **AC-4**, **AC-5**, **AC-11**, **AC-12**, and **AC-13**.
* Provider signature vectors, field mismatch, missing finish time, duplicate/out of order events, and reversal evidence prove **AC-5**, **AC-9**, and **AC-13**.
* One and several date purchases, old credit present, injected financial failures, closed periods, edited/voided invoice replay, and no bank entry prove **AC-6**, **AC-7**, **AC-8**, and **AC-10**.
* Same tab return, false success parameters, different device recovery, later cart edits, notification uncertainty, raw purge, and safe cutover prove **AC-11**, **AC-12**, **AC-14**, and **AC-15**.

## Build plan

**Approach**: Tracer Bullet. Prove one future dated ordinary purchase through both applications in sandbox, then add breadth and failure coverage before live enablement.

1. Add the source/settings, attempt/target, provider transaction/event records, payment source FK, permissions, casts, constraints, and safe setup validation needed for one ordinary purchase. Keep all live creation disabled; add migration and source isolation tests, satisfies **AC-2**, **AC-4**, **AC-7**, **AC-13**, **AC-14**, and **AC-15**.
2. Build one date server quote, retained terms, provider profile validation before insertion, durable queued initiation, provider adapter and verified callback, snapshot order activation, issued invoice, explicit new payment allocation, clearing entry, owned status using accepted captures, same tab website handoff, and encrypted after commit email intent. Use fake provider tests first, then approved sandbox evidence, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-5**, **AC-6**, **AC-7**, **AC-8**, **AC-11**, **AC-12**, and **AC-13**.
3. Extend the same path to several dates and all existing portion/bundle combinations; prove exact total reconciliation, no membership usage, same day review, midnight and after expiry completion, and immutable terms/prices. Preserve legacy callers and future booked wording, satisfies **AC-1**, **AC-3**, **AC-6**, **AC-8**, **AC-9**, **AC-10**, **AC-11**, and **AC-15**.
4. Complete unknown dispatch, lost response, duplicate captures, reversal exceptions, stable posting dates, finance locks, canonical merge ownership, notification markers, bounded recovery, purge, settings authorization, and correction replay tests, satisfies **AC-2**, **AC-4**, **AC-5**, **AC-8**, **AC-9**, **AC-10**, **AC-12**, **AC-13**, and **AC-14**.
5. Complete Account recovery, syntax only equivalent checkout recovery before mutable validation, submitted cart revisions, deliberate repeat acknowledgement, proxy errors, return page privacy, both base paths, accessibility, and analytics boundaries. Execute both repository regression suites, financial/concurrency matrix, and deployment failure drill before requesting live authorization, satisfies **AC-4**, **AC-11**, **AC-12**, **AC-13**, **AC-14**, and **AC-15**.

## Migration plan

**Strategy**: Additive schema and guarded cutover.

**Phases**:

1. Add only forward migrations. Existing payments retain null source links; do not infer SkipCash from historic card/online methods, phone, amount, or a report. Apply migrations only in an explicitly approved environment. No source account, customer, or financial backfill is implicit.
2. Deploy disabled RMS services, queue/recovery handlers, and additive API responses; deploy website proxies, payment page, and cart storage upgrade. Old carts retain selections but require a current quote and owner validation before new payment. Prove the complete tracer using disposable records and sandbox credentials.
3. Complete the identity/merge dependency and the separate payout, operations, and consistency readiness gates from scope. Before public cutover, ensure every membership path still advertised by the website has its intended working route. This slice does not authorize hiding those journeys or reverting them to unpaid submission.
4. Enable new checkout only after the release matrix and provider signature/URL checks pass. In the same controlled cutover, set `CUSTOMER_DIRECT_ORDER_ENABLED = false` and enforce that gate at RMS, including old website/proxy callers. Existing backoffice order creation is not disabled.

**Rollback**: Disable new initiation first, but leave verification, owned status/history, recovery, and financial completion running for existing attempts. Preserve all schema and collected payment records. Do not roll back populated financial migrations, reopen an unpaid customer route automatically, erase evidence, or stop the worker handling confirmed collections.

**Risks**: Website and RMS deployment order, workers running stale configuration, wrong currency/scale or company mapping, unsatisfied provider signature assumptions, and a browser restoring an old submitted cart. The tests and launch gates above address each explicitly.

## Consequences

**Positive**:

* One RMS record chain explains checkout, collection, orders, invoices, and clearing.
* Existing operations and accounting services remain recognizable to the single operator.
* Interrupted visits and delayed provider messages have a recovery path.

**Negative and tradeoffs**:

* Durable provider records, scheduler checks, and exception handling add engineering work before the first live payment.
* Unknown provider create outcomes may require merchant/provider investigation and cannot be made frictionless by retrying a possible charge.
* Email acceptance can be uncertain independently of a correct purchase and needs explicit delivery recovery.
* A payment verified after expiry still commits the original saved order and dates. The checkout timer is not an operational cancellation guarantee, and recovery must retain those snapshots even after releasing an unpaid target.
* Live launch depends on the separate settlement and customer journey work; a successful sandbox tracer alone is not release readiness.

**Neutral**:

* Audit history and provider evidence retention are mandatory.
* No refund, recurring charge, new delivery state, or membership redesign is included.
* Source fees and bank payout remain separate from gross customer receipts.

## Follow-up

* [x] Run the owner's chosen independent model cross check on this draft. The read only gpt-5.5 review completed on 2026-08-30 before the payment after expiry revision below.
* [x] Apply the four independent review fixes approved on 2026-08-31: required profile validation before attempt creation, encrypted notification recipients, syntax only equivalent checkout recovery, and public totals from fully verified matching captures.
* [x] Confirm the design on 2026-08-31 after the owner rejected the speculative extra payment email proposal and instructed proceeding. No separate email or planning blocker remains for that scenario. The specification stays `Proposed` because implementation has not started.
* [x] Link scope feature 2 and record its build milestones. Implementation, verification, tests, fresh code review, and release documentation remain unchecked.
* [ ] Obtain a provider confirmed signature vector and sandbox evidence for the exact required fields, URLs, return reference, details, and callbacks before live use.
* [ ] Confirm deployment company/branch, clearing account, bank/fee readiness, actor, support number, terms, and hosted checkout PCI DSS responsibilities through the existing release process.
* [ ] Complete separately scoped settlement, operations, consistency, membership, and promotion work before their corresponding public journeys are enabled.
