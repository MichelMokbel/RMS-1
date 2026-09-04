# 0005. Payment exceptions and operations

**Date**: 2026-08-31
**Status**: In Progress

## Summary

You manage SkipCash checkouts from a tab inside Customer Payments, with unresolved problems shown first. RMS keeps trying safe recovery and sends one administrator alert when intervention is needed. Staff can retry processing or resend the saved order confirmation without charging again or recreating financial records.

The owner approved this build specification for scope feature 4 on 2026-08-31 and chose to skip the independent design check. Its status remains `Proposed` because implementation has not started. The five build milestones are linked in the [scope](../../scope/scope.md). Nothing here is implemented or enabled by design approval. The shared financial, identity, checkout, and settlement contracts remain in [0001](../0001-payment-accounting-contract/index.md), [0002](../0002-customer-matching-signup/index.md), [0003](../0003-skipcash-paid-order/index.md), and [0004](../0004-skipcash-settlement/index.md).

## Requirements

**User stories**:

* As the administrator, I want to see payments that need my attention without checking every successful order.
* As authorized support staff, I want to find a checkout by customer, phone, or payment reference and explain what happened even when no RMS receipt exists yet.
* As the administrator, I want safe recovery and confirmation email actions that do not charge again, alter posted history, or spend committed membership funds.

**Acceptance criteria**:

* **AC-1**: Customer Payments has a SkipCash tab with Needs attention as its default and an All checkouts view. Search covers customer, phone, and payment reference. Attempts without a payment remain visible. Ordinary successful payments require no staff action.
* **AC-2**: Retry processing uses existing verified recovery and completion services. It never initiates a charge, manually marks a payment paid, changes retained posting dates, bypasses finance locks, or recreates completed or subsequently corrected records. Concurrent actions and exact retries have one effective result.
* **AC-3**: An issue requiring manual intervention becomes eligible for an administrator alert immediately. A verified payment with unfinished RMS processing becomes eligible after 15 minutes from RMS first recording that unresolved processing issue. Ordinary declines, abandoned checkouts, and temporary problems resolved before eligibility generate no alert. Recovery continues where safe.
* **AC-4**: Each unresolved issue has one administrator alert intent with a reference, sanitized reason, and authenticated RMS link. Repeated checks do not produce repeated reminders. The issue remains visible if mail fails or delivery is uncertain, and payment status is unaffected.
* **AC-5**: Authorized staff can deliberately resend an eligible saved customer order confirmation. The action sends the retained recipient and order/payment details, records actor and delivery outcome, and prevents accidental duplicate submissions. It does not charge, create or change orders, invoices, payments, or allocations. Uncertain original delivery is disclosed before a deliberate resend.
* **AC-6**: Dedicated server permissions separate inspection, recovery, confirmation resend, settings, and saved credit allocation. Initial access belongs to Administrator. Company, branch, and customer boundaries apply to reads, totals, linked history, evidence, and actions. Customer portal users cannot access these staff tools.
* **AC-7**: Issue and dispatch tracking stays on the approved checkout records. Existing accounting audit and email history record actions and outcomes. Distinct simultaneous issues remain visible, and resolving one does not clear another. No ticket module or second financial ledger is introduced.
* **AC-8**: Authorized staff can edit the company payment settings in RMS with audit. Duration accepts 5 through 60 whole minutes, cutoff is a valid Qatar clock minute, timezone stays Asia/Qatar, and the support contact is configured there. Changes apply only to later attempts or bookings. Credentials are not editable in this screen.
* **AC-9**: Only an administrator in RMS can allocate available saved credit through the existing AR workflow. Other staff and customers cannot. Funds committed to remaining membership positions are not discretionary credit. Scope, currency, invoice balance, periods, and concurrent allocation checks remain enforced; normal automatic allocation from the payment funding the purchase or meal remains intact.
* **AC-10**: Views distinguish provider payment evidence, RMS completion, current invoice state, email delivery, and settlement. They preserve the public states pending, paid_processing, completed, and declined. A pending 100 percent promotion request is not a paid purchase or collected gateway payment. Cancellation preserves unused credit under the existing contract; refund, reversal, or dispute evidence is a review issue, not an automatic balance mutation or refund action.
* **AC-11**: Customer merge uses current canonical ownership while retaining original checkout identity, provider evidence, financial dates, and notification snapshots. Sensitive evidence stays private, access is audited, and raw provider bodies follow the approved 90 day purge without deleting financial or audit history.
* **AC-12**: Existing scheduled recovery remains bounded, retryable, and active when new checkout is disabled. Needs attention and operational health remain available during shutdown. Basic payment and credit checks, scheduler/queue failure visibility, and both applications' release checks are proved before live collection; broader membership consistency rules remain in their separate scope feature.

