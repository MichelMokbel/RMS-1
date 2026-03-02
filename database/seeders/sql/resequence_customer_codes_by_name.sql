-- Re-sequence customer_code for all customers, ordered by name.
-- Default format: 100001, 100002, ...
-- You can change @code_prefix / @start_num / @pad_len before running.

START TRANSACTION;

SET @code_prefix := '';
SET @start_num := 100001;
SET @pad_len := 6;

DROP TEMPORARY TABLE IF EXISTS tmp_customer_code_sequence;
CREATE TEMPORARY TABLE tmp_customer_code_sequence (
  customer_id INT NOT NULL PRIMARY KEY,
  sort_name VARCHAR(255) NOT NULL,
  new_customer_code VARCHAR(50) NOT NULL,
  KEY idx_tmp_customer_code_sequence_sort_name (sort_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @row_num := 0;
INSERT INTO tmp_customer_code_sequence (customer_id, sort_name, new_customer_code)
SELECT
  c.id AS customer_id,
  LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci AS sort_name,
  CONCAT(
    @code_prefix,
    LPAD(CAST(@start_num + (@row_num := @row_num + 1) - 1 AS CHAR), @pad_len, '0')
  ) AS new_customer_code
FROM customers c
ORDER BY LOWER(TRIM(c.name)) COLLATE utf8mb4_unicode_ci, c.id;

-- Preview summary before update.
SELECT
  (SELECT COUNT(*) FROM customers) AS customers_total,
  (SELECT COUNT(*) FROM tmp_customer_code_sequence) AS generated_rows,
  (SELECT COUNT(DISTINCT new_customer_code) FROM tmp_customer_code_sequence) AS generated_unique_codes;

-- Preview first 30 assignments.
SELECT
  t.customer_id,
  c.name,
  c.customer_code AS old_customer_code,
  t.new_customer_code
FROM tmp_customer_code_sequence t
JOIN customers c ON c.id = t.customer_id
ORDER BY t.new_customer_code
LIMIT 30;

-- Apply new sequential codes to all customers.
UPDATE customers c
JOIN tmp_customer_code_sequence t ON t.customer_id = c.id
SET
  c.customer_code = t.new_customer_code,
  c.updated_at = NOW();

SET @updated_rows := ROW_COUNT();

SELECT @updated_rows AS updated_rows;

-- ROLLBACK; -- Uncomment to dry-run and inspect preview first.
COMMIT;
