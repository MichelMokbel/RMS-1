# 0004. SkipCash settlement and fee clearing

**Date**: 2026-08-31
**Status**: Proposed

## Summary

You import the SkipCash report into RMS, review its payment matches and deductions, then post the net deposit into the default bank. Customer payments stay at the amount the customer paid. Commission and settlement fees become separate expenses, and bank reconciliation confirms the deposit without recording another receipt.

The owner approved this build specification for scope feature 3 on 2026-08-31 after the independent review corrections. Its status remains `Proposed` because the feature is not built. It refines the already approved [shared accounting contract](../0001-payment-accounting-contract/index.md); it does not implement the feature or change checkout, membership, promotion, revenue, or refund policies.

## Requirements

**User stories**:

* As the administrator, I want to upload the provider report and review one payout summary instead of entering each commission manually.
* As finance staff, I want the report, customer collections, expenses, clearing balance, and bank deposit to reconcile without changing customer credit or meal allowances.
* As an authorized reviewer, I want a clear explanation of unmatched rows and a traceable correction when a posted settlement is voided.

**Acceptance criteria**:

* **AC-1**: Upload preserves the original XLSX privately and stages all report rows without creating payments, invoices, allocations, ledger entries, or bank transactions. Blank and literal `Null` values normalize to missing evidence. Invalid or unsupported content remains visible and cannot post.
* **AC-2**: Each successful `Sale` reconciles its gross, component commissions, total commission, and net in exact QAR cents. Post sale `totalCommission` once. Each `Settlement Fee` contributes its own expense and negative net once. A fee outside the report sales period is retained.
* **AC-3**: Match a sale only to one existing, verified SkipCash provider transaction with its posted AR payment. Source, company, mapped branch, currency, amount, and original clearing evidence must agree. Verified provider references authorize a match; phone, amount, and date alone only suggest candidates. No match creates a receipt or changes customer ownership.
* **AC-4**: Review complete payouts by source and payout reference, not file name or phone. The evidenced default bank deposit amount equals the reviewed net. The bank settlement date comes from explicit report evidence, a selected imported bank deposit, or retained remittance evidence. Missing, conflicting, partial, or unsupported evidence prevents posting of that payout.
* **AC-5**: A posted payout creates one canonical AR clearing settlement, its gross payment links, separate fee adjustments, a balanced subledger entry, one net bank book transaction, settled payment markers, and audit records atomically. The method remains `skipcash`. Missing accounts, wrong ownership, finance locks, and closed periods leave all financial records unchanged.
* **AC-6**: Identical uploads, reexports, changed row order, concurrent posts, and repeated requests never duplicate settlements, payment claims, commissions, or fees. Conflicting content and overlaps with an already posted payout require review. A lost response can be recovered from the existing result.
* **AC-7**: Existing bank reconciliation matches the net book transaction to the actual statement deposit. Matching and unmatching never create another customer payment or recognize revenue. A match cannot steal a statement deposit already used elsewhere.
* **AC-8**: Existing authorized settlement void reverses the original posting, voids its bank book transaction, and releases its payment clearing markers together. It preserves the report, fees, identities, and audit trail. It never voids customer payments, invoices, allocations, memberships, or bookings. Posted amounts and dates cannot be edited.
* **AC-9**: Dedicated import, review, posting, and void permissions apply at every server entry point. The initial Administrator can perform the complete flow without a second approver. Company and branch isolation covers lists, totals, candidates, downloads, review, posting, and void. Customer portal users have no settlement access.
* **AC-10**: The existing RMS clearing area exposes SkipCash import, review, posting, history, exceptions, and unsettled totals. Existing card and cheque behavior remains compatible. The customer website has no new settlement screen or action.
* **AC-11**: Files and personal evidence remain private, logs are sanitized, and original identifiers and financial history survive customer merge. Tests use synthetic workbooks. Disabling new settlement mutations preserves access to historical records and never disables valid customer payment recovery.

## Decision

**Chosen option**: Extend canonical AR clearing with a separate SkipCash import and review adapter.

Keep the approved import, row, settlement, payment item, and adjustment records from 0001. Reuse the existing safe XLSX reader and the AR, subledger, bank, period, and audit services. Add explicit SkipCash branches to those writers rather than treating it as card or building a second accounting system. (basis: 0001, `AGENTS.md`, `ArClearingSettlementService`, `SubledgerService`, `BankTransactionService`, and `SafeSpreadsheetReader`)

The existing stack, provider, model relationships, financial rules, default ownership, permissions strategy, and evidence rules are carried forward from the prior approved design. Parser options, review snapshots, endpoint details, and test organization below are implementation recommendations for this slice, not new customer policies.

