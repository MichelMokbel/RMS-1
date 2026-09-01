# Verification plan for the payment and accounting contract

## Environment safety

* Run Laravel feature tests only against the configured `store_test` MySQL database.
* Use a fake SkipCash client for normal automated tests and isolated sandbox credentials for the final provider check.
* Use synthetic webhook and settlement fixtures. Do not commit production payloads, customer details, credentials, or the supplied workbook.
* Run settlement posting tests in open and closed accounting periods with explicit company and branch fixtures.

## Acceptance matrix

| Contract | Required evidence |
|---|---|
| **AC-1** | Server plan totals, QAR minor units, zero tax, delivery included, fixed discount caps, basis point half cent rounding, 26 meal rounding, bounded per meal discounts, forged browser total rejected |
| **AC-2** | One and several future dated ordinary orders, one payment, separate paid invoices, exact allocations, no routine order state change, booked customer wording |
| **AC-3** | Ordinary and membership journal lines, dedicated SkipCash clearing, customer advance, allocation, invoice issue, no duplicate revenue |
| **AC-4** | First purchase, repeat purchase, zero initial selections, immediate conversion, no package invoice, no recurring charge, no old credit use |
| **AC-5** | Oldest block first, one booking crossing two blocks, deterministic gross, discount and net totals, stable positions after void, correct payment allocations |
| **AC-6** | Several main dishes on one day, several days, included sides, family quantities under one customer, existing address snapshots, reserved then invoiced without double use, verified legacy opening balances, zero net meal slice in a positive package, no expiry, later covered booking without payment |
| **AC-7** | Fixed and percent codes, date and usage limits, plan and purchase eligibility, one code, partial discount allowance unchanged, valid 100 percent request only, exact retry, rejected request retry, permanent redemption after cancellation and merge |
| **AC-8** | Website credit request rejected, administrator allocation allowed only for uncommitted balance, support allocation denied, late membership and second real captures retained once, ordinary late capture completes the original order, unexpected reversal review, no refund or automatic balance change |
| **AC-9** | Today only, mixed, future only, Qatar clock, 15 minute snapshot, settings change, 11:58 PM start and 12:02 AM payment, ordinary success before/at/after expiry with normal confirmation and no expiry credit |
| **AC-10** | Change before cutoff, denial at cutoff, booking snapshot after settings change, invoice edit, invoice void, repeated void, pause across several future invoices |
| **AC-11** | Valid webhook, invalid signature, explicit provider status mapping, browser return, missing webhook recovery, unsigned or absent finish time, provider detail mismatch, delayed notification using verified provider finish time |
| **AC-12** | Every internal state maps to one allowed public status with purchase confirmed, message, recovery reference, and separate payable, paid, confirmed, and retained credit amounts |
| **AC-13** | Exact API retry, changed payload under one UUID, one provider create dispatch, duplicate event, out of order event, concurrent last credit booking, duplicate file, overlapping report, economic fee identities, repeated settlement post |
| **AC-14** | Provider create timeout with known and unknown IDs, response lost before URL persistence, held snapshots create no operational orders, webhook database failure returns retry response, closed historical date recovery, email failure |
| **AC-15** | Allowed customer, wrong customer, accepted bypass while enabled, genuine proof required after bypass shutdown, public plans, company and branch mismatch, default company snapshot retained after default change |
| **AC-16** | Sample sale and settlement fee rows, commission counted once across exports, net payout, default bank, evidenced bank date, forbidden posting date override, phone only suggestion, provider branch mapping, `skipcashCoupon` isolation, missing `orderId`, blank and `Null`, closed period |
| **AC-17** | Secret redaction, encrypted raw body access, 90 day purge, private workbook, rate limits, no card or masked card number in normalized records |
| **AC-18** | Exact linking under the accepted 0002 policy, low confidence continuation, matching disabled fallback, legacy token continuity, names only Gemini and failure, source and target merge with immutable proof and event references, destination login survival, exact merge retry |
| **AC-19** | Immutable terms version, effective time, URL and hash, version change before and after attempt creation, request acceptance snapshot, each confirmation email, email failure, no automatic renewal, request wording distinct from active membership |
| **AC-20** | New website quote and status path, legacy path launch setting, covered booking during gateway outage, zero amount request during gateway outage |

## Accounting proofs

For every scenario, assert balanced journal lines and the source event key.

| Event | Debit | Credit |
|---|---|---|
| Ordinary invoice issue | AR | Revenue |
| Ordinary SkipCash receipt | SkipCash clearing | AR |
| Membership SkipCash receipt | SkipCash clearing | Customer advances |
| Membership daily invoice issue | AR | Revenue |
| Membership allocation | Customer advances | AR |
| SkipCash payout | Default bank for net, commission expense, settlement fee expense | SkipCash clearing for gross |
| Daily invoice void | Reverse invoice issue and allocation | Restore customer advance through reversal |

For a zero net slice in a positively paid package, assert an issued zero outstanding invoice and one main dish usage, with no fabricated payment, zero value allocation, or monetary journal. A zero net package instead creates only the pending request.

## Review regression cases

These are required implementation tests, not tests already executed by this documentation change.

