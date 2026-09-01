# 0010. Promotion validation and permanent redemption

**Date**: 2026-08-31
**Status**: Proposed
**Scope**: Feature 6. Design confirmed on 2026-09-01; not implemented.

## Summary

RMS checks a single membership code and calculates the discount. A positive balance follows paid membership checkout, while a zero balance creates only one pending meal plan request. Completed uses stay used after cancellation or customer merge, and returning members booking covered meals do not redeem a code again.

## Requirements

**User stories**:

* As a customer, I want the code to show my saving and final amount before I confirm.
* As the operator, I want limits and accounting to hold through payment delays, repeated submissions and customer merges.

**Acceptance criteria**:

* **AC-1**: Quote uses the server package price, one valid company code, eligible plan, effective dates, limits and completed membership history. Browser totals, first purchase claims, saved credit and multiple codes are rejected.
* **AC-2**: Percentage/fixed discount uses 0001 integer calculation and capping. Positive discounted packages retain their full meal allowance and exact daily gross/discount/net apportionment. Discount never creates credit.
* **AC-3**: Positive checkout atomically reserves capacity before provider initiation. Quote alone does not reserve. Verified on time successful completion converts one reservation to one permanent redemption alongside its paid block. Exact retry never consumes again.
* **AC-4**: Failure or proven unpaid/late payment releases its temporary promo hold without a completed use. Unknown provider outcome or absent finish proof retains it for recovery. Code expiry/pause after accepted hold does not revoke its snapshot.
* **AC-5**: Any valid zero net quote creates only one pending request, proposed choices, retained terms and permanent redemption. No provider call, attempt, receipt, subscription, block, allowance, order, booking, invoice, allocation or conversion is created by that endpoint.
* **AC-6**: Zero amount redemption is once per customer per code. Exact replay and later submissions, including after request closure/rejection, return the same owned request without changing choices or creating a new use. Invalid/exhausted/forged zero submissions create nothing.
* **AC-7**: First/renewal eligibility and per customer usage combine completed legacy and new membership history after approved merges. Cancelled completed purchases and previous discounts remain counted; past legitimate uses are honored even when merged totals exceed a limit.
* **AC-8**: Concurrent attempts cannot reuse first purchase eligibility or overbook code limits. Canonical owner and promotion locks, database uniqueness, scopes, active account and server phone policy hold in all paths.
* **AC-9**: Website distinguishes paid discounted purchase, zero amount request and covered booking, restores the correct result after reload, and never charges or grants allowance from a browser amount. Customer confirmation and staff request handling use existing mail/RMS workflows.
* **AC-10**: Diagnostics, cross application tests, cancellation/merge tests and safe release flags cover both positive and zero branches before promo enablement.

## Decision

**Chosen option**: Server quote and temporary positive checkout reservations, with immutable permanent redemptions. Use the existing meal plan request for zero results, separate from provider checkout and automatic membership conversion.

## Rationale

See [rationale.md](rationale.md).

## Feature design

### Data model

All IDs use existing FK types. Dates are UTC instants; cents and quantities are bounded integers. Restricted deletion preserves references. Original customer IDs are historical evidence and are not rewritten on merge.

| Record | Fields and constraints |
|---|---|
| membership_promotion_reservations | promotion_id, company_id, branch_id, original_customer_id, original_user_id, checkout_id unique FK; status held/redeemed/released; encrypted accepted offer/eligibility snapshot; gross_cents, discount_cents, net_cents; starts_at, expires_at equal checkout window; redeemed_at/released_at and bounded release reason; timestamps |
| membership_promotion_redemptions | promotion_id, company_id, branch_id, original_customer_id, original_user_id; kind paid_purchase/zero_request; checkout_id nullable unique, reservation_id nullable unique, meal_plan_request_id unique; purchase_block_id nullable unique; immutable offer/eligibility snapshot and gross/discount/net; redeemed_at; nullable zero_subject_key unique |
| meal_plan_requests | Reuse 0007 submission fields; add nullable client_uuid with unique user_id/client_uuid, nullable promotion_id and redemption_id FKs, encrypted proposed selections/terms and notification_snapshots, bounded notification_dispatch JSON. No associated operational orders on zero submission |
| Existing audit | Reservation/release/redemption and explicit manual conversion events, original owner, canonical owner at action, terms/quote hashes and operation references; no personal snapshot contents |

Index promotion/status/created_at and original_customer_id/promotion_id for reservation/history queries. Reservations are for positive attempts only. A paid redemption requires its reservation, checkout, converted request and paid block with net greater than zero. A zero redemption requires its request, net zero and no checkout/reservation/block; zero_subject_key is a deterministic hash of company, promotion and original canonical customer ID at first redemption. Application validation and schema checks enforce the kind shape supported by the current MySQL version.