## Rationale

Reasoning, alternatives, report evidence, and references: see [rationale.md](rationale.md).

## Feature design

### Boundaries and prerequisites

This RMS enhancement depends on the source and verified receipt records from [0003](../0003-skipcash-paid-order/index.md). Membership receipts later enter the same clearing path without waiting for daily invoice allocation or meal consumption. A payment held as customer credit is also eligible once its actual receipt is posted. An administrator allocating existing credit produces no new collection to settle.

The report is settlement evidence, not authority to declare a customer payment paid. A missing RMS receipt goes through the existing verified payment recovery workflow. A historical provider transaction absent from that workflow stays unmatched; this slice includes no historical receipt import, opening balance fabrication, refund processing, automatic payout retrieval, or independent journal editor.

### Data model

Use the coherent target approved in 0001. New IDs and foreign keys follow their referenced MySQL types. New money fields are signed `BIGINT` cents with checked bounds, not floating point. Also enforce the receiving legacy column's range and exact JSON integer limits. Nonnegative fields reject negatives; net report rows may be negative. Hashes use SHA256. Instants use UTC `datetime(6)`; bank and report dates use `date`. References remain bounded strings, retaining leading zeros. Private structured evidence uses encrypted text casts, not plain JSON.

| Record | Keys and relationships | Required fields and refinements |
|---|---|---|
| `gateway_settlement_imports` | PK `id`; FK company, source, importing user; one import has many rows and zero or more posted settlements; unique `(payment_source_id, file_hash)` | Private disk/object key, original display name, hash, parser version, currency/timezone snapshots, report period, integer imported totals with completeness flags, review state, posting state, revision, encrypted batch review snapshots and evidence manifest, saved post operation results, created/reviewed actors and times, sanitized error code |
| `gateway_settlement_rows` | PK `id`; FK import, source, nullable matched provider transaction, nullable duplicate/conflict row; unique `(import_id, row_sequence)` | Original worksheet and physical row, normalized type/status, payout and row references, economic identity and financial content hash, encrypted source evidence and normalized phone, branch code/mapped branch, transaction time, nullable explicit bank date, all gross/commission/fee/net cents, match state, evidence reference, reviewer/time and reason |
| `ar_clearing_settlements` | Existing PK; FK company/bank/user; add source and import FKs; unique source plus payout reference for SkipCash; nullable unique FK `evidence_bank_transaction_id` to the selected statement deposit | Keep `amount_cents` as gross. Add commission, settlement fee and net cents, payout reference, reviewed fingerprint, evidenced `settlement_date`, evidence snapshot, original receipt clearing account breakdown, expense/bank account snapshots. Existing immutable header and void fields remain canonical |
| `ar_clearing_settlement_items` | Existing PK and settlement/payment FKs; unique settlement/payment; add nullable provider transaction and source report row FKs | Existing amount is the full gross receipt. These remain historical links after void; no allocation IDs or remaining credit amount determine settlement value |
| `ar_clearing_settlement_adjustments` | PK `id`; FK settlement/source/expense account/source row; unique `(payment_source_id, adjustment_type, economic_identity)` | Type `commission` or `settlement_fee`, positive amount cents, immutable account and evidence snapshots. Omit zero value adjustments. Preserve rows and unique economic identity after void |
| `payment_provider_transactions` | Existing transaction to receipt relationship from 0003; add nullable FK `active_clearing_settlement_id` | One scalar active settlement claim per provider transaction. Claim and release under transaction and payment locks; verify historical settlement items also agree. Never change provider identity or receipt linkage |

No new customer, invoice, payment, membership, or delivery entity is introduced. Batch review snapshots are draft evidence on the import used to start review, not a parallel settlement ledger or a file bounded payout. Key them by source and payout reference; each stores all contributing import and member row IDs, their revisions, payout and reviewed fingerprints, expected deposit, bank/date evidence, resolved account IDs, reviewer, time, and revision. Payout membership is resolved across imports as specified below. Posted settlements retain their own immutable copy, including the original import of every contributing row.

The encrypted evidence manifest maps an immutable evidence UUID to its purpose (`provider_reference` or `bank_remittance`), import/payout binding, private object key, MIME type, byte count, SHA256, uploader/time and reviewed extracted values. Evidence retains its owning import. A combined payout review may reference evidence from a contributing import only after checking that import's access and the same source/payout binding. It cannot accept unrelated import or payout evidence. Row match actions still use the row's original import route. Saved post operations are keyed by client UUID within the locked entry import and retain request fingerprint, selected payout keys, resulting settlement IDs and completion time. They contain no personal data. Invalid numeric row values stay nullable with retained source evidence and a blocking error; never replace unknown amounts with zero or label incomplete totals as reconciled.

