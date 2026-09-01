# Verification for SkipCash paid ordinary orders

This is a planned verification contract, not a test result. No application tests, provider requests, migrations, customer records, or financial mutations were performed while drafting it. Requirements are defined in [index.md](index.md).

## Safe environment

* Before any Laravel feature test, confirm the effective testing connection is disposable MySQL `store_test`, including environment overrides and cached configuration. Never refresh development or production data.
* Use fake SkipCash, mail, clock, and queue boundaries for automated tests. Use real database constraints, canonical services, and transactions. Test concurrent transactions against the supported MySQL/MariaDB engine, not SQLite.
* Use synthetic customers, phones, emails, menu IDs, provider references, and event bodies. Do not copy the supplied settlement workbook or real provider secrets into fixtures.
* Sandbox integration requires approved sandbox credentials, a test customer identity, callback configuration, and explicit authorization at execution time. Live collection is a separate release action, not part of routine testing.
* Website verification uses a local mock or the approved test RMS. Do not submit against the configured production dashboard accidentally.
* The normal provider journey is one session and one successful payment per checkout. Synthetic financial evidence cases below check defensive accounting rules, not documented provider behavior. Do not add a special extra payment email, customer journey, or a requirement to reproduce two successful charges within one hosted session.

## Requirement matrix

| Criterion | Required evidence | Suggested suite ownership |
|---|---|---|
| AC-1 | RMS quote and website estimate parity, tampered price rejection, valid menu/role IDs, integer totals, no unsupported mode | New Payments quote suite; existing Orders submission and website pricing suites |
| AC-2 | Active owner, fallback resolution, accepted bypass, shutdown verification, default company/branch alignment, required provider profile before insertion | Payments API and existing CustomerPortal/Customers suites |
| AC-3 | Today only, mixed cart review, future dates, current terms acceptance, immutable started snapshots | Quote/checkout time and terms tests; website review flow |
| AC-4 | Exact replay, changed UUID payload, concurrent submission, lost dispatch/response, stable recovery after side changes, explicit repeat purchase | Payments initiation/idempotency suite and browser recovery tests |
| AC-5 | Independent HMAC vectors, signed field handling, GET detail verification, accepted capture only totals, no browser authority | Provider adapter and webhook suite |
| AC-6 | One and several dated targets, atomic order/invoice/payment links, rollback at each writer, no premature operational rows | Payments completion with Orders/AR integration tests |
| AC-7 | Correct source/method/account, balanced invoice and receipt entries, no bank/fee/revenue duplication | AR, Accounting, Ledger, and payment source tests |
| AC-8 | Old credit untouched, exact allocation, legacy auto allocation unchanged, edit/void replay stable, no meal usage | Existing Receivables/AR/Subscriptions plus completion regression tests |
| AC-9 | Original purchase completes before, at, and after expiry; released target recovery; distinct extra capture retained once; reversal exception | Payment classification and recovery suite |
| AC-10 | Qatar event dates, finance locks, missing mappings/ledger, retry preserving dates | Accounting period and gateway completion tests |
| AC-11 | Four public states plus confirmation flag, same tab return, Account recovery, draft revisions, future booked wording | CustomerPortal projections and website interaction tests |
| AC-12 | After commit mail intent, encrypted recipient snapshots, queue loss, known failure versus unknown send, no financial replay | Mail dispatch and completion transaction tests |
| AC-13 | Allowed/denied actors, company/branch isolation, merge races, encrypted private fields, redaction, raw evidence retention | Security boundary, customer merge, queue/log payload tests |
| AC-14 | Bounded recovery, stale claim behavior, overlap prevention, settings snapshots, purge restart | Commands/jobs and payment settings API tests |
| AC-15 | Forward migration with legacy rows, both site base paths, safe flag matrix, old route denied at cutover | Migration, existing domains, website routing, release drill |

## Concrete financial tracer

Create a synthetic active customer in the approved default company/public branch with an existing unallocated QAR 300 AR payment. Configure an independent SkipCash clearing ledger account and real AR/revenue/advance mappings in the test database.

