# POS Terminal Registration API

This document describes the setup endpoints used by a fresh POS install before normal POS login.

## Purpose

- Fetch active branches to let the cashier choose a branch.
- Register the local machine as a POS terminal using `code`, `name`, and `device_id`.
- Keep `active` enabled automatically on registration.

Base prefix: `/api/pos`

## 1) Fetch Branches

### Endpoint

`POST /api/pos/setup/branches`

### Auth model

This endpoint is pre-token setup and authenticates using user credentials in the body.
User must be active, `pos_enabled = true`, and authorized for `pos.login`.

### Request body

```json
{
  "email": "string (required_without:username, max 255)",
  "username": "string (required_without:email, max 255)",
  "password": "string (required, max 255)"
}
```

### Response 200

```json
{
  "branches": [
    { "id": 1, "name": "Main Branch", "code": "MAIN" }
  ]
}
```

Only active branches the user is allowed to access are returned (`is_active = 1` + IAM branch allowlist).
Admin users see all active branches.

### Errors

- `401 { "message": "AUTH_ERROR" }` invalid credentials.
- `403 { "message": "AUTH_ERROR" }` inactive user.
- `422` validation errors.

## 2) Register Terminal

### Endpoint

`POST /api/pos/setup/terminals/register`

### Auth model

This endpoint is pre-token setup and authenticates using user credentials in the body.
User must be active, `pos_enabled = true`, and authorized for `pos.login`.

### Request body

```json
{
  "email": "string (required_without:username, max 255)",
  "username": "string (required_without:email, max 255)",
  "password": "string (required, max 255)",
  "branch_id": "int (required, exists:branches,id)",
  "code": "string (required, max 20, pattern ^[A-Za-z0-9._-]+$)",
  "name": "string (required, max 80)",
  "device_id": "string (required, max 80, pattern ^[A-Za-z0-9._-]+$)"
}
```

### Behavior

- Branch must be active (`branches.is_active = 1`).
- User must be allowed to access the selected branch (admin bypass).
- Terminal is upserted by `device_id`.
- Terminal `active` is always set to `true`.
- Terminal code must be unique within a branch.

### Response 200

```json
{
  "terminal": {
    "id": 12,
    "branch_id": 1,
    "code": "T01",
    "name": "Front Counter POS",
    "device_id": "WIN-DEVICE-001",
    "active": true
  },
  "created": true
}
```

### Errors

- `401 { "message": "AUTH_ERROR" }` invalid credentials.
- `403 { "message": "AUTH_ERROR" }` inactive user.
- `403 { "message": "AUTH_ERROR" }` POS disabled / missing POS permission / branch not allowed.
- `422` validation errors, including:
  - inactive branch
  - duplicate terminal code in the same branch

## 3) First-Install Flow

1. Call `POST /api/pos/setup/branches` with user credentials.
2. User selects a branch.
3. Call `POST /api/pos/setup/terminals/register` with selected branch + terminal details.
4. Call `POST /api/pos/login` with same credentials and `device_id` to get the Sanctum token.
