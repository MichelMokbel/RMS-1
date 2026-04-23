-- 2026_04_19_000003_add_payment_allocations_active_unique

ALTER TABLE payment_allocations
  ADD COLUMN IF NOT EXISTS alloc_active_sentinel TINYINT AS (IF(voided_at IS NULL, 1, NULL)) STORED;

ALTER TABLE payment_allocations
  ADD UNIQUE INDEX payment_allocations_active_unique (payment_id, allocatable_type, allocatable_id, alloc_active_sentinel);
