# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

RMS-1 is a restaurant and finance management platform built as a Laravel modular monolith (Laravel 12, PHP 8.2+). It covers operations (orders, kitchen, daily dish, subscriptions, inventory), finance (AR, AP, spend approvals, petty cash, ledger), public ordering channels, and offline-first POS synchronization with print-job relay. The UI is Blade + Livewire Volt + Flux + Tailwind (Vite). MySQL/MariaDB is the primary data store; queues default to the database connection.

## Common commands

Development

```bash
# One-shot: install deps, copy .env, key, migrate, build assets
composer setup

# Run server + queue listener + Vite concurrently (preferred dev loop)
composer dev

# Frontend assets
npm run dev        # Vite dev server
npm run build      # Production build (CI runs this before tests)
```

Testing

```bash
# Full Pest suite (clears config first; enforces *_test DB when using MySQL)
composer test

# Raw Pest
./vendor/bin/pest

# Single file / single test
./vendor/bin/pest tests/Feature/Orders/CreateOrderTest.php
./vendor/bin/pest --filter="posts balanced journal entry"

# Only one suite (names come from phpunit.xml)
./vendor/bin/pest --testsuite=Unit
./vendor/bin/pest --testsuite=Feature
```

`tests/TestCase.php` refuses to run if `bootstrap/cache/config.php` exists or if the MySQL `DB_DATABASE` does not end in `_test` — run `php artisan config:clear` and point tests at a dedicated `*_test` schema (see `phpunit.xml`, which sets `DB_DATABASE=store_test`). Feature tests auto-wrap in `RefreshDatabase` via `tests/Pest.php`.

Lint / style

```bash
./vendor/bin/pint          # Laravel Pint (also run in CI lint.yml)
```

Artisan utilities registered for this project (see `bootstrap/app.php`)

```bash
php artisan users:hash-passwords
php artisan subscriptions:generate-orders           # run daily at config('subscriptions.generation_time') when auto-gen enabled
php artisan accounting:generate-recurring-bills     # scheduled daily at 01:00
php artisan pos:prune-print-stream-events --hours=24 # scheduled hourly
php artisan finance:lock-date
php artisan integrity:audit
php artisan fks:reapply-safe
php artisan menu-items:backfill-branches
php artisan menu-items:export-missing-arabic
php artisan menu-items:import-arabic-names
php artisan daily-dish:import-menu-from-form
php artisan db:restore-from-dump
php artisan help:seed-demo
php artisan help:capture-screenshots
php artisan ar:repair-cross-company-allocations
```

## High-level architecture

Entry points. HTTP routes are split across `routes/web.php` (Blade/Livewire back-office, ~1400 lines — large because it hosts many ad-hoc admin/utility endpoints alongside normal Livewire Volt page routes), `routes/api.php` (internal JSON APIs, POS, and public channels), and `routes/console.php`. `bootstrap/app.php` is the single source of truth for middleware aliases, scheduled tasks, and the artisan command registry — update it when adding new commands/middleware rather than relying on auto-discovery.

Layered structure. Controllers (`app/Http/Controllers/`, with `Api/` subtree grouped by domain) and Livewire Volt components (`resources/views/livewire/<domain>/`) are thin. Business logic lives in domain services under `app/Services/<Domain>/` (AP, AR, Accounting, Banking, CompanyFood, Customers, DailyDish, Finance, Inventory, Ledger, Menu, Orders, POS, PastryOrders, PettyCash, Pricing, Purchasing, Recipes, Reports, Sales, Security, Sequences, Spend, Subscriptions). Each domain typically has a small family of cohesive services (e.g. `*PersistService`, `*QueryService`, `*WorkflowService`, `*TotalsService`) — prefer extending the existing service rather than adding logic to a controller or model.

