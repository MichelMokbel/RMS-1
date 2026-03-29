<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$csvPath = $root.'/daily-dish.csv';
$sqlPath = $root.'/database/seeders/sql/daily_dish_seed_from_csv.sql';
$monthFilter = null;

foreach ($args as $arg) {
    if (preg_match('/^\d{4}-\d{2}$/', $arg)) {
        $monthFilter = $arg;
        continue;
    }

    if (str_ends_with($arg, '.csv')) {
        $csvPath = $arg;
        continue;
    }

    if (str_ends_with($arg, '.sql')) {
        $sqlPath = $arg;
    }
}

if ($monthFilter !== null && ! preg_match('/^\d{4}-\d{2}$/', $monthFilter)) {
    fwrite(STDERR, "Invalid month filter: {$monthFilter}. Expected YYYY-MM\n");
    exit(1);
}

if (! is_file($csvPath)) {
    fwrite(STDERR, "CSV file not found: {$csvPath}\n");
    exit(1);
}

$handle = fopen($csvPath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Unable to open CSV: {$csvPath}\n");
    exit(1);
}

$header = fgetcsv($handle, 0, ',', '"', '\\');
$expected = ['branch_id', 'service_date', 'status', 'role', 'sort_order', 'is_required', 'item_name', 'price', 'unit'];
if ($header !== $expected) {
    fwrite(STDERR, "Unexpected CSV header in {$csvPath}\n");
    exit(1);
}

$rows = [];
$statusByMenu = [];
$line = 1;

while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $line++;

    if ($data === [null] || $data === false) {
        continue;
    }

    if (count($data) !== count($expected)) {
        fwrite(STDERR, "Invalid column count on CSV line {$line}\n");
        exit(1);
    }

    $row = array_combine($expected, $data);
    if ($row === false) {
        fwrite(STDERR, "Failed to read CSV line {$line}\n");
        exit(1);
    }

    if ($monthFilter !== null && ! str_starts_with((string) $row['service_date'], $monthFilter.'-')) {
        continue;
    }

    $menuKey = $row['branch_id'].'|'.$row['service_date'];
    $status = (string) $row['status'];

    if (isset($statusByMenu[$menuKey]) && $statusByMenu[$menuKey] !== $status) {
        fwrite(STDERR, "Mixed statuses for {$menuKey}\n");
        exit(1);
    }

    $statusByMenu[$menuKey] = $status;
    $rows[] = $row;
}

fclose($handle);

if ($rows === []) {
    $suffix = $monthFilter !== null ? " for {$monthFilter}" : '';
    fwrite(STDERR, "No data rows found in {$csvPath}{$suffix}\n");
    exit(1);
}

$escape = static function (?string $value): string {
    $value = $value ?? '';
    return str_replace("'", "''", $value);
};

$values = [];
foreach ($rows as $row) {
    $price = trim((string) $row['price']) === '' ? 'NULL' : number_format((float) $row['price'], 3, '.', '');
    $values[] = sprintf(
        "(%d, '%s', '%s', '%s', %d, %d, '%s', %s, '%s')",
        (int) $row['branch_id'],
        $escape((string) $row['service_date']),
        $escape((string) $row['status']),
        $escape((string) $row['role']),
        (int) $row['sort_order'],
        (int) $row['is_required'],
        $escape((string) $row['item_name']),
        $price,
        $escape((string) $row['unit'])
    );
}

$sourceLabel = basename($csvPath);
if ($monthFilter !== null) {
    $sourceLabel .= " ({$monthFilter})";
}

$sql = <<<SQL
-- Generated from {$sourceLabel}
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
SQL;

$sql .= "\n".implode(",\n", $values).";\n\n";

$sql .= <<<SQL
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
SELECT t.branch_id, t.service_date, MIN(t.status) AS status, NULL AS notes, NULL AS created_by, NOW(), NOW()
FROM tmp_daily_dish_seed t
GROUP BY t.branch_id, t.service_date
ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW();

-- 3) Ensure item availability in each seeded branch (required by availableInBranch scope).
INSERT IGNORE INTO menu_item_branches (menu_item_id, branch_id, created_at, updated_at)
SELECT DISTINCT
  mi.id AS menu_item_id,
  t.branch_id,
  NOW() AS created_at,
  NOW() AS updated_at
FROM tmp_daily_dish_seed t
JOIN menu_items mi
  ON mi.id = (
    SELECT mi2.id
    FROM menu_items mi2
    WHERE LOWER(TRIM(mi2.name)) COLLATE utf8mb4_unicode_ci
      = LOWER(TRIM(t.item_name)) COLLATE utf8mb4_unicode_ci
    ORDER BY
      CASE WHEN COALESCE(mi2.is_active, 1) = 1 THEN 0 ELSE 1 END,
      CASE WHEN t.price IS NULL THEN 0 ELSE ABS(COALESCE(mi2.selling_price_per_unit, 0) - t.price) END ASC,
      CASE WHEN COALESCE(mi2.selling_price_per_unit, 0) > 0 THEN 0 ELSE 1 END,
      COALESCE(mi2.selling_price_per_unit, 0) DESC,
      mi2.id ASC
    LIMIT 1
  );

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
  ON mi.id = (
    SELECT mi2.id
    FROM menu_items mi2
    WHERE LOWER(TRIM(mi2.name)) COLLATE utf8mb4_unicode_ci
      = LOWER(TRIM(t.item_name)) COLLATE utf8mb4_unicode_ci
    ORDER BY
      CASE WHEN COALESCE(mi2.is_active, 1) = 1 THEN 0 ELSE 1 END,
      CASE WHEN t.price IS NULL THEN 0 ELSE ABS(COALESCE(mi2.selling_price_per_unit, 0) - t.price) END ASC,
      CASE WHEN COALESCE(mi2.selling_price_per_unit, 0) > 0 THEN 0 ELSE 1 END,
      COALESCE(mi2.selling_price_per_unit, 0) DESC,
      mi2.id ASC
    LIMIT 1
  );

DROP TEMPORARY TABLE IF EXISTS tmp_daily_dish_seed;
COMMIT;
SQL;

if (file_put_contents($sqlPath, $sql) === false) {
    fwrite(STDERR, "Unable to write SQL file: {$sqlPath}\n");
    exit(1);
}

fwrite(STDOUT, "Generated {$sqlPath} from {$csvPath}\n");
