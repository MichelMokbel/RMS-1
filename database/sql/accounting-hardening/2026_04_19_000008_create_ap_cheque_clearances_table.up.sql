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

ALTER TABLE ap_cheque_clearances
  ADD COLUMN IF NOT EXISTS active_sentinel TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED;

ALTER TABLE ap_cheque_clearances
  ADD COLUMN IF NOT EXISTS active_client_uuid CHAR(36) AS (IF(voided_at IS NULL, client_uuid, NULL)) STORED;

ALTER TABLE ap_cheque_clearances
  ADD UNIQUE INDEX uq_apc_payment_active (ap_payment_id, active_sentinel);

ALTER TABLE ap_cheque_clearances
  ADD UNIQUE INDEX uq_apc_client_uuid_active (active_client_uuid);
