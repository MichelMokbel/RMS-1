# Rationale for the payment and accounting contract

## Context

> ⚠️ Premise note: This topic spans payment capture, membership funding, promotions, identity resolution, settlement, and operations. Treating all of that as one build would create a large release with difficult recovery. This record therefore fixes only the shared contract. The scope already separates the implementation into focused specifications and Tracer Bullet slices.

RMS already owns customer records, orders, AR invoices, payments, allocations, customer advances, ledger postings, bank transactions, membership usage, finance locks, and audit history. The customer website is a standalone PHP application that proxies to RMS. Adding another financial authority in the website or provider callback would create conflicting balances and duplicate posting paths. (basis: `AGENTS.md`, `app/Services/Accounting/AGENTS.md`, `app/Services/AR/AGENTS.md`, `app/Services/Ledger/AGENTS.md`, and the customer website `AGENTS.md`)

SkipCash payment confirmation is asynchronous. Webhooks can repeat and arrive out of order, and a failed attempt may lead to another provider payment ID for the same merchant transaction. The browser return cannot prove receipt. The system therefore needs durable attempt, provider transaction, and event identities before it can safely create accounting effects. (basis: idempotency keys for money operations, idempotent consumer practice, monotonic payment state transitions, and the official SkipCash Webhooks and Get Transaction Details documents)

Membership receipts are not ordinary invoice payments under the current business policy. The receipt remains a customer advance, and later daily invoices recognize revenue and consume the purchase that funded the meal. Several purchases must form one ordered allowance queue without creating competing active subscriptions or individual membership meal records. (basis: `docs/scope/scope.md`, `app/Services/Subscriptions/AGENTS.md`, and the existing AR advance allocation flow)

SkipCash settles gross customer collections as a net bank payout after commission and settlement fees. The supplied report is the available settlement evidence for this release. Customer receipts must therefore remain gross while the payout records separate expenses and the bank net amount. (basis: the supplied SkipCash XLSX report, double entry accounting, and `app/Services/Banking/AGENTS.md`)

The provider hosted page keeps card entry outside RMS, but payments still require a formal PCI DSS scope review. Audit history, permission checks, secret isolation, personal data minimization, and company boundaries are mandatory because the feature moves money and stores customer evidence. (basis: `AGENTS.md`, PCI DSS responsibility scoping, and least privilege)

Customer identity uncertainty cannot become an ownership shortcut or a checkout gate. Deterministic phone and portal ownership checks govern historical access, subject to the explicitly accepted temporary bypass in 0002. A separately owned customer lets uncertain cases continue until the existing merge workflow resolves a confirmed duplicate. (basis: `docs/scope/scope.md`, `app/Services/Customers/AGENTS.md`, and the accepted 0002 design)

## Options considered

### Option 1: Treat SkipCash as an ordinary card payment

Record a card payment directly through the existing AR path and extend the current card settlement with a fee field. (basis: the existing `ArPaymentService` and `ArClearingSettlementService`)

**Pros**:

* It adds the fewest records and reaches a simple paid order quickly.
* It reuses current screens with little visible change.

**Cons**:

* Method `card` hides the real SkipCash source and cannot explain provider specific clearing.
* One merchant attempt can have several provider payment IDs, which the payment row alone cannot represent safely.
* Membership funding sequence, event replay, late capture, report matching, and separate fees become fragile special cases.

### Option 2: RMS owned checkout ledger with dedicated SkipCash clearing

Keep RMS as the financial authority, add durable provider boundary records, record method `skipcash`, and extend canonical AR and membership workflows with purchase blocks and source specific clearing. (basis: `AGENTS.md`, the existing service boundaries, idempotent consumer practice, and the official SkipCash API documents)

**Pros**:

* Each provider event, payment, allocation, meal, invoice, fee, and payout has one traceable owner.
* Existing period, audit, company, invoice void, ledger, and bank controls remain authoritative.
* It supports retries, late capture, several dated orders, and sequential memberships without redesigning the whole system.

