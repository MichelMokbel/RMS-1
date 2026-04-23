-- 2026_04_23_000013_ensure_current_accounting_periods_exist
-- Creates fiscal years and monthly periods for the current and next year for active companies.

INSERT INTO fiscal_years (company_id, name, start_date, end_date, status, created_at, updated_at)
SELECT c.id,
       CONCAT('FY ', y.y) AS name,
       STR_TO_DATE(CONCAT(y.y, '-01-01'), '%Y-%m-%d') AS start_date,
       STR_TO_DATE(CONCAT(y.y, '-12-31'), '%Y-%m-%d') AS end_date,
       'open',
       NOW(),
       NOW()
FROM accounting_companies c
CROSS JOIN (
    SELECT YEAR(CURDATE()) AS y
    UNION ALL
    SELECT YEAR(CURDATE()) + 1 AS y
) y
LEFT JOIN fiscal_years fy
  ON fy.company_id = c.id
 AND fy.start_date = STR_TO_DATE(CONCAT(y.y, '-01-01'), '%Y-%m-%d')
WHERE c.is_active = 1
  AND fy.id IS NULL;

WITH RECURSIVE months AS (
    SELECT 1 AS month_num
    UNION ALL
    SELECT month_num + 1 FROM months WHERE month_num < 12
), years AS (
    SELECT YEAR(CURDATE()) AS year_num
    UNION ALL
    SELECT YEAR(CURDATE()) + 1 AS year_num
)
INSERT INTO accounting_periods (company_id, fiscal_year_id, name, period_number, start_date, end_date, status, created_at, updated_at)
SELECT c.id,
       fy.id,
       DATE_FORMAT(STR_TO_DATE(CONCAT(y.year_num, '-', LPAD(m.month_num, 2, '0'), '-01'), '%Y-%m-%d'), '%b %Y') AS name,
       m.month_num,
       STR_TO_DATE(CONCAT(y.year_num, '-', LPAD(m.month_num, 2, '0'), '-01'), '%Y-%m-%d') AS start_date,
       LAST_DAY(STR_TO_DATE(CONCAT(y.year_num, '-', LPAD(m.month_num, 2, '0'), '-01'), '%Y-%m-%d')) AS end_date,
       'open',
       NOW(),
       NOW()
FROM accounting_companies c
JOIN years y
JOIN fiscal_years fy
  ON fy.company_id = c.id
 AND fy.start_date = STR_TO_DATE(CONCAT(y.year_num, '-01-01'), '%Y-%m-%d')
JOIN months m
LEFT JOIN accounting_periods ap
  ON ap.company_id = c.id
 AND ap.fiscal_year_id = fy.id
 AND ap.period_number = m.month_num
WHERE c.is_active = 1
  AND ap.id IS NULL;
