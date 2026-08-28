# Accounts payable and expense documents

## Overview

This area owns supplier bills, expense creation, posting, payments, allocations, corrections, and recurring bills. It connects purchasing and Spend to accounting, banking, and job costs.

## Key files

| File | Owns |
|---|---|
| `ApInvoicePostingService.php` | Totals, supplier policy, matching, period gates, and posting effects. |
| `ApAllocationService.php` | Payment creation, allocations, and payment replay validation. |
| `ApInvoiceVoidService.php`, `ApPaymentVoidService.php` | Voids and downstream reversal effects. |
| `PaidExpenseCorrectionService.php` | Audited correction of paid expenses. |
| `ApChequeClearanceService.php` | Cheque clearance and its reversal. |
| `PurchaseOrderInvoiceMatchingService.php`, `SupplierAccountingPolicyService.php` | Receipt matching and supplier accounting controls. |
| `RecurringBillService.php`, `ExpenseCategoryService.php` | Recurring documents and expense categories. |

## Conventions

* AP totals and allocations use decimal amounts, unlike AR integer amount fields. You can use the AP totals and status services to keep balances consistent.
* Posting requires a draft with a supplier and lines. It checks period controls, purchase order matching, and supplier policy before the transaction completes.
* Payment creation coordinates allocations, subledger entries, bank transactions, and audit history. A supplied `client_uuid` is checked against the existing payment request.
* Posted records use void, revision, or paid expense correction workflows. Active allocation uniqueness is enforced in migrations as well as application code.

## Gotchas

* Issuing a cheque and clearing it at the bank are separate events. Bank transfer payments also require an appropriate mapped bank account.
* Canonical Spend expenses are AP invoices with an expense profile, not legacy `expenses` records.
* Relevant tests are in `tests/Feature/AP/`, `tests/Feature/Spend/`, and `tests/Feature/Accounting/`. Recurring dispatch lives in `app/Jobs/Accounting/GenerateRecurringBillTemplateJob.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
