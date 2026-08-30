# 0002. Customer matching and signup

**Date**: 2026-08-30
**Status**: Proposed

## Summary

RMS resolves a customer when signup completes, without making uncertain matches wait for staff. It keeps the current login and customer records, adds a private matching review, and extends the existing merge flow. Until SMS is ready, the owner has explicitly accepted temporary linking through verification bypass, with that exception recorded separately from real phone verification.

## Requirements

**User stories**:

* As a customer, I want to register and order immediately without waiting for someone to link my account.
* As a returning customer, I want my existing meals and history when RMS can link my account under the agreed identity rule.
* As an administrator, I want to review possible duplicates on Customer Accounts and use the existing merge flow.
* As an administrator, I want to enable SMS later without losing accounts, purchases, or the evidence of earlier bypass decisions.

**Acceptance criteria**:

* **AC-1**: Completed signup returns an account linked to an active customer. Normal signup resolves the customer after successful SMS verification. Bypass signup resolves it before its immediate token response. Matching uncertainty, Gemini failure, and background queue failure never hold an otherwise valid purchase for review. A database failure still fails safely without claiming success.
* **AC-2**: Automatic linking requires an exact normalized phone and full name match, exactly one active matching customer, and no other login attached to that customer. Name comparison ignores only case and surrounding or repeated whitespace. Genuine SMS proof is required except under AC-3. Name similarity, email equality, and Gemini scores cannot independently authorize linking. An existing login is never replaced by signup.
* **AC-3**: While the existing server verification bypass setting is enabled, bypass satisfies the verification prerequisite for AC-2 and normal customer ordering. Record the decision as bypass, never as an SMS challenge success. The owner explicitly accepts the risk that someone knowing an unlinked customer's name and number could claim it using another email. Disabling bypass stops accepting bypass evidence, including old fabricated verification timestamps. Existing links and purchases remain intact.
* **AC-4**: No eligible match, several eligible matches, an occupied match, or an uncertain match produces one separately owned customer using the registration profile. Possible duplicates appear only in private review. This creates no order, subscription, invoice, payment, credit, promotion redemption, or other financial effect.
* **AC-5**: Concurrent registration, login recovery, matching, and retries cannot give two accounts the same customer, create repeated customers for one account, or change an already resolved account's owner. Existing duplicate email conflicts remain conflicts, not a way to recover another account's token. A lost response can be recovered through normal login.
* **AC-6**: Only an active administrator can inspect and resolve matching reviews. Customer Accounts shows the reason, candidates, optional advisory result, outcome, actor, and time. An administrator can merge through the existing flow or mark a candidate as a different customer. An identical retry creates no new review or audit decision. No review state is a public customer or payment status.
* **AC-7**: Optional Gemini ranking runs outside signup and checkout. It receives only customer names and temporary candidate labels. No phone, email, address, permanent identifier, credentials, payment, promotion, or financial history leaves RMS. Its response is validated against the supplied candidates and cannot link, merge, authorize, or change balances. A missing key, timeout, or invalid result leaves ordinary review usable.
* **AC-8**: An approved merge preserves the destination customer. If both customers have logins, the destination login survives and the source login becomes inactive and unlinked, with its tokens and sessions invalidated. If only the source has a login, it transfers. Source customers remain as inactive historical records with a typed destination reference. A repeated merge has one outcome and no repeated side effects.
* **AC-9**: Merge covers all current customer references, user owned portal orders and requests, and every enabled payment integration reference. It preserves original identity snapshots and proof records. It changes no payment amount, invoice amount or date, allocation, ledger entry, clearing balance, or source event identity, and posts no new money. Delayed payment processing resolves an approved merged owner without bypassing company, branch, currency, signature, or amount checks.
* **AC-10**: Merged membership history supplies one logical customer queue, not competing balances. Existing bookings retain their original blocks and payments. New selections consume eligible remaining blocks in original funding order with stable ID ties. No used meal is restored, no existing booking is charged again, and no automatic order is generated by the merge. Company, branch, and currency funding boundaries remain intact.
* **AC-11**: First purchase and promotion limits use the combined history after merge. Completed uses are never reset or deleted. Two earlier completed discounts remain honored even if their combined use exceeds a limit, while later attempts use that combined history. A 100 percent request and its permanent redemption remain traceable without creating a payment or membership.
* **AC-12**: Account reads and mutations enforce active login, customer role, token ability, and server resolved ownership. Staff cannot use the customer signup flow to gain another role. Nonadmins cannot review or merge. Canonical customer resolution never grants arbitrary access just because a caller supplies a source or destination ID.
* **AC-13**: The website uses RMS account and verification results, preserves the cart during registration, login, verification, or retry, and never shows internal duplicate review as a checkout prerequisite. A revoked source login loses cached private data and must sign in using the surviving account. No browser field grants identity or marks payment paid.
* **AC-14**: Rollout preserves existing links and historical records. Restart safe scans identify unresolved accounts, unreliable verification timestamps, missing merge coverage, and review work that was not dispatched. SMS activation has a usable authenticated verification path that does not require already being verified. Both applications must pass the applicable verification matrix before the new identity path is enabled for live payments.

