# Customer matching verification plan

This is a planned verification matrix. No application test, migration, SMS, Gemini request, production signup, or financial mutation was executed while writing the specification.

## Safe environment

Confirm effective Laravel testing configuration and any cached configuration before running tests. The repository expects a disposable MySQL store_test database on 127.0.0.1. Never run RefreshDatabase suites against development or production data.

Use FakePhoneVerificationProvider and a fake AiProviderInterface. Use synthetic names, phone numbers, emails, and financial fixtures. Website checks use a local mock or an explicitly approved test RMS instance. Never use real customer details as test fixtures.

## Acceptance matrix

| ID | Checks | Required result |
|---|---|---|
| AC-1 | Bypass signup, real SMS signup, matching disabled, queue down, no candidate, database rollback | One active owned customer after completed signup, including while historical matching is disabled. Background uncertainty does not hold checkout. Failed persistence never returns success |
| AC-2 | Same name with case and spaces, spelling variant, accent difference, same email only, multiple exact matches, inactive match, occupied match including inactive login | Only the exact unique active unoccupied match links automatically under the permitted verification policy |
| AC-3 | Bypass enabled, no challenge, old nonnull timestamps, switch disabled, real verified challenge, cached browser account | Audit distinguishes bypass. No fake SMS proof. Server and browser stop treating bypass as satisfied when disabled |
| AC-4 | New record and occupied or fuzzy candidate | Own retail customer and private candidate review; no copied balance, credit, invoice, order, payment, subscription, or redemption |
| AC-5 | Concurrent same user resolution, two accounts claiming one customer, lost response then login, duplicate email, OTP replay, concurrent legacy token quote requests, repeated unchanged profile writes | One customer per user, one login per customer, no repeated creation or matching generation, no token disclosure from public retry |
| AC-6 | Admin view and actions, direct action as nonadmin, repeated decision, dismissed candidate rescanned | Private review only, actor and time retained, exactly one decision, no automatic reopening |
| AC-7 | Capture outbound fake payload, invalid labels and values, duplicate labels, timeout, missing key, matching disabled during job, exact fingerprint inputs and excluded fields, profile change during job, resolved review | Names and temporary labels only. Valid current bounded suggestions or no suggestion. No ownership or financial side effect |
| AC-8 | Both logins, source login only, target login only, neither login, inactive target login, privileged unexpected user, same target retry, changed target retry, merge chain cycle | Confirmed survivor rule, source deactivation, credentials retained appropriately, tokens and sessions revoked, safe conflicts and no repeated merge |
| AC-9 | Every reference matrix row, null customer user owned orders, third customer rows, financial assertions, delayed callback, immutable snapshots | Correct current owner, original evidence retained, no unauthorized third customer move and no additional accounting event |
| AC-10 | Both customers with remaining blocks, existing bookings across blocks, usage, pauses, different funding times, incompatible company or branch | One logical queue in each valid funding context. No duplicate allowance, restored meals, repriced booking, auto generation, or cross scope spending |
| AC-11 | Two historical promo uses, first purchase on one source, active reservations, 100 percent requests, retry after merge | Combined history, no reset or deletion, previous discounts honored, later limits enforced, no synthetic request or entitlement |
| AC-12 | Wrong customer token, inactive user, missing ability, staff role, forged owner ID, stale source request racing with merge, another user's phone token | Server rejects access or mutation. Only the explicitly accepted bypass linking exception is permitted |
| AC-13 | Cart restoration, login timeout, legacy unlinked me response and quote continuation, revoked source login, transferred login, 409 proxy envelope, three viewport sizes | Customer can continue without another login or manual linking gate, private cache is cleared on identity loss, no destination details leak, no false success or payment result |
| AC-14 | Fresh and representative existing schema, resumable inventory, missing audit storage, lost job dispatch, duplicate scheduler run, matching pause and resume, genuine SMS activation and rollback | No duplicate records or destructive repair, missing prerequisites surfaced, current matching work resumes safely, usable verification path, compatible owner resolver retained |
| AC-15 | Qatar place search, map move, current location, denied permission, default center, explicit confirmation reset, stale asynchronous result, provider load or reverse geocode failure, raw and rounded boundary points, islands and holes, Bahrain, Saudi Arabia, UAE, missing or partial coordinates, invalid numeric values, oversized fields, old OTP completion, text only edit, exact match overwrite, merge tuple preservation, and direct API forgery | Only an explicitly confirmed Qatar pin with building detail starts a new registration after cutover. Server rejection is authoritative. Provider labels are not retained. Old challenges and existing account and delivery workflows remain compatible |

## Approved cross check regression cases

