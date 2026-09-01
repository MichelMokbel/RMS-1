# Verification contract

These tests are required during implementation. No payment or customer data is changed by this plan.

* [ ] AC-1: First/renewal/both and plan 20/26/both; inactive, future, exact end, exhausted and wrong company code; forged amount, multiple codes and saved credit field rejection.
* [ ] AC-2: Fixed cap, fractional percent, 100 percent, rounding to zero, positive one cent net with zero meal slices; all daily amounts reconcile and full quota is unchanged.
* [ ] AC-3, AC-4: Quote reserves nothing; final capacity race; positive hold exact replay; paid conversion once; failed/unpaid/late release; unknown finish keeps hold; code pause after acceptance honors snapshot.
* [ ] AC-5: Valid zero request with all/some/no proposed choices creates request/redemption/audit/mail intents only. Assert no new provider, checkout, receipt, subscription, block, order, booking, invoice, allocation, usage or conversion rows.
* [ ] AC-6: Same UUID, changed payload conflict, later UUID, expired code replay, closed/rejected request and fixed amount zero replay return the same original request without new mail or use.
* [ ] AC-7: Legacy cash membership counts, ordinary order does not, free pending request does not, manual conversion later does; cancelled paid block and voided invoice preserve use/history.
* [ ] AC-7, AC-8: Merge completed uses and two historical zero requests without deleting either; future use returns earliest original owned request or rejects exhausted code; source login no longer authorized.
* [ ] AC-8: First positive intent across different codes/no code, simultaneous last total use, per customer limit, admin state change and completion; no double capacity or financial effect.
* [ ] AC-9: Website correct paid/request/covered modes, reload/second device, code removal requote, no automatic fallback to old unpaid endpoint, email failure leaves successful result intact.
* [ ] AC-10: 0006 valid/broken fixtures, owning domain tests, isolated MySQL migrations, website contract and Node tests, changed PHP lint, formatting and builds.
