# Payment integration development handoff

**Updated**: 2026-09-01
**State**: Final design accepted and ready for development. No application implementation, migration, live test or deployment is claimed.

## What is ready

All ten scope features now have a detailed design. Specifications 0001 through 0005 were previously confirmed; 0005's independent design check was explicitly skipped. Specifications 0006 through 0010 remain `Proposed` because they are not implemented, but their content was accepted by the owner on 2026-09-01. They have been reconciled with current RMS and customer website code. The independent review identified three technical completeness gaps, and the owner approved and accepted all three corrections before final design acceptance.

The architect skill was used to write and reconcile the designs. Two read only source scouts mapped subscriptions/AR and the customer website; those reports are source discovery, not an independent critique of these specifications.

After applying the three corrections and recording final acceptance, the coordinating self check resolved all 130 local links across the 32 plan documents, traced all 44 acceptance criteria in 0006 through 0010 into their build and verification contracts, found all 12 required consistency rules and passed `git diff --check`. This is documentation validation, not runtime or independent implementation verification.

## Specification map and ownership

| Scope feature | Design | Implementation responsibility |
|---|---|---|
| 1. Shared payment and accounting contract | [0001](0001-payment-accounting-contract/index.md) | Cross feature invariants; its milestones roll up the owning slices rather than create a second implementation |
| 8. Customer matching and signup | [0002](0002-customer-matching-signup/index.md) | Canonical ownership, temporary SMS bypass provenance, private review and destination login merge |
| 2. SkipCash ordinary checkout | [0003](0003-skipcash-paid-order/index.md) | Checkout/provider records, source/settings/terms, paid order, receipt, invoice and recovery |
| 3. Settlement and fees | [0004](0004-skipcash-settlement/index.md) | Private XLSX staging, reviewed matching, net payout and separate fee expenses |
| 4. Payment operations | [0005](0005-payment-operations/index.md) | Staff support, recovery visibility, alerts, settings and administrator saved credit guard |
| 9. Consistency checks | [0006](0006-payment-consistency/index.md) | Durable diagnostics, targeted checks, 15 minute sweep and Qatar 02:00 full scan |
| 7. Membership purchase | [0007](0007-membership-purchase/index.md) | Full package pricing, immediate paid conversion, sequential funded blocks and verified legacy opening |
| 10. Covered booking | [0008](0008-membership-booking/index.md) | Returning member experience, main quantity attribution, daily invoices, cutoff, void and pause correction |
| 5. Promo administration | [0009](0009-promotion-administration/index.md) | Generated company codes, bounded offer rules, admin controls and manual sharing |
| 6. Promo redemption | [0010](0010-promotion-redemption/index.md) | Server eligibility, protected holds, permanent use and isolated zero amount request |

Each directory contains index.md, rationale.md and verify.md. index.md owns the implementation contract; verify.md contains future execution gates. All feature specs remain Proposed because no implementation has begun.

## Decisions and inputs

### Owner decisions confirmed on 2026-08-31 and 2026-09-01

