# 0011. Checkout and accounting

**Date**: 2026-09-08

## Summary

Add a `menu_order` purpose to the durable checkout foundation and reuse the accepted SkipCash and accounting services. Payment creates the operational and financial records only after provider verification, and every retry reaches the same result.

## Requirements

This child implements **AC-8**, **AC-9**, **AC-10**, **AC-11**, **AC-12**, **AC-18**, **AC-21**, and the payment compatibility part of **AC-20**.

## Decision

Use one existing checkout attempt and one existing payment target for a first release menu order. Add a menu specific quote and activation service, but keep provider verification, recovery, payment creation, AR issue, allocation, ledger posting, notifications, and operations visibility in their existing owners.

### Checkout identity and snapshot

Add `menu_order` to the accepted attempt purposes. Use one durable client UUID and immutable request fingerprint under the existing uniqueness boundary. Exact retries return the original attempt. A changed request with the same UUID returns 409.

The attempt retains:

* Company, portal branch, canonical customer, original portal user, payment source, clearing account, QAR currency, terms, and notification recipients.
* `menu-order-v1`, service date, item IDs, customer titles, canonical menu names, units, quantities, unit cents, line cents, description snapshots, and order note.
* Storefront settings revision, Qatar date and time, cutoff, per item lead days, closed date result, quote fingerprint, total cents, and the current canonical values used to produce the quote.
* The customer, delivery address, support contact, customer email, and administrator recipients resolved at checkout start. These retained values remain the confirmation and activation source even if a profile or setting changes later.

The target uses `target_type = order`, the service date, expected total cents, encrypted summary snapshot, and existing held, activated, or released state. Add a snapshot schema discriminator so activation dispatch can select the menu order activator without interpreting a Daily Dish snapshot.

Add immutable `payment_checkout_target_items` rows for the target. Each row retains the line sequence, canonical menu item foreign key with delete restriction, customer title, description, unit, decimal quantity, unit cents, and line cents. The target and sequence pair is unique. These normalized rows are the searchable accounting and popularity evidence. The encrypted target snapshot remains the signed summary and includes the schema discriminator.

### Payment start and feature disable

Create and commit the attempt, target, and target item rows with provider create outcome `not_sent`. Immediately before the outbound SkipCash call, use a separate short transaction to lock the attempt and company storefront setting, resolve the default company and portal branch again, recheck the feature flag and immutable request eligibility, and atomically claim provider dispatch by setting the outcome to `in_flight`. Commit that claim before calling SkipCash. Do not hold a database transaction across the network call.

If the feature is disabled while the attempt is still `not_sent`, mark the attempt declined, release its held target, and do not dispatch. An attempt is started when its outcome is `in_flight`, `created`, or `unknown`, or when a provider transaction exists. Recovery uses the retained provider request UUID and provider detail lookup. It must not automatically create a replacement checkout after an ambiguous `in_flight` result.

Disabling normal menu sales blocks a new quote, new attempt, and a committed attempt that has not begun provider dispatch. It never blocks webhook retention, provider detail recovery, verified completion, status display, confirmation retry, accounting replay, or invoice correction for a started attempt.

### Paid activation

After existing provider verification proves the reference, provider identity, signature or authenticated detail, amount, QAR currency, and reliable finish time, lock the attempt, target, provider transaction, customer, payment source, relevant finance records, and sequences in the established order.

Use a narrow `StorefrontMenuOrderCreationService`, or an equivalent snapshot aware creation contract, beneath the existing transaction and posting owners. It must write retained paid values directly and must not call a generic recalculation path that can substitute current names, units, prices, addresses, or totals.

In one transaction:

1. Create one `orders` row with source `Website`, `is_daily_dish = false`, type `Delivery`, status `Draft`, canonical customer and user, retained checkout address, chosen service date, no scheduled time, retained note, and retained total. The address may be null and adds no delivery area validation.
2. Create one `order_items` row per retained target item with its canonical menu item ID, retained description, exact decimal quantity, unit price converted from retained unit cents into the existing decimal field, zero discount, exact line total converted from retained line cents, and status `Pending`. Do not run the generic order recalculation after these paid values are written.
3. Issue one AR invoice linked to the order for the exact retained checkout total and retained line descriptions and values. Use the existing normal order invoice shape, QAR currency, retained financial intent date, and zero tax. Do not read current catalog names, units, prices, or finance defaults to rebuild the paid invoice.
4. Create one payment with source `ar`, method `skipcash`, the retained payment source, provider finish date, and exact total.
5. Allocate that payment fully to the invoice and post the accepted ledger events. Invoice issue recognizes revenue. Receipt debits SkipCash clearing and credits AR.
6. Mark the target activated and the attempt completed. Retain all source IDs and financial intent dates for replay.
7. Commit before sending customer or administrator email. The customer recipient comes from the retained portal email. Administrator recipients come from `MailSettingsService::adminRecipientsForCompany()` when checkout starts. Queue both retained notification slots through the existing durable mail intent.

If any financial step fails, roll back all order, invoice, payment, allocation, and ledger effects while retaining verified provider evidence. Public state remains `paid_processing` and scheduled recovery retries the same financial intent. Never substitute a current price, date, account, or terms version.

### Invoice correction

Invoice editing uses the existing AR revision behavior and changes no storefront record. Every authorized void of the original invoice linked through a `menu_order` checkout target, including the existing `voidAndDuplicate()` correction path, releases its active allocation and leaves the payment balance as unallocated customer credit. The replacement invoice does not restore or reactivate the order.

In the same correction transaction or its existing idempotent adapter, set the linked order and every line to `Cancelled` from any reachable status other than already `Cancelled`. This dedicated menu checkout adapter is allowed because the generic order workflow rejects some later statuses. It does not add a new order lifecycle. A repeated void, correction call, or listener replay changes nothing again. No refund call is made.

The user cannot spend this credit on the website. Only the current RMS administrator allocation flow can apply it later.

### Terms and confirmation

Extend the retained versioned payment terms with normal menu advance ordering, the selected service date, included delivery, the no refund and retained credit rule, and the boundary for external delivery applications. New attempts must accept the current published version. Started attempts retain the version, URL, content hash, and acceptance time already captured by the checkout foundation.

The customer confirmation shows the order reference, item summaries, quantities, units, service date, amount, payment reference, and retained support contact from `PaymentSetting::order_support_phone`. It does not call the order delivered. The administrator confirmation shows the same retained operational values and links to the existing RMS records.

### Future combined checkout seam

Completion resolves an activator by target snapshot schema and returns the order and invoice IDs through one common result contract. Every target also exposes its normalized retained lines through `payment_checkout_target_items`. The payment allocation service accepts the list of activated invoice targets and one verified payment. The first release passes one menu target. A future purpose may pass Daily Dish and menu groups together without changing menu profiles, order writers, invoice issue, payment creation, or allocation rules.

Do not add a combined basket, mixed attempt purpose, parent cart table, or second payment now.

## Build slice

1. Add purpose, normalized target items, schema dispatch, and the snapshot aware order writer, then prove one fake provider payment through one menu target.
2. Add full snapshot, fingerprint, `not_sent` dispatch claim, lock, recovery, status, and notification behavior.
3. Add invoice void adaptation, terms content, confirmation content, and payment consistency checks.
4. Run existing payment, settlement, AR, identity, membership, and Daily Dish regression suites.

## Rationale

The current checkout foundation already solves provider verification, exact replay, recovery, clearing, allocations, and notification retry. Replacing it would duplicate the most sensitive code in the system. A small menu activator and a versioned target seam add the new product path while preserving the later option to activate several invoice targets from one payment.
