# Petty Cash services

## Overview

This area owns petty-cash wallets, issues, voids, reconciliation, balances, and staged spreadsheet imports. These workflows affect wallet capacity and AP records, so deterministic idempotency, transaction boundaries, ordered locks, and auditable corrections are required.

## Key files

| File | Owns |
|---|---|
| `PettyCashImportService.php` | Coordinates import validation, staging, and commit. |
| `PettyCashImportValidator.php` | Validates staged rows and business constraints. |
| `PettyCashImportCommitter.php` | Commits valid rows and downstream effects. |
| `PettyCashIssueService.php` | Creates petty-cash issues. |
| `PettyCashIssueVoidService.php` | Voids issues without erasing history. |
| `PettyCashReconciliationService.php` | Reconciles wallet activity. |
| `PettyCashWalletService.php` | Manages wallet rules and available capacity. |

## Conventions

- Keep imports staged: validate and review first, then commit the accepted batch transactionally.
- Derive and preserve the import idempotency key from the source content; enforce uniqueness within the company.
- Lock the import batch, suppliers, wallets, and affected invoices in the established order before applying side effects.
- Recheck wallet capacity and referenced AP state inside the commit transaction, not only during validation.
- Use void and reconciliation workflows instead of deleting or rewriting historical issues.
- Preserve company and branch scope on batches, wallets, suppliers, invoices, and resulting audit records.

## Gotchas

- Retrying the same source file must not create a second batch or duplicate AP and wallet effects.
- Spreadsheet and ZIP input is untrusted. Reuse the repository's safe readers and do not add an unrestricted extraction path.
- Validation can become stale between staging and commit; committers must revalidate mutable constraints under lock.
- Inspect Petty Cash feature tests and the safe spreadsheet reader tests before changing import parsing or commit behavior.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