## Decision

**Chosen option**: Improve the existing customer workflow in place, with a guarded rollout.

Reuse Laravel authentication, Sanctum, CustomerPortalRegistrationService, CustomerPhoneVerificationService, CustomerMergeService, the existing Gemini interface, and the accounting audit service. Add one private review table and a nullable merge destination field on customers. Do not introduce a replacement account system, a customer approval lifecycle, a new provider, or new financial records. (basis: AGENTS.md, the Customers and Ai service guides, and the current registration, merge, and audit services)

This specification refines scope feature 8 and the identity boundary in [0001](../0001-payment-accounting-contract/index.md). The accepted temporary bypass exception changes only the identity prerequisite. It does not weaken payment verification, authorization after linking, accounting controls, or the prohibition on customer credit allocation.

## Rationale

Reasoning, evidence, and alternatives: see [rationale.md](rationale.md).

## Feature design

### Data model

Identifiers reference the actual migrated column types. Existing user and customer IDs are signed integers; new review IDs may use the standard unsigned big integer key. Do not assume all existing keys use Laravel's default type.

| Record | Identity and relationships | Fields and constraints |
|---|---|---|
| users, existing | id; nullable unique customer_id references customers.id. At most one login per customer and one customer per login | Preserve login fields, role assignments, status, and portal profile fields. Lock before assigning customer_id. No new login entity |
| customers, existing | id; add nullable merged_into_customer_id referencing customers.id with restricted deletion | A customer may have many historical sources. No self reference or cycle. Only the merge service writes the destination; a merged source remains inactive and cannot be reactivated through the ordinary toggle |
| customer_phone_verification_challenges, existing | id; user_id and nullable customer_id retain their original meaning. A user has many challenges | Reuse purpose, phone_e164, verified_at, cancelled_at, provider, attempt and send limits, expiry, and token handling. Add the purpose portal_phone_verify as a supported value, not a new table. Do not reassign old proof records during merge |
| customer_match_reviews, new | id; required user_id, customer_id, candidate_customer_id; nullable reviewed_by references users.id | Required reason_codes JSON array, profile_fingerprint string(64), status string(20), and timestamps. Status is pending, merged, or different. Nullable ai_suggestion JSON, ai_checked_at, decision_note text, reviewed_at, and merge_audit_id referencing accounting_audit_logs.id. Unique user_id plus customer_id plus candidate_customer_id. customer_id differs from candidate_customer_id |
| accounting_audit_logs, existing | id; actor, subject type and ID, optional company, structured payload, and created_at | Record customer resolution, verification provenance, scan completion, review decisions, and merge before and after references. Reuse the existing service. A required identity mutation cannot silently succeed if audit storage is missing |

Review customer IDs are historical subjects, not mutable authorization pointers. Retain them after a merge and resolve the current destination for display. Foreign keys restrict deletion of referenced customers and review subjects. The existing application does not gain a new customer deletion workflow.

For a pending review, reviewed_by, reviewed_at, and merge_audit_id are null. A different decision requires reviewer and time; a merged decision also references the successful merge audit. AI data is optional and never necessary to resolve a review. Index status plus created_at for the review list. Reuse the indexed normalized phone lookup and unique users.customer_id constraint. Add a missing subject type, subject ID, action, and created_at audit index only if the effective schema lacks an equivalent index needed by the bounded recovery scan.

Keep decision and merge audit history without automatic deletion in this feature. Retain the minimum SMS challenge metadata needed to establish who verified which phone; do not retain plaintext codes or provider response bodies. Expired codes and encrypted challenge tokens never become reusable credentials.

### Identity evidence and the temporary bypass

Resolve the account phone from users.portal_phone_e164, falling back to the linked customer's phone_e164 only for legacy accounts without a portal phone. Normalize with PhoneNumberService. The phone used for proof is not a browser supplied customer ID or a payment report phone.

Genuine proof requires a successfully verified, uncancelled SMS challenge owned by that user for that current phone and a recognized signup, phone change, or current phone verification purpose. Challenge expiry controls when a code may be accepted, not whether an already completed verification fact disappears afterward. Keep original challenge user, customer, purpose, phone, and verification time as evidence.

The old portal_phone_verified_at and customers.phone_verified_at timestamps alone are insufficient because bypass can populate them today. New bypass signup does not manufacture a verified timestamp or a successful challenge. Record customer.identity.resolved with verification_method = bypass, the server setting value, actor, customer, and time. Genuine verification records verification_method = sms and the challenge ID. Historical timestamps remain historical values until the controlled reconciliation described below; they are never silently promoted to SMS proof.

Account responses gain a phone_verification object:

| Field | Meaning and source |
|---|---|
| method | sms when valid challenge evidence exists; otherwise bypass while the server setting permits it; otherwise unverified |
| satisfied | True for sms, or for bypass while the setting is enabled |
| required | Whether the current server policy requires genuine SMS rather than bypass |
| verified_at | Genuine challenge verification time, or null |
| phone_masked | Masked effective account phone from PhoneNumberService |

