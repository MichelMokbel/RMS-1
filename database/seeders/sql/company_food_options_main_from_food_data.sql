-- Seed script generated from food-data.xlsx (Sheet1)
-- Scope: project_id=1, employee_list_id=1, category=main, dates 2026-03-01..2026-03-19

-- Optional safety precheck: ensure the target table exists and is company_food_options
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_food_options';

-- Optional dedup/replace behavior (uncomment to replace this exact scope)
-- DELETE FROM company_food_options
-- WHERE project_id = 1
--   AND employee_list_id = 1
--   AND category = 'main'
--   AND menu_date BETWEEN '2026-03-01' AND '2026-03-19';

INSERT INTO company_food_options
    (project_id, employee_list_id, menu_date, category, name, sort_order, is_active, created_at, updated_at)
VALUES
    (1, 1, '2026-03-01', 'main', 'Beef stroganoff', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-01', 'main', 'Oriental chicken with rice', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-01', 'main', 'Bourghoul with tomato', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-02', 'main', 'Fassolia with meat', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-02', 'main', 'Fajita', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-02', 'main', 'Fassolia with oil', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-03', 'main', 'Daoud bacha with rice', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-03', 'main', 'Potato souffle', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-03', 'main', 'Noodles with vegetables', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-04', 'main', 'Chicken supreme', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-04', 'main', 'Pasta bolognese', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-04', 'main', 'Bemyeh with oil', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-05', 'main', 'Koussa mehchi', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-05', 'main', 'Bazella with rice', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-05', 'main', 'Mjadara', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-06', 'main', 'Chicken stroganoff', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-06', 'main', 'Pasta red sauce', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-06', 'main', 'Noodles chicken', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-07', 'main', 'Loubye with meat', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-07', 'main', 'Chicken biryani', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-07', 'main', 'Pasta pesto', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-08', 'main', 'Coconut chicken curry', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-08', 'main', 'Kabab orfali', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-08', 'main', 'Mdardara', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-09', 'main', 'Kebbeh bil sayniye', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-09', 'main', 'Chicken alfredo', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-09', 'main', 'Penne Arrabbiata', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-10', 'main', 'Chicken kaju nuts', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-10', 'main', 'Philadelphia', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-10', 'main', 'Eggplant msakaa', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-11', 'main', 'Chich barak with rice', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-11', 'main', 'Creamy shrimp pasta', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-11', 'main', 'Loubye with oil', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-12', 'main', 'Chicken nouille', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-12', 'main', 'Sheikh el mehchi', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-12', 'main', 'Pumpkin kebbeh', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-13', 'main', 'Mashed potato with meat balls', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-13', 'main', 'Shrimp kaju nuts', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-13', 'main', 'Fish fillet with vegetables', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-14', 'main', 'Butter Chicken', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-14', 'main', 'Kafta bi Tahini', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-14', 'main', 'Falafel', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-15', 'main', 'Kafta with potato', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-15', 'main', 'Pasta bolognese', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-15', 'main', 'Loubye with oil', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-16', 'main', 'Moughrabiye', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-16', 'main', 'Shrimp with rice', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-16', 'main', 'Vine leaves with oil', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-17', 'main', 'Roast beef with vegetables', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-17', 'main', 'Chicken cordon bleu', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-17', 'main', 'Pasta with vegetables', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-18', 'main', 'Loubye with meat', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-18', 'main', 'Coconut chicken curry', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-18', 'main', 'Mjadara', 5, 1, NOW(), NOW()),
    (1, 1, '2026-03-19', 'main', 'Lasagna', 3, 1, NOW(), NOW()),
    (1, 1, '2026-03-19', 'main', 'Fish and chips', 4, 1, NOW(), NOW()),
    (1, 1, '2026-03-19', 'main', 'Pasta pesto', 5, 1, NOW(), NOW());

-- Validation 1: total seeded rows in scope should be 57
SELECT COUNT(*) AS seeded_count
FROM company_food_options
WHERE project_id = 1
  AND employee_list_id = 1
  AND category = 'main'
  AND menu_date BETWEEN '2026-03-01' AND '2026-03-19';

-- Validation 2 + 3: each date should have exactly 3 rows with sort_order 3,4,5 (no duplicates)
SELECT menu_date, COUNT(*) AS row_count, COUNT(DISTINCT sort_order) AS distinct_sort_orders,
       MIN(sort_order) AS min_sort_order, MAX(sort_order) AS max_sort_order
FROM company_food_options
WHERE project_id = 1
  AND employee_list_id = 1
  AND category = 'main'
  AND menu_date BETWEEN '2026-03-01' AND '2026-03-19'
GROUP BY menu_date
ORDER BY menu_date;

-- Validation 4: data integrity checks for fixed fields
SELECT DISTINCT project_id, employee_list_id, category, is_active
FROM company_food_options
WHERE project_id = 1
  AND employee_list_id = 1
  AND category = 'main'
  AND menu_date BETWEEN '2026-03-01' AND '2026-03-19';

-- Validation 5: spot check preserved names
SELECT menu_date, name, sort_order
FROM company_food_options
WHERE project_id = 1
  AND employee_list_id = 1
  AND category = 'main'
  AND name IN ('Beef stroganoff', 'Penne Arrabbiata', 'Kafta bi Tahini')
ORDER BY menu_date, sort_order;
