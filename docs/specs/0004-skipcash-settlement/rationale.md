# Rationale for SkipCash settlement and fee clearing

## Context

RMS already has immutable AR clearing settlement headers and payment items, period gated posting and void, source keyed subledger entries, bank book transactions, and bank statement reconciliation. The current settlement service accepts only card and cheque and assumes the whole receipt reaches the bank. The SkipCash report includes commission and a separate settlement fee, so that assumption does not hold for this source. (basis: `ArClearingSettlementService`, `ArClearingSettlement`, `SubledgerService`, and `BankTransactionService`)

The owner already confirmed method `skipcash`, default company ownership, the default receiving bank, no tax, no customer refunds, separate expenses, protected report staging, and evidence based matching. The approved shared model already extends AR settlement rather than introducing another ledger. This slice supplies its implementation detail; it does not reopen those business rules. Membership receipt allocation and meal consumption must remain independent of bank payout timing. (basis: 0001 and scope feature 3)

The supplied report has customer phone evidence, but no explicit currency, timezone, bank settlement date, or declaration that its row reference equals a particular API identifier. Its actual two rows support the commission calculation below, not an invented provider reference mapping. Financial evidence contains personal data and needs private storage and audit access. This design makes no new legal retention or tax assertion. (basis: the supplied workbook, `AGENTS.md`, and the banking and ledger guides)

## Options considered

### Option 1: Extend existing AR clearing with a SkipCash import adapter

Keep existing financial records and posting services, adding a staged report workflow and explicit net/fee support for SkipCash. (basis: 0001 and the existing AR service boundary)

**Pros**: Preserves current finance history, payment void protection, bank source identity, and familiar screens. Matches the already approved model.

**Cons**: Shared writer branches need careful regression coverage. Existing nullable posting behavior and permissive bank matching cannot simply be copied into the new path.

### Option 2: Add a separate gateway settlement ledger alongside AR

Create independent gateway settlement headers and bank/ledger posting adapters, leaving card and cheque untouched. This is an incremental replacement boundary rather than an immediate rewrite. (basis: incremental migration and source isolation)

**Pros**: Isolates provider report logic and could become useful for several providers with different payout models.

**Cons**: Duplicates settlement history, payment clearing ownership and correction controls. It would change the confirmed model without evidence that the additional boundary is needed.

### Option 3: Replace all AR clearing with a general settlement engine

Migrate card, cheque and SkipCash into a new common settlement model and administration flow. (basis: a general financial source model)

**Pros**: Could standardize fees and bank matching across every source.

**Cons**: Requires historical migration and a larger compatibility surface. It expands a single provider integration into a redesign of working finance flows.

## Rationale

Option 1 is the recommendation. Net bank amounts and fee lines are a bounded extension to the existing settlement owner. The report staging adapter can stay separate from canonical financial writers without creating a second accounting ledger. Option 2 is the runner up if provider diversity later creates a real boundary, but it is unnecessary for this release. Option 3 has no demonstrated operational benefit proportional to its migration risk.

The implementation recommendations below settle internal details while preserving the owner's approved rules.

| Detail | Recommendation and reason | Runner up and tradeoff |
|---|---|---|
| Workbook reader | Reuse `App\Support\Imports\SafeSpreadsheetReader` with opt in exact strings and physical row metadata. Its existing archive and XML checks are useful, and finance cannot use its current numeric float conversion | A new spreadsheet package adds dependency and security maintenance for a fixed report format |
| Import execution | Bounded synchronous staging, 10 MiB and 10,000 data rows, with existing archive guards. This avoids another job lifecycle for the current manual workflow | Queue parsing if measured files exceed practical response budgets; it requires durable job states and recovery |
| Review surface | A SkipCash section in existing AR clearing, with a combined payout across contributing imports, scoped evidence and an explicit Post action | A separate dashboard increases navigation without a new user role or job |
| Match authority | Verified provider reference or retained provider evidence. Phone/amount/date narrow candidates only | Amount and phone automatic matching risks attaching a real collection to the wrong receipt |
| Bank evidence | Existing statement deposit or retained remittance identifies the payout, amount and date. A reserved deposit remains matchable to its own settlement's book entry | Freely entered dates cannot prove the accounting period; treating a reservation as an existing reconciliation pair would prevent the intended bank match |
| Account selection | Credit the account actually debited by each receipt, retaining that breakdown on settlement | Today's source mapping can leave the old clearing balance stranded |
| Posting | Canonical AR settlement, one source keyed subledger event and one net bank book entry, in one transaction | Independent journal and bank writes make partial completion possible |
| Void | Reuse existing void and reversal, require bank unmatch/reopen through current controls first, and keep economic identities reserved | Automatically reimporting a voided payout can resurrect money the operator deliberately reversed |
| Replacement after void | No general replacement editor in this slice. Preserve the shared contract's explicit finance correction boundary | A new replacement action would need its own revision/claim rules and must not be smuggled in by relaxing duplicate constraints |