## Decision

**Chosen option**: Extend Customer Payments with an attempt based operations view and small tracking fields on the approved checkout records.

Reuse Laravel, Volt, Flux, MySQL, existing queues and mail, the verified completion service, and the canonical AR writers. Keep settlement in AR clearing and saved credit allocation in the existing payment detail workflow. (basis: `AGENTS.md`, scope feature 4, 0001 through 0004, Customer Payments, and the existing audit and email services)

The record relationships and business rules are carried forward. Field layout, action permissions, dispatch claims (exclusive worker ownership), and endpoint details below are implementation recommendations, not new customer policies.

## Rationale

Reasoning, alternatives, source observations, and references: see [rationale.md](rationale.md).

## Feature design

### Boundaries and prerequisites

0003 must supply durable attempts, provider evidence, accepted payment facts, completion intent, notification snapshots, and verified recovery before these tools can act. Those records are planned, not current runtime tables. An operations view is not an alternative payment verification path.

The first usable slice covers ordinary paid orders. Later membership slices supply their approved completion and funding projections to the same view. Until those adapters exist, do not infer a membership balance or enable recovery for an unsupported purchase type. Pending free meal plan requests stay in the existing request workflow and have no fabricated checkout or payment row. Where shown alongside purchase history later, label and count them separately.

0004 owns report matches, fee explanations, settlement posting/void, and bank reconciliation. Link to its authorized records without copying its controls or changing a customer's paid state because a payout needs review. Feature 9 owns broader consistency sweeps; this slice supplies an issue reporting interface and basic receipt/allocation/link checks, not the future meal, promotion, or booking rules.

### Data model

Keep all keys, foreign keys, unique provider identities, money fields, and financial links from 0001 and 0003. Use their exact integer QAR cents and UTC `datetime(6)` conventions. An operation UUID is a server validated lowercase UUID identifying one deliberate request; it is not a provider payment ID.

| Record | Existing relationships | This slice's fields and constraints |
|---|---|---|
| `payment_checkout_attempts` | Existing primary key and company, branch, current customer, original portal user, source, provider, and target relationships | Add nullable `operations_tracking` JSON and `operations_next_action_at` timestamp. Tracking holds bounded current issue slots and current recovery/resend dispatch markers, with IDs, codes, counts, and times only. Index `(operations_next_action_at, id)` for the due scheduler scan. Lock the attempt for every tracking mutation |
| `payment_checkout_attempts.notification_snapshots` | Existing encrypted content and recipient snapshots | Retain original customer/admin confirmation content. Add an encrypted administrator issue alert snapshot for each current issue episode. Resends reference the original customer snapshot rather than a new recipient or current cart |
| `payment_checkout_attempts.notification_dispatch` | Existing original confirmation slots from 0003 | Preserve their meaning and history. Deliberate resends and issue alerts have distinct dispatch markers in operations tracking, never reset an original sent slot |
| `accounting_audit_logs` | Existing `company_id`, `actor_id`, polymorphic `subject_type` and `subject_id` point to the checkout or existing financial subject | Append issue transitions, accepted action UUID and input fingerprint, processing outcome, before/after codes, related IDs, and timestamps. One checkout has many entries. No new history table or recipient content in audit payloads |
| `email_logs` | Existing order/request/user fields and `context` | Add checkout ID, issue episode or operation UUID, and notification kind to context. Retain each attempt/result through this service; successful dispatch markers retain its log ID. Access is through the authorized checkout, not an arbitrary email log ID |
| `payment_settings` | Existing planned unique company row from 0001 | No new business settings or ownership model. Use `checkout_duration_minutes`, `booking_cutoff_time`, `timezone`, `order_support_phone`, and existing actor/timestamps |

