-- Generated from daily-dish.csv
-- Safe to run multiple times; upserts menus/items and refreshes menu lines for targeted dates.
START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_daily_dish_seed;
CREATE TEMPORARY TABLE tmp_daily_dish_seed (
  branch_id INT NOT NULL,
  service_date DATE NOT NULL,
  status ENUM('draft','published','archived') NOT NULL,
  role ENUM('main','diet','vegetarian','salad','dessert','addon') NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  item_name VARCHAR(255) NOT NULL,
  price DECIMAL(12,3) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'each'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tmp_daily_dish_seed (branch_id, service_date, status, role, sort_order, is_required, item_name, price, unit) VALUES
(1, '2026-03-01', 'draft', 'salad', 1, 0, 'Green Salad', 5.000, 'each'),
(1, '2026-03-01', 'draft', 'dessert', 2, 0, 'Chocolate cake', 5.000, 'each'),
(1, '2026-03-01', 'draft', 'main', 3, 0, 'Beef stroganoff', 50.000, 'each'),
(1, '2026-03-01', 'draft', 'main', 4, 0, 'Oriental chicken with rice', 50.000, 'each'),
(1, '2026-03-01', 'draft', 'main', 5, 0, 'Bourghoul with tomato', 50.000, 'each'),
(1, '2026-03-02', 'draft', 'salad', 1, 0, 'Tabbouleh', 5.000, 'each'),
(1, '2026-03-02', 'draft', 'dessert', 2, 0, 'Apple tarte', 5.000, 'each'),
(1, '2026-03-02', 'draft', 'main', 3, 0, 'Fassolia with meat', 50.000, 'each'),
(1, '2026-03-02', 'draft', 'main', 4, 0, 'Fajita', 50.000, 'each'),
(1, '2026-03-02', 'draft', 'main', 5, 0, 'Fassolia with oil', 50.000, 'each'),
(1, '2026-03-03', 'draft', 'salad', 1, 0, 'Fattouch', 5.000, 'each'),
(1, '2026-03-03', 'draft', 'dessert', 2, 0, 'Banana Cake', 5.000, 'each'),
(1, '2026-03-03', 'draft', 'main', 3, 0, 'Daoud bacha with rice', 50.000, 'each'),
(1, '2026-03-03', 'draft', 'main', 4, 0, 'Potato souffle', 50.000, 'each'),
(1, '2026-03-03', 'draft', 'main', 5, 0, 'Noodles with vegetables', 50.000, 'each'),
(1, '2026-03-04', 'draft', 'salad', 1, 0, 'Greek Salad', 5.000, 'each'),
(1, '2026-03-04', 'draft', 'dessert', 2, 0, 'Rice Pudding', 5.000, 'each'),
(1, '2026-03-04', 'draft', 'main', 3, 0, 'Chicken supreme', 50.000, 'each'),
(1, '2026-03-04', 'draft', 'main', 4, 0, 'Pasta bolognese', 50.000, 'each'),
(1, '2026-03-04', 'draft', 'main', 5, 0, 'Okra with oil', 50.000, 'each'),
(1, '2026-03-05', 'draft', 'salad', 1, 0, 'Green Salad', 5.000, 'each'),
(1, '2026-03-05', 'draft', 'dessert', 2, 0, 'Lazy Cake', 5.000, 'each'),
(1, '2026-03-05', 'draft', 'main', 3, 0, 'Koussa mehchi', 50.000, 'each'),
(1, '2026-03-05', 'draft', 'main', 4, 0, 'Bazella with rice', 50.000, 'each'),
(1, '2026-03-05', 'draft', 'main', 5, 0, 'Mjadara', 50.000, 'each'),
(1, '2026-03-06', 'draft', 'salad', 1, 0, 'Pasta Salad', 5.000, 'each'),
(1, '2026-03-06', 'draft', 'dessert', 2, 0, 'Muffins', 5.000, 'each'),
(1, '2026-03-06', 'draft', 'main', 3, 0, 'Chicken stroganoff', 50.000, 'each'),
(1, '2026-03-06', 'draft', 'main', 4, 0, 'Pasta red sauce', 50.000, 'each'),
(1, '2026-03-06', 'draft', 'main', 5, 0, 'Noodles chicken', 50.000, 'each'),
(1, '2026-03-07', 'draft', 'salad', 1, 0, 'Fattouch', 5.000, 'each'),
(1, '2026-03-07', 'draft', 'dessert', 2, 0, 'Brownies', 5.000, 'each'),
(1, '2026-03-07', 'draft', 'main', 3, 0, 'Loubye with meat', 50.000, 'each'),
(1, '2026-03-07', 'draft', 'main', 4, 0, 'Chicken biryani', 50.000, 'each'),
(1, '2026-03-07', 'draft', 'main', 5, 0, 'Pasta pesto', 50.000, 'each'),
(1, '2026-03-08', 'draft', 'salad', 1, 0, 'Fresh Salad', 5.000, 'each'),
(1, '2026-03-08', 'draft', 'dessert', 2, 0, 'Carrot cake', 5.000, 'each'),
(1, '2026-03-08', 'draft', 'main', 3, 0, 'Coconut chicken curry', 50.000, 'each'),
(1, '2026-03-08', 'draft', 'main', 4, 0, 'Kabab orfali', 50.000, 'each'),
(1, '2026-03-08', 'draft', 'main', 5, 0, 'Mdardara', 50.000, 'each'),
(1, '2026-03-09', 'draft', 'salad', 1, 0, 'Malfouf Salad / Yogurt and Cucumber', 5.000, 'each'),
(1, '2026-03-09', 'draft', 'dessert', 2, 0, 'Fruit cake', 5.000, 'each'),
(1, '2026-03-09', 'draft', 'main', 3, 0, 'Kebbeh bil sayniye', 50.000, 'each'),
(1, '2026-03-09', 'draft', 'main', 4, 0, 'Chicken alfredo', 50.000, 'each'),
(1, '2026-03-09', 'draft', 'main', 5, 0, 'Penne Arrabbiata', 50.000, 'each'),
(1, '2026-03-10', 'draft', 'salad', 1, 0, 'Fattouch', 5.000, 'each'),
(1, '2026-03-10', 'draft', 'dessert', 2, 0, 'Mouhalabiye', 5.000, 'each'),
(1, '2026-03-10', 'draft', 'main', 3, 0, 'Chicken kaju nuts', 50.000, 'each'),
(1, '2026-03-10', 'draft', 'main', 4, 0, 'Philadelphia', 50.000, 'each'),
(1, '2026-03-10', 'draft', 'main', 5, 0, 'Eggplant msakaa', 50.000, 'each'),
(1, '2026-03-11', 'draft', 'salad', 1, 0, 'Tabbouleh', 5.000, 'each'),
(1, '2026-03-11', 'draft', 'dessert', 2, 0, 'Chocolate Cake', 5.000, 'each'),
(1, '2026-03-11', 'draft', 'main', 3, 0, 'Chich barak with rice', 50.000, 'each'),
(1, '2026-03-11', 'draft', 'main', 4, 0, 'Creamy shrimp pasta', 50.000, 'each'),
(1, '2026-03-11', 'draft', 'main', 5, 0, 'Loubye with oil', 50.000, 'each'),
(1, '2026-03-12', 'draft', 'salad', 1, 0, 'Green Salad', 5.000, 'each'),
(1, '2026-03-12', 'draft', 'dessert', 2, 0, 'Cookies', 5.000, 'each'),
(1, '2026-03-12', 'draft', 'main', 3, 0, 'Chicken nouille', 50.000, 'each'),
(1, '2026-03-12', 'draft', 'main', 4, 0, 'Sheikh el mehchi', 50.000, 'each'),
(1, '2026-03-12', 'draft', 'main', 5, 0, 'Pumpkin kebbeh', 50.000, 'each'),
(1, '2026-03-13', 'draft', 'salad', 1, 0, 'Greek Salad', 5.000, 'each'),
(1, '2026-03-13', 'draft', 'dessert', 2, 0, 'Cake', 5.000, 'each'),
(1, '2026-03-13', 'draft', 'main', 3, 0, 'Mashed potato with meat balls', 50.000, 'each'),
(1, '2026-03-13', 'draft', 'main', 4, 0, 'Shrimp kaju nuts', 50.000, 'each'),
(1, '2026-03-13', 'draft', 'main', 5, 0, 'Fish fillet with vegetables', 50.000, 'each'),
(1, '2026-03-14', 'draft', 'salad', 1, 0, 'Fresh Salad', 5.000, 'each'),
(1, '2026-03-14', 'draft', 'dessert', 2, 0, 'Custard', 5.000, 'each'),
(1, '2026-03-14', 'draft', 'main', 3, 0, 'Butter Chicken', 50.000, 'each'),
(1, '2026-03-14', 'draft', 'main', 4, 0, 'Kafta bi Tahini', 50.000, 'each'),
(1, '2026-03-14', 'draft', 'main', 5, 0, 'Falafel', 50.000, 'each'),
(1, '2026-03-15', 'draft', 'salad', 1, 0, 'Quinoa Salad', 5.000, 'each'),
(1, '2026-03-15', 'draft', 'dessert', 2, 0, 'Brownies', 5.000, 'each'),
(1, '2026-03-15', 'draft', 'main', 3, 0, 'Kafta with potato', 50.000, 'each'),
(1, '2026-03-15', 'draft', 'main', 4, 0, 'Pasta bolognese', 50.000, 'each'),
(1, '2026-03-15', 'draft', 'main', 5, 0, 'Loubye with oil', 50.000, 'each'),
(1, '2026-03-16', 'draft', 'salad', 1, 0, 'Green Salad', 5.000, 'each'),
(1, '2026-03-16', 'draft', 'dessert', 2, 0, 'Vanilla Cake', 5.000, 'each'),
(1, '2026-03-16', 'draft', 'main', 3, 0, 'Moughrabiye', 50.000, 'each'),
(1, '2026-03-16', 'draft', 'main', 4, 0, 'Shrimp with rice', 50.000, 'each'),
(1, '2026-03-16', 'draft', 'main', 5, 0, 'Vine leaves with oil', 50.000, 'each'),
(1, '2026-03-17', 'draft', 'salad', 1, 0, 'Tabbouleh', 5.000, 'each'),
(1, '2026-03-17', 'draft', 'dessert', 2, 0, 'Sfouf bi debs', 5.000, 'each'),
(1, '2026-03-17', 'draft', 'main', 3, 0, 'Roast beef with vegetables', 50.000, 'each'),
(1, '2026-03-17', 'draft', 'main', 4, 0, 'Chicken cordon bleu', 50.000, 'each'),
(1, '2026-03-17', 'draft', 'main', 5, 0, 'Pasta with vegetables', 50.000, 'each'),
(1, '2026-03-18', 'draft', 'salad', 1, 0, 'Fattouch', 5.000, 'each'),
(1, '2026-03-18', 'draft', 'dessert', 2, 0, 'Carrot Cake', 5.000, 'each'),
(1, '2026-03-18', 'draft', 'main', 3, 0, 'Loubye with meat', 50.000, 'each'),
(1, '2026-03-18', 'draft', 'main', 4, 0, 'Coconut chicken curry', 50.000, 'each'),
(1, '2026-03-18', 'draft', 'main', 5, 0, 'Mjadara', 50.000, 'each'),
(1, '2026-03-19', 'draft', 'salad', 1, 0, 'Green Salad', 5.000, 'each'),
(1, '2026-03-19', 'draft', 'dessert', 2, 0, 'Custard', 5.000, 'each'),
(1, '2026-03-19', 'draft', 'main', 3, 0, 'Lasagna', 50.000, 'each'),
(1, '2026-03-19', 'draft', 'main', 4, 0, 'Fish and chips', 50.000, 'each'),
(1, '2026-03-19', 'draft', 'main', 5, 0, 'Pasta pesto', 50.000, 'each'),
(1, '2026-03-21', 'draft', 'salad', 1, 0, 'Greek Salad', 5.000, 'each'),
(1, '2026-03-21', 'draft', 'dessert', 2, 0, 'Muffins', 5.000, 'each'),
(1, '2026-03-21', 'draft', 'main', 3, 0, 'Kafta platter', 50.000, 'each'),
(1, '2026-03-21', 'draft', 'main', 4, 0, 'Philadelphia', 50.000, 'each'),
(1, '2026-03-21', 'draft', 'main', 5, 0, 'Taouk platter', 50.000, 'each'),
(1, '2026-03-22', 'draft', 'salad', 1, 0, 'Green Salad', 5.000, 'each'),
(1, '2026-03-22', 'draft', 'dessert', 2, 0, 'Tarte', 5.000, 'each'),
(1, '2026-03-22', 'draft', 'main', 3, 0, 'Frikeh chicken', 50.000, 'each'),
(1, '2026-03-22', 'draft', 'main', 4, 0, 'Shawarma beef', 50.000, 'each'),
(1, '2026-03-22', 'draft', 'main', 5, 0, 'Noodles with vegetables', 50.000, 'each'),
(1, '2026-03-23', 'draft', 'salad', 1, 0, 'Tabbouleh', 5.000, 'each'),
(1, '2026-03-23', 'draft', 'dessert', 2, 0, 'Lazy Cake', 5.000, 'each'),
(1, '2026-03-23', 'draft', 'main', 3, 0, 'Mehchi malfouf', 50.000, 'each'),
(1, '2026-03-23', 'draft', 'main', 4, 0, 'Kafta bi tahini', 50.000, 'each'),
(1, '2026-03-23', 'draft', 'main', 5, 0, 'Mehchi malfouf with oil', 50.000, 'each'),
(1, '2026-03-24', 'draft', 'salad', 1, 0, 'Fattouch or Malfouf Salad', 5.000, 'each'),
(1, '2026-03-24', 'draft', 'dessert', 2, 0, 'Orange Cake', 5.000, 'each'),
(1, '2026-03-24', 'draft', 'main', 3, 0, 'Kebbeh bil sayniye', 50.000, 'each'),
(1, '2026-03-24', 'draft', 'main', 4, 0, 'Oriental chicken with rice', 50.000, 'each'),
(1, '2026-03-24', 'draft', 'main', 5, 0, 'Mjadara', 50.000, 'each'),
(1, '2026-03-25', 'draft', 'salad', 1, 0, 'Caesar Salad', 5.000, 'each'),
(1, '2026-03-25', 'draft', 'dessert', 2, 0, 'Apple Tarte', 5.000, 'each'),
(1, '2026-03-25', 'draft', 'main', 3, 0, 'Siyadiye', 50.000, 'each'),
(1, '2026-03-25', 'draft', 'main', 4, 0, 'Sheikh el mehchi', 50.000, 'each'),
(1, '2026-03-25', 'draft', 'main', 5, 0, 'Fassolia with oil', 50.000, 'each'),
(1, '2026-03-26', 'draft', 'salad', 1, 0, 'Fresh Salad', 5.000, 'each'),
(1, '2026-03-26', 'draft', 'dessert', 2, 0, 'Cookies', 5.000, 'each'),
(1, '2026-03-26', 'draft', 'main', 3, 0, 'Oriental meat with rice', 50.000, 'each'),
(1, '2026-03-26', 'draft', 'main', 4, 0, 'Fajita', 50.000, 'each'),
(1, '2026-03-26', 'draft', 'main', 5, 0, 'Fish fillet with vegetables', 50.000, 'each'),
(1, '2026-03-28', 'draft', 'salad', 1, 0, 'Tabbouleh', 5.000, 'each'),
(1, '2026-03-28', 'draft', 'dessert', 2, 0, 'Chocolate Crunch', 5.000, 'each'),
(1, '2026-03-28', 'draft', 'main', 3, 0, 'Kabab khichkhach', 50.000, 'each'),
(1, '2026-03-28', 'draft', 'main', 4, 0, 'Beef burger', 50.000, 'each'),
(1, '2026-03-28', 'draft', 'main', 5, 0, 'Taouk platter', 50.000, 'each'),
(1, '2026-03-29', 'draft', 'salad', 1, 0, 'Green Salad', 5.000, 'each'),
(1, '2026-03-29', 'draft', 'dessert', 2, 0, 'Carrot Cake', 5.000, 'each'),
(1, '2026-03-29', 'draft', 'main', 3, 0, 'Mloukiye', 50.000, 'each'),
(1, '2026-03-29', 'draft', 'main', 4, 0, 'Bemyeh with meat', 50.000, 'each'),
(1, '2026-03-29', 'draft', 'main', 5, 0, 'Bourghoul with tomato', 50.000, 'each'),
(1, '2026-03-30', 'draft', 'salad', 1, 0, 'Fattouch', 5.000, 'each'),
(1, '2026-03-30', 'draft', 'dessert', 2, 0, 'Sfouf', 5.000, 'each'),
(1, '2026-03-30', 'draft', 'main', 3, 0, 'Spinach with rice', 50.000, 'each'),
(1, '2026-03-30', 'draft', 'main', 4, 0, 'Kabab orfali', 50.000, 'each'),
(1, '2026-03-30', 'draft', 'main', 5, 0, 'Penne arrabbiata', 50.000, 'each'),
(1, '2026-03-31', 'draft', 'salad', 1, 0, 'Tabbouleh', 5.000, 'each'),
(1, '2026-03-31', 'draft', 'dessert', 2, 0, 'Swiss Rolls', 5.000, 'each'),
(1, '2026-03-31', 'draft', 'main', 3, 0, 'Daoud bacha with rice', 50.000, 'each'),
(1, '2026-03-31', 'draft', 'main', 4, 0, 'Biryani chicken', 50.000, 'each'),
(1, '2026-03-31', 'draft', 'main', 5, 0, 'Shrimp kaju nuts', 50.000, 'each');

-- 1) Create missing menu items by normalized name (trim/lower).
INSERT INTO menu_items (
  code, name, selling_price_per_unit, unit, tax_rate, is_active, display_order, status, created_at, updated_at
)
SELECT
  CONCAT('DDCSV-', UPPER(SUBSTRING(MD5(LOWER(TRIM(t.item_name))), 1, 10))) AS code,
  t.item_name AS name,
  COALESCE(MAX(t.price), 0.000) AS selling_price_per_unit,
  COALESCE(NULLIF(MAX(t.unit), ''), 'each') AS unit,
  0.00 AS tax_rate,
  1 AS is_active,
  0 AS display_order,
  'active' AS status,
  NOW() AS created_at,
  NOW() AS updated_at
