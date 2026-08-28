# Daily dish menus and preparation

## Overview

This area owns dated branch menus and preparation queries. These menus feed manual orders, subscription generation, the order sheet, and public menu selection.

## Key files

| File | Owns |
|---|---|
| `DailyDishMenuService.php` | Draft editing, cloning, publishing, and reverting menus. |
| `DailyDishMenuEditQueryService.php` | Menu editor data. |
| `DailyDishOpsQueryService.php` | Operations data and preparation totals. |
| `app/Support/DailyDish/` | Menu slots and edit validation. |
| `resources/views/livewire/daily-dish/`, `resources/views/livewire/kitchen/` | Menu and kitchen UI. |

## Conventions

* A menu belongs to a branch and service date. Only a draft can be edited or used as a clone destination.
* Publishing requires exactly three mains, one salad, and one dessert. The service checks both total item count and roles.
* Preparation totals include the relevant confirmed and production work, with subscription and manual filters. You can preserve the query service's selection rules.
* Pricing and subscription item selection are owned outside this directory.

## Gotchas

* Reverting a published menu does not currently check whether subscription order mappings already depend on it. You can evaluate existing consumers before adding an integration that relies on menu immutability.
* Roles used for pricing can differ from the stricter publishing slots. `app/Services/Pricing/` and `app/Services/Orders/` hold those consumers.
* Relevant tests are in `tests/Feature/DailyDishMenus/`, `tests/Feature/DailyDishOps/`, and `tests/Feature/Orders/ManualDailyDishRequiresMenuTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
