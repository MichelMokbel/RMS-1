# Verification contract

All boxes are execution gates, not completed tests. Use synthetic MySQL fixtures only after confirming the isolated test database.

* [ ] AC-1: After commit dispatch, 15 minute overlap and Qatar 02:00 schedule; transaction rollback emits no targeted work; lost dispatch is found by a sweep.
* [ ] AC-1, AC-4: Interrupt a multi batch run and resume from its saved cursor; new/changed children, same timestamps, records outside the recent window and absent parents are covered.
* [ ] AC-2, AC-6: For each rule matrix row, valid, broken and corrected fixtures; include old restored positions after later valid bookings and an explicit manual conversion of a free request.
* [ ] AC-2, AC-4, AC-8: Registry contract test proves every enabled rule implements every named required record/fingerprint field, expected/observed serializer, ownership resolver, deferral state and resolution evidence. Removing one required allocation, funding, correction or merge reader makes the rule Unhealthy rather than passing a partial evaluation.
* [ ] AC-3: Repeated runs produce one open episode and one alert intent; financial table row counts, cents, quota and source events remain byte equivalent.
* [ ] AC-4: Failed query, missing required table, partial run and changed source revision never clear a finding or report complete coverage.
* [ ] AC-5: Allowed staff, denied customer/nonpermission staff, different branch/company and unknown ownership visibility; action UUID replay and changed payload rejection.
* [ ] AC-6: Invoice edit does not cancel; invoice void does; merged original evidence is valid; zero net paid meal slices count; pending free request supplies no entitlement.
* [ ] AC-7: Checkout and checker share one issue alert; unrelated orphan finding alerts once; failed send never alerts recursively; missing recipients and stale runs remain visible.
* [ ] AC-8: Disabled future rules are Not applicable; enabled missing rules block that feature's release. Run scoped domain suites, formatting and asset build if UI changed.
