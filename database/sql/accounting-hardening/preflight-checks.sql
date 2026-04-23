-- Accounting hardening preflight checks
-- Review result sets before running all-up.sql.

-- Duplicate AP payment idempotency keys
SELECT client_uuid, COUNT(*) AS dup_count
FROM ap_payments
WHERE client_uuid IS NOT NULL
GROUP BY client_uuid
HAVING COUNT(*) > 1;

-- Duplicate bank transaction source rows
SELECT source_type, source_id, transaction_type, COUNT(*) AS dup_count
FROM bank_transactions
WHERE source_type IS NOT NULL
  AND source_id IS NOT NULL
  AND transaction_type IS NOT NULL
GROUP BY source_type, source_id, transaction_type
HAVING COUNT(*) > 1;

-- Duplicate subledger source/event rows
SELECT source_type, source_id, event, COUNT(*) AS dup_count
FROM subledger_entries
WHERE source_type IS NOT NULL
  AND source_id IS NOT NULL
  AND event IS NOT NULL
GROUP BY source_type, source_id, event
HAVING COUNT(*) > 1;

-- Duplicate active AR payment allocations
SELECT payment_id, allocatable_type, allocatable_id, COUNT(*) AS dup_count
FROM payment_allocations
WHERE voided_at IS NULL
GROUP BY payment_id, allocatable_type, allocatable_id
HAVING COUNT(*) > 1;

-- Duplicate active AP payment allocations
SELECT payment_id, invoice_id, COUNT(*) AS dup_count
FROM ap_payment_allocations
WHERE voided_at IS NULL
GROUP BY payment_id, invoice_id
HAVING COUNT(*) > 1;

-- Duplicate active AP cheque clearance client UUIDs
SELECT client_uuid, COUNT(*) AS dup_count
FROM ap_cheque_clearances
WHERE voided_at IS NULL
  AND client_uuid IS NOT NULL
GROUP BY client_uuid
HAVING COUNT(*) > 1;

-- Duplicate active AR settlement client UUIDs
SELECT client_uuid, COUNT(*) AS dup_count
FROM ar_clearing_settlements
WHERE voided_at IS NULL
  AND client_uuid IS NOT NULL
GROUP BY client_uuid
HAVING COUNT(*) > 1;