Preserve existing response keys for compatibility, but the updated website uses phone_verification.satisfied rather than the old timestamp. RMS enforces the same result server side. Do not send matching reasons, candidate IDs, or historical identity audit payloads to the customer.

The deployment setting CUSTOMER_PHONE_VERIFICATION_BYPASS is the only temporary exception switch. It is not a request parameter, an AI decision, or a second dashboard identity toggle. Preserve its existing default of false. The owner's acceptance is a documented risk waiver, not evidence of phone possession.

When the switch becomes false, bypass only accounts become unverified for actions protected by the phone gate. They can still sign in, view their own existing status and history, and verify the current phone. Do not unlink them, undo accepted purchases, cancel bookings, stop verified provider payment recovery, or retroactively describe a bypass link as SMS verified. Disabling the exception cannot undo information already exposed during it.

### Signup and existing account resolution

1. Preserve registration validation, lowercase email handling, password hashing, role assignment, and duplicate staff or customer email conflicts. The request cannot select a customer ID, role, company, or bypass value.
2. In normal registration, create the existing pending user and SMS challenge. Resolve the customer when the correct code completes signup, before returning the token and account. During bypass, resolve within registration start before its immediate token response.
3. Normalize the full name by trimming, collapsing whitespace, and applying Unicode lowercase. Do not remove accents or punctuation, reorder words, transliterate, or equate similar spellings. Compare normalized values in application code so an accent insensitive database collation does not silently broaden the rule.
4. When CUSTOMER_MATCHING_ENABLED is true, query customers by normalized phone, compare full names, and examine active matching rows and their linked users. An eligible exact match has no login attached, including an inactive one. Lock the relevant customer and user rows and revalidate before linking. A unique constraint conflict reruns resolution rather than overwriting another account. When the setting is false, skip historical matching and use the separately owned fallback below; it does not disable customer ownership resolution.
5. Otherwise create a retail customer using the registration name, phone, email, optional address, normal customer code sequence, and established empty credit defaults. Set created_by to the registering user. Do not copy the possible duplicate's balances, verification timestamps, or financial profile. Do not use CustomerUpsertMatcher, whose import matching by email or name is too broad for portal access.
6. Create the customer, link, resolution audit, and any already known review rows atomically. The review source is the account's new owned customer, not a shared placeholder. An unresolved candidate never blocks that customer's new purchase.
7. Dispatch optional candidate scanning after commit only while matching is enabled, and Gemini ranking only while both matching and AI are enabled. Their failure does not roll back the account. Record the profile fingerprint defined below even while suggestions are paused, so recovery can dispatch missing work after enablement without creating another customer.

An existing active unlinked portal account is resolved on successful login or before its first protected customer mutation using the same service. A read such as GET me does not silently create a customer. An unverified account without bypass receives a separately owned customer if it must be resolved before verification, never historical access. Subsequent verification does not silently switch an already assigned owner; possible duplicate resolution stays with the existing review and merge.

An active unlinked portal user with an already issued valid token receives HTTP 200 from GET me. Preserve the existing account shape: linked_customer is false, link_status is unlinked, customer.id is null, and customer.data_source is portal. Profile fields come from that user's stored portal profile, and phone_verification follows the normal proof resolver. Do not return candidate history or require another login. Existing user owned reads stay within their current ownership boundary. Before a quote or checkout is produced, resolve and commit the customer owner through the same locked service, then calculate the quote or create the attempt against that owner. Normal phone verification and action validation still apply. Concurrent calls and retries reuse the resolved owner; a failed resolution produces no quote, attempt, or financial effect.

Once linked, automatic resolution returns the existing active owner. It does not rerun matching on every login, email change, or phone change. Inactive users are not repaired or reactivated. An inactive customer without an approved merge destination is a support case, not an instruction to create another financial identity.

Exact repeat registration with the same email preserves the existing 409 conflict contract and creates no second user or customer. Do not return an existing account's access token from email equality or a repeated public signup payload. If signup committed but the response was lost, normal login resumes that same account. Repeated OTP submission may return the established inactive challenge error, but cannot repeat customer creation.

### Matching profile fingerprint

Use one shared fingerprint function for scan dispatch, review suggestions, stale result checks, and recovery. Its input is the ordered JSON array ["customer-matching-v1", user ID, canonical customer ID, normalized full name, effective phone]. Encode IDs as decimal strings, the name as a string, and a missing phone as JSON null. Encode compact UTF8 JSON with unescaped Unicode and slashes, then store the lowercase hexadecimal SHA256 digest. Generate it only for a resolved account. The full name comes from users.portal_name, falling back to users.name when absent, with the same case and whitespace normalization used at signup. The effective phone is the normalized account phone defined under Identity evidence. Matching scans use these same name and phone inputs.

Email, address, passwords, tokens, verification timestamps, and other profile fields do not participate. Case or whitespace changes that normalize identically do not create a new matching version. A changed normalized name, effective phone, or canonical customer creates a new version without rerunning automatic ownership linking. Record its fingerprint in customer.matching.profile_changed within the authorized change transaction; the initial version remains on customer.identity.resolved. Dispatch after commit when enabled, with recovery covering a lost dispatch. Repeated unchanged writes create neither a new version event nor another scan. Keep historical review subjects and decisions; refresh only pending suggestions for the current fingerprint and reject obsolete results.