Authorization stack. Three layers combine: Spatie roles/permissions (`role`, `role_or_permission` middleware), `EnsureBranchAccess` + `ResolveAllowedBranches` (branch-scoped data access for non-admins via `user_branch_access`), and Fortify/Sanctum for auth. Extra aliases are defined in `bootstrap/app.php`: `active`, `ensure.admin`, `ensure.active-branch`, `pos.token`, `reports.default-dates`, `customer.portal`, `customer.phone.verified`, `reject.customer.backoffice`. Customer-portal users (`auth:sanctum` + `customer.portal`) are a separate identity surface from back-office users and must be kept out of back-office routes via `RejectCustomerBackofficeAccess`.

POS subsystem. `/api/pos/*` is an offline-first contract: bootstrap, sequence reservation (per-terminal + business-date uniqueness), sync (idempotent by `client_uuid` on `pos_sync_events`), and a print-job relay that is terminal-bound with per-job claim tokens. Relevant services live in `app/Services/POS/`; do not bypass `EnsurePosToken`, and preserve idempotency + row locking on any changes to sync or print handlers.

Accounting subsystem. See `AGENTS.md` for the mandatory workflow. Posting goes through `JournalEntryService` / `GlBatchPostingService` / `SubledgerService`; period gating uses `AccountingPeriodGateService`. Posted entries are immutable — corrections happen via reversal/adjustment, never by mutating history. Balances should be derived from journal lines rather than direct-mutated. AR admin-only payment deletion flows through `ArPaymentDeleteService` and emits reversals.

Data layer. 114+ migrations; `schema.sql` and `prod-schema.sql` exist as snapshots but may lag migrations — treat migrations as the source of truth. Models in `app/Models/` are flat (no subfolders) and cover all domains. Seeders/factories under `database/seeders` and `database/factories`.

Frontend. Blade + Livewire Volt pages under `resources/views/livewire/<domain>/`. Flux components require private Composer credentials (`composer.fluxui.dev` http-basic) — CI injects `FLUX_USERNAME` / `FLUX_LICENSE_KEY` before `composer install`. Vite entry points are `resources/css/app.css` and `resources/js/app.js`.

External integrations. Gemini (`app/Services/Ai/`) for the Help bot, AWS SNS for customer phone verification, optional S3, Google reCAPTCHA. All are env-driven — see `.env.example` for the full surface (POS receipt branding, spend thresholds, customer verification tunables, Nightwatch flags, etc.).

## Project-specific rules

Accounting-related work (chart of accounts, journals, ledger, invoices, bills, payments, allocations, taxes, bank/cash, fiscal periods, reconciliation, financial reports, inventory-accounting integration, fixed assets, adjustments, reversals, write-offs) must follow `AGENTS.md`: analyze existing behavior first, state correct accounting treatment explicitly, respond using the Current behavior / Business event / Correct accounting treatment / Gaps / Proposed changes / Validation structure, and only implement once the treatment is explicit. Posted entries are immutable; every posted transaction must balance; never silently rewrite or delete posted records; prevent duplicate posting and posting into closed periods.

Responsive UI. The PR template (`.github/pull_request_template.md`) enforces a responsive checklist: no horizontal overflow at 360px, usable at 768px, preserved density at 1024px+, 44px minimum touch targets, dark-mode verification, and mobile card view or intentional horizontal scroll for tables. Surface these considerations when editing Livewire/Blade views.

CI expectations (`.github/workflows/`). Pint must pass on push/PR to `develop` or `main`; Pest runs on PHP 8.4 + Node 22 after `npm run build`. Keep `composer test` green and style clean before handing off.

## Additional documentation

`docs/RMS-1_Complete_Documentation.md` contains the full module/architecture reference. Domain-specific specs also live in `docs/`: `iam.md`, `pos-api.md`, `pos-print-sse-relay.md`, `pos-terminal-registration-api.md`, `pos_server_areas_tables_requirements.md`, `COMPANY_FOOD_API.md`, `frontend-customer-dashboard-integration.md`, `frontend-daily-dish-customer-auth-integration.md`, `company_food_menu_days_grid.md`. Consult these before designing cross-module changes.
