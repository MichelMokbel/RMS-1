# Shared document numbering

## Overview

This area allocates document numbers using a sequence keyed by branch, type, and year. Customers, menu items, subscriptions, pastry orders, quotations, and sale workflows use it, while some other documents have separate allocators.

## Key files

| File | Owns |
|---|---|
| `DocumentSequenceService.php` | Locked allocation and optional seed from existing numbers. |
| `app/Models/DocumentSequence.php` | Sequence state. |
| `database/migrations/2026_01_29_000011_create_document_sequences_table.php` | Sequence schema and uniqueness. |
| `app/Services/POS/PosSequenceService.php` | Separate POS reservation protocol. |

## Conventions

* `nextWithSeed` locks the sequence row in a transaction and advances beyond the larger of its current value and supplied seed.
* Concurrent initial row creation is handled by rereading the row under lock.
* Domain number services own prefixes and formatting. You can keep the numeric allocator separate from those public formats.
* The caller's chosen branch, type, and year define the sequence namespace.

## Gotchas

* If the sequence table is absent, the fallback is explicitly not safe under concurrency. It is not an acceptable numbering guarantee for a new integration.
* An invalid branch falls back to `1`, and an omitted year uses the current year. The caller still owns branch authorization and historical document semantics.
* POS range reservation is a different contract. You can inspect its guide before replacing it with single number allocation.
* `app/Services/Orders/OrderNumberService.php` uses `order_number_sequences`. `app/Services/Purchasing/PurchaseOrderNumberService.php` derives its candidate from existing purchase orders. Neither uses this shared allocator.
* Relevant tests include `tests/Feature/Orders/OrderNumberSequenceTest.php`, `tests/Feature/Customers/CustomerCodeSequenceTest.php`, and `tests/Feature/POS/`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
