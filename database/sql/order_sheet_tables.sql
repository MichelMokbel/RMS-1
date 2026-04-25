-- =============================================================
-- Order Sheet Tables
-- Migrations:
--   2026_04_24_000001_create_order_sheet_tables
--   2026_04_24_000002_add_order_id_to_order_sheet_entries
-- =============================================================

CREATE TABLE `order_sheets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sheet_date` DATE            NOT NULL,
  `created_at` TIMESTAMP       NULL,
  `updated_at` TIMESTAMP       NULL,
  UNIQUE KEY `order_sheets_sheet_date_unique` (`sheet_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------

CREATE TABLE `order_sheet_entries` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `order_sheet_id`  BIGINT UNSIGNED NOT NULL,
  `customer_id`     INT             NULL,
  `customer_name`   VARCHAR(255)    NOT NULL,
  `location`        VARCHAR(255)    NULL,
  `remarks`         TEXT            NULL,
  `order_id`        INT             NULL,
  `created_at`      TIMESTAMP       NULL,
  `updated_at`      TIMESTAMP       NULL,
  CONSTRAINT `fk_ose_order_sheet`
    FOREIGN KEY (`order_sheet_id`) REFERENCES `order_sheets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ose_customer`
    FOREIGN KEY (`customer_id`)    REFERENCES `customers` (`id`)    ON DELETE SET NULL,
  CONSTRAINT `fk_ose_order`
    FOREIGN KEY (`order_id`)       REFERENCES `orders` (`id`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------

CREATE TABLE `order_sheet_entry_quantities` (
  `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `order_sheet_entry_id`    BIGINT UNSIGNED NOT NULL,
  `daily_dish_menu_item_id` BIGINT UNSIGNED NOT NULL,
  `quantity`                INT UNSIGNED    NOT NULL,
  CONSTRAINT `fk_oseq_entry`
    FOREIGN KEY (`order_sheet_entry_id`)    REFERENCES `order_sheet_entries` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_oseq_dish_item`
    FOREIGN KEY (`daily_dish_menu_item_id`) REFERENCES `daily_dish_menu_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------

CREATE TABLE `order_sheet_entry_extras` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `order_sheet_entry_id` BIGINT UNSIGNED NOT NULL,
  `menu_item_id`         INT             NOT NULL,
  `menu_item_name`       VARCHAR(255)    NOT NULL,
  `quantity`             INT UNSIGNED    NOT NULL DEFAULT 1,
  CONSTRAINT `fk_osee_entry`
    FOREIGN KEY (`order_sheet_entry_id`) REFERENCES `order_sheet_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_osee_menu_item`
    FOREIGN KEY (`menu_item_id`)         REFERENCES `menu_items` (`id`)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
