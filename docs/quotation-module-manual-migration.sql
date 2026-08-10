-- Quotation Builder and Template Module
-- Target: MySQL 8.x
-- Run this once against the same database used by RMS-1.
-- Prerequisites: accounting_companies, ar_invoices, roles, permissions,
-- role_has_permissions, and migrations must already exist.
--
-- IMPORTANT: take a database backup first. The final section registers the
-- Laravel migration names so a later `php artisan migrate` will not rerun them.

SET NAMES utf8mb4;

CREATE TABLE `document_assets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` BIGINT UNSIGNED NOT NULL,
    `kind` VARCHAR(30) NOT NULL DEFAULT 'image',
    `disk` VARCHAR(50) NOT NULL DEFAULT 's3',
    `storage_key` VARCHAR(512) NOT NULL,
    `original_name` VARCHAR(255) NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `size_bytes` BIGINT UNSIGNED NOT NULL,
    `checksum_sha256` CHAR(64) NOT NULL,
    `uploaded_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `document_assets_disk_storage_key_unique` (`disk`, `storage_key`),
    KEY `document_assets_company_id_kind_index` (`company_id`, `kind`),
    CONSTRAINT `document_assets_company_id_foreign`
        FOREIGN KEY (`company_id`) REFERENCES `accounting_companies` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `company_document_profiles` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` BIGINT UNSIGNED NOT NULL,
    `legal_name_en` VARCHAR(255) NULL,
    `legal_name_ar` VARCHAR(255) NULL,
    `address_en` TEXT NULL,
    `address_ar` TEXT NULL,
    `phone` VARCHAR(50) NULL,
    `email` VARCHAR(255) NULL,
    `website` VARCHAR(255) NULL,
    `commercial_registration` VARCHAR(100) NULL,
    `tax_registration` VARCHAR(100) NULL,
    `brand_color` VARCHAR(7) NOT NULL DEFAULT '#1F2937',
    `default_terms_en` TEXT NULL,
    `default_terms_ar` TEXT NULL,
    `logo_asset_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `company_document_profiles_company_id_unique` (`company_id`),
    KEY `company_document_profiles_logo_asset_id_foreign` (`logo_asset_id`),
    CONSTRAINT `company_document_profiles_company_id_foreign`
        FOREIGN KEY (`company_id`) REFERENCES `accounting_companies` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `company_document_profiles_logo_asset_id_foreign`
        FOREIGN KEY (`logo_asset_id`) REFERENCES `document_assets` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_templates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` BIGINT UNSIGNED NOT NULL,
    `type` VARCHAR(40) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `current_version_id` BIGINT UNSIGNED NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `updated_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `document_templates_company_id_type_is_active_index` (`company_id`, `type`, `is_active`),
    KEY `document_templates_company_id_type_is_default_index` (`company_id`, `type`, `is_default`),
    CONSTRAINT `document_templates_company_id_foreign`
        FOREIGN KEY (`company_id`) REFERENCES `accounting_companies` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_template_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_template_id` BIGINT UNSIGNED NOT NULL,
    `version` INT UNSIGNED NOT NULL,
    `schema_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `page_settings` JSON NOT NULL,
    `styles` JSON NOT NULL,
    `table_columns` JSON NOT NULL,
    `blocks` JSON NOT NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `document_template_versions_template_version_unique` (`document_template_id`, `version`),
    CONSTRAINT `document_template_versions_document_template_id_foreign`
        FOREIGN KEY (`document_template_id`) REFERENCES `document_templates` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `document_templates`
    ADD CONSTRAINT `document_templates_current_version_id_foreign`
        FOREIGN KEY (`current_version_id`) REFERENCES `document_template_versions` (`id`)
        ON DELETE SET NULL;

CREATE TABLE `quotations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` BIGINT UNSIGNED NOT NULL,
    `branch_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NULL,
    `template_version_id` BIGINT UNSIGNED NULL,
    `duplicated_from_quotation_id` BIGINT UNSIGNED NULL,
    `quotation_number` VARCHAR(40) NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'draft',
    `current_revision` INT UNSIGNED NOT NULL DEFAULT 0,
    `issue_date` DATE NOT NULL,
    `valid_until` DATE NOT NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'QAR',
    `recipient_name` VARCHAR(255) NOT NULL,
    `recipient_contact_name` VARCHAR(255) NULL,
    `recipient_email` VARCHAR(255) NULL,
    `recipient_phone` VARCHAR(50) NULL,
    `recipient_address` TEXT NULL,
    `page_settings` JSON NOT NULL,
    `styles` JSON NOT NULL,
    `table_columns` JSON NOT NULL,
    `blocks` JSON NOT NULL,
    `gross_subtotal_cents` BIGINT NOT NULL DEFAULT 0,
    `line_discount_total_cents` BIGINT NOT NULL DEFAULT 0,
    `subtotal_cents` BIGINT NOT NULL DEFAULT 0,
    `quotation_discount_type` VARCHAR(20) NULL,
    `quotation_discount_value` BIGINT NOT NULL DEFAULT 0,
    `quotation_discount_cents` BIGINT NOT NULL DEFAULT 0,
    `discount_total_cents` BIGINT NOT NULL DEFAULT 0,
    `total_cents` BIGINT NOT NULL DEFAULT 0,
    `converted_invoice_id` BIGINT UNSIGNED NULL,
    `converted_at` TIMESTAMP NULL DEFAULT NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `updated_by` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `quotations_branch_id_quotation_number_unique` (`branch_id`, `quotation_number`),
    KEY `quotations_company_id_status_issue_date_index` (`company_id`, `status`, `issue_date`),
    KEY `quotations_branch_id_status_valid_until_index` (`branch_id`, `status`, `valid_until`),
    KEY `quotations_customer_id_status_index` (`customer_id`, `status`),
    KEY `quotations_template_version_id_foreign` (`template_version_id`),
    KEY `quotations_duplicated_from_quotation_id_foreign` (`duplicated_from_quotation_id`),
    KEY `quotations_converted_invoice_id_foreign` (`converted_invoice_id`),
    CONSTRAINT `quotations_company_id_foreign`
        FOREIGN KEY (`company_id`) REFERENCES `accounting_companies` (`id`)
        ON DELETE RESTRICT,
    CONSTRAINT `quotations_template_version_id_foreign`
        FOREIGN KEY (`template_version_id`) REFERENCES `document_template_versions` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `quotations_duplicated_from_quotation_id_foreign`
        FOREIGN KEY (`duplicated_from_quotation_id`) REFERENCES `quotations` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `quotations_converted_invoice_id_foreign`
        FOREIGN KEY (`converted_invoice_id`) REFERENCES `ar_invoices` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- branches.id is a legacy signed INT, so branch ownership is validated in
