# Payment operations rationale

## Context

One person currently manages RMS. Successful payments should need no routine approval or order state updates, but a collected payment whose RMS processing failed must be explainable and recoverable. The owner approved a SkipCash tab inside Customer Payments, one alert per unresolved issue, and deliberate resend of the customer's order confirmation.

The existing receipt list cannot show a checkout before its AR payment exists. Finance, mail, and provider outcomes can differ: SkipCash can confirm payment while a required posting period is locked, and a completed paid order can have an uncertain email delivery. Presenting one combined success/failure label would conceal the action actually needed.

The approved accounting, identity, checkout, and settlement designs constrain recovery. Staff must not initiate another charge, substitute a posting date, silently allocate credit, or recreate records after a correction. Operations also needs current customer ownership after merge without rewriting original evidence. These are planned integration contracts, not claims that SkipCash runtime code already exists.

## Options considered

### Option 1: Derive the entire support view from existing records and logs

Read attempts, provider events, payments, and mail history on demand, with recovery actions using existing services. (basis: 0003 and existing Customer Payments, accounting audit, and email history)

**Pros**: Few new stored fields and direct use of authoritative financial records.

**Cons**: Logs alone do not reliably identify a continuous unresolved issue, distinguish a changed error from a new episode, or retain an alert/send claim after a queue failure. Every consumer would need to reconstruct that intent.

### Option 2: Small operations tracking on the approved checkout records

Use the same read projection with bounded issue slots and durable action/alert pointers on the checkout. Reuse existing audit and email history for completed actions. (basis: 0001 and 0003, existing audit/mail services, and durable intent with idempotent actions)

**Pros**: Includes attempts without receipts, preserves one alert identity, and survives retries without introducing a support case workflow.

**Cons**: Shared checkout JSON needs explicit structure, parent locking, and careful separation from encrypted message content. It is not a general purpose ticket system.

### Option 3: Separate support case records and assignment workflow

Represent each issue in a dedicated case model with its own queue, assignment, notification history, and resolution actions. (basis: scope feature 4 and separation of support workflow from financial records)

**Pros**: Suits a larger support team with independent ownership, escalation, and case reporting.

**Cons**: Adds a second workflow to reconcile with checkout truth, more screens, and routine administration that this owner has explicitly sought to avoid.

## Rationale

Choose option 2. The owner needs trustworthy exception handling, not a support department model. Durable issue identity and send intent solve the actual recovery problem while the existing financial records remain authoritative. Deriving everything from logs is the runner up, but it loses a clear place to claim a send and remember which unresolved issue was already alerted. A ticket workflow has no present staffing need. (basis: confirmed owner workflow, scope feature 4, and 0003)

### Implementation recommendations and tradeoffs

| Decision | Pick and reason | Runner up and tradeoff |
|---|---|---|
| Staff entry point | Attempt based tab in Customer Payments, including attempts without a receipt | Extending only receipt rows is smaller but hides the main paid processing failure case |
| Issue storage | Bounded current slots on the checkout, completed transitions in existing audit | Separate issue rows offer richer reporting but create a new support entity the owner does not need |
| Alert identity | One episode and one durable alert intent, independent of changing reason text | Dedupe by error message is simpler but sends repeated alerts when the same failure changes wording |
| Action replay | Parent lock plus accepted UUID/fingerprint in immutable audit | A new operation table gives database uniqueness directly, but adds a record type; the chosen path must prove all writers acquire the parent lock |
| Recovery | Existing verified handler, no staff payment creation or force flags | A synchronous controller retry is easier initially but ties completion to a browser request and obscures uncertain provider outcomes |
| Confirmation resend | Separate deliberate send identity referencing immutable content and recipient | Resetting the original sent marker uses fewer fields but erases the distinction between original delivery and staff resend |
| Resend eligibility | Completed paid ordinary purchase with valid current invoice links; stale or corrected results explain why the saved message cannot be resent | Sending any historic message after a void is permissive but can mislead the customer about a cancelled or no longer paid order |
| Permissions | Separate inspect, recover, resend, settings, and admin credit rights | A single broad finance permission is simpler but gives support staff unnecessary money mutation authority |
| Settings concurrency | Compare the loaded row version before saving | Last writer wins is simpler but silently overwrites a later administrator edit |
| Health | Existing command success markers, scoped due work, and explicit Unknown state | A new monitoring service is unnecessary for this slice; cache markers alone cannot prove an idle worker is healthy |

