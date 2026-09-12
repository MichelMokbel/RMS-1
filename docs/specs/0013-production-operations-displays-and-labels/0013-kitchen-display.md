# Kitchen preparation display

## Decision

Create a dedicated read only projection for the kitchen role. Keep the existing richer operations view for admins and managers, but do not let the kitchen role switch into order cards, customer search, address data, status actions, or financial views.

## Experience

The display opens on today in Qatar time and the worker's assigned branch. It shows the branch name, full date, last refresh indicator, and large grouped rows containing the item or preparation name and total required quantity. It automatically refreshes without moving the worker's scroll position.

The worker may move between dates but cannot type an arbitrary branch ID. If the account has more than one assigned branch, it may select only from that allowed list. The empty state says there is nothing scheduled for preparation on that day.

## Query contract

Use `orders.scheduled_date` and `orders.branch_id`. Include every order whose status is not `Cancelled`. Join its order items, sum decimal quantities, and group by menu item ID plus description snapshot so historical text remains stable. Preserve role information from the dated Daily Dish menu when it exists, but never require it.

This rule deliberately does not require `Confirmed` or `InProduction`. Routine order statuses are not currently managed and cannot be the gate for kitchen preparation. Invoice edits have no effect. The existing invoice void workflow cancels the linked order, which removes it from the total on the next refresh.

## Security

The projection selects no customer, phone, address, price, discount, invoice, payment, or note fields. Kitchen users receive `kitchen.display`, not `orders.access` or general `operations.access`. Admin and manager access is explicit and branch checks run even when an order ID never appears in the UI.

## Verification

Test all non cancelled states, cancellation, decimal grouping, menu role enrichment, missing menu roles, assigned and unassigned branches, inactive accounts, forbidden actions, absence of sensitive text, automatic refresh, and phone, tablet, and large wall display layouts.
