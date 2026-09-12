# 0013. Production operations displays and order labels

**Date**: 2026-09-12
**Status**: In Progress

## Summary

Give kitchen and pastry staff dedicated read only screens that show only what they need to prepare. Add compact order labels through the existing reliable print queue and a local print agent, with printer and label dimensions configured in RMS. Prices remain visible to authorized managers but never appear on worker screens or labels.

## Structure

* [Kitchen display](0013-kitchen-display.md) defines daily preparation totals and kitchen access.
* [Pastry display](0013-pastry-display.md) defines the full screen image led pastry workflow.
* [Order labels](0013-order-labels.md) defines printer setup, label content, queue delivery, retries, and reprints.
* [Verification plan](verify.md) defines automated, browser, device, and deployment evidence.

## Requirements

**User stories**:

* As a kitchen worker, I want a large daily preparation summary so I can prepare the required quantities without reading customer or financial information.
* As a pastry worker, I want each pastry order and its reference images to fill the screen so I can reproduce the requested design accurately.
* As an operator, I want to print a compact label for an order so its customer, destination, date, and contents remain attached to the correct package.
* As an administrator, I want printer and label settings in RMS so a hardware change does not require editing environment files or application code.
* As a manager, I want the existing management and finance views to remain available to authorized users.

**Acceptance criteria**:

* **AC-1**: Kitchen and pastry access is enforced by dedicated permissions and allowed branch assignments. An allowed worker can read only records for an assigned branch. An unassigned branch, inactive user, customer account, or unrelated staff account is denied by the server.
* **AC-2**: A kitchen worker sees only the selected service date, branch name, last refresh time, and grouped preparation item quantities. The screen contains no prices, totals, discounts, customer names, phone numbers, addresses, invoice data, order cards, exports, or order status actions.
* **AC-3**: Kitchen preparation totals include every order scheduled for the selected day and branch except a cancelled order. They group equivalent order item snapshots by menu item and description, retain decimal quantities, update automatically, and do not depend on a worker changing order states.
* **AC-4**: A pastry worker sees a read only daily list of non cancelled orders and a full screen order display with the order number, customer name, scheduled date and time, type, destination, notes, item quantities, and reference images. It contains no prices, totals, discounts, invoice data, exports, editing, cancellation, or status actions.
* **AC-5**: Pastry images preserve their aspect ratio, scale down to fit the available viewport, do not scale above their natural dimensions, and support multiple images without cropping. Missing or unavailable images produce a clear placeholder while retaining the order details.
* **AC-6**: Admin and manager management pages retain their existing price and workflow capabilities. Every pastry mutation, direct record view, print view, report, and export applies its own permission and branch checks instead of relying on hidden controls.
* **AC-7**: An administrator can create an active printer profile for an owned company and branch, assign it to a registered local print terminal, select its department, OS printer queue, verified model, resolution, media mode, exact media dimensions, and default copy count, then preview a sample label before activation. Changes are revision protected and audited.
* **AC-8**: An authorized operator can print one label for one ordinary or pastry order, or print labels for the eligible orders on one service date. The default label contains the Layla Kitchen name, order number, service date and time, customer name, destination, compact item quantities, copy number, and a small machine readable order reference. It never contains a price, discount, payment method, invoice balance, or customer phone number.
* **AC-9**: The server creates a fixed label snapshot before enqueueing. Editing the order later does not alter an already printed label. An explicit reprint creates a new numbered print attempt from the current approved snapshot and records the actor, reason, source label, printer, time, and outcome.
* **AC-10**: Label jobs use the current at least once POS print delivery contract with a nullable server origin, a unique server job identifier, a target terminal, a printer profile, a claim token, bounded retries, and an acknowledgement. Repeated delivery of one claim cannot produce a second physical label, while an explicit authorized reprint can.
* **AC-11**: The local print agent accepts only assigned terminal jobs and allowlisted label document types. It routes each job only to the configured local OS printer queue, rejects arbitrary paths or commands, validates payload size and media metadata, and reports printed or failed without logging customer details.
* **AC-12**: Label printing is disabled by default until a profile has verified model, media, terminal, and successful device test evidence. Disabling a profile blocks new jobs but does not erase job history. Queued jobs can be cancelled or reassigned by an administrator without changing the underlying order.
* **AC-13**: RMS shows printer availability, agent heartbeat, queued jobs, failures, retry count, printed time, and reprint lineage. A failed or offline printer does not block order creation, invoice creation, payment, kitchen totals, or pastry viewing.