1. Quote a future date with one plate main, one salad, and one dessert using the checked in QAR 65 bundle example. Browser day_total of QAR 1, a changed main ID, an unknown portion, or a credit request cannot change the server result. Quote itself creates no order or payment. Covers AC-1, AC-2, and AC-3.
2. Submit the reviewed quote, current terms version, and client UUID. Assert one attempt and target, no operational order, MealPlanRequest, invoice, receipt, allocation, or mail. Assert an initiation job contains only the attempt ID. Covers AC-4, AC-6, AC-12, and AC-13.
3. Return a sandbox/fake session, then a valid paid webhook with authenticated detail and on time finish. Assert one ordinary order, one invoice with 6500 total/paid and zero balance, one payment with method skipcash/source ar/source FK and amount 6500, and one allocation 6500. The old QAR 300 payment remains completely unallocated. Subscription quantities do not change. Covers AC-5, AC-6, AC-7, and AC-8.
4. Assert invoice entry debit AR 65/credit revenue 65 and receipt entry debit SkipCash clearing 65/credit AR 65. Both balance. There is no second revenue entry, bank transaction, provider commission, or settlement. Check audit links and retained dates. Covers AC-7, AC-10, and AC-13.
5. Repeat the exact create request and same/differently serialized valid webhook. Assert no new financial, order, or mail intent rows. A changed payload under the same UUID returns 409. Covers AC-4, AC-5, AC-6, and AC-12.
6. Repeat with two future dates: one QAR 65 bundle and one QAR 145 half main plus salad. Assert one QAR 210 payment, two dated orders/invoices, allocations 6500 and 14500, matching clearing balance, and one combined customer confirmation. Covers AC-1, AC-6, AC-7, AC-8, and AC-12.

## Pricing and snapshot matrix

Cover main only, both bundled sides, each single side, extra sides, multiple distinct mains, repeated main rows, and any mix containing half/full portions. Reordering canonical input yields the same accepted price/fingerprint; changing a meaningful quantity or note changes the request identity. Names supplied by the browser are never menu authorization.

Verify decimal safe price conversion, zero/negative/fractional quantities, missing role items, duplicate dates, unsupported promotion/plan/credit fields, overflow, and unsupported currency/scale. A valid configuration edit changes a fresh quote but never an existing attempt. Order line decimals and invoice summary cents reconcile exactly. Covers AC-1, AC-3, AC-4, AC-6, and AC-8.

An initial quote accepted before a terms, menu, price, or Qatar day change cannot silently start against new values. With no existing attempt to replay or recover, first creation returns a revised quote and requires review. Exact existing attempt replay still returns the original accepted snapshot. The effective terms content hash must match its retained file. Covers AC-3, AC-4, and AC-10.

## Identity and date boundaries

Test real verified phone, the explicitly accepted server bypass, bypass disabled with old fabricated timestamps, current phone verification, legacy active unlinked token, inactive login, wrong token ability, uncertain match with owned fallback, and another customer's attempt. Disabling historical matching must not disable fallback ownership. Covers AC-2 and AC-13.

Test required provider fields before any new attempt or target insertion. Cover absent/blank account names, malformed UTF8 or remaining controls, missing/invalid/overlength phone and email, and Unicode or single word names. Expect HTTP 422 `PROFILE_REQUIRED` on the relevant `profile.name`, `profile.phone`, or `profile.email` key, intact selections, and no attempt, target, initiation job, or provider call. An absent portal name uses the actual account name, not a linked customer's display name; valid accents, punctuation, and non Latin scripts remain valid. One word repeats in both provider name fields, and each outbound field respects the 60 character limit without changing the original RMS name. Correcting the profile permits the normal new checkout. Covers AC-2, AC-4, and AC-13.

After creating an attempt, change or clear the mutable account profile while retaining an active owning login. Exact replay must return the original reference and use saved outbound fields, not produce a new profile error or provider dispatch. Do not confuse this with bypass shutdown or revoked login, whose existing access rules still apply. Covers AC-2, AC-4, and AC-13.

Test today only, tomorrow only, and mixed dates from Qatar and a device using another timezone. Mixed quote review must exclude today without creating any financial or order effect for it. A today only ordinary cart has no payable checkout, unlike the separately scoped membership zero selection journey. Covers AC-3 and AC-11.

