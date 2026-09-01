# Payment consistency decision record

## Context

The owner approved checks after committed work, every 15 minutes, and nightly at 02:00 Qatar time. Current IntegrityAudit is a console report of foreign keys, stock, menu and ledger checks. It has no durable payment findings or resumable run history. Checkout operations in 0005 cannot represent an orphan allocation or legacy subscription without inventing a checkout.

## Options considered

### Option 1: Expand console output only

This adds little schema and works for a developer running an audit manually. It cannot reliably show missed runs, retain issue episodes or give the operator a scoped source screen.

### Option 2: Small diagnostic records and domain adapters

This preserves evidence and reuses operations alerts while leaving financial writes in existing services. It requires explicit rule coverage and cursor tests. This is the selected option.

### Option 3: Automatic repair engine

This could reduce manual work for some defects, but would have to infer intent and bypass the approved separation between checks and authorized financial corrections. It is outside scope.

## Rationale

The small diagnostic model supports both checkout and noncheckout records without imposing another staff managed workflow. Eventual scans supplement transaction invariants; they do not replace them. Sequence checks must use event time availability so restoring an old credit does not retroactively invalidate a valid later booking.

## References

* [Shared payment contract](../0001-payment-accounting-contract/index.md)
* [Customer matching and merge](../0002-customer-matching-signup/index.md)
* [Payment operations and alerts](../0005-payment-operations/index.md)
* [IntegrityAudit source](../../../app/Console/Commands/IntegrityAudit.php)
* [Bootstrap scheduling](../../../bootstrap/app.php)
* [Tests safety guide](../../../tests/AGENTS.md)

No provider research or production data scan was performed for this design.

## Independent review of the final design batch

**Date**: 2026-08-31. **Reviewer**: GPT-5.5, independent of the drafting model. **Result**: Three material specification gaps were identified before implementation signoff. On 2026-09-01 the owner approved all three recommendations, and the coordinating agent applied them to the build and verification contracts. This record preserves the independent critique; it does not claim the reviewer re-reviewed the corrections.

The reviewer read the development handoff, all index, rationale and verification files for 0006 through 0010, the shared 0001 through 0005 contracts and applicable AGENTS guides. This was a read only design critique. It did not inspect referenced application source or external provider documentation, execute tests, use customer data or change files. The coordinating agent verified the cited spec passages and the existing website branch input afterward. This is not runtime verification.

### R1. Exact consistency rule inputs

**Evidence before correction**: The [rule matrix and scan contract](index.md#rule-matrix) left each adapter's precise relations, source event identities and fingerprint fields to implementation.

**Why it matters**: A booking void check could inspect invoice status and quantity but omit the actual allocation release or restoration event, then incorrectly resolve a finding. Broad rule families alone do not settle which evidence is required.

**Recommended addition**: A per rule registry naming rule_code, subject root, required parent/child records, authorized ownership resolver, exact fingerprint fields, expected and observed values, allowed deferrals and the correction evidence required for resolution. Keep it diagnostic only, with no automatic repair. Cover 0006 AC-2, AC-3, AC-4, AC-6 and AC-8, plus shared accounting restoration/idempotency criteria.

**Applied on 2026-09-01**: The [required rule registry](index.md#required-rule-registry) now fixes every enabled rule's root, record/field fingerprint, expected source, deferral and resolution evidence. The [verification contract](verify.md) fails an enabled adapter that omits any required reader or serializer.

### R2. Concrete legacy opening manifest

**Evidence before correction**: The [legacy migration phases](../0007-membership-purchase/index.md#migration-plan), [booking migration dependency](../0008-membership-booking/index.md#migration-plan) and 0001's legacy opening principles specified evidence-based opening, but not a complete manifest schema and inclusion decision table.

**Why it matters**: Existing subscriptions with partially mapped invoices and an unexplained residual could be included or excluded differently by separate implementers, changing visible allowance or inventing funding.

**Recommended addition**: A versioned protected dry run manifest with required source IDs, cutover, source fingerprints, opening used quantities, current booking mappings, actual gross/discount/net and residual values, verified/incomplete/unsupported classifications, explicit exception codes, approval fingerprint and apply replay checks. Only verified entries can be applied; excluded records preserve their existing access and do not force a new purchase. Cover 0007 AC-8, 0008 AC-1/AC-9 and 0006 AC-2/AC-6.

**Applied on 2026-09-01**: The [legacy opening manifest and classification](../0007-membership-purchase/index.md#legacy-opening-manifest-and-classification) now defines the protected header and row data, four classifications, exact inclusion math, exception allowlist, review/apply state, replay behavior and evidenced later void restoration. The 0001, 0006, 0007 and 0008 verification contracts cover it.

### R3. Selected branch membership projection

**Evidence before correction**: [Queue scope](../0007-membership-purchase/index.md#additive-data-model) and the [booking API](../0008-membership-booking/index.md#api-contract) prohibited spending across incompatible branches but described the read input only as Branch context.

**Why it matters**: A customer opening the website in a different branch context must neither lose sight of authorized existing history nor see that branch's allowance as spendable here.

**Recommended addition**: Retain the existing website order branch, validated by RMS, with no new customer branch or membership selector. Return spendable balance only for that compatible scope. Show other owned history only where existing authorization permits it, separately as unavailable for this website branch with the configured contact. Never include it in bookable totals. Name the response fields and wrong context rejection/recovery behavior; do not weaken company or branch read permissions. Current source evidence in assets/js/orders-core.js at the customer website supplies branch_id 1, so the plan must not invent a browser branch picker. Cover 0007 AC-9/AC-10, 0008 AC-1/AC-8/AC-9 and 0002 AC-10.

**Applied on 2026-09-01**: The [purchase API and website contract](../0007-membership-purchase/index.md#api-and-website-contract) and [covered booking API contract](../0008-membership-booking/index.md#api-contract) now define the selected branch source, spendable queue projection, existing-history eligibility label, 404/409/422 behavior, branch-scoped drafts and explicit new-purchase recovery without automatic payment.

### Disposition

All three findings were confirmed as technical specification completeness work, approved by the owner and applied on 2026-09-01. No approved refund, tax, expiry, meal quantity, delivery, zero request, invoice void or promo editing rule was reopened. The coordinating self check found all 12 registry rules, all 44 final batch acceptance criteria in both build and verification contracts, and no broken local documentation links or whitespace errors across the 32 plan document set. The accepted document set contains 130 valid local links. There are no open independent review findings or new owner business decisions. The owner accepted the corrected final design on 2026-09-01. Implementation remains pending, and the corrections have not been independently reviewed again.
