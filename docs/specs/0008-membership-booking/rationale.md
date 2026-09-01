# Covered booking decision record

## Context

The website currently begins with a plan picker and submits the same order payload for all selections. It has no owned allowance mutation API. RMS permits multiple subscription orders per day, but current generation, invoice issue and void paths do not share quantity and funding attribution. Pause currently changes status and records dates without voiding existing bookings.

## Options considered

### Option 1: Reuse purchase checkout for every visit

This minimizes routes but makes another payment or conversion easy to trigger accidentally and obscures zero payment booking. It is unsuitable for the approved experience.

### Option 2: Dedicated covered booking service over existing records

This keeps payment and allowance use separate, with one shared service also used for initial paid choices. It needs explicit adapters for invoice and pause writers. This is selected.

### Option 3: New meal fulfillment and delivery system

This could represent detailed operational stages but creates the staff workload and per meal model the owner rejected. Paid invoice usage and existing order cancellation remain sufficient.

## Rationale

Stable booking revisions retain cancelled order and invoice history while satisfying the existing unique block/order funding key. They do not add a separate cancellation model. Original funding positions, rather than the editable invoice lines, explain quota restoration. Upcoming paid invoices are scheduled selections, not proof of physical delivery.

## References

* [Membership counting and date contract](../0001-payment-accounting-contract/index.md)
* [Membership purchase and legacy opening](../0007-membership-purchase/index.md)
* [Subscription lifecycle service](../../../app/Services/Subscriptions/MealSubscriptionService.php)
* [Current pause and optional cancellation UI](../../../resources/views/livewire/subscriptions/show.blade.php)
* [Subscription order generation](../../../app/Services/Orders/SubscriptionOrderGenerationService.php)
* [Manual order creation](../../../app/Services/Orders/OrderCreateService.php)
* [AR invoice service](../../../app/Services/AR/ArInvoiceService.php)
* [Multiple orders per day migration](../../../database/migrations/2026_01_30_000005_allow_multiple_meal_subscription_orders_per_day.php)
* [Invoice usage listener](../../../app/Listeners/SyncSubscriptionMealsOnInvoiceIssued.php)

Website source inspected: /Applications/XAMPP/htdocs/laylakitchen/orders-menu.php, orders-account.php, components/orders-page.php and assets/js/orders-core.js.
