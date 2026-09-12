# Customer matching and signup rationale

## Context

> Premise note: Bypass does not prove possession of a phone. The owner explicitly accepted temporarily treating it as sufficient for exact matching after the risk of claiming an unlinked customer's history and remaining meals was explained. This is an intentional exception, not a claim that the existing security check verifies identity.

RMS already owns customer logins, SMS challenges, customer records, and manual merging. Current registration deliberately leaves users unlinked, including in bypass mode. The payment integration needs a known customer owner without requiring routine intervention from the one person managing the platform.

The existing customer website is a separate PHP application at /Applications/XAMPP/htdocs/laylakitchen. Its active API wrappers and JavaScript use RMS tokens and account responses. This specification is an enhancement of those boundaries, not a replacement account system.

The feature handles personal data and access to existing financial and membership history. No additional jurisdictional compliance certification is asserted. Names are the only personal fields approved for optional Gemini ranking; data minimization and restricted audit access remain design requirements.

## Confirmed decisions

| Decision | Confirmation |
|---|---|
| Uncertain identity does not delay orders or payment | Create a separately owned customer and review internally |
| Exact automatic match | Full name ignoring case and whitespace, normalized phone, one active match, no attached login |
| Verification exception | Owner accepted temporary bypass on 2026-08-30 after the account claiming risk was explicitly described |
| Surviving login | Destination wins when both have logins; retain existing source transfer when destination has none |
| Gemini | Advisory only, names and temporary candidate labels only |
| Review UI | Existing Customer Accounts page and existing merge flow; administrator can mark different customers |
| Data model | Existing users, customers, SMS challenges, and audit; one internal review entity and a nullable customer merge destination |
| Financial scope | Keep payment, daily invoice allocation, no refunds, admin only customer credit, sequential memberships, and permanent promotion history from 0001 |
| Matching disabled | Keep fallback customer creation and existing links; pause historical automatic linking and new suggestions |
| Existing unlinked token | Preserve the existing HTTP 200 account shape, session, and cart; resolve ownership before the next quote or checkout |
| Matching version | Versioned digest of account ID, canonical customer ID, normalized name, and phone; exclude email and address |
| Delivery location | Google Maps pin, required building detail, Qatar only server validation, and no delivery eligibility or fee behavior |

The exception replaces the earlier requirement for genuine SMS at automatic linking only while the server bypass setting is enabled. It does not authorize taking over an attached login or treating provider phone data as proof.

## Options considered

### Option 1: Improve the current services in place

Keep the established user to customer relationship and add a private review and canonical merge reference. (basis: CustomerPortalRegistrationService, CustomerMergeService, and the current Customer Accounts page)

**Benefit**: Smallest change to the established workflow and payment ownership.

**Cost**: Existing writers, proof handling, and merge consumers need coordinated hardening and regression tests.

### Option 2: Add a parallel identity registry and migrate gradually

Create a separate identity registry, move signup and ownership into it, and preserve old links during migration. (basis: staged migration practice and the current nullable users.customer_id contract)

**Benefit**: Explicit separation of account identity, customer profile, and relationship history.

**Cost**: Two ownership systems and a larger migration than this integration needs.

### Option 3: Replace customer authentication and linking directly

Move login and matching to a new identity implementation in one release. (basis: current Sanctum, Fortify, customer role, proxy, and session contracts)

**Benefit**: A uniform new boundary could remove older special cases.

**Cost**: High account and website compatibility risk with no necessary business benefit here.

### Option 4: Retain manual linking for all historical customers

Allow bypass signup only onto a separately owned customer until an administrator verifies and merges it. (basis: the current unlinked signup behavior and least privilege)

**Benefit**: Avoids public claims of unlinked historical records without phone proof.

**Cost**: More staff work and delayed visibility of old memberships. The owner chose temporary exact matching instead after the risk discussion.

## Rationale

Choose Option 1 because the repository already has the account, customer, review location, provider boundaries, and merge operation needed. Option 4 remains the safer identity policy when SMS is unavailable, but the owner's explicit temporary exception controls this plan. No ranking score substitutes for that policy decision. (basis: confirmed owner decisions and the Customers service guide)