Current issue slots are `processing`, `provider_evidence`, `customer_confirmation`, and `admin_confirmation`. Each present slot has an `episode_uuid`, bounded `reason_code`, `first_seen_at`, `last_seen_at`, nullable `resolved_at`, `attention_at`, and alert dispatch markers. A slot's changing technical error does not reset its first seen time or start a new episode while the same underlying issue remains unresolved. A genuine recurrence after resolution starts another episode; history remains in audit, not an ever growing JSON array.

Dispatch markers contain `operation_uuid` or `episode_uuid`, kind, state, claim UUID/time, attempt count, next attempt time, nullable successful email log ID, and sanitized error code. Recovery also retains the selected existing provider transaction/event IDs and initiating actor ID. Store no email address, phone, notes, raw exception, pay URL, or provider body in these markers. `operations_next_action_at` is the earliest due local tracking or dispatch work, not a payment expiry or public status.

Use the locked parent plus immutable audit acceptance records to replay an action UUID. Within one checkout, an accepted UUID has one action kind and input fingerprint. A repeat returns its prior operation and current outcome; changed input returns conflict. Do not rely on an unconstrained audit JSON search without first acquiring that parent lock. Keep only active dispatch pointers on the checkout, with completed action identities/results in audit. Existing provider and financial database constraints remain the final defense against duplicate effects.

### Issue lifecycle and alert timing

The issue lifecycle is `absent → open → resolved`. An open issue becomes visible in Needs attention when its `attention_at` is reached. A resolved slot can later start a new episode. Alert delivery and payment processing have independent states.

Operations email dispatch uses the existing `pending → sending → sent / failed / unknown` meanings from 0003. Known failed sends can return to pending within the retry budget; an alert resolved before sending is recorded as `suppressed`, a terminal operations outcome. Recovery actions use `queued → running → succeeded / blocked / failed`; those outcomes describe that action, not the customer payment. A failed action does not stop the independently scheduled verified recovery. Terminal action outcomes remain in audit after the current pointer is replaced.

| Observed condition | Attention and alert eligibility | Resolution |
|---|---|---|
| Verified payment, RMS completion temporarily incomplete | First recorded processing issue plus 15 minutes | Canonical completion succeeds with the required linked records |
| Definite manual obstacle, such as locked required period or missing required account | Immediately at detection, including escalation of an existing processing episode | The owning workflow completes its required processing; clearing a configuration obstacle alone must not resolve and restart the same incomplete processing episode |
| Unknown provider create outcome without an ID, conflicting verified evidence, unexpected reversal/refund/dispute evidence | Immediately once existing 0003 verification classifies the retained evidence as requiring intervention | New verified evidence or the existing authorized resolution workflow resolves the reason; no automatic refund or forced paid flag |
| Customer/admin confirmation delivery unknown, retries exhausted, or recipient missing | Immediately when classified as requiring intervention; ordinary scheduled mail retries do not alert | A valid delivery outcome or successful deliberate resend resolves the corresponding delivery issue; original unknown/failed send evidence remains |
| Declined or abandoned checkout with no verified paid conflict | Not an operations issue | No staff action required |

The processing clock starts when RMS records accepted paid evidence but cannot complete its records. It does not start at the browser return, provider finish time, checkout expiry, or an arbitrary queue retry. Set it in the same durable handling path that records the unresolved processing outcome. If a processing issue escalates, keep its episode and first seen time; move its eligibility earlier. Once an alert intent exists, further error changes in that episode do not create another alert.

The scheduler evaluates due issue timers independently of the provider GET backoff. A 15 minute alert cannot wait for an hourly provider poll. Recheck the underlying result under lock before making the intent, and again before claiming unsent mail; suppress an alert already resolved before sending. Persist the alert intent and recipient/content snapshot before queue dispatch, then send outside the transaction. Ordinary successful processing before eligibility leaves an audited resolved episode with no email.

