-- Generated SQL: delete all branch 1 April 2025 AR invoices before replacing them from invoice log CSV
-- Source file: /Users/mohamadsafar/Desktop/Layla Kitchen/RMS-1/docs/csv/import-01-04-2025.csv
-- Generated at: 2026-04-09T11:45:00
-- Branch ID: 1
-- Delete scope: all ar_invoices rows with type = invoice and issue_date from 2025-04-01 through 2025-04-30

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_legacy_april_invoice_ids_to_delete;
CREATE TEMPORARY TABLE tmp_legacy_april_invoice_ids_to_delete AS
SELECT ai.id
FROM ar_invoices ai
WHERE ai.branch_id = 1
  AND ai.type = 'invoice'
  AND ai.issue_date >= '2025-04-01'
  AND ai.issue_date <= '2025-04-30';

ALTER TABLE tmp_legacy_april_invoice_ids_to_delete
  ADD PRIMARY KEY (id);

SET @matched_invoice_rows := (
  SELECT COUNT(*)
  FROM tmp_legacy_april_invoice_ids_to_delete
);

DELETE ii
FROM ar_invoice_items ii
JOIN tmp_legacy_april_invoice_ids_to_delete t ON t.id = ii.invoice_id;
SET @deleted_invoice_item_rows := ROW_COUNT();

DELETE ai
FROM ar_invoices ai
JOIN tmp_legacy_april_invoice_ids_to_delete t ON t.id = ai.id;
SET @deleted_invoice_rows := ROW_COUNT();

SELECT
  @matched_invoice_rows AS matched_invoice_rows,
  @deleted_invoice_item_rows AS deleted_invoice_item_rows,
  @deleted_invoice_rows AS deleted_invoice_rows;

-- ROLLBACK; -- Uncomment for dry-run safety.
COMMIT;
