# Sales and shared sale totals

## Overview

This area owns sale creation, item snapshots, quantity calculations, and totals. POS checkout and AR conversion add further identity and payment rules outside this service.

## Key files

| File | Owns |
|---|---|
| `SaleService.php` | Open sale mutations, discounts, recalculation, and sale state. |
| `app/Support/Money/MinorUnits.php` | Integer money and quantity conversion. |
| `app/Models/Sale.php`, `app/Models/SaleItem.php` | Sale headers and item snapshots. |
| `app/Services/POS/PosCheckoutService.php` | Checkout, payment, and terminal workflow. |
| `resources/views/livewire/sales/` | Backoffice sales UI. |

## Conventions

* Totals use integer amount fields and `MinorUnits::parsePos` reads `pos.money_scale`. Quantity calculation uses thousandths even though the stored quantity is decimal.
* Adding a menu item and changing a global discount check that the sale is open. Names, SKU, price, and tax are captured on the sale item.
* Adding the same menu item can merge quantities into an existing line. You can check discount and price behavior before changing that contract.
* Recalculation includes active payment allocations, not just item totals.

## Gotchas

* Service branch defaults do not authorize the actor, terminal, or shift. Those checks also belong to the caller and POS workflow.
* The item quantity, price, and discount update methods do not repeat the open sale check. You can retain caller state and access checks when reusing them.
* A backoffice sale and a replayed POS sale do not have the same request boundary. You can read `app/Services/POS/AGENTS.md` before sharing either path.
* Relevant tests include `tests/Feature/POS/PosCheckoutTest.php` and the sales report tests in `tests/Feature/Reports/`. No dedicated Sales test directory exists.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