Resolve administrator recipients through the existing configured admin recipient resolver, validated for the source owning company, and snapshot them at alert creation. This release's default company uses that configured list; do not send another company's details to it by fallback. Missing or invalid recipients leave the alert visibly undeliverable. Configuration repair can supply a snapshot only for an intent that never acquired valid recipients or began a send. No new recipient editor is included.

One alert intent is not a guarantee of exactly one delivered SMTP message. Reuse the known nonacceptance retry and uncertain delivery rules from 0003: delays of 1, 5, 15, and 60 minutes, at most five total sends, no blind retry after possible acceptance. A claim older than the 60 second mail worker timeout plus 60 second safety margin is unknown. An alert's own delivery failure does not create another alert issue. Needs attention is the source of truth even if no email arrives.

### Staff interface and projections

Add named routes `receivables.payments.skipcash.index` and `receivables.payments.skipcash.show` under the Customer Payments layout. Register the literal SkipCash route before the existing `{payment}` route. Preserve the current receipt list, route names, query behavior, and finance permissions. A staff member with only support inspection permission can access the SkipCash tab, not the existing receipt editing screens.

Needs attention shows one checkout row with its outstanding issue summaries. All checkouts includes pending, completed, declined, internally expired, and problematic attempts without interpreting expiry as lost credit. Use 15 rows per page, stable `(created_at, id)` ordering, retained filters, and separate paginated activity history. Filters are view, query, internal result, issue kind, and creation date range. Apply permitted company/branch restrictions before search, counts, totals, or pagination. Normalize current customer phone search with existing customer rules; provider evidence phone is not an ownership or matching shortcut.

Keep personal search text in the authenticated Livewire component state, not a query string or analytics event. Only nonpersonal view/date filters may be retained in the URL. Search requests and their diagnostic context must redact the query value; reset the component on account or scope change.

Detail shows current customer, reference, purpose, source/method, quoted payable, accepted provider paid amount, posted receipt amount, allocation and remaining amounts, current linked invoice state, service dates, processing reason, last/next recovery, confirmation delivery, and permitted settlement links. Missing receipt is displayed as Not recorded, not zero paid. Derive each amount from its owning records; no stored operations balance. Show membership committed versus discretionary funds only from its approved funding adapter, never from package price guesses. Unsupported or inconsistent funding is Unavailable for allocation, not free credit.

Current invoice edits or voids are displayed without changing the original checkout result or replaying completion. A void is handled by the canonical invoice workflow from 0001, including subscription effects when that slice exists. Keep the four public states unchanged; internal issue and email labels are staff information. Mask sensitive data in ordinary lists. All controls have accessible labels, loading/disabled states, keyboard access, dark mode contrast, and a usable layout at 360, 768, and 1024 pixels.

### Action contract

Use focused operations query/action services called by Volt. They accept authenticated actor context, never a client supplied company, actor, paid flag, accounting date, clearing account, or payment method. A recovery request schedules work; it does not assert success.