## Decision

**Chosen option**: Dedicated worker displays with the existing print queue extended for RMS labels.

Keep the current management pages for authorized operators. Route kitchen and pastry workers to smaller read only screens, and extend the current terminal based print queue so server generated PDF labels can be delivered to an operating system printer queue inside the restaurant.

## Rationale

Reasoning, alternatives, current code diagnosis, and hardware evidence are in [rationale.md](rationale.md).

## Feature design

### Cross part contract

Worker displays are projections, not new order workflows. They never change order status, invoice state, inventory, or accounting. The order remains the source for preparation data, and an invoice void continues to cancel the related order through its owning workflow.

Printer delivery is operationally separate from commerce. Orders and payments complete even when a printer is unavailable. Labels contain an immutable operational snapshot and can be printed or explicitly reprinted without editing the order.

### Data model sketch

| Record | Purpose | Main constraints |
|---|---|---|
| Existing users, roles, permissions, and branch access | Worker identity and scope | Add `kitchen.display`, `pastry.display`, `order-labels.print`, and `order-label-printers.manage`. Kitchen and pastry roles receive only their display permission by default |
| Existing orders and order items | Kitchen source and ordinary label source | Preparation totals exclude only `Cancelled`. Reads are branch and service date scoped |
| Existing pastry orders, items, and images | Pastry display and pastry label source | Reads are branch and service date scoped. Image access stays presigned and time limited |
| `order_label_printer_profiles` | Audited RMS configuration for one physical printer | Company and branch foreign keys, target POS terminal, department, unique profile code, verified model code, OS queue name, DPI, fixed or continuous media, width and height in tenths of a millimetre, copies, active state, version, actor timestamps |
| `order_label_prints` | Stable source snapshot and reprint lineage | UUID, company, branch, profile, source type and ID, service date, encrypted or protected snapshot, snapshot hash, copy count, sequence, reprint parent, reason, actor, linked POS print job, status timestamps. Unique profile, source type, source ID, and sequence |
| Existing `pos_print_jobs` | Delivery, claim, retry, acknowledgement, and heartbeat | Add nullable label print foreign key and unique nullable server job UUID. Existing POS client jobs remain unchanged |

All new IDs use matching unsigned integer foreign keys. Media dimensions use integers in tenths of a millimetre to avoid floating point comparisons. Printer model is descriptive and does not decide security. The active profile and exact media dimensions decide rendering.

### State transitions

```text
printer profile: inactive -> active -> inactive
label print: preparing -> queued -> claimed -> printed
label print: queued or claimed -> failed -> queued
label print: queued -> cancelled
printed label -> explicit reprint with a new sequence
```

No label state changes the linked order. Cancelling or voiding an order does not delete historical labels.

### API surface

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| Existing kitchen Volt route | GET | branch, service date | preparation totals and refresh time | `kitchen.display` plus branch access | 403, 404 |
| `/pastry-orders/display/{branch}/{date}` | GET | branch, service date, optional selected order | scoped daily list and selected order display | `pastry.display` plus branch access | 403, 404 |
| RMS printer settings routes | Livewire actions | profile fields and expected revision | saved profile and sample preview | `order-label-printers.manage` plus company and branch access | 403, 409, 422 |
| RMS order label action | Livewire action | source type, source ID, profile, copies, request UUID | label record and queue status | `order-labels.print` plus branch access | 403, 404, 409, 422, 503 |
| RMS service date label action | Livewire action | source type, branch, date, profile, request UUID | eligible count and job statuses | `order-labels.print` plus branch access | 403, 409, 422, 503 |
| Existing `/api/pos/print-jobs/stream` and pull routes | GET | terminal identity and cursor | assigned label PDF job and claim | terminal token plus branch alignment | 403, 409 |
| Existing `/api/pos/print-jobs/{job}/ack` | POST | claim token, result, bounded error | final or retry state | claiming terminal token | 403, 409, 422 |