The unique zero key prevents simultaneous first use for that original customer. After merge, queries consider all original IDs in the approved canonical closure before any new key can be created. Preserve previous keys and requests; two historical uses by previously separate customers remain history, not a uniqueness collision to delete. New zero submissions return the earliest retained request by redeemed_at then ID when merged history contains more than one. Foreign keys and ownership resolution still prevent access to unrelated customers.

Money snapshots use the full gross package price even for no proposed meals. Discount is not an amount added to payments or customer balance. Both kinds keep the exact plan and terms snapshot. Shared gross/discount/net fields are not current editable code values.

### Eligibility and usage

First purchase means no completed membership in the canonical customer's combined history. Completed paid blocks count regardless of current exhaustion or cancellation. Completed legacy conversions and manually established memberships count when supported by existing converted request/subscription/payment history, regardless of payment method. Ordinary orders, drafts, failed checkouts and unconverted free requests do not count. A later explicit valid manual conversion of a free request establishes a membership and then counts; its earlier submission alone did not.

Implement one MembershipPurchaseHistory resolver used by quote, acceptance, completion and diagnostics. Its source precedence is completed block, then explicit audited legacy conversion, then verified opening manifest evidence. Deduplicate a request/subscription represented in both new and legacy records. Never infer completed history just from a similar name, a proposed match or an arbitrary payment amount. Ambiguous legacy eligibility is reported to staff; a first only code cannot be promised without evidence, but ordinary purchase without that code remains available. Low identity match confidence alone does not enter this rule or block the owned customer's checkout.

For the code total, count every permanent redemption plus held positive reservations. For a customer's limit, count the same across canonical merged original IDs. Exclude the exact reservation being revalidated during its own completion; converting it changes held to redeemed atomically without briefly counting twice. Once completed, cancellation/void never deletes or releases the redemption. A merged customer exceeding a limit cannot use the code again, but previous discounts and active accepted holds remain honored.

First purchase intent serialization is the 0007 rule across codes and no code while no completed membership exists. It prevents two pending first purchases from each promising first eligibility. A pending free request is not a completed purchase or a positive first payment intent; it consumes its code use only. Renewals/both codes still obey total and customer limits on each additional purchase.

### Quote and acceptance

POST /api/customer/checkouts/quote remains the quote surface. It normalizes one promo_code, reads the default company plan, validates date/plan/eligibility/limit and returns gross, discount, payable, offer revision, terms snapshot, quote fingerprint and result_kind paid_membership or pending_request. It does not create a request, checkout or reservation. A malformed code, unsupported plan, expired/paused code or exhausted limit returns a translated 422 reason without exposing another customer's history. Rate limit promo quote by authenticated user and IP using the existing throttle infrastructure; recommended initial ceiling is 10 evaluations per minute per user and 30 per IP, configurable in code deployment settings.

At positive checkout creation, lock canonical customer, promotion, queue roots and relevant attempt through the shared context, recheck active offer and current terms, then create reservation and pending checkout/request atomically. Rule revision or payload changes return 409 for review; exact retry returns its saved attempt before current rule checks. No held use is created from the browser claiming net zero. Provider creation occurs only after committed acceptance and outside locks.

On verified payment completion, use the accepted snapshot, verified finish time and unchanged deadline. Do not require today's offer to remain active. Mark the reservation redeemed and create the unique permanent redemption in the same transaction as the 0007 receipt/conversion/block and any initial bookings. If accounting fails, keep the accepted payment fact and reservation without a partial redemption. If verified finished time is at or after expiry, record the real credit under 0007, release the hold and create no redemption or block. Unknown provider state cannot be released merely by timer expiry.

### Zero amount request path

POST /api/customer/membership-requests accepts client_uuid, quote fingerprint, plan_code, promo_code, proposed future choices and accepted terms version. Authenticated identity and branch policy match paid checkout. Exact UUID replay is checked first; changed payload with the same UUID conflicts. Then look up an existing zero redemption for that code across canonical history; a later UUID returns its original request and unchanged submission, even if the code has since expired or the request is closed. This replay grants no new benefit and does not revalidate an old submission against current prices.

For a genuinely new submission, lock customer then promotion, revalidate current quote/terms, limits and zero calculation, and insert request plus redemption and required audit atomically. Status is existing new, submission_kind promo_request. Store proposed choices and customer/terms snapshots encrypted, with no request/order pivot rows, no placeholders and no converted_subscription_id. Proposed choices may be empty and are not reservations; valid future choices use current menu rules, with today exclusions reviewed like other new selections. An expired proposal may later need staff adjustment before manual conversion, but the website does not silently convert it or promise a booked meal.

