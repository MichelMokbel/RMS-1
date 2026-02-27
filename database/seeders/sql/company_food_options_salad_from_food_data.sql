-- Seed script generated from updated food-data.xlsx (Sheet1: Date + Starters)
-- Scope: project_id=1, employee_list_id=1, category=salad, dates 2026-03-01..2026-03-19
-- Rule: insert one salad per day with fixed sort_order = 2

-- Optional safety precheck: ensure target table exists
SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_food_options';

-- Optional dedup/replace behavior for this exact scope (uncomment if needed)
-- DELETE FROM company_food_options
-- WHERE project_id = 1
--   AND employee_list_id = 1
--   AND category = 'salad'
--   AND menu_date BETWEEN '2026-03-01' AND '2026-03-19'
--   AND sort_order = 2;

INSERT INTO company_food_options
    (project_id, employee_list_id, menu_date, category, name, sort_order, is_active, created_at, updated_at)
VALUES
    (1, 1, '2026-03-01', 'salad', 'Tabbouleh', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-02', 'salad', 'Greek Salad', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-03', 'salad', 'Green Salad with Cheese', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-04', 'salad', 'Rocca salad', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-05', 'salad', 'Tabbouleh', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-06', 'salad', 'Khyar & Labban', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-07', 'salad', 'Fresh Salad', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-08', 'salad', 'Tabbouleh', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-09', 'salad', 'Greek Salad', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-10', 'salad', 'Green Salad with Cheese', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-11', 'salad', 'Rocca salad', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-12', 'salad', 'Tabbouleh', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-13', 'salad', 'Greek Salad', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-14', 'salad', 'Green Salad with Cheese', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-15', 'salad', 'Rocca salad', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-16', 'salad', 'Tabbouleh', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-17', 'salad', 'Khyar & Labban', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-18', 'salad', 'Fresh Salad', 2, 1, NOW(), NOW()),
    (1, 1, '2026-03-19', 'salad', 'Tabbouleh', 2, 1, NOW(), NOW());

-- Validation: should return 19 rows for sort_order=2 in date scope
SELECT COUNT(*) AS seeded_count
FROM company_food_options
WHERE project_id = 1
  AND employee_list_id = 1
  AND category = 'salad'
  AND sort_order = 2
  AND menu_date BETWEEN '2026-03-01' AND '2026-03-19';

-- Validation: one row per date
SELECT menu_date, COUNT(*) AS row_count
FROM company_food_options
WHERE project_id = 1
  AND employee_list_id = 1
  AND category = 'salad'
  AND sort_order = 2
  AND menu_date BETWEEN '2026-03-01' AND '2026-03-19'
GROUP BY menu_date
ORDER BY menu_date;