### Private review and Gemini

Keep the current Customer Accounts page, filters, pagination, and merge dialog conventions. Add a possible duplicate filter and a per account candidate list. Show name, customer code, phone evidence held inside RMS, matching reason, optional name similarity, and decision history only to administrators. Do not add another staff application.

Reason codes are a bounded allowlist: name_variant, multiple_exact_matches, existing_login, inactive_candidate, and name_only_candidate. A normal new customer with no candidates needs no empty review item. Known candidates produce review rows synchronously with resolution; a later scan may add other candidates. A different decision stays dismissed on identical future scans. A deliberate later merge through the existing flow can override it only with a new audit event.

Phone equality and full name normalization happen locally. The background scan retains at most 10 suggested candidates per account, prioritizing exact phone candidates, then exact normalized name, then local name similarity. Sort ties by customer ID. Scan names in database chunks of 200, retain only the best bounded set, and never send the customer database to Gemini. These caps limit suggestions, not the complete exact match uniqueness check during signup.

Local name similarity is one minus character edit distance divided by the longer normalized name length. Suggest scores at or above 0.80; this is a suggestion filter only. Handle Unicode characters consistently and empty names as no candidate. An administrator can still use the existing customer search if the desired match is not in the short list.

The optional job calls AiProviderInterface with one submitted name and up to 10 candidate names labeled candidate_1 through candidate_10. Labels are newly assigned per request and map to customer IDs only inside RMS. Serialize names as data, not prompt instructions. Accept only known labels, a similarity value from zero to one, and a reason code from exact_name, spelling_variant, or uncertain. Ignore unknown or repeated labels and reject invalid shapes. Store the validated suggestion and configured model identifier, not a raw prompt or free text provider response.

Reuse the configured Gemini provider and its current timeout. Use the existing queue with at most four total attempts and retry delays of 60, 300, and 900 seconds. Deduplicate jobs by user and profile fingerprint. Before storing a result, recheck that the account, profile fingerprint, and review still match; stale results do not reopen resolved reviews or suggest a customer that now has the same canonical owner. An AI failure remains an optional missing suggestion. Sanitize transport exceptions before logging or persisting failed work because the existing provider URL contains its API key.

The review action takes review ID and the desired outcome. Merge uses the reviewed source and an administrator selected destination through CustomerMergeService; it does not execute from a model score. All action methods and their services recheck active admin authority, not just page visibility.

Review transitions are pending to different or pending to merged. A later explicit administrator merge may change different to merged with a new audit event. Merged is terminal. An automatic scan cannot reverse a decision. If another approved merge makes both subjects resolve to the same customer, close a pending review against that existing merge audit, without executing another merge.

### Existing merge and canonical customer resolution

Canonical customer means the active destination reached through an approved merged_into_customer_id chain. Resolve chains with cycle detection. Never infer a destination from notes, similar contact details, or a browser parameter.

Keep the existing merge interface and add audited preflight validation. Source and destination must be distinct, authorized records. Reject a new merge into an inactive destination. If the destination has an inactive login, require its existing administrator activation workflow before a merge that must preserve a usable login. A privileged staff user attached unexpectedly to either customer is a review error, not a candidate for automatic deactivation.

Acquire customer rows in ascending ID order, then involved user rows in ascending ID order, then mutable domain rows in the established domain lock order. Resolution reads candidates first and acquires customer locks before user locks; it rechecks after locking. New customer insertion happens only after locking and rechecking the unlinked user. All participating customer writers resolve and lock the same canonical owner before writing. A changed merge chain causes a bounded transaction retry, not writes against a stale source.

The transaction validates references, performs the allowed ownership changes, updates the login link, records the destination pointer, deactivates the source, writes one structured audit event, and resolves the matching review. The audit records source and destination IDs, affected record IDs and counts, surviving and disabled user IDs, and before and after invariant totals. If any required part fails, none commits.

For an exact repeat whose source already resolves to the requested destination, return the prior result without another note, audit decision, token change, or balance change. A retry asking to send an already merged source to a different destination returns a conflict. A later intentional merge of the current destination is a separate operation.

If both sides have logins, keep the destination login, deactivate and unlink the source, revoke its Sanctum tokens, clear its remember token, and invalidate its stored sessions. Enforce active status in portal middleware and again inside protected mutations so a request racing with deactivation cannot continue writing. Preserve the source user row as authorship history. If the destination has no login, transfer the source login without changing its password, verified phone evidence, or user ID. A merge with neither login does not create one.

Never reactivate a merged source through ordinary customer or account toggles. Existing manual account linking may resolve a truly unlinked account under administrator authority, but changing an already resolved owner uses the audited merge, not a direct customer_id overwrite. Disable direct unlinking for an account with customer owned orders, requests, invoices, payments, subscriptions, or enabled payment integration history, and show the existing merge action instead. A permitted unlink of an empty account is audited and does not deactivate its customer. A later login may resolve it normally; it cannot claim another account's history.

### Reference ownership matrix

