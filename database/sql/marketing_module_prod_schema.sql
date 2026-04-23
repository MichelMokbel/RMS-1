-- Marketing module production schema
-- Generated from:
-- database/migrations/2026_04_21_000001_create_marketing_foundation_tables.php
-- database/migrations/2026_04_21_000002_seed_marketing_permissions.php
--
-- Target: MySQL/MariaDB, InnoDB, utf8mb4
-- Run against the production database selected with USE your_database_name;

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS `marketing_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `meta_app_id` VARCHAR(255) NULL,
  `meta_app_secret` TEXT NULL,
  `meta_system_user_token` TEXT NULL,
  `meta_business_id` VARCHAR(255) NULL,
  `google_developer_token` TEXT NULL,
  `google_login_customer_id` VARCHAR(255) NULL,
  `google_client_id` VARCHAR(255) NULL,
  `google_client_secret` TEXT NULL,
  `google_refresh_token` TEXT NULL,
  `s3_asset_bucket` VARCHAR(255) NULL,
  `meta_sync_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `google_sync_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_platform_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform` VARCHAR(255) NOT NULL,
  `external_account_id` VARCHAR(255) NOT NULL,
  `account_name` VARCHAR(255) NOT NULL,
  `currency` VARCHAR(3) NULL,
  `timezone` VARCHAR(255) NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'active',
  `last_synced_at` TIMESTAMP NULL DEFAULT NULL,
  `sync_error` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mpa_platform_account_unique` (`platform`, `external_account_id`),
  KEY `mpa_platform_status_index` (`platform`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_campaigns` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_account_id` BIGINT UNSIGNED NOT NULL,
  `external_campaign_id` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `status` VARCHAR(255) NOT NULL,
  `objective` VARCHAR(255) NULL,
  `daily_budget_micro` BIGINT NULL,
  `lifetime_budget_micro` BIGINT NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `platform_data` JSON NULL,
  `last_synced_at` TIMESTAMP NULL DEFAULT NULL,
  `internal_notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mc_account_campaign_unique` (`platform_account_id`, `external_campaign_id`),
  KEY `mc_account_status_index` (`platform_account_id`, `status`),
  CONSTRAINT `marketing_campaigns_platform_account_id_foreign`
    FOREIGN KEY (`platform_account_id`) REFERENCES `marketing_platform_accounts` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_ad_sets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `external_adset_id` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `status` VARCHAR(255) NOT NULL,
  `daily_budget_micro` BIGINT NULL,
  `platform_data` JSON NULL,
  `last_synced_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mas_campaign_adset_unique` (`campaign_id`, `external_adset_id`),
  KEY `mas_campaign_status_index` (`campaign_id`, `status`),
  CONSTRAINT `marketing_ad_sets_campaign_id_foreign`
    FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_ads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad_set_id` BIGINT UNSIGNED NOT NULL,
  `external_ad_id` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `status` VARCHAR(255) NOT NULL,
  `creative_type` VARCHAR(255) NULL,
  `platform_data` JSON NULL,
  `last_synced_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ma_adset_ad_unique` (`ad_set_id`, `external_ad_id`),
  KEY `ma_adset_status_index` (`ad_set_id`, `status`),
  CONSTRAINT `marketing_ads_ad_set_id_foreign`
    FOREIGN KEY (`ad_set_id`) REFERENCES `marketing_ad_sets` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_spend_snapshots` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_account_id` BIGINT UNSIGNED NOT NULL,
  `campaign_id` BIGINT UNSIGNED NULL,
  `ad_set_id` BIGINT UNSIGNED NULL,
  `snapshot_date` DATE NOT NULL,
  `impressions` BIGINT NOT NULL DEFAULT 0,
  `clicks` BIGINT NOT NULL DEFAULT 0,
  `spend_micro` BIGINT NOT NULL DEFAULT 0,
  `conversions` INT NOT NULL DEFAULT 0,
  `platform_data` JSON NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mss_account_date_index` (`platform_account_id`, `snapshot_date`),
  KEY `mss_campaign_date_index` (`campaign_id`, `snapshot_date`),
  KEY `mss_adset_date_index` (`ad_set_id`, `snapshot_date`),
  CONSTRAINT `marketing_spend_snapshots_platform_account_id_foreign`
    FOREIGN KEY (`platform_account_id`) REFERENCES `marketing_platform_accounts` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `mss_campaign_fk`
    FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`)
    ON DELETE SET NULL,
  CONSTRAINT `mss_adset_fk`
    FOREIGN KEY (`ad_set_id`) REFERENCES `marketing_ad_sets` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_assets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `type` VARCHAR(255) NOT NULL,
  `s3_key` VARCHAR(255) NOT NULL,
  `s3_bucket` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(255) NULL,
  `file_size` BIGINT NULL,
  `width` INT NULL,
  `height` INT NULL,
  `duration_seconds` INT NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'pending_review',
  `current_version` INT NOT NULL DEFAULT 1,
  `uploaded_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mast_type_status_index` (`type`, `status`),
  KEY `mast_uploaded_by_index` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_asset_versions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` BIGINT UNSIGNED NOT NULL,
  `version_number` INT NOT NULL,
  `s3_key` VARCHAR(255) NOT NULL,
  `file_size` BIGINT NULL,
  `note` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mav_asset_version_unique` (`asset_id`, `version_number`),
  CONSTRAINT `marketing_asset_versions_asset_id_foreign`
    FOREIGN KEY (`asset_id`) REFERENCES `marketing_assets` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_asset_usages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `asset_id` BIGINT UNSIGNED NOT NULL,
  `usageable_type` VARCHAR(255) NOT NULL,
  `usageable_id` BIGINT UNSIGNED NOT NULL,
  `note` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mau_usageable_index` (`usageable_type`, `usageable_id`),
  KEY `mau_asset_index` (`asset_id`),
  CONSTRAINT `marketing_asset_usages_asset_id_foreign`
    FOREIGN KEY (`asset_id`) REFERENCES `marketing_assets` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_briefs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NULL,
  `campaign_id` BIGINT UNSIGNED NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'draft',
  `due_date` DATE NULL,
  `objectives` TEXT NULL,
  `target_audience` TEXT NULL,
  `budget_notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mb_status_index` (`status`),
  KEY `mb_campaign_index` (`campaign_id`),
  KEY `mb_created_by_index` (`created_by`),
  CONSTRAINT `mb_campaign_fk`
    FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_comments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `commentable_type` VARCHAR(255) NOT NULL,
  `commentable_id` BIGINT UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mc_commentable_index` (`commentable_type`, `commentable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_approvals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `approvable_type` VARCHAR(255) NOT NULL,
  `approvable_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'pending',
  `reviewer_id` BIGINT UNSIGNED NULL,
  `note` TEXT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `map_approvable_index` (`approvable_type`, `approvable_id`),
  KEY `map_status_index` (`status`),
  KEY `map_reviewer_index` (`reviewer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_sync_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `platform_account_id` BIGINT UNSIGNED NOT NULL,
  `sync_type` VARCHAR(255) NOT NULL,
  `status` VARCHAR(255) NOT NULL DEFAULT 'pending',
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `records_synced` INT NOT NULL DEFAULT 0,
  `error_message` TEXT NULL,
  `context` JSON NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `msl_account_type_status_index` (`platform_account_id`, `sync_type`, `status`),
  KEY `msl_created_at_index` (`created_at`),
  CONSTRAINT `marketing_sync_logs_platform_account_id_foreign`
    FOREIGN KEY (`platform_account_id`) REFERENCES `marketing_platform_accounts` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_utms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campaign_id` BIGINT UNSIGNED NOT NULL,
  `utm_source` VARCHAR(255) NOT NULL,
  `utm_medium` VARCHAR(255) NOT NULL,
  `utm_campaign` VARCHAR(255) NOT NULL,
  `utm_content` VARCHAR(255) NULL,
  `utm_term` VARCHAR(255) NULL,
  `landing_page_url` VARCHAR(255) NOT NULL,
  `notes` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mu_campaign_index` (`campaign_id`),
  CONSTRAINT `marketing_utms_campaign_id_foreign`
    FOREIGN KEY (`campaign_id`) REFERENCES `marketing_campaigns` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `marketing_activity_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(255) NOT NULL,
  `subject_type` VARCHAR(255) NULL,
  `subject_id` BIGINT UNSIGNED NULL,
  `payload` JSON NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `mal_actor_index` (`actor_id`),
  KEY `mal_subject_index` (`subject_type`, `subject_id`),
  KEY `mal_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;

-- Seed Spatie permissions if the permission tables exist.
INSERT IGNORE INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'marketing.access', 'web', NOW(), NOW()
WHERE EXISTS (
  SELECT 1 FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'permissions'
);

INSERT IGNORE INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`)
SELECT 'marketing.manage', 'web', NOW(), NOW()
WHERE EXISTS (
  SELECT 1 FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'permissions'
);

-- admin gets marketing.access and marketing.manage.
INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id
FROM `permissions` p
JOIN `roles` r ON r.name = 'admin' AND r.guard_name = 'web'
WHERE p.guard_name = 'web'
  AND p.name IN ('marketing.access', 'marketing.manage')
  AND EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'role_has_permissions'
  );

