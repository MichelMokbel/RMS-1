# Marketing campaigns and provider synchronization

## Overview

This area owns Meta and Google Ads account synchronization, spend snapshots, assets, briefs, and activity history. Provider account identity, snapshot grain, and disabled sync behavior matter when you add another integration.

## Key files

| File | Owns |
|---|---|
| `MetaApiService.php`, `GoogleAdsApiService.php`, `GoogleAdsOAuthService.php` | Provider requests and Google authorization. |
| `MarketingCampaignSyncService.php` | External campaign, ad set, and ad identity. |
| `MarketingSpendSyncService.php` | Dated provider spend snapshots. |
| `MarketingSettingsService.php` | Provider settings and sync switches. |
| `MarketingAssetService.php`, `MarketingBriefService.php` | Assets and creative brief workflows. |
| `app/Jobs/Marketing/` | Scheduled campaign and spend workers. |
| `config/marketing.php` | Sync schedules and lookback settings. |

## Conventions

* External identifiers are resolved within their platform account, not globally.
* Spend is stored in micros. Meta ad level rows and Google campaign level rows have different dimensions.
* Sync jobs check active account state and provider enablement before running. Sync logs and activity events describe success and failure.
* The scheduler revisits recent days for late provider data. Repeating a sync updates existing snapshots for the same account, dimensions, and date.

## Gotchas

* Meta aggregate ad set snapshots are removed when detailed ad rows replace them, avoiding duplicated rollups.
* The current spend job has one try and a 180 second timeout. Queue behavior and provider replay guarantees are separate concerns.
* Stored settings, OAuth tokens, provider error text, and raw snapshot payloads need careful handling. You can use `tests/Support/FakeGoogleAdsApiService.php` instead of live calls.
* Relevant tests are `tests/Feature/Marketing/MarketingModuleTest.php` and `tests/Feature/MarketingSyncLogsAuthorizationTest.php`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