The owning services remain responsible for their records. This matrix distinguishes current ownership from immutable event evidence; moving ownership does not mean rewriting every occurrence of an old ID.

| Records | Merge handling |
|---|---|
| orders, meal_plan_requests | Move live customer_id references. Before merging, attach source login owned rows with null customer_id to the source, then move them. Preserve user_id as the original submitter. A row already owned by a third customer is never claimed from user_id alone |
| meal_subscriptions, sales, pastry_orders, ar_invoices, payments | Move live customer_id through the coordinated merge. Preserve IDs, statuses, amounts, payment method and source, company, branch, dates, and all other financial or operational fields |
| quotations, order_sheet_entries | Move live customer_id. Preserve quotation versions, issued document snapshots, printed recipient details, customer_name, location, and linked order IDs |
| users | Apply the confirmed destination login rule. Preserve historical actor fields elsewhere |
| customer_phone_verification_challenges | Keep the original user, customer, phone, purpose, and proof. New verification uses the surviving account, never proof copied from the disabled account |
| customer_match_reviews and audit logs | Keep original subject IDs and outcome history. Resolve current ownership through the merge chain for admin display |
| Invoice items, payment allocations, subscription order mappings, ledger and bank entries | Keep their existing source and parent IDs. No allocation removal, invoice issue or void, ledger repost, bank transaction, or usage increment is caused by merge |
| payment_checkout_attempts and targets | Resolve their current owner through the approved merge chain. Keep original user plus client UUID uniqueness, request fingerprint, identity, price, timing, and terms snapshots. Do not rewrite original idempotency keys to the surviving login |
| Provider transactions, events, and settlement evidence | Keep provider identifiers, signatures, payload hashes, normalized evidence, and original files unchanged. Resolve the current customer through the attempt and resulting payment |
| Membership purchase blocks and booking funding | Keep original block IDs, payments, credit positions, booking links, amounts, and snapshots. The queue resolver uses the canonical customer across retained subscription history |
| Promotion reservations and redemptions | Preserve original redemption identities and customer evidence. Eligibility queries and reservation locks use the canonical customer plus all merged sources, avoiding destructive unique key collisions |

The baseline model inventory also includes MarketingSetting.google_login_customer_id. It is a Google Ads identifier, not an RMS customer reference, and must not be merged.

Every enabled new customer relation must appear in this matrix and in merge integration tests. A migration test inventories foreign keys to customers plus known logical references and fails on an unclassified reference. Do not use dynamic blind updates of every customer_id column.

### Membership and promotion integration contract

Customer matching does not redesign membership tables or implement the future payment and promotion slices ahead of their specifications. Those slices must obey this merge contract before they are enabled.

Retain existing subscription records as history after changing their customer owner. A single customer queue coordinator reads their eligible blocks together; there is no independent competing spending loop per former customer. For compatible company, branch, and currency context, order blocks by original funded time then stable block ID. Existing reservations and paid invoice usage keep their original attribution, even if the combined order now has an older block before them. Only subsequent free selections use the merged order.

Queue totals equal the sum of the distinct retained blocks and their existing usage, with no second copy in another active balance. Do not both copy allowance into a destination subscription and continue counting the source allowance. The membership slice must update all availability, generation, booking, invoice listener, pause, and void consumers to use that same canonical queue view. It selects the destination's existing queue anchor for subsequent purchases when present, otherwise the oldest eligible source anchor, without erasing source history.

Customer identity is global in the current schema. A merge does not change a record's owning company or branch, convert its currency, combine incompatible funds, or authorize cross company spending. Historical records remain visible only through their existing scope rules. This is separate from the default company rule for new SkipCash purchases.

Promotion history is the union of distinct reservation and redemption identities, not a sum of cached counters plus the same rows again. Completed prior uses remain honored. New reservations lock the canonical owner and check the union. Old unique keys using original customer IDs remain intact. If several historical 100 percent requests exist after a merge, preserve every request and deny another use; a repeated submission resolves deterministically to the earliest existing redemption by created_at then ID, without inventing a new request or rewriting later history.

An open paid checkout preserves its accepted quote and promotion reservation during merge. A verified collection is processed through 0001 using its original immutable contract and approved owner mapping, not rejected merely because merge changed later eligibility. Fresh quotes and checkouts use the combined history. Merging does not create additional reservations or release an unresolved paid attempt's existing reservation.

### API and interface surface

The existing registration and account endpoints keep their methods and main response shapes. The following additions complete the SMS transition without a verification dead end.