**Cons**:

* It adds schema, recovery logic, permissions, and an exception queue.
* Operations must review imported settlement evidence and unresolved provider cases.

### Option 3: Build a separate payment and membership ledger service

Create a separate service that owns provider calls, wallet balances, membership entitlements, settlement, and reconciliation, then synchronize summarized results into RMS. (basis: service isolation and event driven integration)

**Pros**:

* It gives the integration an independent scaling and deployment boundary.
* It could support several providers and advanced wallet features later.

**Cons**:

* It creates distributed consistency between two money systems and requires mature operational ownership.
* It duplicates existing AR, ledger, audit, customer, and subscription rules.
* It is disproportionate for the current one person operation and would delay the first reliable payment path.

## Rationale

Option 2 is the smallest design that preserves the accounting and retry invariants. Option 1 is the runner up for speed, but calling SkipCash a card method loses the source identity required for clearing and report reconciliation. It also provides nowhere to store several provider attempts or repeated signed events without overloading immutable payments.

Option 3 would be reasonable only after a real provider count, traffic boundary, or independent team makes separate ownership valuable. Today it would turn local database transactions into cross system coordination and create more failure modes than it removes. The Tracer Bullet plan keeps the design inside the Laravel application and proves one paid order through every real boundary before adding membership, promotion, and settlement breadth.

## Supporting evidence

The sample report uses `orderType` to separate `Sale` and `Settlement Fee`. A sale `totalCommission` already contains the variable and fixed commission, so it is recorded once. A settlement fee row has zero gross and a negative net settlement amount. `paymentRef` groups the batch and is not a unique transaction key. `referenceNumber` is the row reference. `orderId` may be absent, and phone is supporting evidence only.

SkipCash create payment requires a signed request and returns a provider payment ID and hosted payment URL. Transaction detail exposes the provider status and finish time. Webhooks are signed, can repeat, and can arrive out of order. Provider paid status therefore becomes a monotonic fact inside RMS, while browser navigation remains informational. (basis: the official SkipCash API integration, Authentication, Get Transaction Details, Webhooks, PHP example, and API key generation documents)

### Approved review corrections, 2026-08-30

An independent specification review with `gpt-5.5` identified nine decision gaps. The engineer approved correcting them. This was a read only design review, not implementation verification. The main thread updated the contract and verification plan without changing application code, settled customer journeys, or the specification lifecycle status.

| Gap | Adopted correction and reason |
|---|---|
| Checkout replay | Persist the session and one create dispatch claim. Recover an unknown outcome rather than repeat a potentially successful charge. The merchant reference does not guarantee provider idempotency |
| Payment timing | Map provider status IDs explicitly, verify finish time independently of unsigned webhook fields, and retain financial event dates. Delayed delivery cannot replace provider finish time or bypass a closed period |
| Unpaid orders | Keep immutable checkout targets until paid activation calls the existing order workflow. No unpaid operational order or staff managed state is needed |
| Meal counts and cutoff | Track reserved, invoiced, and released funding attribution, preserve credit positions and the booking cutoff, and update existing usage atomically. A meal never counts twice |
| Legacy funding | Establish opening quantities, actual history, and real payment links through a restart safe reconciliation. Missing evidence never becomes an invented receipt or zero price |
| Settlement duplicates | Deduplicate economic rows and provider transactions across files, with separate fee identities and batch posting guards. File hashes alone cannot protect money |
| Settlement dates | Use evidenced bank settlement dates and existing finance locks. Operator input cannot silently move a payout to another period |
| Terms version | Retain immutable published RMS terms content and the accepted version, hash, actor, and time. A changing URL alone does not prove what was accepted |
| Discount rounding | Round the package discount once, apportion gross and bounded discount, then derive net. This preserves package totals without a negative per meal discount |

