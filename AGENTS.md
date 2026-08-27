# RMS-1 Agent Guide

This file is the operating contract for AI coding agents working in this repository. It is intentionally specific to RMS-1. Follow it before generic framework advice or tool defaults.

## 1. Mission and priorities

RMS-1 is a production restaurant operations, finance, accounting, POS, customer portal, marketing, quotations, and HR/payroll platform. Changes can affect money, stock, payroll, permissions, and audit history.

Optimize in this order:

1. Correctness and preservation of business invariants.
2. Security, authorization, tenant/branch isolation, and auditability.
3. Backward compatibility and data integrity.
4. Tests and operational observability.
5. Maintainability and consistency with existing code.
6. Delivery speed.

Do not trade a higher priority for a lower one without making the tradeoff explicit.

## 2. Instruction scope and sources of truth

- This root `AGENTS.md` applies to the entire repository. A deeper `AGENTS.md`, if introduced, overrides it only for that subtree.
- The current source code, dependency manifests, migrations, and tests are authoritative.
- `docs/RMS-1_Complete_Documentation.md`, `schema.sql`, `prod-schema.sql`, SQL dumps, PDFs, and CSV/XLSX samples are useful references but may lag the application.
- `CLAUDE.md` is a compatibility pointer, not a second source of engineering rules.
- When documentation and code disagree, verify behavior in routes, services, migrations, and tests; then update stale documentation when it is in scope.
- Never infer production configuration from `.env.example` defaults.

## 3. Repository reality

### Runtime stack

- Backend: PHP 8.2+, Laravel 12.
- Web UI: Blade, Livewire Volt, Flux UI, Tailwind CSS 4.
- Assets: Vite 7 and Node.js.
- Authentication: Fortify for web sessions; Sanctum for API tokens.
- Authorization: Spatie Permission plus custom branch, customer-portal, active-user, and POS middleware.
- Database: MySQL/MariaDB in normal and CI usage; limited SQLite compatibility may exist.
- Async work: Laravel queues, scheduled commands, and jobs.
- Storage: Laravel Filesystems with local/public/S3 options.
- Tests: Pest 4 on PHPUnit, with Laravel `RefreshDatabase` for feature tests.
- Quality: Laravel Pint and the Vite production build.

### Important entry points

- `bootstrap/app.php`: routing, middleware aliases, scheduled work, commands.
- `routes/web.php`: backoffice and browser routes.
- `routes/api.php`: internal, public, customer portal, and POS APIs.
- `routes/hr.php`: HR routes; loaded by `routes/web.php`.
- `app/Http/Controllers/`: HTTP adapters and export/download endpoints.
- `app/Http/Requests/`: reusable boundary validation.
- `app/Services/`: business workflows and cross-model coordination.
- `app/Models/`: Eloquent models, relations, scopes, and casts.
- `resources/views/livewire/`: route-driven Volt pages containing component state and UI.
- `resources/views/components/`: shared UI/layout components.
- `config/`: module behavior and policy configuration.
- `database/migrations/`: canonical schema evolution.
- `database/seeders/`: roles, permissions, defaults, and reference data.
- `tests/Feature/` and `tests/Unit/`: executable behavior contracts.

### Domain map

| Domain | Primary locations |
|---|---|
| IAM and branch access | `app/Services/Security`, auth middleware, IAM Volt pages |
| Customers and customer portal | `app/Services/Customers`, customer API controllers |
| Catalog, menu, recipes | menu/recipe models, services, and Volt pages |
| Orders, kitchen, daily dish | `app/Services/Orders`, `DailyDish`, kitchen/order pages |
| Subscriptions and company food | subscription services, public APIs, scheduled generation |
| Inventory and purchasing | `app/Services/Inventory`, `Purchasing` |
| AP, spend, and petty cash | `app/Services/AP`, `Spend`, `PettyCash` |
| AR and receivables | `app/Services/AR`, receivables controllers/pages |
| Accounting, ledger, banking | `app/Services/Accounting`, `Ledger`, `Banking` |
| POS, shifts, sync, printing | `app/Services/POS`, `/api/pos/*` |
| Quotations and document templates | `app/Services/Quotations`, quotation pages/controllers |
| Marketing | `app/Services/Marketing`, marketing jobs/pages |
| HR, leave, payroll, imports | `app/Services/HR`, `routes/hr.php`, HR pages/controllers |
| Reports and exports | `app/Services/Reports`, report controllers, `config/reports.php` |
| Help and AI provider | `app/Services/Help`, `app/Services/Ai` |