### Value sourcing

| Action | Value produced or displayed | Source |
|---|---|---|
| Resolve kitchen branch | branch name and allowed records | route branch, active branch table, authenticated user branch access |
| Calculate kitchen quantity | grouped total quantity | `order_items.quantity` joined to non cancelled `orders` for branch and scheduled date |
| Resolve pastry day | ordered pastry list | `pastry_orders.branch_id`, scheduled date, and stable scheduled time then ID ordering |
| Show pastry image | temporary image URL | `PastryOrderImageService` from the stored image disk and path |
| Prevent image pixelation | rendered image dimensions | browser natural image dimensions capped by available viewport dimensions |
| Resolve company for printer | owning company | branch company relation and authenticated actor company access |
| Render label identity | brand, order number, date, time, customer, destination | company settings and fixed snapshots on the selected order |
| Render label items | description and quantity | selected order item description and quantity snapshots ordered by sort order and ID |
| Render page size | PDF width and height | active printer profile media dimensions. Continuous media height is calculated from content within the configured minimum and maximum |
| Select physical printer | operating system printer queue | active printer profile queue name delivered only to its assigned terminal |
| Deduplicate delivery | one physical output per claim | label UUID, POS job server UUID, job ID, and claim token stored by the local agent |
| Record reprint | next sequence and lineage | locked latest `order_label_prints` row for profile and source, explicit reason, actor |
| Show health | online, queued, failed, printed | terminal heartbeat, POS job status, acknowledgement, and label print timestamps |

### Key invariants

* Worker permissions grant projections only. They never imply order, finance, export, report, customer, or catalog management.
* Every list, record open, image request, mutation, report, export, and print action enforces company and branch scope on the server.
* Price fields are not selected into worker projections and are not merely hidden by CSS.
* Kitchen totals do not require status button work. A cancelled order contributes zero; every other scheduled order contributes its item quantities.
* One acknowledged claim is physically printed at most once by the agent. A reprint is a new auditable business action, not a retry of an acknowledged claim.
* The server never opens a connection to a restaurant printer. The authenticated local agent initiates the connection to RMS and then uses an allowlisted OS printer queue.
* No printer failure can roll back or delay an order, invoice, payment, membership allocation, or other financial record.
* Label snapshots contain only the minimum operational data required for preparation and handoff.

### Security model

Admins receive all four new permissions. Managers receive both display permissions and label print permission. Kitchen receives only `kitchen.display`. Pastry users receive only `pastry.display`. Printer profile management remains administrator only by default. Assigning another role is an explicit IAM action.

The kitchen projection excludes all personal and financial fields. The pastry projection includes only the customer and fulfilment details needed to identify and prepare its order. Label actions resolve the source record by allowed branch rather than accepting a trusted company or branch from the browser.

The local agent uses the existing POS terminal authentication and branch alignment. The agent never accepts shell commands, paths, URLs, or printer queue overrides from a label payload. Queue names come from an active server profile assigned to that terminal and are checked against the agent's local allowlist.

### Configuration required

No printer secret is stored in an environment file. RMS stores the profile and target terminal. The local agent requires its existing terminal identity, RMS base URL, locally allowlisted printer queue names, and an encrypted local credential or token provisioned during device registration.

Before a profile can become active, the administrator must confirm the exact printer model, connection, driver, installed label stock, printable width and height, DPI, and successful sample output.

### Critical test scenarios