Reuse genuine challenge evidence instead of inventing another proof table or trusting the existing timestamps. This makes the shutdown rule meaningful even for accounts created under the old bypass implementation. The additional current phone verification endpoints are necessary because the current phone change endpoints are themselves behind the verified phone gate and reject an unchanged number. (basis: CustomerPortalAccountService, CustomerPortalProfileController, CustomerPhoneVerificationService, and routes/api.php)

Preserve event evidence and resolve its current owner through the merge chain. Rewriting original user IDs, checkout idempotency keys, SMS subjects, or promotion history would destroy evidence and can collide with unique keys. The typed destination and original references let newer workflows find the owner without recreating financial effects. (basis: the 0001 payment contract, CustomerMergeService, and immutable event identity practice)

Keep suggested candidate limits, local string comparison, queue retries, and technical error handling as implementation recommendations. They are not new business eligibility or customer approval rules. No new external library, provider, hosting, or agent tool is required. (basis: the existing AiProviderInterface and GeminiProvider)

The owner selected Google Maps for delivery location capture on 2026-09-11 and authorized its setup in the existing labeled GCP project. Use the current Places widget because it provides accessible mobile search and automatic session handling. Use a fixed center pin because it works well with one hand on small screens and avoids a tiny draggable target. Repeat the Qatar check on the server because browser restrictions and country labels are user controlled. Keep location capture separate from delivery coverage because the business has not introduced delivery zones, fees, or address eligibility. Persist customer entered details and the customer confirmed coordinate only. Keep provider formatted addresses transient so the durable operational snapshot does not rely on a broader Google Places retention interpretation. (basis: owner decision, Google Maps JavaScript and Places documentation, service specific terms, and the existing portal address contract)

## Independent cross check

A read only review by gpt-5.5 found three decision gaps: the matching switch boundary, account reads using an existing unlinked token, and the exact fingerprint inputs. The owner approved the recommended clarifications on 2026-08-30. They are incorporated in the design and verification matrix; this records approval of those fixes, not acceptance of the whole specification or proof of implementation.

A second read only review by gpt-6-astra on 2026-09-12 cross checked the Google Maps amendment. It identified boundary provenance, provider content retention, explicit pin confirmation, exact field contracts, atomic tuple handling, staged cutover, and failure and accessibility cases. The owner instructed the implementation to proceed after this cross check. The accepted corrections are incorporated here and in the verification matrix without adding delivery zones, fees, eligibility, or fulfillment behavior.

After those clarifications were applied, the owner separately accepted the complete revised design on 2026-08-30. Scope feature 8 now records its completed design step and remaining implementation milestones. The specification stays Proposed until implementation starts; no application behavior or production setting changed through this acceptance.

Fallback ownership remains available because switching off historical matching must not interrupt signup. Keeping the existing unlinked account envelope avoids forced login or cart loss during deployment. A fingerprint based only on matching inputs rejects obsolete suggestions without rescanning after an unrelated email or address edit. (basis: confirmed owner decisions, CustomerPortalAccountService, and idempotent processing)

The review also noted that customers.verification_bypass could read like a customer field. It is the existing Laravel configuration key in config/customers.php, mapped from CUSTOMER_PHONE_VERIFICATION_BYPASS. The value sourcing table now makes that distinction explicit.

## Source inventory

