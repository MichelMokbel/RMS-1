# Finance settings and lock date

## Overview

This area stores finance defaults and the finance lock date. These settings affect accounting context, matching tolerance, bank selection, and period controls across several modules.

## Key files

| File | Owns |
|---|---|
| `FinanceSettingsService.php` | Reading and saving the singleton settings record. |
| `ApReportSettingsService.php` | Administrator controlled daily AP report delivery settings. |
| `app/Models/FinanceSetting.php` | Persisted finance defaults. |
| `app/Providers/AppServiceProvider.php` | Loading the stored lock date into runtime configuration. |
| `app/Console/Commands/FinanceLockDate.php` | The lock date command. |
| `resources/views/livewire/finance/settings.blade.php` | Finance settings UI. |
| `config/finance.php` | Configuration fallback. |

## Conventions

* Settings use the record with identifier `1`. They are not an independent settings row per branch.
* A supplied nonempty lock date is normalized through Carbon. Moving it backwards requires the service's explicit override.
* Company, bank, purchase order tolerance, and price variance defaults are distinct settings. You can keep caller validation for those references.
* App boot can load the stored lock date into configuration, with guards for an unavailable database.
* Daily AP report delivery uses the same singleton record. Its recipient and company are administrator controlled, and delivery is disabled by default.

## Gotchas

* Some reads create the singleton record. A settings getter is not always read only.
* Changing a stored setting is different from changing configuration already loaded into a running process.
* Relevant tests include `tests/Feature/Accounting/PeriodGateTest.php`, `tests/Feature/Accounting/PeriodCloseWorkflowTest.php`, `tests/Feature/Ledger/GlBatchPostingTest.php`, and `tests/Feature/Settings/AccountingSetupTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
