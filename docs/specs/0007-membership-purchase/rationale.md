# Membership purchase decision record

## Context

The owner requires immediate paid conversion, complete package pricing, no recurring charges, no expiry and one sequence across repeated purchases. Current RMS has one source payment per subscription and several counter writers. The website currently prices partial selections and requires at least one main dish. Both need coordinated changes.

## Options considered

### Option 1: Separate active subscription per purchase

This fits a single payment link but requires the customer or staff to choose which subscription supplies an order. It contradicts the approved sequential experience.

### Option 2: Existing subscription with funded blocks

This retains the current order and subscription interfaces and explains every allocation. It requires a queue adapter and deliberate legacy opening. This is selected.

### Option 3: One record for each purchased meal

This makes individual attribution direct but adds a new entitlement model and many records. The owner explicitly rejected that redesign; compact position ranges on booking funding are sufficient.

## Rationale

The new payment buys an allowance, not just the meals chosen today. Initial choices are bounded by the new package, so they need no reservation against older paid capacity before payment. Once paid, oldest free positions supply those meals. This honors both the sequential rule and a full payment even when no menu has been selected.

Invoice issue and payment allocation remain canonical AR work. The feature must not reuse the current value based fractional usage calculation for discounted blocks because the purchased quantity is unchanged by a discount.

## References

* [Shared accounting and apportionment contract](../0001-payment-accounting-contract/index.md)
* [Paid checkout and provider recovery](../0003-skipcash-paid-order/index.md)
* [Covered booking contract](../0008-membership-booking/index.md)
* [Subscription model](../../../app/Models/MealSubscription.php)
* [Subscription payment link and resync](../../../app/Services/Subscriptions/SubscriptionPaymentLinkService.php)
* [Request conversion UI](../../../resources/views/livewire/meal-plan-requests/index.blade.php)
* [Invoice issue listener](../../../app/Listeners/SyncSubscriptionMealsOnInvoiceIssued.php)
* [Invoice service](../../../app/Services/AR/ArInvoiceService.php)
* [Current plan pricing configuration](../../../config/pricing.php)

Website source inspected: /Applications/XAMPP/htdocs/laylakitchen/orders.php, assets/js/orders-core.js, orders-account.php and api/orders/order.php. The displayed package totals exist today; paid queue behavior does not.
