# 0009. Membership promotion administration

**Date**: 2026-08-31
**Status**: In Progress
**Scope**: Feature 5. Design confirmed on 2026-09-01. Promotion administration is implemented and deployed on development; reservation and redemption projections remain pending 0010.

## Summary

Admins generate membership promo codes in RMS and share them manually. Each code specifies its discount, eligible plans, dates, total limit, customer limit and whether it applies to a first purchase, a renewal or both. Code history explains each use without changing purchased meal quantities or granting customer credit.

## Requirements

**User stories**:

* As an admin, I want controlled codes I can copy and send to customers myself.
* As the operator, I want to know the difference between a held use, a completed discount and a pending free request.

**Acceptance criteria**:

* **AC-1**: Only an active administrator with promotion management permission can generate, edit, activate, pause, expire and inspect codes in the default company scope. No customer or ordinary staff actor can mutate rules.
* **AC-2**: A code has one positive fixed QAR amount or percentage at most 100, required start/end dates, a positive total limit, per customer limit default one, eligible plan 20/26/both and first/renewal/both eligibility. Invalid values fail server validation.
* **AC-3**: A 100 percent offer is once per customer. A code yielding zero for an eligible plan also follows the zero request protection in 0010. Codes cannot create discount credit, reduce purchased quantity or apply to ordinary orders.
* **AC-4**: Codes are unique after normalization, generated securely, copied and manually shared. No recipient assignment, campaign sending, new message provider, branch rules or automatic distribution is added.
* **AC-5**: Rule history and held snapshots are immutable and auditable. A pause or expiry blocks new uses but does not invalidate an already accepted payment hold. Completed use is never reset by cancellation, deletion, expiry or customer merge.
* **AC-6**: Admin lists and details show rule state, limits, reserved uses, completed paid uses, free request uses and scoped references from canonical records. No raw provider or customer contact data leaks in counters or exports.
* **AC-7**: Concurrency, duplicate action, stale editing and merged history are covered with allowed/denied tests and 0006 checks. New promotion rules are not enabled for customers before 0010 is deployed.

## Decision

**Chosen option**: A small membership promotions service and RMS admin page, backed by immutable activated offer terms and separate reservation/redemption records owned by 0010. Reuse existing auth, audit and components.

**Owner confirmation, 2026-08-31**: Activated offer terms stay fixed as specified below. Admins can pause, expire or increase the total limit; a changed offer uses a copied new code. The owner also authorized an independent review of the completed plan. This confirms the promo rule, not implementation or acceptance of unresolved review findings.

## Rationale

See [rationale.md](rationale.md).

## Feature design

### Data model

Use forward MySQL migrations, same FK types as company/plan/user records, UTC instants and integer money. Never hard delete a code with history or reuse its code text.

| Record | Fields and constraints |
|---|---|
| membership_promotions | company_id; code VARCHAR(16) ASCII uppercase with unique company_id/code; discount_type fixed/percentage; nullable fixed_amount_cents positive integer or percentage_basis_points integer 1..10000, exactly one populated; purchase_eligibility first/renewal/both; starts_at, ends_at with ends_at greater; total_limit positive integer; per_customer_limit positive integer default 1 and not greater than total_limit; status draft/active/paused/expired; revision unsigned integer; first_activated_at, expired_at nullable; created_by/updated_by; timestamps |
| membership_promotion_plans | promotion_id and membership_plan_id FKs, unique pair; at least one existing same company supported plan; no branch field |
| Existing audit | Full nonpersonal rule before/after values, normalized code, actor, operation UUID/fingerprint, revision and state changes. Preserve activated offer terms and first activation snapshot |

Index company/status/starts_at/ends_at for administration. Eligibility uses exact normalized equality, not wildcard code matching. Normalize input by trimming surrounding whitespace and converting ASCII letters to uppercase; reject internal whitespace, Unicode lookalikes and characters outside the generated alphabet. Generate 12 characters using a cryptographically secure RNG from 23456789ABCDEFGHJKLMNPQRSTUVWXYZ. Retry a database unique collision up to three times, then report a temporary generation error. This is a redeemable offer identifier, not proof of account identity.

Percent input accepts at most two decimal percentage places and is stored as basis points. Fixed input accepts at most two QAR decimal places and is parsed without binary floating point. Shared calculation, capping and zero handling come from 0001. The activation validator forces per_customer_limit = 1 for percentage 100 and for fixed offers that currently make any eligible plan free. Future price changes still cannot evade 0010's zero amount once rule.

### Rule lifecycle and editing

Draft fields and eligible plans are editable with expected_revision. On first activation, freeze code, discount, eligibility, dates and per customer limit as the offer terms. Later actions can pause/resume, expire early or increase total_limit, with audit and revision increments. Changing a financial offer or its validity dates after activation creates a new generated code using Copy as draft; it never rewrites terms already promised to a customer. An increased total limit cannot be lower than completed plus protected reserved uses. No action reduces usage counters or removes redemption history.

