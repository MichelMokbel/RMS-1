# Recipes, costing, and production

## Overview

This area owns recipe composition, costing, persistence, and production. Production turns nested ingredients into inventory deductions, so quantity units and recipe yield are important when you change it.

## Key files

| File | Owns |
|---|---|
| `RecipeCompositionService.php` | Ingredient normalization, cycle checks, and recursive expansion. |
| `RecipeCostingService.php` | Ingredient and overhead costing. |
| `RecipePersistService.php` | Recipe and line persistence. |
| `RecipeProductionService.php` | Production history and inventory deductions. |
| `app/Support/Recipes/` | Form and production validation rules. |

## Conventions

* Recipe lines represent inventory items or sub recipes. Composition validation rejects direct and indirect cycles.
* Production scales ingredients by produced quantity divided by recipe yield. Draft recipes and invalid yields cannot be produced.
* Unit quantities convert to package quantities using `units_per_package`. Deductions are aggregated by inventory item and rounded to three decimal places.
* Production records and deductions share a transaction. `InventoryStockService` owns stock movements and the final negative stock check.

## Gotchas

* Production checks stock for the selected branch. A fallback branch identifier is not proof of actor access.
* Nested recipes can contribute the same inventory item through several paths. You can keep costing and production expansion consistent by reusing the composition service.
* Relevant tests are `tests/Feature/Recipes/RecipeCostingTest.php`, `tests/Feature/Recipes/RecipeProductionTest.php`, and the CRUD and authorization tests in the same directory.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