Before changing a domain, inspect its service directory, models, routes, migrations, and closest tests. Search for all writers of the affected tables or state fields.

## 4. Standard agent workflow

### A. Orient before editing

1. Read this file and any nearer `AGENTS.md`.
2. Check `git status --short`; preserve all user changes and unrelated work.
3. Identify the smallest affected domain and trace the full request-to-persistence path.
4. Read the nearest feature tests before deciding behavior.
5. Search for analogous workflows and reuse their conventions.
6. State assumptions when requirements leave materially different valid behaviors.

Do not start by generating code from the request alone.

### B. Plan around invariants

For non-trivial work, identify:

- actors, roles, and permissions;
- company and branch scope;
- valid states and transitions;
- transaction and locking needs;
- money, quantity, date, and timezone representation;
- idempotency/retry behavior;
- audit/logging requirements;
- API/UI compatibility;
- migrations, backfills, queues, schedules, storage, and deployment impact;
- tests that prove both success and rejection paths.

Prefer the smallest coherent change. Avoid opportunistic refactors unless they directly reduce the risk of the requested change.

### C. Implement in repository style

- Keep controllers and Volt actions focused on authorization, validation, orchestration, and response/UI state.
- Put reusable business rules and multi-model workflows in domain services.
- Use Eloquent relationships/scopes and existing query services instead of duplicating query logic.
- Use constructor injection for service dependencies.
- Reuse Form Requests when validation is shared or API-facing; colocated Volt validation is acceptable for page-specific state.
- Return user-actionable domain errors with `ValidationException::withMessages()` where that is the established pattern.
- Use `__()` for user-visible server-side text unless the surrounding module intentionally does otherwise.
- Follow existing naming, route, permission, event, and audit conventions within the domain.
- Keep diffs focused. Do not reformat unrelated files or silently rename public contracts.

### D. Verify before handoff

1. Run the narrowest relevant tests while iterating.
2. Run all tests in each affected domain.
3. Run formatting on changed PHP files.
4. Run the asset build for UI/JS/CSS changes.
5. Inspect the final diff and status for accidental files, secrets, dumps, and debug output.
6. Report what was tested and anything that could not be tested.

Never claim a test or build passed unless the command completed successfully.

## 5. Architecture and coding rules

### Boundaries and transactions

- Validate at HTTP, import, CLI, job, and public API boundaries.
- Put operations that must succeed or fail together inside `DB::transaction()`.
- For mutable financial, stock, payroll, sequence, reconciliation, or lifecycle records, evaluate whether `lockForUpdate()` is required.
- Do not perform irreversible external side effects inside a database transaction unless the workflow has a deliberate retry/compensation design.
- Queue long-running or retryable provider synchronization when the existing module does so.
- Preserve database constraints as the last line of defense; application validation is not a substitute for unique keys, foreign keys, or check/trigger invariants.

### Models and persistence

- Define explicit fillable/guarded fields and casts.
- Eager-load relationships on list/report paths to avoid N+1 queries.
- Use explicit column selections for high-volume searches and APIs when practical.
- Treat existing soft-delete, void, reversal, and append-only conventions as domain semantics, not interchangeable implementation details.
- Never mass-update financial or audit data without understanding downstream balances, status recalculation, and ledger effects.

### Money, quantities, and dates

- RMS-1 contains both integer minor-unit fields and decimal monetary fields. Follow the affected domain's established representation; do not mix or globally normalize them as part of an unrelated change.
- Respect configured currency and precision. QAR presentation may require three decimal places even where an internal workflow uses another scale.
- Use decimal-safe calculations and existing totals/calculation services. Do not rely on uncontrolled floating-point equality for financial invariants.
- Preserve historical transaction dates. Use `now()` only when the business event truly occurs now.
- Use Carbon/framework date handling and explicit boundaries for reports, accounting periods, validity windows, and schedules.

