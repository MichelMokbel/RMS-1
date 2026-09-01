# Promotion administration decision record

## Context

The owner approved fixed or percentage membership codes, configurable first purchase/renewal eligibility, required dates and limits, and manual sharing by an admin. The current application has no promo code model or redemption service. Existing marketing tools do not provide this financial eligibility contract.

## Options considered

### Option 1: Add discount text to a payment

This is simple but does not enforce dates, limits, eligibility or permanent use. It also loses the accepted offer when payment completion is delayed.

### Option 2: Dedicated membership offers with durable use records

This provides bounded rules and audit without a campaign system. It needs new admin actions and a coordinated redemption service. This is selected.

### Option 3: General promotion and campaign engine

This could support stacked coupons, branch campaigns and distribution, but all are outside the approved scope and add unnecessary configuration.

## Rationale

Freezing activated offer terms makes pending holds and completed discounts explainable. Admins can still stop new uses or expand the total allowance, and can copy an offer to a new code for different terms. The finite dates and explicit limits remain the business controls; code guessing is limited by authenticated, throttled validation rather than treated as identity proof.

The owner explicitly approved this activated offer rule on 2026-08-31 and authorized the independent design check. It is no longer an open administrative choice.

## References

* [Scope promotion rules](../../scope/scope.md)
* [Discount and rounding contract](../0001-payment-accounting-contract/index.md)
* [Redemption and eligibility](../0010-promotion-redemption/index.md)
* [Security guide](../../../app/Services/Security/AGENTS.md)
* [UI guide](../../../resources/views/AGENTS.md)
* [Existing audit model](../../../app/Models/AccountingAuditLog.php)
