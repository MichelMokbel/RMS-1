-- 2026_04_19_000010_add_cheque_cleared_at_to_ap_payments

ALTER TABLE ap_payments
  ADD COLUMN IF NOT EXISTS cheque_cleared_at TIMESTAMP NULL AFTER voided_at;

ALTER TABLE ap_payments
  ADD INDEX idx_ap_payments_cleared (payment_method, cheque_cleared_at, voided_at);