| Surface | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/receivables/payments/skipcash` | GET | Nonpersonal view/filter strings, page integer, optional authorized branch filter | Scoped rows, counts, pagination, health summary | Active backoffice session and `payments.support.view` | 403 forbidden, 422 invalid filter |
| Volt `searchCheckouts(query, filters, page)` | POST through Livewire | Query string kept out of URLs, validated filters and page | Same scoped list/count/pagination projection | Same inspection permission and scope | 403 forbidden, 422 invalid filter |
| `/receivables/payments/skipcash/{checkout}` | GET | Owned checkout reference, activity page | Detail, eligible actions with reasons, scoped history and links | Same inspection permission and resource scope | 404 absent or outside scope, 403 forbidden |
| Volt `retryProcessing(checkout, operationUuid)` | POST through Livewire | Required checkout reference and UUID | Operation UUID, queued/already running/already complete result, current processing summary | Inspection plus `payments.support.recover` | 409 no safe retry or UUID conflict, 403 forbidden |
| Volt `resendConfirmation(checkout, operationUuid, snapshotHash, acknowledgeUnknown)` | POST through Livewire | Required checkout/UUID/hash; explicit acknowledgement when original delivery is unknown | Operation UUID, queued or existing send result | Inspection plus `payments.support.resend` | 409 ineligible/stale snapshot/active send, 422 missing acknowledgement |
| Volt `inspectEvidence(checkout, eventId)` | POST through Livewire | Checkout and its linked event ID | Restricted evidence view or retained normalized evidence with raw body purged label; audit reference | Inspection plus source and branch scope | 404 unrelated event, 403 denied, 503 audit unavailable |
| `/api/accounting/payment-settings` | GET / PUT | Existing company context; PUT duration, cutoff, support phone, expected settings version | Settings; PUT audit reference and new version | Existing internal authentication and `payments.settings.manage` | 403 denied, 409 scope/version conflict, 422 unsafe input |
| Existing RMS payment detail saved credit action | POST through Livewire | Existing payment and invoice allocation rows; stable operation UUID | Canonical payment/allocations, refreshed balances, audit reference | Administrator and `payments.credit.allocate`, plus finance and resource scope | 403 denied, 409 stale/conflicting operation, 422 insufficient eligible credit or period/ownership failure |

No new customer endpoint is needed. Keep settings request fields from 0001 and return a version hash of their persisted values plus `updated_at`. Compare that hash under the settings row lock before saving, rejecting a stale edit without depending on timestamp precision. No new version column or change to active snapshots is needed. The settings form uses this same service. No arbitrary raw event search or direct email log download is exposed here.

For recovery, lock the attempt, recheck access and supported state, persist action acceptance/audit and an intent, then dispatch after commit. If another recovery is active, return that operation. The worker invokes the 0003 handler for existing evidence or a GET for a known provider ID. This staff action cannot call payment creation, even for a `not_sent` attempt. Background initiation already authorized by checkout stays governed by 0003. Unknown IDs or unresolved semantic conflicts return the recorded reason rather than starting a blind provider request. Staff action identity is retained as `requested_by`; provider initiated financial posting retains the configured system actor.

For resend, require an original committed customer confirmation snapshot and completed paid ordinary purchase, with no current voided or unpaid linked invoice that would make that confirmation misleading. Show current invoice state and explain ineligibility without editing the saved email. A deliberate resend after `sent`, exhausted known failure, or `unknown` is allowed; an original or deliberate send currently claimed or awaiting ordinary retries must finish first. The UI creates one request UUID per deliberate click and retains it through retries. Validate the server supplied snapshot hash and unknown delivery acknowledgement. A worker claims the distinct resend intent, rechecks actor access and eligibility, sends the same saved content/recipient, and writes EmailLog/audit results. A later deliberate click can create another operation; an exact replay cannot. Do not silently add free request, admin confirmation, or arbitrary recipient resends.

Both actions use the parent lock to serialize acceptance and claim changes, but do not hold database locks during network calls. A lost queue message is recovered from the saved intent. Recovery workers and manual requests share the existing claims so they cannot complete the same payment concurrently. A revoked staff action permission prevents an unstarted manual action; independent already authorized automatic recovery still proceeds. Every action records acceptance and outcome. If required audit persistence is unavailable, reject acceptance before any mutation or external send; do not rely on the existing audit service's silent missing table fallback.

### Saved credit and financial boundaries

Keep the existing payment detail workflow. Trace every discretionary existing credit allocation writer, including internal APIs and direct service callers, and route them through one administrator guard. Do not treat a `finance.write` check, a hidden button, or a client supplied allocation purpose as authority. The trusted automatic path must derive its exact payment and target from the verified purchase or supplying membership block, not bypass the guard for arbitrary invoices.

Under the owning AR transaction and established lock order, validate actor, current customer, company, branch, currency, active payment, invoice state/balance, preserved allocation date, periods, and eligible available funds. Lock payment, relevant funding and active allocation records, and target invoices in deterministic order. Eligible discretionary funds are receipt amount less active allocations and still committed membership funding, never a negative amount. Inconsistent funding blocks allocation for review. Do not turn a restored membership reservation into freely spendable credit while its funding is still committed.

Use a stable operation UUID and immutable accepted request fingerprint on the existing payment audit subject for deliberate saved credit use, serialized by its payment lock. Reject duplicate invoice IDs and invalid amounts before calling existing allocation writers. Retain the canonical AR workflow's accepted allocation date with that operation rather than choosing a new date on retry. An exact completed retry returns its saved result even after a later correction; it must not reapply a voided allocation. The existing `paymentClientUuid` identifies a receipt and is not a substitute for this action identity. Ledger, audit, allocations, and balance changes commit together, or all roll back.

This feature does not expire credit, create refunds, allocate credit at checkout, or change recognition policy. Ordinary invoices and membership daily invoices retain the approved accounting. Settlement commissions and fees never reduce the customer's gross receipt or membership allowance. No UI setting can bypass locks or move a historical posting date.

### Security and value sourcing

Seed `payments.support.view`, `payments.support.recover`, `payments.support.resend`, `payments.settings.manage`, and `payments.credit.allocate` for Administrator, using existing Spatie conventions. Inspection alone grants no mutation. Saved credit additionally requires the actual administrator role, even if a nonadmin is mistakenly granted that permission. Existing settlement and promotion permissions remain separate. Apply active user, backoffice identity, CSRF, and company/branch checks at routes and action services, including Livewire calls made without the visible button.

Limit recovery and resend acceptance to 5 requests per minute per actor and checkout, and inspection/search to 60 requests per minute per actor. An exact replay is still authenticated and scoped. Recheck current canonical customer ownership and source scope after merge; preserve original portal identity, provider IDs, financial dates, and email snapshots. Original recipients do not become editable or switch to a destination email merely because a merge occurred.

Raw evidence is sensitive and may contain untrusted provider text. Require audited, deliberate inspection, escape content, mask credentials and card/token fields, and never use it as instructions. Keep raw bodies encrypted and subject to 0003 purge. Email history contains personal recipients already, so expose it only through the authorized parent and minimize displayed fields. No phone search value, recipient, raw exception, provider body, secret, or pay URL enters logs, queue payloads, metrics, or audit context. Queue jobs carry IDs only. The hosted payment/PCI boundary in 0001 remains a deployment requirement, not a compliance certification here.

| Action | Value produced or displayed | Source |
|---|---|---|
| List, search, count | Permitted company/branches, current customer/name/phone, checkout reference, purpose, creation date | Authenticated backoffice actor and existing branch/company policy; canonical customer relation and attempt columns from 0003 |
| List, search, count | Payment reference, internal result, issue labels, attention count, pagination | Attempt public reference, linked provider IDs/merchant reference and receipt reference; saved result and current issue slots; scoped query with 15 row limit |
| Checkout detail | Currency, payable, verified paid, recorded amount, source and method | Immutable attempt cents/currency; accepted provider transactions with `verified_paid_at`; linked AR payment; retained source and method `skipcash` |
| Checkout detail | Active allocation, remaining, committed, discretionary amounts | Canonical payment and active allocations; 0001 funded position projection when installed; remaining less commitment, with inconsistent data unavailable |
| Checkout detail | Order/invoice IDs and current status, service dates, completion, settlement links | 0003 targets and current owning records; 0004 provider transaction settlement claim and canonical settlement history |
| Track issue | Episode, reason, first seen, eligibility, resolution, next action | Verified recovery/completion or mail result; RMS UTC handling time; approved immediate/15 minute policy; authoritative recheck; earliest pending local action |
| Alert | Recipient, reference, reason text, RMS URL, delivery outcome | Validated configured admin resolver and encrypted episode snapshot; bounded reason catalog; named internal checkout route; dispatch claim and EmailLog |
| Recover | Eligibility, requested operation, last/next run, result | Existing evidence and saved completion intent; validated request UUID and actor; retained recovery schedule; audited worker outcome |
| Resend | Eligibility, snapshot hash, unknown warning, recipient/content, send result | Current completed purchase and invoice checks; SHA256 of canonical saved confirmation snapshot; original dispatch outcome; retained encrypted snapshot and EmailLog |
| History and evidence | Actor, time, before/after, action, delivery attempts, purged indicator | Existing audit subject entries, checkout linked email context, linked provider event and raw removal timestamp, all scoped through checkout |
| Settings | Duration, cutoff, timezone, support contact, version, audit ID | Owning company's `payment_settings`; validated edit input; hash of saved values and `updated_at`; authenticated actor and successful audit insert |
| Saved credit action | Eligible cents, invoice balance, resulting allocations, allocation date, audit result | Locked payment/active allocations/funding, canonical AR invoice totals and period checks, owning AR workflow's accepted date retained with the operation, accepted fingerprint and resulting audit |
| Health and release | New collection enabled, configuration readiness, last scheduler/purge success, overdue work, worker observation | Laravel config presence/validated account IDs without secrets; command success markers in existing shared cache; scoped due records and recorded worker claims/completions |

### Configuration, recovery, and deployment

No new provider, secret, external monitoring service, or business setting is needed. Reuse 0003's merchant settings, mail configuration, `DAILY_DISH_ADMIN_EMAIL`/`DAILY_DISH_ADMIN_EMAILS` recipient resolution, `SYSTEM_USER_ID`, recovery and purge commands. Validate actual deployment configuration rather than treating example defaults as production values. The administrator must have a usable RMS link and company appropriate mail recipients before alerts can work.

Extend `payments:recover-skipcash` to select due local issue/operation intents as well as the existing provider/accounting work. Keep at most 100 due checkout IDs per pass, stable ordering, overlap protection, exclusive claims, existing network timeouts, and after commit dispatch. Do not create a second recovery engine. Healthy runs evaluate newly due local timers within the next minute; queue or infrastructure delay is shown as overdue, never silently changed to success. Disabled new collection still allows recorded payment recovery, safe staff recovery, confirmations, history, and purge.

For a basic health summary, record successful recovery and purge run times in the existing shared cache after each successful bounded pass, even if it found no work. Use deployment and source/company qualified keys. Show recovery heartbeat stale after 5 minutes and purge stale after 26 hours; absent/cache unavailable is Unknown, not Healthy. Display oldest overdue local work and last observed worker result from scoped records. Do not claim workers are healthy merely because no checkout is pending. Read paths must not create finance defaults or run provider requests. A failed command exits unsuccessfully and records a sanitized application error, preserving its prior successful marker.

Basic checks in the existing recovery path compare accepted provider amount/currency/source, receipt linkage and amount, completion target links, active allocation limits, and current customer/company/branch ownership. They report mismatches to the existing issue interface without editing posted rows. Consistency checks and alert timing cannot allocate saved credit, recreate a voided invoice, reactivate an expired legacy membership, or change public payment state by themselves. Future feature 9 feeds the same issue interface for its separately designed domains.

### Critical test scenarios

The detailed matrix is in [verify.md](verify.md).

* One verified payment blocked by an accounting period appears immediately, alerts once, and completes from its saved dates after authorized correction and Retry, proving **AC-1**, **AC-2**, **AC-3**, **AC-4**, and **AC-7**.
* Recovery at 14 minutes versus unresolved processing at 15 minutes, issue escalation, mail uncertainty, and a lost dispatch prove **AC-3**, **AC-4**, **AC-7**, and **AC-12**.
* Successful, known failed, uncertain, concurrent, stale, and corrected order confirmation resends prove **AC-5**, **AC-6**, and **AC-10**.
* Support only staff, inactive users, customer tokens, arbitrary history IDs, cross company/branch records, and merge during a queued action prove **AC-6** and **AC-11**.
* Setting edits and stale forms leave started attempts and bookings unchanged, proving **AC-8**.
* Admin versus other staff saved credit, committed membership funds, duplicate action replay after a void, overlapping allocations, and locked periods prove **AC-9**, **AC-10**, and **AC-11**.
* Independent payment/invoice/email/settlement status, pending free requests, no refund actions, basic link discrepancies, disabled collection, absent heartbeat, and raw purge prove **AC-10**, **AC-11**, and **AC-12**.

## Build plan

**Approach**: Tracer Bullet. Prove one real operations path through issue detection, the screen, one alert, safe recovery, and audit before widening the tools. These are implementation milestones, not work completed by this specification.

1. Add one forward migration for the checkout operations fields/index and the initial permissions. Build a scoped SkipCash list/detail, locked issue tracking, durable single alert, and audited Retry path for one verified ordinary payment blocked by a required period. Prove recovery from retained intent and dates with fake mail/provider boundaries, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-6**, and **AC-7**.
2. Extend the same path to the 15 minute timer, simultaneous issues, uncertain provider evidence, mail retries/uncertainty, action replay, paginated search/history, and private evidence inspection. Prove merge, corrected invoice, and canonical settlement links without financial reprocessing, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-6**, **AC-7**, **AC-10**, and **AC-11**.
3. Add deliberate customer confirmation resend through retained snapshots and distinct claims, plus the audited RMS settings form and existing settings API. Cover lost responses, concurrent clicks, changed eligibility, and old attempt/booking snapshots, satisfies **AC-5**, **AC-6**, **AC-7**, **AC-8**, and **AC-11**.
4. Enforce the administrator saved credit guard across every discretionary writer, durable action replay, source funding exclusions, allocation/period checks, and correct balance/status projections. Preserve automatic purchase allocation; add membership adapter contracts and release tests that must pass when membership funding ships, satisfies **AC-6**, **AC-9**, **AC-10**, and **AC-11**.
5. Complete basic consistency reporting, command/worker health, disabled collection operation, purge, responsive UI, and regression/release drills in both applications. Prove no charge or financial mutation from alert/resend and no required routine staff approval before requesting live enablement, satisfies **AC-1**, **AC-2**, **AC-4**, **AC-5**, **AC-6**, **AC-9**, **AC-10**, **AC-11**, and **AC-12**.

## Migration and release plan

Apply an additive forward migration after the 0003 tables exist. Do not rewrite earlier migrations. Old attempts retain null operations fields; first observation initializes them under lock without touching financial records or creating past alerts for already resolved purchases. For an existing unresolved issue, use the earliest durable RMS processing failure observation if available, otherwise the first new observation, never a guessed provider or browser time. Treat a definite manual obstacle as immediately eligible.

Seed permissions idempotently for Administrator only. Check existing users and direct assignments so a support permission cannot accidentally grant finance editing or saved credit allocation. Deploy compatible workers with the services before showing mutating controls. Verify configured mail recipients, audit storage, source scope, queue, and schedule before enabling the alerts. No migration sends email, calls SkipCash, seeds sample finance records, or rewrites payment history.

Rollback keeps additive fields, audit, email history, and recorded operations. Hide the new controls or disable new collection if needed, but retain a compatible worker for already recorded recovery and send claims. Do not roll back to code that could resend an unknown claim or forget an accepted action UUID. Failure recovery is a forward correction, never deleting a posted receipt, allocation, settlement, or audit entry.

## Consequences

**Positive**:

* Routine successful orders remain automatic, with one familiar place for genuine exceptions.
* Staff see the difference between collected money, unfinished RMS processing, email delivery, and bank settlement.
* Saved credit and historical corrections remain explainable through existing financial workflows.

**Negative / tradeoffs**:

* Email can be delayed or uncertain, so the RMS list remains the authoritative work queue.
* Compact tracking still needs careful claims, audit replay, and queue recovery tests.
* Unknown provider evidence and finance locks cannot be resolved by a force button. Broader membership checks cannot ship before their owning features exist.

## Follow-up

* [x] Owner acceptance recorded and scope build milestones linked. The independent design check was skipped at the owner's request; implementation verification remains required.
* [ ] During implementation, prove every protected action, discretionary allocation writer, and audit insert with the verification matrix before enabling live operations.
* [ ] Extend the shared view with the approved membership/request projections as their slices ship, and complete feature 9's broader consistency specification before those checks are claimed as implemented.