| Surface | Method and inputs | Output | Access and errors |
|---|---|---|---|
| /api/customer/auth/register/start | POST existing name, email, password, phone, optional address | Existing registration token and masked phone, or immediate bypass token and linked account | Existing throttle. 409 existing email, 422 invalid input, 503 inability to commit or send. A request cannot select bypass |
| /api/customer/auth/register/verify | POST existing registration_token and code | Existing token and now linked account with phone_verification | Challenge scope and expiry. Existing 409 bypass and 422 invalid or consumed challenge behavior; no duplicate identity effect |
| /api/customer/auth/register/resend | POST existing registration_token | Existing send result | Existing cooldown, count, token, and bypass errors |
| /api/customer/auth/login | POST existing credentials | Token and account; resolve an active unlinked portal user as described above | Credential and active role checks. Never return a token to a disabled source account |
| /api/customer/me | GET | Existing account plus phone_verification; preserve the unlinked envelope above for a legacy active unlinked user | HTTP 200 for a valid active portal token, linked or unlinked. Read only, private, no cache |
| /api/customer/profile/phone/verify-current/start | POST no phone or customer ID | verification_token, effective masked phone, existing challenge timing | Active customer token, but not the phone verified middleware. 409 bypass or already verified, 422 no usable phone, 429 throttle, 503 send failure |
| /api/customer/profile/phone/verify-current/verify | POST verification_token and code | Updated account and genuine verification result | Token user, original subject, purpose, and current phone must match. 422 invalid or expired proof, 409 stale phone or bypass |
| /api/customer/profile/phone/verify-current/resend | POST verification_token | Existing resend timing and masked phone | Same authenticated user and purpose; existing send limits and cooldown |
| Existing profile phone change endpoints | POST existing phone change inputs | Existing updated account plus phone_verification | Preserve unavailable behavior during bypass. Verify token ownership and purpose; update the surviving user's portal phone and linked contact consistently, without rerunning customer matching |
| Customer Accounts review actions | Existing Volt page; review ID, outcome, optional decision note | Updated private review and audit result | Active admin checked by route, action, and service. 403 denied, 409 stale or conflicting decision, validation errors without partial merge |
| Existing customer merge action | Existing source and target selection | Expanded preview and one audited outcome | Active admin. Same target retry succeeds without duplicate changes; a different target after merge is a conflict |

Use the existing signup challenge code length, expiry, resend limits, and cooldown for current phone verification. Keep challenge creation and SMS transport separate from the customer ownership transaction. A failed or uncertain SMS send does not fabricate successful proof. The user can retry under existing limits; automatic transport retries never bypass those limits.

Successful current phone start, verify, and resend return HTTP 200. Apply the existing auth throttle of five requests per minute to these routes as well as per challenge limits. An inactive portal user returns 403 with code CUSTOMER_ACCOUNT_INACTIVE; an unsatisfied phone gate retains 403 PHONE_NOT_VERIFIED. The website distinguishes account invalidation from a verification prompt. Missing or revoked tokens retain the existing 401 behavior.

Phone change and current phone verification must bind the decrypted token's user ID to the authenticated user, not merely trust that the token is valid. A merge can invalidate a pending challenge for the disabled source login; it cannot transfer that challenge's authority to the destination login.

### Value sourcing

| Action | Produced value | Source |
|---|---|---|
| Complete signup | Login, normalized profile, customer owner | Existing validated registration fields, PhoneNumberService, exact matching rule, locked users.customer_id |
| Select matching policy | SMS, bypass, or unverified | Retained verified challenge for the current user and phone, plus Laravel config('customers.verification_bypass'), mapped from CUSTOMER_PHONE_VERIFICATION_BYPASS in config/customers.php, not a customer field |
| Create a customer | Code, name, type, contact and address, actor | CustomerCodeService, validated portal profile, retail default, registering user |
| Record resolution | Method, chosen owner, proof reference, matching generation | Server decision, locked records, genuine challenge ID or explicit bypass, and the versioned digest defined under Matching profile fingerprint |
| Read an active unlinked account | Unlinked flags, null customer ID, portal profile, verification state | Authenticated users.customer_id and stored portal fields through the existing account serializer, plus the proof resolver; no candidate data or ownership mutation |
| Display verification | satisfied, required, method, timestamp, masked phone | Server proof resolver and PhoneNumberService, never browser storage |
| Display duplicate review | Candidates and reasons | Local phone and normalized name checks, bounded background similarity scan, current review rows |
| Display AI suggestion | Candidate similarity and reason | Validated response for temporary labels mapped inside the job to the stored review |
| Resolve review | Outcome, reviewer, time | Explicit authorized admin action and server time |
| Merge | Canonical destination, surviving login, affected references | Locked source and destination, confirmed login rule, typed merge chain, reference matrix |
| Continue delayed payment | Current owner without changed payment facts | Original signed or verified payment contract plus the audited merge chain |
| Read membership balance | Remaining allowance and funding sequence | Distinct retained block and booking history under the canonical customer; formula and original prices from 0001 |
| Check promotion limits | First purchase state and used counts | Union of completed history and unresolved reservations across canonical customer and merged sources |
| Recover background work | Accounts needing a candidate scan | Current active resolved account fingerprint, latest customer.identity.resolved or customer.matching.profile_changed event, and absence of a matching customer.matching.scan_completed event |

### Authorization, failure handling, and observability

Customer token middleware rejects inactive users as well as wrong roles and abilities. Staff review uses the current admin only Customer Accounts boundary. Customer records have no company_id today; do not invent a tenant field or treat that as permission to bypass company and branch checks on financial records.

Uncertain matches are not errors. Database or required audit failure is an error: return an actionable retry response with no partial customer link. Bounded deadlock retries rerun the same resolution or merge transaction. Do not swallow a failed ownership write and return a customer ID that was never committed.

