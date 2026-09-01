# 0006. Payment and membership consistency checks

**Date**: 2026-08-31
**Status**: Proposed
**Scope**: Feature 9. Design confirmed on 2026-09-01; not implemented.

## Summary

RMS checks that payments, memberships, bookings, promotions and payouts agree with their supporting records. Checks run in the background and report problems without delaying customers or changing their money. Staff use the existing authorized workflows to correct a problem, then run the check again.

## Requirements

**User stories**:

* As the operator, I want one traceable explanation of an inconsistency, including cases with no checkout.
* As a customer, I want correct purchases to complete immediately without waiting for a scan.

**Acceptance criteria**:

* **AC-1**: Relevant committed changes enqueue targeted checks. A sweep runs every 15 minutes and a full check starts daily at 02:00 Asia/Qatar. Runs resume safely and never block a customer request on check completion.
* **AC-2**: Enabled rules cover the matrix below, including missing parents and records with no checkout, historical balances, approved merges, and unsupported legacy funding.
* **AC-3**: Repeated findings update one current issue episode. Repeated runs create no payment, invoice, allocation, quota change, redemption, settlement or duplicate alert.
* **AC-4**: Only a complete successful evaluation of the affected rule and subject can resolve its finding. Partial, failed, stale or deferred reads cannot report health or silently clear issues.
* **AC-5**: Staff views and manual rechecks enforce active user, permission, company and branch scope. Unknown ownership is visible only to an authorized administrator, not every branch.
* **AC-6**: Corrected invoices, permanent promo use, released funding history, delayed confirmations, retained credit and successful merges are interpreted using their actual contracts, not simplistic equality with the original cart.
* **AC-7**: Run failures, stalled progress and disabled required rules are visible. Checkout issues use 0005 alerts without a second alert for the same underlying problem. Other financial inconsistencies have one administrator alert intent per episode.
* **AC-8**: Rules are enabled with their owning feature, tested on synthetic valid and invalid records, and required before that feature collects live money or grants new entitlement.

## Decision

**Chosen option**: Persist small diagnostic runs and findings, with domain rule adapters and the existing operations interface. Keep these records separate from accounting and from the console only IntegrityAudit command.

## Rationale

See [rationale.md](rationale.md).

## Feature design

### Data model

Use additive MySQL migrations, matching existing FK types, restricted deletion, UTC instants and integer cents. These records are diagnostics, not a new approval or financial workflow.

| Record | Fields and constraints |
|---|---|
| payment_consistency_runs | UUID reference; company_id; nullable branch_id; kind targeted/catchup/full/manual; state queued/running/completed/failed; requested_by nullable for system; bounded rule_code/rule_version map and registry hash; trigger key unique per company; nullable parent_run_id and not_before; typed target IDs for targeted/manual runs; captured upper boundaries and per table cursors as bounded JSON; heartbeat_at, started_at, completed_at; checked/deferred/open/resolved counts; sanitized error code; timestamps |
| payment_consistency_findings | company_id and branch_id nullable only when ownership cannot be established; rule_code and rule_version; subject_type from an allowlist and subject_id; unique subject_type + subject_id + rule_code; episode UUID; state open/resolved; first_seen_at, last_seen_at, resolved_at; last_run_id; nullable checkout_id; expected/observed nonpersonal IDs, quantities and cents as bounded JSON; evidence fingerprint; alert dispatch marker; timestamps |
| Existing accounting audit and EmailLog | Issue transitions, manual recheck actor, episode and run references, alert attempts and results. Preserve historical episodes here rather than an ever growing JSON array |

Index run state plus heartbeat and company plus created_at; index finding company/branch/state plus last_seen_at. Store no raw provider body, workbook, name, phone, email, address, token or hosted URL. Findings link to authorized source screens. A missing parent has its own typed subject ID, never a fabricated checkout. Retain findings and transition audits without automatic deletion; successful run summaries may be pruned after 90 days, preserving any run referenced by a finding or audit. Failed runs remain until reviewed and retention is explicitly configured.

### Rule matrix

