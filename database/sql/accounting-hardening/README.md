# Accounting Hardening SQL Scripts

Plain SQL equivalents of the accounting hardening Laravel migrations.

Target:
- MySQL 8.x
- Existing RMS base schema already present

Files:
- One `.up.sql` file per migration
- `all-up.sql` to run the full hardening set in order
- `preflight-checks.sql` for duplicate/conflict checks that should be reviewed before applying unique indexes

Notes:
- These scripts mirror the current migration logic, including the later AP/AR active-only uniqueness adjustments.
- `2026_04_23_000013` provisions fiscal years and accounting periods for the current and next year for active companies.
- `2026_04_19_000008` assumes `ap_payments.id` is `INT`, which matches the current project schema.
