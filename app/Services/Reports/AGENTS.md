# Reports and exports

## Overview

This area owns shared report queries and financial report helpers. Report screens, printing, CSV, PDF, and XLSX routes must describe the same filtered data when you change them.

## Key files

| File | Owns |
|---|---|
| `ReceivablesAsOfReport.php` | Historical receivable balances at a selected date. |
| `OutstandingIssuedChequesReportService.php` | Issued cheque reporting. |
| `UnsettledIncomingReceiptsReportService.php` | Incoming receipts awaiting settlement. |
| `InventoryTransactionsReportQueryService.php` | Inventory movement report data. |
| `ApReportService.php`, `DailyApJournalService.php` | Scoped AP report queries, daily snapshots, delivery claims, regeneration, and sequential numbers. |
| `config/reports.php`, `app/Support/Reports/ReportRegistry.php` | Report categories, route keys, filters, and outputs. |
| `app/Support/Reports/` | CSV, PDF, XLSX, and print helpers. |
| `app/Http/Controllers/Reports/` | Export and print adapters. |

## Conventions

* The report registry is the navigation contract. You can keep route names, filter keys, and supported outputs aligned with it.
* Historical balances use event dates and active allocations, not only current invoice status. Imported balances have specific fallback behavior.
* Company and branch scope apply to totals and exports as well as visible rows.
* Shared queries prevent a screen and its export from drifting. Report data can also live in Accounting, AP, and Spend services.
* A saved daily AP journal belongs to one company and accounting date. Regeneration keeps its document number and never resends a completed or uncertain delivery.
* Opening a live report does not allocate a document number. Daily generation uses the shared company and year sequence inside the same transaction as the saved snapshot.
* Historical AP range delivery generates saved days from oldest to newest, sends one combined PDF, and treats unchanged emailed revisions as already delivered.
* AP journal presentation groups all balanced ledger lines belonging to one subledger entry into one event row. Show both account sides and a single event amount, keep reversals visible with an explicit `VOID` label, and prefer business references over internal source IDs.
* AP journal net totals subtract VOID events separately by transaction type and currency. Gross journal movement includes all events and must not be presented as expense or outstanding balance.

## Gotchas

* `ApplyReportDateDefaults` supplies missing month boundaries for several date keys. Blank filters can therefore have an effective range.
* Voids, credit notes, unapplied payments, and allocation based payment methods need deliberate treatment rather than a universal status filter.
* Relevant suites are `tests/Feature/Reports/`, `tests/Unit/Reports/`, and `tests/Feature/Accounting/`. Existing export tests include data beyond old row caps.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
