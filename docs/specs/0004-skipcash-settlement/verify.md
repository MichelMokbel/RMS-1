# Verification plan for SkipCash settlement

## Safety and status

This is a planned verification matrix, not a record of executed application tests. The feature is not implemented. Do not run migrations, refresh a database, post money, upload the customer's workbook to a provider, or call live APIs to check this document.

Before feature tests, confirm the disposable MySQL `store_test` environment under the repository test guide. Use fake storage, synthetic customer/provider identifiers and synthetic workbooks. The user's historical workbook stays outside git and must not be used as a fixture. A final controlled collection requires the normal sandbox and deployment readiness checks from 0001 and 0003.

## Acceptance matrix

| Criterion | Required evidence |
|---|---|
| **AC-1** | Safe upload persists private original and complete staged rows with no financial side effects; blank/Null normalization; invalid input rejected or staged with blocking row reasons |
| **AC-2** | Exact lexical cents and reference preservation; component/total commission equality; fee outside period retained; gross/fees/net reconcile; no double subtraction or rate based recalculation |
| **AC-3** | Verified reference match, manual evidence match, phone suggestions only, ambiguous/forged/missing reference denied, posted receipt required, source/company/branch/currency/gross checks |
| **AC-4** | Complete payout review across all contributing imports, evidence based bank/date/amount, absent bank date in sample, unmatched row or conflicting evidence anywhere blocks only its payout, changed combined membership invalidates review |
| **AC-5** | Gross settlement items, net bank book transaction, expense adjustments, original clearing account credits, mandatory balanced subledger and audit, atomic rollback, finance/period gates |
| **AC-6** | Identical file, reordered rows, changed reference, same identity changed contents, overlapping export counted once across imports, competing imports/posts, exact response recovery, changed UUID payload rejected and voided payment history prevents ordinary reuse |
| **AC-7** | Net bank statement pair, own settlement evidence reservation permits first match, other reservation owners or pairs elsewhere rejected, no extra receipt/revenue, wrong/voided bank pair rejected, closed reconciliation controls and unmatch retains reservation |
| **AC-8** | Authorized void uses original accounts/amounts, bank pair prerequisites, one reversal, active claims/markers released, correction required display and eligibility derive from retained history, no customer invoice/payment/allocation/meal changes |
| **AC-9** | Allowed Administrator; denied customer/ordinary staff; per action permissions; wrong company and unauthorized branch reads/writes/downloads/totals denied across every contributing import; no protected evidence or partial payout exposed; no second approver required |
| **AC-10** | Working import/review/post/history UI, combined payout distinct from file totals, pending and correction required gross sum to total outstanding without duplication, bank reconciliation shown separately, explicit cent units, old card/cheque contracts preserved, responsive and keyboard states |
| **AC-11** | Private files, safe reader limits, sanitized logs, immutable merge evidence, no customer PII in fixtures, disabled mutations with history and payment recovery preserved |

## Thin path accounting proof

Create one synthetic verified, posted SkipCash receipt of 427000 cents with a retained clearing debit. Generate a two row synthetic report with distinct row references and a shared payout reference. Use the supplied report's field names, but not its original identifiers, merchant details or customer phone.

| Component | Cents |
|---|---:|
| Sale gross | 427000 |
| Variable commission | 9821 |
| Fixed commission | 100 |
| Sale total commission | 9921 |
| Sale net | 417079 |
| Separate settlement fee | 600 |
| Separate fee net | -600 |
| Payout net | 416479 |

Retain a synthetic default bank deposit or remittance for 416479 cents with an explicit bank date different from both sale and upload date. Assert after posting:

* One `ar_clearing_settlements` header, method `skipcash`, gross 427000, commission 9921, settlement fee 600, net 416479 and evidenced date.
* One gross receipt item and two expense adjustments. The fee row's fixed commission is not also posted as sale commission.
* One balanced `ar_clearing_settlement` / `settle` source event: debit bank 416479, debit commission 9921, debit fee 600, credit original clearing 427000.
* One incoming bank book transaction for QAR 4164.79 with no statement import ID, plus the separate imported statement line if used.
* The receipt remains 427000, with the same customer, received date, method, source, invoice allocations, paid status, and customer balance. No new payment or revenue event appears.
* When the statement was retained as evidence, its reservation permits the first match to this settlement's own book transaction. Matching changes only bank reconciliation. A replay returns the same settlement and creates no new financial records.

