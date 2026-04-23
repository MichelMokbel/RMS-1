-- 2026_04_23_000011_adjust_ap_cheque_clearance_client_uuid_uniqueness

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1
      FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_cheque_clearances'
        AND index_name = 'ap_cheque_clearances_client_uuid_unique'
    ),
    'ALTER TABLE ap_cheque_clearances DROP INDEX ap_cheque_clearances_client_uuid_unique',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE ap_cheque_clearances
  ADD COLUMN IF NOT EXISTS active_client_uuid CHAR(36) AS (IF(voided_at IS NULL, client_uuid, NULL)) STORED;

ALTER TABLE ap_cheque_clearances
  ADD UNIQUE INDEX uq_apc_client_uuid_active (active_client_uuid);
