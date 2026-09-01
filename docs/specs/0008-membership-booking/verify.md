# Verification contract

Execution is pending. Use isolated MySQL and mocked website/API transport.

* [ ] AC-1, AC-2: Buy 20, choose 5, return six weeks later for 4: selected 9 and available 11 with one payment. Repeat for 26 and partial discount; future paid invoices do not count twice.
* [ ] AC-1, AC-8: With an owned allowance in branch A and the configured website in branch B, membership projection returns no spendable queue and never mixes A quantities/cents. Existing account history only labels already-authorized A records unavailable; a forged A queue reference is 404, invalid branch is 422, and an explicit new B purchase remains possible without automatic checkout.
* [ ] AC-2: Three mains on one day versus three separate dates consumes three; sides consume zero; one order crossing blocks allocates exact cents from both payments.
* [ ] AC-3: Qatar today, yesterday, future, browser wrong timezone, mixed cart review and midnight between accepted paid hold and completion. Starting the draft reserves nothing.
* [ ] AC-4: Just before/equal/after 23:00 previous day, changed configured cutoff, changed profile, date replacement with original clock, stale revision and changed menu. Rejections leave all related records unchanged.
* [ ] AC-3, AC-4: A new booking for tomorrow at 23:30 today is permitted, with changes already closed; no accidental 24 hour placement rule.
* [ ] AC-5: Original and edited invoice void, repeated void, voidAndDuplicate, later duplicate draft issue, unissued reservation cancel and atomic replacement. Restore original quantity and positions once; financial draft alone cannot restore entitlement. A verified closed legacy invoice uses its exact manifest evidence to create one released attribution and opening release update; missing/conflicting evidence rejects without guessed restoration.
* [ ] AC-6: Pause with future paid/unissued bookings inside and outside inclusive range, mandatory queue correction regardless of legacy checkbox, locked invoice rollback, repeated pause/resume and booking after pause end without generated replacement meals.
* [ ] AC-7: Concurrent last meal bookings, cross block booking/void, admin allocation of committed funds, direct AR service entry, merge and replay after a replacement. No stale operation cancels the new revision.
* [ ] AC-8: Returning welcome path, explicit new purchase, login/logout/selected-branch scoped draft, changed site branch 409 and fresh quote, second device, token loss, email failure and no provider/payment/promo endpoint on covered booking.
* [ ] AC-9: Legacy generation and manual AR regressions, funded listener/resync exclusion, diagnostic rule fixtures, both application builds/lint/tests, and responsive 360/768/1024 pixel checks.