This proves **AC-1** through **AC-7** and the ordinary part of **AC-10**. It does not establish a real merchant identifier mapping.

## Required regression cases

| Case | Assertions |
|---|---|
| Report shapes | Reordered columns, missing/duplicate headers, extra populated sheet, empty report, missing `orderId`, literal `Null`, fee date outside period, missing bank date and unknown row/status all have explicit outcomes |
| Safe reader | Formulas even with cached values, macros, external relationships, unsafe ZIP paths, XML entities, malformed archives, excessive expansion/cells/rows and oversize upload cannot enter staging |
| Exact values | Leading zero and large text identifiers survive. Scientific or high precision numeric values cannot silently round. Invalid money strings, negative commission, inconsistent gross/net, and overflow block posting. Existing HR/petty cash reader output remains unchanged |
| Fee identity | Same fee reexported cannot expense twice. Distinct verified fee references with equal amounts remain distinct. Missing reliable reference cannot use row number or equal amount as identity |
| Match ownership | Same phone and amount for two receipts only suggests candidates. Wrong source, merchant, company, mapped branch, currency, gross, voided receipt, or missing receipt posting cannot confirm a match |
| Membership and credit | A 90000 cent membership receipt can settle before any meals are selected or invoiced. Later daily allocation changes neither gross settled amount nor fees. A partially discounted receipt settles its actual paid amount and leaves meal allowance unchanged. Admin credit allocation and 100 percent requests create no settlement rows |
| Clearing mapping change | Change the configured source account after receipt, then settle. Credit the original receipt account. A batch spanning two original clearing accounts credits each for its own receipts while debiting one net bank deposit |
| Bank defaults | Missing/inactive/wrong company bank, changed default after review, missing expense mapping and unavailable original clearing account block without fallback. A later default company does not transfer source ownership |
| Date evidence | Sale date, fee date and upload date cannot replace bank date. Missing/conflicting remittance date, wrong amount/reference, reused bank deposit, and attempted date override fail. Closed period and finance lock fail before any financial effect |
| Partial payout | Sales and fees split across imports resolve to the same complete payout from either entry import. An unmatched sale or fee in any contributing import prevents that payout posting. Another complete independent payout can post. Existing posted rows cannot be omitted from a revised payout to turn its remainder into a second deposit |
| Replay and rollback | Lost upload/post response resolves existing result. Same UUID with changed references/fingerprint fails. New rows or evidence in another contributing import after review return `REVIEW_STALE` without financial writes and require a new combined review. Failure after header/items/adjustments/ledger/bank/audit rolls back all selected payouts and claims |
| Concurrency | Two reviewers invalidating a revision, staging another import for one payout, two postings claiming one transaction, and simultaneous void/post use the same source lock and database constraints. Membership is resolved under that lock; newly committed rows cannot be missed. No duplicate fee or bank inflow survives |
| Bank matching | Same bank, company, net and direction required. A deposit reserved for this settlement permits its first own book match; another reservation owner, a pair elsewhere or a voided line is rejected. Manual and automatic matching cannot give the reserved deposit to an unrelated book entry. Repeating the same legitimate match is harmless; unmatch retains its evidence reservation. Reconciliation itself adds no financial event |
| Void | Matched or closed bank run blocks void until normal authorized reopen/unmatch. Void reverses original entry once, marks bank book void, releases active claims and payment markers, retains adjustments and evidence. Show correction required with the original settlement link, not an ordinary Post action. Retained payment history rejects reuse with `SETTLEMENT_CORRECTION_REQUIRED` even under another import, reference or UUID. Released gross stays in total outstanding and its separate correction subtotal. No invoice, allocation, customer credit or meal changes |
| Authorization | Import only cannot review/post. Review only cannot post/void. Generic accounting access alone is insufficient. Initial Administrator can complete all actions. Customer portal and other company actors cannot read candidates, totals, files or mutate records |
| Branch scope | Mixed authorized/unauthorized branch payout across imports cannot be reduced to visible rows and reviewed or posted. Protected contributing evidence and file contents remain unavailable; a raw file requires access to every row in that file. An unknown provider branch remains an admin review item, never an assumed RMS branch ID |
| Merge | Customer merge changes current display ownership through canonical relations. It never changes report phone snapshots, economic IDs, provider IDs, source accounts, receipt amounts, or settled claims |
| Compatibility | Run existing card/cheque settle, same UUID retry, voided UUID reuse, immutable header/item and period tests unchanged. Test SkipCash's separate post/void guards. Existing report summary keys and units remain stable |
| Disabled mode | New imports/reviews/posts respect the flag. History remains readable and authorized correction controls remain available. Valid captured payment processing is not disabled with settlement |