| Rule family | Expected relationship and exclusions |
|---|---|
| Provider and checkout | Verified matching amount, currency, ownership and finish evidence; unique source/provider ID; one receipt per real capture; completion targets or legitimate retained credit. Browser return and elapsed timer are not paid or unpaid evidence |
| Ordinary accounting | Activated original targets, required orders, issued invoices, allocations, clearing receipt and balanced source postings; explain later authorized edits/voids through correction history rather than reopening completion |
| Membership purchase | Positive completed block has its own receipt, converted request, correct plan snapshot and queue; zero selections require no orders; late payment has credit and no automatic block or conversion |
| Queue and money | Opening quantities do not overlap mapped bookings; reserved plus invoiced positions are unique within each block; aggregate counters reconcile; saved allocations use the supplying block, not another advance; zero net meal slices still count |
| Sequence | Evaluate the free positions available at each accepted reservation using its audit sequence. A restored older position does not invalidate a previously valid later booking. New reservations must prefer the now available older position |
| Booking corrections | Voided linked invoice has no active allocation or active linked subscription order and funding is released once; edited invoice alone leaves quantities and positions unchanged; manual adjustments remain separately explainable |
| Cutoff, pause and dates | New selections exclude Qatar today; accepted changes precede their saved deadline; cutoff/profile snapshots are immutable; effective pause cancellation completes across orders, invoices, allocations and funding; unused allowance does not expire by date |
| Ownership and merge | Current mutable owners resolve to the surviving customer; original proof, checkout and redemption evidence stays historical; source login is inactive/revoked; queue and promo queries combine retained sources; scope remains consistent |
| Promotions | Completed paid use and zero amount request use remain permanent; holds do not exceed limits; zero request has no automated payment, allowance, order or invoice effects. Later explicit audited manual conversion is distinguishable from automatic free checkout conversion |
| Saved credit | Available money equals actual receipt less active allocations and active membership commitments; only an administrator can spend discretionary credit; normal block funding is not such a spend |
| Settlement | Unique reviewed economic rows and provider claims; gross minus commission minus fee equals net bank; correct company default bank snapshot and posting date evidence; customer receipt/credit unchanged by payout |
| Notifications and operations | Durable confirmation or issue intent, delivery outcome, no repeated original send; operational timers and run health. Email failure never reverses a purchase |

### Required rule registry

The registry below is part of the build contract, not a list for the implementer to complete later. Implement it as versioned PHP configuration beside the rule adapters. Each rule_code and rule_version is stored on runs and findings. A registry change increments that rule's version and schedules a full reevaluation of its eligible roots before old findings can resolve under the new definition.

Every fingerprint is the canonical sorted encoding of the named IDs and fields after decimal and timestamp normalization. Include a missing marker for every required absent row. Do not include display text, encrypted personal snapshots, raw provider bodies or mutable error messages. Expected and observed JSON uses the same allowlisted field names. A rule reads all named inputs in one consistent snapshot, then rechecks the fingerprint before saving its result.

The registry implementation cannot interpret `every` or a slash-separated group as permission for open-ended serialization. `Every` means the complete related collection, sorted by stable ID, using only the fields named in that row. A slash-separated group expands to each individually named field. The exact typed serializers and field-path arrays are stored beside each rule; the registry contract test compares them with these normative lists. The following ownership resolver is also mandatory for every rule:

| Rule code | Required ownership and scope resolver |
|---|---|
| provider_checkout_v1 | Attempt customer through 0002 canonical ownership, with the attempt's immutable company, branch, currency and original portal user proof |
| ordinary_accounting_v1 | Owning attempt/target resolver above; order, invoice, receipt and allocations must resolve to that same customer/company/branch/currency |
| membership_purchase_v1 | Request or block customer through 0002, then the exact 0007 queue company/branch/currency; the zero request uses its retained default-company scope |
| membership_balance_v1 and membership_sequence_v1 | 0007 logical queue resolver over canonical customer and only compatible retained roots; no incompatible branch or currency enters the subject |
| booking_correction_v1 and booking_policy_v1 | Subscription-order mapping to its 0008 owned logical queue and saved branch; the invoice/order must align to that result |
| customer_ownership_v1 | Full 0002 merge chain to the surviving customer; if no safe source scope exists, default-company Administrator visibility only |
| promotion_usage_v1 | Promotion's immutable company plus 0002 canonical customer; checkout/request/block scope must align, while promotion itself remains company-wide |
| saved_credit_v1 | Payment customer through 0002 plus the payment's company/branch/currency and every linked allocation/commitment in that same scope |
| settlement_v1 | Payment source and import owning company, configured provider-branch mapping and matched payment scopes; no report phone decides ownership |
| notification_operations_v1 | Allowlisted typed parent resolver; inherit its canonical customer when present and its company/branch visibility in all cases |