1. With CUSTOMER_MATCHING_ENABLED false, complete bypass signup and real SMS signup against profiles that would otherwise match an unoccupied historical customer. Each account receives its own fallback customer, not the historical record. Existing linked accounts remain linked. No scan or Gemini call runs, even if the AI setting is true. Existing reviews remain available to an admin. Reenable matching and recover current pending scans without another customer, automatic relinking, or duplicate review. A job paused before storing a result cannot write suggestions or mark its scan complete. Covers AC-1, AC-2, AC-4, AC-6, AC-7, and AC-14.
2. Keep a valid token for an active unlinked legacy user across deployment. GET me returns HTTP 200 with linked_customer false, link_status unlinked, customer.id null, customer.data_source portal, and the user's own profile and verification state. It creates nothing and discloses no candidate history. Keep the token and cart. Verify the phone if required, then request a quote and resolve one owner before its totals and fingerprint are produced. Concurrent and repeated quote or checkout calls reuse that owner; failure creates no attempt or financial effect. Covers AC-5, AC-12, and AC-13.
3. Assert the exact compact JSON tuple and SHA256 digest with synthetic Unicode names, phone values, decimal string IDs, and a missing phone. Case and whitespace normalization, email edits, and address edits leave the fingerprint unchanged. A different normalized name, phone, or canonical customer changes it. An old job result is rejected; the current generation is recovered if dispatch failed. Repeated unchanged writes create no new generation event, and resolved reviews never reopen. Covers AC-5, AC-6, AC-7, and AC-14.

## Financial merge fixture

Before each test, capture the IDs and exact monetary values of source and destination payments, invoices, invoice items, active and removed allocations, posted ledger and subledger entries, clearing links, and bank transactions. Include a closed financial period and historical dates.

After merge and after exact retry:

* Customer ownership is correct for mutable references and canonical reads.
* Every payment amount, invoice amount, source event key, posting date, and company or branch remains unchanged.
* Allocation rows and amounts are byte equivalent for their financial fields.
* Ledger debit and credit totals are unchanged; no entry has been inserted, edited, voided, or reposted.
* Customer advance totals combine exactly once, without automatically allocating credit.
* Original contact, identity, terms, and pricing snapshots remain unchanged.
* The merge audit explains all changed reference IDs, and no duplicate audit decision appears.

Run the same fixture with quotation history, order sheet entries, source user owned orders and requests without customer_id, and a row belonging to a third customer. The third customer's row is not moved.

## Payment, membership, and promotion extension gates

These fixtures become mandatory as the owning slices add their tables. They cannot be marked passed merely because the tables do not exist yet.

1. Start a payment under the source customer, merge before callback, then deliver the verified callback twice. Expect one payment and one permitted completion under the surviving customer, with original provider and checkout evidence unchanged.
2. Give each portal user the same client UUID on two distinct original attempts. Merge their customers. The original user plus UUID keys remain distinct; neither attempt becomes the other or charges again.
3. Give each customer a funded membership block with partial usage and future bookings. Merge, reserve more meals, issue a daily invoice, edit it, and then void it. Existing attribution survives; only the normal void restores the original positions once.
4. Include blocks from different companies, branches, or currencies. Customer identity may resolve to one destination, but funds are not reassigned across those boundaries.
5. Give both customers historical uses of one promotion. Merge, retry old requests, and attempt a new use. Keep both completed uses and block any later use exceeding the combined limit.
6. Include two permanent 100 percent redemptions. Preserve both original requests, return a deterministic existing result for a repeat, and create no payment, membership, meal balance, or replacement redemption.
7. Include an open priced checkout whose promotion eligibility would differ after the merge. Keep its original accepted quote and reservation for verified completion. A fresh checkout uses combined history.
8. Prove that every enabled booking, usage listener, scheduler, balance report, allocation, invoice void, and pause path uses the same canonical customer queue. Summing source and destination counters after copying them must fail the fixture.

## SMS activation checks

Use a test account with a bypass audit and the old implementation's nonnull timestamp, but no successful challenge. Disable bypass and refresh both server configuration and browser me.

The account stays linked and can read its existing status and history. Protected mutations require real verification. It can start current phone verification without passing the verified phone middleware. A valid code changes the server result to sms. No balances, purchases, bookings, or provider recovery change.

Verify wrong user, wrong purpose, changed phone, consumed token, expired token, wrong code, maximum attempts, resend cooldown, and maximum sends. An authenticated user cannot submit another user's valid encrypted token.

## Commands at implementation time

After the safe environment check, run the relevant suites, including new files added under them:

~~~sh
php artisan test tests/Feature/Customers
php artisan test tests/Feature/CustomerPortal
php artisan test tests/Feature/Subscriptions
php artisan test tests/Feature/SubscriptionGeneration
php artisan test tests/Feature/MealPlanRequests
php artisan test tests/Feature/AR
php artisan test tests/Feature/Ledger
./vendor/bin/pint --dirty
npm run build
git diff --check
~~~

Confirm each test directory exists at implementation time and include the quotation, order sheet, and payment slice suites that own the enabled matrix entries. Use targeted PHP formatting if the worktree contains unrelated PHP changes.

From the customer website, run its existing Node pricing and rendering suite, PHP lint on changed wrappers, its asset build where applicable, and new proxy and account flow tests against a mock:

~~~sh
node --test tests/orders-pricing.test.cjs
npm run build
~~~

Inspect the browser key without printing its value. Confirm that it permits only the development orders origin and approved local origins, and that it permits only Maps JavaScript and Places. Confirm the key is absent from Git history, container images, logs, and analytics. Test the picker at 360 px, 768 px, and desktop width with keyboard navigation and a touch sized retry action.

Do not use npm test in either repository. Existing pricing tests alone do not prove signup, review, merge, or payment integration.

## Documentation checks

Verify all 15 acceptance criteria have both build coverage and verification coverage. Validate local links and source paths, inspect the final diff, and confirm that scope design status is not advanced until the engineer accepts the specification.
