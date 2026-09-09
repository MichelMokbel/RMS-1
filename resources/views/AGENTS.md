# Web UI, shared screens, and module map

## Overview

This area contains Volt pages, shared Blade components, report layouts, and document views. You can use the map below to find service context even when a module's code is split between UI actions and services.

## Key files

| File | Owns |
|---|---|
| `livewire/` | Route driven Volt pages and their component actions. |
| `components/layouts/app/sidebar.blade.php` | Shared navigation and module visibility. |
| `components/reports/` | Shared branch, date, and status filters. |
| `components/settings/layout.blade.php` | Settings navigation. |
| `../../routes/web.php` | UI route names and middleware. |
| `../../resources/js/`, `../../resources/css/` | Client behavior and styling. |

## Module context map

All paths in the first column are relative to `resources/views/livewire/`.

| UI area | Context |
|---|---|
| `auth/`, `users/` | [Identity and branch access](../../app/Services/Security/AGENTS.md). |
| `categories/`, `menu-items/` | [Menu catalog](../../app/Services/Menu/AGENTS.md). |
| `customers/` | [Customers and portal accounts](../../app/Services/Customers/AGENTS.md). |
| `recipes/` | [Recipes and production](../../app/Services/Recipes/AGENTS.md). |
| `inventory/` | [Inventory and transfers](../../app/Services/Inventory/AGENTS.md). |
| `suppliers/`, `purchase-orders/` | [Purchasing and supplier references](../../app/Services/Purchasing/AGENTS.md). |
| `orders/` | [Orders](../../app/Services/Orders/AGENTS.md). |
| `storefront/` | [Customer storefront administration, catalog, delivery apps, analytics, and checkout upsells](../../app/Services/Storefront/AGENTS.md). |
| `daily-dish/` | [Daily menus and preparation](../../app/Services/DailyDish/AGENTS.md), [pricing](../../app/Services/Pricing/AGENTS.md). |
| `kitchen/` | [Kitchen transitions](../../app/Services/Orders/AGENTS.md). |
| `order-sheet.blade.php` | [Order sheet publication](../../app/Services/OrderSheet/AGENTS.md). |
| `pastry-orders/` | [Pastry orders](../../app/Services/PastryOrders/AGENTS.md). |
| `subscriptions/`, `meal-plan-requests/` | [Subscriptions and request conversion](../../app/Services/Subscriptions/AGENTS.md). |
| `membership-promotions/` | [Membership promotions](../../app/Services/Promotions/AGENTS.md). |
| `company-food/` | [Company Food](../../app/Services/CompanyFood/AGENTS.md). |
| `sales/` | [Sales](../../app/Services/Sales/AGENTS.md). |
| `pos/` | [POS](../../app/Services/POS/AGENTS.md). |
| `payables/` | [AP bills, payments, categories, and recurring bills](../../app/Services/AP/AGENTS.md). |
| `spend/` | [Expense approval and settlement](../../app/Services/Spend/AGENTS.md). |
| `petty-cash/` | [Wallets, reconciliation, and imports](../../app/Services/PettyCash/AGENTS.md). |
| `receivables/` | [AR invoices and customer receipts](../../app/Services/AR/AGENTS.md). |
| `accounting/` | [Periods, journals, budgets, and jobs](../../app/Services/Accounting/AGENTS.md), [banking](../../app/Services/Banking/AGENTS.md). |
| `ledger/` | [Subledger and GL batches](../../app/Services/Ledger/AGENTS.md). |
| `finance/` | [Finance settings](../../app/Services/Finance/AGENTS.md). |
| `quotations/`, `quotation-templates/` | [Quotations and templates](../../app/Services/Quotations/AGENTS.md). |
| `marketing/` | [Campaigns, assets, briefs, settings, and sync logs](../../app/Services/Marketing/AGENTS.md). |
| `hr/` | [Employees, documents, leave, payroll, imports, reports, settings, and audit](../../app/Services/HR/AGENTS.md). |
| `help/` | [Help content and assistant](../../app/Services/Help/AGENTS.md), [AI provider](../../app/Services/Ai/AGENTS.md). |
| `reports/` | [Reports and exports](../../app/Services/Reports/AGENTS.md). |
| `settings/` | Shared settings described below, [email history](../../app/Services/Mail/AGENTS.md), and relevant accounting or POS context. |
| `dashboard.blade.php` | Shared dashboard described below. |

## Shared settings and dashboard

* Profile, password, appearance, and two factor settings are user settings. Finance, accounting setup, payment terms, POS terminals, organization, and logs are administrative surfaces with separate route gates.
* Organization settings manage accounting companies, branches, departments, and jobs. Those records are security and reporting dimensions, not just display labels.
* The log page has separate operations and email filters and pagination names. Its query and actions check admin access.
* The dashboard aggregates several domains directly in its Volt component. You can trace each metric's permission, branch filter, date range, and void handling rather than assuming one shared scope.

## Conventions

* Volt actions combine state, authorization, validation, and service calls. You can reuse existing services for domain rules while retaining component level checks.
* Shared navigation visibility is not authorization. API, export, download, and component actions need their own existing guards.
* Query string filters, named pagination, stable keys, loading states, and translated messages are part of the UI contract.
* Shared layouts also affect print and document consumers. You can keep responsive and dark mode checks scoped to the screens changed.

## Gotchas

* A service guide does not automatically apply by directory scope to a view. The map makes that ownership explicit.
* Relevant tests are `tests/Feature/DashboardTest.php`, `tests/Feature/Settings/`, `tests/Feature/CategoriesTest.php`, `tests/Feature/Suppliers/`, and each domain's feature suite.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