These picks follow the approved stack and security rules. They refine implementation rather than change payment, membership, or customer journeys. (basis: `AGENTS.md`, 0001 through 0004, parent row locking, and durable intent with idempotent actions)

### Source observations

| Inspected source | Current behavior | Consequence for implementation |
|---|---|---|
| `resources/views/livewire/receivables/payments/index.blade.php` | Lists AR payments with customer search and 15 row pagination | Preserve it, but query attempts for the new tab so a missing payment remains visible |
| `resources/views/livewire/receivables/payments/show.blade.php` | Allocation action currently checks `finance.write`; removal and deletion have admin checks | The already approved admin only saved credit rule needs a service boundary, not only another UI condition |
| `app/Services/AR/ArPaymentService.php::applyExistingPaymentAllocations` | Locks the receipt and checks active allocations, invoice/customer/company/branch/currency; `paymentClientUuid` identifies the payment | Reuse the money writer, add deliberate action identity and admin/committed funds guards, and prove retry after a later void does not allocate again |
| `app/Services/Accounting/AccountingAuditLogService.php` | Can return without writing if its table is missing | Protected new actions must require a successful audit insert, not count the missing table fallback as success |
| `app/Models/AccountingAuditLog.php` | Existing subject type/ID, company, actor, payload, and timestamp | Checkout actions can use the existing polymorphic subject without a new history table |
| `app/Models/EmailLog.php` and `app/Services/Mail/EmailLogService.php` | Existing recipient lists, order/request links, context, and result; no checkout/company FK or delivery dedupe | Link through checkout context and dispatch IDs, scope through the parent, and sanitize exceptions before logging |
| `app/Services/Orders/CustomerDailyDishOrderService.php::resolveAdminRecipients` and `config/mail.php` | Existing configured administrator recipient resolution | Reuse and validate that source for the owning company; do not treat an example email as production configuration |
| `app/Services/Finance/FinanceSettingsService.php::getSettings` | A getter may create its singleton defaults row | The operations health/read path must not use a read that silently changes finance configuration |
| `routes/web.php` | Existing AR group and dynamic payment routes; permissions are broader than the proposed support capability | Add scoped literal routes before dynamic binding without broadening old receipt permissions |
| `bootstrap/app.php` and 0003 | Existing scheduler/queue wiring; approved bounded recovery, notification claims, and purge | Extend the approved command, not a second recovery engine |

These observations are implementation gaps to test, not fixes made by this document. (basis: the inspected source files in the table)

### Boundaries deliberately retained

The 15 minute rule measures unresolved RMS processing, not checkout validity. A verified ordinary purchase still completes from its saved selections after expiry or date rollover under 0003. Neither the alert nor an operator Retry creates an expiry credit policy. (basis: 0003)

The receipt remains gross and `skipcash`; clearing fees and net bank settlement stay in 0004. A no longer allocated amount can still fund unused membership positions, so the existing funded block contract determines discretionary credit. Pending free requests have no receipt or allowance to recover. (basis: 0001, 0004, and scope features 7 and 10)

Merge reassigns current ownership through 0002 without changing notification recipients or original portal/provider evidence. The tool must not confuse customer matching confidence or temporary login bypass with proof of a provider payment. (basis: 0002 and 0003)

## References

**Project sources**:

* [Root agent guide](../../../AGENTS.md): money, access, audit, testing, and change boundaries.
* [Feature scope](../../scope/scope.md): feature 4 operations, feature 9 consistency, and membership funding/request boundaries.
* [0001 shared contract](../0001-payment-accounting-contract/index.md): company settings, saved credit, public states, accounting, and membership funding.
* [0002 customer identity](../0002-customer-matching-signup/index.md): canonical ownership, destination login, and immutable source evidence.
* [0003 paid order checkout](../0003-skipcash-paid-order/index.md): planned records, verified completion, notification snapshots, recovery, and purge.
* [0004 settlement](../0004-skipcash-settlement/index.md): report review, fees, clearing, bank matching, and void boundaries.
* Source observations table above: exact repository paths and the inspected behavior behind the recommendations.

**Practices and principles**:

* Durable intent with idempotent actions: record accepted work before external delivery and return the same result for the same request identity.
* Parent row locking: serialize related claims and balance decisions through their authoritative record.
* Separation of support workflow from financial records: an issue or email outcome is not evidence that money moved.

No new provider, runtime dependency, web reference, tax treatment, or compliance interpretation was selected in this design.