### APIs and integrations

- Preserve route names, response shapes, status codes, and pagination unless a contract change is explicitly requested.
- Public, customer, POS, and sync endpoints require explicit authentication/throttling/idempotency review.
- Reuse client UUID or source-event keys for retried mutations where the domain already supports them.
- A retry must not duplicate payments, allocations, stock movements, orders, journal/subledger entries, print jobs, or provider imports.
- Redact credentials, tokens, personal data, and provider payloads from logs and errors.
- Use provider interfaces/configuration instead of hardcoding external services.

### Livewire, Blade, and responsive UI

- Treat Volt page classes as presentation/application adapters; move reusable rules into services.
- Preserve query-string filters and pagination behavior on index/report pages.
- Use stable `wire:key` values for dynamic collections.
- Prevent double submissions and surface validation/loading states for mutations.
- Reuse Flux and existing shared components before adding custom UI primitives.
- Maintain dark-mode readability and keyboard-accessible labels/actions.
- Verify responsive behavior at approximately 360 px, 768 px, and 1024 px+.
- Tables must use an intentional mobile card layout or controlled horizontal scrolling.
- Modal/drawer primary actions must remain visible, and touch targets should be at least 44 px.
- Avoid large unrelated frontend rewrites for a backend feature.

## 6. Non-negotiable domain invariants

### Authorization and isolation

- Enforce access server-side. Hidden navigation or disabled buttons are never authorization.
- Preserve active-user checks, role/permission middleware, and direct service-level checks where present.
- Non-admin users are branch-scoped. Scope reads as well as writes, exports, downloads, searches, and aggregate totals.
- Treat `company_id` and `branch_id` as security boundaries. Never accept either blindly from request data.
- Customer portal users must remain isolated from backoffice routes and other customers' records.
- POS access must preserve user, terminal, device, token ability, and branch alignment.
- Sensitive HR data requires the specific HR permissions and isolation rules used by that module.

Every new protected action needs tests for an allowed actor and at least one denied actor. Branch/company-scoped behavior needs cross-branch or cross-company rejection coverage.

### Accounting and finance

- Debits must equal credits for journal posting.
- Closed periods and finance lock dates must block prohibited mutations.
- Posted records are not silently edited or deleted. Use established void, reversal, correction, or revision workflows.
- Preserve subledger and source-event idempotency.
- Payment and allocation changes must recalculate affected balances/statuses and preserve audit history.
- Bank reconciliation, AP cheque clearance, AR clearing, payroll posting, spend settlement, and petty-cash operations must remain transactionally consistent.
- Corrections must not erase the history needed to explain financial statements.

### Inventory, purchasing, orders, and subscriptions

- Stock changes require an inventory transaction trail and correct branch scope.
- Respect negative-stock policy and existing weighted-cost rules.
- PO receiving must keep receipt history, stock updates, PO status, and any AP draft creation consistent.
- Order, item, subscription, quotation, expense, leave, and payroll state transitions must use the owning workflow service.
- Scheduled/retried subscription generation must remain idempotent.

### HR and audit records

- Respect append-only HR records and database triggers.
- Payroll calculation, posting, and payment are distinct lifecycle stages; do not collapse them casually.
- Preserve actor, timestamp, reason, source, and before/after context in existing audit systems.

## 7. Database and migration policy

- Add a new forward migration for schema changes. Do not rewrite a migration that may have run in another environment unless explicitly instructed and proven safe.
- Make migrations safe for the repository's supported MySQL/MariaDB versions.
- Order changes so existing data remains valid before adding restrictive constraints.
- For backfills, define deterministic behavior, batching/locking needs, restart safety, and rollback expectations.
- Add indexes for new foreign keys and demonstrated query patterns; avoid speculative indexes.
- Use foreign keys and unique constraints when they express real invariants.
- Treat raw SQL in `database/sql/`, dumps, and root schema files as deployment/reference artifacts; update them only when the relevant workflow requires it.
- Never run destructive data commands, restoration commands, or production migrations as part of routine verification.

## 8. Testing and quality gates

