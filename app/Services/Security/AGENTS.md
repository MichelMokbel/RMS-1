# Identity and branch access

## Overview

This area owns IAM user changes, role permissions, branch access, and login safety helpers. You can use it to trace authorization shared by the web UI, internal APIs, and POS.

## Key files

| File | Owns |
|---|---|
| `BranchAccessService.php` | Allowed branches, query scoping, and branch identifiers from requests. |
| `IamUserService.php` | User details, roles, direct permissions, and branch assignments. |
| `SafetyPolicyService.php` | Last active admin protection and protection against losing your own IAM access. |
| `RolePermissionService.php` | Role permission changes for the web guard. |
| `RecaptchaService.php` | Configured reCAPTCHA verification. |
| `app/Providers/AuthServiceProvider.php` | Gates and module access rules. |

## Conventions

* You can follow `app/Http/Middleware/` and `bootstrap/app.php` to see where branch checks run. Query scoping and permission checks are separate concerns.
* `BranchAccessService` returns no branches for a user without assignments. An admin has different access semantics.
* IAM changes synchronize roles, direct permissions, and `user_branch_access`. The safety service protects the last active admin and the actor's own IAM access.
* The `web` guard owns the Spatie roles used here. Customer portal and POS token checks live in dedicated middleware.

## Gotchas

* Branch middleware recognizes common request keys. It does not automatically scope every Eloquent query or infer ownership from every model identifier.
* `IamUserService::create` accepts an actor but does not replace authorization at its caller. You can check route and component gates before reusing it.
* Relevant tests are in `tests/Feature/Admin/`, `tests/Feature/Auth/`, and `tests/Feature/POS/`. Customer isolation is covered in `tests/Feature/CustomerPortal/`.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
