-- 2026_04_23_000012_adjust_ar_clearing_settlement_client_uuid_uniqueness

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ar_clearing_settlements'
        AND index_name = 'ar_clearing_settlements_client_uuid_unique'
    ),
    'ALTER TABLE ar_clearing_settlements DROP INDEX ar_clearing_settlements_client_uuid_unique',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE ar_clearing_settlements
  ADD COLUMN IF NOT EXISTS active_client_uuid CHAR(36) AS (IF(voided_at IS NULL, client_uuid, NULL)) STORED;

ALTER TABLE ar_clearing_settlements
  ADD UNIQUE INDEX uq_ars_client_uuid_active (active_client_uuid);
