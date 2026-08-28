# Repository context audit

Date: 2026-08-28. Mode: the repository audit skill's gap filling workflow.

## Outcome

The root [AGENTS.md](../../AGENTS.md) now indexes all service areas and shared application context. Existing guidance was preserved, with additions for missing boundaries. New guides have sibling `CLAUDE.md` pointers and the audit provenance line.

This is a source based context audit, not a security certification, exhaustive code review, or proof that every business rule is implemented. No application code, database records, credentials, provider configuration, or integration scope was changed.

## Coverage

| Area | Result |
|---|---|
| Service modules | All 31 current service directories have a guide, including the five existing guides. |
| New service guides | 26 guides for previously undocumented service areas. |
| Shared context | Five guides for application wiring, routes, views, database, and tests. |
| Root index | 36 nested context links, each listed once. |
| UI modules | Every top level Volt folder and page is mapped in [the UI guide](../../resources/views/AGENTS.md). |
| Existing guides | Accounting, HR, POS, Quotations, and Petty Cash received additions without replacing their original guidance. |
| Planned integration | The existing [scope](../scope/scope.md) was read, not changed. Its recorded Tracer Bullet choice fills the root build approach placeholder. |

Small catalog and administrative screens share meaningful context rather than receiving a file for every folder. Categories map to Menu, suppliers to Purchasing, kitchen to Orders and Daily Dish, and settings and dashboard to the UI guide. Budgets, job costing, periods, and account mappings remain in Accounting. HR submodules remain in HR.

## What the scan checked

The scan compared the root and five existing guides against manifests, route groups, bootstrap wiring, providers, representative services, models, migrations, test configuration, CI workflows, and feature test contracts. A single read only scout checked existing guides and shared boundaries. The main agent read the governing skill files and wrote all documentation.

The current implementation includes several boundaries worth carrying into later planning:

* Company Food owns independent project, employee list, option, and order records. It does not feed the ordinary order or subscription workflow automatically.
* Canonical Spend expenses are AP invoices plus expense profiles. The legacy expense API returns HTTP 410.
* Portal order replay uses a user scoped cache with a 600 second lifetime. It is not a durable payment event inbox.
* AR and POS integer amount fields, AP decimal amounts, and marketing spend micros are different representations.
* Subscription generation and invoice based usage are distinct paths. Invoice events and payment links can affect meal counts.
* Statement imports are bank evidence, not a second source payment. AP cheque clearance and AR card or cheque settlement are separate lifecycle events.
* Petty Cash imports now support bank funding and category review, with AP, Spend, Banking, and Accounting effects.
* Provider contracts already exist for structured AI, phone verification, and HR document scanning. Marketing synchronization has separate account and date based jobs.

## Existing wording proposed for review

The audit skill preserves curated text when code and wording diverge. These proposals were not substituted into the existing guides. You can accept the wording changes or retain a statement as an intended engineering rule, with implementation work scoped separately.

### Accounting context description

The key file table in [the Accounting guide](../../app/Services/Accounting/AGENTS.md) says the context service resolves the active company and branch. [AccountingContextService.php](../../app/Services/Accounting/AccountingContextService.php) resolves company, period, and default bank identifiers, including explicit identifier and default fallbacks. It does not resolve actor branch access or authorize the actor.

Proposed description: `Resolves company, accounting period, and default bank identifiers. Callers own authorization and branch access checks.`

### POS authentication statement

[The POS guide](../../app/Services/POS/AGENTS.md) says POS APIs require Sanctum plus `pos.token`. [routes/api.php](../../routes/api.php) places setup and login outside that nested group. Terminal status uses Sanctum without `pos.token`. The operational group has both.

Proposed wording: `Operational POS APIs use Sanctum plus pos.token. Setup and login use their controller credential checks, and terminal status has its own Sanctum authenticated route. You can preserve each boundary when changing the protocol.`

### POS numbering statement

The same guide directs receipt and order numbering only through `PosSequenceService`. [PosSequenceService.php](../../app/Services/POS/PosSequenceService.php) reserves terminal and date ranges, while [PosCheckoutService.php](../../app/Services/POS/PosCheckoutService.php) uses the shared `DocumentSequenceService` for sale numbers. The blanket wording can obscure that second path.

Proposed wording: `POS reservation uses PosSequenceService, while checkout sale numbers use DocumentSequenceService. You can preserve each existing namespace and format rather than substituting one allocator for the other.`

## Standards versus observed implementation

Existing instructions about locking and isolation remain engineering requirements. They are not evidence that every path already satisfies those requirements.

* HR employee scope permits self and direct manager paths after permission checks. Payroll applies broader company and result branch checks. The new HR notes name these distinctions without weakening access requirements.
* Quotation finalization locks the quotation and compensates for artifact failures. Revision starts with an update before the draft service acquires its explicit row lock. Concurrency guarantees need a targeted review if a new integration uses revision.
* Petty Cash import locking follows batch, company, category resolution, suppliers, and the selected bank account or wallets. New AP records are then created. The added guide records this path without turning the older lock guidance into a claim about every row.

## Coverage and operational limits

No dedicated OrderSheet, PastryOrders, Pricing, or Sales test directories were found. Some nearby behavior is covered by order, AR, subscription, POS, and report tests. That is not proof of complete module coverage. New integration work can add direct behavior tests at the boundary it uses.

The public Company Food route group is intentionally unthrottled in current code. Order sheet publication updates linked order items and has its own branch fallback. These are current compatibility and review considerations, not changes authorized by this audit.

The daily dish and subscription API group uses Sanctum separately from the main internal API role groups. You can review its actual middleware, controller validation, and record scope before exposing it through a new integration, rather than assuming it inherits backoffice permissions.

The tracked scope already describes customer payments and promotions, including planned SkipCash work. This audit does not mark that plan implemented, choose new accounting behavior, or create an architecture spec.

## Verification

Documentation checks passed:

* All 31 service areas have context, and all 34 top level Volt modules appear in the UI map.
* All 36 nested guides are indexed once at the root. Including the root, all 37 guides have a sibling `CLAUDE.md` import.
* All 140 local Markdown links resolve, including the runtime stack anchor.
* All 415 checked source and test path references resolve. The check covers concrete paths in the nested guides, not symbolic names or illustrative commands.
* The original lines in the root and five existing guides are preserved in order, apart from the permitted build approach placeholder replacement. The replacement matches the scope exactly.
* `git diff --check` passed. Final status review keeps this audit's changes limited to Markdown.

Application tests, PHP formatting, asset builds, migrations, operational repair commands, and live provider requests were not run. The changes are Markdown only. Existing local lockfile changes, the deleted skill, database artifact, spreadsheets, and desktop metadata were left untouched.
