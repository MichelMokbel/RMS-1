# Accounts receivable and customer payments

## Overview

This area owns customer invoices, credit notes, payment allocation, voids, and clearing settlements. It links orders, sales, pastry orders, quotations, and subscriptions to customer balances.

## Key files

| File | Owns |
|---|---|
| `ArInvoiceService.php` | Invoice creation, source conversion, issuing, and recalculation. |
| `ArPaymentService.php`, `ArAllocationService.php` | Customer receipts, advances, allocations, and credit note application. |
| `ArAllocationIntegrityService.php` | Payment and invoice company checks and mismatch handling. |
| `ArPaymentDeleteService.php` | Allocation release and payment void workflow. |
| `ArClearingSettlementService.php` | Card and cheque settlement into a bank account. |
| `app/Models/PaymentAllocation.php` | Polymorphic allocation relationships. |

## Conventions

* AR money uses integer `*_cents` fields. You can preserve signs, currency, and the configured scale when converting decimal order totals. Quotation totals already use integer amount fields.
* Invoice recalculation and allocation status changes belong to the services. Payments can leave unapplied customer credit.
* Credit note application uses a zero amount voucher payment with positive and negative allocations. It is not an ordinary incoming cash receipt.
* Allocation company checks use `ArAllocationIntegrityService`. You can trace the specific payment entry point because replay and validation behavior vary by method.

## Gotchas

* `ArPaymentDeleteService` voids financial history rather than simply deleting it. A settled payment requires its clearing settlement to be voided first.
* `InvoiceIssued` affects subscription usage through an automatically discovered listener. Issuing is not only a status update.
* Clearing currently concerns gross card or cheque receipts. The planned gateway fee and payout flow in `docs/scope/scope.md` is not an implemented provider integration.
* Relevant suites are `tests/Feature/AR/`, `tests/Feature/Receivables/`, `tests/Feature/Subscriptions/`, and `tests/Feature/Accounting/`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
