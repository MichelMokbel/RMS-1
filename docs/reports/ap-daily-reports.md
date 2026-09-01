# Daily AP journal and category expenses

The AP Journal Entries report is in Reports, Accounting. Expenses by Category is in Reports, Expenses. Both provide company, branch, and date filters with print, CSV, and PDF outputs. Totals include every matching row and remain separate for each currency. Non administrators see only permitted branches and companies.

## Daily journal

Each saved journal belongs to one company and one accounting calendar date (`subledger_entries.entry_date`). It contains posted AP invoices, payments, allocations, cheque clearances, and their reversal entries. Originals remain visible after their source document is voided. Drafts and invalidated ledger entries are excluded. It never creates or changes journal postings.

Saved daily journals receive sequential document numbers such as `APJ-2026-0001`. Sequences are separate for each company and report calendar year, allocated in first-generation order (including empty days). A backdated report uses its accounting date's year. Regeneration, repeated runs, and email retries retain the original number. Numbers appear in email subjects, bodies, PDF attachments, and saved-day report views/exports. Date-range exports identify the daily document for each entry; dates not yet generated have no number. Merely viewing or exporting live data does not allocate numbers. Underlying ledger entry IDs are unchanged.

At 17:00 in `config('app.timezone')`, the application generates the current day's journal and emails a PDF to the configured recipient. This is a calendar day report, not a rolling 24 hour posting window. Records from yesterday are not included simply because they were posted after 17:00.

When further AP entries are added for a saved report's date, a scheduled check regenerates the same report within a minute. This also applies to backdated additions. Its revision increases only if the data changes. The email remains once per company and date, and records which revision it contained. Regeneration does not resend an email. The email links to the current interactive report, which queries current data. Empty days receive an empty report.

## Configuration and deployment

1. Apply the forward migration `2026_08_31_170000_add_daily_ap_journal_reports.php` through the normal deployment process. It adds disabled by default finance settings, the report snapshot table, and a subledger index for daily refresh queries. Existing finance defaults are preserved; no business data backfill is required. Allow for index creation time on a large subledger.
   Then apply `2026_08_31_180000_number_daily_ap_journal_reports.php`. It requires the existing `document_sequences` table, adds a required company-unique document number, and numbers existing reports in date order per company/year. The restart-safe backfill commits in batches of 200 while preserving snapshots, revisions, timestamps, and delivery history. Pause report commands during deployment until both migrations finish. Rolling back numbering drops the column but keeps counters to avoid reusing issued numbers if redeployed.
2. In Settings, Finance, set Daily AP journal email, choose a company and recipient, and enable it. Only an administrator may configure delivery. The recipient receives all branches of the selected company.
3. Keep the application's Laravel scheduler running every minute (`php artisan schedule:run`). Both commands run synchronously; a queue worker is not needed for this feature. Configure the normal Laravel mail transport and `APP_URL` for email links. Confirm the deployed `APP_TIMEZONE`; do not infer it from development defaults.

The scheduler runs `reports:send-ap-journal` at 17:00 and `reports:refresh-ap-journals` every minute. Normal overlap protection and database locking prevent repeated delivery claims. Snapshot uniqueness is enforced by company and date in the database.

For deployments using direct SQL, `database/sql/ap_daily_reports.sql` applies both migrations and records them in Laravel's migration history only after success. Import the whole file in the application database using a MySQL client that supports `DELIMITER` and a user with routine/schema privileges. Back up first and pause application writes and the scheduler. It handles both a fresh installation and existing daily reports, preserves assigned numbers and delivery settings, and can resume after interruption. DDL is not transactional; stop on any error before resuming the application. Do not also run the two migrations manually after importing the SQL. The SQL does not deploy application code or install the scheduler.

An operator can generate and send a missed date with `php artisan reports:send-ap-journal --date=2026-08-31`. Rerunning an already sent date refreshes its data but does not resend it. A scheduled run missed during downtime is not automatically emailed the next day.

## Delivery failures

The email log category is `ap_daily_journal`. Report records retain generation time, revision, recipient, delivery status, sent time, and emailed revision. Provider error text is not copied into email history; only its exception class is retained in the context.

SMTP cannot guarantee exactly once delivery across a network timeout or a process crash. Failed or uncertain attempts are not automatically retried. After checking the provider for an already accepted message, an operator can retry a failed delivery with `php artisan reports:send-ap-journal --date=2026-08-31 --retry-failed`. A report left in `sending` requires operator investigation; it is deliberately not reclaimed automatically. Already sent reports are never resent by this command.

## Expense category totals

The category report aggregates canonical AP expense invoices by category ID and currency. It includes posted, partially paid, and paid invoices by invoice date, excluding drafts and voids. Missing categories appear as Uncategorized. It shows document count, subtotal, tax, and gross total without a row cap. Legacy `expenses` records are not included.

Journal amounts retain the ledger's four decimal places. Category totals are displayed with three decimal places, matching report presentation elsewhere. Branchless AP cheque clearance entries are included in company reports for administrators and scheduled delivery, but excluded from branch scoped non administrator reports.
