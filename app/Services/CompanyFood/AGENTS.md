# Company Food

## Overview

Company Food is a standalone project catering module with employee lists, dated options, orders, and kitchen exports. It does not use ordinary orders or daily dish subscription records.

## Key files

| File | Owns |
|---|---|
| `CompanyFoodOrderService.php` | Selection validation, order creation, and token checked updates. |
| `app/Models/CompanyFoodProject.php`, `app/Models/CompanyFoodOrder.php` | Project periods and order records. |
| `app/Http/Controllers/Api/PublicCompanyFoodController.php` | Public option selection. |
| `app/Http/Controllers/Api/PublicCompanyFoodOrderController.php` | Public order endpoints. |
| `resources/views/livewire/company-food/` | Project, employee list, menu, and order management. |

## Conventions

* Orders belong to a project, a date within its period, and an employee list owned by that project.
* Active options depend on date. Main and soup options also depend on the employee list.
* Effective categories combine list configuration and categories with active options. Required categories without options are rejected.
* Updates require the order's edit token. Employee names are constrained to the selected list when that list has named employees.

## Gotchas

* The `public/company-food/{projectSlug}` route group is intentionally unthrottled in current code. It is not the authenticated customer portal order flow.
* Export sorting follows employee list order, not necessarily alphabetical employee names. You can preserve filters and ordering when adding consumers.
* Relevant tests are `tests/Feature/PublicCompanyFoodOptionsTest.php` and `tests/Feature/CompanyFoodExportsTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