| Rule code and subject root | Required records and exact fingerprint fields | Expected value source | Deferral and resolution evidence |
|---|---|---|---|
| provider_checkout_v1, payment_checkout_attempts.id | Attempt: id, company_id, branch_id, customer_id, original portal user, payment_source_id, purpose, currency, gross/discount/payable cents, request/quote fingerprints, internal/create states, expires/completed times. Targets: id, sequence, type, expected cents, hold state, intended issue date, activated/released times and resulting IDs. Provider transactions: source/provider/merchant IDs, amount, currency, raw/normalized status, finish time/source, verified paid time and payment_id. Provider events: id, payload hash, normalized facts, signature key reference, processing state. Payment: id, customer/company/branch/source, method, currency, amount, received/void times | Immutable attempt/target snapshots plus 0001 provider normalization, verified evidence and resulting canonical payment | Defer pending/unknown evidence, an active provider detail recovery, or paid_processing still inside its owning recovery lease. Resolve only from verified terminal unpaid evidence, or canonical paid completion/retained credit with matching target and payment links. A later correction does not erase accepted paid evidence |
| ordinary_accounting_v1, payment_checkout_targets.id for type order | Target fields above; resulting order id/customer/branch/status/type and immutable item/date snapshot; invoice id/type/status/issue/void dates, total/balance cents, items and source order metadata; payment id/amount/source/method; every allocation id/payment/invoice/amount/date/void state; invoice and receipt journal source keys, account IDs, debit/credit cents and reversal links; related accounting audit action/subject/result IDs | Accepted target prices and issue intent, 0003 completion result, canonical AR totals/allocation status and balanced ledger source events | Defer only while the owning attempt is legitimately paid_processing inside its retry lease. Resolve through canonical completion, or an authorized invoice/payment correction whose void/reversal/allocation audit and ledger links fully explain the current result. A draft duplicate does not recreate the target |
| membership_purchase_v1, membership_purchase_blocks.id, or meal_plan_requests.id when no block is allowed | Request id/customer/user/plan/status/submission kind/checkout/conversion/promotion/redemption/subscription links and converted time; attempt/payment/provider facts from provider_checkout_v1; subscription id/customer/branch/mode/total/used/status/start/end/request link; block id/subscription/plan/payment/request/queue position/origin/money/meal count/currency/funded/cancelled fields and immutable pricing/terms/promo hashes; zero redemption kind/net/request links | Accepted membership quote and terms, verified on time payment for a positive block, or 0010 zero request contract | Defer positive paid_processing and unknown provider evidence. A positive result resolves only when receipt, conversion, subscription and block agree. A late result resolves only as real unallocated credit with no conversion/redemption/block. A zero result resolves only as one pending request/redemption with none of the forbidden financial or allowance records |
| membership_balance_v1, logical queue root reference | Every compatible subscription id/company/branch/currency/mode/status/total/used/revision; every block id/origin/queue position/meal/`opening_used_quantity`/`opening_released_quantity`/opening used and released ranges/cancel fields and gross/discount/net/payment; every active or released funding id/order/block/manifest evidence key/quantity/ranges/state/invoice/allocation/cents/deadline; active allocations and invoice states; legacy manifest/row/classification/source fingerprint/content hash/apply IDs; authorized queue correction audit IDs | 0001 counting/apportionment, 0007 verified opening and cancellation, and 0008 active funding states | Defer a root whose owning queue mutation has a live lease or whose verified legacy manifest is being applied. Resolve only through the canonical queue, invoice void, pause, cancellation or manifest workflow. Never clamp a mismatch or rewrite a counter from this rule |
| membership_sequence_v1, logical queue root reference | Block id, funded_at, stable id, queue position, cancelled state and all position ranges; booking operation id/accepted time/input queue revision; funding id/state/ranges/reserved/invoiced/released times; release audit/event id and accepted later booking attribution | Free positions and block order at each accepted operation, reconstructed from retained block/funding/audit history | Defer only a source graph changing during the read. Resolve a current premature attribution through the owning booking correction. Restoring an older position does not invalidate a later block used when no older position was free |
| booking_correction_v1, meal_subscription_orders.id | Mapping id/subscription/order/service date/branch/booking UUID/revision/superseded id; order id/status/customer/branch; invoice id/status/void/duplicate root and item metadata; each funding id/block/manifest row/evidence key/quantity/ranges/state/invoice/allocation/release time; block opening used/released quantities and ranges; each actual allocation amount/void state; queue revision; accepted booking/void/pause operation and audit IDs | 0008 saved funding attribution or exact 0007 closed-opening evidence, plus actual canonical AR void/allocation result | Defer an accepted operation within its active local transaction/retry lease. Resolve only when an active invoice has active funding and allowed allocations, or a voided invoice has a Cancelled linked order, released funding, voided actual allocations and one queue restoration event. An evidenced legacy opening void additionally requires one materialized released attribution and the matching opening range release. Invoice edit alone is a valid unchanged quantity state |
| booking_policy_v1, meal_subscription_orders.id or meal_subscription_pauses.id | Service date; booking accepted/changed/cancelled times and operation IDs; saved cutoff clock/timezone/deadline/profile snapshot hash; branch/menu quote fingerprint; mapping/order/invoice/funding states; pause start/end/resumed fields/status and pause operation audit | Qatar date and the booking's saved policy snapshot, plus 0008 pause and no same day rules | Defer only an accepted booking or pause operation within its recovery lease. Resolve a late customer mutation only through an authorized correction that retains the rejected attempt with no effects. Resolve pause mismatch only through the atomic pause/void/release/cancel workflow; later settings/profile edits are not corrections to old snapshots |
| customer_ownership_v1, canonical customers.id | Customer id/active/merged_into fields for the full chain; linked user id/customer/active status, token/session revocation markers; customer match review id/status/candidates; merge audit source/destination/actor/time; live customer_id on every record in the 0002 matrix; immutable original owner IDs on attempts/events/redemptions; company/branch/currency on affected finance/queue records | 0002 canonical resolver, destination login rule and ownership matrix | Defer an active canonical merge operation only. Resolve through the audited merge or different customer review outcome. Historical proof IDs remain valid; live rows on an inactive source and an active source login do not |
| promotion_usage_v1, membership_promotions.id plus canonical customer id when customer limited | Promotion id/company/code/status/revision/type/value/eligibility/dates/limits; eligible plan IDs; reservation id/customer/checkout/status/cents/expiry/release/redeem times; redemption id/customer/kind/request/checkout/block/cents/time/zero key; combined canonical customer source IDs and completed membership evidence; request/block cancellation and conversion audit IDs | 0009 immutable activated rule plus 0010 accepted snapshot, completed history resolver and permanent use semantics | Defer protected positive holds with unknown provider evidence. Resolve excess/missing use only through the canonical reservation/redemption or merge workflow. Cancellation, expiry, request closure and invoice void never remove a completed use. An explicit later manual conversion is valid only with its separate audit |
| saved_credit_v1, payments.id | Payment id/customer/company/branch/currency/source/method/amount/void state; all allocation ids/invoice/amount/date/void state; every active membership block payment id/cancelled state and unconsumed committed gross/discount/net; funding ranges/states; discretionary allocation audit actor/action/UUID; affected invoice balance/period | Actual receipt less active allocations and active membership commitments, using 0005 administrator authority and 0007 block funding | Defer a live canonical allocation/queue transaction only. Resolve through the authorized allocation, invoice void, membership cancellation or payment correction. A checker never changes money. Promotional discount is never observed credit |
| settlement_v1, payment source plus payout reference | All import/row ids across files, file/content/economic hashes, row types/references/match/conflict states and exact gross/commission/fee/net/date values; reviewed batch snapshot/hash; provider transaction/payment/claim IDs; settlement/adjustment ids/status/account/date/gross/deduction/net/reversal fields; bank transaction/statement reservation/reconciliation IDs and amounts; source clearing/default bank/expense account snapshots | 0004 cross import payout batch, reviewed evidence, stable economic identities and canonical clearing/bank entries | Staged/review work is valid and not deferred. Defer a claimed posting/void transaction within its lease. Resolve posted mismatch only through 0004's authorized void/correction evidence. Voided identities remain correction required, not ordinary unsettled candidates |
| notification_operations_v1, typed parent id | Parent type/id/company/branch/customer and committed outcome; immutable encrypted snapshot presence/hash; each dispatch/issue operation UUID, kind, state, claim time, attempts, next time, successful EmailLog id, sanitized error and suppression/resolution times; matching EmailLog parent context/result; checkout issue episode or typed finding episode; scheduler/worker heartbeat | Committed purchase/request/booking outcome and 0003/0005 notification state machine, retry budget and recipient resolver | Defer pending work before next action time or an unexpired claim. Resolve through sent/suppressed/known final delivery evidence or the one correctly open issue episode. Email failure never changes its parent purchase, request or booking |

