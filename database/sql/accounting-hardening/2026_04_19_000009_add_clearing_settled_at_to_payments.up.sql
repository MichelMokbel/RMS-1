-- 2026_04_19_000009_add_clearing_settled_at_to_payments

ALTER TABLE payments
  ADD COLUMN IF NOT EXISTS clearing_settled_at TIMESTAMP NULL AFTER voided_at;

ALTER TABLE payments
  ADD INDEX idx_payments_cleared (method, clearing_settled_at, voided_at);
