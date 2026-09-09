# 0012. Checkout add-on upsell verification

## Automated evidence

- `./vendor/bin/pint --dirty` passed on 2026-09-10.
- The focused payment and membership suites passed with 64 tests and 1,062 assertions on 2026-09-10.
- The customer portal suite passed with 31 tests on 2026-09-10.
- The full RMS suite passed with 1,087 tests and 6,953 assertions on 2026-09-10.
- The RMS and customer portal production asset builds passed on 2026-09-10.
- Final diff and repository status checks are release gates recorded in the implementation handoff.

The focused suite covers category settings and authorization, public branch-aware discovery, price and availability validation, full-price promotion isolation, same-payment accounting, covered-booking holds, concurrent holds, retained-credit fallbacks, retry compatibility, merge behavior, status messaging, and idempotent activation.

## Browser evidence

The local portal was exercised at phone, tablet, and desktop sizes. A published QAR 350 normal-menu item was added for one service date, the optional QAR 12 add-on modal appeared before final review, `No thanks` remained available, and the review showed one QAR 362 total. Payment dispatch was intentionally not started during the synthetic browser check.

## Deployment gate

The normal-menu feature is enabled only in the development environment after its automated deployment succeeds. Checkout upsell remains independently controlled and must not be enabled until an administrator selects a valid category. A real SkipCash sandbox checkout and subsequent settlement import remain operational evidence before any production rollout.
