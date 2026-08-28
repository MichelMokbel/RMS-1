# Purchasing and supplier references

## Overview

This area owns purchase order persistence, numbering, approval, cancellation, and receiving. Receiving connects supplier purchasing to stock, AP drafts, job costs, and accounting audit history.

## Key files

| File | Owns |
|---|---|
| `PurchaseOrderPersistService.php` | Purchase order and line persistence. |
| `PurchaseOrderWorkflowService.php` | Approval and cancellation. |
| `PurchaseOrderReceivingService.php` | Receipt history, stock costs, and AP draft creation. |
| `PurchaseOrderNumberService.php` | Purchase order numbering. |
| `app/Services/SupplierReferenceChecker.php` | Supplier references across purchasing, AP, inventory, and petty cash. |
| `resources/views/livewire/suppliers/` | Supplier maintenance UI. |

## Conventions

* Receiving accepts approved purchase orders, locks the order and receipt lines, rejects excess quantities, and records each receipt with its supplied date.
* A partially received purchase order remains approved. Full receipt moves it to received and may create one draft AP invoice.
* Weighted inventory cost uses stock across branches while the quantity increase belongs to the purchase order branch.
* You can trace approval, cancellation, receiving, and revisions separately. Cancellation is rejected after receipt activity.

## Gotchas

* An automatic AP draft is not a posted payable. AP posting has its own supplier, matching, period, and ledger checks.
* Supplier deletion decisions depend on references outside purchasing. `SupplierReferenceChecker` and `app/Services/AP/SupplierAccountingPolicyService.php` cover different concerns.
* Relevant tests are `tests/Feature/PurchaseOrders/`, `tests/Feature/Suppliers/`, and `tests/Feature/Accounting/PurchaseOrderMatchingAndAccrualsTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
