# Production operations displays and order labels rationale

## Context

The current kitchen page combines preparation totals, individual customer orders, contact details, filters, and state changing controls. Its totals include only confirmed and in production orders even though routine order states are intentionally not being managed in the current one person operation. A kitchen account can therefore see more information than it needs and can miss legitimate preparation quantities.

The current pastry page combines management and worker use. It displays order totals to pastry users, exposes status controls without an authorization check inside the action, and builds its list without applying the user's allowed branch scope unless the user voluntarily chooses a branch filter. Its image drawer is useful for management but too small for a production screen.

The current POS print service already provides terminal identity, branch alignment, queueing, claims, at least once delivery, acknowledgements, bounded retries, heartbeat, and failure visibility. Reimplementing these properties for labels would introduce two competing print systems. The missing parts are server originated jobs, label profiles and snapshots, a PDF label document type, and a local printer adapter.

Cloud hosted RMS code cannot safely or reliably open direct connections to USB or private LAN printers. Printing must be completed by an outbound local process that can reach both RMS and the operating system printer queues.

## Options considered

### Option 1: Add role conditions inside the existing kitchen and pastry pages and use browser printing

This is the smallest code change. It keeps one page for every role and uses the browser print dialog for labels.

**Pros**:

* Few new routes and records.
* No local agent is needed for manual browser printing.

**Cons**:

* The existing pages already mix query, finance, mutation, and worker concerns, so one missed condition exposes data or actions.
* Browser printing cannot provide reliable queue status, retry, fixed printer selection, or duplicate protection.
* A wall display remains cluttered by management controls.

### Option 2: Dedicated worker projections and extend the existing print queue

This keeps management pages intact, creates minimal read only worker projections, and adds label records and profiles around the proven POS queue. A local agent prints through official operating system drivers.

**Pros**:

* Strong permission and data minimization boundary.
* Focused full screen experiences.
* Reuses current delivery, retry, acknowledgement, and health behavior.
* Supports both printers without tying business logic to manufacturer commands.

**Cons**:

* Requires additive schema and a local agent update.
* Requires physical printer and media verification.

### Option 3: Build a separate kitchen application and independent label service

This creates a new application and print queue dedicated to production operations.

**Pros**:

* Complete isolation from RMS presentation code.
* Maximum freedom for a future large production team.

**Cons**:

* Duplicates authentication, deployment, monitoring, and queue reliability now.
* Adds operational overhead for a one person team.
* Creates more integration surfaces without improving the immediate workflow.

## Rationale

Option 2 fixes the current authorization problems and improves production usability without redesigning orders, invoices, fulfilment, or inventory. It respects the current one person operation by making both worker views read only and by using manual label printing first.

Extending the existing print queue is smaller and safer than a second delivery system. Rendering PDF through an official operating system driver avoids hardcoding one vendor language, while the printer profile holds the media facts needed to produce a label that actually fits.

## Current implementation evidence

* `resources/views/livewire/kitchen/ops.blade.php` exposes order cards, customer data, and state actions to the kitchen role.
* `app/Services/DailyDish/DailyDishOpsQueryService.php` currently limits preparation totals to confirmed and in production status.
* `resources/views/livewire/pastry-orders/index.blade.php` exposes totals and unguarded quick status actions, and does not apply `BranchAccessService` to its base query.
* `app/Http/Controllers/Reports/PastryOrdersReportController.php` accepts branch filters without applying actor branch scope.
* `app/Services/POS/PosPrintJobService.php` and `docs/pos-print-sse-relay.md` already implement terminal scoped at least once delivery, claims, acknowledgement, retry, and heartbeat.

## References

**Project sources**:

* Root `AGENTS.md` authorization, branch isolation, POS retry, UI, and completion rules.
* `app/Services/Orders/AGENTS.md`, `app/Services/PastryOrders/AGENTS.md`, `app/Services/POS/AGENTS.md`, `app/Services/Security/AGENTS.md`, and `resources/views/AGENTS.md`.
* `docs/pos-print-sse-relay.md`.
* Specs 0003, 0005, 0006, and 0011 for paid order, operations, consistency, and order cancellation boundaries.

**Practices and standards**:

* Least privilege and server side authorization.
* Data minimization for worker projections.
* At least once delivery with idempotent consumers.
* Outbound local print agent for private network hardware.
* Additive migration and feature gated activation.

**Links**:

* Brother QL 820NWB specifications: https://support.brother.com/g/b/spec.aspx?c=us&lang=en&prod=lpql820nwbeus
* Brother QL 820NWB manuals: https://support.brother.com/g/b/manualtop.aspx?c=us&lang=en&prod=lpql820nwbeus
* BIXOLON SLP DX220 official specification sheet: https://www.bixolon.com/_upload/product/SpecSheet_SLP-DX220_English_Jul25_V1%5B1%5D.pdf
* BIXOLON SLP DX220 official user manual: https://www.bixolon.com/_upload/manual/Manual_User_SLP-DX220_Series_ENG_V2.00.pdf