Status transitions are draft to active, active to paused, paused to active, and draft/active/paused to expired. Expired is terminal. Effective eligibility also requires starts_at <= server_now < ends_at, so natural expiry needs no cron and future active codes display Scheduled. Display Live, Scheduled, Paused or Expired from persisted state and server date. Early expire records expired_at without rewriting the original end date. Resume never bypasses an elapsed end date. No delete action is included.

The UI accepts Qatar calendar start and end dates, explaining that both displayed dates are included. Store start at 00:00 Qatar and end at 00:00 of the day after the chosen end date, converted to UTC. Effective end is exclusive. Never interpret an administrator browser timezone as the offer timezone. A hold accepted while eligible retains the exact offer snapshot until the checkout's fixed deadline, even if the code later pauses or expires.

### Eligibility examples shown in the form

* First purchase: a customer with no completed membership can use the code. Ordinary meal orders and a pending free request do not make them a renewing member.
* Renewals: a customer with any completed membership, including an earlier cash/manual membership or merged customer history, can use it on a deliberate additional purchase, even before finishing the older allowance.
* Both: either customer can use it, subject to the code's plan and usage limits.

Examples explain the setting; actual eligibility always comes from 0010, not an admin typed customer category. A completed cancelled membership remains completed history. The code supplies no allowance itself when net is zero: the screen explicitly says Pending meal plan request only.

### Interface and action contract

Add Membership promotions under existing RMS administration navigation, using Flux/Volt and a focused Promotions service. No marketing campaign integration is implied. List 15 rows per page, retain nonpersonal filters and keep current status/limit summaries visible at 360, 768 and 1024 pixels.

| Surface | Inputs | Output and permissions |
|---|---|---|
| GET /membership-promotions | Status/plan/eligibility filters, page | Scoped list and aggregate uses; administrator plus promotions.manage |
| GET /membership-promotions/{promotion} | Owned ID, history page | Offer, usage totals and permitted linked requests/checkouts; same permission and company scope |
| Volt createPromotion | Valid rule fields, operation UUID | New draft and generated code; client cannot supply a different company |
| Volt updateDraft | Promotion, expected revision, fields, UUID | Updated draft; 409 stale or activated; 422 invalid fields |
| Volt activate/pause/resume/expire | Promotion, expected revision, UUID | New state and audit; 409 invalid transition/stale revision |
| Volt increaseLimit | Promotion, expected revision, new total, UUID | New total and audit; 422 decrease or invalid limit |
| Volt copyAsDraft | Source promotion and UUID | New generated code, draft copied terms for review; no copied usage/history |

Every action rechecks administrator status and company access within the service. Mutation locks promotion row, compares revision, and accepts the operation UUID/fingerprint in durable audit under that lock. Create action replay is serialized by the acting user row before insertion. Same UUID and payload return the original result; changed input conflicts. Promotion only administration never waits for a customer lock while holding a promotion lock. Reservation paths acquire customer then promotion, as in 0010, to avoid lock inversion.

Counts are queries over protected reservations and permanent redemptions, not editable counters on the promotion. Remaining uses = max(0, total_limit minus completed uses minus protected holds); show over limit combined customer history explicitly after merge, without deleting past uses. Zero request uses and completed paid uses are separate subtotals that sum to completed uses. A pending payment is reserved, not completed revenue. Linked detail access still requires the destination screen's permission; promotion authority alone does not grant financial evidence access.

### Value sourcing

| Value | Source |
|---|---|
| Company and actor | Default company context, active authenticated administrator |
| Code | Secure server generator and database unique constraint |
| Discount and eligible plans | Validated draft and same company membership_plans |
| Dates/effective state | Admin Qatar dates, stored UTC instants, RMS server clock |
| Eligibility explanation | Selected rule enum and 0010 completed history definition |
| Usage/reservations/remaining | 0010 canonical records and unresolved protected holds |
| History and stale edit version | Persisted revision and required accounting audit |
| Copy/share text | Saved normalized code and offer summary, never a new outbound message |

### Critical test scenarios

See [verify.md](verify.md), including permission denial, fixed/percent parsing, exact expiry boundary, held offer after pause, no use restoration and stale mutation (AC-1 through AC-7).

## Build plan

1. Add promotion schema, validation, generated codes, permissions and audit conventions (AC-1, AC-2, AC-3, AC-4).
2. Implement draft/activation/state/limit actions, immutable offer snapshots, action replay and revision checks (AC-2, AC-5, AC-7).
3. Build scoped admin forms, manual copy, eligibility examples and responsive history (AC-1, AC-3, AC-4, AC-6).
4. Connect reservation/redemption read projections and diagnostics with 0010, including merged history (AC-5, AC-6, AC-7).
5. Verify all action/validation/scope/hold tests, formatting, build and operator guidance before customer enablement (AC-1, AC-2, AC-3, AC-4, AC-5, AC-6, AC-7).

## Consequences

Activated offers are stable promises. A changed offer gets a new code instead of silently changing a customer's pending purchase. Admin sharing remains manual and no new customer messaging or credit system is introduced.
