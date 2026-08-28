# Quotation services

## Overview

This area owns quotation drafts, lifecycle transitions, totals, template validation, immutable snapshots, rendered artifacts, and invoice conversion. Preserve version history, branch-scoped numbering, transactional transitions, and the exact customer-facing document that was finalized.

## Key files

| File | Owns |
|---|---|
| `QuotationDraftService.php` | Creates and updates mutable drafts. |
| `QuotationLifecycleService.php` | Finalize, revise, expire, and other state transitions. |
| `QuotationSnapshotService.php` | Captures immutable version snapshots and artifacts. |
| `QuotationTemplateService.php` | Manages document templates and versions. |
| `QuotationSchemaValidator.php` | Validates template and payload schemas. |
| `QuotationInvoiceConversionService.php` | Converts eligible quotations into invoices. |

## Conventions

- Mutate content only through the draft and lifecycle services; do not bypass state checks with direct model updates.
- Lock the quotation and related numbering or version rows during lifecycle transitions.
- Preserve finalized snapshots and rendered artifacts as immutable historical evidence.
- Validate templates and render data through the existing schema validator before storing or rendering them.
- Allocate quotation numbers within the established company and branch scope and preserve uniqueness.
- Recalculate totals through `QuotationTotalsCalculator`; follow the domain's money precision and tax rules.
- Invoice conversion must be permission checked, transactional, and idempotent.

## Gotchas

- Editing a finalized quotation is a revision workflow, not an in-place update.
- Template changes must not alter previously finalized customer documents.
- Scheduled expiry is registered in `bootstrap/app.php` and is configuration driven.
- Inspect `tests/Feature/Quotations/` and `tests/Unit/Quotations/`, including rendering and lifecycle coverage, before changing document behavior.

## Rendering and integration boundaries

* `CompanyDocumentProfileService.php` stores company document identity. `Rendering/` and `Storage/` own asset resolution, HTML, PDF, DOCX, and generated artifacts.
* Finalization generates artifacts during its database transaction and attempts artifact cleanup on failure. You can preserve that compensation path when changing storage.
* `QuotationInvoiceConversionService.php` connects accepted versions to AR source records and payment terms. Its source identity and total checks are part of the conversion contract.
* Related guides are [AR](../AR/AGENTS.md), [shared numbering](../Sequences/AGENTS.md), and [UI module context](../../../resources/views/AGENTS.md).

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
