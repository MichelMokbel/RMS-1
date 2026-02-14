# Identity & Access Management (IAM)

## Overview

IAM centralizes user access management across web modules and POS.

Scope:
- User lifecycle: create, edit, activate, deactivate, password reset.
- Multi-role assignment.
- Direct permission assignment.
- POS eligibility controls.
- Branch allowlists for non-admin users.

Admin-only hub routes:
- `GET /iam/users`
- `GET /iam/users/create`
- `GET /iam/users/{user}/edit`
- `GET /iam/roles`
- `GET /iam/permissions`

Legacy `/users*` routes redirect to `/iam/users*`.

## Data Model

### `users.pos_enabled`
- Type: boolean
- Default: `false`
- Meaning: whether the user is eligible for POS login (in addition to permissions).

### `user_branch_access`
- `id` bigint primary key
- `user_id` int (FK -> `users.id`, cascade)
- `branch_id` int (FK -> `branches.id`, cascade)
- Unique key: (`user_id`, `branch_id`)

Admin users bypass branch allowlists.

## Permission Catalog

Seeded baseline permissions:
- `iam.users.manage`
- `iam.roles.manage`
- `iam.roles.view`
- `iam.permissions.assign`
- `settings.pos_terminals.manage`
- `pos.login`
- `orders.access`
- `catalog.access`
- `operations.access`
- `receivables.access`
- `finance.access`
- `reports.access`

## Role Baseline Mapping

- `admin`: all permissions.
- `manager`: settings + POS + operational/finance/reporting modules.
- `cashier`: POS + orders/catalog/operations.
- `waiter`: POS + orders/catalog.
- `kitchen`: operations.
- `staff`: finance + reports.

## POS Enforcement Rules

For POS setup/login/runtime:
- User must be active.
- User must have `pos_enabled = true`.
- User must have `pos.login` (or admin).
- User must be allowed for the terminal branch (admin bypass).

Applies to:
- `POST /api/pos/setup/branches`
- `POST /api/pos/setup/terminals/register`
- `POST /api/pos/login`
- Authenticated POS requests via `pos.token` middleware.

## Web Enforcement Rules

- Route access now supports direct permissions via `role_or_permission`.
- Branch keys `branch`, `branchId`, `branch_id` are validated by middleware for non-admin users.
- For branch-optional order print routes, non-admin users are restricted to their allowlist when no branch filter is provided.

## Safety Policies

IAM mutations enforce:
- Cannot remove/deactivate the last active admin.
- Cannot self-lock out from IAM administration.
