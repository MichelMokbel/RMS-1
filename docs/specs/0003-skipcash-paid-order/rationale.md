# Rationale for SkipCash paid ordinary orders

## Context

RMS already owns the order, invoice, receipt, allocation, audit, and ledger records. The active customer website at /Applications/XAMPP/htdocs/laylakitchen is a separate PHP application with browser cart storage and proxies to RMS. The shared payment contract and customer identity design are confirmed but not yet implemented. This specification turns the ordinary purchase slice into buildable work without reopening membership, promotion, revenue, or settlement policy. (basis: [0001](../0001-payment-accounting-contract/index.md), [0002](../0002-customer-matching-signup/index.md), and [scope](../../scope/scope.md))

The current ordinary submission is not a payment boundary. It accepts a submitted day total, creates operational orders and a MealPlanRequest, and sends email after its own local transaction. Invoice issue can automatically allocate older customer credit. Receipt posting chooses a clearing account from the method alone. Each is understandable in the current workflow but needs an explicit boundary for verified gateway payment. (basis: CustomerDailyDishOrderService, ArInvoiceService, ArPaymentService, SubledgerService, and LedgerAccountMappingService)

The operator wants paid invoices to be sufficient without maintaining additional order states. The customer must recover a closed browser, a lost network response, or delayed confirmation without another accidental collection. Provider evidence, customer ownership, current account visibility, and the financial result have different lifetimes and cannot be represented by a browser success screen. (basis: confirmed scope, idempotent consumer handling, and the official SkipCash Webhooks and Get Transaction Details research)

Hosted checkout keeps card entry at the provider. That narrows the implementation boundary but does not certify PCI DSS compliance or remove the merchant's responsibility to confirm its scope with SkipCash and the acquirer. Audit history, source/company checks, encrypted evidence, and secret isolation remain release requirements. (basis: the root agent guide, least privilege, and hosted checkout PCI DSS responsibility scoping)

## Options considered

### Option 1: Extend the existing submission endpoint in place

Add provider initiation and verified completion behavior to the current controller and order service. Keep its URL and adapt all callers together. (basis: the existing PublicDailyDishOrderController and customer website order proxy)

**Pros**:

* Fewer visible endpoint and caller changes.
* Existing payload, audit, and order creation code is immediately available.

**Cons**:

* The current operation creates orders and sends mail before there is any verified payment authority.
* Its cache based retry lifetime cannot own durable money deduplication.
* Mixing legacy and gateway semantics in one response increases cutover and recovery risk.

### Option 2: Add durable checkout orchestration and reuse domain writers

Create explicit quote, initiation, status, and webhook surfaces alongside the existing route. Share order persistence and financial services, then disable legacy direct customer submission at controlled cutover. (basis: 0001, the AR/Orders/Ledger service guides, and the strangler pattern)

**Pros**:

* Unpaid checkout records never become operational orders.
* The provider boundary can be retried and audited independently of browser visits.
* Existing invoice revenue, receipt, allocation, correction, and customer merge authority remain intact.

**Cons**:

* Both applications need coordinated API, UI, queue, and migration work.
* Recovery and dispatch intent need durable records rather than a thin redirect.

### Option 3: Replace ordinary order creation with a payment specific pipeline

Build a new order/invoice writer dedicated to gateway purchases and retire the current customer submission code directly. (basis: direct replacement as an enhancement strategy)

**Pros**:

* The new pipeline could have a uniform input and state contract from the start.
* It avoids optional behavior in legacy services.

**Cons**:

* It duplicates numbering, item snapshots, accounting, audit, and visibility semantics.
* It creates another financial writer and a larger production migration for no current scale benefit.

## Rationale

Option 2 keeps the payment boundary explicit while preserving the familiar operating system. The new layer decides whether a verified purchase may complete; canonical services still create its financial and order records. Option 1 is the runner up if the old route had no compatibility burden, but the observed cache, mail, and lead side effects make that assumption unsafe. Option 3 adds too much replacement work for the current one person operation.

The small internal choices follow that same boundary:

| Choice | Reason | Runner up and tradeoff |
|---|---|---|
| Queued provider creation with saved dispatch claim | A proxy timeout cannot lose the identity of a possible collection | Synchronous creation is simpler but increases dependence on the website's 12 second transport window |
| Recomputed quote and immutable started attempt | No new quote table is needed; a changed first quote returns to review | Persist every browsing quote, adding retention and cleanup work without payment authority |
| Original client UUID plus canonical cart recovery guard | Retries keep one identity while an explicit separate purchase stays possible | A new purchase/cart entity gives more lifecycle flexibility but is unnecessary here |
| Saved notification intent in the checkout and existing EmailLog | A queue dispatch loss can be found without making email part of the financial transaction | A generic outbox table scales to many event kinds but adds a wider framework to this slice |
| Existing page styles and bounded polling | Fits the installed PHP/vanilla JavaScript site and its operating scale | Websocket updates add infrastructure without changing payment authority |
| Source aware clearing resolution | Method stays skipcash and every gross receipt has the account needed for later payout reconciliation | Generic other clearing mixes provider balances and loses the accepted source contract |

These are engineering refinements to the confirmed data structure, not new customer policies. They were stated before drafting and remain subject to the complete draft review. No new provider, library, hosting service, AI model, delivery workflow, or membership model was selected.

## Source evidence

The main thread read the affected code. A bounded read only scout independently mapped payment and ledger integration points; no scout wrote specification or application files.

