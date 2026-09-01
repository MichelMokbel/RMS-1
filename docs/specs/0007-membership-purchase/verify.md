# Verification contract

These are required future execution gates, not results from this planning task.

* [ ] AC-1, AC-3: 20/26 plans with zero, one, some and full selections always quote 90000/120000 cents before promo; empty choices work with no menu; no package invoice or included delivery charge.
* [ ] AC-2, AC-9: Verified success plus exact callback/browser/job retries yields one payment, converted request, block and first subscription. Concurrent first attempts recover the original intent. Invalid ownership and wrong company/branch/currency fail without partial records.
* [ ] AC-3: Multiple main quantities on one day count individually; plate only and derived included sides; reject extras, half/full portions, ordinary items and forged totals.
* [ ] AC-4: Initial choices on a repeat purchase use old free positions first; crossing blocks uses their actual different payments and discounts. No unrelated advance is allocated.
* [ ] AC-4, AC-6: Exact sums for 120000 cents over 26 meals, one cent discount and very large partial discount with zero net slices; no negative amount or artificial unpaid remainder.
* [ ] AC-5: Finished before/equal/after expiry, delayed webhook across midnight, absent finish evidence, finance lock failure, safe replay with original dates and no second charge prompt.
* [ ] AC-6: Zero result cannot invoke paid checkout; no free allowance is exposed before explicit manual conversion.
* [ ] AC-7: No standing auto generation for customer selection mode; no elapsed expiry; invoice edit leaves quantity; queue cancellation restores only unconsumed funds and never promo use or first purchase eligibility.
* [ ] AC-8: Manifest schema, `subscriptions.queue.migrate` scope and state transitions; fixed source boundaries and canonical hash; verified, incomplete, unsupported and already-applied rows for old expired labels, mixed/voided/missing payments, invalid quota, ambiguous owner, partially mapped bookings, allocations and residual cents. Only verified rows apply; unchanged historical orders/invoices/payments/allocations/journals and preserved nonverified access are mandatory.
* [ ] AC-8, AC-9: Repeated exact apply returns the original block and mappings; crash after some rows resumes pending rows; changed source fingerprint invalidates instead of applying; conflicting origin fails. Closed opening evidence later voids through one released attribution and one `opening_released_quantity` update; retry restores no quantity or cents twice.
* [ ] AC-9: Merge before and during conversion, destination login, retained roots and source payment attribution, no new subscription choices and no double counters.
* [ ] AC-9, AC-10: Branch A allowance while the website uses branch B: spendable queue is null, account history retains only already-authorized records with `unavailable_for_selected_branch`, covered quote with the A reference is 404, and no payment starts. A same-scope queue works; missing/inactive/outside-company branch is 422; changed site branch is 409 and cannot mix totals or positions.
* [ ] AC-10: Both application contracts, token and logout state, return/reload/second device, explicit Buy another membership without automatic payment, confirmation failure and 360/768/1024 pixel views. Run subscription, request, order, AR, merge and checkout suites on isolated MySQL; website Node tests and changed PHP lint, plus builds.
