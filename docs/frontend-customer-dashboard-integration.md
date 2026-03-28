# Frontend Handoff: Customer Dashboard Integration

## Goal

Build the authenticated customer dashboard where a logged-in customer can view:

- their placed orders
- their past orders
- their active subscriptions
- their invoices
- their payment history
- their current outstanding balance
- their overdue and due-today balances

This file is separate from the Daily Dish auth handoff on purpose. This document is only for the customer dashboard area after login.

## Access Rules

The customer dashboard is private.

Every dashboard endpoint requires:

- `Authorization: Bearer {token}`
- a valid customer Sanctum token
- a linked customer account

Use these routes only after customer login:

- `GET /api/customer/me`
- `GET /api/customer/dashboard`
- `GET /api/customer/orders`
- `GET /api/customer/subscriptions`
- `GET /api/customer/subscriptions/{id}`
- `GET /api/customer/invoices`
- `GET /api/customer/invoices/{id}`
- `GET /api/customer/payments`
- `GET /api/customer/payments/{id}`

## Recommended Frontend Pages

Recommended customer portal routes:

- `/account`
- `/account/orders`
- `/account/subscriptions`
- `/account/invoices`
- `/account/payments`

Recommended rule:

- if no valid customer token exists, redirect to customer login
- if token exists, load `GET /api/customer/me` first
- only render dashboard pages when that request succeeds

## Bootstrapping The Dashboard

On customer app boot:

1. read token from storage
2. call `GET /api/customer/me`
3. if it returns `401`, clear token and redirect to login
4. if it succeeds, hydrate the customer account store
5. then load dashboard summary and page-specific lists

`GET /api/customer/me` returns:

```json
{
  "account": {
    "user": {
      "id": 1,
      "name": "Portal Customer",
      "email": "portal@example.com"
    },
    "customer": {
      "id": 10,
      "name": "Portal Customer",
      "email": "portal@example.com",
      "phone": "55123456",
      "phone_e164": "+97455123456",
      "phone_verified_at": "2026-03-24T12:00:00+00:00",
      "delivery_address": "West Bay",
      "billing_address": "West Bay",
      "customer_type": "retail"
    }
  }
}
```

Use this to render:

- customer name
- email
- phone
- saved delivery address
- account header/avatar info

## Dashboard Summary API

### Endpoint

```text
GET /api/customer/dashboard
Authorization: Bearer {token}
```

### Response shape

```json
{
  "summary": {
    "active_subscriptions": 1,
    "unpaid_invoice_count": 2,
    "outstanding_balance": {
      "cents": 20000,
      "formatted": "200.00",
      "currency": "QAR"
    },
    "overdue_balance": {
      "cents": 15000,
      "formatted": "150.00",
      "currency": "QAR"
    },
    "last_payment": {
      "id": 12,
      "source": "customer",
      "method": "cash",
      "received_at": "2026-03-24T12:00:00+00:00",
      "reference": null,
      "notes": null,
      "is_voided": false,
      "amount": {
        "cents": 8000,
        "formatted": "80.00",
        "currency": "QAR"
      }
    }
  },
  "due_payments": {
    "overdue": {
      "cents": 15000,
      "formatted": "150.00",
      "currency": "QAR"
    },
    "due_today": {
      "cents": 5000,
      "formatted": "50.00",
      "currency": "QAR"
    },
    "upcoming": {
      "cents": 0,
      "formatted": "0.00",
      "currency": "QAR"
    }
  }
}
```

## Recommended Dashboard Home UI

The main `/account` page should show:

- welcome header with customer name
- outstanding balance
- overdue balance
- due today
- unpaid invoice count
- active subscriptions count
- latest payment card
- quick links to Orders, Invoices, Payments, and Subscriptions

Recommended sections:

### Balance cards

Show:

- `Outstanding Balance`
- `Overdue`
- `Due Today`
- `Upcoming`

Use the `formatted` money values for display.

### Account activity

Show:

- last payment amount and date if available
- active subscription count
- unpaid invoice count

## Orders Page

### Endpoint

```text
GET /api/customer/orders
Authorization: Bearer {token}
```

Supports:

- `?page=1`
- `?per_page=15`

### Response shape