Use a checkout started at 23:58 Qatar with a 15 minute window. Test verified finish at 00:02, one microsecond before expiry, exactly at 00:13, and after expiry at 00:14. Every first matching capture creates the same original dated order, paid invoice, payment, and exact allocation, with completed and purchase_confirmed true. It creates no expiry credit or support requirement. Repeat after the unpaid attempt was marked expired and its target released, with a delayed webhook, later price/menu changes, and a repeated callback. The saved selections, service date, and price remain authoritative and complete once. New or changed same day selections are still rejected. Covers AC-3, AC-5, AC-9, and AC-10.

Test timestamp with offset, offsetless merchant time, missing/unsigned/invalid/future/conflicting finish, and receipt near a UTC/Qatar date boundary. Assert the retained receipt date, first intended invoice issue date, and allocation date rather than application default timezone or callback arrival time. Close each relevant date independently. No partial accounting occurs, dates survive retry, and public status remains paid_processing. Covers AC-5, AC-9, and AC-10.

## Retry, concurrency, and provider evidence

* Two simultaneous requests with one UUID create one attempt and one dispatch claim. Changed payload cannot share it. Two different UUIDs for an equivalent unresolved cart return recovery unless an owned explicit separate purchase acknowledgement is present. That acknowledgement allows another real purchase without modifying the first. Covers AC-4 and AC-11.
* Start an unresolved checkout, then replace its dated salad/dessert menu IDs. A new UUID submitting the same normalized selections returns `EXISTING_CHECKOUT` with the original reference and no new attempt/job/provider POST. Repeat with an old or refreshed quote, reordered equivalent mains, current price/terms changes, missing current menu roles, and Qatar day rollover. Recovery uses the syntax only hash before those mutable checks and preserves the old resolved snapshot. A meaningful cart change does not match; another customer/company/branch cannot recover this attempt. An owned explicit separate purchase uses a fresh quote and all current validation, never rewrites or cancels the first. Covers AC-3, AC-4, AC-11, and AC-13.
* Crash before queue dispatch, before claim commit, after claim commit, after provider accepts but before response persistence, and after final accounting commit. Recover only cases safe under the saved state. There must never be a second provider POST after an in_flight or unknown claim. Covers AC-4 and AC-14.
* Confirm exact outgoing string/body, nonempty signed field order, UTF8 names, zero status values, decoded constant time signature comparison, different create/webhook secrets, and Client ID detail authorization using independent fixtures. Invalid signature produces no inbox row; signed mismatches produce no financial effect. Covers AC-5 and AC-13.
* Test unsupported pay URL host/scheme, provider HTTP redirect, malformed success response, missing provider ID, missing pay URL, and expired known session. Unknown create must remain recoverable without a new POST. Covers AC-4, AC-5, and AC-13.
* Deliver webhook before create response save, duplicate events with changed JSON formatting, status 12/0 transitions, paid then failed, failed then paid, unknown status, and missing required detail. Verify monotonic paid facts and source/provider uniqueness. Covers AC-4, AC-5, AC-9, and AC-14.
* Deliver two distinct valid paid provider IDs for one attempt concurrently. Only one completes the purchase. The other creates one unallocated receipt after verification; it never reuses the first receipt UUID or allocates automatically. Run both orders of evidence arrival with captures before and after expiry: the selected first capture funds the purchase, and only the distinct additional capture becomes retained credit. Covers AC-6, AC-7, and AC-9.
* A partial/wrong amount, wrong currency, wrong merchant reference, unexpected custom field, wrong source, refund, or reversal cannot silently become a completed purchase or automatic refund. Retain appropriate signed evidence and an exception. Covers AC-5, AC-9, and AC-13.

### Public amount projection

For a QAR 65 attempt, prove these owned detail and list results using distinct synthetic provider IDs. Repeat events must never increase a total. Covers AC-5, AC-9, AC-10, AC-11, and AC-13.

