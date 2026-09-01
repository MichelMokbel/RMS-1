# Promotion redemption decision record

## Context

Codes must respect limits while hosted payment is pending. A quote cannot guarantee a scarce final use, and a late provider callback cannot safely be treated as unpaid just because a browser timer elapsed. The owner also requires a free result to stop at a request, permanently redeemable once per customer per code.

## Options considered

### Option 1: Redeem immediately at quote

This reserves uses for abandoned previews and makes ordinary cart changes consume a customer's code. It does not distinguish viewing an offer from confirming it.

### Option 2: Hold at checkout, redeem at successful completion

This protects limits and preserves accepted offers without calling an unpaid checkout a completed purchase. A zero result instead redeems atomically with its request. This is selected.

### Option 3: Validate again only after payment

This avoids holds but can take money before discovering an exhausted or changed promotion. It cannot satisfy the approved full price and frictionless completion contract.

## Rationale

Permanent redemptions are evidence, not editable customer counters. Retaining original identities while resolving combined ownership makes merges safe even when two prior legitimate uses exceed a limit. A zero request has its own idempotent result and mail intent; it does not need a payment attempt or a synthetic receipt.

The first positive purchase intent guard is shared across codes and no code. It recovers a customer's pending first payment instead of promising first purchase eligibility twice. Subsequent completed memberships can be bought deliberately without waiting for the prior meal allowance to be consumed.

## References

* [Rounding, timing and zero amount rules](../0001-payment-accounting-contract/index.md)
* [Canonical ownership and merge matrix](../0002-customer-matching-signup/index.md)
* [Paid membership lifecycle](../0007-membership-purchase/index.md)
* [Promotion administration](../0009-promotion-administration/index.md)
* [Request model](../../../app/Models/MealPlanRequest.php)
* [Existing request handling](../../../resources/views/livewire/meal-plan-requests/index.blade.php)
* [Mail service guide](../../../app/Services/Mail/AGENTS.md)

No new external promotion service or provider API is required.
