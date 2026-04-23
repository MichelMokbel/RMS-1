-- 2026_04_19_000004_add_ap_payment_allocations_active_unique

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_payment_allocations'
        AND index_name = 'uniq_payment_invoice'
    ),
    'ALTER TABLE ap_payment_allocations DROP INDEX uniq_payment_invoice',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE ap_payment_allocations
  ADD COLUMN IF NOT EXISTS alloc_active_sentinel TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED;

ALTER TABLE ap_payment_allocations
  ADD UNIQUE INDEX ap_payment_allocations_active_unique (payment_id, invoice_id, alloc_active_sentinel);
