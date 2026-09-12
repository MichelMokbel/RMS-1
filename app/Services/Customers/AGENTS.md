# Customers and customer portal accounts

## Overview

This area owns customer imports, merging, customer codes, portal registration, automatic identity resolution, review, phone normalization, and verification. Registration links one eligible exact name-and-phone match or creates an owned fallback customer immediately; uncertain candidates are reviewed later and never block checkout.

## Key files

| File | Owns |
|---|---|
| `CustomerPortalRegistrationService.php` | Portal signup profile validation before identity resolution. |
| `CustomerPortalAccountService.php` | Linked account and verified phone state. |
| `CustomerIdentityResolver.php`, `CustomerMatchingService.php` | Exact linking, fallback customer creation, bounded candidate discovery, and optional ranking. |
| `CustomerMatchReviewService.php`, `CustomerIdentityIntegrityService.php` | Private review, recovery, and identity integrity diagnostics. |
| `CustomerPhoneVerificationService.php` | Challenges, expiry, resend limits, and encrypted challenge tokens. |
| `CustomerDeliveryLocationService.php` | Normalized portal location tuples, the pinned Qatar boundary check, and compatible delivery address summaries. |
| `PhoneNumberService.php` | Phone normalization and masking. |
| `CustomerImportService.php`, `CustomerUpsertMatcher.php` | CSV preview, import, and matching. |
| `CustomerMergeService.php`, `CustomerCodeService.php` | Merging references and allocating customer codes. |
| `app/Contracts/PhoneVerificationProvider.php` | SMS provider boundary. |

## Conventions

* Signup stores portal profile fields on `User`, links one eligible exact normalized full-name and phone match when identity is proven, or creates a new owned customer. Low-confidence candidates remain a private review suggestion and do not hold the account or checkout.
* `customer.portal` checks customer role and token ability. Phone verification is a further gate on selected routes, not a replacement for account ownership.
* Verification stores hashed codes, expiry, attempt counts, resend limits, and challenge purpose. The provider is bound in `app/Providers/AppServiceProvider.php`.
* New portal locations are one atomic tuple: customer confirmed coordinates, optional Google place ID, required building detail, and optional unit and instructions. RMS validates the pinned GeoJSON boundary and generates the legacy `delivery_address` summary. Provider address labels are not retained.
* Merge moves references across orders, subscriptions, AR, sales, pastry orders, and requests, then deactivates the source customer. A conflicting portal user is deactivated instead of violating the unique customer link.

## Gotchas

* `config/customers.php` has an explicit verification bypass. Its presence is not evidence that production bypasses verification.
* `CUSTOMER_DELIVERY_LOCATION_REQUIRED` defaults off for additive rollout. Existing accounts and already started verification challenges remain compatible; enable it only after the website picker is deployed.
* Unlinked portal accounts have user owned orders but no customer financial history. You can preserve both paths using `tests/Feature/CustomerPortal/CustomerDashboardApiTest.php`.
* Relevant suites are `tests/Feature/Customers/` and `tests/Feature/CustomerPortal/`. The SMS fake is `tests/Support/FakePhoneVerificationProvider.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
