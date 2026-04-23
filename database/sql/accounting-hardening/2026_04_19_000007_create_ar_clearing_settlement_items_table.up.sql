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
