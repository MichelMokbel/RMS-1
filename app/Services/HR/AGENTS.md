# HR services

## Overview

This area owns HR access, employee records, leave, compensation, payroll, alerts, audit history, and staged imports. It handles sensitive employee data and append-only records, so company isolation, explicit permissions, lifecycle boundaries, and traceability are mandatory.

## Key files

| File | Owns |
|---|---|
| `HrAccessService.php` | Central HR access and permission checks. |
| `HrImportService.php` | Spreadsheet validation, staging, and import orchestration. |
| `PayrollCalculationService.php` | Payroll calculation and result generation. |
| `PayrollPostingService.php` | Payroll posting and accounting effects. |
| `PayrollPaymentService.php` | Payroll payment lifecycle. |
| `HrAuditLogService.php` | HR audit event recording. |

## Conventions

- Enforce the specific `hr.*` permission and company scope for every read, mutation, export, and download.
- Preserve the separation between payroll calculation, posting, and payment. Each stage has distinct permissions and side effects.
- Treat models using `app/Models/Concerns/HrAppendOnly.php` as immutable event history. Add a new record instead of updating or deleting an old one.
- Keep imports staged: parse safely, validate all rows, show actionable errors, then commit through the established workflow.
- Preserve actor, time, reason, source, and before/after context in HR audit events.
- Use existing HR number, settings, and access services instead of duplicating identifiers or policy logic.

## Gotchas

- Append-only behavior is enforced in application code and by `database/migrations/2026_08_11_000006_enforce_hr_append_only_records.php`.
- `routes/hr.php` is required at the end of `routes/web.php`, not registered independently. Check both files when changing route groups or middleware context.
- HR alerts run on the scheduler in `bootstrap/app.php`; repeat execution and disabled behavior must remain safe.
- Inspect `tests/Feature/HR/` and `tests/Unit/HR/`, especially safe XLSX and ZIP reader tests, before changing import behavior.

## Documents and broader HR workflows

* `EmployeeService.php`, `CompensationService.php`, `LeaveService.php`, and `PayrollRunService.php` own workflows beyond calculation and payment. You can preserve their distinct preparation, approval, rejection, and reversal actions.
* `EmployeeDocumentService.php` inspects uploads, invokes `Documents/DocumentScanner.php`, quarantines rejected files, stores private versions, and checks checksums at download.
* `config/hr.php` controls document and import limits. The general safe readers and full history import path are separate entry points, not interchangeable parsers.
* `HrAccessService.php` checks permissions before its employee scope rules. Employee self access and direct manager rules differ from payroll, which checks every active company branch and the run's result branches for actors without admin access.
* Related guides are [accounting](../Accounting/AGENTS.md), [database constraints](../../../database/AGENTS.md), and [test isolation](../../../tests/AGENTS.md).

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
