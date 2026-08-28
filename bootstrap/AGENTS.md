# Application wiring and scheduled work

## Overview

This area connects routes, middleware, commands, and scheduled work. You can also follow the providers, events, and jobs named here when planning an integration across modules.

## Key files

| File | Owns |
|---|---|
| `app.php` | Route loading, middleware aliases, command registration, and schedule definitions. |
| `providers.php` | Application provider registration. |
| `app/Providers/AppServiceProvider.php` | AI and SMS bindings, finance lock configuration, and view namespace. |
| `app/Providers/AuthServiceProvider.php` | Application gates. |
| `app/Providers/FortifyServiceProvider.php` | Web authentication configuration. |
| `app/Events/`, `app/Listeners/`, `app/Jobs/` | Business events, listeners, and queued work. |

## Scheduled boundaries

| Workflow | Entry point |
|---|---|
| Subscription orders | Configured daily callback, enabled by subscription settings and a system actor. |
| POS print retention | Hourly `pos:prune-print-stream-events` command. |
| Recurring AP bills | Daily `accounting:generate-recurring-bills` command. |
| Quotation expiry | Daily `quotations:expire` command at the configured time. |
| HR alerts | Daily `hr:refresh-alerts` command. |
| Marketing campaigns and spend | Daily callbacks dispatching account and date specific jobs. |

## Conventions

* Route loading includes web, API, console, and health endpoints. HR routes are required from the web route file.
* You can preserve disabled and missing configuration paths when changing schedules. Not all scheduled work has identical overlap protection.
* Provider bindings currently include `AiProviderInterface` and `PhoneVerificationProvider`. HR document scanning has a separate `DocumentScanner` contract.
* `SyncSubscriptionMealsOnInvoiceIssued` is discovered automatically. `PaymentReceived` and `SaleClosed` are also business events, but dispatch alone is not a durable provider inbox.

## Gotchas

* App boot may inspect finance settings in the database. Even a command that only lists routes can bootstrap providers.
* Restore, repair, backfill, import, and transactional clearing commands are operational tools, not routine verification. You can inspect their code without executing them.
* Runtime queue, cache, storage, and credentials are deployment inputs. Repository defaults do not identify production configuration.
* Relevant tests span `tests/Feature/SubscriptionGeneration/`, `tests/Feature/AP/RecurringBillsTest.php`, `tests/Feature/POS/`, `tests/Feature/HR/`, and `tests/Feature/Marketing/`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