* Kitchen happy path: two non cancelled orders on the same day group repeated items and preserve decimal quantities while a cancelled order contributes nothing, verifies **AC-2**, **AC-3**.
* Kitchen permission: a kitchen user can read one assigned branch but cannot see another branch, customer data, order cards, or status controls, verifies **AC-1**, **AC-2**.
* Pastry happy path: a pastry user opens one assigned order with multiple portrait and landscape images and sees full details without pricing or mutation actions, verifies **AC-4**, **AC-5**.
* Pastry direct access: a pastry user cannot call management actions, exports, reports, print views, or open an unassigned branch order by ID, verifies **AC-1**, **AC-6**.
* Label happy path: an authorized operator prints one order through one active profile and the agent acknowledges one correctly sized price free label, verifies **AC-7**, **AC-8**, **AC-10**, **AC-11**.
* Label retry: the same claim is delivered repeatedly and the agent prints it once, then an explicit reprint creates sequence two with its reason and actor, verifies **AC-9**, **AC-10**.
* Printer unavailable: the order remains complete while the label is queued, health is visible, retries are bounded, and an administrator can cancel or reassign it, verifies **AC-12**, **AC-13**.
* Payload security: oversized data, unsupported document type, unknown queue, arbitrary path, wrong terminal, and cross branch source are rejected without customer data in logs, verifies **AC-1**, **AC-11**.

## Build plan

1. [x] Add dedicated permissions, branch scoped query services, and route separation. Remove pastry worker access from management, report, export, print, search, and mutation surfaces, satisfies **AC-1**, **AC-6**.
2. [x] Replace the kitchen role experience with the read only preparation display and change its query contract to all non cancelled scheduled orders, satisfies **AC-2**, **AC-3**.
3. [x] Add the read only pastry day list and full screen image display, while retaining existing admin and manager management screens, satisfies **AC-4**, **AC-5**, **AC-6**.
4. [x] Add printer profile and label print schema, services, permissions, audit, RMS configuration, preview, and feature off gate, satisfies **AC-7**, **AC-8**, **AC-9**, **AC-12**, **AC-13**.
5. [x] Extend the existing POS print service with server origin label jobs, fixed PDF snapshots, terminal routing, explicit reprints, batch enqueue, and operational history, satisfies **AC-8**, **AC-9**, **AC-10**, **AC-13**.
6. [x] Add the cross platform local agent label handler with local queue allowlisting, payload validation, sanitized logging, and durable claim deduplication. Exact OS commands remain local configuration, satisfies the software portion of **AC-10**, **AC-11**.
7. [ ] Run the complete automated, browser, printer, role, branch, responsive, queue recovery, and deployment verification matrix before activating either printer profile, satisfies **AC-1** through **AC-13**.

## Migration plan

**Strategy**: Feature flagged strangler change.

**Phases**:

1. Add permissions and dedicated worker screens while keeping existing manager screens unchanged.
2. Add nullable print queue extensions and new printer profile and label history tables with every profile inactive.
3. Register the local agent and one printer, preview and print synthetic labels, then enable only that profile.
4. Run several real but non production labels, verify fit and scan quality, then enable the second profile.
5. Route worker accounts to the dedicated screens and retain admin management access for fallback.

**Rollback**: Disable printer profiles and worker navigation. Existing orders, pastry management, invoices, payments, and POS receipts continue unchanged. Additive print history and permissions remain for audit and can be removed only in a later migration.

**Risks**: Incorrect media dimensions can clip labels. An overly broad route can expose customer or financial data. At least once delivery can duplicate a physical label if the agent does not persist claim acknowledgements. The activation gate, server projections, branch tests, profile preview, and agent deduplication address these risks.

## Consequences

**Positive**:

* Workers receive focused large screen tools without finance noise or routine status work.
* Manager workflows remain intact.
* Both label printers use one audited order label contract and one proven delivery mechanism.
* Printer failure becomes visible and recoverable without affecting sales or accounting.

**Negative and tradeoffs**:

* A small local agent must remain online inside the restaurant.
* Exact printer models, drivers, connections, and label stock must be verified on the physical hardware.
* The existing print agent must learn one new PDF label document type.

**Neutral**:

* The first release uses manual single order and service date batch printing. It does not automatically print on payment or order creation.
* The first release prints one label per order by default. An authorized operator can set copies when one order needs several physical packages.

## Follow up

* [ ] Confirm each physical printer model from its label, its connection method, installed media dimensions, and the operating system of the always on local agent computer.
* [ ] Confirm the preferred BIXOLON and Brother printer for kitchen and pastry after the sample labels are compared.