(basis: the existing reader, AR, banking, finance and ledger code; 0001; atomic financial posting and immutable evidence)

## Supporting evidence

### Supplied workbook inspected on 2026-08-31

Source: `/Users/mohamadsafar/Downloads/Layla Kitchen-Z56ST980BL238.xlsx`, `Sheet1!A1:W3`. Inspected without modifying the workbook. Customer phone and raw reference values are deliberately not copied into these documents or fixtures.

| Evidence | Sale, row 2 | Settlement fee, row 3 |
|---|---|---|
| `period`, column A | 09/08/2026 through 11/08/2026 | Same period |
| `transactionDate`, column D | 11-Aug-2026 | 13-Aug-2026 |
| `orderType`, column F | Sale | Settlement Fee |
| `status`, column I | Successful | Successful |
| `sales` and `grossAmount`, columns N and O | 4,270.00 | 0.00 |
| `variableCommission`, column Q | 98.21 | 0.00 |
| `fixedCommission`, column R | 1.00 | 6.00 |
| `totalCommission`, column S | 99.21 | 6.00 |
| `netMerchantSettlementAmount`, column T | 4,170.79 | Negative 6.00 |

Both rows share `paymentRef`; their `referenceNumber` values differ. `orderId` is blank. The fee row's customer phone is the literal string `Null`. The sheet contains no bank date column. These are observations of this sample, not a claim that every future report has the same layout or that the sample's identifiers prove an API mapping.

Under the already agreed QAR merchant configuration, the supported calculation is:

```text
Gross customer collections      427000 cents
Sale commission                  9921 cents
Settlement fee                    600 cents
Net payout                     416479 cents

417079 + (-600) = 416479
427000 - 9921 - 600 = 416479
```

The resulting settlement entry would debit bank QAR 4,164.79, debit commission expense QAR 99.21, debit settlement fee expense QAR 6.00, and credit SkipCash clearing QAR 4,270.00. This is an arithmetic illustration, not a posting or proof that this historical transaction exists in RMS. The receipt stays QAR 4,270.00; the settlement records no new receipt or revenue.

### Current code boundaries

| Source inspected | Existing behavior | Required SkipCash treatment |
|---|---|---|
| `ArClearingSettlementService::settle` | Validates card/cheque, sums full receipt gross, creates immutable header/items, marks receipts and calls ledger/bank/audit | Separate reviewed SkipCash entry, source/company/branch checks, fees/net and original clearing snapshots, mandatory writer results |
| `ArClearingSettlementService::void` | Reverses original entry, clears payment markers, voids bank book record and audits | Add source/claim and bank reconciliation guards without changing customer financial records |
| `ArClearingSettlement` | Only void metadata can be updated; deletion forbidden | All posted gateway values and evidence written at creation and kept immutable |
| `SubledgerService::recordArClearingSettlement` | Two lines, bank gross and card/cheque clearing; can return null | Explicit SkipCash gross clearing/net bank/separate fees, with a required real entry |
| `BankTransactionService::recordArClearingSettlement` | Source keyed book inflow, currently gross | Keep source identity, use exact net, no direct receipt bank posting |
| `BankTransactionService::voidArClearingSettlement` | Marks book record void without clearing reconciliation links | Require prior authorized unmatch/reopen and locked validation for SkipCash |
| `BankReconciliationService::match` | Checks bank/amount/direction, can clear prior pairs | Prevent a SkipCash match from stealing a claimed statement or book entry |
| `BankStatementImportService` | Creates independent imported statement lines from CSV | Reuse lines as evidence; do not treat them as customer receipts |
| `SafeSpreadsheetReader` | Rejects formulas, active content, external relationships and unsafe XML/archives; converts numeric cells to int/float and compacts rows | Add opt in lexical numbers and physical row coordinates, preserve old consumers |
| `UnsettledIncomingReceiptsReportService` | Defaults to card/cheque and returns existing summary keys/units | Add explicit scoped SkipCash values without silently changing old keys or units |

