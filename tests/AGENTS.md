# Tests and verification boundaries

## Overview

This area contains Pest feature and unit tests, provider fakes, and a standalone Node test. You can choose checks from the affected domain while keeping test data isolated from real business records.

## Key files

| File | Owns |
|---|---|
| `Pest.php` | Feature test binding and `RefreshDatabase`. |
| `TestCase.php` | Application test bootstrap. |
| `../phpunit.xml` | Testing environment and MySQL target. |
| `Support/FakePhoneVerificationProvider.php` | SMS test boundary. |
| `Support/FakeGoogleAdsApiService.php` | Google Ads test boundary. |
| `Node/petty-cash-source-converter.test.mjs` | Spreadsheet source converter tests. |
| `../.github/workflows/tests.yml`, `../.github/workflows/lint.yml` | CI build, tests, and PHP formatting. |

## Conventions

* Feature tests use `RefreshDatabase`. The checked in test target is MySQL `store_test` on `127.0.0.1`, but you can confirm effective environment and cached configuration before any test that resets data.
* Fakes replace SMS, ads, AI, and other external boundaries. They do not replace the database rules being tested.
* Authorization tests cover allowed and denied actors. Financial tests also need source side effects, audit history, invalid states, and exact retry behavior.
* CI currently selects PHP 8.4 and Node 22. Those versions are CI choices, distinct from the manifest's minimum PHP requirement.

## Additional check

The source converter test has no npm test script. From the repository root, you can run:

```bash
node --test tests/Node/petty-cash-source-converter.test.mjs
```

## Gotchas

* Several domains use tests outside a matching directory. Banking is covered under Accounting, while Company Food has top level feature test files.
* OrderSheet and PastryOrders currently have no dedicated test directory. Nearby tests do not prove those complete workflows.
* `Pest.php` currently includes diagnostic log output. You can avoid treating an existing debug artifact as a result of your change.
* Documentation checks can verify paths and coverage without booting Laravel or resetting a database. They do not prove application tests passed.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
