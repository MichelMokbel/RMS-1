# 0011. Catalog administration

**Date**: 2026-09-08

## Summary

Keep each product in `menu_items`, but require a separate reviewed profile before it can appear on the customer website. This prevents active raw materials or internal records from leaking into the storefront and lets the operator manage customer content without changing accounting or recipe history.

## Requirements

This child implements **AC-1**, **AC-2**, **AC-3**, and the catalog parts of **AC-15** and **AC-16**.

## Decision

Add a dedicated Storefront workspace in RMS. A profile starts hidden. Direct publication and each delivery application channel are independent choices.

### Records

| Record | Fields and rules |
|---|---|
| `storefront_settings` | `company_id` unique FK, `portal_branch_id` FK, `normal_menu_enabled` false by default, `menu_cutoff_time` default `23:00:00`, `timezone` fixed `Asia/Qatar`, `delivery_apps_enabled` false by default, positive company storefront `revision`, actor fields, timestamps |
| `storefront_categories` | company FK, unique company slug, title up to 120 characters, optional description up to 500 characters, display order, active flag, soft delete, actor fields |
| `storefront_item_profiles` | company FK, branch FK, menu item FK, category FK nullable until direct publication, optional customer title up to 120 characters, optional short description up to 280 characters, image disk and path, direct flag false, advance days default one, decimal minimum and increment default one, optional decimal maximum, Chef pick flag, display order, actor fields, timestamps, unique company plus branch plus menu item |
| `storefront_closed_dates` | company FK, branch FK, service date, optional short reason, actor fields, unique company plus branch plus date |
| `storefront_delivery_channels` | company FK, code in `talabat`, `snoonu`, `rafeeq`, `keeta`, label, enabled flag, optional validated HTTPS restaurant URL up to 2048 characters, display order, actor fields, unique company plus code |
| `storefront_item_channels` | profile FK, channel FK, enabled flag, optional validated HTTPS item URL up to 2048 characters, actor fields, unique profile plus channel |

Use the existing public storage disk for one primary image. Accept JPEG, PNG, or WebP up to 5 MB, verify the decoded image, generate a random owned path, and never expose a submitted path. The storage service owns replacement, deletion, and URL generation. A missing image uses one standard placeholder and does not block publication.

### Publication validation

A direct item can be published only when all of these facts hold:

1. The profile company and branch agree with the storefront setting.
2. The canonical menu item is active and has a branch availability row for the portal branch.
3. The menu item has a positive selling price and an allowed unit.
4. The storefront category is active.
5. Advance days are at least one.
6. Minimum and increment are positive. Maximum is absent or not less than minimum. The range admits at least one valid quantity.

Customer title falls back to the current menu item name. Unit and price never come from the profile. A profile may remain visible for an enabled delivery application while direct normal menu sales are off, as long as the canonical item stays active and its application destination resolves.

Every storefront mutation locks the company setting and increments its revision. This includes settings, categories, profiles, closed dates, images, Chef picks, channels, and item channel links. Canonical menu item and branch changes do not use this revision, so quote and payment start also revalidate their current values and include the resolved values in the quote fingerprint.

Public reads resolve the company through the existing default company service and then use `storefront_settings.portal_branch_id`. No public company or branch parameter is accepted. Direct item reads require `direct_order_enabled`. Delivery application item reads use a separate projection and require the application section, channel, item channel link, canonical item, company, and profile branch to be eligible. Application cards omit direct price, quantity, lead date, cart, and checkout controls.

### Administration behavior

The Storefront workspace contains Settings, Categories, Items, Closed dates, Delivery applications, and Funnel tabs. Item search includes canonical name, Arabic name, code, and category for staff only. The item editor shows publication blockers before Save and distinguishes Direct, Application only, Both, and Hidden.

Use optimistic version checking against the company storefront revision and row locking for competing publication changes. Seed `storefront.manage` through the existing permission process and grant it to the current administrator role only. Audit every settings, profile, category, closed date, Chef pick, image, and channel change with company, actor, before values, and after values.

### Cleanup flow

Add a read only catalog cleanup report that shows each menu item, active state, branch state, recipe link, storefront state, and every known reference. Extend the existing `MenuItemUsageService` into the canonical reference check for orders, daily dish menus, recipes, sales, quotations, subscriptions, pastry records, storefront profiles, immutable payment target item rows, and any database foreign key that points to `menu_items`.

The administrator chooses one reviewed action:

1. Keep and configure as a valid sellable product.
2. Disable and hide while preserving references.
3. Permanently delete only when the canonical reference check returns no use inside the same locked transaction. Checkout start and deletion lock menu item rows in ascending ID order so neither can create an invisible reference during the other action.

No name, category, recipe, or AI heuristic may delete a row automatically. The report supports dry run export so the launch cleanup is reviewable before mutation.

## Build slice

1. Add settings, profile, permission, audit service, and one public eligible item query.
2. Add categories, images, quantity rules, closed dates, and channel records.
3. Add the Storefront workspace and all publication blockers.
4. Expand reference checks, add the cleanup report, and test deletion against every known writer.

## Rationale

Using `is_active` as public publication would repeat the current data quality problem. A separate profile adds one small layer but preserves the canonical item, historical snapshots, and existing branch model. A separate commerce catalog would duplicate prices and product identity, which would create synchronization and accounting errors.
