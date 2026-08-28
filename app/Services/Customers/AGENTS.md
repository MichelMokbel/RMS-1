# Customers and customer portal accounts

## Overview

This area owns customer imports, merging, customer codes, portal registration, phone normalization, and verification. A portal user may exist without a linked customer record, which matters when you expose orders or financial data.

## Key files

| File | Owns |
|---|---|
| `CustomerPortalRegistrationService.php` | Portal signup and the initial unlinked account. |
| `CustomerPortalAccountService.php` | Linked account and verified phone state. |
| `CustomerPhoneVerificationService.php` | Challenges, expiry, resend limits, and encrypted challenge tokens. |
| `PhoneNumberService.php` | Phone normalization and masking. |
| `CustomerImportService.php`, `CustomerUpsertMatcher.php` | CSV preview, import, and matching. |
| `CustomerMergeService.php`, `CustomerCodeService.php` | Merging references and allocating customer codes. |
| `app/Contracts/PhoneVerificationProvider.php` | SMS provider boundary. |

## Conventions

* Signup stores portal profile fields on `User` without automatically linking an existing customer. You can inspect `CustomerPortalAuthController` and the customer accounts UI for the separate linking workflow.
* `customer.portal` checks customer role and token ability. Phone verification is a further gate on selected routes, not a replacement for account ownership.
* Verification stores hashed codes, expiry, attempt counts, resend limits, and challenge purpose. The provider is bound in `app/Providers/AppServiceProvider.php`.
* Merge moves references across orders, subscriptions, AR, sales, pastry orders, and requests, then deactivates the source customer. A conflicting portal user is deactivated instead of violating the unique customer link.

## Gotchas

* `config/customers.php` has an explicit verification bypass. Its presence is not evidence that production bypasses verification.
* Unlinked portal accounts have user owned orders but no customer financial history. You can preserve both paths using `tests/Feature/CustomerPortal/CustomerDashboardApiTest.php`.
* Relevant suites are `tests/Feature/Customers/` and `tests/Feature/CustomerPortal/`. The SMS fake is `tests/Support/FakePhoneVerificationProvider.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
