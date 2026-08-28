# Pastry orders

## Overview

Pastry orders have their own records, images, numbering, and totals, with a link into AR invoicing. You can keep them distinct from ordinary orders even though both use menu items and customers.

## Key files

| File | Owns |
|---|---|
| `PastryOrderCreateService.php`, `PastryOrderUpdateService.php` | Header, item snapshot, and image changes. |
| `PastryOrderTotalsService.php` | Decimal line and order totals. |
| `PastryOrderNumberService.php` | Number allocation through the shared sequence service. |
| `PastryOrderImageService.php` | Image storage, removal, and signed URLs. |
| `app/Models/PastryOrder.php` | Lifecycle helpers and invoice relationship. |
| `resources/views/livewire/pastry-orders/` | Pastry order UI. |

## Conventions

* Editing rejects invoiced pastry orders. You can trace invoice conversion through `app/Services/AR/ArInvoiceService.php`.
* Item names, prices, and customer details are snapshots. Totals use the pastry service's decimal precision rather than AR integer fields.
* Updates replace item rows and scope image removals to the current pastry order.
* Image storage is an external filesystem effect. A database rollback alone does not undo uploaded or deleted objects.

## Gotchas

* `sales_order_number` is separate from generated pastry numbering. These identifiers are not interchangeable.
* Storage settings and signed URLs are handled by the image service, not by directly exposing an arbitrary stored path.
* No dedicated PastryOrders test suite was found in this audit. AR invoicing and report tests are adjacent references, so new behavior needs direct pastry coverage.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
