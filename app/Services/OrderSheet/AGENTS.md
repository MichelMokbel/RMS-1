# Order sheet publishing

## Overview

This area publishes a daily planning sheet into ordinary orders. Publishing can update an already linked order, so it is a persistence workflow rather than a print operation.

## Key files

| File | Owns |
|---|---|
| `OrderSheetPublishService.php` | Creating or updating orders from sheet entries. |
| `app/Models/OrderSheet.php`, `app/Models/OrderSheetEntry.php` | Sheet identity and linked order identifiers. |
| `resources/views/livewire/order-sheet.blade.php` | Sheet editing and publish actions. |
| `app/Http/Controllers/OrderSheet/OrderSheetPrintController.php` | Prints by order and item totals. |
| `database/migrations/2026_04_24_000002_add_order_id_to_order_sheet_entries.php` | Persistent links between entries and orders. |

## Conventions

* Entries without a customer name are skipped. Positive menu quantities and extras become order items.
* An entry with an existing order is resynchronized by replacing its items and recalculating totals. An unlinked entry creates a new order and stores its identifier.
* Published items currently start at zero price and pending status. You can inspect this behavior before treating sheet publication as a sales or payment event.
* Number allocation and totals delegate to the order services.

## Gotchas

* Publishing currently selects the first active branch as its fallback and builds menu roles for the sheet date. It does not establish a new tenant isolation contract.
* The service has a stale link recovery path when a linked order no longer exists. Retry and concurrency behavior need dedicated coverage before reusing publication for an external integration.
* No dedicated OrderSheet test suite was found in this audit. Nearby order and kitchen tests are useful references, not evidence that publication itself is covered.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
