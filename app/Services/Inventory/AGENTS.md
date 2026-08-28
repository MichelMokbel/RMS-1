# Inventory, stock, and transfers

## Overview

This area owns inventory item maintenance, branch stock, adjustments, transfers, and landed costs. You can trace every quantity change through inventory transactions and the related ledger workflow.

## Key files

| File | Owns |
|---|---|
| `InventoryStockService.php` | Stock movement validation, locks, and transaction history. |
| `InventoryTransferService.php` | Stock transfers between branches. |
| `InventoryAvailabilityService.php` | Item availability in a branch. |
| `InventoryItemPersistService.php`, `InventoryItemStatusService.php` | Item persistence and lifecycle checks. |
| `LandedCostAllocationService.php` | Cost allocation from landed cost AP documents. |
| `app/Models/InventoryStock.php`, `app/Models/InventoryTransaction.php` | Branch balances and movement records. |

## Conventions

* Stock is held in `inventory_stocks` by inventory item and branch. `InventoryStockService` locks the item and stock row before writing the balance and transaction.
* Movement types are `in`, `out`, and `adjustment`. The service accepts `adjust` as an alias, while adjustments retain their signed quantity.
* Quantities use three decimal places and movement costs use four. `config/inventory.php` controls negative stock behavior.
* You can preserve the supplied transaction date, reference type, reference identifier, actor, and branch when adding a new source of stock movements.

## Gotchas

* Purchase order receiving also writes stock and weighted cost in `app/Services/Purchasing/PurchaseOrderReceivingService.php`. It is another writer, not a call to `InventoryStockService`.
* Recipes and transfers also affect stock. A branch fallback inside a service does not authorize a caller to use that branch.
* Relevant tests are in `tests/Feature/Inventory/`, `tests/Feature/PurchaseOrders/`, and `tests/Feature/Recipes/RecipeProductionTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