Add lookup indexes for source plus economic identity, source plus payout reference, import plus match state, and company plus creation time. Source references and hashes are bounded and compared deterministically. Do not cascade delete financial or evidence rows. Existing card and cheque records retain null gateway fields; no historical reclassification or backfill is required.

### Report mapping and normalization

The supplied workbook has one populated worksheet, `Sheet1`, with headers in row 1 and two data rows. Require exactly one populated report sheet; reject additional populated sheets rather than silently dropping them. Match known header names, not column order. The existing reader normalizes camel case names to lowercase without inserting underscores, for example `totalCommission` becomes `totalcommission`.

| Report fields | Treatment |
|---|---|
| `period` | Sales reporting window, parsed as `DD/MM/YYYY - DD/MM/YYYY`, never a posting date or row exclusion rule |
| `paymentRef` | Payout grouping evidence within the configured source/merchant. Required before posting; not an individual customer payment ID |
| `referenceNumber` | Row identity. Never assume it equals provider payment ID, merchant transaction ID, or Visa ID without verified mapping evidence |
| `orderType`, `status` | This adapter supports `Sale` and `Settlement Fee` with `Successful`. Other values are preserved with a blocking reason, not silently discarded |
| `transactionDate`, `transactionTime` | Separate report event date/time, including `DD-MMM-YYYY` and `HH:mm:ss` as in the sample; parse strictly in the snapshotted Qatar timezone |
| `merchant`, `branch` | Match configured merchant and explicit provider branch to RMS branch mapping. A provider branch number is never used directly as an RMS primary key |
| `orderId` | Optional correlation evidence. Missing values are valid and do not become zero or a fabricated ID |
| `customerPhoneNumber` | Optional supporting evidence, normalized using the existing RMS phone rules and encrypted. Not a uniqueness key, ownership grant, or automatic financial match |
| `sales`, `grossAmount` | Retain both. For the supported report profile they agree and identify the actual provider collection, not the undiscounted membership list price |
| `variableCommission`, `fixedCommission`, `totalCommission` | Retain all. Validate the component sum, but expense only the total once |
| `netMerchantSettlementAmount` | Signed row net. Sum once for the batch; never subtract the separate fee again |
| `skipcashCoupon` | Provider evidence only. A nonempty value that changes the supported gross/net relationship requires review, never an RMS promo redemption |
| `method`, `paymentMethod`, commission percentage/fixed rate fields, `mobile` | Evidence only. They do not select RMS method, recompute charged fees, or replace the customer phone source |

Currency and timezone are not workbook columns. Snapshot verified merchant configuration, QAR and `Asia/Qatar`; do not infer currency from a cell number format. No bank date column exists in this sample. A future report profile with an explicit bank date needs a tested mapping before use; do not guess a column alias at runtime.

Keep the existing `SafeSpreadsheetReader` formula, external link, macro, XML entity, archive expansion, row, column, and cell protections. Add opt in raw numeric string and physical row metadata output for the settlement adapter. Existing callers keep their current return types. Money and identifiers must not pass through its present float or integer conversion before settlement normalization. Parse decimal strings into cents with checked integer arithmetic; trailing zero precision is harmless, but reject nonzero precision beyond two decimals, exponent notation, separators, silent rounding, locale guessing, and monetary tolerance. Rates remain evidence, not posting inputs.

Upload accepts `.xlsx` only, maximum 10 MiB, with the reader's existing 100 MiB expanded archive and structural limits. The adapter permits at most 10,000 data rows per upload and returns an actionable error for a larger file. These are technical safety limits in configuration, not payment limits. Parsing and staging are bounded synchronous work in this release. A failed parse creates no staged financial rows; a lost successful response is recoverable by file hash. A private object left by a database failure is not posting authority and is removed only after a reference check.

### Exact amounts

For each supported sale, in cents:

```text
sales = gross
gross > 0
variable_commission + fixed_commission = total_commission
gross - total_commission = net
settlement_fee = 0
```

For each supported settlement fee row:

```text
sales = gross = 0
variable_commission + fixed_commission = total_commission
settlement_fee = total_commission > 0
net = -settlement_fee
```

All commission components are nonnegative. A contradictory row stays in review. For a complete payout, sum distinct economic rows, not duplicate evidence copies:

```text
G = sum(Sale.gross)
C = sum(Sale.total_commission)
F = sum(SettlementFee.settlement_fee)
N = sum(all row net) = G - C - F
```

The supported automatic posting path requires `G > 0` and `N > 0`, with an evidenced incoming deposit of `N`. Fee only, zero net, or negative net batches stay visible for finance review; they do not manufacture a customer receipt or an incoming bank transaction. Review shows raw file totals separately from distinct economic totals so repeated evidence is explainable.

