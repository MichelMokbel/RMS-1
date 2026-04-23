-- 2026_04_19_000001_harden_accounting_payment_idempotency_and_bank_uniqueness

ALTER TABLE ap_payments
  ADD COLUMN IF NOT EXISTS client_uuid CHAR(36) NULL AFTER supplier_id;

ALTER TABLE ap_payments
  ADD UNIQUE INDEX ap_payments_client_uuid_unique (client_uuid);

ALTER TABLE bank_transactions
  ADD UNIQUE INDEX bank_transactions_source_unique (source_type, source_id, transaction_type);
