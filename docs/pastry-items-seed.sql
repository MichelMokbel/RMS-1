-- =============================================================================
-- Pastry Items — menu_items seed
-- Requires: categories row with name='Pastry Items' and deleted_at IS NULL (id=33)
-- Idempotent: skips rows whose code already exists.
-- =============================================================================

-- 1. Ensure the "Pastry Items" category exists
INSERT IGNORE INTO `categories` (`name`, `created_at`, `updated_at`)
SELECT 'Pastry Items', NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `categories` WHERE `name` = 'Pastry Items' AND `deleted_at` IS NULL
);

-- 2. Resolve category id into a variable
SET @cat = (
  SELECT `id` FROM `categories`
  WHERE `name` = 'Pastry Items' AND `deleted_at` IS NULL
  LIMIT 1
);

-- 3. Insert items (skips any code that already exists)
INSERT INTO `menu_items`
  (`code`, `name`, `category_id`, `selling_price_per_unit`, `unit`, `tax_rate`, `is_active`, `display_order`, `created_at`, `updated_at`)
VALUES
  -- Individual items
  ('MI-000602', 'Cake Pops - Normal Design',   @cat,   8.000, 'each', '0.00', 1, 364, NOW(), NOW()),
  ('MI-000603', 'Cake Pops - 3D Design',       @cat,   8.000, 'each', '0.00', 1, 365, NOW(), NOW()),
  ('MI-000604', 'Cupcake - 2D',                @cat,   5.000, 'each', '0.00', 1, 366, NOW(), NOW()),
  ('MI-000605', 'Cupcake - 3D',                @cat,   8.000, 'each', '0.00', 1, 367, NOW(), NOW()),
  ('MI-000606', 'Decorated Cookies',           @cat,   7.000, 'each', '0.00', 1, 368, NOW(), NOW()),
  ('MI-000607', 'Macarons Normal',             @cat,   5.000, 'each', '0.00', 1, 369, NOW(), NOW()),

  -- Basic Cakes
  ('MI-000608', 'Basic Cake - Black Forest 10"',  @cat, 100.000, 'each', '0.00', 1, 370, NOW(), NOW()),
  ('MI-000609', 'Basic Cake - Red Velvet 10"',    @cat, 100.000, 'each', '0.00', 1, 371, NOW(), NOW()),
  ('MI-000610', 'Basic Cake - White Forest 10"',  @cat, 100.000, 'each', '0.00', 1, 372, NOW(), NOW()),
  ('MI-000611', 'Basic Cake - Black Forest 12"',  @cat, 145.000, 'each', '0.00', 1, 373, NOW(), NOW()),
  ('MI-000612', 'Basic Cake - Red Velvet 12"',    @cat, 145.000, 'each', '0.00', 1, 374, NOW(), NOW()),
  ('MI-000613', 'Basic Cake - White Forest 12"',  @cat, 145.000, 'each', '0.00', 1, 375, NOW(), NOW()),

  -- Basic Cakes with Edible Photo
  ('MI-000614', 'Basic Cake with Photo - Black Forest 10"',  @cat, 135.000, 'each', '0.00', 1, 376, NOW(), NOW()),
  ('MI-000615', 'Basic Cake with Photo - Red Velvet 10"',    @cat, 135.000, 'each', '0.00', 1, 377, NOW(), NOW()),
  ('MI-000616', 'Basic Cake with Photo - White Forest 10"',  @cat, 135.000, 'each', '0.00', 1, 378, NOW(), NOW()),
  ('MI-000617', 'Basic Cake with Photo - Black Forest 12"',  @cat, 185.000, 'each', '0.00', 1, 379, NOW(), NOW()),
  ('MI-000618', 'Basic Cake with Photo - Red Velvet 12"',    @cat, 185.000, 'each', '0.00', 1, 380, NOW(), NOW()),
  ('MI-000619', 'Basic Cake with Photo - White Forest 12"',  @cat, 185.000, 'each', '0.00', 1, 381, NOW(), NOW()),

  -- 2D Single-tier Cakes
  ('MI-000620', '2D Cake 6"  - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 245.000, 'each', '0.00', 1, 382, NOW(), NOW()),
  ('MI-000621', '2D Cake 8"  - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 295.000, 'each', '0.00', 1, 383, NOW(), NOW()),
  ('MI-000622', '2D Cake 10" - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 350.000, 'each', '0.00', 1, 384, NOW(), NOW()),
  ('MI-000623', '2D Cake 12" - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 450.000, 'each', '0.00', 1, 385, NOW(), NOW()),

  -- 3D Single-tier Cakes
  ('MI-000624', '3D Cake 6"  - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 235.000, 'each', '0.00', 1, 386, NOW(), NOW()),
  ('MI-000625', '3D Cake 8"  - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 250.000, 'each', '0.00', 1, 387, NOW(), NOW()),
  ('MI-000626', '3D Cake 10" - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 300.000, 'each', '0.00', 1, 388, NOW(), NOW()),
  ('MI-000627', '3D Cake 12" - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 395.000, 'each', '0.00', 1, 389, NOW(), NOW()),

  -- 3D 2-Layer Decorative Cakes
  ('MI-000628', '3D 2-Layer Decorative Cake 8"&6"   - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 495.000, 'each', '0.00', 1, 390, NOW(), NOW()),
  ('MI-000629', '3D 2-Layer Decorative Cake 10"&8"  - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 595.000, 'each', '0.00', 1, 391, NOW(), NOW()),
  ('MI-000630', '3D 2-Layer Decorative Cake 12"&10" - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 715.000, 'each', '0.00', 1, 392, NOW(), NOW()),

  -- 2D 3-Layer Decorative Cakes
  ('MI-000631', '2D 3-Layer Decorative Cake 10"/8"/6"  - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 735.000, 'each', '0.00', 1, 393, NOW(), NOW()),
  ('MI-000632', '2D 3-Layer Decorative Cake 12"/10"/6" - Choc/Vanilla/Nutella/Strawberry/Mango',  @cat, 830.000, 'each', '0.00', 1, 394, NOW(), NOW()),

  -- Croissants
  ('MI-000633', 'Croissant - Cheese',    @cat,  4.000, 'each', '0.00', 1, 395, NOW(), NOW()),
  ('MI-000634', 'Croissant - Chocolate', @cat,  4.000, 'each', '0.00', 1, 396, NOW(), NOW()),
  ('MI-000635', 'Croissant - Plain',     @cat,  4.000, 'each', '0.00', 1, 397, NOW(), NOW()),
  ('MI-000636', 'Croissant - Zaatar',    @cat,  4.000, 'each', '0.00', 1, 398, NOW(), NOW()),

  -- Mini Cheesecakes
  ('MI-000637', 'Mini Cheesecake 6" - Lotus',      @cat, 70.000, 'each', '0.00', 1, 399, NOW(), NOW()),
  ('MI-000638', 'Mini Cheesecake 6" - Blueberry',  @cat, 70.000, 'each', '0.00', 1, 400, NOW(), NOW()),
  ('MI-000639', 'Mini Cheesecake 6" - Strawberry', @cat, 70.000, 'each', '0.00', 1, 401, NOW(), NOW()),

  -- Mini Cakes
  ('MI-000640', 'Mini Cake 6" - Dark Chocolate', @cat, 60.000, 'each', '0.00', 1, 402, NOW(), NOW()),
  ('MI-000641', 'Mini Cake 6" - Carrot',         @cat, 60.000, 'each', '0.00', 1, 403, NOW(), NOW()),
  ('MI-000642', 'Mini Cake 6" - Red Velvet',     @cat, 60.000, 'each', '0.00', 1, 404, NOW(), NOW()),

  -- Other
  ('MI-000643', 'Cake Slice', @cat, 12.000, 'each', '0.00', 1, 405, NOW(), NOW())

AS new_rows
ON DUPLICATE KEY UPDATE `code` = new_rows.`code`; -- no-op update keeps INSERT IGNORE semantics
-- =============================================================================
