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
| `PosPrintJobService.php` | Creates and streams retained POS jobs and server originated order-label PDF jobs. |

## Conventions

- POS APIs require Sanctum authentication plus `pos.token`; preserve token abilities and middleware checks. Print polling and acknowledgement also accept the narrower `pos.print` ability, while normal POS tokens retain access through `pos:*`.
- Verify user, token, device, terminal, active shift, and branch alignment before accepting a mutation.
- Use the established client event UUID or idempotency key so an exact retry returns the prior result without duplicating effects.
- Wrap checkout and sync mutations in transactions and lock shared sequence, shift, table, sale, and sync-event rows in the established order.
- Allocate receipt and order numbers only through `PosSequenceService`.
- Create print jobs through the existing service and keep stream cursors and retention behavior compatible.
- Server originated label jobs use `server_job_uuid`, a nullable source terminal, an assigned target terminal, and the same claim, retry, stream, and acknowledgement contract as receipts.

## Gotchas

- Offline events may arrive out of order or after another device changes shared state. Test stale, duplicate, and conflicting replay paths.
- A successful retry must not duplicate payments, stock movements, orders, journal entries, sequence values, or print jobs.
- Print-stream pruning is scheduled hourly in `bootstrap/app.php`.
- The local order-label agent accepts only `order_label_pdf`, validates the PDF and media bounds, maps only locally allowlisted queue names, and persists job deduplication before OS submission.
- The RMS-generated Windows label agent uses a dedicated active user with only `pos.login`, one branch, and a device-bound token limited to `pos.print`. That token must remain forbidden from bootstrap, sync, checkout, and every other POS mutation.
- Inspect the POS section of `routes/api.php`, `app/Http/Middleware/EnsurePosToken.php`, and `tests/Feature/POS/` before changing authentication or protocol behavior.

## Related business workflows

* Sync events can reach AP, AR, Spend, and Ledger services. You can read their guides before adding a new event type or changing replay payloads.
* Bootstrap includes branch menu data, tables, sessions, wallets, currency scale, and receipt profile information. Those fields are part of the client contract.
* Related guides are [sales](../Sales/AGENTS.md), [AP](../AP/AGENTS.md), [AR](../AR/AGENTS.md), [Spend](../Spend/AGENTS.md), and [ledger](../Ledger/AGENTS.md).
* The [route access map](../../../routes/AGENTS.md) and [numbering guide](../Sequences/AGENTS.md) describe current entry points. The [audit report](../../../docs/audits/2026-08-28-context-audit.md) flags the existing blanket authentication and numbering statements for review.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
