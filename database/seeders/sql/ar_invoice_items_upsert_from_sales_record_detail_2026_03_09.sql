-- Generated SQL: AR invoice item-lines upsert from sales-record-detail CSV
-- Source file: /Users/mohamadsafar/Desktop/Layla Kitchen/RMS-1/docs/csv/data/Sales_record_detail_09_03_2026.csv
-- Generated at: 2026-03-09T11:35:17
-- Branch ID: 1
-- Date filter (inclusive): 2026-03-09 to 2026-03-09
-- Total CSV rows: 20
-- Loaded line rows: 20
-- Distinct invoices: 19
-- Skipped rows with missing Invoice No: 0
-- Negative-qty rows: 0
-- Negative-total rows: 0
-- Loaded min date: 2026-03-09
-- Loaded max date: 2026-03-09
-- Matching: existing invoice by (branch, invoice_number), fallback (branch, pos_reference)
-- Existing non-voided invoice lines replaced only when grouped CSV invoice total equals existing invoice total
-- Missing invoices are created; missing customers are auto-created when unique by normalized name

START TRANSACTION;

SET @inserted_customers := 0;
SET @created_invoice_rows := 0;
SET @deleted_invoice_item_rows := 0;
SET @inserted_invoice_item_rows := 0;

DROP TEMPORARY TABLE IF EXISTS tmp_sales_record_detail_lines;
CREATE TEMPORARY TABLE tmp_sales_record_detail_lines (
  source_row_num INT NOT NULL,
  invoice_number VARCHAR(64) NOT NULL,
  invoice_number_norm VARCHAR(64) NOT NULL COLLATE utf8mb4_unicode_ci,
  pos_reference VARCHAR(191) DEFAULT NULL,
  source_timestamp VARCHAR(40) NOT NULL,
  business_date DATE NOT NULL,
  customer_name VARCHAR(191) NOT NULL,
  customer_norm VARCHAR(191) NOT NULL COLLATE utf8mb4_unicode_ci,
  warehouse VARCHAR(100) NOT NULL,
  payment_type VARCHAR(20) NOT NULL,
  item_name VARCHAR(255) NOT NULL,
  unit VARCHAR(40) DEFAULT NULL,
  qty DECIMAL(12,3) NOT NULL,
  unit_price_cents BIGINT NOT NULL,
  line_discount_cents BIGINT NOT NULL,
  line_total_cents BIGINT NOT NULL,
  PRIMARY KEY (source_row_num),
  KEY idx_tmp_srdl_invoice_norm (invoice_number_norm),
  KEY idx_tmp_srdl_customer_norm (customer_norm),
  KEY idx_tmp_srdl_pos_reference (pos_reference),
  KEY idx_tmp_srdl_business_date (business_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_sales_record_detail_lines (source_row_num, invoice_number, invoice_number_norm, pos_reference, source_timestamp, business_date, customer_name, customer_norm, warehouse, payment_type, item_name, unit, qty, unit_price_cents, line_discount_cents, line_total_cents) VALUES
(2, 'INV7801', 'inv7801', '1037824', '2026-03-09T13:05:00.153000', '2026-03-09', 'Chirine Ayache', 'chirine ayache', 'Branch 1', 'credit', 'Catering Event', 'EA', 6.000, 15000, 0, 90000),
(3, 'INV7801', 'inv7801', '1037824', '2026-03-09T13:05:00.153000', '2026-03-09', 'Chirine Ayache', 'chirine ayache', 'Branch 1', 'credit', 'Delivery Charge', 'EA', 1.000, 5000, 0, 5000),
(4, 'INV7802', 'inv7802', '1037825', '2026-03-09T13:05:56.712000', '2026-03-09', 'jackie', 'jackie', 'Branch 1', 'credit', 'Daily Dish 1', 'EA', 2.000, 5500, 0, 11000),
(5, 'INV7803', 'inv7803', '1037826', '2026-03-09T13:06:04.697000', '2026-03-09', 'Fadi El Jam', 'fadi el jam', 'Branch 1', 'credit', 'Daily dish M.S.', 'EA', 1.000, 4000, 0, 4000),
(6, 'INV7804', 'inv7804', '1037827', '2026-03-09T13:06:13.237000', '2026-03-09', 'PIA', 'pia', 'Branch 1', 'credit', 'Main Dish 1 Portion', 'EA', 1.000, 20000, 0, 20000),
(7, 'INV7805', 'inv7805', '1037828', '2026-03-09T13:06:21.350000', '2026-03-09', 'Antonio', 'antonio', 'Branch 1', 'credit', 'Daily dish M.S.', 'EA', 1.000, 4000, 0, 4000),
(8, 'INV7806', 'inv7806', '1037829', '2026-03-09T13:06:29.958000', '2026-03-09', 'GHADA MAALOUF', 'ghada maalouf', 'Branch 1', 'credit', 'Daily Dish 1', 'EA', 2.000, 5500, 0, 11000),
(9, 'INV7807', 'inv7807', '1037830', '2026-03-09T13:06:37.338000', '2026-03-09', 'Roger Abou Malhab', 'roger abou malhab', 'Branch 1', 'credit', 'Daily Dish Monthly 26 Days', 'EA', 1.000, 4230, 0, 4230),
(10, 'INV7808', 'inv7808', '1037831', '2026-03-09T13:06:47.643000', '2026-03-09', 'St Georges And Isaac Church', 'st georges and isaac church', 'Branch 1', 'credit', 'Daily Dish 2', 'EA', 4.000, 3500, 0, 14000),
(11, 'INV7809', 'inv7809', '1037832', '2026-03-09T13:07:04.826000', '2026-03-09', 'Eliane Daccache', 'eliane daccache', 'Branch 1', 'credit', 'Daily dish M.S.', 'EA', 1.000, 4000, 0, 4000),
(12, 'INV7810', 'inv7810', '1037833', '2026-03-09T13:07:14.373000', '2026-03-09', 'Wael Fattouh', 'wael fattouh', 'Branch 1', 'credit', 'Daily dish M.S.', 'EA', 1.000, 4000, 0, 4000),
(13, 'INV7811', 'inv7811', '1037834', '2026-03-09T13:07:24.529000', '2026-03-09', 'Mohamad Al Jamal', 'mohamad al jamal', 'Branch 1', 'credit', 'Iftar Box', 'EA', 1.000, 4500, 0, 4500),
(14, 'INV7812', 'inv7812', '1037835', '2026-03-09T13:07:33.655000', '2026-03-09', 'Youssef R', 'youssef r', 'Branch 1', 'credit', 'Daily dish M.S.', 'EA', 1.000, 4000, 0, 4000),
(15, 'INV7813', 'inv7813', '1037836', '2026-03-09T13:07:40.151000', '2026-03-09', 'Melody R', 'melody r', 'Branch 1', 'credit', 'Daily dish M.S.', 'EA', 1.000, 4000, 0, 4000),
(16, 'INV7814', 'inv7814', '1037837', '2026-03-09T13:07:53.410000', '2026-03-09', 'Ramzi Joukhadar', 'ramzi joukhadar', 'Branch 1', 'credit', 'Daily dish M.S.', 'EA', 1.000, 4000, 0, 4000),
(17, 'INV7815', 'inv7815', '1037838', '2026-03-09T13:08:00.799000', '2026-03-09', 'Ghinwa Bou Abdallah', 'ghinwa bou abdallah', 'Branch 1', 'credit', 'Daily dish M.S.', 'EA', 1.000, 4000, 0, 4000),
(18, 'INV7816', 'inv7816', '1037839', '2026-03-09T13:08:07.221000', '2026-03-09', 'chady', 'chady', 'Branch 1', 'credit', 'Daily Dish 1', 'EA', 2.000, 5500, 0, 11000),
(19, 'INV7817', 'inv7817', '1037840', '2026-03-09T13:08:20.552000', '2026-03-09', 'waad', 'waad', 'Branch 1', 'credit', 'Warak 3inab Meat', 'EA', 1.000, 32000, 0, 32000),
(20, 'INV7818', 'inv7818', '1037841', '2026-03-09T13:08:53.752000', '2026-03-09', 'alaa', 'alaa', 'Branch 1', 'credit', 'Warak 3inab Meat', 'EA', 2.000, 15000, 0, 30000),
(21, 'INV7819', 'inv7819', '1037842', '2026-03-09T13:09:03.928000', '2026-03-09', 'Saeed Zeidan', 'saeed zeidan', 'Branch 1', 'credit', 'Daily dish M.S.', 'EA', 1.000, 4000, 0, 4000);

DROP TEMPORARY TABLE IF EXISTS tmp_sales_record_detail_invoice_groups;
CREATE TEMPORARY TABLE tmp_sales_record_detail_invoice_groups AS
SELECT
  MIN(source_row_num) AS group_row_num,
  MIN(invoice_number) AS invoice_number,
  invoice_number_norm,
  MIN(pos_reference) AS pos_reference,
  MIN(source_timestamp) AS source_timestamp,
  MIN(business_date) AS business_date,
  MIN(customer_name) AS customer_name,
  MIN(customer_norm) AS customer_norm,
  MIN(payment_type) AS payment_type,
  SUM(line_total_cents + line_discount_cents) AS subtotal_cents,
  SUM(line_discount_cents) AS discount_cents,
  SUM(line_total_cents) AS total_cents,
  COUNT(*) AS line_count
FROM tmp_sales_record_detail_lines
GROUP BY invoice_number_norm;
ALTER TABLE tmp_sales_record_detail_invoice_groups
  ADD PRIMARY KEY (group_row_num),
  ADD UNIQUE KEY uq_tmp_srdig_invoice_norm (invoice_number_norm),
  ADD KEY idx_tmp_srdig_invoice_number (invoice_number),
  ADD KEY idx_tmp_srdig_customer_norm (customer_norm);

DROP TEMPORARY TABLE IF EXISTS tmp_customer_source;
CREATE TEMPORARY TABLE tmp_customer_source AS
SELECT customer_norm, MIN(customer_name) AS customer_name
FROM tmp_sales_record_detail_invoice_groups
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

DROP TEMPORARY TABLE IF EXISTS tmp_missing_customers;
CREATE TEMPORARY TABLE tmp_missing_customers AS
SELECT s.customer_norm, s.customer_name
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

DROP TEMPORARY TABLE IF EXISTS tmp_group_customer_resolution;
CREATE TEMPORARY TABLE tmp_group_customer_resolution AS
SELECT
  g.group_row_num,
  g.invoice_number_norm,
  cu.customer_id,
  CASE
    WHEN ca.customer_norm IS NOT NULL THEN 'ambiguous'
    WHEN cu.customer_id IS NULL THEN 'missing'
    ELSE 'resolved'
  END AS customer_resolution
FROM tmp_sales_record_detail_invoice_groups g
LEFT JOIN tmp_customer_unique_names_final cu ON cu.customer_norm = g.customer_norm
LEFT JOIN tmp_customer_ambiguous_names_final ca ON ca.customer_norm = g.customer_norm;
ALTER TABLE tmp_group_customer_resolution
  ADD PRIMARY KEY (group_row_num),
  ADD KEY idx_tmp_group_customer_resolution_state (customer_resolution),
  ADD KEY idx_tmp_group_customer_resolution_customer_id (customer_id);

DROP TEMPORARY TABLE IF EXISTS tmp_invoice_match;
CREATE TEMPORARY TABLE tmp_invoice_match AS
SELECT
  g.group_row_num,
  g.invoice_number,
  g.invoice_number_norm,
  g.pos_reference,
  g.source_timestamp,
  g.business_date,
  g.customer_name,
  g.customer_norm,
  g.payment_type,
  g.subtotal_cents,
  g.discount_cents,
  g.total_cents AS grouped_total_cents,
  g.line_count,
  cr.customer_id,
  cr.customer_resolution,
  inv_num.id AS invoice_by_number_id,
  inv_num.status AS invoice_by_number_status,
  inv_num.total_cents AS invoice_by_number_total_cents,
  inv_pos.id AS invoice_by_pos_id,
  inv_pos.status AS invoice_by_pos_status,
  inv_pos.total_cents AS invoice_by_pos_total_cents,
  COALESCE(inv_num.id, inv_pos.id) AS resolved_invoice_id,
  COALESCE(inv_num.status, inv_pos.status) AS resolved_invoice_status,
  COALESCE(inv_num.total_cents, inv_pos.total_cents) AS resolved_invoice_total_cents,
  CASE
    WHEN inv_num.id IS NOT NULL AND inv_pos.id IS NOT NULL AND inv_num.id <> inv_pos.id THEN 'skip_conflict'
    WHEN COALESCE(inv_num.status, inv_pos.status) = 'voided' THEN 'skip_voided'
    WHEN inv_num.id IS NULL AND inv_pos.id IS NULL AND cr.customer_resolution <> 'resolved' THEN 'skip_customer'
    WHEN inv_num.id IS NULL AND inv_pos.id IS NULL THEN 'create_new'
    ELSE 'matched'
  END AS resolution_status
FROM tmp_sales_record_detail_invoice_groups g
LEFT JOIN tmp_group_customer_resolution cr ON cr.group_row_num = g.group_row_num
LEFT JOIN ar_invoices inv_num
  ON inv_num.branch_id = 1
  AND inv_num.type = 'invoice'
  AND inv_num.invoice_number COLLATE utf8mb4_unicode_ci = g.invoice_number COLLATE utf8mb4_unicode_ci
LEFT JOIN ar_invoices inv_pos
  ON inv_pos.branch_id = 1
  AND inv_pos.type = 'invoice'
  AND g.pos_reference IS NOT NULL
  AND inv_pos.pos_reference COLLATE utf8mb4_unicode_ci = g.pos_reference COLLATE utf8mb4_unicode_ci;
ALTER TABLE tmp_invoice_match
  ADD PRIMARY KEY (group_row_num),
  ADD KEY idx_tmp_invoice_match_status (resolution_status),
  ADD KEY idx_tmp_invoice_match_invoice_id (resolved_invoice_id);

DROP TEMPORARY TABLE IF EXISTS tmp_matched_total_check;
CREATE TEMPORARY TABLE tmp_matched_total_check AS
SELECT
  m.group_row_num,
  m.invoice_number,
  m.resolved_invoice_id,
  m.grouped_total_cents,
  m.resolved_invoice_total_cents,
  CASE
    WHEN m.grouped_total_cents = m.resolved_invoice_total_cents THEN 'ok'
    ELSE 'skip_total_mismatch'
  END AS total_status
FROM tmp_invoice_match m
WHERE m.resolution_status = 'matched';
ALTER TABLE tmp_matched_total_check
  ADD PRIMARY KEY (group_row_num),
  ADD KEY idx_tmp_matched_total_check_status (total_status),
  ADD KEY idx_tmp_matched_total_check_invoice_id (resolved_invoice_id);

DROP TEMPORARY TABLE IF EXISTS tmp_replace_targets;
CREATE TEMPORARY TABLE tmp_replace_targets AS
SELECT
  m.group_row_num,
  m.invoice_number_norm,
  m.invoice_number,
  m.resolved_invoice_id AS invoice_id
FROM tmp_invoice_match m
LEFT JOIN tmp_matched_total_check tc ON tc.group_row_num = m.group_row_num
WHERE m.resolution_status = 'matched'
  AND COALESCE(tc.total_status, 'skip_total_mismatch') = 'ok';
ALTER TABLE tmp_replace_targets
  ADD PRIMARY KEY (group_row_num),
  ADD KEY idx_tmp_replace_targets_invoice_id (invoice_id);

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
  m.customer_id,
  'import' AS source,
  'invoice' AS type,
  m.invoice_number AS invoice_number,
  CASE WHEN m.payment_type IN ('cash','card') THEN 'paid' ELSE 'issued' END AS status,
  m.payment_type,
  m.business_date AS issue_date,
  m.business_date AS due_date,
  'QAR' AS currency,
  m.subtotal_cents,
  m.discount_cents AS discount_total_cents,
  'fixed' AS invoice_discount_type,
  m.discount_cents AS invoice_discount_value,
  m.discount_cents AS invoice_discount_cents,
  0 AS tax_total_cents,
  m.grouped_total_cents AS total_cents,
  CASE WHEN m.payment_type IN ('cash','card') THEN m.grouped_total_cents ELSE 0 END AS paid_total_cents,
  CASE WHEN m.payment_type IN ('cash','card') THEN 0 ELSE m.grouped_total_cents END AS balance_cents,
  m.pos_reference,
  'Imported from Sales Record Detail CSV' AS notes,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(m.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS created_at,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(m.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS updated_at
FROM tmp_invoice_match m
WHERE m.resolution_status = 'create_new'
ORDER BY m.group_row_num;
SET @created_invoice_rows := ROW_COUNT();

DROP TEMPORARY TABLE IF EXISTS tmp_created_targets;
CREATE TEMPORARY TABLE tmp_created_targets AS
SELECT
  m.group_row_num,
  m.invoice_number_norm,
  m.invoice_number,
  ai.id AS invoice_id
FROM tmp_invoice_match m
JOIN ar_invoices ai
  ON ai.branch_id = 1
  AND ai.type = 'invoice'
  AND ai.invoice_number COLLATE utf8mb4_unicode_ci = m.invoice_number COLLATE utf8mb4_unicode_ci
WHERE m.resolution_status = 'create_new';
ALTER TABLE tmp_created_targets
  ADD PRIMARY KEY (group_row_num),
  ADD KEY idx_tmp_created_targets_invoice_id (invoice_id);

DROP TEMPORARY TABLE IF EXISTS tmp_target_invoice_ids;
CREATE TEMPORARY TABLE tmp_target_invoice_ids AS
SELECT
  t.group_row_num,
  t.invoice_number_norm,
  t.invoice_number,
  t.invoice_id,
  'replace' AS target_mode
FROM tmp_replace_targets t
UNION ALL
SELECT
  t.group_row_num,
  t.invoice_number_norm,
  t.invoice_number,
  t.invoice_id,
  'create' AS target_mode
FROM tmp_created_targets t;
ALTER TABLE tmp_target_invoice_ids
  ADD PRIMARY KEY (group_row_num),
  ADD KEY idx_tmp_target_invoice_ids_invoice_id (invoice_id),
  ADD KEY idx_tmp_target_invoice_ids_mode (target_mode);

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
  l.item_name AS description,
  l.qty,
  l.unit_price_cents,
  l.line_discount_cents AS discount_cents,
  0 AS tax_cents,
  l.line_total_cents,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(l.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS created_at,
  COALESCE(STR_TO_DATE(LEFT(REPLACE(l.source_timestamp, 'T', ' '), 19), '%Y-%m-%d %H:%i:%s'), NOW()) AS updated_at
FROM tmp_target_invoice_ids t
JOIN tmp_sales_record_detail_lines l
  ON l.invoice_number_norm = t.invoice_number_norm
ORDER BY l.source_row_num;
SET @inserted_invoice_item_rows := ROW_COUNT();

SET @source_total_csv_rows := 20;
SET @source_line_rows_loaded := 20;
SET @source_distinct_invoices := 19;
SET @source_missing_invoice_no_rows := 0;
SET @source_negative_qty_rows := 0;
SET @source_negative_total_rows := 0;

SET @invoice_group_rows := (SELECT COUNT(*) FROM tmp_sales_record_detail_invoice_groups);
SET @replaced_invoice_rows := (SELECT COUNT(DISTINCT invoice_id) FROM tmp_replace_targets);
SET @target_invoice_rows := (SELECT COUNT(DISTINCT invoice_id) FROM tmp_target_invoice_ids);
SET @skip_conflict_rows := (SELECT COUNT(*) FROM tmp_invoice_match WHERE resolution_status = 'skip_conflict');
SET @skip_voided_rows := (SELECT COUNT(*) FROM tmp_invoice_match WHERE resolution_status = 'skip_voided');
SET @skip_customer_rows := (SELECT COUNT(*) FROM tmp_invoice_match WHERE resolution_status = 'skip_customer');
SET @skip_total_mismatch_rows := (SELECT COUNT(*) FROM tmp_matched_total_check WHERE total_status = 'skip_total_mismatch');

-- Summary
SELECT
  @source_total_csv_rows AS source_total_csv_rows,
  @source_line_rows_loaded AS source_line_rows_loaded,
  @source_distinct_invoices AS source_distinct_invoices,
  @source_missing_invoice_no_rows AS source_missing_invoice_no_rows,
  @source_negative_qty_rows AS source_negative_qty_rows,
  @source_negative_total_rows AS source_negative_total_rows,
  @invoice_group_rows AS invoice_group_rows,
  @inserted_customers AS inserted_customers,
  @created_invoice_rows AS created_invoices,
  @replaced_invoice_rows AS replaced_existing_invoices,
  @target_invoice_rows AS total_target_invoices,
  @skip_conflict_rows AS skipped_conflict_rows,
  @skip_voided_rows AS skipped_voided_rows,
  @skip_customer_rows AS skipped_customer_rows,
  @skip_total_mismatch_rows AS skipped_total_mismatch_rows,
  @deleted_invoice_item_rows AS deleted_existing_invoice_items,
  @inserted_invoice_item_rows AS inserted_invoice_items;

-- Resolution-status breakdown
SELECT resolution_status, COUNT(*) AS invoice_count
FROM tmp_invoice_match
GROUP BY resolution_status
ORDER BY resolution_status;

-- Skipped rows due to invoice-number/POS conflicts
SELECT
  group_row_num,
  invoice_number,
  pos_reference,
  invoice_by_number_id,
  invoice_by_pos_id
FROM tmp_invoice_match
WHERE resolution_status = 'skip_conflict'
ORDER BY group_row_num;

-- Skipped rows due to matched voided invoices
SELECT
  group_row_num,
  invoice_number,
  resolved_invoice_id,
  resolved_invoice_status
FROM tmp_invoice_match
WHERE resolution_status = 'skip_voided'
ORDER BY group_row_num;

-- Skipped rows due to unresolved customer for new invoices
SELECT
  m.group_row_num,
  m.invoice_number,
  m.customer_name,
  m.customer_norm,
  m.customer_resolution
FROM tmp_invoice_match m
WHERE m.resolution_status = 'skip_customer'
ORDER BY m.group_row_num;

-- Skipped rows due to existing-total mismatch
SELECT
  m.group_row_num,
  m.invoice_number,
  m.resolved_invoice_id,
  m.grouped_total_cents AS csv_group_total_cents,
  m.resolved_invoice_total_cents AS existing_invoice_total_cents,
  m.line_count
FROM tmp_invoice_match m
JOIN tmp_matched_total_check tc ON tc.group_row_num = m.group_row_num
WHERE tc.total_status = 'skip_total_mismatch'
ORDER BY m.group_row_num;

-- ROLLBACK; -- Uncomment for dry-run safety.
COMMIT;