For missing parent checks, the existing child supplies the typed root ID and any company/branch fields it owns. If no safe scope can be established, expose the finding only to the default company Administrator until an authorized correction establishes ownership. Each adapter implements every field above or remains disabled/Unhealthy; implementation cannot silently reduce the fingerprint. Rules compare opening and current attribution with immutable accepted snapshots plus authorized correction events. Do not declare intentional original owner references after merge, unposted report staging, a pending free request, an unused paid allowance, or a known invoice correction to be corrupt.

### Scheduling and consistency

After commit, enqueue affected root IDs using existing queues. Targeted dispatch is an optimization, not the only detection path. The 15 minute sweep walks changed checkouts, provider records, orders, requests, subscriptions, funding, invoices, allocations, payments, promotion records, customers/users/merge audits and settlement records. Its per table cursor is (updated_at, id), with a five minute overlap; immutable tables use (created_at, id). Child changes resolve all affected roots. A table without a reliable timestamp uses ID plus the owning audit change stream, not an assumed timestamp. Nightly scans include all relevant retained roots and reverse orphan queries, not just recent transactions.

Capture each run's upper timestamp and ID boundaries; keyset batches contain at most 100 roots. Persist cursor and results after a successful batch. A unique company/kind/scheduled slot trigger deduplicates dispatch; a single running full scan per company resumes rather than starting another at midnight. Overlap prevents routine gaps; the full scan catches old timestamp changes and lost dispatches. Do not claim immediate detection of every out of band database edit.

