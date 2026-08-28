# Schema evolution and reference data

## Overview

This area owns migrations, factories, and seeders. You can use migrations as the schema authority while treating dumps and exported schemas as potentially older references.

## Key files

| File | Owns |
|---|---|
| `migrations/` | Forward schema and data changes. |
| `factories/` | Test data construction. |
| `seeders/` | Permissions, defaults, and reference data. |
| `../phpunit.xml`, `../tests/Pest.php` | The test database contract and refresh behavior. |

## Conventions

* You can add a new forward migration for a change without editing migrations that may already have run elsewhere.
* MySQL specific behavior includes generated columns, triggers, JSON queries, and index discovery. SQLite is not an equivalent verification target.
* Financial uniqueness depends on source event keys and active allocation constraints. Voided history must remain possible alongside a new active record.
* Permission and reference data changes appear in both seeders and some migrations. You can inspect both before adding a new module permission.

## Gotchas

* Journal and HR immutability are enforced by database triggers as well as application code.
* The subscription mapping migration `migrations/2026_01_30_000005_allow_multiple_meal_subscription_orders_per_day.php` changed the uniqueness contract to subscription and order, not subscription, date, and branch.
* `migrations/2026_04_19_000002_add_subledger_entries_source_event_unique.php` refuses existing duplicate source events instead of silently deleting financial history.
* Root schema files, SQL dumps, and spreadsheet samples are not a safe substitute for a migrated test database.
* Migration, refresh, seed, restore, truncate, and repair commands need a confirmed disposable target. This audit does not execute them.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
