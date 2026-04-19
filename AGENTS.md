
# AGENTS.md

## Purpose
This repository contains a business system with an existing accounting module.
Any accounting-related work must prioritize correctness, traceability, control, and production readiness.

The goal is not to "make it work somehow."
The goal is to harden and complete the accounting module so it behaves like a professional accounting system with explicit logic and no guesswork.

## Scope
Treat any work touching the following as accounting-related:
- chart of accounts
- journals
- ledger
- invoices
- bills
- receivables
- payables
- payments
- allocations
- taxes
- bank / cash
- fiscal periods
- reconciliation
- financial reporting
- inventory-accounting integration
- fixed assets
- adjustments
- reversals
- write-offs

## Mandatory mode of operation
For any accounting-related task, follow this order:

1. Analyze the existing implementation first
2. Describe the current behavior factually
3. State the correct accounting treatment explicitly
4. Identify gaps, unsafe assumptions, and control weaknesses
5. Propose the minimal safe changes required
6. Implement only after accounting treatment is explicit
7. Add tests and validation checks
8. Re-check reporting consistency

Do not guess accounting behavior.
Do not assume the current code is correct merely because it exists.
Do not introduce shortcuts that weaken auditability.

## Core accounting principles
- Use double-entry bookkeeping for all posted accounting events.
- Every posted transaction must balance exactly: total debits == total credits.
- The ledger / journal entries are the source of truth for accounting.
- Posted journal entries are immutable.
- Corrections must occur through reversal entries, adjusting entries, or compensating entries.
- Never silently rewrite posted accounting history.
- Never delete posted accounting records.
- Separate operational document lifecycle from accounting posting lifecycle.
- Distinguish clearly between draft, approved, posted, reversed, voided, cancelled, and adjusted states where relevant.
- Prevent duplicate posting of the same business event.
- Prevent posting into closed periods.
- Require traceability from source document to journal entry.
- Require clear audit metadata for every accounting mutation and posting action.

## Engineering rules for accounting work
- Prefer explicit posting services / domain services over scattered direct writes.
- Do not directly mutate balances if balances can be derived from journal lines.
- Keep posting logic centralized.
- Keep source-document creation separate from ledger posting.
- Preserve idempotency where retries, queues, or repeated requests are possible.
- Validate all required account mappings before posting.
- Use explicit date semantics:
  - document date
  - posting date
  - due date
  - reversal date if applicable
- If the system supports multi-currency, require currency and exchange-rate consistency.
- If the system supports tax, require explicit tax treatment and account mapping.
- If inventory exists, ensure accounting integration is explicit and not implied.

## Required review structure
Every accounting-related response must use this structure:

### Current behavior
Describe what the current code actually does.

### Business event
Describe the real-world accounting event.

### Correct accounting treatment
State debit / credit treatment and recognition logic clearly.

### Gaps / risks
Describe what is unsafe, ambiguous, incomplete, or non-professional.

### Proposed changes
Describe exact technical and accounting changes.

### Validation
Describe required tests, control checks, and report reconciliation checks.

## Required controls
At minimum, protect or implement:
- balanced-entry validation
- period locking
- immutable posted entries
- duplicate posting prevention
- account mapping validation
- source-document traceability
- audit trail completeness
- permission / authorization boundaries
- idempotent posting behavior
- reversal integrity
- allocation correctness
- rounding consistency
- report reconciliation checks

## Reporting rules
Financial reports must derive from posted accounting data, not ad hoc operational totals.

Protect correctness of:
- general ledger
- trial balance
- balance sheet
- profit and loss
- accounts receivable aging
- accounts payable aging
- tax summaries
- bank / cash movement reports

## Definition of done
An accounting task is not done unless:
- the current behavior was analyzed first
- the accounting treatment is explicit
- debits and credits are balanced
- controls are enforced
- auditability is preserved
- report correctness has been checked
- tests cover nominal cases, invalid cases, reversals, and edge cases

## Migration Guardrail (Critical)

Historical migration files are considered executed production history.

Do NOT modify old migration files unless explicitly instructed.

Treat all previously run migrations as immutable.

When schema changes are required:

1. Create a NEW forward-only migration.
2. Preserve existing migration history.
3. Preserve production upgrade path.
4. Avoid rewriting timestamps or historical migration order.
5. Do not rename old migration files.
6. Do not alter already-applied constraints in historical files.

Only edit an old migration if ALL are true:
- the user explicitly requests it
- the environment is confirmed disposable / pre-production
- no shared database has run it

Default assumption:
This is a real environment with already-run migrations.

Preferred behavior:
Generate a new additive migration instead of editing history.