These cases map to the matching criteria in the acceptance matrix; the full build cannot be called verified from the thin path alone.

### Independent review regression fixtures

| Fixture | Required result | Criteria |
|---|---|---|
| Import A has a sale. Import B has a proven duplicate of that sale and its settlement fee, with the same source and payout reference | Opening either import resolves one combined payout with the sale counted once and the fee included. Each row and retained evidence link keeps its original import. Review/post requires access to every contributing branch and evidence record, and neither entry can post a file only subset | **AC-4**, **AC-6**, **AC-9** |
| A combined payout was reviewed, then another contributing import commits a new fee or conflicting row | Its payout fingerprint changes. Posting with the old review returns `REVIEW_STALE` and makes no financial writes. A new review includes the fee or blocks on the conflict; it never silently omits it | **AC-4**, **AC-6** |
| A posted payout reserves its imported bank deposit as evidence, then the operator matches its own book transaction | The first match succeeds despite the reservation. A different book entry cannot take the deposit, and the settlement cannot substitute another same amount deposit. Unmatch removes only the pair, leaving the evidence reservation intact | **AC-7** |
| An authorized void clears the receipt's settled marker and provider active claim | History and receipt selection show **Voided, correction required** from retained settlement items. Released gross remains in total outstanding and the correction subtotal, not the ordinary selectable list. Another import or UUID cannot repost it, and customer balances remain unchanged | **AC-6**, **AC-8**, **AC-10** |

## Quality gates

1. Confirm safe MySQL isolation, then run focused tests for import normalization, matching, review, posting, void, bank matching and permissions.
2. Run every affected AR, Accounting, Ledger, Banking and Reports test, including `tests/Feature/AR/ArClearingSettlementTest.php`, plus shared reader consumers and the relevant 0002/0003 merge/receipt cases.
3. Prove clean forward migration and a representative existing database path with card/cheque history. No historical data mutation is required.
4. Run Pint on changed PHP, the production asset build for UI changes, and inspect registered routes and policy coverage.
5. Verify the RMS review page at approximately 360, 768 and 1024 pixels, keyboard interaction, 44 px touch targets, dark mode, loading and error preservation.
6. Review final changes for private workbooks, contact data, credentials, generated files and unrelated edits.
7. Confirm the merchant identifier mapping with retained controlled provider/report evidence, plus actual company, bank, account and storage configuration. Do not infer these from `.env.example`.
8. Complete one controlled collection through report import, posting and bank reconciliation under the approved environment. No live enablement is implied by a documentation or mocked test pass.

## Documentation verification

On 2026-08-31, all three documents were checked for required sections and local links. All 11 criteria have build task, critical scenario and verification coverage across five build tasks. Eight local links resolve. Exact integer checks confirm sale commission 9921 cents, sale net 417079 cents and payout net 416479 cents, with debits equal to gross clearing credit. Whitespace checks found no errors in the new documents or tracked diff.

These are document and arithmetic checks only. No application tests, migrations, provider requests, settlement postings, customer data changes or workbook edits ran. The independent GPT-5.5 design review is complete and its three owner approved document corrections are applied. The owner accepted the revised specification on 2026-08-31. Scope feature 3 links the approved design with build milestones and all execution gates open; application and sandbox gates above are still unexecuted.