Read each connected subject graph in a short consistent database snapshot without write locks on financial rows. Include allocation, funding and correction children. If the graph is incomplete because a dependency is still legitimately processing, record deferred with its reason and due time, not corruption. If a relevant revision changes during evaluation or before persisting its diagnostic result, defer and retry. Persist findings under the diagnostic parent lock; do not hold that lock across provider calls. Scans make no provider calls; recovery owns evidence retrieval.

Only evaluated rules can resolve findings. A missing required table, exception or incomplete scan fails its rule/run, never counts as an empty healthy result. A feature not yet deployed is Not applicable; an enabled feature missing its rule is Unhealthy. A completed full scan with deferred subjects shows Incomplete coverage plus their count rather than All healthy.

Persist deferred subjects as queued targeted runs, at most 100 target IDs each, with parent_run_id, reason and not_before. The trigger key includes source/rule and the next 15 minute slot so repeat dispatch shares that retry rather than losing it in a count. Source stability uses a canonical fingerprint of the relevant parent and child IDs, states, quantities, cents, versions and correction references; adapters name those fields explicitly. Recheck the fingerprint outside the original read snapshot before recording a result. A changed graph is retried, not resolved as healthy.

### Operations and actions

| Surface | Inputs | Output and authorization |
|---|---|---|
| GET /receivables/payments/consistency | Nonpersonal status/rule/date filters and page | Scoped findings and latest run health, 15 rows per page; payments.support.view |
| GET /receivables/payments/consistency/{finding} | Finding reference | Authorized source references, differences and episode history; same permission and resource scope |
| Volt recheckFinding | Finding ID, operation UUID | Existing or queued targeted run; active administrator with payments.consistency.run and source scope; 409 changed/replayed input, 404 outside scope |
| payments:check-consistency | --mode=catchup/full, optional configured company | Scheduled bounded run, sanitized exit result; system context, no arbitrary SQL or repair flag |