| Source | Observed behavior and design consequence |
|---|---|
| `app/Services/Orders/CustomerDailyDishOrderService.php:858` | Ordinary header total comes from the first submitted day_total. New quote/activation must use RMS integer totals |
| Same service, order creation and lead/email sections | Current wrapper creates MealPlanRequest even for an ordinary submission and sends mail after its local transaction. Extract reusable persistence without those gateway side effects |
| `app/Services/Pricing/MealPlanPricingService.php` and `config/pricing.php` | Existing portion, bundle, and side prices remain the source. Membership package rules are outside this slice |
| `app/Services/AR/ArInvoiceService.php:195`, `:362`, and `:481` | Order conversion creates a draft with source link; Daily Dish is a summary line; issue posts revenue and then allocates old advances. Add explicit internal no auto allocation policy |
| `app/Services/AR/ArPaymentService.php:124` | Allocated payment locks invoices, may cap allocation, and posts applied/unapplied balances. Gateway completion must assert exact full coverage |
| `app/Services/Ledger/SubledgerService.php:508` | Receipt debit currently resolves by method/bank and date from the received timestamp. Add source and retained Qatar posting date support |
| `app/Services/Accounting/LedgerAccountMappingService.php:215` | Unknown method falls to other clearing. That fallback is not correct for SkipCash |
| `app/Models/Payment.php` and payment migrations | Payment source FK is absent. Payment updates are restricted; extend creation and keep source immutable |
| `app/Listeners/SyncSubscriptionMealsOnInvoiceIssued.php` | Invoice metadata and plan items can change subscription usage. Ordinary invoices must never carry those subscription markers |
| `app/Services/Mail/EmailLogService.php` | Existing log records delivery outcomes, not a unique pending dispatch intent |
| Website `assets/js/orders-core.js:2972` | Submission clears all selections on HTTP success. Paid checkout must read purchase_confirmed and preserve newer drafts |
| Website `api/orders/_proxy.php` | Existing stream transport has a 12 second timeout and explicit authorization forwarding. Preserve statuses and avoid raw exception disclosure |
| Website shared page, router, Apache rules, and asset guide | New private result page needs both base paths, asset version helpers, route parity, no indexing, and controlled analytics |
| `database/AGENTS.md`, `bootstrap/AGENTS.md`, and `tests/AGENTS.md` | Forward migrations, scheduled job safety, source uniqueness, and disposable MySQL tests are required |

Some older scout observations described price defaults or server pricing differently. The current main thread reading is authoritative: ordinary day_total is currently trusted, and checked in plan rates are 45/46.15. This draft does not copy those older observations as implemented behavior or alter the accepted 900/1,200 membership package policy.

## Provider evidence reused

No provider pages were fetched again for this draft. The earlier completed SkipCash research supplied these facts:

* Create uses a signed request and returns a provider payment ID plus hosted payUrl.
* Amount is a decimal string with at most two digits after the dot, and documented currency is QAR.
* The mandatory field table includes Phone even though one minimal example omits it. This design supplies the customer's actual phone.
* Required create signature fields and webhook fields have different canonical sequences and secrets. Authentication examples differ on empty optional values; the exact nonempty payload needs a provider confirmed vector.
* TransactionId is a merchant correlation value, not a documented idempotency key.
* Details use Merchant Client ID authorization and expose status and finishedDate.
* Webhook acknowledgement is HTTP 200. The documented timeout is 10 seconds, with attempts immediately, after one hour, and after one day. RMS recovery therefore cannot depend only on provider retries.
* The optional expiryDate is date only. No reviewed fact makes it equivalent to the RMS minute deadline.
* Return query authenticity is not documented. The RMS reference and authenticated status endpoint supply the customer result.

These facts shape the adapter contract and integration gates. They do not authorize live provider calls, receipt posting, or credential changes during planning. (basis: the official API Integration, Authentication, Webhooks, Get Transaction Details, and PHP example research)

## Design record

* 2026-08-30: Owner confirmed same tab hosted payment after durable RMS checkout, followed by an RMS backed status page with recoverable selections.
* 2026-08-30: Owner confirmed the ordinary subset of the shared checkout, dated target, provider transaction/event, and existing financial record structure.
* 2026-08-30: Main thread drafted the detailed slice and verification matrix. The independent gpt-5.5 cross check subsequently completed read only. Other proposed clarifications and full draft ratification remain pending. The Proposed status does not indicate implementation.
* 2026-08-30: Owner replaced the ordinary payment after expiry credit outcome with normal creation of the saved order, paid invoice, payment, allocation, and confirmation. Expiry alone must not make a paying customer contact support. This revises 0001 for ordinary checkout only; membership promotion timing and genuinely additional captures are unchanged. The timer still limits payment initiation and link display, while verified receipt dates and finance checks remain authoritative. (basis: the owner's instruction to simply create the order and the updated scope)

## References

**Project sources**:

* [Payment and accounting contract](../0001-payment-accounting-contract/index.md)
* [Customer matching and signup](../0002-customer-matching-signup/index.md)
* [Feature scope](../../scope/scope.md)
* Root `AGENTS.md` and the Orders, AR, Accounting, Ledger, Customers, Pricing, Mail, database, bootstrap, routes, and tests guides
* The source services and website files named in the evidence table
* /Applications/XAMPP/htdocs/laylakitchen/AGENTS.md and its api/orders, assets, and components guides

**Practices and standards**:

* Strangler pattern for controlled live migration
* Durable dispatch intent and idempotent consumer handling
* Monotonic payment evidence and double entry accounting
* Least privilege and hosted checkout PCI DSS responsibility scoping

**Previously verified provider links**:

* [SkipCash API integration](https://dev.skipcash.app/doc/api-integration/)
* [SkipCash authentication](https://dev.skipcash.app/doc/authentication/)
* [SkipCash transaction details](https://dev.skipcash.app/doc/api-integration/get-transaction-details/)
* [SkipCash webhooks](https://dev.skipcash.app/doc/web-hooks/)
* [SkipCash PHP example](https://dev.skipcash.app/doc/api-integration/php/)
