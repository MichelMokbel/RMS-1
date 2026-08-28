# Email history and customer notifications

## Overview

This area records email delivery attempts for operations and customer messages. Sending and recording are separate responsibilities, which matters when you add retries or a new provider.

## Key files

| File | Owns |
|---|---|
| `EmailLogService.php` | Email category, recipients, outcome, context, and error metadata. |
| `app/Models/EmailLog.php` | Delivery history records. |
| `app/Mail/DailyDishOrderAdminMail.php`, `app/Mail/DailyDishOrderCustomerMail.php` | Daily dish notifications. |
| `app/Notifications/CustomerPortalResetPassword.php` | Portal password reset notification. |
| `resources/views/livewire/settings/logs.blade.php` | Admin log viewer. |
| `config/mail.php` | Mail transport configuration. |

## Conventions

* The logger receives an outcome from its caller. It does not send a message or provide send deduplication.
* `sent_at` is populated for the `sent` status. Order and meal plan request references connect delivery history to its source.
* Recipients, context, and exception text can contain personal data. You can minimize these fields and keep the log viewer restricted.

## Gotchas

* Replaying an order or provider event does not automatically make its notification safe to resend. You can inspect the sending boundary separately.
* Relevant tests are `tests/Feature/Settings/LogsPageTest.php`, `tests/Feature/Orders/PublicDailyDishOrderSubmissionTest.php`, and `tests/Feature/CustomerPortal/CustomerPortalAuthTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
