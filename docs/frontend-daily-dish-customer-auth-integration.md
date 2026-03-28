# Frontend Handoff: Customer Login Required For Daily Dish Ordering

## Goal

Integrate customer authentication into the public Daily Dish flow so a visitor can browse menus publicly, but cannot place an order unless they are logged into a customer portal account.

This document reflects the backend that already exists in this repo. The frontend should implement against these endpoints and rules, not invent a parallel auth flow.

## Non-Negotiable Product Rules

1. Daily Dish menus remain public.
2. Daily Dish order submission is not public anymore.
3. A customer must be logged in before checkout can be submitted.
4. A customer must also have a verified phone number before checkout can be submitted.
5. The frontend must not try to bypass this with guest checkout or anonymous order creation.

## Backend Reality

The order endpoint is now protected by:

- `auth:sanctum`
- `customer.portal`
- `customer.phone.verified`

That means:

- no token: request fails
- wrong user type: request fails
- customer token present but phone not verified: request fails

The protected order endpoint is:

```text
POST /api/public/daily-dish/orders
```

The public menu endpoint remains:

```text
GET /api/public/daily-dish/menus
```

## Required Frontend Behavior

### Menu browsing

- Allow public users to browse Daily Dish menus.
- Allow cart building locally if desired.
- Do not allow final checkout submission unless the customer is authenticated.

### Checkout gate

When the user clicks `Checkout`, `Place Order`, or any final submit action:

1. If there is no customer token, redirect to customer login or open a login/register modal.
2. After successful login or signup verification, return the user to the checkout step with cart state preserved.
3. Before enabling the final submit button, confirm the session with `GET /api/customer/me`.
4. If `account.customer.phone_verified_at` is `null`, block ordering and show a phone-verification-required message.
5. Only call `POST /api/public/daily-dish/orders` when the customer is authenticated and phone-verified.

### Prefill behavior

Once authenticated, prefill checkout fields from `GET /api/customer/me`:

- `customerName` from `account.customer.name` or fallback to `account.user.name`
- `phone` from `account.customer.phone`
- `email` from `account.user.email`
- `address` from `account.customer.delivery_address`

These values can still be editable in the form, but the frontend should start from account data.

## Auth Endpoints

### 1. Start registration

```text
POST /api/customer/auth/register/start
```

Request body:

```json
{
  "name": "Portal Customer",
  "email": "portal@example.com",
  "password": "password123",
  "phone": "55123456",
  "address": "West Bay"
}
```

Success response:

```json
{
  "registration_token": "....",
  "phone": {
    "e164": "+97455123456",
    "masked": "+974*****456"
  }
}
```

Frontend behavior:

- move the user to OTP verification screen
- keep `registration_token`
- show the masked phone

### 2. Verify registration OTP

```text
POST /api/customer/auth/register/verify
```

Request body:

```json
{
  "registration_token": "....",
  "code": "123456"
}
```

Success response:

