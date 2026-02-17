-- Company Food Module - Production SQL
-- Run this on production to create all tables for the Company Food module.
-- Compatible with MySQL 8.0+ / MariaDB 10.4+
--
-- WARNING: The DROP statements below will delete existing data. Remove them
-- if the tables already exist and you only need to add missing tables.

DROP TABLE IF EXISTS `company_food_orders`;
DROP TABLE IF EXISTS `company_food_list_categories`;
DROP TABLE IF EXISTS `company_food_employees`;
DROP TABLE IF EXISTS `company_food_employee_lists`;
DROP TABLE IF EXISTS `company_food_options`;
DROP TABLE IF EXISTS `company_food_projects`;

CREATE TABLE `company_food_projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `slug` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_food_projects_slug_unique` (`slug`),
  KEY `company_food_projects_slug_index` (`slug`),
  KEY `company_food_projects_is_active_index` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table: company_food_employee_lists
-- ------------------------------------------------------

CREATE TABLE `company_food_employee_lists` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_food_employee_lists_project_id_foreign` (`project_id`),
  CONSTRAINT `company_food_employee_lists_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `company_food_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table: company_food_list_categories
-- ------------------------------------------------------

CREATE TABLE `company_food_list_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `employee_list_id` bigint unsigned NOT NULL,
  `category` varchar(50) NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_food_list_categories_list_category_unique` (`employee_list_id`, `category`),
  CONSTRAINT `company_food_list_categories_employee_list_id_foreign` FOREIGN KEY (`employee_list_id`) REFERENCES `company_food_employee_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table: company_food_options
-- ------------------------------------------------------

CREATE TABLE `company_food_options` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `menu_date` date NOT NULL,
  `category` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_food_options_project_id_foreign` (`project_id`),
  KEY `company_food_options_project_date_category_index` (`project_id`, `menu_date`, `category`),
  KEY `company_food_options_project_active_index` (`project_id`, `is_active`),
  CONSTRAINT `company_food_options_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `company_food_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table: company_food_employees
-- ------------------------------------------------------

CREATE TABLE `company_food_employees` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `employee_list_id` bigint unsigned NOT NULL,
  `employee_name` varchar(255) NOT NULL,
  `sort_order` int unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `company_food_employees_project_id_foreign` (`project_id`),
  KEY `company_food_employees_employee_list_id_foreign` (`employee_list_id`),
  KEY `company_food_employees_list_employee_index` (`employee_list_id`, `employee_name`),
  CONSTRAINT `company_food_employees_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `company_food_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_food_employees_employee_list_id_foreign` FOREIGN KEY (`employee_list_id`) REFERENCES `company_food_employee_lists` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table: company_food_orders
-- ------------------------------------------------------

CREATE TABLE `company_food_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `employee_list_id` bigint unsigned NOT NULL,
  `order_date` date NOT NULL,
  `employee_name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `edit_token` char(36) NOT NULL,
  `salad_option_id` bigint unsigned DEFAULT NULL,
  `appetizer_option_id_1` bigint unsigned DEFAULT NULL,
  `appetizer_option_id_2` bigint unsigned DEFAULT NULL,
  `main_option_id` bigint unsigned DEFAULT NULL,
  `sweet_option_id` bigint unsigned DEFAULT NULL,
  `location_option_id` bigint unsigned DEFAULT NULL,
  `soup_option_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `company_food_orders_edit_token_unique` (`edit_token`),
  KEY `company_food_orders_project_date_index` (`project_id`, `order_date`),
  KEY `company_food_orders_project_employee_index` (`project_id`, `employee_name`),
  KEY `company_food_orders_edit_token_index` (`edit_token`),
  KEY `company_food_orders_project_id_foreign` (`project_id`),
  KEY `company_food_orders_employee_list_id_foreign` (`employee_list_id`),
  KEY `company_food_orders_salad_option_id_foreign` (`salad_option_id`),
  KEY `company_food_orders_appetizer_option_id_1_foreign` (`appetizer_option_id_1`),
  KEY `company_food_orders_appetizer_option_id_2_foreign` (`appetizer_option_id_2`),
  KEY `company_food_orders_main_option_id_foreign` (`main_option_id`),
  KEY `company_food_orders_sweet_option_id_foreign` (`sweet_option_id`),
  KEY `company_food_orders_location_option_id_foreign` (`location_option_id`),
  KEY `company_food_orders_soup_option_id_foreign` (`soup_option_id`),
  CONSTRAINT `company_food_orders_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `company_food_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_food_orders_employee_list_id_foreign` FOREIGN KEY (`employee_list_id`) REFERENCES `company_food_employee_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `company_food_orders_salad_option_id_foreign` FOREIGN KEY (`salad_option_id`) REFERENCES `company_food_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `company_food_orders_appetizer_option_id_1_foreign` FOREIGN KEY (`appetizer_option_id_1`) REFERENCES `company_food_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `company_food_orders_appetizer_option_id_2_foreign` FOREIGN KEY (`appetizer_option_id_2`) REFERENCES `company_food_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `company_food_orders_main_option_id_foreign` FOREIGN KEY (`main_option_id`) REFERENCES `company_food_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `company_food_orders_sweet_option_id_foreign` FOREIGN KEY (`sweet_option_id`) REFERENCES `company_food_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `company_food_orders_location_option_id_foreign` FOREIGN KEY (`location_option_id`) REFERENCES `company_food_options` (`id`) ON DELETE SET NULL,
  CONSTRAINT `company_food_orders_soup_option_id_foreign` FOREIGN KEY (`soup_option_id`) REFERENCES `company_food_options` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
