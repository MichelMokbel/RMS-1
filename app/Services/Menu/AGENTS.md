# Menu catalog and branch availability

## Overview

This area owns menu item codes, branch availability, and reference checks. Catalog categories and recipe links also shape what orders, daily menus, and POS can sell.

## Key files

| File | Owns |
|---|---|
| `MenuItemCodeService.php` | Menu item code allocation. |
| `MenuItemBranchAvailabilityService.php` | The `menu_item_branches` availability mapping. |
| `MenuItemAvailabilityQueryService.php` | Availability defaults, listing, and branch labels. |
| `MenuItemUsageService.php` | Checks for existing menu item references. |
| `app/Models/MenuItem.php`, `app/Models/Category.php` | Catalog data and relationships. |
| `resources/views/livewire/menu-items/`, `resources/views/livewire/categories/` | Catalog maintenance UI. |

## Conventions

* You can trace `app/Http/Controllers/Api/MenuItemController.php` alongside the Volt pages because API and web permissions are not identical.
* Global item activity and branch availability are separate. Availability uses pivot rows, not separate menu item copies.
* Existing item descriptions and prices are copied into sales and order snapshots. Catalog changes do not mean historical snapshots should change.
* `MenuItemUsageService` is the reference check for deactivation. Categories are catalog categories, not AP expense categories.

## Gotchas

* `MenuItemAvailabilityQueryService::ensureDefaultsForBranch` writes missing pivot rows despite living in a query service.
* API consumers use a light listing and token order independent search. You can preserve these contracts with `tests/Feature/MenuItems/`.
* Category behavior is covered in `tests/Feature/CategoriesTest.php`. Recipe composition has its own guide in `app/Services/Recipes/AGENTS.md`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
