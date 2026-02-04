# POS Server Readiness — Existing vs Missing (Audit)

Date: 2026-02-04

## Existing (already in repo)

- **Sanctum**: `personal_access_tokens` migration exists; API already uses `auth:sanctum` in places.
- **AR**: `ar_invoices` + `ar_invoice_items` tables + `App\Services\AR\ArInvoiceService` exist and use **minor units** integer fields (`*_cents`).
- **Payments**: `payments` + `payment_allocations` exist and are treated as immutable after creation.
- **POS (web)**: `pos_shifts` table + `App\Services\POS\PosShiftService` exist; `App\Services\POS\PosCheckoutService` exists for online POS checkout (sales flow).
- **Money scale config**: `config/pos.php` provides `currency=QAR` + `money_scale` and `App\Support\Money\MinorUnits` provides safe decimal→minor conversions.

## Missing (required for offline-first Flutter POS)

- **Terminal binding**: `pos_terminals` table + device binding + last-seen tracking.
- **Offline numbering**: terminal/day sequence reservation table (`pos_document_sequences`) + `/api/pos/sequences/reserve`.
- **Offline sync**: `/api/pos/sync` outbox ingestion with replay-safe idempotency store (`pos_sync_events`) and per-event transactional writes.
- **AR traceability/idempotency**: `ar_invoices` needs `client_uuid`, `terminal_id`, `pos_shift_id`, table/session references, and a unique constraint on `(branch_id, pos_reference)`.
- **Payments/expenses idempotency**: `payments.client_uuid` and `petty_cash_expenses.client_uuid` (and terminal/shift linkage).
- **Restaurant table management**: areas/tables/sessions tables + server-side table session locking (multi-device safe).
- **POS auth**: `/api/pos/login` that binds user+device→terminal and issues Sanctum token with `pos:*` abilities.
- **Readiness pack**: Pest tests for idempotency/locks/sequences + docs with request/response examples and error codes.

## Notes / constraints observed

- `branches.id` is an `INT` (MySQL dump style). Existing tables use `unsignedInteger('branch_id')` widely but do **not** enforce foreign keys.
- To avoid production migration failures on MySQL, POS migrations should avoid adding FK constraints to `branches.id` and prefer application-level validation for `branch_id` integrity.