Use root scoped authorization before list totals, search and source links. Manual runs have a UUID trigger key and immutable fingerprint. No Repair all, force paid, merge, credit allocation, cancellation or ledger rewrite action is added.

Reuse 0005 issue ownership: a finding corresponding to an existing checkout processing/evidence/delivery issue references that episode and never sends its own alert. Other confirmed financial findings create one durable administrator alert intent immediately. Known nonacceptance retries, uncertain delivery handling, recipient resolution and audit are exactly 0005. Delivery failure is visible but does not spawn another alert. A transient deferred subject has no inconsistency alert. Failed/stalled run health creates one system issue episode per company/check kind, resolved only by subsequent successful progress.

Use a two minute heartbeat for queued batch progress; a running run with no heartbeat for ten minutes is stalled, eligible for resumption. Catchup is stale after 30 minutes without successful completion; full scan is stale after 26 hours without completion. These technical thresholds do not change the approved payment recovery and alert clocks. Expose never run, disabled, running, failed, stale and last successful time separately.

### Value sourcing

| Value | Source |
|---|---|
| Subject owner and permitted readers | Canonical customer resolver, source company/branch and actor permissions |
| Expected quantities and financial values | The expected source named for the applicable required rule registry row |
| Observed values | The required records and exact allowlisted fingerprint fields for that rule row, never browser or cached dashboard totals |
| Finding identity, episode and changed evidence | Rule code/version plus typed subject key, locked existing finding and canonical fingerprint defined above |
| Schedules and stale indicators | Server UTC clock, Asia/Qatar scheduler configuration and persisted progress |
| Alert recipient and status | Existing company aware admin resolver and durable 0005 dispatch/EmailLog conventions |
| Display text and recovery links | Allowlisted translated rule messages and authorized route registry |

### Critical test scenarios

See [verify.md](verify.md). Cover every rule with a valid fixture, a single broken relationship, repeated evaluation and its authorized correction (AC-2, AC-3, AC-6). Prove resumed scans, incomplete coverage, no financial writes and isolation (AC-1, AC-4, AC-5, AC-7, AC-8).

## Build plan

1. Add diagnostic schema, scoped query services and the complete versioned rule registry above, with contract tests that reject missing fingerprint readers; keep finance mutation services out of checker dependencies (AC-2, AC-3, AC-4, AC-5, AC-8).
2. Deliver ordinary payment, ownership, credit and settlement rules with targeted dispatch, catchup and resumable full scans (AC-1, AC-2, AC-4, AC-6).
3. Add scoped diagnostics UI, manual recheck, deduplicated operations alerts and run health (AC-3, AC-4, AC-5, AC-7).
4. Add membership, booking and promotion adapters in their owning feature milestones before each enablement (AC-2, AC-6, AC-8).
5. Verify failure/restart safety, synthetic rule matrix, operational schedule and release runbook (AC-1, AC-2, AC-3, AC-4, AC-5, AC-6, AC-7, AC-8).

## Consequences

Findings explain inconsistencies without becoming another accounting system. Detection is eventual, not a reason to postpone a correct purchase. Operators still use existing authorized workflows; external provider uncertainty and unresolved legacy evidence cannot be repaired by a checker.
