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