### Match and review

1. Resolve the authorized company from RMS context and the selected source. Its merchant, report branch mapping, posted receipt company and bank company must agree. Source deactivation does not strand already collected money; settlement authority is separate from enabling new checkout.
2. Use the source scoped provider identifier mapping approved during setup. Its retained evidence identifies which report field corresponds to which provider field. This mapping is not established by the supplied file alone. Until established, show candidate transactions and require retained provider evidence for each manual link.
3. Candidate suggestions may use normalized phone, exact gross, and report date. Confirming a candidate requires a verified reference from report or retained provider evidence that resolves exactly one transaction. A reviewer cannot waive amount, source, company, currency, or branch mismatch. Do not call Gemini or use fuzzy names to match money.
4. Require a verified paid transaction with one nonvoided `payments.source = ar`, `payments.method = skipcash` receipt, matching source, amount and company, plus its original posted clearing entry. A verified capture still awaiting accounting stays unmatched for settlement. Resolve current customer through existing merge ownership; never compare only an old phone snapshot.
5. Resolve every persisted row across all imports for the same `payment_source_id` and payout reference, not only the import named in the route. Count each distinct economic row once and retain proven duplicates as linked evidence. Unmatched, conflicting and unsupported rows in any contributing import remain part of the payout and block its review/posting; a reviewer cannot omit them or a fee to make the total balance. Independently complete payouts remain reviewable separately.
6. Show the combined payout with each row's original import and a `payout_fingerprint` covering the ordered member IDs, revisions, normalized content, matches and evidence references. Save the reviewed fingerprint from that membership plus the selected bank/date and account snapshots. The review request must match the displayed payout fingerprint. New rows, rematching, evidence replacement, or relevant configuration changes invalidate affected reviews across imports. Posting resolves the global membership again rather than trusting browser rows or totals.

The route's `{import}` remains the entry point and owner of its review/request result, not a filter on economic payout membership. Raw file totals remain specific to their file; combined payout totals are clearly separate and never summed once per contributing import. Reads derive posted or voided payout state from the same canonical settlement regardless of which import was opened. An incomplete or conflicting combined payout returns `PAYOUT_REVIEW_REQUIRED`; a changed membership or review returns `REVIEW_STALE`, both HTTP 409. If the actor cannot access every required branch or contributing evidence record, deny review/posting without exposing protected rows or silently reviewing only the visible subset. Original file downloads still require access to every row in that file, not only the selected payout.

The default bank comes from existing finance settings and must be active, QAR compatible, and owned by the retained company. Never move an old source's settlement to a different company because the global default company changed. If the current default bank cannot serve that company, require an authorized finance configuration correction and review again.

When an imported statement line is supplied as evidence, require the same bank, incoming direction, exact net, an unused nonvoided line, and reference or retained remittance evidence associating it with this payout. A matching amount alone is insufficient. Save its transaction date and ID; selecting it for review does not yet mark it reconciled. Posting reserves that deposit through the settlement's unique `evidence_bank_transaction_id`. This settlement evidence reservation prevents use by another payout, but is not a bank reconciliation pair and must allow later matching to this settlement's own book transaction. Alternatively, retain a private bank/provider remittance file and record its payout reference, bank, exact amount and evidenced date, reviewer and time. That path posts an open book transaction for later normal statement matching.

The settlement date is the evidenced bank date under 0001. The sale date, fee row date, upload time, file name, and manually supplied post date are not substitutes. Evidence replacement before posting requires a reason and a fresh review. There is no date override input to posting.

### Identity, lifecycle, and atomic posting

Economic identity is SHA256 of a versioned canonical tuple `[source_id, orderType, paymentRef, referenceNumber]`. Missing row or payout references require retained provider evidence before assigning an identity; never derive one from row number, phone, or amount. The separate financial content hash covers normalized source/merchant/branch, type/status, identifiers, dates, currency and monetary evidence. It excludes contact changes, presentation, upload time and row order.

Matching identity and content links duplicate evidence. Changed content blocks the payout. A changed exported row reference cannot bypass the provider transaction's active claim. Fee adjustment uniqueness and source/payout uniqueness survive a void, so ordinary import or post cannot resurrect a reversed settlement. An already posted payout cannot post only its newly supplied remainder. Do not mutate old rows when a revised report arrives.

Import review states are `staged`, `needs_review`, and `reviewed`. Batch snapshots hold their own review state; aggregate import state is derived from them against current combined payout membership. Posting state is `unposted`, `partially_posted`, or `posted`, derived from distinct payout groups and canonical settlements across imports. A voided settlement is shown explicitly as voided with the derived `correction_required` outcome below, not pending for automatic repost. Report row states are `unmatched`, `matched`, `duplicate`, `conflict`, or `unsupported`; correction required is an additional finance projection, not a rewritten raw row or customer payment status.