| Evidence and accounting state | Paid cents | Confirmed cents | Retained credit cents |
|---|---|---|---|
| Only signed partial/wrong amount, currency/reference/source mismatch, or missing reliable finish time | 0 | 0 | 0 |
| Fully matching capture accepted, but accounting blocked by a finance lock | 6500 | 0 | 0 |
| Original matching capture completes the purchase | 6500 | 6500 | 0 |
| Additional fully matching capture accepted, but its receipt is not posted yet | 13000 | 6500 | 0 |
| That additional receipt commits and remains unallocated | 13000 | 6500 | 6500 |
| Administrator later allocates the additional receipt in full | 13000 | 6500 | 0 |

Assert `verified_paid_at` remains null until all matching checks pass and then persists with the immutable accepted amount/currency/finish/attempt association. A newly quarantined event does not enter paid totals. A later declined/refund/reversal/conflicting event for an already accepted capture neither erases its historical paid amount nor changes purchase confirmation or money by itself. Keep original accepted facts separate from later exception evidence. Provider status 2 or a valid signature alone is insufficient.

## Corrections and existing behavior

Inject failures after the first of two orders, invoice issue, receipt creation, allocation, ledger posting, and required audit write. Rollback must leave no partial purchase while provider evidence remains durable. Disable ledger availability and invalidate the retained clearing account: do not interpret a null ledger result as successful accounting. Covers AC-6, AC-7, AC-10, and AC-13.

Edit the issued invoice through the existing permitted path, then replay a paid webhook. No repricing or reallocation occurs. Void the original or edited invoice through the canonical correction flow, assert allocation release and current invoice status, then replay both create and webhook. No order/invoice recreation or restored allocation occurs, and the customer view does not show the voided order as booked. Repeat the void operation safely. Covers AC-8, AC-10, AC-11, and AC-15.

Regression test legacy invoice issue auto allocation, non gateway payment methods, existing bank transfer behavior, card/cheque clearing, invoice numbering, subscription usage listeners, and backoffice order submission. New source handling must not change them. Covers AC-7, AC-8, and AC-15.

Merge while a checkout is pending, while verified completion awaits a finance lock, and concurrently with completion. Assert destination login survival, revoked source token/session, canonical financial ownership, preserved original portal user/client UUID/provider proof/snapshots/dates, and one completion. The surviving customer resumes by reference without rewriting the old submission key. Covers AC-2, AC-4, AC-10, and AC-13.

## Website and notification journeys

Run browser interaction checks in addition to the existing Node extraction tests:

* Guest builds selections, signs in, completes any required phone step, reviews the RMS quote/terms, and leaves in the same tab only after a durable reference exists.
* A missing required provider profile field shows an actionable account correction error with the cart intact. After correction, review and payment continue normally; an existing checkout remains recoverable from its saved snapshot.
* Provider return contains fake success and amount values. The page ignores them and reads RMS. Refresh, back/forward navigation, missing reference, login expiry, and another customer's reference remain safe.
* Lost POST response keeps the exact submitted request. Closed browser or another device finds the checkout in Account. Resume reuses a usable provider session; no session is reconstructed from browser data.
* New draft edits made after submission survive completion of the original revision. No HTTP 202, decline, logout, or paid_processing outcome clears selections.
* Payment completed after expiry shows the normal confirmed order and no expiry credit warning or second payment request. An extra real collection leaves the original purchase confirmed and shows its separate retained amount without a credit checkout button.
* An expired RMS timer with provider outcome still pending or unknown stays in recovery, hides an unusable payment link, and does not prompt another charge. A later verified success completes the original order.
* Verify every state at 360 px, 768 px, and desktop, keyboard operation, focus, loading announcements, touch targets, slow/offline requests, bounded polling, 429 backoff, and preserved account filters.
* Verify root and /laylakitchen paths, Apache/local router parity, canonical asset version redirects retaining the RMS reference, noindex/no cache/no referrer result page, and no third party event from that page.

These checks cover AC-3, AC-4, AC-11, AC-13, and AC-15.

Force mail queue dispatch loss, known send failure, duplicate jobs, and a crash after possible SMTP/provider acceptance. Financial status remains completed. Known unsent work recovers; uncertain delivery does not blindly resend. Assert recipient and amount snapshots, successful EmailLog links, no email before commit, and no PII in failed job payloads. Repeat with payment finishing after expiry: send the normal order confirmation once after commit and no expiry credit email. Covers AC-12, AC-13, and AC-14.