| Source | Verified current behavior and gap |
|---|---|
| app/Services/Customers/CustomerPortalRegistrationService.php | Creates a customer role user with customer_id null. Bypass sets portal_phone_verified_at without a challenge |
| app/Services/Customers/CustomerPortalAccountService.php | Treats the global bypass or either nonnull verification timestamp as verified. Already serializes linked_customer, link_status, and an unlinked customer object with null id and portal profile fields |
| app/Services/Customers/CustomerPhoneVerificationService.php | Stores challenge user, purpose, phone, limits, provider and verified_at; sends synchronously and resolves encrypted tokens |
| app/Http/Controllers/Api/CustomerPortalProfileController.php | Phone change is unavailable in bypass and cannot verify the unchanged current phone. Token user ownership must be checked against the authenticated caller |
| app/Services/Customers/CustomerUpsertMatcher.php | Import matcher can select by email, phone, or name and type; unsuitable as a portal identity proof |
| app/Services/Customers/CustomerMergeService.php | Moves eight current customer reference groups; disables source login on conflict or transfers it otherwise. Does not provide complete new reference coverage, locks, session revocation, or a typed merge destination |
| app/Models/User.php and app/Http/Middleware/EnsureCustomerPortalUser.php | Portal role and token ability are checked. The portal middleware itself does not check active status |
| app/Http/Controllers/Api/CustomerPortalDashboardController.php | Orders can be visible by user_id or customer_id; financial history uses customer_id |
| resources/views/livewire/customers/accounts.blade.php | Existing account list, filters, manual linking, unlinking, and status actions |
| resources/views/livewire/customers/index.blade.php and routes/web.php | Existing destination selection and admin merge action; Customer Accounts route is admin only |
| app/Models/Quotation.php and app/Models/OrderSheetEntry.php | Current customer references omitted by the existing merge implementation |
| app/Models/MarketingSetting.php | google_login_customer_id is external advertising configuration and is not an RMS customer FK |
| app/Models/MealSubscription.php and app/Services/Orders/SubscriptionOrderGenerationService.php | Existing subscriptions have separate usage and generation consumers. The membership slice must make its customer queue consistent after merge |
| app/Services/Accounting/AccountingAuditLogService.php | Reusable structured audit service; currently returns without writing when its table is missing, so identity workflow readiness must enforce storage |
| app/Models/SubledgerEntry.php | Posted entries are immutable and reference financial sources rather than mutable customer ownership directly |
| database/migrations/2026_03_24_000030_add_customer_portal_columns_and_phone_verification_table.php | Unique signed integer users.customer_id; normalized phone index and challenge schema |
| database/migrations/2026_04_25_000001_add_customer_portal_profile_fields_and_order_user_link.php | Nullable challenge customer_id, portal profile fields, and orders.user_id. The actual field is user_id, not customer_user_id |
| tests/Feature/CustomerPortal/CustomerPortalAuthTest.php | Explicitly expects unlinked signup today and a bypass timestamp without any SMS challenge |
| tests/Feature/CustomerPortal/CustomerPhoneChangeTest.php | Covers phone change success and bypass rejection, not the new current phone transition |
| tests/Feature/Customers/CustomerMergeTest.php | Covers the two surviving login cases, not financial coverage, revocation, or concurrent and repeated merges |
| Customer website api/orders and assets/js/orders-core.js | Active proxies, special registration conflict envelope, persisted token and cart, and verification inferred from a timestamp today |

This inventory is code evidence, not an assertion that the planned behavior is implemented. No database or production configuration was inspected or changed to establish these findings.

## References

**Project sources**:

* AGENTS.md and app/Services/Customers/AGENTS.md, the ownership and workflow boundaries.
* app/Services/Ai/AGENTS.md, AiProviderInterface.php, and GeminiProvider.php, the installed structured provider boundary.
* CustomerPortalRegistrationService.php, CustomerPortalAccountService.php, CustomerPortalProfileController.php, CustomerPhoneVerificationService.php, CustomerMergeService.php, and routes/api.php, as listed in the inventory.
* The current Customer Accounts page, Sanctum and Fortify contracts, and customer website proxies, as listed in the inventory.
* [0001 payment and accounting contract](../0001-payment-accounting-contract/index.md), financial, queue, promotion, and retry invariants.

**Practices**:

* Least privilege, data minimization, and explicit risk acceptance.
* Staged migration with additive schema and compatible rollback.
* Immutable event identity and idempotent processing.
* Google Maps Platform API security guidance, current Places widget guidance, and Maps content attribution requirements.
* geoBoundaries gbOpen Qatar ADM0 boundary QAT-ADM0-15585745 at upstream revision 9469f09, CC BY 4.0.

References use inspected project sources, the named geoBoundary artifact, and current provider documentation. GCP APIs and a restricted development browser key were configured during implementation; no provider generated address content is retained by this design.
