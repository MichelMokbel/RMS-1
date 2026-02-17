# Company Food Selection – Public API Documentation

This API allows an external website to let employees submit and edit their food selections for a company food project. No authentication is required; the project is identified by its slug, and order edits are secured by an `edit_token` returned when creating an order.

A project can have multiple **employee lists**, each with a different menu. For example, List 1 might offer salad, appetizers, main, sweet, and location; List 2 might offer only soup and main. **Each day has its own menu** – options are defined per date. **Main dishes and soup are list-specific** – each list has its own main and soup options. Salad, appetizer, sweet, and location are shared across all lists. The form flow is: user selects date, then list, then employee name, then the menu for that date and list.

---

## Base URL

```
https://your-domain.com/api/public/company-food/{projectSlug}
```

Replace `{projectSlug}` with the project slug (e.g. `ahr-meals-2026`). The slug is set by the admin when creating the project and is shown on the project detail page in the RMS admin.

---

## Rate Limits

| Endpoint | Limit |
|----------|-------|
| GET options, GET order | 60 requests per minute |
| POST order, PUT order | 20 requests per minute |

---

## Endpoints

### 1. Get Options

Returns options grouped by date. Each date has its own menu (different dishes per day). Use this to build the form: user picks a date, then a list, then sees employees and categories for that date and list.

**Request**

```
GET /api/public/company-food/{projectSlug}/options
```

**Response** `200 OK`

```json
{
  "success": true,
  "data": {
    "project_start": "2026-01-15",
    "project_end": "2026-01-19",
    "available_dates": ["2026-01-15", "2026-01-16"],
    "options_by_date": {
      "2026-01-15": {
        "lists": [
          {
            "id": 1,
            "name": "List 1",
            "employees": [{ "name": "John Smith" }],
            "categories": {
              "salad": [{ "id": 1, "name": "Caesar Salad" }],
              "appetizer": [...],
              "main": [...],
              "sweet": [...],
              "location": [...]
            }
          },
          {
            "id": 2,
            "name": "List 2",
            "employees": [{ "name": "Bob Wilson" }],
            "categories": {
              "soup": [{ "id": 11, "name": "Tomato Soup" }],
              "main": [...]
            }
          }
        ]
      },
      "2026-01-16": {
        "lists": [...]
      }
    }
  }
}
```

**Client flow:** User selects a date (e.g. "2026-01-15") → selects a list → show employees and categories from `options_by_date["2026-01-15"].lists` → on submit, send `order_date`, `employee_list_id`, `employee_name`, and the option IDs for that date and list.

**Error** `404 Not Found` – Project not found or inactive.

---

### 2. Create Order

Creates a new employee order. Returns `order_id` and `edit_token`; store the `edit_token` so the employee can edit their order later.

**Request**

```
POST /api/public/company-food/{projectSlug}/orders
Content-Type: application/json
```

**Body (JSON)**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `order_date` | string | Yes | Date (YYYY-MM-DD) within project period. Must match a date in `available_dates`. |
| `employee_list_id` | integer | Yes | ID from `data.options_by_date[date].lists[].id` |
| `employee_name` | string | Yes | Must match one of the employees in that list |
| `email` | string | No | Email of the person filling out the form. Optional. |

**Plus fields for each category in that list:**

- If list has `salad`: `salad_option_id` (integer, required)
- If list has `appetizer`: `appetizer_option_ids` (array of 2 integers, required)
- If list has `main`: `main_option_id` (integer, required)
- If list has `sweet`: `sweet_option_id` (integer, required)
- If list has `location`: `location_option_id` (integer, required)
- If list has `soup`: `soup_option_id` (integer, required)

**Example – List 1 (full menu)**

```json
{
  "order_date": "2026-01-15",
  "employee_list_id": 1,
  "employee_name": "John Smith",
  "email": "hr@company.com",
  "salad_option_id": 1,
  "appetizer_option_ids": [3, 4],
  "main_option_id": 6,
  "sweet_option_id": 8,
  "location_option_id": 10
}
```

**Example – List 2 (soup + main only)**

List 2 has its own soup and main options. Use option IDs from `options_by_date[date].lists[1].categories` (the List 2 entry), not from List 1.

```json
{
  "order_date": "2026-01-15",
  "employee_list_id": 2,
  "employee_name": "Bob Wilson",
  "soup_option_id": 11,
  "main_option_id": 12
}
```

**Response** `201 Created`