The result is result_kind pending_request, request reference and translated request confirmation text, not a payment status and not purchase_confirmed. Show No payment required. Request received; your membership is not active yet. The customer can see the request in account separately from paid allowance. Returning to Book remaining meals cannot use it as quota.

RMS meal plan request detail shows saved proposed choices, code, saving and terms. Existing authorized manual request handling remains; if an operator later deliberately converts it, that is a distinct audited action using the existing process, not background recovery of this endpoint. No zero receipt, synthetic payment or automatic positive funded block is invented for a free request. Its redemption remains used after closure, rejection, cancellation or manual conversion.

### API and actions

| Surface | Required inputs | Output and errors |
|---|---|---|
| POST /api/customer/checkouts/quote | Existing membership payload plus one optional promo_code | Server quote/result_kind; 422 invalid/ineligible/exhausted, 429 throttled |
| POST /api/customer/checkouts | Existing UUID/quote/terms with positive membership net | Same 0007 payment result; 409 changed offer or stale acceptance; no zero attempt |
| POST /api/customer/membership-requests | UUID, zero quote, code, plan, proposed choices, terms | New or existing pending request, replay flag; 409 changed UUID input, 422 nonzero/invalid quote, no financial effects |
| GET /api/customer/membership-requests/{reference} | Owned request reference | Submission kind, existing request status, proposed choices and request message; 404 outside scope, no candidate identity information |
| Existing RMS request actions | Existing request ID, authorized action and audited reason | Current manual workflow result; no promo use restoration or automatic funding |

The website PHP layer proxies these calls with the owning bearer token and preserves the error/result distinctions. Covered booking endpoints reject code fields and never call this service. Code removal before first acceptance requires a fresh quote; a started checkout cannot be changed in place.

### Notification, locking and recovery

Zero submission persists its encrypted customer/admin confirmation intents on the request and dispatches after commit. Reuse the bounded 0003 notification states and EmailLog behavior with request context, not a fake checkout. Known send failures can retry under the existing policy; possible acceptance becomes unknown. A missing recipient or exhausted send is visible through request context and 0006 diagnostics. Request success does not depend on email delivery; repeat submission cannot resend the original automatically.

Positive paths lock canonical customer before promotion and queue/finance. Promotion administration locks promotion only and never acquires a customer afterward. Merge locks involved customers first, combines history without rewriting original redemption identity, and preserves accepted snapshots. Customer, promotion, request, block and financial audit acceptance occur in one local transaction; provider/email activity never does. Paid completion cannot invalidate historical promo counts because it used another current portal login after an approved merge.

### Value sourcing

| Value | Source |
|---|---|
| Code normalization, active rule and plan | 0009 server rule, current membership_plans and server Qatar dates |
| First/renewal classification | Shared completed membership resolver across approved merge closure |
| Total/customer capacity | Permanent redemptions and protected reservations under locks |
| Gross/discount/net | 0001 exact cents calculation over full package; saved on acceptance |
| Hold deadline and paid eligibility | Existing checkout snapshot and verified provider finish evidence |
| Zero request identity | Existing user/client UUID or original redemption key, canonical combined history |
| Proposed choices, recipient and terms | Accepted server normalized payload, authenticated customer snapshot and current versioned terms |
| Customer result/status | Typed committed request or checkout, never browser total or local storage |
| Per meal amounts and quota | Paid block snapshot and 0001 position calculation; zero request supplies neither |

### Critical test scenarios

See [verify.md](verify.md). Both branches need permanent use, merge, replay, expiry and zero effect assertions, not only arithmetic tests (AC-1 through AC-10).

## Build plan

1. Add reservation/redemption schema, zero request fields and shared completed history/merge resolver (AC-1, AC-3, AC-5, AC-6, AC-7, AC-8).
2. Implement server quote, exact discount, capacity locking and positive checkout completion/release integration (AC-1, AC-2, AC-3, AC-4, AC-7, AC-8).
3. Implement request only zero branch, permanent replay, explicit manual handling boundary and durable request mail (AC-5, AC-6, AC-7, AC-8, AC-9).
4. Add website code entry/requote, positive versus pending result, account recovery and admin use projections (AC-1, AC-2, AC-6, AC-9).
5. Verify complete positive/zero/merge/cancel/concurrency matrix, 0006 rules, exact invoice sums and both applications before promo flag enablement (AC-1, AC-2, AC-3, AC-4, AC-5, AC-6, AC-7, AC-8, AC-9, AC-10).

## Consequences

Quotes are informative; only accepted holds or completed requests consume capacity. Free request redemption is permanent but creates no customer funds or entitlement. The same code cannot accidentally become a second purchase when the customer returns to choose covered meals.
