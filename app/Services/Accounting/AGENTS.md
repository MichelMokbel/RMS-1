# Accounting services

## Overview

This area owns accounting context, period controls, journal workflows, reporting, budgets, and account mappings. Changes here can alter financial statements and audit history, so preserve balanced entries, immutability, company scope, and closed-period rules.

## Key files

| File | Owns |
|---|---|
| `AccountingContextService.php` | Resolves the active company, branch, and accounting context. |
| `AccountingPeriodGateService.php` | Enforces period and finance lock-date gates. |
| `JournalEntryService.php` | Creates, posts, reverses, and validates journals. |
| `AccountingPeriodCloseService.php` | Coordinates period closing and reopening workflows. |
| `AccountingAuditLogService.php` | Records accounting audit events. |

## Conventions

- Resolve company and branch context through the established context service; never trust request identifiers by themselves.
- Keep debits and credits balanced and preserve source-event idempotency.
- Use database transactions for multi-record accounting workflows and lock mutable rows before recalculating or transitioning them.
- Check accounting periods and finance lock dates before posting, reversing, or mutating dated financial records.
- Treat posted journals as immutable. Correct them through the established reversal or correction workflow.
- Keep general-ledger, subledger, source-document, and audit effects consistent in one workflow.

## Gotchas

- Journal immutability is also enforced by database triggers in `database/migrations/2026_04_19_000005_add_journal_entries_immutability_trigger.php`.
- Company scope and branch scope are different. Confirm which one owns each balance, journal, report, and permission check.
- Inspect related services under `app/Services/Ledger/` and `app/Services/Banking/` plus tests under `tests/Feature/Accounting/` and `tests/Feature/Ledger/` before changing shared behavior.

## Additional module boundaries

* `BudgetService.php` owns budget versions, activation, locks, CSV import, and variance. `JobCostingService.php` owns jobs, phases, cost codes, budgets, and source cost reversals.
* `AccountingReportService.php`, `AccountingPeriodChecklistService.php`, and `DashboardCashActivityService.php` are separate report and close workflow entry points.
* `LedgerAccountMappingService.php` supplies company mappings used by AP, AR, banking, and ledger posting. You can inspect those consumers when changing a mapping.
* Related guides are [ledger](../Ledger/AGENTS.md), [banking](../Banking/AGENTS.md), [finance settings](../Finance/AGENTS.md), and [reports](../Reports/AGENTS.md).
* The [audit report](../../../docs/audits/2026-08-28-context-audit.md) proposes a clarification to the existing context service description without changing that curated line.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
