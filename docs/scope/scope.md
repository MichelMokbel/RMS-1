# Scope: Customer payments and promotions

Extend RMS so an external customer ordering website can take SkipCash payments safely, create the right receivable records, clear provider payouts into the bank, and use promotion codes managed in this dashboard.

**Build approach:** Tracer Bullet (prove one narrow real payment through every layer, then add breadth).
**Workflow:** GA (`/check verify`, `/test`, a fresh `/check review`, then `/document`). The project default level of rigor. `/architect` is the recommended first stop for a feature with a real decision, but skippable when you already know the build. Any feature can carry its own tag to do more or less.

_These are recommendations to keep your build orderly, not requirements. Skip anything that does not fit. You decide when a feature is `done`._

## At a glance

| # | Feature | Phase | Status |
|---|---------|-------|--------|
| A | Customer order creation | Existing | existing |
| B | AR invoices and payments | Existing | existing |
| C | AR card clearing | Existing | existing |
| D | Membership and subscription orders | Existing | existing |
| 1 | Payment and accounting contract | Foundation | planned |
| 2 | SkipCash paid order tracer | Slice 1 | planned |
| 3 | Gateway settlement and fee clearing | Slice 2 | planned |
| 4 | Payment exceptions and operations | Slice 3 | planned |
| 5 | Promotion rules and dashboard | Slice 4 | planned |
| 6 | Promotion validation and redemption API | Slice 5 | planned |

## Existing foundation

### A. Customer order creation · existing
Customer and public order services already create scoped orders with audit and replay protection where the current public flow requires it.
**Done when:** existing order creation remains the source of order identity and does not create duplicates when a request is retried.
Code in `app/Services/Orders/`, `app/Services/Customers/`, and `app/Http/Controllers/Api/`

### B. AR invoices and payments · existing
AR services already create and issue invoices, record customer advances and allocated payments, and preserve immutable accounting history.
**Done when:** new gateway flows reuse these services and preserve invoice, allocation, reversal, period, and audit rules.
Code in `app/Services/AR/` and `app/Models/ArInvoice.php`

### C. AR card clearing · existing
The current clearing flow moves gross card collections from card clearing to a bank account and supports voiding and replay safety.
**Done when:** gateway settlement extends this pattern without weakening existing card and cheque clearing behavior.
Code in `app/Services/AR/ArClearingSettlementService.php`

### D. Membership and subscription orders · existing
Membership and subscription services already generate advance orders, link payments, track invoice use, and preserve subscription usage.
**Done when:** gateway payment and promotion rules do not duplicate covered meals, payment links, invoices, or usage.
Code in `app/Services/Subscriptions/` and related subscription models

## Foundation

### 1. Payment and accounting contract · needs a decision
Set the business contract before code is written. Resolve payment confirmation, advance order revenue timing, customer invoice timing, membership value, refunds, disputes, merchant ownership, fees, and settlement evidence.
**Done when:** each money movement has an agreed business event, ledger entry, reversal rule, owner, and customer visible outcome, including orders placed before fulfillment.
- [ ] Design it (spec): `/architect payment and accounting contract`

## Slice 1: One real paid order

### 2. SkipCash paid order tracer · needs a decision
Prove one real flow from an existing order through SkipCash initiation, verified server confirmation, invoice and payment creation, customer status, ledger posting, and audit. The browser return is informative only and cannot mark money as paid.
**Done when:** one QAR order can be paid once, exact retries are harmless, forged or mismatched events are rejected, and the invoice, payment, allocation, order, membership context, and clearing balance agree.
- [ ] Design it (spec): `/architect SkipCash paid order tracer`

## Slice 2: Clear provider payouts

### 3. Gateway settlement and fee clearing · needs a decision
Match SkipCash payout batches to captured payments, record commissions and fees separately, and move the net deposit from provider clearing into the selected bank account.
**Done when:** gross payments equal net bank receipt plus fees and adjustments, unmatched items stay visible, duplicate settlement is blocked, closed periods are respected, and void or correction restores a complete audit trail.
- [ ] Design it (spec): `/architect gateway settlement and fee clearing`

## Slice 3: Operate safely

### 4. Payment exceptions and operations · needs a decision
Give finance and support staff a safe view of pending, successful, failed, expired, refunded, and mismatched transactions, with deliberate recovery actions.
**Done when:** staff can diagnose provider events, retry safe internal processing, process agreed refund paths, and reconcile every gateway transaction without editing posted financial history.
- [ ] Design it (spec): `/architect payment exceptions and operations`

## Slice 4: Manage promotion rules

### 5. Promotion rules and dashboard · needs a decision
Let authorized dashboard users create and distribute codes with clear eligibility, value, dates, limits, company and branch scope, membership interaction, and audit history.
**Done when:** staff can create, activate, pause, expire, and inspect fixed or percentage codes, and every rule change and redemption remains explainable.
- [ ] Design it (spec): `/architect promotion rules and dashboard`

## Slice 5: Apply promotions once

### 6. Promotion validation and redemption API · needs a decision
Expose a stable API contract for the external website to quote a code, then redeem it against the final server calculated order amount without trusting browser totals.
**Done when:** eligible customers receive the correct discount, ineligible or exhausted codes fail clearly, one order consumes at most one permitted redemption, membership rules are enforced, and the order and invoice retain an immutable discount snapshot.
- [ ] Design it (spec): `/architect promotion validation and redemption API`

## Deferred

- **External customer website implementation**: build the SkipCash redirect experience and promotion entry UI in its own repository after these API contracts are accepted.
- **Automatic payout retrieval**: add only if confirmed SkipCash APIs provide reliable settlement and fee data. The first release can import or enter provider evidence and match it safely.
- **Dispute automation**: keep chargeback ingestion and provider dispute workflows out of the first slice unless SkipCash operations require them for launch.

## Legend

**The decision box.** Every planned feature starts with one architecture decision. After its spec is captured, the feature gains build, verify, test, review, and document steps from that spec.

**Feature lifecycle:** `planned` becomes `in-progress` when design or build starts, then `done` when you accept the verified result. `existing` describes work that predates this workflow.

**Next step:** the first unticked box is the recommended next command.

**Workflow:** GA means payment features normally run `/architect`, `/develop`, `/check verify`, `/test`, a fresh `/check review`, and `/document`.
