# POS Server Requirements — Restaurant Areas & Tables (Admin Config)

This document lists the **server-side changes required** so the POS can create and update **restaurant areas** and **restaurant tables** from the admin UI, and have those changes sync safely across devices.

## Current Status
- POS bootstrap already **returns** `restaurant_areas[]` and `restaurant_tables[]`.
- POS sync does **not** accept events to create/update areas or tables.
- Result: admin-created areas/tables are **local-only** and won’t propagate to other devices.

## Required Server Additions (Sync Event Types)

### 1) `restaurant_area.upsert`

**Purpose**: Create or update a restaurant area.

**Event envelope** (standard `/api/pos/sync` event):
```json
{
  "event_id": "evt-area-001",
  "type": "restaurant_area.upsert",
  "client_uuid": "uuid",
  "payload": {
    "area": {
      "id": 123,                 // optional for update; omit to create
      "name": "Main Hall",       // required
      "display_order": 1,        // required
      "active": true,            // optional, default true
      "updated_at": "2026-02-08T12:00:00Z" // optional (used for concurrency)
    }
  }
}
```

**Validation**
- `area.name`: required string, max 100
- `area.display_order`: required int
- `area.active`: optional bool
- `area.id`: optional int (if present, must exist)
- `updated_at`: optional; if present and older than server value, ignore update

**Idempotency**
- Event-level: `client_uuid` in `pos_sync_events` (already implemented).
- If `area.id` is omitted, server should create and return new ID.

**ACK on success**
```json
{
  "event_id": "evt-area-001",
  "ok": true,
  "server_entity_type": "restaurant_area",
  "server_entity_id": 123,
  "applied_at": "2026-02-08T12:00:01Z"
}
```

---

### 2) `restaurant_table.upsert`

**Purpose**: Create or update a restaurant table.

**Event envelope**:
```json
{
  "event_id": "evt-table-001",
  "type": "restaurant_table.upsert",
  "client_uuid": "uuid",
  "payload": {
    "table": {
      "id": 456,                 // optional for update; omit to create
      "area_id": 123,            // optional (nullable on server too)
      "code": "T01",             // required
      "name": "Table 1",         // required
      "capacity": 4,             // optional
      "display_order": 1,        // required
      "active": true,            // optional, default true
      "updated_at": "2026-02-08T12:00:00Z" // optional
    }
  }
}
```

**Validation**
- `table.code`: required string, max 50
- `table.name`: required string, max 100
- `table.display_order`: required int
- `table.capacity`: optional int
- `table.area_id`: optional int, must exist if provided
- `table.id`: optional int (if present, must exist)
- `updated_at`: optional; if present and older than server value, ignore update

**Idempotency**
- Event-level: `client_uuid` (already implemented).

**ACK on success**
```json
{
  "event_id": "evt-table-001",
  "ok": true,
  "server_entity_type": "restaurant_table",
  "server_entity_id": 456,
  "applied_at": "2026-02-08T12:00:01Z"
}
```

---

## Required Server Code Touchpoints

### A) POS Sync handler
Add two new handlers in `PosSyncService::processEvent()`:
- `restaurant_area.upsert`
- `restaurant_table.upsert`

### B) Validation
Add FormRequest or inline validation with:
- `area` / `table` payload schemas above
- soft concurrency using `updated_at` (optional)

### C) Data writes
- `restaurant_areas` insert/update
- `restaurant_tables` insert/update
- Ensure `updated_at` is set on write

### D) ACK response
Return `server_entity_type` / `server_entity_id` / `applied_at` for success.

---

## Notes / Compatibility

- This is **additive**; it does not break existing POS clients.
- POS already consumes `restaurant_areas` and `restaurant_tables` from bootstrap/sync deltas.
- Once implemented, new areas/tables can be created from POS admin UI and synced across devices.

---

## Optional: Dedicated Admin Endpoints (Non-POS)

If POS sync is not desired for admin edits, you can also provide:
- `POST /api/pos/areas` (create)
- `PUT /api/pos/areas/{id}` (update)
- `POST /api/pos/tables` (create)
- `PUT /api/pos/tables/{id}` (update)

However, **sync events are recommended** to remain offline-first and multi-device safe.