Post accepts selected complete reviewed payout references and their fingerprint. One request is atomic across its selected payouts. Lock in a consistent order: payment source, all contributing imports by ID (including the entry import), selected existing settlement identities, provider transactions by ID, payments by ID, then the bank and required reconciliation records. Staging commits and row/evidence changes use the same source lock as review, post and void. Resolve combined membership and fingerprints after taking that lock, so a newly committed import cannot be missed by a competing post. A changed review must be confirmed again; no subset posts. Integrate with existing finance lock ordering and retry bounded database deadlocks through the same saved request identity.

Inside the transaction, reauthorize, verify review freshness, period and finance locks, receipt state, every claim and amount, account validity, bank evidence, and the unchanged default bank. For each payout:

* Create the immutable AR settlement and full gross payment items, then positive commission and settlement fee adjustments.
* Claim each transaction and update `payments.clearing_settled_at`. Claim and history must agree before commit.
* Post `SubledgerService` source `ar_clearing_settlement`, event `settle`, with debit bank `N`, debit commission expense `C`, debit settlement fee expense `F`, and credit original SkipCash receipt clearing accounts totaling `G`. If all receipts share one account this is one clearing line; otherwise group by their retained account. Current source mapping never redirects an older receipt's clearing credit.
* Create `BankTransactionService` source `ArClearingSettlement::class`, transaction type `ar_clearing_settlement`, incoming net `N`, same company/bank/date, no statement import ID. Convert cents to an exact decimal string. Do not reuse the old gross amount conversion for SkipCash.
* Record mandatory audit history and the posted review snapshot. Require actual ledger and bank records: the existing writers' nullable no op results are errors here, not successful settlement.

No external provider call, file upload, email, or network side effect belongs in this transaction. Any failed step rolls back the entire selected set. An exact post retry checks the saved client UUID and fingerprint before returning the same settlements; a changed request returns 409. The UUID is operation identity, never payout identity. Persist it with the import's post result so a multiple payout response is recoverable without assigning one unique legacy header UUID to several headers.

### Bank matching and void

After posting, use the existing banking reconciliation page and service to match the net book entry. If the statement line was already reviewed, expose a direct link and suggested pair. Check settlement evidence reservation separately from reconciliation pairing:

* A deposit with no settlement evidence reservation can be matched under the normal validated rules. A deposit reserved by `evidence_bank_transaction_id` can be matched only to the book transaction whose source is that same active `ArClearingSettlement`. When the settlement names a reserved deposit, its book transaction must match that deposit, not a different one with the same amount.
* Both lines must have no reconciliation pair or already be paired to each other. A reservation belonging to the same settlement is allowed on the first match. A different reservation owner or an existing pair elsewhere is rejected; neither is silently replaced.
* Both lines must be nonvoided and agree on company, bank, direction and net amount, with existing banking permission, date locks and reconciliation state checks. Apply these guards to manual and automatic matching whenever either the book source or the statement reservation belongs to SkipCash, including attempts to pair its reserved deposit with an unrelated book entry.

Matching changes reconciliation evidence only. Unmatching removes the reconciliation pair but retains the settlement evidence reservation and its original audit trail.

Dispatch SkipCash void through `ArClearingSettlementService`, including calls from the existing clearing detail screen. Require the dedicated void permission and reason. If bank reconciliation is closed, the authorized banking workflow must reopen it first. If matched, it must unmatch the pair first. Recheck those conditions under locks before voiding; do not leave a statement pointing at a void book entry.

Retain the void event's Qatar date once and enforce the existing period/finance gates. Reverse the exact original subledger entry and account snapshots, mark the original bank book entry void, release the active provider claims and payment clearing markers, and append audit history in one transaction. Existing payment item and fee evidence remains unchanged. A repeated void cannot repeat the reversal; preserve the existing service's already voided error behavior.

After void, derive `correction_required` from the voided SkipCash settlement, its retained payment items and adjustments, and its source/payout identity. Show the payout and linked released receipts as **Voided, correction required**, with the original settlement link and no ordinary Post action. A null `clearing_settled_at` or active claim alone never makes those receipts eligible again. Ordinary matching/review/post selection checks the retained identities and payment history and rejects reuse with `SETTLEMENT_CORRECTION_REQUIRED`, HTTP 409, including under another import, row reference or client UUID. The statement evidence reservation also remains historical and cannot authorize matching to the now void book entry.