| Case | Required assertion |
|---|---|
| Replayed create after response loss | One dispatch, one attempt, same client reference. Recover the known provider ID and original URL; if no ID is known, return pending recovery with no second create call |
| Changed retry and terminal retry | Changed payload with the same UUID returns 409. The original payload returns the existing pending or terminal result, even after price, terms, or settings changes |
| Unpaid and paid visibility | Zero order rows or kitchen and order sheet side effects before paid activation. Exactly one order per target afterward, with no manually advanced order states |
| Provider time and status | Test IDs 0, 1, 2, 3, 4, 5, 6, 7, 8, 12, and an unknown value. A signed body with an unsigned altered finish time cannot decide eligibility. Missing or inconsistent time remains paid processing when payment is verified |
| Hold boundary and delayed webhook | First matching ordinary capture before, exactly at, and after expiry creates the original order, paid invoice, payment, allocation, and normal email once, including after midnight or an unpaid target release. Preserve original selections, dates, and price. A pending or unknown provider result at timer expiry stays in recovery without another payment prompt. Membership finish exactly at expiry retains its existing credit outcome; uncertainty does not release scarce promo reservations as proven nonpayment |
| Closed historical receipt date | A paid event captured in a locked period creates durable evidence but no partial accounting or entitlement. Replaying next day cannot substitute that open date; authorized resolution retains the original event dates |
| Funding transition | With quota 20, two used and three reserved gives 15 available. Invoicing those three changes used to five and reserved to zero, still 15 available. Repeat listeners do not change it again |
| Cutoff and release | A saved 23:00 Qatar cutoff remains after settings change. A moved date uses that saved clock time. Edit then void restores original quantity and positions and releases the actual allocation once |
| Legacy opening | The reviewed manifest proves existing payment, historical allocations, opening used ranges and current bookings without reposting history. Exact repeat apply is unchanged; changed evidence invalidates. Missing/conflicting evidence cannot create a synthetic receipt or force purchase. A later evidenced legacy invoice void moves its original range from opening used to opening released exactly once |
| Committed balance | Concurrent administrator allocation cannot spend funds committed to active membership positions. A booking only uses its original block payments, not unrelated retained credit |
| Discount bounds | QAR 1,200 less QAR 0.01 over 26 meals never yields a negative discount. All gross, discount, and net slices sum exactly. Test full price, half cent rounding, fixed cap, near full discount, zero package, and zero net slices within a positive package |
| Stable allocation positions | Book, invoice, void after editing, then rebook across two blocks. Restored positions keep their original cents and quantity. Future pricing or promo changes do not reprice them |
| Reexport and overlapping payout | Changed file hash or row order cannot post the same provider transaction or fee twice. Equal fees with distinct verified references remain separate. Conflicting content and reference free fees require review |
| Bank date evidence | Report bank date wins over upload and sale dates. A missing report date requires retained bank or remittance evidence. Missing, conflicting, or locked dates and a forged override all prevent posting |
| Terms publication | A newer effective version before a fresh attempt requires acceptance. An already started attempt or exact zero request retry retains the earlier accepted content, hash, and actor. A missing version or hash mismatch blocks new confirmation |
| Identity foundation from 0002 | Run the [customer matching matrix](../0002-customer-matching-signup/verify.md) for each enabled slice. Bypass never skips provider signature or payment checks. A delayed callback after merge keeps its original attempt and proof identities, posts once for the canonical customer, and does not restore promo use or duplicate membership allowance |

## Quality gates

1. Run focused Pest feature and unit tests for each slice.
2. Run all AR, ledger, banking, subscription, customer, order, promotion, and integration tests affected by the slice.
3. Run the full Composer test command after all slices pass.
4. Run Pint on changed PHP files.
5. Run the production asset build for RMS and the customer website checks for changed PHP and JavaScript.
6. Inspect routes, migrations, indexes, foreign keys, permission seeds, schedules, queues, storage configuration, and deployment variables.
7. Review the final diff for credentials, provider payloads, customer data, workbooks, database files, and debug output.
8. Complete one controlled SkipCash sandbox payment and webhook recovery run before enabling production traffic.
9. Reconcile that sandbox payment through a synthetic report and default bank posting without using production evidence.
10. Verify mobile, keyboard, loading, return, retry, mixed cart, and account status behavior at approximately 360, 768, and 1024 pixel widths.
11. Verify legacy opening dry run totals and replay safety before enabling the new covered booking path for existing balances.
12. Before live collection, verify identity, merge, and payment recovery prerequisites. Before membership checkout, also verify returning member booking and legacy readiness. Earlier tracer runs remain sandbox only.

## Documentation checks completed on 2026-08-30

The revised apportionment formula passed 859,602 standalone arithmetic cases covering 15,638,446 slices. This includes every integer cent discount for the QAR 900 and QAR 1,200 plans, plus small gross values and meal counts, and five percentage rounding fixtures. At that review, all 20 original acceptance criteria were unchanged and had build task, critical scenario, and verification coverage. Local document links and whitespace checks passed.

These results validate the written formula and document consistency only. No application tests, migrations, provider calls, bank postings, or customer data changes were performed for this revision. The implementation and sandbox checks above remain required.

After that review, the accepted 0002 design refined AC-15 and AC-18 for the temporary server bypass and immutable merge evidence. At that point, the remaining 18 acceptance criteria and the financial formula were unchanged. The additional identity cases above are planned implementation checks, not executed tests.

The owner's later 2026-08-30 ordinary order revision changes AC-8 and AC-9 so a verified first matching payment completes the original purchase even after expiry. This matrix now tests normal completion and email, released target recovery, unchanged dates/prices, and duplicate safety across that boundary. Membership timing, identity rules, and the financial formula remain unchanged. These added cases are also unexecuted.
