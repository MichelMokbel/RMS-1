# Subledger and general ledger batches

## Overview

This area converts business events into balanced subledger entries and summarizes them into general ledger batches. Source identity and period controls matter when you add a new posting source.

## Key files

| File | Owns |
|---|---|
| `SubledgerService.php` | Source event posting and reversals for finance and inventory workflows. |
| `GlSummaryService.php` | Batch generation and ledger summaries. |
| `GlBatchPostingService.php` | Balanced batch posting and optional period closing. |
| `app/Services/Accounting/LedgerAccountMappingService.php` | Company specific account mapping. |
| `config/ledger.php` | Ledger behavior and defaults. |

## Conventions

* Entries retain source type, source identifier, event, date, company, and dimensions. The source event unique constraint protects against duplicate posting.
* Lines use four decimal places and cannot carry both a debit and a credit. The posting service checks balance before writing.
* Reversals are new entries, not edits to historical lines. You can follow the owning business workflow to preserve source and audit effects.
* Source workflow transactions surround their ledger effects. A ledger entry is not a substitute for completing the source mutation.

## Gotchas

* Posting a GL batch can close its accounting period or advance the finance lock date. It is not just a report state change.
* Posting can return no entry when ledger availability or configuration disables it. You can inspect `canPost` before assuming all environments post identically.
* Relevant tests are `tests/Feature/Ledger/GlBatchPostingTest.php`, `tests/Feature/Accounting/SubledgerIdempotencyTest.php`, and `tests/Feature/Accounting/AccountMappingPostingTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