The released receipt gross remains part of the outstanding clearing balance, shown in a separate correction required subtotal rather than hidden or included among ordinary selectable receipts. This is a derived finance display and eligibility rule; it creates no new payment state, ledger entry, customer credit, or replacement settlement.

This slice does not add a general replacement settlement editor. An ordinary reupload or new UUID cannot post a voided payout again. A corrected or overlapping payout remains an explicit finance correction case under 0001, with preserved original references, not an automatic import retry. Any future replacement posting action must be designed to retain those economic identities rather than weaken uniqueness.

### API and RMS surface

Use existing backoffice session or Sanctum authentication, active user checks, accounting context, and server policies. JSON errors contain a stable code and actionable field errors, never provider payloads. Company and bank selection cannot override server ownership.

| Endpoint or action | Method and inputs | Output | Permission and key failures |
|---|---|---|---|
| `/api/accounting/gateway-settlement-imports` | GET, page/status/date filters | Paginated import summaries and distinct totals | `gateway_settlements.review`; 403 denied, 422 invalid filter |
| Same path | POST multipart, source ID and XLSX | 201 staged import, revision, totals and row errors | `gateway_settlements.import`; 409 duplicate with authorized existing reference, 422 unsafe/invalid file, 503 private storage failure |
| `/api/accounting/gateway-settlement-imports/{import}` | GET, row page and state filter | File totals separately from combined payout summaries/fingerprints, contributing import references, paginated evidence/matches, correction required and posted links | Review; 403 denied, 404 outside visible scope; filters never reduce payout membership |
| `/api/accounting/gateway-settlement-imports/{import}/file` | GET | Authorized private attachment | Review; 404 unavailable, never a public storage URL |
| `/api/accounting/gateway-settlement-imports/{import}/rows/{row}/match` | PUT, revision, provider transaction ID, optional retained evidence reference, reason for change | Updated match and invalidated batch review | Review; 409 stale review or claim conflict, 422 missing proof or mismatch |
| `/api/accounting/gateway-settlement-imports/{import}/evidence` | POST multipart, payout reference, purpose and private provider/bank evidence file | Evidence reference scoped to import/payout | Review; 422 invalid file, 503 storage failure |
| `/api/accounting/gateway-settlement-imports/{import}/review` | PUT, revision, payout reference/fingerprint, statement transaction ID or retained evidence reference, extracted remittance values when needed | Reviewed fingerprint for the combined payout, totals, account and evidenced bank/date snapshot | Review; 409 `REVIEW_STALE`, `PAYOUT_REVIEW_REQUIRED` or `SETTLEMENT_CORRECTION_REQUIRED`; 422 missing or inconsistent evidence/configuration |
| `/api/accounting/gateway-settlement-imports/{import}/post` | POST, client UUID, reviewed payout references/fingerprints | 201 settlements with gross/fees/net/date/book IDs; 200 exact replay | `gateway_settlements.post`; 409 stale/incomplete combined payout, conflict/not reviewed or `SETTLEMENT_CORRECTION_REQUIRED`; 422 mismatch, 423 locked date, 503 unavailable accounting prerequisite |
| Existing clearing detail `voidSettlement` | POST Livewire action, reason | Voided settlement and reversal reference | `gateway_settlements.void` for SkipCash; 409 bank reconciliation still linked, 422 already voided/invalid, 423 locked date |
| Existing banking match/unmatch | Existing contract, statement/book/run IDs | Existing reconciliation result | Existing banking permission plus company/branch access; same settlement evidence reservation allowed for its own match, other reservations/pairs and wrong/voided/closed pairs denied |

A reviewer clearing a draft match sends a null transaction ID and reason through the match action; it returns the row to `unmatched` and invalidates review. Uploaded evidence accepts private PDF, PNG, JPEG, or XLSX up to 10 MiB with content validation. No OCR, AI extraction, remote URL fetch, or script execution is included. Provider reference proof records the report row reference and the exact provider field/value it supports; the match action validates that value against the selected verified transaction. Bank remittance proof records the amount/date/reference shown in the retained evidence; the review action validates them against the payout and bank. Evidence download uses an authorized import evidence action with the same access and attachment rules as the original file.

Add SkipCash navigation within the current AR clearing area. Use current Volt/Flux layouts, not a separate dashboard design. Show pending collections, imports, and settlement history; review shows gross, commission, settlement fee, net, expected bank deposit, date source, row counts and blocking reasons. Offer automatic reference matches for review, candidate search, evidence upload, review, then Post. Disable repeated submissions and preserve the draft on errors.

