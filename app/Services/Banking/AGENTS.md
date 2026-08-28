# Banking and reconciliation

## Overview

This area owns statement imports, book bank transactions, and reconciliation runs. You can distinguish imported statement evidence from transactions produced by AP, AR, and other internal workflows.

## Key files

| File | Owns |
|---|---|
| `BankStatementImportService.php` | CSV normalization, duplicate checks, and imported statement lines. |
| `BankTransactionService.php` | Bank effects created or voided by source documents. |
| `BankReconciliationService.php` | Matching, exceptions, closing, reopening, and summaries. |
| `app/Models/BankTransaction.php`, `app/Models/BankReconciliationRun.php` | Transaction state and reconciliation records. |
| `app/Http/Controllers/Api/Accounting/BankingController.php` | API validation and access boundary. |

## Conventions

* Statement lines have a `statement_import_id`; source book transactions have their own source identity. Importing a bank line is not recognition of a second payment.
* Duplicate statement detection uses bank account, transaction date, amount, direction, and reference.
* Matching connects statement and book lines. The workflow maintains counterpart links and audit events.
* Closing requires all statement lines to be matched, no exceptions, and zero variance at the service's precision.

## Gotchas

* Import metadata and stored files are created outside the row import transaction. A failed import is not guaranteed to remove the uploaded file.
* Company ownership, bank account mapping, historical dates, and period rules also depend on the calling AP or AR workflow.
* Relevant tests are `tests/Feature/Accounting/BankStatementImportAndReconciliationTest.php`, `tests/Feature/Accounting/BankTransactionIntegrationTest.php`, `tests/Feature/AP/ApChequeClearanceTest.php`, and `tests/Feature/AR/ArClearingSettlementTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