Read the raw stored `notification_snapshots` column without model decryption and prove synthetic customer/admin emails and message content are not plaintext. An authorized worker can decrypt the original snapshots; `notification_dispatch` contains only the documented nonpersonal fields. Change the account email after checkout and administrator recipient configuration after completion, then retry mail: it still uses the retained respective recipients. Queue/failed job payloads, operational logs, and error text expose no recipient or message content. Reuse existing EmailLog behavior without creating another outbox. Covers AC-12 and AC-13.

## Operations and release checks

1. Verify allowed settings actor plus denied customer/nonpermission actor and cross company access. Change duration/support/cutoff and prove existing attempt expiry/source account/terms are unchanged. Timezone change is rejected. Covers AC-13 and AC-14.
2. Run recovery twice over more than one batch, with overlapping workers and a lost queue dispatch. It processes only due IDs and cannot repeat provider create or financial posting. Unknown provider ID remains an exception. Covers AC-4 and AC-14.
3. Run raw purge at just before, exactly at, and after 90 days, interrupt a batch, then restart. Normalized event/provider/payment records and their links survive. Check logs contain only allowed IDs/codes. Covers AC-13 and AC-14.
4. Test disabled/empty feature, invalid source/actor/terms/money scale, gateway outage, flag change before dispatch, and flag change during a pending/paid attempt. New creation stops safely; status, signed verification, money completion, and purge remain available. Covers AC-2, AC-10, AC-14, and AC-15.
5. Migrate a clean disposable database and one with representative existing payment/AR rows. Existing source links remain null, no historical money is reclassified, source seeding is idempotent, and conflicting seed account IDs fail without rewriting the source. Covers AC-7 and AC-15.
6. Verify the old direct customer route cannot bypass payment after cutover, including old proxies and cached clients. Backoffice routes stay usable. Do not enable public cutover until all still advertised membership journeys and payout/operations readiness gates are met. Covers AC-15.

## Commands during implementation

After confirming the disposable environment, the eventual implementation should run targeted new Payments suites and the affected existing Orders, AR, Receivables, Accounting, Ledger, CustomerPortal, Customers, and Subscriptions suites. Use installed Pest through `php artisan test`; run targeted PHP formatting and the RMS asset build if its UI changes.

In the website repository, run `node --test tests/orders-pricing.test.cjs`, any added checkout interaction suite, `php -l` on changed PHP files, and the asset build when applicable. There is no npm test script. Browser checks do not replace financial/database tests.

## Draft verification record

On 2026-08-30, documentation checks passed for all three files: required sections, local Markdown links, whitespace, Proposed status, all 15 criteria in the build/critical scenario/verification matrices, and five build stages. SHA256 comparisons confirmed the scope and all six files in 0001 and 0002 were unchanged.

Application and sandbox checks above are unexecuted. The independent gpt-5.5 review completed before the owner changed the ordinary payment after expiry rule on 2026-08-30. The written matrix requires normal purchase completion on both sides of expiry.

On 2026-08-31, the four approved independent review fixes were applied to the specification and the planned cases above. The later extra payment email proposal was withdrawn after the owner's objection to speculative complexity. The owner then instructed proceeding; the design is confirmed, with no additional email or planning blocker for that scenario. Application and sandbox verification remain unexecuted.

The earlier review fix checks passed: all three files retained the required sections, all 11 local Markdown links resolved, all 15 unchanged acceptance criteria appeared in the build/scenario/verification coverage, the five build stages and Proposed status remained intact, and `git diff --check` reported no whitespace errors. At that checkpoint, the scope and both earlier specifications were unchanged. These checks did not execute or prove the planned application behavior.

Final design closeout checks on 2026-08-31 passed for this specification and its scope link: 14 local Markdown links across four documents resolve, all 15 unchanged criteria map to the build/scenario/verification/scope coverage, and scope feature 2 has five pending build milestones and all GA execution gates unchecked. Only feature 2 and its summary status changed in the scope; 0001 and 0002 remain unchanged. The speculative email blocker is closed. No application or provider verification was run.