Use the existing audit service for resolution, review, and merge. Record IDs, method, proof ID, reason codes, fingerprints, and before and after counts. Avoid passwords, tokens, full phone numbers, names, addresses, raw Gemini messages, or provider response bodies in operational logs, exceptions, and failed job payloads. Queue payloads contain IDs and fingerprints, not customer profiles. Audit history is mandatory, not best effort.

Add a bounded customer matching recovery command on the existing scheduler every five minutes, with overlapping runs prevented. While matching is enabled, inspect up to 100 pending current account generations per run and dispatch missing optional scans once. Ignore obsolete generations and inactive users. Record scan completion even when there are no candidates, but not when work is paused by the matching setting. A separate read only report checks merged source activity, duplicate links, broken merge chains, and uncovered references. These checks never auto merge, move money, repair balances, or reactivate a login.

### Website behavior

Implement against /Applications/XAMPP/htdocs/laylakitchen, the inspected customer website location. Its active proxies are api/orders, not the older api/daily-dish copies. Reuse its authorization forwarding and no cache behavior.

Preserve registration's upstream 409 to HTTP 200 conflict envelope with success false and conflict true. A response is not successful merely because its HTTP status is 200. Add matching proxies for current phone verification and update orders-core.js to use RMS phone_verification.

Refreshing me after login or before checkout reloads current server identity and policy. Cached phone timestamps cannot preserve bypass after it is disabled. Preserve cart selections while asking the customer to verify, and return to the same review afterward. If the account changes to a different customer, clear cached private account data and revalidate any customer bound cart and promo state before submission.

The legacy unlinked response is not logout, an error, or a staff approval gate. Keep the valid token and cart, show the normal verification prompt only when required, and continue to the server quote or checkout action that resolves ownership. Refresh me after that action succeeds. Do not ask the customer to wait for manual linking or show another customer's history while no owner is linked.

For a disabled source login, clear its bearer token and cached private history. Show a neutral sign in or support message, not the destination email or account details. Do not silently sign the browser into the destination account. A transferred source login retains its normal credentials and refreshes the new owner from RMS.

Reuse current page components and styles. Verify 360 px, 768 px, and desktop layouts, keyboard labels, loading states, duplicate submit protection, and visible modal actions. These authenticated surfaces add no public SEO or indexing work.

### Configuration

* CUSTOMER_MATCHING_ENABLED, new, defaults false until both applications and database checks are ready. It controls historical automatic linking and new candidate suggestions only. When false, completed signup and active unlinked account resolution still create an owned fallback customer, existing links remain usable, and no candidate scans or Gemini requests run. Existing reviews and authorized manual merge remain available. Workers recheck the setting before processing or storing suggestions. Reenabling resumes missing current scans but never automatically rematches an already owned account.
* CUSTOMER_MATCHING_AI_ENABLED, new, defaults false and enables only optional background ranking while CUSTOMER_MATCHING_ENABLED is also true. It may be enabled after the approved names only payload test passes.
* CUSTOMER_PHONE_VERIFICATION_BYPASS, existing, remains the temporary owner accepted policy switch. A cached configuration refresh and worker restart must take effect together during a switch.
* Existing phone normalization and SMS timing settings remain authoritative.
* Existing services.gemini configuration and AiProviderInterface remain authoritative. No new Gemini credentials or model choice is assumed.

Launch checks require the customer role, customer code sequence, customer and audit tables, unique users.customer_id, a shared queue and scheduler for eventual review, and a configured SMS provider before bypass is disabled. Queue failure cannot block a committed signup.

### Critical test scenarios

The full matrix is in [verify.md](verify.md).

* Bypass and real SMS exact matches, no matches, spelling differences, and occupied matches prove **AC-1**, **AC-2**, **AC-3**, and **AC-4**.
* Concurrent claims, a lost signup response, repeated verification, and recovered login prove **AC-5** and **AC-12**.
* Admin review, denied actors, invalid Gemini labels, provider failure, and stale profile results prove **AC-6**, **AC-7**, and **AC-14**.
* Merges with both logins, one login, no logins, session races, and repeat requests prove **AC-8**, **AC-9**, and **AC-12**.
* Delayed payment after merge, both membership queues, and duplicate prior promotion uses prove **AC-9**, **AC-10**, and **AC-11** before those payment slices are enabled.
* Bypass shutdown, current phone verification, retained cart, revoked login, and the proxy conflict envelope prove **AC-3**, **AC-13**, and **AC-14**.
* Matching disabled and reenabled, a legacy unlinked token continuing through quote without another login, and fingerprint changes versus excluded profile fields prove **AC-1**, **AC-5**, **AC-6**, **AC-7**, **AC-12**, **AC-13**, and **AC-14**.

## Migration plan

**Strategy**: Additive schema and guarded rollout.