Unsettled SkipCash reporting uses full posted receipt gross and historical settlement/void evidence, not unallocated customer balance. Preserve card and cheque response keys and their consumers. Add `skipcash_unsettled_gross_cents` for total outstanding gross, split into `skipcash_pending_gross_cents` and `skipcash_correction_required_gross_cents` without counting a receipt twice. Pending does not itself mean ready to post; all report and evidence checks still apply. Derive the correction subtotal and eligibility from the retained voided settlement payment history, not only cleared markers. Do not opportunistically change existing report units. Show bank reconciliation separately from clearing posted status. Use pagination of 50 rows, a maximum page size of 100, scoped totals, keyboard labels, visible errors, dark mode and controlled table scrolling at narrow widths.

### Value sourcing

| Action | Produced values | Authoritative source |
|---|---|---|
| Stage | Hash, sheet/row, original values, file totals | Protected upload, safe reader physical coordinates and exact numeric strings |
| Normalize | Currency, timezone, merchant, branch | Versioned source report configuration plus approved branch mapping, snapshotted on import |
| Suggest and match | Candidate receipt, verified identity, current customer | Source scoped provider transaction, posted payment, retained mapping/manual provider evidence, 0002 merge ownership |
| Group and total | Payout key, all contributing imports, payout fingerprint, duplicate identities, gross/commission/fee/net | All persisted rows for source plus payout reference across imports, their revisions and evidence, canonical row tuples and the exact sum rules above |
| Review | Bank/account IDs, expected deposit, settlement date, evidence/reviewer | Finance default bank, original receipt clearing snapshots, company expense mappings, selected bank line or retained remittance and authenticated reviewer |
| Post | Amounts, dates, financial links, replay result | Locked reviewed fingerprint and rows, canonical AR/subledger/bank writers, stored operation UUID/result |
| Reconcile | Reservation owner, matched state and pair eligibility | Settlement `evidence_bank_transaction_id`, book source settlement ID and existing scoped reconciliation pair records, not the checkout status |
| Void | Reversal accounts/amounts, date, released claims | Original posted settlement/subledger entry and retained authorized void event |
| Show history | Customer name, pending/correction/total outstanding gross, fees, net and bank state | Current authorized receipt customer relation, immutable settlement payment items and void evidence, source/payout and fee identities, and scoped reconciliation history |

### Security, configuration, and operations

The Administrator initially receives all four gateway settlement permissions. No second person approval is required. Fine grained permissions remain server enforced for future staff. Do not accept a public token, customer token, or generic accounting write permission as a substitute. A nonadmin reviewer needs access to every mapped branch in a payout; hiding an unauthorized row while allowing a reduced total to post is forbidden. Imports containing unknown branches are visible only to an authorized company administrator until resolved.

Store report and remittance files on an explicitly private configured disk with random object keys. Download rechecks access and uses attachment headers. Preserve normalized financial evidence and private originals for the accounting retention policy; the 90 day webhook purge does not apply to settlement reports. No new automatic deletion period is introduced. Logs carry IDs, counts, error codes, actors and totals, not full rows, phones or file contents. Audit import, manual match, review, evidence replacement, post, void and file access.

Use `config/skipcash.php` source keyed report profiles for merchant identity, branch mapping, verified identifier mapping and evidence reference, parser version, currency/timezone, private disk and upload/row limits. Use existing company ledger mappings `skipcash_commission_expense` and `skipcash_settlement_fee_expense`. No provider key is added to the dashboard or workbook. Add a disabled by default `SKIPCASH_SETTLEMENTS_ENABLED` mutation gate, independent of new checkout enablement. History remains readable; authorized void remains available under its separate controls.

No new scheduled posting or provider payout polling is included. Existing application logging and accounting audit are enough for this slice. Expose unmatched rows and stale review reasons in RMS; payment consistency and broader alerts remain in their already scoped operations feature. Nothing in an imported document is executable instruction.

### Critical test scenarios

* Synthetic version of the supplied sale and fee through upload, verified matching, review, posting, and bank match, verifies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-5**, **AC-7**, and **AC-10**.
* Reupload, reexport, combined payout review across imports, new evidence invalidating an earlier review, repeated post, concurrent claims and failed transaction rollback, verifies **AC-1**, **AC-4**, **AC-5**, **AC-6**, and **AC-9**.
* Missing identifier/date, ambiguous phone, wrong source/branch/bank, closed period, and unavailable ledger writer, verifies **AC-3**, **AC-4**, **AC-5**, and **AC-9**.
* First bank match with its own settlement evidence reservation, denial of another settlement's reservation, and matched or closed reconciliation followed by authorized unmatch and void. Released gross remains in the correction required subtotal and cannot be selected for ordinary repost, with original customer balances unchanged, verifies **AC-6**, **AC-7**, **AC-8**, and **AC-10**.
* Member advance before any meal invoice, later allocations, discounted membership, retained credit, customer merge, and unchanged card/cheque paths, verifies **AC-3**, **AC-5**, **AC-8**, **AC-10**, and **AC-11**.
* Unsafe workbook, exact decimal/reference preservation, private file access, role denial, and disabled mutations, verifies **AC-1**, **AC-2**, **AC-9**, and **AC-11**.