FROM tmp_daily_dish_seed t
LEFT JOIN menu_items mi
  ON LOWER(TRIM(mi.name)) COLLATE utf8mb4_unicode_ci
   = LOWER(TRIM(t.item_name)) COLLATE utf8mb4_unicode_ci
WHERE mi.id IS NULL
GROUP BY LOWER(TRIM(t.item_name)), t.item_name
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 2) Upsert menu headers per branch/date.
INSERT INTO daily_dish_menus (branch_id, service_date, status, notes, created_by, created_at, updated_at)
SELECT t.branch_id, t.service_date, 'published' AS status, NULL AS notes, NULL AS created_by, NOW(), NOW()
FROM tmp_daily_dish_seed t
GROUP BY t.branch_id, t.service_date
ON DUPLICATE KEY UPDATE status = 'published', updated_at = NOW();

-- 3) Ensure item availability in each seeded branch (required by availableInBranch scope).
INSERT IGNORE INTO menu_item_branches (menu_item_id, branch_id, created_at, updated_at)
SELECT DISTINCT
  mi.id AS menu_item_id,
  t.branch_id,
  NOW() AS created_at,
  NOW() AS updated_at
FROM tmp_daily_dish_seed t
JOIN menu_items mi
  ON LOWER(TRIM(mi.name)) COLLATE utf8mb4_unicode_ci
   = LOWER(TRIM(t.item_name)) COLLATE utf8mb4_unicode_ci;

-- 4) Replace menu lines for seeded dates (keeps data aligned to CSV).
DELETE dmi
FROM daily_dish_menu_items dmi
JOIN daily_dish_menus m ON m.id = dmi.daily_dish_menu_id
JOIN (SELECT DISTINCT branch_id, service_date FROM tmp_daily_dish_seed) s
  ON s.branch_id = m.branch_id AND s.service_date = m.service_date;

-- 5) Insert menu lines matched by normalized item name.
INSERT INTO daily_dish_menu_items (daily_dish_menu_id, menu_item_id, role, sort_order, is_required, created_at)
SELECT
  m.id AS daily_dish_menu_id,
  mi.id AS menu_item_id,
  t.role,
  t.sort_order,
  t.is_required,
  NOW() AS created_at
FROM tmp_daily_dish_seed t
JOIN daily_dish_menus m
  ON m.branch_id = t.branch_id AND m.service_date = t.service_date
JOIN menu_items mi
  ON LOWER(TRIM(mi.name)) COLLATE utf8mb4_unicode_ci
   = LOWER(TRIM(t.item_name)) COLLATE utf8mb4_unicode_ci;

DROP TEMPORARY TABLE IF EXISTS tmp_daily_dish_seed;
COMMIT;
