# POS API Documentation (RMS-1)

> Audience: Windows Flutter Desktop POS team implementing an offline-first POS client against the RMS-1 Laravel server.

## Table of Contents

- [1. Overview](#1-overview)
- [2. Authentication](#2-authentication)
- [3. Bootstrap](#3-bootstrap)
- [4. Sequence Reservation](#4-sequence-reservation)
- [5. Sync Endpoint](#5-sync-endpoint)
- [6. Event Types (DETAILED)](#6-event-types-detailed)
- [7. Restaurant Table Management Contract](#7-restaurant-table-management-contract)
- [8. Data Types & Conventions](#8-data-types--conventions)
- [9. End-to-End Test Plan (REQUIRED)](#9-end-to-end-test-plan-required)
- [10. Error Codes Catalog](#10-error-codes-catalog)

## 1. Overview

### Base URL and environments

- Base URL: `{RMS_BASE_URL}/api/pos/*`
- Environments: derived from your deployment URL (no separate “POS host” in code).
- Content type: `application/json`
- Auth header: `Authorization: Bearer {token}`

Evidence:
- Routes: `routes/api.php` (POS group under `Route::prefix('pos')`)

### Auth model (Sanctum + device binding)

- Login returns a Sanctum token whose abilities include:
  - `pos:*`
  - `device:{device_id}`
- All authenticated POS endpoints use middleware:
  - `auth:sanctum`
  - `pos.token` (device/terminal binding + terminal lookup)
- Device ID propagation rules (how the server identifies your device):
  - For requests with a JSON body (sync, login): send `device_id` in JSON.
  - For requests without a JSON body (bootstrap GET): `pos.token` can infer the device from the token abilities/name; alternatively send `X-Device-Id: {device_id}`.

Evidence:
- Token creation: `app/Http/Controllers/Api/Pos/AuthController.php::login()`
- Middleware logic: `app/Http/Middleware/EnsurePosToken.php::handle()`
- Route middleware: `routes/api.php` (POS subgroup)

### Offline-first model (what to call, and why)

1) **Bootstrap**: seed local database (catalog + customers + tables + open sessions) and pull deltas later.
2) **Reserve sequences**: allocate POS reference numbers offline-safe per terminal per business date.
3) **Sync**: push local “outbox” events and receive server deltas in the same round-trip.

Evidence:
- Bootstrap implementation: `app/Services/POS/PosBootstrapService.php::bootstrap()`
- Sequence reservation: `app/Services/POS/PosSequenceService.php::reserve()`
- Sync: `app/Services/POS/PosSyncService.php::sync()`

### Idempotency principles (server-side)

- **Primary idempotency key** for sync events: `events[*].client_uuid`.
  - Stored in `pos_sync_events.client_uuid` with a unique index.
- Server returns `ok=true` **only** when the event is durably applied and the ACK includes:
  - `server_entity_type`
  - `server_entity_id`
  - `applied_at`
- Some handlers also have **domain idempotency**:
  - `invoice.finalize`: treated as success if an `ar_invoices` row exists by `client_uuid` OR by `(branch_id, pos_reference)`.
  - `petty_cash.expense.create`: treated as success if a `petty_cash_expenses` row exists by `client_uuid`.

Evidence:
- Sync event table + uniqueness: `database/migrations/2026_02_04_000004_create_pos_sync_events_table.php`
- Replay logic and ACK rules: `app/Services/POS/PosSyncService.php::processEvent()`
- Invoice idempotency: `app/Services/POS/PosSyncService.php::handleInvoiceFinalize()`
- Petty cash idempotency: `app/Services/POS/PosSyncService.php::handlePettyCashExpenseCreate()`

### Error model (standard error envelope)

There are **two** error channels:

1) **HTTP request-level errors** (endpoint call fails):
- `401` unauthenticated:
  ```json
  { "message": "Unauthenticated." }
  ```
- `403/401` auth/device/terminal errors:
  ```json
  { "message": "AUTH_ERROR", "reason": "DEVICE_MISMATCH" }
  ```
- `422` validation (Laravel FormRequest default):
  ```json
  {
    "message": "The given data was invalid.",
    "errors": { "field": ["..."] }
  }
  ```

2) **Sync ACK-level errors** (sync call succeeds `200`, but an event fails):
```json
{
  "event_id": "evt-123",
  "ok": false,
  "error_code": "VALIDATION_ERROR",
  "error_message": "Customer not found."
}
```

Evidence:
- Request validation rules: `app/Http/Requests/Api/Pos/*`
- Middleware error payloads: `app/Http/Middleware/EnsurePosToken.php`
- Sync ACK error format: `app/Services/POS/PosSyncService.php::ackError()`

## 2. Authentication

### POST `/api/pos/login`

Purpose: authenticate a user and bind a token to a specific `device_id` that must map to an active `pos_terminals` row.

Middleware: none (public)

Evidence:
- Route: `routes/api.php` (`Route::post('pos/login', ...)`)
- Controller: `app/Http/Controllers/Api/Pos/AuthController.php::login()`
- Validation: `app/Http/Requests/Api/Pos/LoginRequest.php`
- Terminal lookup: `App\Models\PosTerminal` (`pos_terminals` table)

Request JSON schema:
```json
{
  "email": "string (required, email, max 255)",
  "password": "string (required, max 255)",
  "device_id": "string (required, max 80, regex ^[A-Za-z0-9._-]+$)"
}
```

Response JSON schema (200):
```json
{
  "token": "string",
  "user": { "id": "int", "name": "string", "email": "string" },
  "role": "string|null",
  "roles": ["string", "..."],
  "branch_id": "int",
  "terminal": { "id": "int", "code": "string", "name": "string" }
}
```

Status codes and errors:
- `200`: success
- `401`: invalid credentials
  ```json
  { "message": "AUTH_ERROR" }
  ```
- `403`: user inactive OR device not mapped to an active POS terminal
  ```json
  { "message": "AUTH_ERROR", "error": "Device is not registered to a POS terminal." }
  ```
- `422`: invalid input (FormRequest)

Token abilities/scopes:
- Token is created with abilities: `['pos:*', 'device:{device_id}']`

### POST `/api/pos/logout`

Purpose: revoke the current token.

Middleware:
- `auth:sanctum`
- `pos.token`

Evidence:
- Route: `routes/api.php`
- Controller: `app/Http/Controllers/Api/Pos/AuthController.php::logout()`
- Token device enforcement: `app/Http/Middleware/EnsurePosToken.php`

Request:
- No request body required.

Response (200):
```json
{ "ok": true }
```

Status codes and errors:
- `200`: success
- `401`: unauthenticated
- `403`: token ability/device/terminal mismatch (see Error Codes Catalog)

## 3. Bootstrap

### GET `/api/pos/bootstrap?since=...`

Purpose:
- Initial seed for offline operation (catalog/customers/tables/sessions/config).
- Subsequent pulls using `since` for deltas (best-effort “updated since” filtering).

Middleware:
- `auth:sanctum`
- `pos.token`

Evidence:
- Route: `routes/api.php`
- Controller: `app/Http/Controllers/Api/Pos/BootstrapController.php::__invoke()`
- Validation: `app/Http/Requests/Api/Pos/BootstrapRequest.php`
- Data builder: `app/Services/POS/PosBootstrapService.php::bootstrap()`

Query params:
- `since` (optional, nullable): any date/time parseable by Laravel `date` rule (ISO8601 recommended).

Response payload sections (200):
```json
{
  "settings": {
    "currency": "string (config pos.currency, default QAR)",
    "money_scale": "int (config pos.money_scale, default 100)"
  },
  "terminal": { "id": "int", "code": "string", "branch_id": "int" },
  "categories": [
    { "id": "int", "name": "string", "parent_id": "int|null", "updated_at": "string|null (ISO8601)" }
  ],
  "menu_items": [
    {
      "id": "int",
      "code": "string",
      "name": "string",
      "arabic_name": "string",
      "category_id": "int",
      "unit": "string",
      "is_active": "bool",
      "tax_rate": "string",
      "price_cents": "int",
      "updated_at": "string|null (ISO8601)"
    }
  ],
  "customers": [
    { "id": "int", "name": "string", "phone": "string", "email": "string", "is_active": "bool", "updated_at": "string|null (ISO8601)" }
  ],
  "restaurant_areas": [
    { "id": "int", "name": "string", "display_order": "int", "active": "bool", "updated_at": "string|null (ISO8601)" }
  ],
  "restaurant_tables": [
    {
      "id": "int",
      "area_id": "int|null",
      "code": "string",
      "name": "string",
      "capacity": "int|null",
      "display_order": "int",
      "active": "bool",
      "updated_at": "string|null (ISO8601)"
    }
  ],
  "restaurant_table_sessions": [
    {
      "id": "int",
      "table_id": "int",
      "status": "string",
      "active": "bool",
      "opened_at": "string|null (ISO8601)",
      "closed_at": "string|null (ISO8601)",
      "guests": "int|null",
      "terminal_id": "int|null",
      "device_id": "string|null",
      "pos_shift_id": "int|null",
      "updated_at": "string|null (ISO8601)"
    }
  ],
  "petty_cash_wallets": [
    { "id": "int", "name": "string", "active": "bool", "balance": "string(decimal)", "created_at": "string|null (ISO8601)" }
  ],
  "expense_categories": [
    { "id": "int", "name": "string", "active": "bool", "created_at": "string|null (ISO8601)" }
  ],
  "server_timestamp": "string (ISO8601, UTC)"
}
```

Delta sync semantics (`since` behavior)
- For tables with an `updated_at` column, results are filtered using `updated_at > since`.
- If `updated_at` does not exist on a table, that table is returned unfiltered (best-effort).
- `restaurant_table_sessions` behavior:
  - If `since` is **omitted**: returns **only** `active=1` sessions (open sessions snapshot).
  - If `since` is provided: returns sessions updated since (can include closed sessions).
- Customers are capped at 5000 rows per call.

Evidence:
- Updated-since filtering: `app/Services/POS/PosBootstrapService.php::whereUpdatedSince()`
- Session selection logic: `app/Services/POS/PosBootstrapService.php::bootstrap()` (sessions query)

Tax rules (MISSING / current behavior)
- Bootstrap provides `menu_items[*].tax_rate`, but `invoice.finalize` currently enforces `tax_cents=0` effectively (line tax is hard-coded to 0 during totals verification).
- POS must send `totals.tax_cents=0` and ensure line totals match without tax.

Evidence:
- Tax currently treated as 0: `app/Services/POS/PosSyncService.php::handleInvoiceFinalize()` (sets `$lineTax = 0`)

## 4. Sequence Reservation

### POST `/api/pos/sequences/reserve`

Purpose: reserve a contiguous integer range used to generate `pos_reference` offline without collisions.

Middleware:
- `auth:sanctum`
- `pos.token`

Evidence:
- Route: `routes/api.php`
- Controller: `app/Http/Controllers/Api/Pos/SequenceController.php::reserve()`
- Validation: `app/Http/Requests/Api/Pos/SequenceReserveRequest.php`
- DB allocation with row lock: `app/Services/POS/PosSequenceService.php::reserve()`
- Storage table: `pos_document_sequences` (`database/migrations/2026_02_04_000002_create_pos_document_sequences_table.php`)

Request JSON schema:
```json
{
  "business_date": "string (required, YYYY-MM-DD)",
  "count": "int (required, min 1, max 5000)"
}
```

Response (200):
```json
{
  "terminal": { "id": 1, "code": "T01" },
  "business_date": "2026-02-04",
  "count": 200,
  "reserved_start": 1,
  "reserved_end": 200
}
```

`pos_reference` generation formula
- `pos_reference = "{terminal_code}-{YYYYMMDD}-{seq:06d}"`
- Example: `T01-20260204-000123`

Concurrency guarantees
- Reservation is serialized per `(terminal_id, business_date)` using `SELECT ... FOR UPDATE`.
- Ranges never overlap for the same terminal/date.

Important client note (idempotency)
- This endpoint is **not idempotent**: if the same request is retried, it will reserve *another* range and advance `last_number`.
- The POS client must persist the reserved range locally and avoid automatic retries that would waste sequence numbers.

## 5. Sync Endpoint

### POST `/api/pos/sync`

Purpose:
- Push offline events (outbox → server) and receive server deltas in one request.

Middleware:
- `auth:sanctum`
- `pos.token`

Evidence:
- Route: `routes/api.php`
- Controller: `app/Http/Controllers/Api/Pos/SyncController.php::__invoke()`
- Validation: `app/Http/Requests/Api/Pos/SyncRequest.php`
- Service: `app/Services/POS/PosSyncService.php::sync()` and `::processEvent()`
- Idempotency table: `pos_sync_events` (`database/migrations/2026_02_04_000004_create_pos_sync_events_table.php`)

Request fields:
```json
{
  "device_id": "string (required, max 80, regex ^[A-Za-z0-9._-]+$)",
  "terminal_code": "string (required, regex ^T\\d{2}$)",
  "branch_id": "int (required, min 1)",
  "last_pulled_at": "string|null (optional, date parseable; ISO8601 recommended)",
  "events": "array (required)",
  "events[*].event_id": "string (required, max 100) - client-visible tracking id",
  "events[*].type": "string (required, max 60) - event type name",
  "events[*].client_uuid": "string (required, UUID) - idempotency key",
  "events[*].payload": "object (required) - type-specific payload"
}
```

Terminal safety check
- Even after `pos.token`, the controller enforces:
  - `terminal_code` must equal the token-bound terminal’s `code`
  - `branch_id` must equal the token-bound terminal’s `branch_id`
- If mismatch: `403` with:
  ```json
  { "message": "AUTH_ERROR", "reason": "TERMINAL_MISMATCH" }
  ```

Response (200):
```json
{
  "acks": [ /* one per input event, in order */ ],
  "deltas": { /* same shape as Bootstrap response, minus terminal/settings */ },
  "server_timestamp": "string (ISO8601, UTC)"
}
```

ACK schema
```json
{
  "event_id": "string (echoed from request event_id)",
  "ok": "bool",

  "error_code": "string (present when ok=false)",
  "error_message": "string (present when ok=false)",

  "server_entity_type": "string (present when ok=true)",
  "server_entity_id": "int (present when ok=true)",
  "applied_at": "string (present when ok=true; UTC; format YYYY-MM-DDTHH:mm:ssZ)"
}
```

ACK semantics and retry rules (state machine)

`pos_sync_events.status` values:
- `pending`: created/ready-to-run (internal)
- `processing`: in-progress only; **never** safe to ACK `ok=true` without completion proof
- `applied`: terminal success; must have `server_entity_type`, `server_entity_id`, `applied_at`
- `failed`: retryable; next sync retry may reprocess
- `rejected`: terminal deterministic error; re-sending the same event UUID will keep returning the rejection

Rules:
- If existing row is `applied`: return `ok=true` with the stored entity reference and `applied_at` (no reprocessing).
- If existing row is `processing` and entity reference is missing: return `ok=false` with `error_code=INCOMPLETE_PROCESSING`; server marks it `failed` (retryable).
- If existing row is `failed`: server will retry (re-process) on the next call.
- Deterministic payload/type errors are stored as `rejected` and returned as `ok=false`.

Evidence:
- State machine: `app/Services/POS/PosSyncService.php::processEvent()`
- Table schema: `database/migrations/2026_02_04_000004_create_pos_sync_events_table.php`

Idempotency and duplicates
- Replaying the same `events[*].client_uuid` must never create duplicates.
- `invoice.finalize` additionally treats duplicate `pos_reference` as success and returns the existing invoice ID.

Recommended `last_pulled_at` usage (important for performance)
- `deltas` are generated by calling the same bootstrap builder using `last_pulled_at` as `since`.
- If `last_pulled_at` is `null`, `deltas` can include large “full bootstrap” payloads.
- Recommended client rule:
  - After every successful sync, set `last_pulled_at = server_timestamp` from the previous response.

Evidence:
- Sync returns `deltas` from bootstrap builder: `app/Services/POS/PosSyncService.php::sync()`

## 6. Event Types (DETAILED)

All events are sent via `POST /api/pos/sync` in the standard event envelope:
```json
{
  "event_id": "evt-001",
  "type": "shift.open",
  "client_uuid": "uuid",
  "payload": { /* type-specific */ }
}
```

For each event below:
- “Validation rules” include both schema validation and business rules observed in code.
- “Side effects” list the primary tables/fields touched (not an exhaustive DB diff).

### shift.open

When POS sends it:
- At the start of a cashier session for a terminal/device, before taking cash payments.

Payload JSON schema:
```json
{
  "opening_cash_cents": "int (required, min 0)",
  "opened_at": "string (required, date parseable; ISO8601 recommended)"
}
```

Validation rules:
- `opening_cash_cents` required integer >= 0
- `opened_at` required date

Server side effects:
- Inserts into `pos_shifts`:
  - `branch_id`, `terminal_id`, `device_id`, `user_id`
  - `active=true`, `status='open'`
  - `opening_cash_cents`, `opened_at`
  - `created_by`

Idempotency keys:
- Sync-level: `events[*].client_uuid` (via `pos_sync_events`)

Common errors:
- `VALIDATION_ERROR` if missing/invalid fields

Example request event:
```json
{
  "event_id": "sh-001",
  "type": "shift.open",
  "client_uuid": "7b1dd0d4-ec0e-49c0-8c40-ea0a05c2a0e0",
  "payload": { "opening_cash_cents": 0, "opened_at": "2026-02-04T09:00:00Z" }
}
```

Example ACK:
```json
{
  "event_id": "sh-001",
  "ok": true,
  "server_entity_type": "pos_shift",
  "server_entity_id": 123,
  "applied_at": "2026-02-04T09:00:01Z"
}
```

Evidence:
- Handler: `app/Services/POS/PosSyncService.php::handleShiftOpen()`
- Model/table: `app/Models/PosShift.php` (`pos_shifts`)

### shift.close

When POS sends it:
- At end of shift to close a cashier session.

Payload JSON schema:
```json
{
  "shift_id": "int (required, min 1)",
  "closed_at": "string (required, date parseable; ISO8601 recommended)",
  "closing_cash_cents": "int (required)",
  "expected_cash_cents": "int|null (optional)"
}
```

Validation rules:
- Locks the `pos_shifts` row (`SELECT ... FOR UPDATE`).
- If shift is already closed (`!isOpen()`), handler returns success without changes (idempotent close).
- If `expected_cash_cents` omitted, server computes:
  - `expected = opening_cash_cents + SUM(payments.amount_cents WHERE method='cash' AND pos_shift_id=? AND voided_at IS NULL)`

Server side effects:
- Updates `pos_shifts`:
  - `closing_cash_cents`, `expected_cash_cents`, `variance_cents`
  - `closed_at`, `closed_by`
  - `status='closed'`, `active=NULL`

Idempotency keys:
- Sync-level: `events[*].client_uuid`
- Domain-level: closing an already closed shift returns success.

Common errors:
- `VALIDATION_ERROR` on missing/invalid fields
- `SERVER_ERROR` on unexpected server exceptions

Example request event:
```json
{
  "event_id": "sh-002",
  "type": "shift.close",
  "client_uuid": "c9e3d9a2-0b7b-42b8-a7b6-ef764dcf0377",
  "payload": {
    "shift_id": 123,
    "closed_at": "2026-02-04T18:00:00Z",
    "closing_cash_cents": 12500
  }
}
```

Example ACK:
```json
{
  "event_id": "sh-002",
  "ok": true,
  "server_entity_type": "pos_shift",
  "server_entity_id": 123,
  "applied_at": "2026-02-04T18:00:01Z"
}
```

Evidence:
- Handler: `app/Services/POS/PosSyncService.php::handleShiftClose()`
- Cash expected calculation: `handleShiftClose()` sums `payments` by `pos_shift_id` and `method='cash'`

### table_session.open

When POS sends it:
- When seating a table for dine-in.

Payload JSON schema:
```json
{
  "table_id": "int (required, min 1)",
  "opened_at": "string (required, date parseable; ISO8601 recommended)",
  "guests": "int|null (optional, min 1, max 50)",
  "notes": "string|null (optional, max 500)",
  "pos_shift_id": "int|null (optional, min 1)"
}
```

Validation rules / business rules:
- Locks the `restaurant_tables` row to serialize opens.
- Table’s `branch_id` must match terminal’s `branch_id` (“Table branch mismatch.”).
- Only **one** active session per table:
  - If an active session exists: returns an ACK error via `PosSyncException`:
    - `error_code=TABLE_ALREADY_OPEN`
    - includes `existing_table_session_id`, `existing_terminal_id`, `existing_device_id`

Server side effects:
- Inserts into `restaurant_table_sessions`:
  - `branch_id`, `table_id`, `opened_by`, `device_id`, `terminal_id`, `pos_shift_id`
  - `status='open'`, `active=true`, `opened_at`, `guests`, `notes`

Idempotency keys:
- Sync-level: `events[*].client_uuid`

Common errors:
- `TABLE_ALREADY_OPEN`: show conflict UI; refresh sessions via deltas/bootstrap; do not auto-retry blindly.
- `VALIDATION_ERROR`: fix payload or local state.

Example request event:
```json
{
  "event_id": "ts-001",
  "type": "table_session.open",
  "client_uuid": "7f7b2736-6c51-4a5f-9a65-84f15f06a44b",
  "payload": { "table_id": 12, "opened_at": "2026-02-04T09:10:00Z", "guests": 2 }
}
```

Example ACK (success):
```json
{
  "event_id": "ts-001",
  "ok": true,
  "server_entity_type": "restaurant_table_session",
  "server_entity_id": 55,
  "applied_at": "2026-02-04T09:10:01Z"
}
```

Example ACK (TABLE_ALREADY_OPEN):
```json
{
  "event_id": "ts-001",
  "ok": false,
  "error_code": "TABLE_ALREADY_OPEN",
  "error_message": "Table is already open.",
  "existing_table_session_id": 55,
  "existing_terminal_id": 1,
  "existing_device_id": "DEV-B"
}
```

Evidence:
- Handler + lock: `app/Services/POS/PosSyncService.php::handleTableSessionOpen()`
- Error payload fields: `App\Services\POS\Exceptions\PosSyncException`

### table_session.close

When POS sends it:
- When table is cleared/closed.

Payload JSON schema:
```json
{
  "table_session_id": "int (required, min 1)",
  "closed_at": "string (required, date parseable; ISO8601 recommended)"
}
```

Validation rules / business rules:
- Locks the `restaurant_table_sessions` row.
- If already closed (`active=false` or `status='closed'`), returns success without changes.

Server side effects:
- Updates `restaurant_table_sessions`:
  - `status='closed'`, `active=false`, `closed_at`

Idempotency keys:
- Sync-level: `events[*].client_uuid`
- Domain-level: closing an already closed session returns success.

Common errors:
- `VALIDATION_ERROR` for missing/invalid IDs or timestamps

Evidence:
- Handler: `app/Services/POS/PosSyncService.php::handleTableSessionClose()`

### invoice.finalize

When POS sends it:
- After the POS has finalized an invoice (receipt) locally and needs the server to persist the AR invoice and any payments.

Payload JSON schema (as implemented)
```json
{
  "client_uuid": "string (required, UUID v4-ish; used as ar_invoices.client_uuid)",
  "pos_reference": "string (required, regex ^T\\d{2}-\\d{8}-\\d{6}$; must start with {terminal_code}-)",
  "payment_type": "string (required, one of cash|card|credit|mixed)",
  "customer_id": "int (required, min 1)",
  "issue_date": "string (required, YYYY-MM-DD)",
  "pos_shift_id": "int|null (optional, min 1)",
  "restaurant_table_id": "int|null (optional, min 1)",
  "table_session_id": "int|null (optional, min 1)",
  "lines": [
    {
      "menu_item_id": "int (required, min 1)",
      "qty": "string (required, regex ^\\d+(\\.\\d{1,3})?$)",
      "unit_price_cents": "int (required, min 0)",
      "line_discount_cents": "int|null (optional, min 0)",
      "line_total_cents": "int (required, min 0)"
    }
  ],
  "totals": {
    "subtotal_cents": "int (required, min 0)",
    "discount_cents": "int (required, min 0)",
    "tax_cents": "int (required, min 0)",
    "total_cents": "int (required, min 0)"
  },
  "payments": [
    {
      "client_uuid": "string (required if payments present, UUID; used as payments.client_uuid)",
      "method": "string (required if payments present, one of cash|card|online|bank|voucher)",
      "amount_cents": "int (required if payments present, min 1)",
      "received_at": "string|null (optional, date parseable)",
      "reference": "string|null (optional, max 120)"
    }
  ]
}
```

Validation rules / business rules:
- Payments rules:
  - If `payment_type=credit`: `payments` must be empty.
  - If `payment_type!=credit`: `payments` must be non-empty.
- `pos_reference` must start with `{terminal_code}-` (terminal code mismatch is a validation error).
- Invoice client UUID:
  - Read from `payload.client_uuid` (not inside `lines`).
  - Must match regex `^[0-9a-fA-F-]{36}$` (UUID string).
- Domain idempotency:
  - If an invoice exists with `ar_invoices.client_uuid = payload.client_uuid`: returns success with that invoice ID.
  - Else if invoice exists with `(branch_id, pos_reference)`: returns success with that invoice ID.
- Lines and totals integrity:
  - Each line total must equal `qty * unit_price_cents - line_discount_cents` (tax currently treated as 0 in code).
  - `totals.*` must match the computed sums.
- Non-credit payments integrity:
  - Sum of `payments.amount_cents` must equal `ar_invoices.total_cents` or validation fails.

Server side effects (successful apply):
- Inserts into `ar_invoices` and `ar_invoice_items` via `ArInvoiceService::createDraft()` then issues invoice:
  - `ar_invoices.source='pos'`
  - `ar_invoices.pos_reference = payload.pos_reference`
  - `ar_invoices.payment_type = payload.payment_type`
  - `ar_invoices.issue_date = payload.issue_date`
  - then updates:
    - `terminal_id`, `pos_shift_id`, `client_uuid`, `restaurant_table_id`, `table_session_id`
    - `meta.device_id`
- If `payment_type != 'credit'`:
  - Inserts into `payments` for each payment:
    - `source='pos'`, `terminal_id`, `pos_shift_id`, `client_uuid`, `method`, `amount_cents`, `currency`, `received_at`, `reference`
  - Inserts into `payment_allocations` to allocate payments to the invoice.
  - Posts subledger entries for payments (ledger integration).
- Recalculates invoice totals/status.

Idempotency keys:
- Sync-level: `events[*].client_uuid` (`pos_sync_events`)
- Invoice-level: `payload.client_uuid` and `(branch_id, pos_reference)`
- Payment-level: `payments[*].client_uuid` is written, but **no explicit server-side idempotency check** exists for payments inside this handler. In practice, the handler will not rerun for the same sync event UUID, and will return early for existing invoices.

Common errors:
- `VALIDATION_ERROR`:
  - `payments`: “Payments are required.” / “Credit invoices must not include payments.” / “Payment total must equal invoice total.”
  - `pos_reference`: “Terminal code mismatch.”
  - `customer_id`: “Customer not found.”
  - `lines`/`totals`: “Invalid menu item.” / “Line totals mismatch.” / “Totals mismatch.”
- `SERVER_ERROR`: unexpected exceptions.

Example request event (cash invoice):
```json
{
  "event_id": "inv-001",
  "type": "invoice.finalize",
  "client_uuid": "2d3d6a46-0cdd-4d40-8e5a-8c2bdbf5a5a3",
  "payload": {
    "client_uuid": "6a91b1b1-2c08-4bf6-b9c4-4a1f0b71b2d1",
    "pos_reference": "T01-20260204-000123",
    "payment_type": "cash",
    "customer_id": 100,
    "issue_date": "2026-02-04",
    "lines": [
      { "menu_item_id": 10, "qty": "1.000", "unit_price_cents": 500, "line_discount_cents": 0, "line_total_cents": 500 }
    ],
    "totals": { "subtotal_cents": 500, "discount_cents": 0, "tax_cents": 0, "total_cents": 500 },
    "payments": [
      { "client_uuid": "c72d1b62-2d43-43f2-9de2-49d8b0d2a2b7", "method": "cash", "amount_cents": 500, "received_at": "2026-02-04T09:15:00Z" }
    ]
  }
}
```

Example ACK:
```json
{
  "event_id": "inv-001",
  "ok": true,
  "server_entity_type": "ar_invoice",
  "server_entity_id": 501,
  "applied_at": "2026-02-04T09:15:01Z"
}
```

Evidence:
- Handler + validations: `app/Services/POS/PosSyncService.php::handleInvoiceFinalize()`
- Invoice insert: `app/Services/AR/ArInvoiceService.php::createDraft()` (`ar_invoices`, `ar_invoice_items`)

### petty_cash.expense.create

When POS sends it:
- When the POS records a petty cash expense (cash out) associated with a wallet/category.

Payload JSON schema:
```json
{
  "client_uuid": "string (required, regex ^[0-9a-fA-F-]{36}$; used as petty_cash_expenses.client_uuid)",
  "wallet_id": "int (required, min 1)",
  "category_id": "int (required, min 1)",
  "expense_date": "string (required, YYYY-MM-DD)",
  "amount_cents": "int (required, min 1)",
  "description": "string (required, max 255)",
  "pos_shift_id": "int|null (optional, min 1)"
}
```

Validation rules / business rules:
- Idempotent by `petty_cash_expenses.client_uuid`:
  - If existing expense found: returns success with that ID.
- On creation, workflow submits then approves:
  - Wallet must be active (otherwise validation error).

Server side effects:
- Inserts into `petty_cash_expenses`:
  - `client_uuid`, `terminal_id`, `pos_shift_id`, `wallet_id`, `category_id`, `expense_date`, `description`
  - `amount`, `tax_amount='0.00'`, `total_amount`
  - `status` transitions: `draft` → `submitted` → `approved`
  - `submitted_by`, `approved_by`, `approved_at`
- Updates petty cash wallet balance and posts subledger entries (ledger integration).

Idempotency keys:
- Sync-level: `events[*].client_uuid`
- Domain-level: `payload.client_uuid` (expense UUID)

Common errors:
- `VALIDATION_ERROR` (wallet inactive, bad schema, etc.)

Evidence:
- Handler: `app/Services/POS/PosSyncService.php::handlePettyCashExpenseCreate()`
- Workflow: `app/Services/PettyCash/PettyCashExpenseWorkflowService.php::submit()` and `::approve()`

### customer.upsert

When POS sends it:
- When creating/updating a customer from the POS UI.

Payload JSON schema:
```json
{
  "customer": {
    "id": "int|null (optional, min 1 when present)",
    "name": "string (required, max 255)",
    "phone": "string|null (optional, max 50)",
    "email": "string|null (optional, email, max 255)",
    "updated_at": "string|null (optional, date parseable)"
  }
}
```

Validation rules / business rules:
- If `customer.id` is provided:
  - Locks the customer row.
  - If incoming `updated_at` is older than server `customers.updated_at`, server returns success without applying the update.
- If `customer.id` is omitted:
  - Creates a new customer record.

Server side effects:
- Updates `customers` (when `id` provided):
  - `name`, `phone`, `email`, `updated_by`
- Inserts into `customers` (when no `id`):
  - `name`, `phone`, `email`, `is_active=1`, `created_by`, `updated_by`

Idempotency keys:
- Sync-level: `events[*].client_uuid`
- No domain-level dedupe key is used when creating customers (different events can create duplicates). POS should avoid re-sending “create new customer” with a new event UUID if it is actually a retry of the same create.

Common errors:
- `VALIDATION_ERROR` on bad schema or unknown `customer.id`

Evidence:
- Handler: `app/Services/POS/PosSyncService.php::handleCustomerUpsert()`

### MISSING: table_session.transfer / split / merge

Status: **MISSING / TO BE IMPLEMENTED**

Evidence of absence:
- The only implemented POS sync event types are in `app/Services/POS/PosSyncService.php::processEvent()` and there is no handler for transfer/split/merge.

Recommended contract (proposed; not implemented)

If the POS requires these workflows, implement them as sync event types under `/api/pos/sync` with the same envelope and idempotency via `events[*].client_uuid`:

1) `table_session.transfer`
```json
{
  "from_table_session_id": "int (required)",
  "to_table_id": "int (required)",
  "transferred_at": "string (required, ISO8601)"
}
```
ACK: `server_entity_type="restaurant_table_session"` and `server_entity_id` of the new/updated session.

Minimal server changes required:
- Add a handler in `PosSyncService` and implement DB updates with locking on both table rows and session rows.

## 7. Restaurant Table Management Contract

### Bootstrap schema (areas/tables/sessions)

See Bootstrap response sections:
- `restaurant_areas[]`
- `restaurant_tables[]`
- `restaurant_table_sessions[]`

Evidence:
- Schema mapping: `app/Services/POS/PosBootstrapService.php::bootstrap()` (areas/tables/sessions mapping arrays)

### Locking semantics (multi-device safe)

Server guarantees:
- **Only one active session per table**.
- On `table_session.open`, server locks the table row and checks for an active session before inserting.

Conflict error:
- `TABLE_ALREADY_OPEN` ACK includes:
  - `existing_table_session_id`
  - `existing_terminal_id` (nullable)
  - `existing_device_id` (nullable)

Evidence:
- Conflict throw: `app/Services/POS/PosSyncService.php::handleTableSessionOpen()`

### Recommended client behavior

- On `TABLE_ALREADY_OPEN`:
  - Do not retry immediately.
  - Pull deltas (sync with `last_pulled_at`) or call bootstrap to refresh `restaurant_table_sessions`.
  - Present a conflict resolution UI:
    - show which device/terminal currently owns the active session (if provided)
    - allow user to choose a different table or coordinate with the other terminal
- Always treat table session state as server-authoritative for conflicts.

## 8. Data Types & Conventions

### Money

- All POS amounts sent to the server are integer “minor units”:
  - `*_cents` fields are integers.
- Currency is `QAR` by default.
- `money_scale` is provided in bootstrap settings and configured server-side:
  - `config('pos.money_scale')` default 100

Evidence:
- Config: `config/pos.php`
- Bootstrap settings: `app/Services/POS/PosBootstrapService.php::bootstrap()`

### Quantity

- Invoice line `qty` is a string validated by regex: `^\d+(\.\d{1,3})?$`
- Server computes totals using milli-units parsing (`parseQtyMilli`) and integer math.

Evidence:
- Validation + computation: `app/Services/POS/PosSyncService.php::handleInvoiceFinalize()`

### Date/time formats and timezone

- Request timestamps are validated with Laravel `date` rule and parsed with `Carbon::parse()`.
- Use ISO8601 in UTC in the POS client to avoid ambiguity (recommended).
- Server response fields:
  - `server_timestamp`: ISO8601 UTC (may include fractional seconds)
  - `acks[*].applied_at`: UTC formatted `YYYY-MM-DDTHH:mm:ssZ` (seconds precision)

Evidence:
- `server_timestamp`: `app/Services/POS/PosBootstrapService.php::bootstrap()` and `app/Services/POS/PosSyncService.php::sync()`
- `applied_at` formatting: `app/Services/POS/PosSyncService.php::ackOk()`

### IDs

- Server entity IDs are integers (DB primary keys).
- `client_uuid` fields are UUID strings used for idempotency (sync events) and some domain entities.

Evidence:
- Sync request rule: `app/Http/Requests/Api/Pos/SyncRequest.php`
- Domain UUID usage:
  - invoice: `ar_invoices.client_uuid`
  - petty cash expense: `petty_cash_expenses.client_uuid`
  - payment: `payments.client_uuid`

### Enumerations

- `payment_type` (invoice.finalize): `cash | card | credit | mixed`
- `payments[*].method` (invoice.finalize): `cash | card | online | bank | voucher`
- `pos_shifts.status`: `open | closed` (as used by POS sync handlers)
- `restaurant_table_sessions.status`: `open | closed`

MISSING / TO BE IMPLEMENTED:
- “Order type” (`dine_in` / `takeaway`) is not part of `/api/pos/sync` invoice payloads in this codebase. If the POS needs to persist order type at invoice level, backend changes are required.

## 9. End-to-End Test Plan (REQUIRED)

This is a minimal Postman-style script using only implemented endpoints.

Assumptions / setup:
- There exists an active `pos_terminals` row with:
  - `code="T01"`, `device_id="DEV-A"`, `active=1`, `branch_id=1`
- There is at least:
  - one `customers` row (or create one via `customer.upsert`)
  - one `menu_items` row available in branch 1
  - one `restaurant_tables` row in branch 1
  - petty cash wallet + expense category if testing petty cash

### Step 1) Login

Request:
```json
POST /api/pos/login
{
  "email": "cashier@example.com",
  "password": "password",
  "device_id": "DEV-A"
}
```

Expected response (200): contains `token`, `terminal.code="T01"`, `branch_id=1`.

DB expectations:
- `personal_access_tokens` row created (Sanctum)
- `pos_terminals.last_seen_at` updated

### Step 2) Bootstrap (initial seed)

Request:
```
GET /api/pos/bootstrap
Authorization: Bearer {token}
```

Expected response (200): includes `settings`, `terminal`, and arrays.

DB expectations:
- none (read-only)

### Step 3) Reserve sequences

Request:
```json
POST /api/pos/sequences/reserve
{
  "business_date": "2026-02-04",
  "count": 5
}
```

Expected response (200): `reserved_start=1`, `reserved_end=5` (values depend on existing state).

DB expectations:
- `pos_document_sequences` row exists for `(terminal_id, business_date)` and `last_number` advanced.

### Step 4) Open shift

Request:
```json
POST /api/pos/sync
{
  "device_id": "DEV-A",
  "terminal_code": "T01",
  "branch_id": 1,
  "last_pulled_at": null,
  "events": [
    {
      "event_id": "evt-sh-open-001",
      "type": "shift.open",
      "client_uuid": "11111111-1111-1111-1111-111111111111",
      "payload": { "opening_cash_cents": 0, "opened_at": "2026-02-04T09:00:00Z" }
    }
  ]
}
```

Expected response: `acks[0].ok=true`, `server_entity_type="pos_shift"`, `server_entity_id>0`.

DB expectations:
- `pos_shifts` row created with `status='open'`, `active=1`.
- `pos_sync_events` row created with `status='applied'` and entity fields populated.

### Step 5) Open table

Request event:
```json
{
  "event_id": "evt-ts-open-001",
  "type": "table_session.open",
  "client_uuid": "22222222-2222-2222-2222-222222222222",
  "payload": { "table_id": 12, "opened_at": "2026-02-04T09:10:00Z", "guests": 2 }
}
```

Expected ACK: `server_entity_type="restaurant_table_session"`.

DB expectations:
- `restaurant_table_sessions` row created with `active=1`, `status='open'`.

### Step 6) Create cash invoice + payment

Preparation:
- Pick `seq=reserved_start` and format `pos_reference = "T01-20260204-000001"` (example).

Request event:
```json
{
  "event_id": "evt-inv-001",
  "type": "invoice.finalize",
  "client_uuid": "33333333-3333-3333-3333-333333333333",
  "payload": {
    "client_uuid": "aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa",
    "pos_reference": "T01-20260204-000001",
    "payment_type": "cash",
    "customer_id": 100,
    "issue_date": "2026-02-04",
    "lines": [
      { "menu_item_id": 10, "qty": "1.000", "unit_price_cents": 500, "line_discount_cents": 0, "line_total_cents": 500 }
    ],
    "totals": { "subtotal_cents": 500, "discount_cents": 0, "tax_cents": 0, "total_cents": 500 },
    "payments": [
      { "client_uuid": "bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb", "method": "cash", "amount_cents": 500, "received_at": "2026-02-04T09:15:00Z" }
    ]
  }
}
```

Expected ACK: `server_entity_type="ar_invoice"`, `server_entity_id>0`.

DB expectations:
- `ar_invoices` row created with:
  - `source='pos'`, `pos_reference` set, `client_uuid` set, `terminal_id` set
- `ar_invoice_items` rows created
- `payments` row(s) created with `source='pos'`, `client_uuid` set
- `payment_allocations` rows created allocating to the invoice

### Step 7) Create credit invoice (no payments)

Request event:
```json
{
  "event_id": "evt-inv-002",
  "type": "invoice.finalize",
  "client_uuid": "44444444-4444-4444-4444-444444444444",
  "payload": {
    "client_uuid": "cccccccc-cccc-cccc-cccc-cccccccccccc",
    "pos_reference": "T01-20260204-000002",
    "payment_type": "credit",
    "customer_id": 100,
    "issue_date": "2026-02-04",
    "lines": [
      { "menu_item_id": 10, "qty": "1.000", "unit_price_cents": 500, "line_discount_cents": 0, "line_total_cents": 500 }
    ],
    "totals": { "subtotal_cents": 500, "discount_cents": 0, "tax_cents": 0, "total_cents": 500 }
  }
}
```

Expected ACK: success with invoice id.

DB expectations:
- `ar_invoices` created; **no** `payments` rows created for this event.

### Step 8) Create petty cash expense

Request event:
```json
{
  "event_id": "evt-pc-001",
  "type": "petty_cash.expense.create",
  "client_uuid": "55555555-5555-5555-5555-555555555555",
  "payload": {
    "client_uuid": "dddddddd-dddd-dddd-dddd-dddddddddddd",
    "wallet_id": 3,
    "category_id": 7,
    "expense_date": "2026-02-04",
    "amount_cents": 2500,
    "description": "Gas"
  }
}
```

Expected ACK: `server_entity_type="petty_cash_expense"`.

DB expectations:
- `petty_cash_expenses` created and approved (status `approved`).

### Step 9) Close table session

Request event:
```json
{
  "event_id": "evt-ts-close-001",
  "type": "table_session.close",
  "client_uuid": "66666666-6666-6666-6666-666666666666",
  "payload": { "table_session_id": 55, "closed_at": "2026-02-04T10:00:00Z" }
}
```

Expected ACK: `server_entity_type="restaurant_table_session"` with same ID.

DB expectations:
- `restaurant_table_sessions.active=0`, `status='closed'`, `closed_at` set.

### Step 10) Close shift

Request event:
```json
{
  "event_id": "evt-sh-close-001",
  "type": "shift.close",
  "client_uuid": "77777777-7777-7777-7777-777777777777",
  "payload": { "shift_id": 123, "closed_at": "2026-02-04T18:00:00Z", "closing_cash_cents": 12500 }
}
```

Expected ACK: `server_entity_type="pos_shift"`.

DB expectations:
- `pos_shifts.status='closed'`, `active=NULL`.

### Step 11) Repeat sync to prove idempotency (no duplicates)

Repeat Step 6’s exact `invoice.finalize` event with the same `events[*].client_uuid` (the sync-event UUID).

Expected:
- ACK returns `ok=true` with the **same** `server_entity_id` and the **same** `applied_at`.
- Counts do not increase:
  - `ar_invoices` count for that `pos_reference` remains 1
  - `payments` count for that `payments[*].client_uuid` remains 1

## 10. Error Codes Catalog

This catalog lists observed (implemented) codes and required-but-missing codes.

### Implemented (observed in code)

- `AUTH_ERROR` (HTTP error)
  - When: invalid credentials, device mismatch, terminal mismatch, ability missing, terminal not found.
  - Payload examples:
    - `401 { "message": "AUTH_ERROR" }` (login invalid credentials)
    - `403 { "message": "AUTH_ERROR", "reason": "DEVICE_MISMATCH" }`
  - Client action: prompt login / correct device binding / block access.
  - Evidence: `app/Http/Controllers/Api/Pos/AuthController.php`, `app/Http/Middleware/EnsurePosToken.php`, `app/Http/Controllers/Api/Pos/SyncController.php`

- `VALIDATION_ERROR` (ACK error)
  - When: payload fails validation/business rules in an event handler.
  - Client action: treat as deterministic; fix payload/state before retrying.
  - Evidence: `app/Services/POS/PosSyncService.php` (catch `ValidationException`)

- `UNSUPPORTED_TYPE` (ACK error)
  - When: `events[*].type` not in the supported list.
  - Client action: bug in client; do not retry.
  - Evidence: `app/Services/POS/PosSyncService.php::processEvent()` supported list

- `TABLE_ALREADY_OPEN` (ACK error)
  - When: another active `restaurant_table_sessions` row exists for the same `table_id`.
  - Client action: refresh sessions; resolve conflict; do not auto-retry.
  - Evidence: `app/Services/POS/PosSyncService.php::handleTableSessionOpen()`

- `INCOMPLETE_PROCESSING` (ACK error)
  - When: an event UUID exists in `pos_sync_events` with `status=processing` but no persisted entity reference (crash/timeout scenario).
  - Client action: retry later (this is retryable); if repeated, escalate (server needs investigation).
  - Evidence: `app/Services/POS/PosSyncService.php::processEvent()` processing replay branch

- `SERVER_ERROR` (ACK error)
  - When: unexpected exception while applying an event.
  - Client action: retry with backoff; if persistent, surface error and allow manual recovery.
  - Evidence: `app/Services/POS/PosSyncService.php` catch-all `Throwable`

### Required by POS team but NOT implemented (MISSING / TO BE IMPLEMENTED)

- `INSUFFICIENT_SEQUENCE`
  - Status: MISSING (sequence reservation always returns a range; no “capacity” limit besides `count<=5000`).
  - Recommended meaning: terminal cannot reserve due to business rules (e.g., day closed) or configured limit.
  - Minimal server change: add rule checks in `PosSequenceService::reserve()` and return a deterministic error.

- `DUPLICATE_POS_REFERENCE`
  - Status: not emitted as an error; duplicates are treated as success in `invoice.finalize`.
  - Client action (current behavior): safe to treat as success.

- `TRANSIENT_ERROR`
  - Status: MISSING (server currently uses `SERVER_ERROR` for unexpected exceptions).
  - Recommended meaning: retryable failure category distinct from deterministic validation.
  - Minimal server change: map known transient exceptions to `TRANSIENT_ERROR` in sync service.