-- the application service instead of using a mismatched foreign key here.

CREATE TABLE `quotation_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `quotation_id` BIGINT UNSIGNED NOT NULL,
    `menu_item_id` INT UNSIGNED NULL,
    `description` VARCHAR(255) NOT NULL,
    `unit` VARCHAR(40) NULL,
    `quantity` DECIMAL(14,3) NOT NULL,
    `unit_price_cents` BIGINT NOT NULL,
    `discount_cents` BIGINT NOT NULL DEFAULT 0,
    `line_total_cents` BIGINT NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `catalog_snapshot` JSON NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `quotation_items_quotation_id_sort_order_index` (`quotation_id`, `sort_order`),
    KEY `quotation_items_menu_item_id_index` (`menu_item_id`),
    CONSTRAINT `quotation_items_quotation_id_foreign`
        FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quotation_versions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `quotation_id` BIGINT UNSIGNED NOT NULL,
    `revision` INT UNSIGNED NOT NULL,
    `quotation_number` VARCHAR(40) NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'sent',
    `snapshot` JSON NOT NULL,
    `subtotal_cents` BIGINT NOT NULL DEFAULT 0,
    `discount_total_cents` BIGINT NOT NULL DEFAULT 0,
    `total_cents` BIGINT NOT NULL DEFAULT 0,
    `created_by` BIGINT UNSIGNED NULL,
    `finalized_at` TIMESTAMP NOT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `quotation_versions_quotation_id_revision_unique` (`quotation_id`, `revision`),
    CONSTRAINT `quotation_versions_quotation_id_foreign`
        FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quotation_artifacts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `quotation_version_id` BIGINT UNSIGNED NOT NULL,
    `format` VARCHAR(10) NOT NULL,
    `disk` VARCHAR(50) NOT NULL DEFAULT 's3',
    `storage_key` VARCHAR(512) NULL,
    `mime_type` VARCHAR(100) NULL,
    `size_bytes` BIGINT UNSIGNED NULL,
    `checksum_sha256` CHAR(64) NULL,
    `generation_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
    `generated_at` TIMESTAMP NULL DEFAULT NULL,
    `error_message` TEXT NULL,
    `created_at` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `quotation_artifacts_quotation_version_id_format_unique` (`quotation_version_id`, `format`),
    KEY `quotation_artifacts_generation_status_created_at_index` (`generation_status`, `created_at`),
    CONSTRAINT `quotation_artifacts_quotation_version_id_foreign`
        FOREIGN KEY (`quotation_version_id`) REFERENCES `quotation_versions` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quotation_status_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `quotation_id` BIGINT UNSIGNED NOT NULL,
    `quotation_version_id` BIGINT UNSIGNED NULL,
    `event` VARCHAR(30) NOT NULL,
    `from_status` VARCHAR(30) NULL,
    `to_status` VARCHAR(30) NULL,
    `note` TEXT NULL,
    `metadata` JSON NULL,
    `actor_id` BIGINT UNSIGNED NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `quotation_status_events_quotation_id_created_at_index` (`quotation_id`, `created_at`),
    KEY `quotation_status_events_event_created_at_index` (`event`, `created_at`),
    KEY `quotation_status_events_quotation_version_id_foreign` (`quotation_version_id`),
    CONSTRAINT `quotation_status_events_quotation_id_foreign`
        FOREIGN KEY (`quotation_id`) REFERENCES `quotations` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `quotation_status_events_quotation_version_id_foreign`
        FOREIGN KEY (`quotation_version_id`) REFERENCES `quotation_versions` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ar_invoices`
    ADD COLUMN `source_quotation_id` BIGINT UNSIGNED NULL AFTER `source_pastry_order_id`,
    ADD COLUMN `source_quotation_version_id` BIGINT UNSIGNED NULL AFTER `source_quotation_id`,
    ADD UNIQUE KEY `ar_invoices_source_quotation_unique` (`source_quotation_id`),
    ADD UNIQUE KEY `ar_invoices_source_quotation_version_unique` (`source_quotation_version_id`),
    ADD CONSTRAINT `ar_invoices_source_quotation_id_foreign`
        FOREIGN KEY (`source_quotation_id`) REFERENCES `quotations` (`id`)
        ON DELETE SET NULL,
    ADD CONSTRAINT `ar_invoices_source_quotation_version_id_foreign`
        FOREIGN KEY (`source_quotation_version_id`) REFERENCES `quotation_versions` (`id`)
        ON DELETE SET NULL;

-- Permissions
INSERT IGNORE INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`) VALUES
    ('quotations.access', 'web', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('quotations.manage', 'web', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('quotation-templates.manage', 'web', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
    ('quotations.convert', 'web', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id
FROM `permissions` p
JOIN `roles` r
  ON r.guard_name = 'web'
WHERE p.guard_name = 'web'
  AND (
      (p.name IN ('quotations.access', 'quotations.manage')
       AND r.name IN ('admin', 'manager', 'cashier'))
      OR
      (p.name IN ('quotation-templates.manage', 'quotations.convert')
       AND r.name IN ('admin', 'manager'))
  );

-- Default company profiles
INSERT IGNORE INTO `company_document_profiles` (
    `company_id`, `legal_name_en`, `brand_color`, `created_at`, `updated_at`
)
SELECT c.id, c.name, '#1F2937', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `accounting_companies` c;

-- Create a default quotation template for companies that do not have one.
INSERT INTO `document_templates` (
    `company_id`, `type`, `name`, `is_active`, `is_default`, `created_at`, `updated_at`
)
SELECT c.id, 'quotation', 'Default Quotation', 1, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `accounting_companies` c
WHERE NOT EXISTS (
    SELECT 1
    FROM `document_templates` dt
    WHERE dt.company_id = c.id
      AND dt.type = 'quotation'
      AND dt.is_default = 1
);

-- Create immutable version 1 for every default quotation template missing it.
INSERT INTO `document_template_versions` (
    `document_template_id`, `version`, `schema_version`,
    `page_settings`, `styles`, `table_columns`, `blocks`,
    `created_at`, `updated_at`
)
SELECT
    dt.id,
    1,
    1,
    JSON_OBJECT(
        'size', 'A4',
        'orientation', 'portrait',
        'margins_mm', JSON_OBJECT('top', 15, 'right', 15, 'bottom', 15, 'left', 15)
    ),
    JSON_OBJECT(
        'font_family', 'DejaVu Sans',
        'font_size', 10,
        'heading_font_family', 'DejaVu Sans',
        'text_color', '#111827',
        'accent_color', '#1F2937',
        'default_direction', 'auto'
    ),
    JSON_OBJECT(
        'description', CAST('true' AS JSON),
        'quantity', CAST('true' AS JSON),
        'unit', CAST('true' AS JSON),
        'unit_price', CAST('true' AS JSON),
        'discount', CAST('true' AS JSON),
        'total', CAST('true' AS JSON)
    ),
    CAST('[{"id":"company-header","type":"company_header","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"stretch","show_logo":true,"logo_position":"start","alignment":"start"}},{"id":"quotation-meta","type":"quotation_metadata","direction":"auto","settings":{"column_span":6,"new_row":true,"horizontal_alignment":"stretch"}},{"id":"recipient","type":"recipient_details","direction":"auto","settings":{"column_span":6,"new_row":false,"horizontal_alignment":"stretch"}},{"id":"items","type":"items_table","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"stretch"}},{"id":"totals","type":"totals","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"end"}},{"id":"terms","type":"terms","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"stretch"}},{"id":"signatures","type":"signature_lines","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"stretch","labels":["Prepared by","Accepted by"]}}]' AS JSON),
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `document_templates` dt
WHERE dt.type = 'quotation'
  AND dt.is_default = 1
  AND NOT EXISTS (
      SELECT 1
      FROM `document_template_versions` dtv
      WHERE dtv.document_template_id = dt.id
        AND dtv.version = 1
  );

UPDATE `document_templates` dt
JOIN `document_template_versions` dtv
  ON dtv.document_template_id = dt.id
 AND dtv.version = 1
SET dt.current_version_id = dtv.id,
    dt.updated_at = CURRENT_TIMESTAMP
WHERE dt.type = 'quotation'
  AND dt.is_default = 1
  AND dt.current_version_id IS NULL;

-- Seed the reusable Menu Proposal template for every company.
INSERT INTO `document_templates` (
    `company_id`, `type`, `name`, `is_active`, `is_default`,
    `created_at`, `updated_at`
)
SELECT c.id, 'quotation', 'Menu Proposal', 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM `accounting_companies` c
WHERE NOT EXISTS (
    SELECT 1
    FROM `document_templates` dt
    WHERE dt.company_id = c.id
      AND dt.type = 'quotation'
      AND dt.name = 'Menu Proposal'
);

INSERT INTO `document_template_versions` (
    `document_template_id`, `version`, `schema_version`,
    `page_settings`, `styles`, `table_columns`, `blocks`,
    `created_at`, `updated_at`
)
SELECT
    dt.id,
    1,
    1,
    JSON_OBJECT(
        'size', 'A4',
        'orientation', 'portrait',
        'margins_mm', JSON_OBJECT('top', 15, 'right', 15, 'bottom', 15, 'left', 15)
    ),
    JSON_OBJECT(
        'font_family', 'DejaVu Sans',
        'font_size', 10,
        'heading_font_family', 'DejaVu Sans',
        'text_color', '#111827',
        'accent_color', '#1F2937',
        'default_direction', 'auto',
        'document_mode', 'menu_proposal'
    ),
    JSON_OBJECT(
        'description', CAST('true' AS JSON),
        'quantity', CAST('true' AS JSON),
        'unit', CAST('true' AS JSON),
        'unit_price', CAST('true' AS JSON),
        'discount', CAST('true' AS JSON),
        'total', CAST('true' AS JSON)
    ),
    CAST('[{"id":"menu-company-header","type":"company_header","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"center","show_logo":true,"show_details":false,"logo_position":"top","alignment":"center"}},{"id":"menu-title","type":"rich_text","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"center"},"content":{"type":"doc","content":[{"type":"heading","attrs":{"level":1,"textAlign":"center"},"content":[{"type":"text","text":"Buffet Menu Proposal"}]}]}},{"id":"menu-pricing","type":"menu_pricing","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"center"}},{"id":"menu-salads","type":"rich_text","direction":"auto","settings":{"column_span":6,"new_row":true,"horizontal_alignment":"center"},"content":{"type":"doc","content":[{"type":"heading","attrs":{"level":2,"textAlign":"center"},"content":[{"type":"text","text":"Salads"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Tabbouleh"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Caesar Salad"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Rocca Salad"}]}]}},{"id":"menu-soup","type":"rich_text","direction":"auto","settings":{"column_span":6,"new_row":false,"horizontal_alignment":"center"},"content":{"type":"doc","content":[{"type":"heading","attrs":{"level":2,"textAlign":"center"},"content":[{"type":"text","text":"Soup"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Vegetables Soup"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Wild Mushroom Truffle Soup"}]}]}},{"id":"menu-cold-appetizers","type":"rich_text","direction":"auto","settings":{"column_span":6,"new_row":true,"horizontal_alignment":"center"},"content":{"type":"doc","content":[{"type":"heading","attrs":{"level":2,"textAlign":"center"},"content":[{"type":"text","text":"Cold Appetizers"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Hommus"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Mtabal"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Warak Inab"}]}]}},{"id":"menu-hot-appetizers","type":"rich_text","direction":"auto","settings":{"column_span":6,"new_row":false,"horizontal_alignment":"center"},"content":{"type":"doc","content":[{"type":"heading","attrs":{"level":2,"textAlign":"center"},"content":[{"type":"text","text":"Hot Appetizers"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Assorted Mix Mouajanat"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Sambousik"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Cheese Rolls"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Kebbeh"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Fatayer"}]}]}},{"id":"menu-main-courses","type":"rich_text","direction":"auto","settings":{"column_span":6,"new_row":true,"horizontal_alignment":"center"},"content":{"type":"doc","content":[{"type":"heading","attrs":{"level":2,"textAlign":"center"},"content":[{"type":"text","text":"Main Courses"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Chicken with Oriental Rice"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Grilled Chicken"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Beef Stroganoff with White Rice"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Chicken Curry with Rice"}]}]}},{"id":"menu-desserts","type":"rich_text","direction":"auto","settings":{"column_span":6,"new_row":false,"horizontal_alignment":"center"},"content":{"type":"doc","content":[{"type":"heading","attrs":{"level":2,"textAlign":"center"},"content":[{"type":"text","text":"Desserts"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Mouhalabiye"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Fruit Platter"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Mini Cake"}]}]}},{"id":"menu-bakery","type":"rich_text","direction":"auto","settings":{"column_span":6,"new_row":true,"horizontal_alignment":"center"},"content":{"type":"doc","content":[{"type":"heading","attrs":{"level":2,"textAlign":"center"},"content":[{"type":"text","text":"Bakery"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Fresh Arabic Bread"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Artisan Bread Basket"}]}]}},{"id":"menu-beverages","type":"rich_text","direction":"auto","settings":{"column_span":6,"new_row":false,"horizontal_alignment":"center"},"content":{"type":"doc","content":[{"type":"heading","attrs":{"level":2,"textAlign":"center"},"content":[{"type":"text","text":"Beverages"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Fresh Juices"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Soft Drinks"}]},{"type":"paragraph","attrs":{"textAlign":"center"},"content":[{"type":"text","text":"Mineral Water"}]}]}},{"id":"menu-items","type":"items_table","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"stretch","hidden":true}},{"id":"menu-totals","type":"totals","direction":"auto","settings":{"column_span":12,"new_row":true,"horizontal_alignment":"end","hidden":true}}]' AS JSON),
    CURRENT_TIMESTAMP,
    CURRENT_TIMESTAMP
FROM `document_templates` dt
WHERE dt.type = 'quotation'
  AND dt.name = 'Menu Proposal'
  AND NOT EXISTS (
      SELECT 1
      FROM `document_template_versions` dtv
      WHERE dtv.document_template_id = dt.id
  );

UPDATE `document_templates` dt
JOIN `document_template_versions` dtv
  ON dtv.document_template_id = dt.id
 AND dtv.version = 1
SET dt.current_version_id = dtv.id,
    dt.updated_at = CURRENT_TIMESTAMP
WHERE dt.type = 'quotation'
  AND dt.name = 'Menu Proposal'
  AND dt.current_version_id IS NULL;

-- Register these manual changes in Laravel's migration history.
SET @quotation_migration_batch := (SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_21_000100_create_quotation_domain_tables', @quotation_migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_07_21_000100_create_quotation_domain_tables'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_21_000101_add_quotation_sources_to_ar_invoices', @quotation_migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_07_21_000101_add_quotation_sources_to_ar_invoices'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_21_000102_seed_quotation_permissions_and_defaults', @quotation_migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_07_21_000102_seed_quotation_permissions_and_defaults'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_07_22_000100_seed_menu_proposal_quotation_templates', @quotation_migration_batch
WHERE NOT EXISTS (
    SELECT 1 FROM `migrations`
    WHERE `migration` = '2026_07_22_000100_seed_menu_proposal_quotation_templates'
);

-- The additive 000101 PHP migration is intentionally left pending. It upgrades
-- already-customized Menu Proposal templates while preserving edited categories.
-- Run `php artisan migrate` once after this manual baseline script.

-- Verification summary
SELECT 'document_assets' AS table_name, COUNT(*) AS row_count FROM `document_assets`
UNION ALL SELECT 'company_document_profiles', COUNT(*) FROM `company_document_profiles`
UNION ALL SELECT 'document_templates', COUNT(*) FROM `document_templates`
UNION ALL SELECT 'document_template_versions', COUNT(*) FROM `document_template_versions`
UNION ALL SELECT 'quotations', COUNT(*) FROM `quotations`
UNION ALL SELECT 'quotation_items', COUNT(*) FROM `quotation_items`
UNION ALL SELECT 'quotation_versions', COUNT(*) FROM `quotation_versions`
UNION ALL SELECT 'quotation_artifacts', COUNT(*) FROM `quotation_artifacts`
UNION ALL SELECT 'quotation_status_events', COUNT(*) FROM `quotation_status_events`;
