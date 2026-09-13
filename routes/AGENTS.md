# HTTP and console entry points

## Overview

This area declares browser, internal API, public website, customer portal, POS, and HR routes. You can use it to identify the actual authentication boundary before reusing a service.

## Key files

| File | Owns |
|---|---|
| `web.php` | Backoffice Volt pages, exports, public utilities, and HR route inclusion. |
| `api.php` | Internal, public, customer portal, and POS API groups. |
| `hr.php` | HR module and action permission groups. |
| `console.php` | Console route declarations. |
| `bootstrap/app.php` | Route registration and middleware aliases. |

## Access map

| Surface | Current boundary |
|---|---|
| Backoffice pages | Usually `auth`, `active`, and the route's role or permission group. |
| Main internal API group | The default `auth` middleware and customer backoffice rejection, with action specific roles. |
| Daily dish and subscription API group | A separate `auth:sanctum` group at the start of `api.php`, outside the main internal API role groups. |
| Customer portal | `auth:sanctum` and `customer.portal`, with selected phone verification gates. |
| Public daily dish order submission | Despite its public URL prefix, it requires a verified customer portal identity. |
| Public Company Food | Independent project slug and edit token flow, intentionally unthrottled in current routes. |
| POS setup and login | Credentialed controller actions outside the operational token group. |
| POS terminal status | Sanctum authentication without the nested `pos.token` middleware. |
| POS operational APIs | Sanctum plus `pos.token`; normal POS operations require `pos:*`, while label agent stream, pull, and acknowledgement accept the narrower `pos.print` ability with the same device and branch checks. |
| Browser order labels | Authenticated active users with `order-labels.print` or the established admin or manager role, plus server enforced source and label format branch alignment. Browser printing creates no terminal or queue state. |
| HR | `auth`, `active`, module permission, then action permissions and service access checks. |

## Conventions

* You can inspect controller and component authorization as well as route middleware. A registered route is not evidence that every record query is scoped.
* Named routes are consumed by navigation, redirects, reports, exports, and help content. API response and route names are compatibility contracts.
* Branch middleware is appended globally, but arbitrary model identifiers still need ownership checks.
* The legacy expenses API returns HTTP 410 and directs callers to Spend.
* AP journal reads use the general reports boundary. Generating and emailing company wide daily report ranges is an administrator only POST action.

## Gotchas

* Web and internal API role groups differ in several modules. Copying a web route's access assumption into an API can change who may act.
* Auxiliary import utilities appear near the start of `web.php`, outside the ordinary module groups. They are not reusable public integration contracts.
* A full module to UI map lives in `resources/views/AGENTS.md`. Authorization evidence lives in the corresponding `tests/Feature/` module suites.

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