-- manager gets marketing.access.
INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`)
SELECT p.id, r.id
FROM `permissions` p
JOIN `roles` r ON r.name = 'manager' AND r.guard_name = 'web'
WHERE p.guard_name = 'web'
  AND p.name = 'marketing.access'
  AND EXISTS (
    SELECT 1 FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'role_has_permissions'
  );

-- Mark these migrations as applied if the migrations table exists.
-- This prevents a future php artisan migrate from trying to recreate the same tables.
SET @marketing_batch := COALESCE((SELECT MAX(`batch`) + 1 FROM `migrations`), 1);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_04_21_000001_create_marketing_foundation_tables', @marketing_batch
WHERE EXISTS (
  SELECT 1 FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'migrations'
)
AND NOT EXISTS (
  SELECT 1 FROM `migrations`
  WHERE `migration` = '2026_04_21_000001_create_marketing_foundation_tables'
);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_04_21_000002_seed_marketing_permissions', @marketing_batch
WHERE EXISTS (
  SELECT 1 FROM information_schema.tables
  WHERE table_schema = DATABASE() AND table_name = 'migrations'
)
AND NOT EXISTS (
  SELECT 1 FROM `migrations`
  WHERE `migration` = '2026_04_21_000002_seed_marketing_permissions'
);
