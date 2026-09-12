# Customer ordering production cutover

This runbook releases the RMS payment, membership, promotion, customer identity, mapped delivery location, storefront, and checkout add-on work together with the customer ordering portal. It does not authorize destructive production data cleanup or settlement posting without the evidence described below.

## Release boundary

The application code and automated test suite are ready for deployment. Two data-dependent gates remain deliberately closed:

1. Keep normal-menu ordering and checkout upsells disabled until a current production database copy has been reviewed, mistaken raw-material entries have been removed or excluded through the existing catalog workflow, and the intended storefront items, category, prices, lead days, availability, images, and delivery-app links are approved.
2. Keep SkipCash settlement mutations disabled until one retained provider transaction proves the report identifier mapping and one controlled payout reconciles to the exact bank evidence. Format and arithmetic validation of the supplied workbook is not identity proof.

Daily Dish and existing customer account journeys can remain available independently of both gates.

## Before deployment

1. Take a recoverable database backup and preserve the current application release and environment configuration.
2. Confirm the target meets the supported PHP and MySQL or MariaDB versions and has long-running queue and scheduler processes.
3. Set a unique production `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`, production URLs, trusted HTTPS proxy settings, database credentials, cache, queue, session, mail, and storage configuration. Never copy a development `.env` wholesale.
4. Configure the RMS customer portal origin and the portal `DASHBOARD_BASE_URL` to their production HTTPS origins. The expected customer return path is `/orders/payment`; the RMS webhook path is `/api/integrations/skipcash/webhook`.
5. Retrieve the dedicated **Layla Orders Production Browser** Google Maps key from GCP without printing or committing it. Set it as the portal `GOOGLE_MAPS_BROWSER_KEY`. Its browser restrictions must remain limited to `https://layla-kitchen.com/*` and `https://www.layla-kitchen.com/*`, and its API restrictions to Maps JavaScript and Places.
6. Configure Resend for public inquiries and the encrypted RMS Mail Settings for transactional and administrator mail. Rotate or revoke the historical SMTP credential removed from the portal repository if it could still be valid.
7. Configure Telnyx with the production API key, sender or messaging profile, `CUSTOMER_SMS_PROVIDER=telnyx`, and `CUSTOMER_PHONE_VERIFICATION_BYPASS=false`.
8. Verify the active default company and QAR bank account, the `skipcash` payment source, its clearing account, and both SkipCash fee expense mappings in the target database.

Start from these conservative payment and feature controls:

```dotenv
CUSTOMER_MATCHING_ENABLED=true
CUSTOMER_MATCHING_AI_ENABLED=false
CUSTOMER_DELIVERY_LOCATION_REQUIRED=true
CUSTOMER_PHONE_VERIFICATION_BYPASS=false

SKIPCASH_ENABLED=false
SKIPCASH_ENVIRONMENT=production
SKIPCASH_RETURN_URL=https://layla-kitchen.com/orders/payment
SKIPCASH_WEBHOOK_URL=https://store.layla-kitchen.com/api/integrations/skipcash/webhook
SKIPCASH_SETTLEMENTS_ENABLED=false

MEMBERSHIP_CHECKOUT_ENABLED=false
MEMBERSHIP_QUEUE_ENABLED=false
MEMBERSHIP_BOOKING_ENABLED=false
MEMBERSHIP_PROMOTIONS_ENABLED=false
PAYMENT_CONSISTENCY_ENABLED=true
```

Confirm `SKIPCASH_BASE_URL`, `SKIPCASH_PAY_URL_HOSTS`, credentials, webhook secret, and the clearing account against SkipCash production values. If the RMS production origin differs from `https://store.layla-kitchen.com`, replace the example webhook origin before enabling checkout. Leave the report identifier fields blank until retained evidence proves the mapping.

The normal-menu and checkout-upsell switches are stored in RMS rather than environment variables. Keep both off in production until the data-cleanup gate passes.

## Deployment order

1. Deploy RMS first with checkout and membership feature flags off.
2. Run forward migrations once. Do not roll back financial migrations as a release shortcut.
3. Rebuild application caches, then restart the web, queue, and scheduler processes so they share the same release and configuration.
4. Confirm the scheduler includes SkipCash recovery, customer matching, and payment consistency work. Confirm the queue is processing and has no unexplained failed jobs.
5. Deploy the customer portal and verify that it can read the RMS public menu and configuration endpoints over HTTPS.
6. Smoke-test registration, Qatar map pin and address details, Telnyx verification, login, Daily Dish browsing, plan selection, cart restore, account history, and confirmation email with synthetic data.
7. Enable `SKIPCASH_ENABLED`, make one small controlled production checkout, and verify the server-confirmed payment, dated order, invoice, allocation, clearing entry, customer confirmation, administrator notification, account result, and exact-retry idempotency before broader access.
8. Enable membership checkout, queue, booking, and promotions one control at a time. Verify a paid membership, a later covered booking without a second payment, and a promotion case before enabling the next control.
9. After the database cleanup is approved, configure the storefront and upsell category in RMS, preview the public catalog, and enable normal-menu ordering first. Enable checkout upsells only after their category and items are confirmed.
10. Follow `docs/skipcash-settlement-operations.md` separately for the first controlled payout. Checkout success does not authorize settlement posting.

## Acceptance checks

The release is accepted only when:

* browser return data alone cannot mark a payment successful;
* retrying checkout completion does not duplicate a payment, order, invoice, allocation, subscription, booking, promotion use, or email;
* payment, invoice, clearing, and customer ownership agree with the default company and branch;
* a membership payment remains one payment and later dated invoices allocate against its purchase block without another charge;
* add-ons retain full menu price, do not consume meal credits, and are not discounted by membership promotions;
* a 100 percent membership promotion creates only the pending meal plan request;
* uncertain customer matching never blocks checkout, and verified destination ownership survives a merge;
* the Qatar map boundary and server-side structured-location requirement reject invalid locations without trusting browser-only validation;
* operational failures are visible in Payment Operations, failed jobs, mail history, and consistency findings without changing posted history silently.

## Rollback

For a customer-facing incident, disable the narrowest RMS feature flag first. Existing captured payments must continue through recovery even after new checkout starts are disabled. Do not delete payments, allocations, invoices, orders, memberships, provider events, or audit records.

Roll the application image or code release back only if the previous version remains compatible with the forward schema. Keep queue and scheduler versions aligned with the web release. Use the established invoice void, payment recovery, settlement void, and customer merge workflows for business corrections; do not reverse production migrations or edit posted finance records directly.

## Deferred production data work

When the current database copy is supplied, perform the menu cleanup as a separate reviewed data operation: inventory all `menu_items` consumers, classify mistaken records, map storefront categories and delivery-app listings, preview the exact affected rows, take a backup, and use reversible application workflows or a purpose-built migration rather than ad hoc production deletion. Re-run catalog, ordering, accounting, and storefront tests against a safe copy before enabling the normal menu.

## References

* [Payment and accounting scope](scope/scope.md)
* [SkipCash settlement operations](skipcash-settlement-operations.md)
* [Payment service guide](../app/Services/Payments/AGENTS.md)
* [Customer service guide](../app/Services/Customers/AGENTS.md)
* [Storefront service guide](../app/Services/Storefront/AGENTS.md)
* [Production environment example](../.env.example)