The rounding defect was reproduced independently: distributing QAR 1,200 gross and QAR 1,199.99 net separately across 26 positions yields negative one cent discounts at positions 9, 12, 15, and 18. Distributing the one cent discount against cumulative gross avoids that result. A zero net meal slice can occur in a positively paid package and still counts as one main dish; only a zero net package follows the request only rule. These choices preserve the agreed allowance and existing payment accounting rather than impose a new minimum purchase price.

The date and legacy corrections preserve existing period, invoice issue, advance allocation, and audit controls. The terms choice uses versioned application configuration rather than adding a content management system. Detailed screens and migration execution remain in their already planned implementation specifications. (basis: `AGENTS.md`, `docs/scope/scope.md`, the AR and subscription service guides, and idempotency keys for money operations)

### Accepted customer identity refinement, 2026-08-30

The owner accepted [0002](../0002-customer-matching-signup/index.md) after an independent review and its approved clarifications. That design temporarily permits exact historical linking and gated customer actions through the existing server verification bypass until SMS is ready. It records bypass as an accepted impersonation risk, not evidence of phone possession. It also defines destination login survival, immutable proof and event references during merge, names only Gemini input, and continuity for existing unlinked sessions. This contract now references those decisions without changing the financial rules or enabling implementation.

### Owner revision for ordinary payment after expiry, 2026-08-30

The owner changed the ordinary order outcome after the 0003 review: a first fully verified matching payment creates the original saved order, paid invoice, payment, allocation, and normal confirmation even if it finishes at or after the checkout deadline. Timing alone must not send that paying customer to support with unused credit. This supersedes the earlier ordinary late credit rule in AC-8 and AC-9. Membership promotion timing, extra real collections, no refunds, administrator credit authority, payment verification, accounting dates, and finance locks are unchanged. (basis: the owner's explicit instruction and the revised scope)

The tradeoff is that an unpaid target release or expired timer cannot guarantee that the original order will never complete. Preserve its snapshots and accept later verified completion once; do not reprice, invent replacement dates, or weaken duplicate protection. This is a planning revision, not an application change or approval of the other proposed 0003 review findings.

## References

**Project sources**:

* `AGENTS.md`, money, security, tenant, audit, testing, and Tracer Bullet rules
* `docs/scope/scope.md`, confirmed customer, payment, membership, promotion, timing, and settlement policies
* `app/Services/AR/AGENTS.md`, invoice, advance, allocation, credit, and clearing rules
* `app/Services/Accounting/AGENTS.md`, period, journal, and audit rules
* `app/Services/Ledger/AGENTS.md`, source event posting and reversal rules
* `app/Services/Banking/AGENTS.md`, bank transaction and reconciliation boundaries
* `app/Services/Subscriptions/AGENTS.md`, subscription payment link and usage behavior
* `app/Services/Customers/AGENTS.md`, portal ownership and merge behavior
* [0002 customer matching and signup](../0002-customer-matching-signup/index.md), the accepted identity policy and reference ownership matrix
* `/Applications/XAMPP/htdocs/laylakitchen/AGENTS.md`, website proxy and customer experience boundaries
* `/Users/mohamadsafar/Downloads/Layla Kitchen-Z56ST980BL238.xlsx`, SkipCash settlement report evidence

**Practices and standards**:

* Double entry accounting
* Idempotency keys for money operations
* Idempotent consumer handling for repeated events
* Monotonic payment state transitions
* Least privilege and tenant isolation
* PCI DSS responsibility scoping for hosted checkout

**Links**:

* [SkipCash API integration](https://dev.skipcash.app/doc/api-integration/)
* [SkipCash authentication](https://dev.skipcash.app/doc/authentication/)
* [SkipCash Get Transaction Details](https://dev.skipcash.app/doc/api-integration/get-transaction-details/)
* [SkipCash webhooks](https://dev.skipcash.app/doc/web-hooks/)
* [SkipCash PHP example](https://dev.skipcash.app/doc/api-integration/php/)
* [SkipCash API key generation](https://dev.skipcash.app/doc/how-to-generate-the-api-keys/)