These observations came from source inspection, not an application test or production configuration check.

### Discovery carried forward

| Dimension | Disposition |
|---|---|
| Requirements, no refunds/tax, customer balances | Inferred from explicit owner decisions and approved 0001; unchanged |
| Entity relationships and core fields | Reused approved 0001 settlement model; internal review, evidence and claim fields specified in this slice |
| Stack, provider, hosting, auth, UI conventions | Inferred from repository and prior design; no new library or service selected |
| Interface and authorization | Approved import/review/post surfaces refined with evidence, match and detail actions; existing banking/void controls retained |
| Failure handling | Approved duplicate, missing evidence, partial payout, closed period and rollback policies made executable; no new customer payment journey |
| Private evidence and references | Existing privacy/audit policy and owner's request for references retained |
| Public SEO, new website UX, recurring collection | Not applicable to this internal clearing slice |

### Approved review corrections, 2026-08-31

An independent GPT-5.5 design review identified three gaps in the proposed implementation detail. The owner approved applying the following document corrections, then confirmed the revised specification on 2026-08-31. This records final design approval, not executed application tests or a second independent review. The feature remains unbuilt.

| Review finding | Adopted correction | Existing criteria |
|---|---|---|
| Payout completeness was required, but review snapshots and routes appeared limited to one import | Resolve every persisted row for the source and payout across imports. Preserve original row and evidence ownership, count proven duplicates once, and show one combined payout. New rows or evidence invalidate earlier review; access checks cover every contributing branch and evidence record | AC-4, AC-6, AC-9 |
| A deposit reserved as settlement evidence could be rejected by the later rule against claimed bank lines | Distinguish the settlement evidence reservation from a reconciliation pair. Permit the reserved deposit's first match to its own active settlement book entry; reject another settlement or an existing pair elsewhere. Unmatching retains the evidence reservation | AC-7 |
| Clearing markers released by void could make the receipt appear ready for ordinary settlement even though payout and fee identities remain reserved | Derive **Voided, correction required** from retained settlement and payment history. Keep the released gross in outstanding clearing with a separate correction subtotal, and reject ordinary reuse server side even when the active markers are null | AC-6, AC-8, AC-10 |

These corrections preserve the canonical AR model, gross receipt amounts, fee arithmetic, default company and bank rules, and all customer and membership behavior. They add no replacement settlement action. The revised verification plan covers each correction before implementation can be considered verified.

## References

**Project sources**:

* [Root agent guide](../../../AGENTS.md), finance, isolation, evidence and migration rules.
* [Shared payment contract](../0001-payment-accounting-contract/index.md), the approved settlement model, identities, dates and accounting.
* [Ordinary paid checkout](../0003-skipcash-paid-order/index.md), verified receipt and original clearing account snapshots.
* `docs/scope/scope.md`, feature 3 and the owner's settlement decisions.
* `app/Services/AR/ArClearingSettlementService.php` and `app/Models/ArClearingSettlement.php`, canonical lifecycle.
* `app/Services/Ledger/SubledgerService.php`, `app/Services/Banking/BankTransactionService.php`, and `app/Services/Banking/BankReconciliationService.php`, existing money and bank writers.
* `app/Services/Banking/BankStatementImportService.php`, existing imported bank evidence.
* `app/Services/Finance/FinanceSettingsService.php`, default bank/company and finance lock behavior.
* `app/Support/Imports/SafeSpreadsheetReader.php`, the existing XLSX boundary.
* `app/Services/Reports/UnsettledIncomingReceiptsReportService.php`, existing clearing report behavior.
* `tests/Feature/AR/ArClearingSettlementTest.php`, existing settlement, retry, immutable item, void and period contracts.
* The supplied workbook and exact cells identified above, inspected using the spreadsheet skill. It informed the parser mapping and arithmetic, not a new runtime dependency.

**Practices**:

* Balanced double entry posting within one database transaction.
* Separate transport retry identity and economic event identity.
* Immutable evidence and explicit reversal rather than historical mutation.
* Incremental migration and source isolation.

No new web lookup was needed for this report and repository grounded design. The existing provider references remain in 0001; no unverified report to API identifier mapping is asserted here.