```json
{
  "success": true,
  "order_id": 42,
  "edit_token": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Important:** Store `edit_token` securely (e.g. in `localStorage`, or send it to the form filler by email if they provided one). It is required to view or update the order.

**Error** `422 Unprocessable Entity` – Validation failed.

**Error** `404 Not Found` – Project not found or inactive.

---

### 3. Get Order

Retrieves an existing order. Requires `edit_token` to prove the requester can access it.

**Request**

```
GET /api/public/company-food/{projectSlug}/orders/{id}?edit_token={token}
```

Or send the token in a header:

```
GET /api/public/company-food/{projectSlug}/orders/{id}
X-Edit-Token: {token}
```

**Response** `200 OK`

```json
{
  "success": true,
  "data": {
    "id": 42,
    "employee_list_id": 1,
    "order_date": "2026-01-15",
    "employee_name": "John Smith",
    "email": "john.smith@company.com",
    "salad_option_id": 1,
    "salad_option_name": "Caesar Salad",
    "appetizer_option_ids": [3, 4],
    "appetizer_option_names": ["Hummus", "Soup"],
    "main_option_id": 6,
    "main_option_name": "Grilled Chicken",
    "sweet_option_id": 8,
    "sweet_option_name": "Chocolate Cake",
    "location_option_id": 10,
    "location_option_name": "Office Floor 1",
    "soup_option_id": null,
    "soup_option_name": null,
    "created_at": "2026-02-17T20:00:00.000000Z",
    "updated_at": "2026-02-17T20:00:00.000000Z"
  }
}
```

**Error** `422 Unprocessable Entity` – Missing `edit_token`.

**Error** `404 Not Found` – Order not found or token invalid.

---

### 4. Update Order

Updates an existing order. Requires `edit_token` in the request body or `X-Edit-Token` header. Send only the fields for the order's list categories (same as Create Order for that list).

**Request**

```
PUT /api/public/company-food/{projectSlug}/orders/{id}
Content-Type: application/json
```

**Headers (optional)**

```
X-Edit-Token: {token}
```

**Body (JSON)** – Same fields as Create Order for that list. Include `edit_token` in the body if not using the header.

**Error** `422 Unprocessable Entity` – Validation failed or invalid token.

---

## Implementation Flow

### New order flow

1. Call `GET /api/public/company-food/{projectSlug}/options` to load options by date.
2. User selects a date (from `available_dates`), then a list.
3. Render form with: employee dropdown (from that list's employees) and option dropdowns for each category in that date's list (from `options_by_date[date].lists`).
4. On submit, call `POST /api/public/company-food/{projectSlug}/orders` with `order_date`, `employee_list_id`, `employee_name`, and the option IDs for that date and list.
5. Store `order_id` and `edit_token`.

### Edit order flow

1. User opens edit page (e.g. from email link or stored token).
2. Call `GET /api/public/company-food/{projectSlug}/orders/{id}?edit_token={token}` to load current order.
3. Pre-fill form with `data` from the response (use `employee_list_id` to know which categories to show).
4. On submit, call `PUT /api/public/company-food/{projectSlug}/orders/{id}` with `edit_token` in body or `X-Edit-Token` header.

---

## Validation Rules

| Field | Rules |
|-------|-------|
| `order_date` | Required, date (YYYY-MM-DD), must be within project start/end dates |
| `employee_list_id` | Required, integer, must be a valid list ID for the project |
| `employee_name` | Required, string, max 255. Must match one of the employees in that list. |
| `email` | Optional, valid email, max 255 |
| Category option IDs | Required for each category in the list. Must be active option IDs from the options endpoint. Main and soup options are list-specific – use only option IDs returned for that list. |

---

## Admin Dashboard

The Company Food module is managed from **Company Food → Projects** in the RMS admin sidebar.

### Project tabs

| Tab | Purpose |
|-----|---------|
| **Orders** | View all orders; filter by date, list, location; export CSV |
| **Kitchen Prep** | View prep counts by date, list, and location |
| **Menu** | Day grid (18 Feb – 19 Mar); click a day to add options |
| **Lists** | Manage employee lists and their categories; add/import employees per list |
| **Options** | Legacy view of all options grouped by date and category |

### Menu tab (day grid)

The **Menu** tab shows a grid of all days from **18 February to 19 March** (31 days, including 29 Feb in leap years). The year is taken from the project’s start date.

- Click a day card to open a slide-over drawer.
- In the drawer, add options for each category (salad, appetizer, main, sweet, soup, location) for that date.
- For **main** and **soup**, select which list the option belongs to before adding – each list has its own main and soup options.
- Salad, appetizer, sweet, and location are shared across lists.
- Each option can be edited, hidden/shown, or deleted.

### Lists tab

- Create employee lists (e.g. "List 1", "List 2").
- Configure which categories each list uses (List 1: full menu; List 2: soup + main only).
- Add or import employees per list.

---

## CORS

The API is intended for use from external websites. Ensure your Laravel CORS configuration allows requests from your public site's origin (see `config/cors.php`).

---

## Summary Table

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET  | `/api/public/company-food/{projectSlug}/options` | Get lists with employees and options |
| POST | `/api/public/company-food/{projectSlug}/orders` | Create order |
| GET  | `/api/public/company-food/{projectSlug}/orders/{id}` | Get order (requires edit_token) |
| PUT  | `/api/public/company-food/{projectSlug}/orders/{id}` | Update order (requires edit_token) |
