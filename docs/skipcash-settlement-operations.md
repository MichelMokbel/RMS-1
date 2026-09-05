# SkipCash settlement operations

This runbook covers the first controlled SkipCash payout reconciliation and later routine operation. It does not change customer payments, invoice allocations, membership balances, or revenue recognition. A customer payment remains recorded at its full gross amount. The settlement records the provider deductions and moves the net amount from the SkipCash clearing account to the default bank.

## Safety boundary

Keep `SKIPCASH_SETTLEMENTS_ENABLED=false` until every readiness item below is confirmed. This flag controls new report imports, evidence changes, matches, reviews, and posting. It does not disable payment checkout or recovery, and it does not hide existing settlement history or the authorized void action.

Use the development environment for the first controlled run. Do not upload the historical customer workbook to tests or commit it to git. Use a provider generated sandbox report containing a known sandbox payment.

## Required deployment configuration

Configure these values for the environment that owns the payments:

```dotenv
SKIPCASH_SETTLEMENTS_ENABLED=false
SKIPCASH_SETTLEMENT_DISK=local
SKIPCASH_SETTLEMENT_MAX_UPLOAD_KB=10240
SKIPCASH_SETTLEMENT_MAX_ROWS=10000
SKIPCASH_REPORT_MERCHANT="Exact merchant value from the report"
SKIPCASH_REPORT_BRANCH_MAP='{"Exact provider branch value":1}'
SKIPCASH_REPORT_IDENTIFIER_FIELD=referenceNumber
SKIPCASH_PROVIDER_IDENTIFIER_FIELD=visaId
SKIPCASH_IDENTIFIER_MAPPING_EVIDENCE="Internal reference to retained mapping proof"
```

The private disk must not be publicly served. The merchant and provider branch values must come from the report. The RMS branch ID must be checked in the target database. Do not copy an example branch ID without checking ownership.

`SKIPCASH_IDENTIFIER_MAPPING_EVIDENCE` is a nonsecret reference to retained controlled evidence showing that the report `referenceNumber` for the same transaction equals the SkipCash `visaId`. Do not populate it from field shape or documentation alone. Until the proof exists, leave the three identifier mapping values blank and use the review page's retained provider evidence flow for any controlled manual match.

After changing deployment configuration, clear the Laravel configuration cache and restart the web, queue, and scheduler containers so every process uses the same values.

## Readiness checklist

Confirm all of the following before setting the mutation flag to `true`:

1. The default accounting company is active and uses QAR.
2. The report merchant belongs to that company.
3. Every provider branch value in the report has an explicit mapping to an active RMS branch owned by that company.
4. The active `skipcash` payment source belongs to that company, uses method `skipcash`, and points to an active clearing ledger account owned by the same company.
5. The default bank account is active, QAR denominated, and owned by the same company.
6. The `skipcash_commission_expense` and `skipcash_settlement_fee_expense` mappings both resolve to active expense accounts owned by the same company.
7. The settlement disk can write, read, and delete a private test object.
8. The administrator has `gateway_settlements.import`, `gateway_settlements.review`, `gateway_settlements.post`, and `gateway_settlements.void`.
9. The known sandbox payment has a verified provider transaction, a posted RMS payment with method `skipcash`, and its original clearing entry. Its gross amount, company, branch, and currency agree.
10. A provider generated sandbox report and either the imported bank deposit or retained remittance evidence show the same payout and exact net amount.
11. The relevant accounting period is open and the finance lock date allows the evidenced bank settlement date.

If any item fails, keep settlement mutations disabled. Do not create a replacement customer payment or adjust the customer's invoice to force a match.

## Controlled reconciliation

1. Retain the provider generated sandbox report and the SkipCash transaction details for one known sandbox payment. Confirm that the report's sale `referenceNumber` equals the details API `visaId`. Record the evidence reference in deployment configuration.
2. Set `SKIPCASH_SETTLEMENTS_ENABLED=true` in development only, refresh configuration, and restart the RMS processes.
3. Open **Accounting > AR Clearing > SkipCash**. Select the SkipCash source and upload the XLSX report. Staging must show the original report totals and must not create a settlement or another customer payment.
4. Open the staged report. Confirm the sale gross, sale commission, separate settlement fee, and payout net. A fee row is not a customer payment.
5. Match the sale to the existing verified RMS payment. The match must use the retained provider identity. Phone, amount, and date are suggestions only.
6. Select the imported default bank deposit for the exact payout net. If the bank statement is not imported yet, retain the provider remittance with its exact net amount and bank date.
7. Review the payout. Resolve every blocking row before continuing.
8. Confirm the displayed gross, deductions, and net, then post the settlement once.
9. Verify the result:
   * the settlement gross equals the original customer payment;
   * commission and settlement fee are separate expenses;
   * the bank book transaction equals the net payout;
   * the entry debits bank plus the two expenses and credits the original SkipCash clearing account for the gross;
   * debits equal credits;
   * the customer payment, allocations, invoice status, order, and membership balance did not change;
   * replaying the same posting request returns the existing settlement and creates nothing new.
10. In bank reconciliation, match the settlement's net bank book transaction to the selected statement deposit. Confirm that matching creates no payment, invoice, revenue entry, or membership change.
11. Download or record the settlement, source report, mapping proof, and bank evidence references for the operational record.

Only after this controlled run passes may settlement mutations remain enabled in that environment. Production requires its own readiness review and controlled first payout. A successful development run is not production authorization.

## Routine operation

For each provider payout, import the report once, resolve every row in the payout, select the exact bank evidence, review, post, and then reconcile the net bank line. Reexported or overlapping reports remain visible but must not create duplicate commissions, fees, payment claims, or bank inflows.

If a sale has no eligible RMS payment, use the existing payment recovery flow first. Do not create a manual receipt from the report. If the identifier mapping is unavailable, retain row specific provider proof and perform the explicit reviewed match.

## Stop and rollback

For an import, matching, configuration, or provider evidence problem before posting:

1. Set `SKIPCASH_SETTLEMENTS_ENABLED=false`.
2. Refresh configuration and restart RMS processes.
3. Preserve the staged report and evidence for review. Do not delete or rewrite financial history.

For a posted settlement that must be corrected:

1. Complete any required authorized bank reconciliation reopen or unmatch action.
2. Open the settlement and use the authorized void action with a reason.
3. Confirm that the reversal voided the settlement bank book transaction and released the payment clearing marker and active provider claim.
4. Confirm that the original customer payment, invoice, allocations, order, membership, report, fee evidence, and audit history remain unchanged.
5. Correct the source evidence or configuration, then use the normal reviewed correction flow. Never delete the settlement, edit posted amounts, roll back financial migrations, or reuse the old posting as a new customer receipt.

Checkout recovery is independent. Disabling settlement mutations must never be used to disable or discard a valid captured customer payment.

## References

* [SkipCash settlement specification](specs/0004-skipcash-settlement/index.md)
* [SkipCash settlement verification plan](specs/0004-skipcash-settlement/verify.md)
* [Payment accounting contract](specs/0001-payment-accounting-contract/index.md)
* [SkipCash paid order specification](specs/0003-skipcash-paid-order/index.md)
* [AR service guide](../app/Services/AR/AGENTS.md)
* [Accounting service guide](../app/Services/Accounting/AGENTS.md)
* [Banking service guide](../app/Services/Banking/AGENTS.md)
* [Ledger service guide](../app/Services/Ledger/AGENTS.md)