1. **Activated promo editing, approved:** freeze discount, eligible plans, purchase eligibility, dates and per customer limit after first activation. Admins can pause, expire or increase the total limit; a different offer is copied to a newly generated code.
2. **Independent design check, completed:** GPT-5.5 reviewed 0006 through 0010 and this handoff against the shared contracts. The earlier decision to skip the 0005 check was not applied to this new batch. The [review record](0006-payment-consistency/rationale.md#independent-review-of-the-final-design-batch) explains its scope, findings and recommended additions.
3. **Review corrections, approved and applied:** the exact consistency registry, deterministic legacy opening manifest/classification and selected-branch membership projection were added without changing the agreed business rules. The independent reviewer has not re-reviewed the corrected text.
4. **Final design, accepted:** the owner accepted specifications 0006 through 0010 on 2026-09-01. Their scope features are now in progress with ready to build milestones. Their spec status stays `Proposed` until development and verification are complete.

### Review corrections applied

* **R1:** [0006's required registry](0006-payment-consistency/index.md#required-rule-registry) names each rule's source records, fields, expected source, deferral conditions and resolution evidence. Its contract test makes incomplete enabled adapters Unhealthy.
* **R2:** [0007's legacy opening manifest](0007-membership-purchase/index.md#legacy-opening-manifest-and-classification) fixes the reviewed data, verified/incomplete/unsupported/already-applied rules, restart behavior and preserved existing access. 0008 owns evidenced later void restoration.
* **R3:** [0007](0007-membership-purchase/index.md#api-and-website-contract) and [0008](0008-membership-booking/index.md#api-contract) retain the current website branch, return only compatible spendable balance and define authorized history labels plus 404/409/422 recovery without a new selector or automatic payment.

These are technical additions preserving the agreed business rules. The owner approved them before they were applied. There are no open independent review findings or unresolved design decisions.

No new decision is needed about refunds, recurring charges, tax, meal expiry, delivery workflow, general credit at website checkout, customer match approval or a second active membership selector. Those remain excluded or settled as previously agreed.

### Recommended technical details included for signoff

* Positive first purchase initiation recovers an unresolved first payment instead of promising first purchase eligibility twice. Repeat completed purchases remain allowed without finishing the older allowance.
* Covered changes retain the original cancelled order/invoice and create a linked replacement revision through existing services. This preserves history, not a new fulfillment workflow.
* New memberships use an explicit queue mode; old standing subscriptions change only after verified opening. No global resync or guessed legacy backfill is permitted.
* Diagnostic batch sizes, run health thresholds, generated code format and authenticated quote throttles are deployment defaults in the specs, not new business questions.

### Required before live enablement, not before writing code

* Confirm the customer contact number. Current website evidence is 55683442; do not silently treat it as the approved production contact.
* Supply final T&C content, canonical URL and approved immutable version for retention.
* Provide SkipCash sandbox and production configuration, callback/return domains, verified timestamp/currency evidence and provider/report identifier mappings through secret configuration, not repository files.
* Confirm the existing default company/bank, SkipCash clearing account, commission and settlement fee expense mappings, and active system actor through the setup screen/runbook. No new owner choice of a different bank is implied.
* Ensure queue workers, scheduler, private storage, encryption key management, mail recipients and recovery monitoring are operational.
* Resolve applicable website security findings recorded in its AGENTS guide, including tracked credential/setup and submission log exposure, before live payment rollout. Do not copy their contents into fixtures or this plan; credential rotation is a separately authorized operational action.
* Review actual legacy opening dry run exceptions before enabling those old balances. A dry run has not been executed in this planning task.

Accounting treatment here records the owner's chosen existing invoice/advance policy. It is not an independent legal or accounting compliance opinion.

## Build order

Use one narrow real path through both applications before adding breadth, with all live flags off. Work remains on the payment integration feature branch; no merge, commit or push is authorized by this planning step.

| Stage | Deliverable | Dependency and exit condition |
|---|---|---|
| 0. Safe foundation | Inventory writers and both app routes; add isolated fixtures, source/settings/terms interfaces and feature flags | Read root and domain AGENTS, establish safe MySQL test environment; no production data commands |
| 1. Identity and one ordinary payment | Implement 0002 core ownership and 0003 narrow quote to provider to paid order/invoice/receipt | Verify source identity, signatures, replay and no browser authority; optional AI cannot block signup |
| 2. Operate and reconcile money | Implement 0004 reviewed report clearing, 0005 support/credit controls and 0006 base payment/ownership/settlement checks | A receipt can be explained through net bank settlement and fees; recovery works with new checkout disabled |
| 3. Membership vertical slice | Implement 0007 and 0008 together: empty purchase, partial choices, later covered booking, repeated purchase and original funding | Never enable membership purchase without returning booking, direct invoice void, pause and exact counter behavior |
| 4. Promotion vertical slice | Implement 0009 then 0010 with positive discount and zero request paths | Permanent limits, merged history, no free entitlement and exact invoice discount allocation are proven |
| 5. Migration and release rehearsal | Legacy dry run, approved apply rehearsal, both app contract tests, monitoring, permissions and owner walkthrough | Each advertised customer journey works; fresh implementation review and all GA gates before live flags |

0006 adapters are added with stages 3 and 4, not postponed after launch. Stage 2 support and settlement can be developed as bounded tracks after shared record contracts exist, but coupled money/queue writers must have one owner. This handoff does not authorize extra agent tasks or new application services beyond the specs.

Default launch is coordinated across the existing website offerings: do not take an advertised membership path away while switching ordinary orders to SkipCash. An earlier ordinary only release would need an explicit owner launch decision and a working approved membership path. It is not assumed here.

## Migration ownership and shared invariants

| Owning slice | Schema responsibilities |
|---|---|
| 0002 | Customer merge pointer, matching review and proof provenance changes |
| 0003 | Payment source/settings, checkout targets/attempts, provider transactions/events and nullable receipt source |
| 0004 | Settlement staging and existing clearing extensions/mappings |
| 0005 | Checkout operations tracking, permissions and existing audited allocation guard |
| 0006 | Diagnostic runs/findings and the versioned consistency rule registry |
| 0007 | Plan rows, queue mode/revision, purchase blocks, opening manifests/rows and request conversion linkage |
| 0008 | Funding rows, booking revision/notification fields on existing mappings, evidenced opening release attribution and audited pause resume markers |
| 0009 | Promotion rules and plan eligibility pivot |
| 0010 | Reservations, permanent redemptions and zero request snapshot/replay fields |

Create each shared table once, in its owning forward migration. Nullable FKs are added in dependency order; request/redemption circular pointers are added only after both tables exist. Earlier slices define interfaces without migrating unused later feature tables. Tests cover clean schema and representative existing data, never a destructive routine refresh of a development database.

### One mutation context

The detailed queue lock contract is in 0008. Resolve canonical customer before any affected queue invoice/payment lock. Merge acquires customer IDs then user IDs ascending. Promo reservation adds promotion locks before queue locks; admin promo changes never acquire customer locks afterward. Queue roots and blocks are locked deterministically before booking/funding and canonical finance writes.

0005's payment row/action lock is still required, but when that payment backs a queue it is inside this outer context, not the first lock. Direct AR invoice/void/allocation entry points must discover this context before their current child locks. This is a technical ordering clarification, not a change to financial authority or permission. Settlement does not change customer allocations or quota and must not acquire a queue lock after holding a conflicting customer financial lock.

### One set of counters and dates

Only the queue service changes queue mode counters. Legacy issue listeners, price based resync, manual order increments and scheduled generation either delegate or skip that mode. Counter tests include direct services and existing screens, not just the new customer API.

Provider finish date owns receipt date. First intended invoice issue owns its retained issue date. Service dates do not silently replace accounting dates. Membership expiry comparison is strictly before; ordinary paid orders retain their approved late completion rule. Customer date/cutoff comes from Qatar server time, never the browser.

Invoice edits do not change saved meal positions. Void releases actual allocation and original saved quantity once. A later restoration does not make an earlier valid use of a later block incorrect; ordering is judged when the booking was accepted. Pauses correct future bookings in their period and never regenerate unchosen meals.

### One customer website contract

Actual repository path: /Applications/XAMPP/htdocs/laylakitchen. The earlier /Documents/XAMPP path is not the inspected checkout. Keep PHP/vanilla JS, existing account authentication and current menu availability. No new frontend framework or delivery area validator is required.

Both applications must distinguish ordinary checkout, paid membership purchase, covered booking and zero amount request. Prices, counts, status and ownership come from RMS. Full package pricing replaces partial selection pricing only in purchase mode. Existing unsigned or legacy customer submission endpoints cannot bypass the live enabled payment/membership paths; trusted RMS manual workflows remain scoped and compatible.

The existing configured order/menu branch remains the website branch; no customer branch selector is added. Spendable membership totals contain only that branch's compatible queue. Records already visible through authorized account history may be labelled unavailable here, but cannot be spent or trigger an automatic purchase.

## End to end acceptance journeys

| Journey | Required observable result | Owning specs |
|---|---|---|
| New account, uncertain possible duplicate | Owned customer created/linked without staff wait; review private; later destination merge preserves accounting and login rule | 0002, 0006 |
| Ordinary order across future dates | One payment, original orders and paid invoices; same day exclusions reviewed; return/reload and late success recover correctly | 0001, 0003, 0005 |
| Membership with no choices | Full receipt, converted request, full allowance; no menu requirement, order or package invoice | 0007 |
| Buy 20, select 5, return for 4 six weeks later | One original payment, 9 selected and 11 available; no expiry or second charge | 0007, 0008 |
| Several meals for family on one date | Count main quantities, sides/delivery included, same customer details | 0008 |
| Buy again before finishing the first | New receipt and block; old remaining positions consumed first, including one order spanning blocks | 0007, 0008 |
| Membership history from another website branch | Current branch spendable queue remains empty or unchanged; authorized history is labelled unavailable here; forged cross-scope reference is rejected and no payment starts automatically | 0007, 0008 |
| Change/cancel before and at cutoff | Valid early replacement/cancel corrects financial and quantity history; exact deadline rejects without changes | 0008 |
| Invoice edit then void | Edit alone changes no quota; void cancels linked order and restores original attribution once | 0008 |
| Pause future paid bookings | Void/release/cancel/restore together; later unpaused dates available without automatic orders | 0008 |
| Valid partial promo, first versus renewal | Eligibility from combined completed history; full meal count and exact discounted invoice totals | 0009, 0010 |
| Valid zero promo and repeat submission | Same pending request and permanent use; no payment, membership, allowance or bookings | 0010 |
| Late membership payment or unknown provider result | Actual credit for proven late capture, no automatic conversion; unknown stays recovery without another charge prompt | 0003, 0005, 0007, 0010 |
| Admin retained credit and cancelled purchase | Only admin can allocate discretionary paid funds; active allowance money stays committed; no discount credit or restored promo use | 0005, 0007, 0010 |
| SkipCash report with commission and fee row | Reviewed unique matches, gross clearing, fee expenses and net default bank, no change to customer receipt | 0004 |
| Failed local posting, failed email, restart or shutdown | Durable evidence and safe recovery; no double money, entitlement or original confirmation; diagnostics visible | 0003, 0005, 0006 |
| Verified and unsupported legacy balances | Reviewed manifest applies only verified rows once; incomplete/unsupported history keeps existing access; changed evidence invalidates; later evidenced void restores its original range once, never guessed or forced repurchase | 0001, 0006, 0007, 0008 |

## Development verification and handoff

For every slice run /develop, /check verify, /test, a fresh /check review and /document as required by the GA scope. Design review does not replace implementation verification.

* Confirm the test connection targets isolated MySQL store_test before any RefreshDatabase or migration test. Do not run automated suites against development or production records.
* Run targeted and full affected RMS domain suites, changed PHP formatting and UI asset build. Test provider and mail boundaries with fakes and synthetic report files, not the user's real customer workbook.
* In the website run node --test tests/orders-pricing.test.cjs plus new mode/API/recovery contract tests, lint changed PHP and run its asset build. The current partial plan pricing tests need intentional purchase mode changes, not deletion of ordinary pricing coverage.
* Verify responsive/keyboard behavior at approximately 360, 768 and 1024 pixels, both base path deployments, profile/merge/logout and second device recovery.
* Inspect final diffs for secrets, raw provider payloads, customer files and unrelated worktree changes. Document every flag, worker, schedule, mapping, migration and rollback limitation.
* Enable only after approved sandbox flow and report mapping proof, owner walkthrough, usable support/diagnostics and release rollback rehearsal. No live charge or migration is authorized by this document alone.

Next step: update this feature branch from `origin/main` without losing the current work, then begin the foundation and identity and ordinary payment tracer with `/develop` using the owning specifications, not another broad requirements interview.
