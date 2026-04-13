-- =============================================================================
-- Pastry Orders Module — Full Setup Script
-- Run once on a fresh database. All statements are idempotent.
-- Requires: branches, customers, menu_items, categories, ar_invoices,
--           permissions, roles, role_has_permissions, document_sequences
-- =============================================================================

-- ---------------------------------------------------------------------------
-- 1. Core tables
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `pastry_orders` (
  `id`                         bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_number`               varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id`                  int(10) unsigned DEFAULT NULL,
  `status`                     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Draft',
  `type`                       varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pickup',
  `customer_id`                int(10) unsigned DEFAULT NULL,
  `customer_name_snapshot`     varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_phone_snapshot`    varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_address_snapshot`  text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scheduled_date`             date DEFAULT NULL,
  `scheduled_time`             time DEFAULT NULL,
  `notes`                      text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_discount_amount`      decimal(12,3) NOT NULL DEFAULT 0.000,
  `total_before_tax`           decimal(12,3) NOT NULL DEFAULT 0.000,
  `tax_amount`                 decimal(12,3) NOT NULL DEFAULT 0.000,
  `total_amount`               decimal(12,3) NOT NULL DEFAULT 0.000,
  `invoiced_at`                datetime DEFAULT NULL,
  `created_by`                 int(10) unsigned DEFAULT NULL,
  `created_at`                 timestamp NULL DEFAULT NULL,
  `updated_at`                 timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pastry_orders_order_number_unique` (`order_number`),
  KEY `pastry_orders_branch_id_index` (`branch_id`),
  KEY `pastry_orders_status_index` (`status`),
  KEY `pastry_orders_scheduled_date_index` (`scheduled_date`),
  KEY `pastry_orders_customer_id_index` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `pastry_order_items` (
  `id`                   bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pastry_order_id`      bigint(20) unsigned NOT NULL,
  `menu_item_id`         int(10) unsigned DEFAULT NULL,
  `description_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity`             decimal(12,3) NOT NULL,
  `unit_price`           decimal(12,3) NOT NULL,
  `discount_amount`      decimal(12,3) NOT NULL DEFAULT 0.000,
  `line_total`           decimal(12,3) NOT NULL,
  `status`               varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Pending',
  `sort_order`           int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `pastry_order_items_pastry_order_id_index` (`pastry_order_id`),
  CONSTRAINT `pastry_order_items_pastry_order_id_foreign`
    FOREIGN KEY (`pastry_order_id`) REFERENCES `pastry_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `pastry_order_images` (
  `id`               bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pastry_order_id`  bigint(20) unsigned NOT NULL,
  `image_path`       varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image_disk`       varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 's3',
  `sort_order`       int(11) NOT NULL DEFAULT 0,
  `created_at`       timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `pastry_order_images_pastry_order_id_index` (`pastry_order_id`),
  CONSTRAINT `pastry_order_images_pastry_order_id_foreign`
    FOREIGN KEY (`pastry_order_id`) REFERENCES `pastry_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- 2. AR invoices — link column
-- ---------------------------------------------------------------------------

ALTER TABLE `ar_invoices`
  ADD COLUMN IF NOT EXISTS `source_pastry_order_id` bigint(20) unsigned DEFAULT NULL
    AFTER `source_order_id`;

-- Add FK only if it doesn't already exist
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME        = 'ar_invoices'
    AND CONSTRAINT_NAME   = 'ar_invoices_source_pastry_order_id_foreign'
    AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
);

SET @sql = IF(
  @fk_exists = 0,
  'ALTER TABLE `ar_invoices`
     ADD CONSTRAINT `ar_invoices_source_pastry_order_id_foreign`
     FOREIGN KEY (`source_pastry_order_id`) REFERENCES `pastry_orders` (`id`) ON DELETE SET NULL,
     ADD INDEX `ar_invoices_source_pastry_order_id_index` (`source_pastry_order_id`)',
  'SELECT 1 -- FK already exists, skipping'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- ---------------------------------------------------------------------------
-- 3. Permissions
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
VALUES ('pastry-orders.manage', 'web', NOW(), NOW());

INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id
FROM `permissions` p
JOIN `roles` r ON r.guard_name = 'web' AND r.name IN ('admin', 'manager', 'cashier')
WHERE p.name = 'pastry-orders.manage' AND p.guard_name = 'web';


-- ---------------------------------------------------------------------------
-- 4. Menu category — "Pastry Items"
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO `categories` (`name`, `created_at`, `updated_at`)
SELECT 'Pastry Items', NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM `categories`
  WHERE `name` = 'Pastry Items' AND `deleted_at` IS NULL
);


-- ---------------------------------------------------------------------------
-- NOTE on order number sequences
-- ---------------------------------------------------------------------------
-- Pastry order numbers (PST{year}-XXXXXX) are generated by DocumentSequenceService
-- using type = 'pastry_order' and branch_id = 0 (normalised to 1 internally).
-- No separate sequence table is needed.
-- =============================================================================
