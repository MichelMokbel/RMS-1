-- Generated SQL: AR invoices upsert from sales-entry CSV
-- Source file: /Users/mohamadsafar/Desktop/Layla Kitchen/RMS-1/docs/csv/Sales_entry_dailyreport_2026-03-02_11_07PM.csv
-- Generated at: 2026-03-06T12:27:55
-- Date range (inclusive): 2026-03-01 to 2026-03-02
-- Branch ID: 1
-- Total CSV rows: 42
-- Filtered rows in range: 41
-- Filtered min date: 2026-03-01
-- Filtered max date: 2026-03-02
-- Distinct documents in range: 41
-- Distinct non-empty POS refs in range: 0
-- Distinct normalized customers in range: 30
-- Matching rules: invoice by (branch, invoice_number) then (branch, pos_reference); customer by normalized name
-- Rerunnable behavior: upsert invoice headers + replace items for touched invoices only

START TRANSACTION;

SET @inserted_customers := 0;
SET @inserted_invoice_rows := 0;
SET @updated_invoice_rows := 0;
SET @deleted_invoice_item_rows := 0;
SET @inserted_invoice_item_rows := 0;

DROP TEMPORARY TABLE IF EXISTS tmp_sales_source;
CREATE TEMPORARY TABLE tmp_sales_source (
  source_row_num INT NOT NULL,
  warehouse VARCHAR(100) NOT NULL,
  source_timestamp VARCHAR(40) NOT NULL,
  business_date DATE NOT NULL,
  document_no VARCHAR(64) NOT NULL,
  customer_name VARCHAR(191) NOT NULL,
  customer_norm VARCHAR(191) NOT NULL COLLATE utf8mb4_unicode_ci,
  pos_reference VARCHAR(191) DEFAULT NULL,
  subtotal_cents BIGINT NOT NULL,
  discount_cents BIGINT NOT NULL,
  total_cents BIGINT NOT NULL,
  cash_cents BIGINT NOT NULL,
  card_cents BIGINT NOT NULL,
  credit_cents BIGINT NOT NULL,
  payment_type VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL,
  paid_total_cents BIGINT NOT NULL,
  balance_cents BIGINT NOT NULL,
  PRIMARY KEY (source_row_num),
  UNIQUE KEY uq_tmp_sales_source_document_no (document_no),
  UNIQUE KEY uq_tmp_sales_source_pos_reference (pos_reference),
  KEY idx_tmp_sales_source_customer_norm (customer_norm),
  KEY idx_tmp_sales_source_business_date (business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_sales_source (source_row_num, warehouse, source_timestamp, business_date, document_no, customer_name, customer_norm, pos_reference, subtotal_cents, discount_cents, total_cents, cash_cents, card_cents, credit_cents, payment_type, status, paid_total_cents, balance_cents) VALUES
(2, 'Branch 1', '2026-03-01T14:32:11+03:00', '2026-03-01', '100753', 'PIA', 'pia', NULL, 19000, 0, 19000, 0, 0, 19000, 'credit', 'issued', 0, 19000),
(3, 'Branch 1', '2026-03-01T14:33:49+03:00', '2026-03-01', '100754', 'Ghinwa Bou Abdallah', 'ghinwa bou abdallah', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(4, 'Branch 1', '2026-03-01T14:34:33+03:00', '2026-03-01', '100755', 'Ramzi Joukhadar', 'ramzi joukhadar', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(5, 'Branch 1', '2026-03-01T14:35:21+03:00', '2026-03-01', '100756', 'chady', 'chady', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(6, 'Branch 1', '2026-03-01T14:36:01+03:00', '2026-03-01', '100757', 'Wael Fattouh', 'wael fattouh', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(7, 'Branch 1', '2026-03-01T14:36:43+03:00', '2026-03-01', '100758', 'MANAL', 'manal', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(8, 'Branch 1', '2026-03-01T14:37:23+03:00', '2026-03-01', '100759', 'Pamela', 'pamela', NULL, 15000, 0, 15000, 0, 0, 15000, 'credit', 'issued', 0, 15000),
(9, 'Branch 1', '2026-03-01T14:38:13+03:00', '2026-03-01', '100760', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(10, 'Branch 1', '2026-03-01T14:39:13+03:00', '2026-03-01', '100761', 'Joyce', 'joyce', NULL, 10000, 0, 10000, 0, 0, 10000, 'credit', 'issued', 0, 10000),
(11, 'Branch 1', '2026-03-01T14:39:52+03:00', '2026-03-01', '100762', 'Mohamad Al Jamal', 'mohamad al jamal', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(12, 'Branch 1', '2026-03-01T14:40:51+03:00', '2026-03-01', '100763', 'jackie', 'jackie', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(13, 'Branch 1', '2026-03-01T14:41:36+03:00', '2026-03-01', '100764', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(14, 'Branch 1', '2026-03-01T14:42:20+03:00', '2026-03-01', '100765', 'MARAH', 'marah', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(15, 'Branch 1', '2026-03-01T14:43:08+03:00', '2026-03-01', '100766', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(16, 'Branch 1', '2026-03-01T14:43:45+03:00', '2026-03-01', '100767', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 10500, 0, 10500, 0, 0, 10500, 'credit', 'issued', 0, 10500),
(17, 'Branch 1', '2026-03-01T14:44:59+03:00', '2026-03-01', '100768', 'Rawan Hachem', 'rawan hachem', NULL, 54500, 0, 54500, 0, 0, 54500, 'credit', 'issued', 0, 54500),
(18, 'Branch 1', '2026-03-01T15:29:11+03:00', '2026-03-01', '100769', 'Rola Talih', 'rola talih', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(19, 'Branch 1', '2026-03-01T15:30:30+03:00', '2026-03-01', '100770', 'Mayss Bitar', 'mayss bitar', NULL, 11000, 0, 11000, 0, 0, 11000, 'credit', 'issued', 0, 11000),
(20, 'Branch 1', '2026-03-01T15:32:34+03:00', '2026-03-01', '100771', 'VICKY', 'vicky', NULL, 28000, 0, 28000, 0, 0, 28000, 'credit', 'issued', 0, 28000),
(21, 'Branch 1', '2026-03-01T15:34:49+03:00', '2026-03-01', '100772', 'Dima Merhebi', 'dima merhebi', NULL, 39000, 0, 39000, 0, 0, 39000, 'credit', 'issued', 0, 39000),
(22, 'Branch 1', '2026-03-01T15:38:09+03:00', '2026-03-01', '100773', 'ABIR', 'abir', NULL, 37500, 0, 37500, 0, 0, 37500, 'credit', 'issued', 0, 37500),
(23, 'Branch 1', '2026-03-02T10:37:08+03:00', '2026-03-02', '100752', 'Marcelle', 'marcelle', NULL, 50000, 0, 50000, 0, 0, 50000, 'credit', 'issued', 0, 50000),
(24, 'Branch 1', '2026-03-02T15:59:54.613000+03:00', '2026-03-02', 'INV7675', 'jackie', 'jackie', NULL, 16500, 0, 16500, 0, 0, 16500, 'credit', 'issued', 0, 16500),
(25, 'Branch 1', '2026-03-02T16:00:17.447000+03:00', '2026-03-02', 'INV7676', 'Fadi El Jam', 'fadi el jam', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(26, 'Branch 1', '2026-03-02T16:00:24.741000+03:00', '2026-03-02', 'INV7677', 'PIA', 'pia', NULL, 20000, 0, 20000, 0, 0, 20000, 'credit', 'issued', 0, 20000),
(27, 'Branch 1', '2026-03-02T16:00:34.397000+03:00', '2026-03-02', 'INV7678', 'Rouba El Khoury', 'rouba el khoury', NULL, 5000, 0, 5000, 0, 0, 5000, 'credit', 'issued', 0, 5000),
(28, 'Branch 1', '2026-03-02T16:00:44.124000+03:00', '2026-03-02', 'INV7679', 'Antonio', 'antonio', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(29, 'Branch 1', '2026-03-02T16:00:59.810000+03:00', '2026-03-02', 'INV7680', 'Eliane Daccache', 'eliane daccache', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(30, 'Branch 1', '2026-03-02T16:01:15.577000+03:00', '2026-03-02', 'INV7681', 'GHADA MAALOUF', 'ghada maalouf', NULL, 16500, 0, 16500, 0, 0, 16500, 'credit', 'issued', 0, 16500),
(31, 'Branch 1', '2026-03-02T16:01:26.185000+03:00', '2026-03-02', 'INV7682', 'St Georges And Isaac Church', 'st georges and isaac church', NULL, 14000, 0, 14000, 0, 0, 14000, 'credit', 'issued', 0, 14000),
(32, 'Branch 1', '2026-03-02T16:01:40.526000+03:00', '2026-03-02', 'INV7683', 'Youssef R', 'youssef r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(33, 'Branch 1', '2026-03-02T16:01:47.094000+03:00', '2026-03-02', 'INV7684', 'Melody R', 'melody r', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(34, 'Branch 1', '2026-03-02T16:01:56.121000+03:00', '2026-03-02', 'INV7685', 'Saeed Zeidan', 'saeed zeidan', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(35, 'Branch 1', '2026-03-02T16:02:08.849000+03:00', '2026-03-02', 'INV7686', 'MANAL', 'manal', NULL, 16500, 0, 16500, 0, 0, 16500, 'credit', 'issued', 0, 16500),
(36, 'Branch 1', '2026-03-02T16:02:17.740000+03:00', '2026-03-02', 'INV7687', 'Ramzi Joukhadar', 'ramzi joukhadar', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(37, 'Branch 1', '2026-03-02T16:02:25.490000+03:00', '2026-03-02', 'INV7688', 'Ghinwa Bou Abdallah', 'ghinwa bou abdallah', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(38, 'Branch 1', '2026-03-02T16:02:32.191000+03:00', '2026-03-02', 'INV7689', 'Roger Abou Malhab', 'roger abou malhab', NULL, 4230, 0, 4230, 0, 0, 4230, 'credit', 'issued', 0, 4230),
(39, 'Branch 1', '2026-03-02T16:02:44.945000+03:00', '2026-03-02', 'INV7690', 'Jihad Abou Chabke', 'jihad abou chabke', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(40, 'Branch 1', '2026-03-02T16:02:55.297000+03:00', '2026-03-02', 'INV7691', 'Mohamad Al Jamal', 'mohamad al jamal', NULL, 4500, 0, 4500, 0, 0, 4500, 'credit', 'issued', 0, 4500),
(41, 'Branch 1', '2026-03-02T16:03:21.441000+03:00', '2026-03-02', 'INV7692', 'Wael Fattouh', 'wael fattouh', NULL, 4000, 0, 4000, 0, 0, 4000, 'credit', 'issued', 0, 4000),
(42, 'Branch 1', '2026-03-02T16:04:25.606000+03:00', '2026-03-02', 'INV7693', 'Umm Khaled', 'umm khaled', NULL, 62400, 0, 62400, 0, 0, 62400, 'credit', 'issued', 0, 62400);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_source;
CREATE TEMPORARY TABLE tmp_customer_source AS
SELECT
  customer_norm,
  MIN(customer_name) AS customer_name
FROM tmp_sales_source
GROUP BY customer_norm;
ALTER TABLE tmp_customer_source
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_name_counts;
CREATE TEMPORARY TABLE tmp_customer_name_counts AS
SELECT
  LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci AS customer_norm,
  COUNT(*) AS target_count
FROM customers c
GROUP BY LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci;
ALTER TABLE tmp_customer_name_counts
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_unique_names;
CREATE TEMPORARY TABLE tmp_customer_unique_names AS
SELECT
  cc.customer_norm,
  c.id AS customer_id
FROM tmp_customer_name_counts cc
JOIN customers c
  ON LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci = cc.customer_norm
WHERE cc.target_count = 1;
ALTER TABLE tmp_customer_unique_names
  ADD PRIMARY KEY (customer_norm),
  ADD KEY idx_tmp_customer_unique_names_customer_id (customer_id);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_ambiguous_names;
CREATE TEMPORARY TABLE tmp_customer_ambiguous_names AS
SELECT customer_norm, target_count
FROM tmp_customer_name_counts
WHERE target_count > 1;
ALTER TABLE tmp_customer_ambiguous_names
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_missing_customers;
CREATE TEMPORARY TABLE tmp_missing_customers AS
SELECT
  s.customer_norm,
  s.customer_name
FROM tmp_customer_source s
LEFT JOIN tmp_customer_name_counts c ON c.customer_norm = s.customer_norm
WHERE c.customer_norm IS NULL;
ALTER TABLE tmp_missing_customers
  ADD PRIMARY KEY (customer_norm);

SET @next_customer_num := (
  SELECT COALESCE(MAX(CAST(SUBSTRING(customer_code, 6) AS UNSIGNED)), 0) + 1
  FROM customers
  WHERE customer_code REGEXP '^CUST-[0-9]+$'
);
SET @customer_row_num := 0;
INSERT INTO customers (
  customer_code,
  name,
  customer_type,
  credit_limit,
  is_active,
  created_at,
  updated_at
)
SELECT
  CONCAT('CUST-', LPAD(CAST(@next_customer_num + (@customer_row_num := @customer_row_num + 1) - 1 AS CHAR), 4, '0')) AS customer_code,
  m.customer_name AS name,
  'retail' AS customer_type,
  0.000 AS credit_limit,
  1 AS is_active,
  NOW() AS created_at,
  NOW() AS updated_at
FROM tmp_missing_customers m
ORDER BY m.customer_norm;
SET @inserted_customers := ROW_COUNT();

DROP TEMPORARY TABLE IF EXISTS tmp_customer_name_counts_final;
CREATE TEMPORARY TABLE tmp_customer_name_counts_final AS
SELECT
  LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci AS customer_norm,
  COUNT(*) AS target_count
FROM customers c
GROUP BY LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci;
ALTER TABLE tmp_customer_name_counts_final
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_unique_names_final;
CREATE TEMPORARY TABLE tmp_customer_unique_names_final AS
SELECT
  cc.customer_norm,
  c.id AS customer_id
FROM tmp_customer_name_counts_final cc
JOIN customers c
  ON LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci = cc.customer_norm
WHERE cc.target_count = 1;
ALTER TABLE tmp_customer_unique_names_final
  ADD PRIMARY KEY (customer_norm),
  ADD KEY idx_tmp_customer_unique_names_final_customer_id (customer_id);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_ambiguous_names_final;
CREATE TEMPORARY TABLE tmp_customer_ambiguous_names_final AS
SELECT customer_norm, target_count
FROM tmp_customer_name_counts_final
WHERE target_count > 1;
ALTER TABLE tmp_customer_ambiguous_names_final
  ADD PRIMARY KEY (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_sales_customer_resolution;
CREATE TEMPORARY TABLE tmp_sales_customer_resolution AS
SELECT
  s.source_row_num,
  s.customer_norm,
  cu.customer_id,
  CASE
    WHEN ca.customer_norm IS NOT NULL THEN 'ambiguous'
    WHEN cu.customer_id IS NULL THEN 'missing'
    ELSE 'resolved'
  END AS customer_resolution
FROM tmp_sales_source s
LEFT JOIN tmp_customer_unique_names_final cu ON cu.customer_norm = s.customer_norm
LEFT JOIN tmp_customer_ambiguous_names_final ca ON ca.customer_norm = s.customer_norm;
ALTER TABLE tmp_sales_customer_resolution
  ADD PRIMARY KEY (source_row_num),
  ADD KEY idx_tmp_sales_customer_resolution_state (customer_resolution),
  ADD KEY idx_tmp_sales_customer_resolution_customer_id (customer_id);

DROP TEMPORARY TABLE IF EXISTS tmp_invoice_resolution;
CREATE TEMPORARY TABLE tmp_invoice_resolution AS
SELECT
  s.source_row_num,
  s.document_no,
  s.pos_reference,
  cr.customer_id,
  cr.customer_resolution,
  inv_num.id AS invoice_by_number_id,
  inv_pos.id AS invoice_by_pos_id,
  CASE
    WHEN cr.customer_resolution <> 'resolved' THEN 'skip_customer'
    WHEN inv_num.id IS NOT NULL AND inv_pos.id IS NOT NULL AND inv_num.id <> inv_pos.id THEN 'skip_conflict'
    WHEN inv_num.id IS NULL AND inv_pos.id IS NULL THEN 'insert'
    ELSE 'update'
  END AS resolution_status,
  COALESCE(inv_num.id, inv_pos.id) AS resolved_invoice_id
FROM tmp_sales_source s
JOIN tmp_sales_customer_resolution cr ON cr.source_row_num = s.source_row_num
LEFT JOIN ar_invoices inv_num
  ON inv_num.branch_id = 1
  AND inv_num.type = 'invoice'
  AND inv_num.invoice_number COLLATE utf8mb4_unicode_ci = s.document_no COLLATE utf8mb4_unicode_ci
LEFT JOIN ar_invoices inv_pos
  ON inv_pos.branch_id = 1
  AND inv_pos.type = 'invoice'
  AND s.pos_reference IS NOT NULL
  AND inv_pos.pos_reference COLLATE utf8mb4_unicode_ci = s.pos_reference COLLATE utf8mb4_unicode_ci;
ALTER TABLE tmp_invoice_resolution
  ADD PRIMARY KEY (source_row_num),
  ADD KEY idx_tmp_invoice_resolution_status (resolution_status),
  ADD KEY idx_tmp_invoice_resolution_invoice_id (resolved_invoice_id);

UPDATE ar_invoices ai
JOIN tmp_invoice_resolution r
  ON r.resolution_status = 'update'
 AND r.resolved_invoice_id = ai.id
JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num
SET
  ai.customer_id = r.customer_id,
  ai.source = 'import',
  ai.type = 'invoice',
  ai.invoice_number = s.document_no,
  ai.status = s.status,
  ai.payment_type = s.payment_type,
  ai.issue_date = s.business_date,
  ai.due_date = s.business_date,
  ai.currency = 'QAR',
  ai.subtotal_cents = s.subtotal_cents,
  ai.discount_total_cents = s.discount_cents,
  ai.invoice_discount_type = 'fixed',
  ai.invoice_discount_value = s.discount_cents,
  ai.invoice_discount_cents = s.discount_cents,
  ai.tax_total_cents = 0,
  ai.total_cents = s.total_cents,
  ai.paid_total_cents = s.paid_total_cents,
  ai.balance_cents = s.balance_cents,
  ai.pos_reference = s.pos_reference,
  ai.created_at = COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), ai.created_at),
  ai.updated_at = COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), ai.updated_at);
SET @updated_invoice_rows := ROW_COUNT();

INSERT INTO ar_invoices (
  branch_id,
  customer_id,
  source,
  type,
  invoice_number,
  status,
  payment_type,
  issue_date,
  due_date,
  currency,
  subtotal_cents,
  discount_total_cents,
  invoice_discount_type,
  invoice_discount_value,
  invoice_discount_cents,
  tax_total_cents,
  total_cents,
  paid_total_cents,
  balance_cents,
  pos_reference,
  notes,
  created_at,
  updated_at
)
SELECT
  1 AS branch_id,
  r.customer_id,
  'import' AS source,
  'invoice' AS type,
  s.document_no AS invoice_number,
  s.status,
  s.payment_type,
  s.business_date AS issue_date,
  s.business_date AS due_date,
  'QAR' AS currency,
  s.subtotal_cents,
  s.discount_cents AS discount_total_cents,
  'fixed' AS invoice_discount_type,
  s.discount_cents AS invoice_discount_value,
  s.discount_cents AS invoice_discount_cents,
  0 AS tax_total_cents,
  s.total_cents,
  s.paid_total_cents,
  s.balance_cents,
  s.pos_reference,
  'Imported from Sales Entry Daily Report' AS notes,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS created_at,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS updated_at
FROM tmp_invoice_resolution r
JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num
WHERE r.resolution_status = 'insert'
ORDER BY s.source_row_num;
SET @inserted_invoice_rows := ROW_COUNT();

DROP TEMPORARY TABLE IF EXISTS tmp_target_invoice_ids;
CREATE TEMPORARY TABLE tmp_target_invoice_ids AS
SELECT
  r.source_row_num,
  CASE
    WHEN r.resolution_status = 'update' THEN r.resolved_invoice_id
    ELSE ai.id
  END AS invoice_id
FROM tmp_invoice_resolution r
JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num
LEFT JOIN ar_invoices ai
  ON r.resolution_status = 'insert'
 AND ai.branch_id = 1
 AND ai.type = 'invoice'
 AND ai.invoice_number COLLATE utf8mb4_unicode_ci = s.document_no COLLATE utf8mb4_unicode_ci
WHERE r.resolution_status IN ('insert', 'update');
ALTER TABLE tmp_target_invoice_ids
  ADD PRIMARY KEY (source_row_num),
  ADD KEY idx_tmp_target_invoice_ids_invoice_id (invoice_id);

DELETE ii
FROM ar_invoice_items ii
JOIN (SELECT DISTINCT invoice_id FROM tmp_target_invoice_ids) t
  ON t.invoice_id = ii.invoice_id;
SET @deleted_invoice_item_rows := ROW_COUNT();

INSERT INTO ar_invoice_items (
  invoice_id,
  description,
  qty,
  unit_price_cents,
  discount_cents,
  tax_cents,
  line_total_cents,
  created_at,
  updated_at
)
SELECT
  t.invoice_id,
  'Legacy import' AS description,
  1.000 AS qty,
  s.total_cents AS unit_price_cents,
  0 AS discount_cents,
  0 AS tax_cents,
  s.total_cents AS line_total_cents,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS created_at,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(s.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS updated_at
FROM tmp_target_invoice_ids t
JOIN tmp_sales_source s ON s.source_row_num = t.source_row_num
ORDER BY t.source_row_num;
SET @inserted_invoice_item_rows := ROW_COUNT();

SET @source_rows_loaded := (SELECT COUNT(*) FROM tmp_sales_source);
SET @source_distinct_customers := (SELECT COUNT(*) FROM tmp_customer_source);
SET @skipped_conflict_rows := (
  SELECT COUNT(*) FROM tmp_invoice_resolution WHERE resolution_status = 'skip_conflict'
);
SET @skipped_customer_rows := (
  SELECT COUNT(*) FROM tmp_invoice_resolution WHERE resolution_status = 'skip_customer'
);

-- Summary
SELECT
  @source_rows_loaded AS source_rows_loaded,
  @source_distinct_customers AS source_distinct_customers,
  @inserted_customers AS inserted_customers,
  @inserted_invoice_rows AS inserted_invoices,
  @updated_invoice_rows AS updated_invoices,
  @skipped_conflict_rows AS skipped_conflict_rows,
  @skipped_customer_rows AS skipped_customer_rows,
  @deleted_invoice_item_rows AS deleted_existing_invoice_items,
  @inserted_invoice_item_rows AS inserted_invoice_items;

-- Skipped rows due to invoice-number/POS-reference conflicts
SELECT
  source_row_num,
  document_no,
  pos_reference,
  invoice_by_number_id,
  invoice_by_pos_id
FROM tmp_invoice_resolution
WHERE resolution_status = 'skip_conflict'
ORDER BY source_row_num;

-- Skipped rows due to unresolved customer matching
SELECT
  r.source_row_num,
  s.customer_name,
  s.customer_norm,
  cr.customer_resolution
FROM tmp_invoice_resolution r
JOIN tmp_sales_source s ON s.source_row_num = r.source_row_num
JOIN tmp_sales_customer_resolution cr ON cr.source_row_num = r.source_row_num
WHERE r.resolution_status = 'skip_customer'
ORDER BY r.source_row_num;

-- Breakdown by mapped status/payment type in the source range
SELECT
  status,
  payment_type,
  COUNT(*) AS row_count
FROM tmp_sales_source
GROUP BY status, payment_type
ORDER BY status, payment_type;

-- ROLLBACK; -- Uncomment for dry-run safety.
COMMIT;
