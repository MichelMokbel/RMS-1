-- Accounting hardening all-in-one SQL
-- Generated from database/sql/accounting-hardening/*.up.sql
-- Target: MySQL 8.x

SET NAMES utf8mb4;


-- ============================================================================
-- 2026_04_19_000001_harden_accounting_payment_idempotency_and_bank_uniqueness

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_payments'
        AND column_name = 'client_uuid'
    ),
    'SELECT 1',
    "ALTER TABLE ap_payments ADD COLUMN client_uuid CHAR(36) NULL AFTER supplier_id"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_payments'
        AND index_name = 'ap_payments_client_uuid_unique'
    ),
    'SELECT 1',
    "ALTER TABLE ap_payments ADD UNIQUE INDEX ap_payments_client_uuid_unique (client_uuid)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'bank_transactions'
        AND index_name = 'bank_transactions_source_unique'
    ),
    'SELECT 1',
    "ALTER TABLE bank_transactions ADD UNIQUE INDEX bank_transactions_source_unique (source_type, source_id, transaction_type)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- 2026_04_19_000002_add_subledger_entries_source_event_unique

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'subledger_entries'
        AND index_name = 'subledger_entries_source_event_unique'
    ),
    'SELECT 1',
    "ALTER TABLE subledger_entries ADD UNIQUE INDEX subledger_entries_source_event_unique (source_type, source_id, event)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- 2026_04_19_000003_add_payment_allocations_active_unique

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'payment_allocations'
        AND column_name = 'alloc_active_sentinel'
    ),
    'SELECT 1',
    "ALTER TABLE payment_allocations ADD COLUMN alloc_active_sentinel TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'payment_allocations'
        AND index_name = 'payment_allocations_active_unique'
    ),
    'SELECT 1',
    "ALTER TABLE payment_allocations ADD UNIQUE INDEX payment_allocations_active_unique (payment_id, allocatable_type, allocatable_id, alloc_active_sentinel)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
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

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_payment_allocations'
        AND column_name = 'alloc_active_sentinel'
    ),
    'SELECT 1',
    "ALTER TABLE ap_payment_allocations ADD COLUMN alloc_active_sentinel TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_payment_allocations'
        AND index_name = 'ap_payment_allocations_active_unique'
    ),
    'SELECT 1',
    "ALTER TABLE ap_payment_allocations ADD UNIQUE INDEX ap_payment_allocations_active_unique (payment_id, invoice_id, alloc_active_sentinel)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- 2026_04_19_000005_add_journal_entries_immutability_trigger

DROP TRIGGER IF EXISTS journal_entries_immutable_posted;

DELIMITER $$
CREATE TRIGGER journal_entries_immutable_posted
BEFORE UPDATE ON journal_entries
FOR EACH ROW
BEGIN
    IF OLD.status <> 'draft' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Posted journal entries are immutable.';
    END IF;
END$$
DELIMITER ;


-- ============================================================================
-- 2026_04_19_000006_create_ar_clearing_settlements_table

CREATE TABLE IF NOT EXISTS ar_clearing_settlements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  bank_account_id BIGINT UNSIGNED NOT NULL,
  settlement_method ENUM('card', 'cheque') NOT NULL,
  settlement_date DATE NOT NULL,
  amount_cents INT NOT NULL DEFAULT 0,
  client_uuid CHAR(36) NULL,
  reference VARCHAR(255) NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  voided_at TIMESTAMP NULL,
  voided_by BIGINT UNSIGNED NULL,
  void_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY ar_clearing_settlements_client_uuid_unique (client_uuid),
  KEY ar_clearing_settlements_company_date_idx (company_id, settlement_date),
  KEY ar_clearing_settlements_method_voided_idx (settlement_method, voided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2026_04_19_000007_create_ar_clearing_settlement_items_table

CREATE TABLE IF NOT EXISTS ar_clearing_settlement_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  settlement_id BIGINT UNSIGNED NOT NULL,
  payment_id BIGINT UNSIGNED NOT NULL,
  amount_cents INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_acs_item_payment (settlement_id, payment_id),
  KEY ar_clearing_settlement_items_payment_id_idx (payment_id),
  CONSTRAINT ar_clearing_settlement_items_settlement_fk FOREIGN KEY (settlement_id) REFERENCES ar_clearing_settlements(id),
  CONSTRAINT ar_clearing_settlement_items_payment_fk FOREIGN KEY (payment_id) REFERENCES payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================================
-- 2026_04_19_000008_create_ap_cheque_clearances_table
-- Assumes ap_payments.id is INT in the target schema.

CREATE TABLE IF NOT EXISTS ap_cheque_clearances (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id BIGINT UNSIGNED NOT NULL,
  bank_account_id BIGINT UNSIGNED NOT NULL,
  ap_payment_id INT NOT NULL,
  clearance_date DATE NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  client_uuid CHAR(36) NULL,
  reference VARCHAR(255) NULL,
  notes TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  voided_at TIMESTAMP NULL,
  voided_by BIGINT UNSIGNED NULL,
  void_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY ap_cheque_clearances_company_date_idx (company_id, clearance_date),
  KEY ap_cheque_clearances_ap_payment_id_idx (ap_payment_id),
  CONSTRAINT ap_cheque_clearances_ap_payment_id_foreign FOREIGN KEY (ap_payment_id) REFERENCES ap_payments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_cheque_clearances'
        AND column_name = 'active_sentinel'
    ),
    'SELECT 1',
    "ALTER TABLE ap_cheque_clearances ADD COLUMN active_sentinel TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_cheque_clearances'
        AND column_name = 'active_client_uuid'
    ),
    'SELECT 1',
    "ALTER TABLE ap_cheque_clearances ADD COLUMN active_client_uuid CHAR(36) AS (IF(voided_at IS NULL, client_uuid, NULL)) STORED"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_cheque_clearances'
        AND index_name = 'uq_apc_payment_active'
    ),
    'SELECT 1',
    "ALTER TABLE ap_cheque_clearances ADD UNIQUE INDEX uq_apc_payment_active (ap_payment_id, active_sentinel)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_cheque_clearances'
        AND index_name = 'uq_apc_client_uuid_active'
    ),
    'SELECT 1',
    "ALTER TABLE ap_cheque_clearances ADD UNIQUE INDEX uq_apc_client_uuid_active (active_client_uuid)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- 2026_04_19_000009_add_clearing_settled_at_to_payments

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'payments'
        AND column_name = 'clearing_settled_at'
    ),
    'SELECT 1',
    "ALTER TABLE payments ADD COLUMN clearing_settled_at TIMESTAMP NULL AFTER voided_at"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'payments'
        AND index_name = 'idx_payments_cleared'
    ),
    'SELECT 1',
    "ALTER TABLE payments ADD INDEX idx_payments_cleared (method, clearing_settled_at, voided_at)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- 2026_04_19_000010_add_cheque_cleared_at_to_ap_payments

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_payments'
        AND column_name = 'cheque_cleared_at'
    ),
    'SELECT 1',
    "ALTER TABLE ap_payments ADD COLUMN cheque_cleared_at TIMESTAMP NULL AFTER voided_at"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_payments'
        AND index_name = 'idx_ap_payments_cleared'
    ),
    'SELECT 1',
    "ALTER TABLE ap_payments ADD INDEX idx_ap_payments_cleared (payment_method, cheque_cleared_at, voided_at)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
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

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_cheque_clearances'
        AND column_name = 'active_client_uuid'
    ),
    'SELECT 1',
    "ALTER TABLE ap_cheque_clearances ADD COLUMN active_client_uuid CHAR(36) AS (IF(voided_at IS NULL, client_uuid, NULL)) STORED"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ap_cheque_clearances'
        AND index_name = 'uq_apc_client_uuid_active'
    ),
    'SELECT 1',
    "ALTER TABLE ap_cheque_clearances ADD UNIQUE INDEX uq_apc_client_uuid_active (active_client_uuid)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
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

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = 'ar_clearing_settlements'
        AND column_name = 'active_client_uuid'
    ),
    'SELECT 1',
    "ALTER TABLE ar_clearing_settlements ADD COLUMN active_client_uuid CHAR(36) AS (IF(voided_at IS NULL, client_uuid, NULL)) STORED"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = 'ar_clearing_settlements'
        AND index_name = 'uq_ars_client_uuid_active'
    ),
    'SELECT 1',
    "ALTER TABLE ar_clearing_settlements ADD UNIQUE INDEX uq_ars_client_uuid_active (active_client_uuid)"
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ============================================================================
-- 2026_04_23_000013_ensure_current_accounting_periods_exist
-- Creates fiscal years and monthly periods for the current and next year for active companies.

INSERT INTO fiscal_years (company_id, name, start_date, end_date, status, created_at, updated_at)
SELECT c.id,
       CONCAT('FY ', y.y) AS name,
       STR_TO_DATE(CONCAT(y.y, '-01-01'), '%Y-%m-%d') AS start_date,
       STR_TO_DATE(CONCAT(y.y, '-12-31'), '%Y-%m-%d') AS end_date,
       'open',
       NOW(),
       NOW()
FROM accounting_companies c
CROSS JOIN (
    SELECT YEAR(CURDATE()) AS y
    UNION ALL
    SELECT YEAR(CURDATE()) + 1 AS y
) y
LEFT JOIN fiscal_years fy
  ON fy.company_id = c.id
 AND fy.start_date = STR_TO_DATE(CONCAT(y.y, '-01-01'), '%Y-%m-%d')
WHERE c.is_active = 1
  AND fy.id IS NULL;

INSERT INTO accounting_periods (company_id, fiscal_year_id, name, period_number, start_date, end_date, status, created_at, updated_at)
SELECT c.id,
       fy.id,
       DATE_FORMAT(STR_TO_DATE(CONCAT(y.year_num, '-', LPAD(m.month_num, 2, '0'), '-01'), '%Y-%m-%d'), '%b %Y') AS name,
       m.month_num,
       STR_TO_DATE(CONCAT(y.year_num, '-', LPAD(m.month_num, 2, '0'), '-01'), '%Y-%m-%d') AS start_date,
       LAST_DAY(STR_TO_DATE(CONCAT(y.year_num, '-', LPAD(m.month_num, 2, '0'), '-01'), '%Y-%m-%d')) AS end_date,
       'open',
       NOW(),
       NOW()
FROM accounting_companies c
JOIN (
    SELECT YEAR(CURDATE()) AS year_num
    UNION ALL
    SELECT YEAR(CURDATE()) + 1 AS year_num
) y ON 1 = 1
JOIN fiscal_years fy
  ON fy.company_id = c.id
 AND fy.start_date = STR_TO_DATE(CONCAT(y.year_num, '-01-01'), '%Y-%m-%d')
JOIN (
    SELECT 1 AS month_num
    UNION ALL SELECT 2
    UNION ALL SELECT 3
    UNION ALL SELECT 4
    UNION ALL SELECT 5
    UNION ALL SELECT 6
    UNION ALL SELECT 7
    UNION ALL SELECT 8
    UNION ALL SELECT 9
    UNION ALL SELECT 10
    UNION ALL SELECT 11
    UNION ALL SELECT 12
) m ON 1 = 1
LEFT JOIN accounting_periods ap
  ON ap.company_id = c.id
 AND ap.fiscal_year_id = fy.id
 AND ap.period_number = m.month_num
WHERE c.is_active = 1
  AND ap.id IS NULL;


-- ============================================================================
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

