# POS services

## Overview

This area owns POS bootstrap, shifts, checkout, offline synchronization, numbering, and print jobs. POS mutations can arrive late or be replayed, so terminal identity, branch alignment, idempotency, ordered locking, and retry-safe effects are core invariants.

## Key files

| File | Owns |
|---|---|
| `PosBootstrapService.php` | Builds terminal bootstrap data. |
| `PosShiftService.php` | Opens, updates, and closes POS shifts. |
| `PosCheckoutService.php` | Coordinates sale checkout and downstream effects. |
| `PosSyncService.php` | Replays and records offline events. |
| `PosSequenceService.php` | Allocates branch and terminal sequences. |
| `PosPrintJobService.php` | Creates and streams retained print jobs. |

## Conventions

- POS APIs require Sanctum authentication plus `pos.token`; preserve token abilities and middleware checks.
- Verify user, token, device, terminal, active shift, and branch alignment before accepting a mutation.
- Use the established client event UUID or idempotency key so an exact retry returns the prior result without duplicating effects.
- Wrap checkout and sync mutations in transactions and lock shared sequence, shift, table, sale, and sync-event rows in the established order.
- Allocate receipt and order numbers only through `PosSequenceService`.
- Create print jobs through the existing service and keep stream cursors and retention behavior compatible.

## Gotchas

- Offline events may arrive out of order or after another device changes shared state. Test stale, duplicate, and conflicting replay paths.
- A successful retry must not duplicate payments, stock movements, orders, journal entries, sequence values, or print jobs.
- Print-stream pruning is scheduled hourly in `bootstrap/app.php`.
- Inspect the POS section of `routes/api.php`, `app/Http/Middleware/EnsurePosToken.php`, and `tests/Feature/POS/` before changing authentication or protocol behavior.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