```json
{
  "data": [
    {
      "id": 100,
      "order_number": "ORD-100",
      "source": "Website",
      "status": "pending",
      "scheduled_date": "2026-03-25",
      "scheduled_time": "12:30:00",
      "total": {
        "amount": 123.45,
        "formatted": "123.450",
        "currency": "QAR"
      },
      "invoice": {
        "id": 200,
        "invoice_number": "INV-200",
        "status": "issued"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

### Orders UI requirements

The customer should be able to distinguish current and historical orders without needing separate APIs.

Recommended frontend grouping:

- `Upcoming / Current Orders`
  - orders where `scheduled_date` is today or in the future
- `Past Orders`
  - orders where `scheduled_date` is before today

Recommended columns/cards:

- order number
- scheduled date
- scheduled time
- status
- source
- total
- linked invoice number if present

Do not show orders from any other customer. The backend already scopes this, but the UI should assume zero cross-customer access.

## Subscriptions Page

### List endpoint

```text
GET /api/customer/subscriptions
Authorization: Bearer {token}
```

### Detail endpoint

```text
GET /api/customer/subscriptions/{id}
Authorization: Bearer {token}
```

### List response shape

```json
{
  "data": [
    {
      "id": 10,
      "subscription_code": "SUB-10",
      "status": "active",
      "start_date": "2026-03-01",
      "end_date": "2026-03-31",
      "plan_meals_total": 20,
      "meals_used": 4,
      "delivery_time": "13:00:00",
      "address_snapshot": "West Bay",
      "phone_snapshot": "55123456",
      "days": [1, 2, 3, 4, 5]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

### Subscriptions UI requirements

Show:

- subscription code
- active / paused / cancelled status
- date range
- total meals
- meals used
- remaining meals
- delivery time
- delivery address snapshot
- delivery weekdays

Recommended computed field on frontend:

- `remaining_meals = plan_meals_total - meals_used`

## Invoices Page

### List endpoint

```text
GET /api/customer/invoices
Authorization: Bearer {token}
```

### Detail endpoint

```text
GET /api/customer/invoices/{id}
Authorization: Bearer {token}
```

### List/detail response shape

```json
{
  "data": [
    {
      "id": 200,
      "invoice_number": "INV-200",
      "status": "issued",
      "type": "ar",
      "issue_date": "2026-03-20",
      "due_date": "2026-03-25",
      "due_bucket": "due_today",
      "amounts": {
        "total": {
          "cents": 15000,
          "formatted": "150.00",
          "currency": "QAR"
        },
        "paid": {
          "cents": 0,
          "formatted": "0.00",
          "currency": "QAR"
        },
        "balance": {
          "cents": 15000,
          "formatted": "150.00",
          "currency": "QAR"
        }
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

### Invoices UI requirements

This page is the main source of truth for customer balance visibility.

Show:

- invoice number
- issue date
- due date
- status
- total amount
- paid amount
- remaining balance
- due bucket

Recommended frontend filters:

- `All`
- `Overdue`
- `Due Today`
- `Upcoming`
- `Paid / Zero Balance`

Map `due_bucket` visually:

- `overdue` -> high attention
- `due_today` -> medium attention
- `upcoming` -> neutral

## Payments Page

### List endpoint

```text
GET /api/customer/payments
Authorization: Bearer {token}
```

### Detail endpoint

```text
GET /api/customer/payments/{id}
Authorization: Bearer {token}
```

### Response shape

```json
{
  "data": [
    {
      "id": 300,
      "source": "customer",
      "method": "cash",
      "received_at": "2026-03-24T12:00:00+00:00",
      "reference": null,
      "notes": null,
      "is_voided": false,
      "amount": {
        "cents": 8000,
        "formatted": "80.00",
        "currency": "QAR"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

### Payments UI requirements

Show:

- payment date
- amount
- method
- reference
- notes
- voided state

Recommended sort:

- newest first

## Recommended Frontend Data Strategy

### On `/account`

Load in this order:

1. `GET /api/customer/me`
2. `GET /api/customer/dashboard`
3. optionally `GET /api/customer/orders?per_page=5`
4. optionally `GET /api/customer/invoices?per_page=5`

This gives a dashboard landing page with both summary cards and recent activity.

### On `/account/orders`

Load:

- `GET /api/customer/orders?page={n}&per_page=15`

Then split client-side into:

- upcoming/current
- past

### On `/account/invoices`

Load:

- `GET /api/customer/invoices?page={n}&per_page=15`

Then filter client-side by `due_bucket` and balance.

### On `/account/payments`

Load:

- `GET /api/customer/payments?page={n}&per_page=15`

### On `/account/subscriptions`

Load:

- `GET /api/customer/subscriptions?page={n}&per_page=15`

## Error Handling

### `401 Unauthorized`

Meaning:

- token missing
- token expired
- token invalid

Frontend action:

- clear auth state
- redirect to customer login

### `403 Forbidden`

Meaning:

- token exists but this is not a valid customer portal account

Frontend action:

- block access
- show account access error
- offer logout

### `404 Not Found`

Meaning:

- detail endpoint requested a record that does not belong to this customer
- or the record does not exist

Frontend action:

- show normal not-found state
- do not assume server error

## UX Notes

- Do not mix customer dashboard pages with the backoffice UI.
- This is a customer-facing area, not an admin panel.
- Use plain customer language:
  - `Orders`
  - `Subscriptions`
  - `Invoices`
  - `Payments`
  - `Balance`
- Make balance and due items very visible on mobile.
- Put `Outstanding Balance` and `Overdue` near the top of the dashboard.

## Implementation Checklist

- create authenticated customer portal layout
- bootstrap account state from `GET /api/customer/me`
- add dashboard summary page from `GET /api/customer/dashboard`
- add orders page using `GET /api/customer/orders`
- split orders into current and past in the UI
- add subscriptions page using `GET /api/customer/subscriptions`
- add invoices page using `GET /api/customer/invoices`
- add payments page using `GET /api/customer/payments`
- render money using API-provided `formatted` fields
- handle `401`, `403`, and `404` explicitly
- never expose or depend on backoffice routes for customer dashboard screens

