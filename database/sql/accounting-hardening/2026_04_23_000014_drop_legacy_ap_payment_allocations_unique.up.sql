-- 2026_04_23_000014_drop_legacy_ap_payment_allocations_unique

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