```json
{
  "token": "plain-text-sanctum-token",
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

Frontend behavior:

- store the returned bearer token
- mark customer as authenticated
- return the user to checkout if they started auth from checkout

### 3. Resend registration OTP

```text
POST /api/customer/auth/register/resend
```

Request body:

```json
{
  "registration_token": "...."
}
```

Notes:

- resend is rate-limited by the backend
- cooldown and max-send rules are enforced server-side
- frontend should still show a resend countdown to reduce friction

### 4. Login

```text
POST /api/customer/auth/login
```

Request body:

```json
{
  "email": "portal@example.com",
  "password": "password123"
}
```

Success response:

```json
{
  "token": "plain-text-sanctum-token",
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

Frontend behavior:

- store token
- store account snapshot
- if login was launched from checkout, return to checkout
- if `phone_verified_at` is null, do not enable ordering

### 5. Load current customer session

```text
GET /api/customer/me
Authorization: Bearer {token}
```

Use this:

- on app boot if a token exists
- after login
- after signup verification
- before rendering account-aware checkout state

### 6. Logout

```text
POST /api/customer/auth/logout
Authorization: Bearer {token}
```

After logout:

- clear token
- clear cached account state
- keep local cart if that helps conversion
- force checkout back to auth-gated mode

## Daily Dish Order Submission

### Endpoint

```text
POST /api/public/daily-dish/orders
Authorization: Bearer {token}
```

### Minimum request shape

```json
{
  "branch_id": 1,
  "customerName": "Portal Customer",
  "phone": "55123456",
  "email": "portal@example.com",
  "address": "West Bay",
  "notes": "Leave at reception",
  "mealPlan": null,
  "items": [
    {
      "key": "2026-03-05",
      "mains": [
        {
          "name": "Website Main",
          "portion": "plate",
          "qty": 1
        }
      ],
      "salad_qty": 1,
      "dessert_qty": 1,
      "day_total": 123.45
    }
  ]
}
```

### Success response

```json
{
  "success": true,
  "order_ids": [123],
  "meal_plan_request_id": null,
  "email_sent_admin": true,
  "email_sent_customer": true
}
```

## Required Frontend Routing Pattern

Recommended public website routes:

- `/daily-dish`
- `/daily-dish/login`
- `/daily-dish/register`
- `/daily-dish/verify-phone`
- `/daily-dish/checkout`

Recommended guard behavior:

- `/daily-dish`: public
- `/daily-dish/checkout`: accessible, but final submit is gated by auth
- `/daily-dish/login`: public
- `/daily-dish/register`: public
- `/daily-dish/verify-phone`: public only for in-progress signup verification

Recommended redirect behavior:

- user hits checkout without token
  - send to login/register
  - preserve cart and return path
- user completes login or OTP verification
  - return to checkout
- user logs out
  - return to menu or keep them on checkout with submit disabled

## Token Handling

Use bearer auth for customer API calls:

```http
Authorization: Bearer {token}
Accept: application/json
```

Implementation rules:

- do not rely on Laravel web session auth for the public customer experience
- treat the token as the source of truth for the website
- on app boot, if token exists, call `GET /api/customer/me`
- if `/api/customer/me` fails with `401`, clear the token

## Error Handling Contract

### `401 Unauthorized`

Meaning:

- user is not logged in
- token is missing
- token is expired or invalid

Frontend action:

- clear stale auth state if needed
- redirect to login
- preserve the user’s cart and return target

### `403 Forbidden`

Meaning:

- authenticated account is not allowed to use customer portal ordering
- or phone verification is required

Frontend action:

- if logged in and `phone_verified_at` is null, show a blocking verification-required state
- do not keep retrying order submission

### `409 Conflict`

Meaning:

- registration matched a customer record that cannot be safely linked
- or another account conflict exists

Frontend action:

- show a specific message
- send the user to support or account recovery flow

### `422 Unprocessable Entity`

Meaning:

- field validation failed
- order payload invalid
- menu item resolution failed
- OTP resend cooldown triggered

Frontend action:

- show field-level validation where possible
- keep user on the same step

## Frontend State Model

The checkout page should derive behavior from these states:

### Anonymous

- can browse menus
- can build cart
- cannot submit
- primary CTA should be `Login to order`

### Authenticating

- login/register/OTP flow active
- preserve cart locally

### Authenticated but unverified

- can view account-aware checkout
- cannot submit order
- show blocking notice that phone verification is required

### Authenticated and verified

- full checkout enabled
- submit order allowed

## Important Backend Caveat

There is one current backend limitation the frontend must respect:

- signup verification is fully supported
- verified customers can start a phone change flow
- but an existing customer who logs in with `phone_verified_at = null` does not currently have a self-serve verification endpoint after login

Frontend implication:

- if login succeeds but `account.customer.phone_verified_at` is null, block ordering and show a support message instead of showing a broken verification flow

Do not invent a fake frontend-only verification path for that case.

## Suggested UX Copy

### Guest checkout gate

`Please log in or create an account to place a Daily Dish order.`

### Unverified phone gate

`Your account must have a verified phone number before you can place a Daily Dish order.`

### OTP verification screen

`We sent a 6-digit code to your phone. Enter it to activate your account and continue to checkout.`

## Implementation Checklist

- build customer auth store for bearer token + account payload
- call `GET /api/customer/me` on app boot when token exists
- keep Daily Dish menu browsing public
- gate final checkout submission behind authenticated customer session
- gate final checkout submission behind `phone_verified_at != null`
- preserve cart through login and signup flow
- prefill checkout fields from customer account data
- call `POST /api/public/daily-dish/orders` with bearer token
- handle `401`, `403`, `409`, and `422` explicitly
- do not offer guest order submission anywhere in the Daily Dish flow

