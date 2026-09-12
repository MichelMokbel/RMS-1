# Pastry full screen display

## Decision

Create a separate pastry worker route instead of adding more conditions to the current management page. The existing management page remains for admins, managers, and other explicitly permitted operators.

## Experience

The worker opens a day view for an assigned branch. Cancelled orders do not appear. A compact order rail is ordered by scheduled time then order ID. Selecting an order opens a full viewport workspace with its primary image taking the largest available region and the operational text in a stable side or bottom panel according to screen width.

Images use `object contain` behavior and keep their original proportions. Large files scale down. Small files remain at their natural dimensions rather than being stretched into visible pixelation. Multiple images use accessible previous, next, and thumbnail controls. A missing or expired image shows a neutral placeholder and a retry action without hiding the order text.

The text shows order number, customer name, scheduled date and time, pickup or delivery, destination when available, notes, and item quantities. It never shows totals, unit prices, discounts, invoices, payment state, reports, exports, editing, cancellation, or status controls.

## Security

The route requires `pastry.display` and active branch access. The server resolves a selected pastry order inside that branch and date. Guessing another order ID returns not found or forbidden. Temporary image URLs are created only after the same scoped order lookup.

The current pastry management page and every action receive explicit `pastry-orders.manage` checks. Query services and report controllers apply `BranchAccessService`. A pastry worker cannot reach menu item price search, management print views, CSV, PDF, or status mutation endpoints.

## Verification

Test assigned and unassigned branches, direct order ID access, empty days, missing images, expired URLs, multiple aspect ratios, natural size capping, keyboard navigation, full screen behavior, and the absence of financial or mutation controls at 360 px, 768 px, 1024 px, and a typical wall screen.
