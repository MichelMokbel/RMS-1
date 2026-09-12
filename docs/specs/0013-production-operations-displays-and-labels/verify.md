# 0013. Production operations displays and order labels verification

## Automated evidence required

* Permission tests for admin, manager, kitchen, pastry, unrelated staff, customer, inactive user, assigned branch, and unassigned branch.
* Kitchen query tests for every current order status, cancellation exclusion, decimal quantities, stable grouping, missing menu roles, and automatic refresh output.
* Pastry query and component tests for direct ID access, branch filtering, price exclusion, mutation denial, management preservation, multiple images, and missing images.
* Report and export tests proving branch scope and proving pastry workers cannot call management surfaces.
* Printer profile tests for company and branch ownership, validation, optimistic revision, audit, inactive defaults, activation gates, and sample preview.
* Label rendering tests for exact PDF page dimensions, deterministic line wrapping, excluded finance and phone fields, multiple copies, long names, long addresses, and item overflow.
* Print queue tests for server origin idempotency, terminal assignment, branch alignment, claim replay, acknowledgement loss, bounded retry, cancellation, reassignment, and explicit reprint lineage.
* Agent contract tests for local queue allowlisting, unsupported document types, oversized payloads, duplicate claim persistence, sanitized errors, offline recovery, and successful acknowledgement.
* Existing POS receipt print tests must continue unchanged.
* Run targeted domain suites, then the full Laravel suite, formatting, and the RMS production asset build on a safe MySQL test database.

## Browser evidence required

Check kitchen and pastry screens at about 360 px, 768 px, 1024 px, and the actual production display resolution. Verify keyboard and touch access, stable auto refresh, empty states, dark mode, full screen entry and exit, multiple image navigation, natural image size, and no hidden overflow containing price or customer data outside each role's contract.

## Physical printer evidence required

For each printer, photograph or record the exact model label, connection, installed driver version, operating system queue name, media stock code, measured label dimensions, resolution, and connection method. Print synthetic short, normal, and maximum content labels. Verify margins, wrapping, cutter behavior, QR or barcode scanning, quantity readability, Arabic and English glyph behavior for stored data, and repeated delivery deduplication.

The profile remains inactive until the sample is signed off. After activation, print a controlled batch, disconnect and reconnect the printer, recover a failed job, perform one explicit reprint, and confirm no order or invoice data changed.

## Deployment gate

Deploy additive migrations and code with every printer profile inactive. Confirm queue workers, terminal heartbeat, private image access, permission cache refresh, reverse proxy streaming, and logs before assigning worker accounts. Activate one printer profile at a time. Keep the existing manager pages and browser print views available as an administrator fallback until both physical printer checks pass.

## Automated evidence recorded

On 2026-09-12 the focused Laravel suite passed 46 tests and 271 assertions on a disposable MySQL database. It covered kitchen and pastry role/branch projections, scoped pastry management prints, printer profiles, preview, activation gating, immutable label rendering, server queue idempotency, claims, acknowledgements, reprints, cancellation, reassignment, RMS access, and the existing POS readiness suite. The local Python agent contract passed three tests for queue/document rejection, durable replay deduplication, and ambiguous submission handling. Laravel Pint and the Vite production build passed.

Physical printer and actual display-resolution evidence remains open and is intentionally required before this spec can be marked complete.