1. Add the nullable customer merge reference and private review table with compatible foreign keys and indexes. Add no default destination and no automatic merge based on historical notes.
2. Deploy the shared resolver, genuine proof checks, authenticated current phone verification endpoints, audit enforcement, and website response support together while historical automatic matching remains disabled. Separately owned fallback creation is active in this deployment. Preserve HTTP 200 unlinked reads for existing valid tokens and keep the independent live payment readiness gate closed until verification passes.
3. Run a read only inventory of existing links, normalized phones, current user phone evidence, historical bypass timestamps, source user owned rows with no customer, and all references. Classify evidence from actual challenges only. Retain unexplained historical timestamps as untrusted; do not create synthetic proof.
4. Normalize missing phone lookup values in resumable batches using the existing normalization rules, without changing the raw contact or interpreting a formatting change as verification. Preserve all existing links. An actual database uniqueness constraint that prevents the separately owned fallback is a deployment blocker to resolve explicitly, not a constraint to drop silently.
5. Validate one complete signup, review, and merge path in the safe test environment, then enable matching. Identity plus every currently enabled financial reference must pass its merge tests before live SkipCash traffic uses it.
6. Before enabling genuine SMS policy, test sending and receiving a code with an approved test account, deploy all verification proxies, disable bypass, refresh server configuration, restart workers, and confirm old bypass only accounts now need genuine verification for gated actions. Do not run a destructive timestamp cleanup or unlink users.

**Rollback**: Disable historical automatic matching and optional suggestions while retaining fallback customer creation, the schema, approved links, merge pointers, audits, and completed records. This setting alone does not suspend signup or ordering. Do not roll back to code that accepts a bypass timestamp as genuine proof. If the identity resolver itself is unavailable, suspend new payment checkout rather than restore unsafe ownership writes. Existing provider recovery must retain the compatible canonical owner resolver.

**Risks**: The temporary bypass permits fraudulent claims of unlinked history, and later SMS cannot undo earlier exposure. Existing manually linked accounts may have no surviving SMS evidence and need verification when bypass ends. A merge is not automatically reversible, because later transactions can depend on the destination.

## Build plan

The Tracer Bullet approach proves a thin real customer path through the website, RMS, persistence, and admin review before broadening it. Later payment tables remain owned by their payment and membership slices, with mandatory extension tests here before enablement.

1. Add the minimal schema, resolver, audit events, server verification result, and website response support. Prove one bypass signup with historical matching disabled returns one owned customer and can continue the existing order path, satisfies **AC-1**, **AC-3**, **AC-4**, **AC-5**, and **AC-13**.
2. Add exact matching, genuine SMS proof, safe active unlinked login recovery, legacy unlinked token reads and quote continuation, conflict handling, and concurrency tests across signup and customer mutation writers, satisfies **AC-2**, **AC-3**, **AC-5**, **AC-12**, and **AC-13**.
3. Complete the private Customer Accounts review path and one audited merge through all current customer references, including source login invalidation, proof preservation, repeat execution, and before and after financial assertions, satisfies **AC-6**, **AC-8**, **AC-9**, and **AC-12**.
4. Add bounded candidate scanning, optional names only Gemini ranking, the shared versioned fingerprint, stale result rejection, matching pause and resume behavior, and scheduled missing work recovery, satisfies **AC-4**, **AC-5**, **AC-6**, **AC-7**, and **AC-14**.
5. Add the authenticated current phone verification path, matching website proxies, bypass shutdown behavior, phone change token binding, and migration inventory checks, satisfies **AC-3**, **AC-12**, **AC-13**, and **AC-14**.
6. In each payment, membership, and promotion slice, implement the corresponding reference matrix extension and prove merged ownership, preserved history, one customer queue, combined promotion limits, and delayed callback behavior before enabling that slice, satisfies **AC-8**, **AC-9**, **AC-10**, **AC-11**, and **AC-14**.
7. Run the complete current domain suites, scoped formatting and builds, website mock integration checks, and the release matrix. Inspect the final diff for secrets and unrelated work, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-5**, **AC-6**, **AC-7**, **AC-8**, **AC-9**, **AC-10**, **AC-11**, **AC-12**, **AC-13**, and **AC-14**.

## Consequences

**Positive**:

* Normal signup no longer depends on routine staff linking.
* One existing admin workflow handles uncertain matches.
* Payment recovery and merge can retain their original evidence while finding the surviving customer.

**Tradeoffs**:

* The explicitly accepted bypass risk remains until SMS is enabled. Exact contact matching and an unoccupied login do not eliminate impersonation.
* Uncertain matches can create real duplicate customer records that need eventual admin review.
* Disabling bypass can require verification for existing accounts whose old timestamps have no genuine proof.
* Review, canonical owner resolution, and merge tests add work to every future customer owned integration.

## Follow-up

* [x] Design accepted on 2026-08-30 after the independent review and approved clarifications. Scope feature 8 and the shared 0001 identity boundary now reference this contract, including the explicit temporary bypass exception. Implementation has not started, so the lifecycle status remains Proposed.
* [ ] Confirm actual deployed schema, configuration, SMS provider readiness, queue, and session storage during implementation. No environment changes are authorized by this document.
* [ ] Complete the membership, promotion, and payment merge extensions in their owning slices before those features go live.
* [ ] Revisit the temporary bypass exception as soon as SMS is configured, using the rollout procedure above.