Detailed executable evidence is planned in [verify.md](verify.md). None of these application tests has run for this documentation change.

## Build plan

Use the repository Tracer Bullet approach. The first path includes a real import shape, one verified receipt, one fee, one posting, one bank match, and denial tests before adding broader report handling. Keep new mutations disabled outside the controlled test environment until all gates pass.

1. Add only the approved import/row fields, nullable canonical settlement extensions, adjustment identities, provider claim pointer and permissions needed by one payout. Add opt in exact reader output and migration tests without changing existing reader consumers, satisfies **AC-1**, **AC-2**, **AC-6**, **AC-9**, and **AC-11**.
2. Build one payout from a synthetic XLSX through matching, retained bank evidence, review page and API, explicit SkipCash AR/subledger/net bank posting, normal reconciliation and an auditable void. Prove customer receipt and allocations unchanged, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-5**, **AC-7**, **AC-8**, **AC-9**, and **AC-10**.
3. Add complete payout review across contributing imports, duplicate/conflict evidence, manual reference evidence, import/post replay, global membership review invalidation, concurrency locks and rollback cases, satisfies **AC-3**, **AC-4**, **AC-5**, **AC-6**, and **AC-9**.
4. Complete scoped history, downloads, pending and correction required clearing subtotals, separate evidence reservation and bank pair guards, retained originals, merge coverage, disabled mode, configuration validation and all card/cheque/reader regression checks, satisfies **AC-7**, **AC-8**, **AC-9**, **AC-10**, and **AC-11**.
5. Verify all criteria, confirm provider report identifier mapping with controlled evidence, reconcile one controlled collection through the full path, and document operation/rollback instructions before enabling mutations, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-5**, **AC-6**, **AC-7**, **AC-8**, **AC-9**, **AC-10**, and **AC-11**.

## Migration plan

**Strategy**: Additive, behind a feature flag.

1. Use new forward migrations. Add nullable gateway fields to existing records and create the import/adjustment records with source scoped constraints. Do not edit old migrations, relabel card receipts, or seed fake payments.
2. Deploy the SkipCash branches and source profiles with mutations disabled. Validate compatible old reader and card/cheque behavior, private storage, original receipt accounts, bank defaults and expense mappings.
3. Prove the controlled end to end path, then enable imports and posting for authorized users. Existing historical rows require no financial backfill.

**Rollback**: Disable new settlement mutations while preserving code able to read posted SkipCash records. Do not roll back away financial columns or delete imports. Correct an actual posting through the authorized void/reversal path, never by migration rollback. Checkout recovery remains available independently.

**Risks**: The existing writers silently return null in some configurations, clearing uses gross bank amounts, the reader converts numeric values, and current bank matching can replace existing pairs. This slice must explicitly guard those boundaries for SkipCash without changing unrelated behavior.

## Consequences

**Positive**:

* Customer receipts, credits, allocations and membership meals remain independent of payout fees and timing.
* You reuse familiar AR clearing and banking screens with a reviewable report trail.
* One economic payout can create only one settlement through ordinary import and posting.

**Negative and tradeoffs**:

* The report alone cannot establish the bank date or prove its row reference maps to a provider payment. Initial setup and unresolved matches need retained evidence.
* A payout cannot post until all of its rows reconcile. Another complete payout may still proceed independently.
* This release does not automate replacement of a voided payout, unsupported deductions, fee only debits, or historical receipt creation.
* Private financial evidence needs durable storage and access controls, not the short webhook retention rule.

## Follow-up

* [x] Apply the three owner approved corrections from the independent GPT-5.5 review: combined payout membership, same settlement bank evidence matching, and explicit correction required projection after void. These are document corrections, not implementation verification.
* [x] Owner confirmed the revised specification on 2026-08-31. Scope feature 3 now links this design with build milestones and verification steps. The feature remains unbuilt and all execution gates remain open.
* [ ] Before automatic matching is enabled, retain proof of the report reference to provider identifier mapping for the actual merchant. Phone similarity is not that proof.
* [ ] Verify actual default company/bank, source and expense accounts, report merchant/branch values, private storage and original receipt posting readiness in the deployment environment.
* [ ] Execute [verify.md](verify.md), including all financial, permission, migration and compatibility gates, before enabling settlement mutations.