### Environment safety

- Feature tests use `RefreshDatabase` and `phpunit.xml` currently targets the MySQL database `store_test` on `127.0.0.1`.
- Confirm the test environment before any command that migrates, refreshes, truncates, clears, restores, or seeds data.
- Never point automated tests at development, staging, or production data.
- Do not commit `.env`, Composer credentials, provider keys, generated debug logs, database files, or test artifacts.
- Flux is a private Composer dependency. Use configured credentials; do not remove or replace Flux merely to make installation easier.

### Useful commands

```bash
# Initial setup (requires Composer/Flux credentials and a safe database)
composer install
npm install

# Targeted and full tests
php artisan test tests/Feature/Domain/SpecificTest.php
php artisan test --filter=descriptive_test_name
composer test

# PHP style (prefer targeted/dirty during iteration)
./vendor/bin/pint --dirty

# Frontend production build
npm run build

# Inspect registered routes when dependencies are installed
php artisan route:list
```

Do not use `npm test`; this repository does not define that script.

### Required coverage by change type

| Change | Minimum evidence |
|---|---|
| Authorization | allowed + forbidden actors; branch/company isolation when applicable |
| Lifecycle/state transition | valid transition + invalid transition + persisted side effects |
| Financial mutation | totals/balances + period/lock rules + ledger/subledger/audit effects |
| Retriable/API mutation | first request + exact retry/idempotency behavior |
| Migration | clean migration + representative existing-data path |
| Livewire UI | component/action behavior + validation; responsive manual check for layout work |
| Report/export | filters + scope + totals + voided/reversed handling |
| Scheduled job/command | disabled/no-op behavior + repeat execution safety |

Prefer behavior-focused tests over implementation-detail mocks. Use existing factories, seeders, helpers, and fake providers. Mock true external boundaries, not Eloquent internals.

## 9. Multi-agent work

Use multiple agents only when work can be split into independent, bounded tracks such as domain research, test review, migration review, or security review.

- Give each agent a concrete deliverable and explicit file/domain ownership.
- Avoid concurrent edits to the same files or tightly coupled workflow.
- Share discovered invariants early; do not let agents independently invent conflicting contracts.
- The coordinating agent owns the plan, integrates changes, reviews the combined diff, and runs final verification.
- Do not use delegation as a substitute for reading the affected code yourself.
- For small fixes and single-file changes, work directly.

Repository-provided `.agents/` and `.claude/` assets are optional development aids. They do not override this file, application code, or platform-level instructions.

## 10. Git and change hygiene

- Start from a clean understanding of the worktree; never discard changes you did not create.
- Use a focused branch for non-trivial work unless the user specifies another workflow.
- Keep commits reviewable and scoped. Do not mix generated artifacts or drive-by cleanup with behavior changes.
- Never force-push, rewrite shared history, or push directly to a protected branch without explicit authorization.
- Commit messages should use the repository's conventional style, for example `feat(accounting): add ...` or `fix(hr): prevent ...`.
- Do not add AI attribution or co-author trailers unless the repository owner explicitly requests them.
- Before handoff, review `git diff --check`, `git diff`, and `git status --short`.

## 11. Stop and ask when

Pause for user direction if:

- two plausible interpretations materially change accounting, payroll, stock, permissions, customer behavior, or API contracts;
- the change requires destructive or irreversible data work;
- a required production credential, infrastructure decision, or external coordination is missing;
- an existing migration/data inconsistency cannot be resolved safely from repository evidence;
- tests reveal pre-existing failures that make the requested result ambiguous;
- the requested change conflicts with a security or accounting invariant.

Do not block on minor naming or implementation choices that can be resolved safely from established repository patterns.

## 12. Completion contract

A task is complete only when:

- the requested behavior is implemented end to end;
- relevant authorization, validation, state, and data invariants are preserved;
- targeted tests were added or updated and pass;
- applicable formatting/build checks pass;
- no secrets, debug artifacts, accidental data files, or unrelated edits are included;
- migrations, config, schedules, queues, and deployment implications are documented when applicable;
- the final handoff states the outcome, files/areas changed, verification performed, and any residual risk or unverified step.
