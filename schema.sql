-- MySQL dump 10.13  Distrib 8.0.41, for macos15 (arm64)
--
-- Host: localhost    Database: store
-- ------------------------------------------------------
-- Server version	5.5.5-10.4.18-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `ap_invoice_items`
--

DROP TABLE IF EXISTS `ap_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ap_invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT 1.000,
  `unit_price` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `line_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `ap_invoice_items_invoice_id_index` (`invoice_id`),
  CONSTRAINT `ap_invoice_items_invoice_fk` FOREIGN KEY (`invoice_id`) REFERENCES `ap_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ap_invoice_items_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `ap_invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_invoice_items`
--

LOCK TABLES `ap_invoice_items` WRITE;
/*!40000 ALTER TABLE `ap_invoice_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `ap_invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ap_invoices`
--

DROP TABLE IF EXISTS `ap_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ap_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `is_expense` tinyint(1) NOT NULL DEFAULT 0,
  `purchase_order_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(100) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','posted','partially_paid','paid','void') DEFAULT 'draft',
  `posted_at` timestamp NULL DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_supplier_invoice` (`supplier_id`,`invoice_number`),
  UNIQUE KEY `ap_invoices_supplier_invoice_unique` (`supplier_id`,`invoice_number`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_ap_invoices_supplier` (`supplier_id`),
  KEY `idx_ap_invoices_due` (`due_date`),
  KEY `idx_ap_invoices_category` (`category_id`),
  KEY `ap_invoices_supplier_id_index` (`supplier_id`),
  KEY `ap_invoices_status_index` (`status`),
  KEY `ap_invoices_invoice_date_index` (`invoice_date`),
  KEY `ap_invoices_due_date_index` (`due_date`),
  KEY `ap_invoices_po_id_index` (`purchase_order_id`),
  KEY `ap_invoices_category_id_index` (`category_id`),
  KEY `ap_invoices_posted_by_fk` (`posted_by`),
  KEY `ap_invoices_voided_by_fk` (`voided_by`),
  CONSTRAINT `ap_invoices_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ap_invoices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ap_invoices_posted_by_fk` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `ap_invoices_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ap_invoices_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `ap_invoices_voided_by_fk` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_invoices`
--

LOCK TABLES `ap_invoices` WRITE;
/*!40000 ALTER TABLE `ap_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `ap_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ap_payment_allocations`
--

DROP TABLE IF EXISTS `ap_payment_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ap_payment_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `allocated_amount` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payment_invoice` (`payment_id`,`invoice_id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `ap_payment_allocations_payment_id_index` (`payment_id`),
  KEY `ap_payment_allocations_invoice_id_index` (`invoice_id`),
  KEY `ap_payment_allocations_voided_by_fk` (`voided_by`),
  CONSTRAINT `ap_payment_allocations_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `ap_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ap_payment_allocations_payment_id_foreign` FOREIGN KEY (`payment_id`) REFERENCES `ap_payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ap_payment_allocations_voided_by_fk` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_payment_allocations`
--

LOCK TABLES `ap_payment_allocations` WRITE;
/*!40000 ALTER TABLE `ap_payment_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `ap_payment_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ap_payments`
--

DROP TABLE IF EXISTS `ap_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ap_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','bank_transfer','card','cheque','other') DEFAULT 'bank_transfer',
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_ap_payments_supplier` (`supplier_id`),
  KEY `ap_payments_supplier_id_index` (`supplier_id`),
  KEY `ap_payments_payment_date_index` (`payment_date`),
  KEY `ap_payments_created_by_index` (`created_by`),
  KEY `ap_payments_posted_by_fk` (`posted_by`),
  KEY `ap_payments_voided_by_fk` (`voided_by`),
  CONSTRAINT `ap_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ap_payments_posted_by_fk` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `ap_payments_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `ap_payments_voided_by_fk` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_payments`
--

LOCK TABLES `ap_payments` WRITE;
/*!40000 ALTER TABLE `ap_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `ap_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ar_invoice_items`
--

DROP TABLE IF EXISTS `ar_invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ar_invoice_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `unit` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_price_cents` bigint(20) NOT NULL DEFAULT 0,
  `discount_cents` bigint(20) NOT NULL DEFAULT 0,
  `tax_cents` bigint(20) NOT NULL DEFAULT 0,
  `line_total_cents` bigint(20) NOT NULL DEFAULT 0,
  `line_notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sellable_type` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sellable_id` bigint(20) unsigned DEFAULT NULL,
  `name_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku_snapshot` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ar_invoice_items_invoice_id_index` (`invoice_id`),
  KEY `ar_invoice_items_sellable_index` (`sellable_type`,`sellable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ar_invoice_items`
--

LOCK TABLES `ar_invoice_items` WRITE;
/*!40000 ALTER TABLE `ar_invoice_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `ar_invoice_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ar_invoices`
--

DROP TABLE IF EXISTS `ar_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ar_invoices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `terminal_id` bigint(20) unsigned DEFAULT NULL,
  `pos_shift_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `source_sale_id` bigint(20) unsigned DEFAULT NULL,
  `source_order_id` bigint(20) unsigned DEFAULT NULL,
  `pos_reference` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `restaurant_table_id` bigint(20) unsigned DEFAULT NULL,
  `table_session_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dashboard',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'invoice',
  `invoice_number` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `payment_type` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_term_id` bigint(20) unsigned DEFAULT NULL,
  `payment_term_days` int(10) unsigned NOT NULL DEFAULT 0,
  `sales_person_id` bigint(20) unsigned DEFAULT NULL,
  `lpo_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issue_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'QAR',
  `subtotal_cents` bigint(20) NOT NULL DEFAULT 0,
  `discount_total_cents` bigint(20) NOT NULL DEFAULT 0,
  `invoice_discount_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `invoice_discount_value` bigint(20) NOT NULL DEFAULT 0,
  `invoice_discount_cents` bigint(20) NOT NULL DEFAULT 0,
  `tax_total_cents` bigint(20) NOT NULL DEFAULT 0,
  `total_cents` bigint(20) NOT NULL DEFAULT 0,
  `paid_total_cents` bigint(20) NOT NULL DEFAULT 0,
  `balance_cents` bigint(20) NOT NULL DEFAULT 0,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ar_invoices_branch_invoice_number_unique` (`branch_id`,`invoice_number`),
  UNIQUE KEY `ar_invoices_client_uuid_unique` (`client_uuid`),
  UNIQUE KEY `ar_invoices_branch_pos_reference_unique` (`branch_id`,`pos_reference`),
  KEY `ar_invoices_branch_status_issue_date_index` (`branch_id`,`status`,`issue_date`),
  KEY `ar_invoices_customer_status_index` (`customer_id`,`status`),
  KEY `ar_invoices_source_sale_id_index` (`source_sale_id`),
  KEY `ar_invoices_pos_reference_index` (`pos_reference`),
  KEY `ar_invoices_source_index` (`source`),
  KEY `ar_invoices_source_order_id_index` (`source_order_id`),
  KEY `ar_invoices_table_session_id_index` (`table_session_id`),
  KEY `ar_invoices_terminal_issue_date_index` (`terminal_id`,`issue_date`),
  KEY `ar_invoices_shift_fk` (`pos_shift_id`),
  KEY `ar_invoices_restaurant_table_fk` (`restaurant_table_id`),
  CONSTRAINT `ar_invoices_restaurant_table_fk` FOREIGN KEY (`restaurant_table_id`) REFERENCES `restaurant_tables` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ar_invoices_shift_fk` FOREIGN KEY (`pos_shift_id`) REFERENCES `pos_shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ar_invoices_table_session_fk` FOREIGN KEY (`table_session_id`) REFERENCES `restaurant_table_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ar_invoices_terminal_fk` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ar_invoices`
--

LOCK TABLES `ar_invoices` WRITE;
/*!40000 ALTER TABLE `ar_invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `ar_invoices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `status` enum('operational','maintenance','retired') DEFAULT 'operational',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_code` (`asset_code`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assets`
--

LOCK TABLES `assets` WRITE;
/*!40000 ALTER TABLE `assets` DISABLE KEYS */;
/*!40000 ALTER TABLE `assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
INSERT INTO `branches` VALUES (1,'Branch 1',NULL,1,'2026-01-28 06:27:09','2026-01-28 06:27:09'),(2,'Branch 2',NULL,1,NULL,NULL);
/*!40000 ALTER TABLE `branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache`
--

DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache`
--

LOCK TABLES `cache` WRITE;
/*!40000 ALTER TABLE `cache` DISABLE KEYS */;
INSERT INTO `cache` VALUES ('laravel-cache-1c31ecdcf43a4c45335e125fdd661c66','i:1;',1770207572),('laravel-cache-1c31ecdcf43a4c45335e125fdd661c66:timer','i:1770207572;',1770207572),('laravel-cache-5c785c036466adea360111aa28563bfd556b5fba','i:3;',1766328643),('laravel-cache-5c785c036466adea360111aa28563bfd556b5fba:timer','i:1766328643;',1766328643),('laravel-cache-spatie.permission.cache','a:3:{s:5:\"alias\";a:0:{}s:11:\"permissions\";a:0:{}s:5:\"roles\";a:0:{}}',1769800564);
/*!40000 ALTER TABLE `cache` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cache_locks`
--

DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cache_locks`
--

LOCK TABLES `cache_locks` WRITE;
/*!40000 ALTER TABLE `cache_locks` DISABLE KEYS */;
/*!40000 ALTER TABLE `cache_locks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `categories_name_index` (`name`),
  KEY `categories_parent_id_index` (`parent_id`),
  CONSTRAINT `categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Raw Materials','',NULL,'2025-10-29 09:51:36',NULL,NULL),(2,'Packaging and Accessories','Packaging and Accessories',NULL,'2025-10-29 09:51:36',NULL,NULL),(3,'Maintenance','Maintenance tools and materials',NULL,'2025-10-29 09:51:36',NULL,NULL),(4,'IT Equipment','Computers, servers, and networking equipment',NULL,'2025-10-29 09:51:36',NULL,NULL),(5,'Furniture','Office furniture and fixtures',NULL,'2025-10-29 09:51:36',NULL,NULL),(6,'Salads',NULL,NULL,'2025-12-20 11:57:21','2025-12-20 11:57:21',NULL),(7,'Cold Mezze',NULL,NULL,'2025-12-20 11:57:33','2025-12-20 11:57:33',NULL),(8,'Hot Mezze',NULL,NULL,'2025-12-20 11:57:38','2025-12-20 11:57:38',NULL),(9,'Main Dish',NULL,NULL,'2025-12-20 11:57:45','2025-12-20 11:57:45',NULL);
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `customer_type` enum('retail','corporate','subscription') NOT NULL DEFAULT 'retail',
  `contact_name` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `billing_address` text DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `default_payment_method_id` int(11) DEFAULT NULL,
  `credit_limit` decimal(12,3) NOT NULL DEFAULT 0.000,
  `credit_terms_days` int(11) NOT NULL DEFAULT 0,
  `credit_status` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customers_name` (`name`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_type` (`customer_type`),
  KEY `idx_customers_is_active` (`is_active`),
  KEY `idx_customers_payment` (`default_payment_method_id`),
  KEY `customers_customer_type_index` (`customer_type`),
  KEY `customers_is_active_index` (`is_active`),
  KEY `customers_phone_index` (`phone`),
  KEY `customers_email_index` (`email`),
  KEY `customers_created_by_foreign` (`created_by`),
  KEY `customers_updated_by_foreign` (`updated_by`),
  KEY `customers_name_index` (`name`),
  KEY `customers_customer_code_index` (`customer_code`),
  CONSTRAINT `customers_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customers_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=447 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'100001','GHADA MAALOUF','retail',NULL,'9613960175',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(2,'100002','jackie','retail',NULL,'97455872034',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(3,'100003','Rana Abilmona','retail',NULL,'97470507859',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(4,'100004','VICKY','retail',NULL,'55784194',NULL,NULL,NULL,'Qatar',NULL,3000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(5,'100005','Joyce','retail',NULL,'66543637',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',0,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(6,'100006','ABIR','retail',NULL,'66688230',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(7,'100007','PIA','retail',NULL,'66458801',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(8,'100008','TALAR','retail',NULL,'74770018',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(9,'100009','NADA','retail',NULL,'55341850',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(10,'100010','DIALA','retail',NULL,'55776288',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(11,'100011','NABIL','retail',NULL,'33447353',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(12,'100012','SAMIRA','retail',NULL,'96171194185',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(13,'100013','RAMA Kana','retail',NULL,'66334998',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(14,'100014','MARAH','retail',NULL,'33953636',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(15,'100015','CYNTHIA','retail',NULL,'55537909',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(16,'100016','NANCY','retail',NULL,'55072237',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(17,'100017','MANAL','retail',NULL,'96170578155',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(18,'100018','CARLA TARRAF','retail',NULL,'66594433',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(19,'100019','MARK Chidiac','retail',NULL,'55132686',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(20,'100020','MOUNIRA','retail',NULL,'55363500',NULL,NULL,NULL,'Qatar',NULL,2000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(21,'100021','CARINE','retail',NULL,'33097059',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',0,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(22,'100022','DALAL','retail',NULL,'55602663',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(23,'100023','Alex','retail',NULL,'50632385',NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(24,'100024','Amanda','retail',NULL,'66033398',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(25,'100025','Rouba El Khoury','retail',NULL,'55895004',NULL,NULL,NULL,NULL,NULL,3000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(26,'100026','Dory','retail',NULL,'66446635',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(27,'100027','Janet Chammas','retail',NULL,'97466264633',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(28,'100028','Romy Sengakis','retail',NULL,'97433062444',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(29,'100029','Marie Helene','retail',NULL,'9613868360',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(30,'100030','Bettina Hanna','retail',NULL,'97433839507',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(31,'100031','Emilie Bejjani','retail',NULL,'97452037491',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(32,'100032','Mirna Salem','retail',NULL,'97455862393',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(33,'100033','Carole Azar','retail',NULL,'97470364393',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(34,'100034','Janine','retail',NULL,'55231716',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(35,'100035','Carla Hanna','retail',NULL,'55063368',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(36,'100036','Wissam Hajj','retail',NULL,'33654184',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(37,'100037','Toni Ghanem','retail',NULL,'33747739',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(38,'100038','Emilie Bejjani','retail',NULL,'52037491',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'Credit OK',0,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(39,'100039','Aline Ghassan','retail',NULL,'33156655',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(40,'100040','Rita Chedid','retail',NULL,'30269268',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(41,'100041','Laudy Samaha','retail',NULL,'33199646',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(42,'100042','Yasmine Hasan','retail',NULL,'50357199',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(43,'100043','Mira Chaccour','retail',NULL,'66858975',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(44,'100044','Micheline Feghaly','retail',NULL,'55669625',NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(45,'100045','Layla Al Helou','retail',NULL,'77557753',NULL,NULL,NULL,'Qatar',NULL,3000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(46,'100046','Leila Al Helou','retail',NULL,'97477557753',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(47,'100047','Lina Mchantaf','retail',NULL,'97433685859',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(48,'100048','Remonde Abi Saleh','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(49,'100049','Rita Rahme','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(50,'100050','Nelly Frangieh','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(51,'100051','Dolly Matar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(52,'100052','Karen ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(53,'100053','Dalia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(54,'100054','Carla Kayrouz','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(55,'100055','Carmen Jarrouj','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(56,'100056','Cesar Touma','retail',NULL,'30321507',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(57,'100057','Stephanie Rahme ','retail',NULL,'96170191043',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(58,'100058','Paul Abou Rjeily','retail',NULL,'55096937',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(59,'100059','Zeina Khoury','retail',NULL,'66610276',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(60,'100060','Hiba Kayal','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(61,'100061','Nisreen','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(62,'100062','Jamil ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(63,'100063','Linda Issa','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(64,'100064','Syed Muzammil','retail',NULL,'55004296',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(65,'100065','Mireille Khoury','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(66,'100066','Ghada El Rassi','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(67,'100067','Joyce Riyachi','retail',NULL,'66536920',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(68,'100068','Nancy Wehbe','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(69,'100069','Rana Deeb','retail',NULL,'55878767',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(70,'100070','Nahed ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(71,'100071','Pamela','retail',NULL,'66469523',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(72,'100072','Mahran','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(73,'100073','Shahil','retail',NULL,'70044812',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(74,'100074','DG JONES','retail',NULL,'55878767',NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(75,'100075','UPTC','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,50000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(76,'100076','Amale','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(77,'100077','Ayman ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(78,'100078','sahar tabet','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:42',NULL),(79,'100079','Joelle Douahi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(80,'100080','Cynthia Abou Jaoude','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(81,'100081','Diana','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',0,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(82,'100082','Dunia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(83,'100083','Marcelle','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(84,'100084','Caroline','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(85,'100085','Marianne Haddad','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(86,'100086','Manal Elias','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(87,'100087','Ahmad Hashimi ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(88,'100088','Kery Ghassan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(89,'100089','bashir bechara','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(90,'100090','waad','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(91,'100091','nadya','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(92,'100092','nour ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(93,'100093','jalal dohhan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(94,'100094','karim','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(95,'100095','cherry on top ','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(96,'100096','amal','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(97,'100097','Rama Abboud','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(98,'100098','Roula','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(99,'100099','Nancy Haddad','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(100,'100100','Bachir','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(101,'100101','Marianna Tannous','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(102,'100102','Nadine Wehbe','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',0,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(103,'100103','Zeinab Ismail','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(104,'100104','Nivine','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(105,'100105','Yara','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(106,'100106','Hiba Abou Assi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(107,'100107','Nagham','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(108,'100108','Carine Adri','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(109,'100109','Djida','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(110,'100110','Michelle Hachem','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(111,'100111','Hadil','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(112,'100112','St Charbel Church','retail',NULL,'66683365',NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(113,'100113','Marina Aneid','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(114,'100114','Rima Al Kouzi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(115,'100115','Christelle Milan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(116,'100116','Joseph Chouaity','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(117,'100117','Rita Jawhar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(118,'100118','Sana','retail',NULL,'33944822',NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(119,'100119','Maic','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'Credit OK',0,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(120,'100120','Rouba Kai','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(121,'100121','Layal','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(122,'100122','Nadine Wehbe','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(123,'100123','soha maalouf','retail',NULL,'77939967',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(124,'100124','Nemr Abou Rjeily','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(125,'100125','Ahmed Abu Rubb','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(126,'100126','GAT Middle East','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(127,'100127','Maria Achkar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(128,'100128','Aya Akhal','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(129,'100129','Hana','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(130,'100130','Suzanne Kanaan','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(131,'100131','Marianne Azzi','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(132,'100132','Chirine Ayache','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(133,'100133','Khouzam','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(134,'100134','Nadim Azar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(135,'100135','Vandana','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(136,'100136','Amale Michlib','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(137,'100137','Mark Karam','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(138,'100138','Samo','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(139,'100139','Michel Kazan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(140,'100140','Rawan Nasser','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(141,'100141','Jennie Nakhoul','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(142,'100142','Bashir mourice','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(143,'100143','nemr Abu Rjeily','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',0,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(144,'100144','Nasri Rbeiz','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(145,'100145','Elias Khoury','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(146,'100146','Diana Hassan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(147,'100147','Diana Gbely','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(148,'100148','Sana Askar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(149,'100149','Khalil Ibrahim','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(150,'100150','Krystal Sarkis','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(151,'100151','Lama Kalash','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(152,'100152','Carla','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(153,'100153','Maya Abou Ramia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(154,'100154','Shadia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(155,'100155','Moune','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(156,'100156','Carol Nadir','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(157,'100157','Elias Khalil','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(158,'100158','Rana Mallah','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(159,'100159','Dima Merhebi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(160,'100160','Nadim Azar','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',0,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(161,'100161','Eliane Chaccour','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(162,'100162','Jean Youssef','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(163,'100163','Nicole Al Kache','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(164,'100164','Pepita','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(165,'100165','Hiba Darwish','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(166,'100166','Riham ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(167,'100167','Samar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(168,'100168','Hiba Hijazi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(169,'100169','Hiam Zakhem','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(170,'100170','Sally Haddad','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(171,'100171','Roula Ismail','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(172,'100172','Sahar Abou Jaoude','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(173,'100173','Ghizlane','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(174,'100174','Chirine Gharzedinne','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(175,'100175','Alysha','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(176,'100176','Marie Cremono','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(177,'100177','Hajar Issa','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(178,'100178','Neevine WAF','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(179,'100179','Rana Abi Akl','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(180,'100180','Georgette Mansour','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(181,'100181','Abir Abou Diab','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(182,'100182','Alia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(183,'100183','Ola','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(184,'100184','Gisele Sassine','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(185,'100185','Im Abdel Aziz','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(186,'100186','Grace Alam','retail',NULL,'77983225',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(187,'100187','Rania Yammine','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(188,'100188','Jad Ayache','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(189,'100189','Hala Tabbah','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(190,'100190','St Georges And Isaac Church','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,20000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(191,'100191','Maya Hanna','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(192,'100192','Grace Ghoseini','retail',NULL,'66963211',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(193,'100193','machaal','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(194,'100194','Manal El Aawar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(195,'100195','Aya El Hammoud','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(196,'100196','Lama','retail',NULL,'77776121',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(197,'100197','Najla Azzam','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(198,'100198','Dana Amasha','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(199,'100199','Sarah','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(200,'100200','Eliane Daccache','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(201,'100201','Katia Hanna','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(202,'100202','Fifth Element Management','retail',NULL,'9.71503E+11',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(203,'100203','Carla','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(204,'100204','Sally','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(205,'100205','Racil Ali','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,3000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(206,'100206','Sarah Nahal','retail',NULL,'66813837',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(207,'100207','Sandy','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(208,'100208','Saleh Alayan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(209,'100209','Georges Ghasssan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(210,'100210','Sabine','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(211,'100211','Nada Nemr','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(212,'100212','Hamda Essa','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(213,'100213','Imad Aneid','retail',NULL,'66298820',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(214,'100214','Joelle Isaac','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(215,'100215','Issam Hichmi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(216,'100216','Joe Khoury','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(217,'100217','Joy Khoury','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(218,'100218','Alford Hughes','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(219,'100219','Ali Bin Ali Medical ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(220,'100220','jhonny','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(221,'100221','Georges Nehme','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(222,'100222','Hoda ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(223,'100223','Michel Achkouty ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(224,'100224','Abrar','retail',NULL,'33833673',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(225,'100225','Ayman','retail',NULL,'55624199',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(226,'100226','Michel Said','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(227,'100227','Ghinwa Bou Abdallah','retail',NULL,'66166804',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(228,'100228','Diana Hoteit','retail',NULL,'50222070',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(229,'100229','Dr Elissar Charrouf','retail',NULL,'77807040',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(230,'100230','Romel Saleh','retail',NULL,'33221130',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(231,'100231','Mireille Saliba','retail',NULL,'33389959',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(232,'100232','suzanne Bassil','retail',NULL,'55578276',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(233,'100233','rouba kaddoura','retail',NULL,'55530648',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(234,'100234','Dunia Abboud','retail',NULL,'55470452',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(235,'100235','Nour Moatassem','retail',NULL,'55651525',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(236,'100236','Daniel Ocean','retail',NULL,'50587777',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(237,'100237','International School of London','retail',NULL,'66181704',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(238,'100238','samer bejjani','retail',NULL,'66900393',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(239,'100239','Mayada','retail',NULL,'66837776',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(240,'100240','Saad Azarieh','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(241,'100241','Carole Hadi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(242,'100242','Jana Bilal','retail',NULL,'66410190',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(243,'100243','Reem','retail',NULL,'70091802',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(244,'100244','Zahra','retail',NULL,'31630115518',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(245,'100245','Nada Khoury','retail',NULL,'66870907',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(246,'100246','nelly khalil','retail',NULL,'55232965',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(247,'100247','Ramzi Joukhadar','retail',NULL,'55536459',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(248,'100248','Pamela Kachouh','retail',NULL,'66188391',NULL,NULL,NULL,NULL,NULL,1100.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(249,'100249','Sandy semaan','retail',NULL,'33319381',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(250,'100250','Natasha Hammad','retail',NULL,'33655858',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(251,'100251','Layal Fayad','retail',NULL,'55302263',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(252,'100252','Syrine','retail',NULL,'66099840',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(253,'100253','Sunday School ','retail',NULL,'33085578',NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(254,'100254','Zeina Yazbek','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(255,'100255','Caroline Ghossain','retail',NULL,'55874600',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(256,'100256','Hala Kandah','retail',NULL,'30145643',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(257,'100257','Lynn Zoughaibi','retail',NULL,'66615535',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(258,'100258','Elsy Abi Assi','retail',NULL,'33916971',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(259,'100259','Maram Al Kourani','retail',NULL,'55060455',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(260,'100260','Fatima Abbas','retail',NULL,'66668655',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(261,'100261','Bassam Ghazal','retail',NULL,'55372895',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(262,'100262','Nayla Bejjani','retail',NULL,'55804561',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(263,'100263','lama el moatassem','retail',NULL,'55778467',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(264,'100264','Patricia Abboud','retail',NULL,'30436000',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(265,'100265','Alia Ghabar','retail',NULL,'50715725',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(266,'100266','Sana Abou Sleiman','retail',NULL,'50381979',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(267,'100267','Layla Jaber','retail',NULL,'55198117',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(268,'100268','Rana Diab','retail',NULL,'66994468',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(269,'100269','Manuella Kays','retail',NULL,'50585236',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(270,'100270','Ziad Abou Mansour','retail',NULL,'66305669',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(271,'100271','Pamela Azzi','retail',NULL,'66860821',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(272,'100272','Roula Mezher ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(273,'100273','Roger Abou Malhab','retail',NULL,'66855997',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(274,'100274','Barbar Jabbour','retail',NULL,'31335528',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(275,'100275','Nancy A K','retail',NULL,'55680160',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(276,'100276','Reine Nader','retail',NULL,'50279220',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(277,'100277','Rola Talih','retail',NULL,'33669622',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(278,'100278','Rita Nawar','retail',NULL,'96170503896',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(279,'100279','Eliane Andraos','retail',NULL,'9613228557',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(280,'100280','Toni Maroun','retail',NULL,'33669945',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(281,'100281','Grace Wehbe','retail',NULL,'9613878189',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(282,'100282','Lara Karam','retail',NULL,'77954847',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(283,'100283','Diana Rezkallah','retail',NULL,'77760714',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(284,'100284','Rawan Al Fardan 6','retail',NULL,'33925135',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(285,'100285','Lama El Khatib','retail',NULL,'55876877',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(286,'100286','Hanan Kozbar','retail',NULL,'30064176',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(287,'100287','Ramzi Abou Dayya','retail',NULL,'33353315',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(288,'100288','Antoine Bassil','retail',NULL,'50929900',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',0,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(289,'100289','Salim Saliba','retail',NULL,'50623661',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(290,'100290','Hassan Abou El Khoudoud','retail',NULL,'55679780',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(291,'100291','Karla Kammouge','retail',NULL,'66545301',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(292,'100292','Jad Sleiman','retail',NULL,'50277752',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(293,'100293','Maguy','retail',NULL,'77760928',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(294,'100294','Rawan Hachem','retail',NULL,'51864970',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(295,'100295','Mirvat Kai','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(296,'100296','Antoinette Ibrahim','retail',NULL,'33168442',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(297,'100297','Manal Aloush','retail',NULL,'55067643',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(298,'100298','Hala El Halabi','retail',NULL,'66253399',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(299,'100299','Nazek Arnous','retail',NULL,'66992836',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(300,'100300','Wael Fattouh','retail',NULL,'77451387',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(301,'100301','Micheline Jeaara','retail',NULL,'55781945',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(302,'100302','Elias Eid','retail',NULL,'33777553',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(303,'100303','Yasmina','retail',NULL,'33559937',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(304,'100304','Elie Hani','retail',NULL,'33139205',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(305,'100305','Leen Toukali','retail',NULL,'66326253',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(306,'100306','Gaby  Y Khoury','retail',NULL,'55558939',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(307,'100307','Husseni Mehdi','retail',NULL,'55618469',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(308,'100308','Tony Nawar','retail',NULL,'66480735',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(309,'100309','Thouraya Khachan','retail',NULL,'33303908',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(310,'100310','Maya Chammas','retail',NULL,'55282533',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(311,'100311','Rania Ouwayda','retail',NULL,'55300921',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(312,'100312','Sandy','retail',NULL,'33574797',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(313,'100313','Rayya Mehdi','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(314,'100314','Diala Salloum ','retail',NULL,'33670070',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(315,'100315','Toni Bassil ','retail',NULL,'50929900',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(316,'100316','Tatiana Makari','retail',NULL,'39946699',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(317,'100317','Hannan Hamed','retail',NULL,'30228002',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(318,'100318','Celine Chahine','retail',NULL,'33956299',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(319,'100319','Joanna ','retail',NULL,'33506668',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(320,'100320','Carmen Haddad','retail',NULL,'66789367',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(321,'100321','Mitche Maroun','retail',NULL,'31161746',NULL,NULL,NULL,'Qatar',NULL,800.000,0,'Credit OK',0,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(322,'100322','Elie Makhoul','retail',NULL,'55777658',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(323,'100323','Joseph Arja ','retail',NULL,'66076353',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(324,'100324','Antoine Roukoz','retail',NULL,'50878050',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(325,'100325','Cynthia Layous','retail',NULL,'33141670',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(326,'100326','Iman Bakhach','retail',NULL,'33075507',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(327,'100327','Bilal Zamzam','retail',NULL,'66053077',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(328,'100328','Micheline Maakaron','retail',NULL,'66188466',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(329,'100329','Ghada Freiha','retail',NULL,'55237522',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(330,'100330','Mayss Bitar ','retail',NULL,'66796532',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(331,'100331','Rania El Lakkis','retail',NULL,'55389023',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(332,'100332','Lyn Sawaya','retail',NULL,'33242672',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(333,'100333','Natacha Gebeo','retail',NULL,'31693663',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(334,'100334','Joelle Nader','retail',NULL,'33240218',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(335,'100335','Hala Attieh','retail',NULL,'33633036',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(336,'100336','Souad Sarkis','retail',NULL,'55620274',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(337,'100337','Josette Yazbeck','retail',NULL,'33259989',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(338,'100338','Mada ','retail',NULL,'55948085',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(339,'100339','Dona Hayek','retail',NULL,'51020707',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(340,'100340','Yasmine Hayek ','retail',NULL,'33182652',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(341,'100341','Diala El Masri ','retail',NULL,'50554438',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(342,'100342','Diala Al MAsri','retail',NULL,'50554438',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(343,'100343','Eliane Chaccour','retail',NULL,'55210216',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(344,'100344','Neda Kohan','retail',NULL,'33301317',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(345,'100345','Danya Khatib','retail',NULL,'55080829',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(346,'100346','Ahmad Abou Saleh','retail',NULL,'33527842',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(347,'100347','Dina Azar','retail',NULL,'55828072',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(348,'100348','Aya','retail',NULL,'55115679',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(349,'100349','Ogarite Slim','retail',NULL,'66059082',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(350,'100350','Hiba Kayal ','retail',NULL,'30872498',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(351,'100351','Paula Akkari','retail',NULL,'55305674',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(352,'100352','Mohammed Hammoud ','retail',NULL,'55007624',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(353,'100353','jaymmy Assaf ','retail',NULL,'55500621',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(354,'100354','Mahmoud Chhoury','retail',NULL,'33384470',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(355,'100355','Grace Hachem ','retail',NULL,'66574629',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(356,'100356','Hisham Awad ','retail',NULL,'66456892',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(357,'100357','Harley Davidson Qatar','retail',NULL,'123456',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(358,'100358','Djida','retail',NULL,'55489665',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(359,'100359','Aida Peltekian ','retail',NULL,'66002467',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(360,'100360','Fadi Douaidari','retail',NULL,'66150165',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(361,'100361','Dalia Baraka','retail',NULL,'66551279',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(362,'100362','Carla Bacha ','retail',NULL,'33004991',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(363,'100363','Nay Azzam ','retail',NULL,'77211784',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(364,'100364','Eliana Salloum','retail',NULL,'50792444',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(365,'100365','Sabine Haddad','retail',NULL,'66173872',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(366,'100366','Aleen Salloum','retail',NULL,'66724415',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(367,'100367','Maya Saba','retail',NULL,'55727656',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(368,'100368','Carla Kabbara','retail',NULL,'66195259',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(369,'100369','Ghinwa Ibrahim','retail',NULL,'55118480',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(370,'100370','Samer Awadallah','retail',NULL,'55665788',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(371,'100371','Hiba Hijazi ','retail',NULL,'66009399',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(372,'100372','Talar','retail',NULL,'74770018',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(373,'100373','Khouzam','retail',NULL,'55500728',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(374,'100374','Emilie Bejjani','retail',NULL,'52037491',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(375,'100375','Hussein Choucair','retail',NULL,'60009808',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(376,'100376','Annie Abdallah ','retail',NULL,'55846209',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(377,'100377','Nour Hadad','retail',NULL,'51288843',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(378,'100378','Sdimktg','retail',NULL,'77959298',NULL,NULL,NULL,'Qatar',NULL,2000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(379,'100379','Rana N','retail',NULL,'66890849',NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(380,'100380','Farah Mokbel','retail',NULL,'50558242',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(381,'100381','Hala Issa','retail',NULL,'77889294',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(382,'100382','Georges Ghawi','retail',NULL,'66647323',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(383,'100383','Mirna Salem ','retail',NULL,'55862393',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(384,'100384','Ghada Trad','retail',NULL,'55739524',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(385,'100385','Zeina Karam','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(386,'100386','Carla Karam','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(387,'100387','Crystelle Tannouri','retail',NULL,'0',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(388,'100388','Nada Rizkallah','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(389,'100389','Rana El Khoury','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(390,'100390','Rana Khoury','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(391,'100391','Joanne Abi Nader','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(392,'100392','Veeda','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(393,'100393','Mohammed Sabouh ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(394,'100394','Nabil','retail',NULL,'1234',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(395,'100395','Joceline','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(396,'100396','Berna Noufaily','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(397,'100397','Joe Bou Abboud ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(398,'100398','Margo Beyrouti','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(399,'100399','Leila Dreik ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(400,'100400','Nadine Zeitoun','retail',NULL,'1234',NULL,NULL,NULL,'Qatar',NULL,750.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(401,'100401','Hanan Lattouf','retail',NULL,'1234',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(402,'100402','Jessy Mouawad','retail',NULL,'1234',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(403,'100403','Nagham Lehdo','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(404,'100404','Mirianne ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(405,'100405','Aida Hadchity','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(406,'100406','Jihane ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(407,'100407','Christel ','retail',NULL,'12345',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(408,'100408','Patricia Toulany','retail',NULL,'51565555',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(409,'100409','Elissa Ayache','retail',NULL,'59922020',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(410,'100410','Christine Basha','retail',NULL,'66040443',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(411,'100411','Cosette Abboud','retail',NULL,'55426343',NULL,NULL,NULL,NULL,NULL,550.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(412,'100412','cynthia abou jaoude','retail',NULL,'55646261',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(413,'100413','Samar Jokhadar','retail',NULL,'66974440',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(414,'100414','Dr Maya Jalloul','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(415,'100415','Mustafa Halabi','retail',NULL,'55850901',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(416,'100416','Amim Nasr','retail',NULL,'30282735',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(417,'100417','Bassam Arab','retail',NULL,'50052663',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(418,'100418','Rana Bou Karim','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(419,'100419','Dinesh Qsale','retail',NULL,'31006500',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(420,'100420','Michel','retail',NULL,'66752347',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(421,'100421','Rita ','retail',NULL,'96170503896',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(422,'100422','Elissar','retail',NULL,'97455473100',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(423,'100423','alaa','retail',NULL,'97433609688',NULL,NULL,NULL,'Qatar',NULL,1500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(424,'100424','Nina Natout','retail',NULL,'9613151566',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(425,'100425','Josianne Massoud','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(426,'100426','Antonio','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(427,'100427','Amer Khatib','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(428,'100428','Teknowledge Services and Solutions LLC','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,50000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(429,'100429','AstraZeneca','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(430,'100430','GAC','retail',NULL,'50304633',NULL,NULL,NULL,'Qatar',NULL,10000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(431,'100431','Keeta','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(432,'100432','95 West Bay Luxury Appartments','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,25000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(433,'100433','Thirty Five WestBay Luxury Appartments','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,25000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(434,'100434','Mitche Maroun','retail',NULL,'31161746',NULL,NULL,NULL,'Qatar',NULL,800.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(435,'100435','Karkeh Rest','retail',NULL,'66000117',NULL,NULL,NULL,NULL,NULL,50000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(436,'100436','Muhammad Suheil','retail',NULL,'55090933',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(438,'100438','Funderdome','retail',NULL,'31355811',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(439,'100439','American School of Doha','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,10000.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(440,'100440','Baladi Express','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(441,'100441','CASH CUSTOMER','retail',NULL,'44413660',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(442,'100442','Clicks','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(443,'100443','Deliveroo','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(444,'100444','Rafeeq','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(445,'100445','Snoonu','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL),(446,'100446','Talabat','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,NULL,NULL,'2025-12-10 22:23:43',NULL);
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `daily_dish_menu_items`
--

DROP TABLE IF EXISTS `daily_dish_menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_dish_menu_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `daily_dish_menu_id` bigint(20) unsigned NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `role` enum('main','diet','vegetarian','salad','dessert','addon') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ddmi_unique_menu_item_role` (`daily_dish_menu_id`,`menu_item_id`,`role`),
  KEY `ddmi_menu_id_index` (`daily_dish_menu_id`),
  KEY `ddmi_menu_item_id_index` (`menu_item_id`),
  KEY `ddmi_role_index` (`role`),
  CONSTRAINT `dd_menu_items_item_fk` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `dd_menu_items_menu_fk` FOREIGN KEY (`daily_dish_menu_id`) REFERENCES `daily_dish_menus` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `ddmi_daily_dish_menu_fk` FOREIGN KEY (`daily_dish_menu_id`) REFERENCES `daily_dish_menus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ddmi_menu_item_fk` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=149 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `daily_dish_menu_items`
--

LOCK TABLES `daily_dish_menu_items` WRITE;
/*!40000 ALTER TABLE `daily_dish_menu_items` DISABLE KEYS */;
INSERT INTO `daily_dish_menu_items` VALUES (4,3,226,'main',0,0,'2025-12-14 21:48:38'),(5,3,359,'main',1,0,'2025-12-14 21:48:38'),(6,3,360,'main',2,0,'2025-12-14 21:48:38'),(7,3,303,'salad',0,0,'2025-12-14 21:48:38'),(8,3,361,'dessert',0,0,'2025-12-14 21:48:38'),(9,4,362,'main',0,0,'2025-12-14 21:48:38'),(10,4,363,'main',1,0,'2025-12-14 21:48:38'),(11,4,364,'main',2,0,'2025-12-14 21:48:38'),(12,4,300,'salad',0,0,'2025-12-14 21:48:38'),(13,4,365,'dessert',0,0,'2025-12-14 21:48:38'),(14,5,366,'main',0,0,'2025-12-14 21:48:38'),(15,5,367,'main',1,0,'2025-12-14 21:48:38'),(16,5,368,'main',2,0,'2025-12-14 21:48:38'),(17,5,302,'salad',0,0,'2025-12-14 21:48:38'),(18,5,369,'dessert',0,0,'2025-12-14 21:48:38'),(19,6,370,'main',0,0,'2025-12-14 21:48:38'),(20,6,371,'main',1,0,'2025-12-14 21:48:38'),(21,6,372,'main',2,0,'2025-12-14 21:48:38'),(22,6,373,'salad',0,0,'2025-12-14 21:48:38'),(23,6,374,'dessert',0,0,'2025-12-14 21:48:38'),(24,7,375,'main',0,0,'2025-12-14 21:48:38'),(25,7,376,'main',1,0,'2025-12-14 21:48:38'),(26,7,322,'main',2,0,'2025-12-14 21:48:38'),(27,7,303,'salad',0,0,'2025-12-14 21:48:38'),(28,7,377,'dessert',0,0,'2025-12-14 21:48:38'),(29,8,378,'main',0,0,'2025-12-14 21:48:38'),(30,8,379,'main',1,0,'2025-12-14 21:48:38'),(31,8,380,'main',2,0,'2025-12-14 21:48:38'),(32,8,291,'salad',0,0,'2025-12-14 21:48:38'),(33,8,35,'dessert',0,0,'2025-12-14 21:48:38'),(34,9,381,'main',0,0,'2025-12-14 21:48:38'),(35,9,263,'main',1,0,'2025-12-14 21:48:38'),(36,9,382,'main',2,0,'2025-12-14 21:48:38'),(37,9,304,'salad',0,0,'2025-12-14 21:48:38'),(38,9,383,'dessert',0,0,'2025-12-14 21:48:38'),(39,10,56,'main',0,0,'2025-12-14 21:48:38'),(40,10,384,'main',1,0,'2025-12-14 21:48:38'),(41,10,385,'main',2,0,'2025-12-14 21:48:38'),(42,10,303,'salad',0,0,'2025-12-14 21:48:38'),(43,10,386,'dessert',0,0,'2025-12-14 21:48:38'),(44,11,387,'main',0,0,'2025-12-14 21:48:38'),(45,11,241,'main',1,0,'2025-12-14 21:48:38'),(46,11,388,'main',2,0,'2025-12-14 21:48:38'),(47,11,297,'salad',0,0,'2025-12-14 21:48:38'),(48,11,389,'dessert',0,0,'2025-12-14 21:48:38'),(49,12,390,'main',0,0,'2025-12-14 21:48:38'),(50,12,253,'main',1,0,'2025-12-14 21:48:38'),(51,12,391,'main',2,0,'2025-12-14 21:48:38'),(52,12,373,'salad',0,0,'2025-12-14 21:48:38'),(53,12,392,'dessert',0,0,'2025-12-14 21:48:38'),(54,13,393,'main',0,0,'2025-12-14 21:48:38'),(55,13,394,'main',1,0,'2025-12-14 21:48:38'),(56,13,230,'main',2,0,'2025-12-14 21:48:38'),(57,13,302,'salad',0,0,'2025-12-14 21:48:38'),(58,13,377,'dessert',0,0,'2025-12-14 21:48:38'),(59,14,395,'main',0,0,'2025-12-14 21:48:38'),(60,14,396,'main',1,0,'2025-12-14 21:48:38'),(61,14,397,'main',2,0,'2025-12-14 21:48:38'),(62,14,373,'salad',0,0,'2025-12-14 21:48:38'),(63,14,398,'dessert',0,0,'2025-12-14 21:48:38'),(64,15,251,'main',0,0,'2025-12-14 21:48:38'),(65,15,399,'main',1,0,'2025-12-14 21:48:38'),(66,15,360,'main',2,0,'2025-12-14 21:48:38'),(67,15,400,'salad',0,0,'2025-12-14 21:48:38'),(68,15,361,'dessert',0,0,'2025-12-14 21:48:38'),(69,16,256,'main',0,0,'2025-12-14 21:48:38'),(70,16,393,'main',1,0,'2025-12-14 21:48:38'),(71,16,401,'main',2,0,'2025-12-14 21:48:38'),(72,16,302,'salad',0,0,'2025-12-14 21:48:38'),(73,16,35,'dessert',0,0,'2025-12-14 21:48:38'),(74,17,402,'main',0,0,'2025-12-14 21:48:38'),(75,17,359,'main',1,0,'2025-12-14 21:48:38'),(76,17,403,'main',2,0,'2025-12-14 21:48:38'),(77,17,373,'salad',0,0,'2025-12-14 21:48:38'),(78,17,369,'dessert',0,0,'2025-12-14 21:48:38'),(79,18,404,'main',0,0,'2025-12-14 21:48:38'),(80,18,405,'main',1,0,'2025-12-14 21:48:38'),(81,18,406,'main',2,0,'2025-12-14 21:48:38'),(82,18,373,'salad',0,0,'2025-12-14 21:48:38'),(83,18,389,'dessert',0,0,'2025-12-14 21:48:38'),(84,19,407,'main',0,0,'2025-12-14 21:48:38'),(85,19,276,'main',1,0,'2025-12-14 21:48:38'),(86,19,322,'main',2,0,'2025-12-14 21:48:39'),(87,19,373,'salad',0,0,'2025-12-14 21:48:39'),(88,19,377,'dessert',0,0,'2025-12-14 21:48:39'),(89,20,408,'main',0,0,'2025-12-14 21:48:39'),(90,20,409,'main',1,0,'2025-12-14 21:48:39'),(91,20,360,'main',2,0,'2025-12-14 21:48:39'),(92,20,302,'salad',0,0,'2025-12-14 21:48:39'),(93,20,347,'dessert',0,0,'2025-12-14 21:48:39'),(94,21,216,'main',0,0,'2025-12-14 21:48:39'),(95,21,263,'main',1,0,'2025-12-14 21:48:39'),(96,21,382,'main',2,0,'2025-12-14 21:48:39'),(97,21,303,'salad',0,0,'2025-12-14 21:48:39'),(98,21,386,'dessert',0,0,'2025-12-14 21:48:39'),(99,22,215,'main',0,0,'2025-12-14 21:48:39'),(100,22,410,'main',1,0,'2025-12-14 21:48:39'),(101,22,316,'main',2,0,'2025-12-14 21:48:39'),(102,22,411,'salad',0,0,'2025-12-14 21:48:39'),(103,22,104,'dessert',0,0,'2025-12-14 21:48:39'),(104,23,240,'main',0,0,'2025-12-14 21:48:39'),(105,23,412,'main',1,0,'2025-12-14 21:48:39'),(106,23,372,'main',2,0,'2025-12-14 21:48:39'),(107,23,302,'salad',0,0,'2025-12-14 21:48:39'),(108,23,35,'dessert',0,0,'2025-12-14 21:48:39'),(109,24,370,'main',0,0,'2025-12-14 21:48:39'),(110,24,241,'main',1,0,'2025-12-14 21:48:39'),(111,24,230,'main',2,0,'2025-12-14 21:48:39'),(112,24,300,'salad',0,0,'2025-12-14 21:48:39'),(113,24,361,'dessert',0,0,'2025-12-14 21:48:39'),(114,25,376,'main',0,0,'2025-12-14 21:48:39'),(115,25,241,'main',1,0,'2025-12-14 21:48:39'),(116,25,364,'main',2,0,'2025-12-14 21:48:39'),(117,25,373,'salad',0,0,'2025-12-14 21:48:39'),(118,25,389,'dessert',0,0,'2025-12-14 21:48:39'),(119,26,413,'main',0,0,'2025-12-14 21:48:39'),(120,26,414,'main',1,0,'2025-12-14 21:48:39'),(121,26,415,'main',2,0,'2025-12-14 21:48:39'),(122,26,291,'salad',0,0,'2025-12-14 21:48:39'),(123,26,365,'dessert',0,0,'2025-12-14 21:48:39'),(124,27,416,'main',0,0,'2025-12-14 21:48:39'),(125,27,396,'main',1,0,'2025-12-14 21:48:39'),(126,27,417,'main',2,0,'2025-12-14 21:48:39'),(127,27,303,'salad',0,0,'2025-12-14 21:48:39'),(128,27,369,'dessert',0,0,'2025-12-14 21:48:39'),(129,28,384,'main',0,0,'2025-12-14 21:48:39'),(130,28,418,'main',1,0,'2025-12-14 21:48:39'),(131,28,375,'main',2,0,'2025-12-14 21:48:39'),(132,28,291,'salad',0,0,'2025-12-14 21:48:39'),(133,28,361,'dessert',0,0,'2025-12-14 21:48:39'),(134,29,402,'main',0,0,'2025-12-14 21:48:39'),(135,29,263,'main',1,0,'2025-12-14 21:48:39'),(136,29,410,'main',2,0,'2025-12-14 21:48:39'),(137,29,302,'salad',0,0,'2025-12-14 21:48:39'),(138,29,35,'dessert',0,0,'2025-12-14 21:48:39'),(139,30,259,'main',0,0,'2026-02-01 21:16:55'),(140,30,291,'salad',0,0,'2026-02-01 21:16:55'),(141,30,389,'dessert',0,0,'2026-02-01 21:16:55'),(142,30,147,'main',0,0,'2026-02-01 21:16:55'),(143,30,417,'main',0,0,'2026-02-01 21:16:55'),(144,31,276,'main',0,0,'2026-02-01 21:18:49'),(145,31,48,'main',0,0,'2026-02-01 21:18:49'),(146,31,366,'main',0,0,'2026-02-01 21:18:49'),(147,31,291,'salad',0,0,'2026-02-01 21:18:49'),(148,31,398,'dessert',0,0,'2026-02-01 21:18:49');
/*!40000 ALTER TABLE `daily_dish_menu_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `daily_dish_menus`
--

DROP TABLE IF EXISTS `daily_dish_menus`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `daily_dish_menus` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `service_date` date NOT NULL,
  `status` enum('draft','published','archived') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `daily_dish_menus_branch_date_unique` (`branch_id`,`service_date`),
  KEY `daily_dish_menus_created_by_fk` (`created_by`),
  CONSTRAINT `daily_dish_menus_branch_id_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `daily_dish_menus_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `daily_dish_menus`
--

LOCK TABLES `daily_dish_menus` WRITE;
/*!40000 ALTER TABLE `daily_dish_menus` DISABLE KEYS */;
INSERT INTO `daily_dish_menus` VALUES (3,1,'2025-12-01','published',NULL,1,'2025-12-13 22:11:49','2025-12-13 22:11:51'),(4,1,'2025-12-02','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(5,1,'2025-12-03','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(6,1,'2025-12-04','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(7,1,'2025-12-06','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(8,1,'2025-12-07','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(9,1,'2025-12-08','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(10,1,'2025-12-09','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(11,1,'2025-12-10','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(12,1,'2025-12-11','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(13,1,'2025-12-13','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(14,1,'2025-12-14','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(15,1,'2025-12-15','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(16,1,'2025-12-16','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(17,1,'2025-12-17','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(18,1,'2025-12-18','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(19,1,'2025-12-20','published',NULL,1,'2025-12-14 20:48:38','2025-12-14 20:48:38'),(20,1,'2025-12-21','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(21,1,'2025-12-22','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(22,1,'2025-12-23','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(23,1,'2025-12-24','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(24,1,'2025-12-25','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(25,1,'2025-12-27','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(26,1,'2025-12-28','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(27,1,'2025-12-29','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(28,1,'2025-12-30','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(29,1,'2025-12-31','published',NULL,1,'2025-12-14 20:48:39','2025-12-14 20:48:39'),(30,1,'2026-02-01','published',NULL,1,'2026-02-01 20:16:55','2026-02-01 20:16:56'),(31,1,'2026-02-02','published',NULL,1,'2026-02-01 20:18:49','2026-02-01 20:18:50');
/*!40000 ALTER TABLE `daily_dish_menus` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `document_sequences`
--

DROP TABLE IF EXISTS `document_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_sequences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `year` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `next_number` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_sequences_branch_type_year_unique` (`branch_id`,`type`,`year`),
  KEY `document_sequences_type_year_index` (`type`,`year`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `document_sequences`
--

LOCK TABLES `document_sequences` WRITE;
/*!40000 ALTER TABLE `document_sequences` DISABLE KEYS */;
INSERT INTO `document_sequences` VALUES (1,1,'pos_sale','2026',13,'2026-01-29 18:43:36','2026-01-29 19:05:24'),(2,1,'ar_invoice','2026',8,'2026-01-29 19:45:24','2026-02-01 21:27:40');
/*!40000 ALTER TABLE `document_sequences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_attachments`
--

DROP TABLE IF EXISTS `expense_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_expense_attachments_expense` (`expense_id`),
  KEY `expense_attachments_expense_id_index` (`expense_id`),
  KEY `expense_attachments_uploaded_by_index` (`uploaded_by`),
  CONSTRAINT `expense_attachments_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expense_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_attachments`
--

LOCK TABLES `expense_attachments` WRITE;
/*!40000 ALTER TABLE `expense_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_categories`
--

DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `expense_categories_name_unique` (`name`),
  KEY `expense_categories_active_index` (`active`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_categories`
--

LOCK TABLES `expense_categories` WRITE;
/*!40000 ALTER TABLE `expense_categories` DISABLE KEYS */;
INSERT INTO `expense_categories` VALUES (1,'General','',1,'2025-12-10 16:46:35'),(2,'Pastry','',1,'2025-12-13 13:44:35');
/*!40000 ALTER TABLE `expense_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_payments`
--

DROP TABLE IF EXISTS `expense_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','bank_transfer','cheque','other') DEFAULT 'cash',
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_expense_payments_expense` (`expense_id`),
  KEY `expense_payments_expense_id_index` (`expense_id`),
  KEY `expense_payments_payment_date_index` (`payment_date`),
  KEY `expense_payments_created_by_index` (`created_by`),
  KEY `expense_payments_posted_by_fk` (`posted_by`),
  KEY `expense_payments_voided_by_fk` (`voided_by`),
  CONSTRAINT `expense_payments_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expense_payments_expense_id_foreign` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `expense_payments_posted_by_fk` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `expense_payments_voided_by_fk` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_payments`
--

LOCK TABLES `expense_payments` WRITE;
/*!40000 ALTER TABLE `expense_payments` DISABLE KEYS */;
INSERT INTO `expense_payments` VALUES (1,3,'2025-12-13',100.00,'cash','test',NULL,1,'2025-12-13 13:46:56',1,'2025-12-13 13:46:56',NULL,NULL),(2,4,'2025-12-13',100.00,'cash',NULL,NULL,1,'2025-12-13 14:58:07',1,'2025-12-13 14:58:07',NULL,NULL),(3,2,'2025-12-13',111.00,'cash','test payment for unpaid expenses',NULL,1,'2025-12-13 15:02:28',1,'2025-12-13 15:02:28',NULL,NULL);
/*!40000 ALTER TABLE `expense_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('unpaid','partial','paid') DEFAULT 'paid',
  `payment_method` enum('cash','card','bank_transfer','cheque','other') DEFAULT 'cash',
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_expenses_supplier` (`supplier_id`),
  KEY `idx_expenses_category` (`category_id`),
  KEY `idx_expenses_date` (`expense_date`),
  KEY `expenses_category_id_index` (`category_id`),
  KEY `expenses_supplier_id_index` (`supplier_id`),
  KEY `expenses_expense_date_index` (`expense_date`),
  KEY `expenses_payment_status_index` (`payment_status`),
  KEY `expenses_payment_method_index` (`payment_method`),
  KEY `expenses_created_by_index` (`created_by`),
  CONSTRAINT `expenses_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `expenses_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
INSERT INTO `expenses` VALUES (2,NULL,1,'2025-12-13','test',111.00,0.00,111.00,'paid','cash',NULL,NULL,1,'2025-12-13 13:11:38'),(3,NULL,2,'2025-12-13','test',100.00,0.00,100.00,'paid','cash','test',NULL,1,'2025-12-13 13:46:56'),(4,12,2,'2025-12-13','test pastry',100.00,0.00,100.00,'paid','cash',NULL,NULL,1,'2025-12-13 14:58:07');
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `finance_settings`
--

DROP TABLE IF EXISTS `finance_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `finance_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lock_date` date DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `finance_settings`
--

LOCK TABLES `finance_settings` WRITE;
/*!40000 ALTER TABLE `finance_settings` DISABLE KEYS */;
INSERT INTO `finance_settings` VALUES (1,NULL,NULL,'2026-01-29 15:05:29','2026-01-29 15:05:29');
/*!40000 ALTER TABLE `finance_settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gl_batch_lines`
--

DROP TABLE IF EXISTS `gl_batch_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gl_batch_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 0,
  `debit_total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `credit_total` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gl_batch_lines_batch_account_branch_unique` (`batch_id`,`account_id`,`branch_id`),
  KEY `gl_batch_lines_batch_id_fk_index` (`batch_id`),
  KEY `gl_batch_lines_account_id_fk_index` (`account_id`),
  KEY `gl_batch_lines_batch_account_branch_index` (`batch_id`,`account_id`,`branch_id`),
  CONSTRAINT `gl_batch_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `ledger_accounts` (`id`),
  CONSTRAINT `gl_batch_lines_batch_id_foreign` FOREIGN KEY (`batch_id`) REFERENCES `gl_batches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gl_batch_lines`
--

LOCK TABLES `gl_batch_lines` WRITE;
/*!40000 ALTER TABLE `gl_batch_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `gl_batch_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gl_batches`
--

DROP TABLE IF EXISTS `gl_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `gl_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `generated_at` timestamp NULL DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gl_batches_period_start_period_end_unique` (`period_start`,`period_end`),
  KEY `gl_batches_created_by_foreign` (`created_by`),
  KEY `gl_batches_posted_by_foreign` (`posted_by`),
  CONSTRAINT `gl_batches_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `gl_batches_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gl_batches`
--

LOCK TABLES `gl_batches` WRITE;
/*!40000 ALTER TABLE `gl_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `gl_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_items`
--

DROP TABLE IF EXISTS `inventory_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `units_per_package` decimal(12,3) NOT NULL DEFAULT 1.000,
  `package_label` varchar(50) DEFAULT NULL,
  `unit_of_measure` varchar(50) DEFAULT NULL,
  `minimum_stock` decimal(12,3) DEFAULT 0.000,
  `cost_per_unit` decimal(10,4) DEFAULT NULL,
  `last_cost_update` timestamp NULL DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `status` enum('active','discontinued') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `item_code` (`item_code`),
  KEY `category_id` (`category_id`),
  KEY `fk_inventory_supplier` (`supplier_id`),
  KEY `inventory_items_category_id_index` (`category_id`),
  KEY `inventory_items_supplier_id_index` (`supplier_id`),
  KEY `inventory_items_status_index` (`status`),
  KEY `inventory_items_name_index` (`name`),
  KEY `inventory_items_item_code_index` (`item_code`),
  KEY `inventory_items_location_index` (`location`),
  CONSTRAINT `inventory_items_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_items_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=278 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_items`
--

LOCK TABLES `inventory_items` WRITE;
/*!40000 ALTER TABLE `inventory_items` DISABLE KEYS */;
INSERT INTO `inventory_items` VALUES (1,'ITEM-001','Frozen Whole Chicken 1100gms - Brazil','Unit Cost: 8.8',1,NULL,1.000,NULL,'EA',1.000,8.8000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(2,'ITEM-002','Topside Frozen Brazil','Unit Cost: 24.0',1,NULL,1.000,NULL,'EA',0.000,24.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(4,'ITEM-004','Aluminum Container - 1120 (400pcs)','Supplier: Packon Trading | Unit Cost: 89.0',2,NULL,1.000,NULL,'EA',150.000,89.0000,'2025-12-07 19:30:45','',NULL,'active','2025-10-29 10:33:20','2025-12-07 19:30:45'),(5,'ITEM-005','Paper Cup Double Wall 500pcs','Supplier: Packon Trading | Unit Cost: 100.0',2,NULL,1.000,NULL,'EA',200.000,100.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(6,'ITEM-006','French Fries 9x9mm, Skin off 4x2.5Kgs','Unit Cost: 68.0',1,NULL,1.000,NULL,'EA',1.000,68.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(7,'ITEM-007','Black Rect Container RE 32 Pack On 150pcs/ctn','Supplier: Packon Trading | Unit Cost: 63.0',2,NULL,1.000,NULL,'EA',100.000,63.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(8,'ITEM-008','Dish Washing Liquid 20ltr 5x4','Supplier: Packon Trading | Unit Cost: 33.0',2,NULL,1.000,NULL,'EA',1.000,33.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(9,'ITEM-009','Floor Cleaner 5Ltrx4','Supplier: Packon Trading | Unit Cost: 33.0',2,NULL,1.000,NULL,'EA',1.000,33.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(10,'ITEM-010','Garbage Bag - 90*110 (12KG)','Supplier: Packon Trading | Unit Cost: 48.0',2,NULL,1.000,NULL,'EA',4.000,48.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(11,'ITEM-011','Frozen Shredded Mozzarella','Unit Cost: 25.0',1,NULL,1.000,NULL,'EA',0.000,25.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(12,'ITEM-012','Hash Brown Triangular 4x2.5Kgs','Unit Cost: 90.0',1,NULL,1.000,NULL,'EA',1.000,90.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(13,'ITEM-013','Yellow Bag Medium - 20KG','Supplier: Packon Trading | Unit Cost: 129.0',2,NULL,1.000,NULL,'EA',2.000,129.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(14,'ITEM-014','Tomato Paste Mechaalany','Unit Cost: 40.0',1,NULL,1.000,NULL,'EA',5.000,40.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(17,'ITEM-017','Frozen Beef Tenderloin Brazil','Unit Cost: 41.0',1,NULL,1.000,NULL,'KG',0.000,41.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(18,'ITEM-018','Karan - Black Angus Beef Chilled Eyeround','Unit Cost: 30.0',1,NULL,1.000,NULL,'EA',0.000,30.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(20,'ITEM-020','Chicken Shawarma Diplomata 4x2.5Kg','Unit Cost: 105.0',1,NULL,1.000,NULL,'EA',0.000,105.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(21,'ITEM-021','LEBANON 5283000909763 AOUN PARBOILED RICE 2KG','Unit Cost: 14.4',1,NULL,1.000,NULL,'EA',2.000,14.4000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(22,'ITEM-022','Palm Oil 18 Ltr','Unit Cost: 112.0',1,NULL,1.000,NULL,'EA',0.000,112.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(24,'ITEM-024','AOUN SODIUM BICARBONATE','Unit Cost: 3.6',1,NULL,1.000,NULL,'EA',0.000,3.6000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(25,'ITEM-025','AOUN BROWN BURGHUL FINE 4KG','Unit Cost: 20.0',1,NULL,1.000,NULL,'EA',1.000,20.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(26,'ITEM-026','Happy Gardens Chickpeas 9mm-20 kg','Unit Cost: 180.5',1,NULL,1.000,NULL,'EA',0.000,180.5000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(27,'ITEM-027','BM Tomato Paste 650 GR - Lebanon','Unit Cost: 8.5',1,NULL,1.000,NULL,'EA',0.000,8.5000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(28,'ITEM-028','MBM Apple Vinegar 500ML','Unit Cost: 6.25',1,NULL,1.000,NULL,'EA',1.000,6.2500,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(29,'ITEM-029','Aoun All Spices Powder 500GR','Unit Cost: 35.63',1,NULL,1.000,NULL,'EA',0.000,35.6300,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(30,'ITEM-030','Aoun Sumac Powder 500 GR','Unit Cost: 25.89',1,NULL,1.000,NULL,'EA',2.000,25.8900,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(31,'ITEM-031','Aoun 7 Spices 500 GR','Unit Cost: 27.08',1,NULL,1.000,NULL,'EA',0.000,27.0800,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(32,'ITEM-032','SHP- Gourmet Foods White Onion Powder','Unit Cost: 14.45',1,NULL,1.000,NULL,'EA',0.000,14.4500,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(33,'ITEM-033','Topside Chilled South Africa','Unit Cost: 24.0',1,NULL,1.000,NULL,'KG',0.000,24.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(35,'ITEM-035','Frozen Mix Vegetable','Unit Cost: 42.0',1,NULL,1.000,NULL,'EA',0.000,42.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(36,'ITEM-036','Karak Tea','Unit Cost: 35.0',1,NULL,1.000,NULL,'KG',2.000,35.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(37,'ITEM-037','Coffee Beans','Unit Cost: 90.0',1,NULL,1.000,NULL,'KG',3.000,90.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(38,'ITEM-038','Hot Chocolate','Unit Cost: 50.0',1,NULL,1.000,NULL,'KG',2.000,50.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(39,'ITEM-039','Cappuccino Topping','Unit Cost: 70.0',1,NULL,1.000,NULL,'KG',1.000,70.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(42,'ITEM-042','Mix Sea Food 20x400 gm','Unit Cost: 170.0',1,NULL,1.000,NULL,'EA',0.000,170.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(43,'ITEM-043','Tomato Peeled 6 x 2.5Kgs','Unit Cost: 96.0',1,NULL,1.000,NULL,'EA',0.000,96.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(44,'ITEM-044','Talmera Mild White Cheddar Slice 1x2.27KG','Unit Cost: 77.18',1,NULL,1.000,NULL,'KG',1.000,77.1800,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(45,'ITEM-045','Yellow Cheddar Slice 1x2.27KG','Unit Cost: 122.5',1,NULL,1.000,NULL,'EA',0.000,122.5000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(46,'ITEM-046','Tredos Kashkaval 1x2.8KG','Unit Cost: 66.66',1,NULL,1.000,NULL,'EA',0.000,66.6600,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(47,'ITEM-047','Whole Peeled Tomatoes 6x2.5KG','Unit Cost: 90.0',1,NULL,1.000,NULL,'EA',0.000,90.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(48,'ITEM-048','Chicken Strips Regular 6x1KG','Unit Cost: 140.0',1,NULL,1.000,NULL,'EA',0.000,140.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(49,'ITEM-049','Cowland Unsalted Butter Spread 82%','Unit Cost: 360.0',1,NULL,1.000,NULL,'Each',0.000,360.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(50,'ITEM-050','Corn Flour Bag 25KG','Unit Cost: 90.0',1,NULL,1.000,NULL,'EA',0.000,90.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(51,'ITEM-051','Salt 25Kgs','Unit Cost: 15.0',1,NULL,1.000,NULL,'EA',0.000,15.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(52,'ITEM-052','Frozen Strawberry 10x1kg','Unit Cost: 75.0',1,NULL,1.000,NULL,'EA',0.000,75.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(53,'ITEM-053','Frozen Mango Pulp 16x1Kg','Unit Cost: 105.0',1,NULL,1.000,NULL,'EA',0.000,105.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(54,'ITEM-054','Fish Fillet - 4 x 2.5 KG','Unit Cost: 73.0',1,NULL,1.000,NULL,'KG',1.000,73.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(55,'ITEM-055','Benina - Soy Sauce Original 1.86L','Unit Cost: 12.5',1,NULL,1.000,NULL,'EA',1.000,12.5000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(56,'ITEM-056','Benina - Oyster Sauce 1.9L','Unit Cost: 18.5',1,NULL,1.000,NULL,'EA',1.000,18.5000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(57,'ITEM-057','Benina - Sweet and Sour 1.9L','Unit Cost: 20.0',1,NULL,1.000,NULL,'EA',1.000,20.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(58,'ITEM-058','Chilled Beef Tenderlion','Unit Cost: 32.0',1,NULL,1.000,NULL,'KG',0.000,32.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(59,'ITEM-059','Philadelphia Cream Cheese 6.6KG','Unit Cost: 219.0',1,NULL,1.000,NULL,'EA',0.000,219.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(60,'ITEM-060','Okra Zero 20x400g','Unit Cost: 90.0',1,NULL,1.000,NULL,'EA',0.000,90.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(61,'ITEM-061','Tomex Frozen Sweet Corn 4x2.5KG','Unit Cost: 95.0',1,NULL,1.000,NULL,'EA',1.000,95.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(62,'ITEM-062','Tomex Mix Vegetables 4x2.5KG','Unit Cost: 50.0',1,NULL,1.000,NULL,'EA',0.000,50.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(63,'ITEM-063','Happy Gardens Foul Medamas 400gr 1x24','Unit Cost: 2.0',1,NULL,1.000,NULL,'EA',7.000,2.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(65,'ITEM-065','Aoun Green Lentils 900 Gr 1x20','Unit Cost: 9.5',1,NULL,1.000,NULL,'EA',1.000,9.5000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(66,'ITEM-066','Aoun Garlic Powder 500GR 1x12','Unit Cost: 15.91',1,NULL,1.000,NULL,'EA',1.000,15.9100,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(67,'ITEM-067','SHP Cinnamon Stick','Unit Cost: 85.0',1,NULL,1.000,NULL,'EA',0.000,85.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(68,'ITEM-068','Aoun Lime','Unit Cost: 10.2',1,NULL,1.000,NULL,'EA',0.000,10.2000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(69,'ITEM-069','Frozen Striploin Brazil','Unit Cost: 35.0',1,NULL,1.000,NULL,'EA',0.000,35.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(71,'ITEM-071','Chilled Striploin Brazil','Unit Cost: 34.0',1,NULL,1.000,NULL,'EA',0.000,34.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(72,'ITEM-072','Frozen Tenderloin Brazil','Unit Cost: 51.0',1,NULL,1.000,NULL,'EA',0.000,51.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(73,'ITEM-073','Green Peas - Medium Fine 4x2.5KG','Unit Cost: 63.0',1,NULL,3.000,'','EA',1.000,63.0000,'2025-12-06 06:52:25','',NULL,'active','2025-10-29 10:33:20','2025-12-06 06:52:25'),(74,'ITEM-074','Green Beans - Cut 4x2.5KG','Unit Cost: 60.0',1,NULL,1.000,NULL,'EA',1.000,60.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(75,'ITEM-075','Beef Bacon Bits Toppings 6x1KG','Unit Cost: 170.0',1,NULL,1.000,NULL,'EA',0.000,170.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(76,'ITEM-076','Barilla Penne Rigate 12x500gm','Unit Cost: 100.0',1,NULL,1.000,NULL,'EA',5.000,100.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(77,'ITEM-077','Barilla Spaghetti (No.5)','Unit Cost: 150.0',1,NULL,1.000,NULL,'EA',5.000,150.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(78,'ITEM-078','White Chunk Tuna in Sunflower Oil 6x1.8KG','Unit Cost: 230.0',1,NULL,1.000,NULL,'EA',1.000,230.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(79,'ITEM-079','AOUN BLACK PEPPER POWDER 500GR','Unit Cost: 27.5',1,NULL,1.000,NULL,'EA',0.000,27.5000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(80,'ITEM-080','Benina Sesame Oil 1.86L','Unit Cost: 65.0',1,NULL,1.000,NULL,'EA',1.000,65.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(81,'ITEM-081','Fish Filet Box 4x2.5 Kg','Unit Cost: 75.0',1,NULL,1.000,NULL,'EA',0.000,75.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(84,'ITEM-084','COCOA POWDER 1KG','Unit Cost: 60.0',1,NULL,1.000,NULL,'EA',0.000,60.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(85,'ITEM-085','DARK COMPOUND BLOCKS 2.5KG','Unit Cost: 45.0',1,NULL,1.000,NULL,'EA',1.000,45.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(86,'ITEM-086','MILK COMPOUND BLOCKS 2.5KG','Unit Cost: 45.0',1,NULL,1.000,NULL,'EA',1.000,45.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(87,'ITEM-087','WHITE COMPOUND BLOCKS 2.5KG','Unit Cost: 45.0',1,NULL,1.000,NULL,'EA',1.000,45.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(88,'ITEM-088','Chocolate Sticks 1KG','Unit Cost: 27.0',1,NULL,1.000,NULL,'KG',1.000,27.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(89,'ITEM-089','Cling Film 6pcs','Unit Cost: 77.0',2,NULL,1.000,NULL,'EA',3.000,77.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(90,'ITEM-090','Vinyl Gloves Medium 10pcs','Unit Cost: 51.0',2,NULL,1.000,NULL,'EA',4.000,51.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(92,'ITEM-092','Aluminum Foil - 6pcs (1.5kg)','Unit Cost: 126.0',2,NULL,1.000,NULL,'EA',3.000,126.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(95,'ITEM-095','Chana Dal 5 Kgs','Unit Cost: 21.5',1,NULL,1.000,NULL,'EA',0.000,21.5000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(96,'ITEM-096','Handy Fuel 72 pcs / Ctn','Unit Cost: 97.0',2,NULL,1.000,NULL,'EA',15.000,97.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(97,'ITEM-097','Plastic Bowl 4OZ - 2000pcs','Unit Cost: 149.0',2,NULL,1.000,NULL,'EA',200.000,149.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(99,'ITEM-099','Yellow Bag Small - 20KG','Unit Cost: 129.0',1,NULL,1.000,NULL,'EA',2.000,129.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(101,'ITEM-101','Black Base Container 1 Compartment JPIF TR-1C','Unit Cost: 77.0',2,NULL,1.000,NULL,'EA',100.000,77.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(102,'ITEM-102','Baking Sheet 500pcs','Unit Cost: 66.0',2,NULL,1.000,NULL,'EA',1.000,66.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(103,'ITEM-103','Aluminum Platter 6586 - Big','Unit Cost: 40.0',2,NULL,1.000,NULL,'EA',100.000,40.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(104,'ITEM-104','Black Round Container - RO16','Unit Cost: 43.0',2,NULL,1.000,NULL,'EA',150.000,43.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(105,'ITEM-105','Aluminum Container - 83185 1x400','Unit Cost: 162.0',1,NULL,1.000,NULL,'EA',0.000,162.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(106,'ITEM-106','Aluminum Container - 73365 1x100','Unit Cost: 104.0',2,NULL,1.000,NULL,'EA',20.000,104.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(107,'ITEM-107','Cutlery Pack 500 pcs','Unit Cost: 72.0',2,NULL,1.000,NULL,'Boxes',1.000,72.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(108,'ITEM-108','Hand Soap 5 ltr x 4','Unit Cost: 33.0',2,NULL,1.000,NULL,'EA',1.000,33.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(109,'ITEM-109','PE Arm Sleeve 2000/Ctn','Unit Cost: 127.0',1,NULL,1.000,NULL,'EA',0.000,127.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(110,'ITEM-110','Frz EG Mixed Vegetables 2.5Kgx4 Hi-Chef','Unit Cost: 34.0',1,NULL,1.000,NULL,'EA',1.000,34.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(111,'ITEM-111','Frz EG Cauli Flower 2.5Kgx4 Hi-Chef','Unit Cost: 35.0',1,NULL,1.000,NULL,'EA',0.000,35.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(112,'ITEM-112','Frz EG Okra Zero 20gx400 Hi-Chef','Unit Cost: 83.0',1,NULL,1.000,NULL,'EA',0.000,83.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(113,'ITEM-113','Alpro Almond Barista 8x1Ltr','Unit Cost: 108.0',1,NULL,1.000,NULL,'EA',0.000,108.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(114,'ITEM-114','Alpro Coconut Barista 12x1Ltr','Unit Cost: 155.0',1,NULL,1.000,NULL,'EA',0.000,155.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(115,'ITEM-115','Sadia Breast Box','Unit Cost: 130.0',1,NULL,1.000,NULL,'EA',0.000,130.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(117,'ITEM-117','Sadia Chicken Shawarma - ctn','Unit Cost: 100.0',1,NULL,1.000,NULL,'EA',0.000,100.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(119,'ITEM-119','Sadia Whole Chicken 1100 Gms - 10 Pcs/Ctn','Unit Cost: 99.0',1,NULL,1.000,NULL,'EA',0.000,99.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(120,'ITEM-120','Aoun Moghrabiya 900 GR','Unit Cost: 9.6',1,NULL,1.000,NULL,'EA',0.000,9.6000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(121,'ITEM-121','Aoun Egyptian Rice 5 Kgs','Unit Cost: 27.9',1,NULL,1.000,NULL,'EA',1.000,27.9000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(122,'ITEM-122','Coco Mazaya Charcoal 10KG','Unit Cost: 84.0',1,NULL,1.000,NULL,'EA',0.000,84.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(123,'ITEM-123','Tannous Blossom Water 500 ML','Unit Cost: 5.95',1,NULL,1.000,NULL,'EA',1.000,5.9500,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(124,'ITEM-124','Happy Gardens Green Olives','Unit Cost: 163.2',1,NULL,1.000,NULL,'EA',0.000,163.2000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(125,'ITEM-125','Happy Gardens Black Olives 12 kgs','Unit Cost: 168.0',1,NULL,1.000,NULL,'EA',0.000,168.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(126,'ITEM-126','Wooden coffee Stirrer Paper Wrapped-14cm - CTN','Unit Cost: 123.0',2,NULL,1.000,NULL,'Boxes',2.000,123.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(127,'ITEM-127','Wet wipes 7*11cm -1000p','Unit Cost: 89.0',2,NULL,1.000,NULL,'Boxes',0.000,89.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(128,'ITEM-128','Q Paper Napkins 33x33cm - CTN','Unit Cost: 61.0',2,NULL,1.000,NULL,'EA',10.000,61.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(129,'ITEM-129','M/W Rectangular Cont w/Lid 1000ML - 500pcs','Unit Cost: 155.0',2,NULL,1.000,NULL,'EA',0.000,155.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(130,'ITEM-130','Kraft Salad Bowl 750ML - CTN','Unit Cost: 158.0',2,NULL,1.000,NULL,'EA',200.000,158.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(133,'ITEM-133','Plastic Rectangular Container 1500 - 300pcs','Unit Cost: 172.0',2,NULL,1.000,NULL,'EA',100.000,172.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(134,'ITEM-134','Aluminum Pot 173 * 50 pcs per ctn small size','Unit Cost: 105.0',1,NULL,1.000,NULL,'EA',0.000,105.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(135,'ITEM-135','Tanmiah Plain Chicken Breast 6 OZ - Calibrated 5*2Kg','Unit Cost: 180.0',1,NULL,1.000,NULL,'EA',0.000,180.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(136,'ITEM-136','Sadia Whole legs 10.8 Kg','Unit Cost: 135.0',1,NULL,1.000,NULL,'EA',0.000,135.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(137,'ITEM-137','FRZ EG Broccoli 2.5KGx4 Hi-Chef','Unit Cost: 47.0',1,NULL,1.000,NULL,'EA',0.000,47.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(138,'ITEM-138','FRZ EG Cut Beans 2.5x4kg Hi-Chef','Unit Cost: 43.0',1,NULL,1.000,NULL,'EA',0.000,43.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(139,'ITEM-139','FZ EG Strawberry 1kgx8 Vegie-Tut','Unit Cost: 60.0',1,NULL,1.000,NULL,'EA',0.000,60.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(140,'ITEM-140','Shrimp Frozen 11/15 1 Kg','Unit Cost: 28.0',1,NULL,1.000,NULL,'KG',0.000,28.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(141,'ITEM-141','Fish Filet Frozen - Vietnam 1 Kg','Unit Cost: 9.0',1,NULL,1.000,NULL,'EA',0.000,9.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(143,'ITEM-143','Cheddar Cheese Sauce (TIN) 3.4 Kg','Unit Cost: 38.33',1,NULL,1.000,NULL,'EA',0.000,38.3300,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(144,'ITEM-144','Chicken Stock Powder 2 Kg','Unit Cost: 33.33',1,NULL,1.000,NULL,'EA',0.000,33.3300,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(145,'ITEM-145','Beef Stock Powder 2 Kg','Unit Cost: 33.33',1,NULL,1.000,NULL,'EA',0.000,33.3300,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(146,'ITEM-146','StarSea Chunk White Tune in sunflower oil 6x1.85Kg','Unit Cost: 225.0',1,NULL,1.000,NULL,'EA',0.000,225.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(147,'ITEM-147','AlWadi-Pomegrenate Molasses 500mlx12','Unit Cost: 90.0',1,NULL,1.000,NULL,'EA',5.000,90.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(148,'ITEM-148','FRZ US Beef HotDog 6Inch 6/1Kg Oak Valley','Unit Cost: 72.0',1,NULL,1.000,NULL,'EA',0.000,72.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(149,'ITEM-149','Candia Puff Pastry Butter Extra Tourage 82% 1Kgx10','Unit Cost: 510.0',1,NULL,1.000,NULL,'EA',0.000,510.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(150,'ITEM-150','Dodoni - Feta Goat Cheese 200 GRS','Unit Cost: 14.0',1,NULL,1.000,NULL,'EA',0.000,14.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(151,'ITEM-151','Dodoni - Feta Cheese 200 GRS','Unit Cost: 14.5',1,NULL,1.000,NULL,'EA',2.000,14.5000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(152,'ITEM-152','AOUN RED LENTILS 900GR','Unit Cost: 9.5',1,NULL,1.000,NULL,'EA',2.000,9.5000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(153,'ITEM-153','Coombe Castle - Dragon Mild White Cheddar','Unit Cost: 23.0',1,NULL,1.000,NULL,'KG',0.000,23.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(154,'ITEM-154','Talmera - Monterey Jack Block Cheese','Unit Cost: 34.0',1,NULL,1.000,NULL,'KG',0.000,34.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(155,'ITEM-155','Bister Dijon Mustard - 5KG','Unit Cost: 70.0',1,NULL,1.000,NULL,'EA',1.000,70.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(156,'ITEM-156','Kings Harvest - Crispy Fried Onions','Unit Cost: 27.0',1,NULL,1.000,NULL,'KG',0.000,27.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(157,'ITEM-157','Benina Quinoa Multicolor 1000G','Unit Cost: 23.0',1,NULL,1.000,NULL,'EA',0.000,23.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(158,'ITEM-158','Colavita Balsamic Vinegar - 5L','Unit Cost: 49.0',1,NULL,1.000,NULL,'EA',0.000,49.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(159,'ITEM-159','Sugar Renuka 50Kgs','Unit Cost: 108.0',1,NULL,1.000,NULL,'EA',0.000,108.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(160,'ITEM-160','QFM Flour No1 Bag 50 Kg','Unit Cost: 145.0',1,NULL,1.000,NULL,'EA',0.000,145.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(161,'ITEM-161','Almond Slice 1 Kg','Unit Cost: 45.0',1,NULL,1.000,NULL,'EA',0.000,45.0000,'2025-12-13 08:51:55',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-13 08:51:55'),(162,'ITEM-162','Wallnut 1 Kg','Unit Cost: 35.0',1,NULL,1.000,NULL,'EA',0.000,35.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(163,'ITEM-163','Latex Gloves Large','Unit Cost: 51.0',2,NULL,1.000,NULL,'EA',4.000,51.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(164,'ITEM-164','Frz EG Chopped Spinach 2.5KGx4 Hi-Chef','Unit Cost: 35.0',1,NULL,1.000,NULL,'EA',0.000,35.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(165,'ITEM-165','Zeeba Rice 3 kgs x12','Unit Cost: 144.0',1,NULL,1.000,NULL,'EA',5.000,144.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(166,'ITEM-166','Sadia Hot Dog small size 20x340gr','Unit Cost: 74.0',1,NULL,1.000,NULL,'EA',0.000,74.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(167,'ITEM-167','Delta Top Side Meat','Unit Cost: 24.0',1,NULL,1.000,NULL,'KG',0.000,24.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(168,'ITEM-168','Black Base 2 compartement RE 2/32 - 150pcs/Ctn','Unit Cost: 103.0',2,NULL,1.000,NULL,'EA',50.000,103.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(169,'ITEM-169','FZ EG Mango SlicesFahed Food 1Kgx8 / Box','Unit Cost: 69.0',1,NULL,1.000,NULL,'EA',0.000,69.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(170,'ITEM-170','Unsalted Butter 82% Beurre Doux 10x1Kg - France','Unit Cost: 495.0',1,NULL,1.000,NULL,'EA',0.000,495.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(171,'ITEM-171','Croissant Butter 82% Le Grand Tourage 10x1Kg','Unit Cost: 575.0',1,NULL,1.000,NULL,'EA',0.000,575.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(172,'ITEM-172','Milk Powder - SLG 25 Kgs','Unit Cost: 290.0',1,NULL,1.000,NULL,'EA',0.000,290.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(173,'ITEM-173','Benina - Parmesan Grana Moravia Cheese 5 Kg','Unit Cost: 250.0',1,NULL,1.000,NULL,'KG',1.000,250.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(175,'ITEM-175','SUGAR PASTE black 1KG X 12PCS','Unit Cost: 168.0',1,NULL,1.000,NULL,'EA',0.000,168.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(176,'ITEM-176','Aoun White Kidney Beans 900GR','Unit Cost: 10.79',1,NULL,1.000,NULL,'EA',0.000,10.7900,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(177,'ITEM-177','Aoun Red Round Beans 900GR','Unit Cost: 13.0',1,NULL,1.000,NULL,'EA',0.000,13.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(178,'ITEM-178','Aoun Cumin Powder 500GR','Unit Cost: 22.53',1,NULL,1.000,NULL,'EA',0.000,22.5300,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(179,'ITEM-179','Aoun Corriander Powder 500GR','Unit Cost: 11.64',1,NULL,1.000,NULL,'EA',0.000,11.6400,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(180,'ITEM-180','Aoun Cinnamon Powder 500GR','Unit Cost: 14.5',1,NULL,1.000,NULL,'EA',0.000,14.5000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(181,'ITEM-181','Aoun White Pepper Powder 500GR','Unit Cost: 41.8',1,NULL,1.000,NULL,'EA',0.000,41.8000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(182,'ITEM-182','Happy Gardens Tahina Extra 8.58 NW - GW 9.00','Unit Cost: 162.56',1,NULL,1.000,NULL,'EA',1.000,162.5600,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(183,'ITEM-183','Happy Gardens Tehina Brl N.W. 10KG','Unit Cost: 160.0',1,NULL,1.000,NULL,'EA',0.000,160.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(185,'ITEM-185','Toilet Roll 10pcs','Unit Cost: 9.0',2,NULL,1.000,NULL,'EA',10.000,9.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(186,'ITEM-186','Tissue Box - 30pcs','Unit Cost: 48.0',2,NULL,1.000,NULL,'EA',5.000,48.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(188,'ITEM-188','Hair Net Black 1000pcs','Unit Cost: 38.0',2,NULL,1.000,NULL,'EA',4.000,38.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(189,'ITEM-189','Paper Bag 30x25x15 Brown','Unit Cost: 135.0',2,NULL,1.000,NULL,'EA',100.000,135.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(191,'ITEM-191','HD Spoon Black 1000pcs','Unit Cost: 50.0',1,NULL,1.000,NULL,'EA',0.000,50.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(192,'ITEM-192','Black Garbage Bag 120x140','Unit Cost: 55.0',2,NULL,1.000,NULL,'EA',0.000,55.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(193,'ITEM-193','Clorex 4x4ltr','Unit Cost: 36.0',2,NULL,1.000,NULL,'EA',1.000,36.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(194,'ITEM-194','RP Plastic Container Round 250 ml 500pcs','Unit Cost: 77.0',2,NULL,1.000,NULL,'EA',200.000,77.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(195,'ITEM-195','Nylon Foil','Unit Cost: 28.0',2,NULL,1.000,NULL,'EA',0.000,28.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(196,'ITEM-196','Aluminum Plate 6586 - 100pcs','Unit Cost: 48.0',1,NULL,1.000,NULL,'EA',0.000,48.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(197,'ITEM-197','White Plastic bag 20pkt','Unit Cost: 45.0',2,NULL,1.000,NULL,'Packets',2.000,45.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(198,'ITEM-198','IT Chopped Tomatoes 2550GMx6 Patisiya','Unit Cost: 75.0',1,NULL,1.000,NULL,'EA',1.000,75.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(199,'ITEM-199','Aviko H Patatas Bravas 4x2500g','Unit Cost: 110.0',1,NULL,1.000,NULL,'EA',0.000,110.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(200,'ITEM-200','LAKELAND - MILLAC WHIP TOPPING UNSWEETENED (12X1L)','Unit Cost: 189.0',1,NULL,1.000,NULL,'EA',0.000,189.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(201,'ITEM-201','GRANORO PENNE RIGATE 24X500G (1026)','Unit Cost: 129.6',1,NULL,1.000,NULL,'EA',4.000,129.6000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(202,'ITEM-202','GRANORO DEDICATO &quot;NIDI FETTUCCINE&quot; 12X500G (48082)','Unit Cost: 90.0',1,NULL,1.000,NULL,'EA',2.000,90.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(203,'ITEM-203','ALKARAMAH - SPRING ROLLS SMALL{24X160G}','Unit Cost: 78.0',1,NULL,1.000,NULL,'EA',0.000,78.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(204,'ITEM-204','EGG LARGE 360PCS (12X30\'s)','Unit Cost: 155.0',1,NULL,1.000,NULL,'EA',0.000,155.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(205,'ITEM-205','Bake XL - Improver 10Kgs','Unit Cost: 163.0',1,NULL,1.000,NULL,'EA',0.000,163.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(206,'ITEM-206','Milk Compound Chocolate 5Kgs','Unit Cost: 80.0',1,NULL,1.000,NULL,'EA',0.000,80.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(207,'ITEM-207','White Compound Chocolate 5Kgs','Unit Cost: 80.0',1,NULL,1.000,NULL,'EA',0.000,80.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(208,'ITEM-208','Dark Compound Chocolate','Unit Cost: 80.0',1,NULL,1.000,NULL,'EA',0.000,80.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(209,'ITEM-209','Frozen Mix Berries 2.5kgs Andros Chef','Unit Cost: 87.5',1,NULL,1.000,NULL,'EA',0.000,87.5000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(210,'ITEM-210','Tomex Spinach Leaf 4x2.5kgs','Unit Cost: 55.0',1,NULL,1.000,NULL,'EA',0.000,55.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(211,'ITEM-211','Happy Gardens Vine Leaves -Deluxe 908GR 1x12','Unit Cost: 160.65',1,NULL,1.000,NULL,'EA',5.000,160.6500,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(212,'ITEM-212','Al Wajba Red Vinegar 1ltr x 12 pcs','Unit Cost: 34.0',1,NULL,1.000,NULL,'EA',0.000,34.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(213,'ITEM-213','Mission Tortilla Wraps Wheat 20A 8x12 120c Original 320g','Unit Cost: 6.0',1,NULL,1.000,NULL,'EA',1.000,6.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(214,'ITEM-214','Mission Tortilla Wraps 25 A 6x13 105c Original 378 g','Unit Cost: 7.0',1,NULL,1.000,NULL,'EA',0.000,7.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(215,'ITEM-215','Aviko Crunchy Crispy 9.5mm','Unit Cost: 90.0',1,NULL,1.000,NULL,'EA',1.000,90.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(216,'ITEM-216','Eye Round Chilled South African','Unit Cost: 32.0',1,NULL,1.000,NULL,'EA',0.000,32.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(217,'ITEM-217','AOUN WHITE BURGHUL HARD 900Gr','Unit Cost: 5.52',1,NULL,1.000,NULL,'EA',0.000,5.5200,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(218,'ITEM-218','Al Anabi- Vermicelli 24x400G','Unit Cost: 79.2',1,16,24.000,NULL,'EA',0.000,79.2000,'2025-12-12 16:17:26',NULL,NULL,'active','2025-10-29 10:33:20','2026-01-28 21:26:59'),(219,'ITEM-219','QK-South African Chilled Beef Knuckle','Unit Cost: 32.0',1,NULL,1.000,NULL,'EA',0.000,32.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(220,'ITEM-220','Vizyon White Sugar Paste 1kgx12Pcs','Unit Cost: 180.0',1,NULL,1.000,NULL,'EA',2.000,180.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(221,'ITEM-221','Piping Bag 10 Pcs','Unit Cost: 195.0',2,NULL,1.000,NULL,'EA',2.000,195.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(222,'ITEM-222','Vizyon Custard 1Kgx10pcs','Unit Cost: 285.0',1,NULL,1.000,NULL,'EA',0.000,285.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(223,'ITEM-223','Spiral Whipping Cream 1 Ltrx12pcs','Unit Cost: 140.0',1,NULL,1.000,NULL,'EA',5.000,140.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(224,'ITEM-224','QNited Paper Bag 31x36x18 200pcs','Unit Cost: 140.0',2,NULL,1.000,NULL,'EA',30.000,140.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(226,'ITEM-226','Qnited Paper BAg 22x14x21 200 Pcs','Unit Cost: 80.0',2,NULL,1.000,NULL,'EA',50.000,80.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(227,'ITEM-227','Qnited Kraft Salad Bowl 500ML - 300 pcs/CTN','Unit Cost: 130.0',2,NULL,1.000,NULL,'EA',150.000,130.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(229,'ITEM-229','Qnited 1 Compartement 250pcs/Ctn','Unit Cost: 90.0',2,NULL,1.000,NULL,'EA',50.000,90.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(230,'ITEM-230','Qnited Hinged Clear Container Small Size 300pcs/CTN','Unit Cost: 45.0',2,NULL,1.000,NULL,'EA',200.000,45.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(232,'ITEM-232','Qnited White Plastic Bag 5Kgs','Unit Cost: 36.0',2,NULL,1.000,NULL,'EA',0.000,36.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(233,'ITEM-233','Qnited Plastic Blue Bag 5Kgs','Unit Cost: 36.0',2,NULL,1.000,NULL,'EA',0.000,36.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(234,'ITEM-234','Lexquis- Emmental Block','Unit Cost: 35.72',1,NULL,1.000,NULL,'EA',1.000,35.7200,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(235,'ITEM-235','Mission - Tort Wheat 15 A 8x15 171c Original - 200g','Unit Cost: 5.0',1,NULL,1.000,NULL,'EA',0.000,5.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(236,'ITEM-236','Talmera - 120 Slice American Cheese Colored SSI - 2.27Kg','Unit Cost: 56.75',1,NULL,1.000,NULL,'KG',0.000,56.7500,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(237,'ITEM-237','Talmera - 160 Slice American Cheese Colored - 2.27Kg','Unit Cost: 56.75',1,NULL,1.000,NULL,'KG',1.000,56.7500,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(238,'ITEM-238','Chilled BR Beef Topside BL JBS','Unit Cost: 29.0',1,NULL,1.000,NULL,'KG',0.000,29.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(239,'ITEM-239','Al Rayes Echo Foamchlor FC313 5ltr','Unit Cost: 49.0',2,NULL,1.000,NULL,'EA',1.000,49.0000,'2025-12-12 20:04:20','',NULL,'active','2025-10-29 10:33:20','2025-12-12 20:04:20'),(240,'ITEM-240','Al Rayes Echo Zan BK 1 ltr','Unit Cost: 80.0',2,NULL,1.000,NULL,'EA',1.000,80.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(241,'ITEM-241','Al Rayes Dish Soap Ginger Lemon 5LTR','Unit Cost: 40.0',2,NULL,1.000,NULL,'EA',1.000,40.0000,'2025-12-12 20:04:20','',NULL,'active','2025-10-29 10:33:20','2025-12-12 20:04:20'),(242,'ITEM-242','Al Rayes Bio Lab Maxi Roll 600 G Green','Unit Cost: 30.0',2,NULL,1.000,NULL,'EA',6.000,30.0000,'2025-12-12 21:06:07','',NULL,'active','2025-10-29 10:33:20','2025-12-12 21:06:07'),(243,'ITEM-243','Icing sugar 2Kg 5 pkt','Unit Cost: 60.0',1,NULL,1.000,NULL,'EA',1.000,60.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(244,'ITEM-244','Backaldrin Dry Yeast 20Pc x 500g','Unit Cost: 125.0',1,NULL,1.000,NULL,'EA',0.000,125.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(245,'ITEM-245','Vanilla Liquid Btl','Unit Cost: 61.0',1,NULL,1.000,NULL,'EA',0.000,61.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(246,'ITEM-246','U shape PET Juice cup 12OZ 1000pc/Box','Unit Cost: 150.0',2,NULL,1.000,NULL,'Packets',0.000,150.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(247,'ITEM-247','Stretch film 2.5Kgx6','Unit Cost: 100.0',2,NULL,1.000,NULL,'EA',1.000,100.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(248,'ITEM-248','Flour Turkey 50 Kgs','Unit Cost: 90.0',1,NULL,1.000,NULL,'EA',0.000,90.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(249,'ITEM-249','Pure Ghee 1kg','Unit Cost: 37.0',1,NULL,1.000,NULL,'EA',0.000,37.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(250,'ITEM-250','Hogget Mutton Whole','Unit Cost: 37.0',1,NULL,1.000,NULL,'EA',0.000,37.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(251,'ITEM-251','Qnited Cocoa Powder 10Kg Reference 900','Unit Cost: 300.0',1,NULL,1.000,NULL,'EA',1.000,300.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(252,'ITEM-252','Mayonnaise','Unit Cost: 40.0',1,NULL,1.000,NULL,'EA',1.000,40.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(253,'ITEM-253','Mozarella Sticks 6 KG','Unit Cost: 175.0',1,NULL,1.000,NULL,'EA',0.000,175.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(254,'ITEM-254','Masoor Dal 15Kg','Unit Cost: 58.0',1,NULL,15.000,'','EA',1.000,58.0000,'2025-12-06 06:51:36','',NULL,'active','2025-10-29 10:33:20','2025-12-06 06:51:36'),(255,'ITEM-255','Mashhor Indian 1121 Long Grain Basmati Rice 1x35kg','Unit Cost: 140.0',1,NULL,35.000,'','EA',0.000,140.0000,'2025-12-06 06:51:04','',NULL,'active','2025-10-29 10:33:20','2025-12-06 06:51:04'),(256,'ITEM-256','Pena branca 1500gms  whole chicken','Unit Cost: 88.0',1,NULL,1.000,NULL,'EA',0.000,88.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(257,'ITEM-257','Sunflower oil 4x5Lit','Unit Cost: 108.0',1,NULL,1.000,NULL,'EA',5.000,108.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(258,'ITEM-258','Fresh Eggs Grade A -','Unit Cost: 120.0',1,NULL,1.000,NULL,'Boxes',0.000,120.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(260,'ITEM-260','Sona Masoori Rice 35 Kg','Unit Cost: 82.0',1,NULL,35.000,'KG','EA',1.000,82.0000,'2025-12-06 06:49:57','',NULL,'active','2025-10-29 10:33:20','2025-12-06 06:49:57'),(262,'ITEM-262','Frozen Mackerel Whole 10kgs','Unit Cost: 65.0',1,NULL,1.000,NULL,'KG',0.000,65.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(263,'ITEM-263','Sun White Rice 20Kg','Unit Cost: 128.0',1,NULL,1.000,NULL,'EA',0.000,128.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(264,'ITEM-264','White Vinegar 4x3.78ltr','Unit Cost: 18.0',1,NULL,1.000,NULL,'EA',1.000,18.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(265,'ITEM-265','HAPPY GARDENS PICKLED GRAPE LEAVES','Unit Cost: 13.06',1,NULL,1.000,NULL,'EA',5.000,13.0600,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(266,'ITEM-266','AOUN WHITE BURGHUL FINE 4KG','Unit Cost: 23.0',1,NULL,1.000,NULL,'EA',1.000,23.0000,'2025-12-05 15:24:34','',NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(267,'ITEM-267','Frozen Spinach 10x1kg KLA India','Unit Cost: 37.0',1,NULL,1.000,NULL,'EA',0.000,37.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(268,'ITEM-268','Beef Slice Alwahid 20x900g','Unit Cost: 309.0',1,NULL,1.000,NULL,'EA',0.000,309.0000,'2025-12-05 15:24:34',NULL,NULL,'active','2025-10-29 10:33:20','2025-12-05 15:24:34'),(270,'ITEM-269','Paper Cup 4 Oz - Small','',2,NULL,1.000,NULL,'EA',100.000,NULL,NULL,'',NULL,'active','2025-11-11 12:05:15','2025-11-15 08:28:53'),(272,'ITEM-271','Sauce Cup 20z (20pcksts x1000pcs)','QAR 95',2,NULL,1.000,NULL,'EA',200.000,NULL,NULL,'',NULL,'active','2025-11-15 08:04:55','2025-11-24 07:53:00'),(273,'ITEM-272','Chicken Breast Aurora 2Kgx6','QAR 159',1,NULL,1.000,NULL,'KG',5.000,NULL,NULL,'',NULL,'active','2025-11-15 10:37:32','2025-11-23 10:03:26'),(274,'ITEM-273','Rose Water','',1,NULL,1.000,NULL,'0',0.000,NULL,NULL,'',NULL,'active','2025-11-15 13:32:58','2025-11-15 13:33:12'),(275,'ITEM-274','Tomato Ketchup','',1,NULL,1.000,NULL,'EA',1.000,NULL,NULL,'',NULL,'active','2025-11-15 13:39:06','2025-11-15 13:39:06'),(276,'ITEM-275','Sponge','',2,NULL,1.000,NULL,'EA',5.000,NULL,NULL,'',NULL,'active','2025-11-29 10:05:50','2025-11-29 10:05:50'),(277,'INV-001','test',NULL,1,23,24.000,NULL,'Pcs',1.000,100.0000,'2026-01-29 06:37:47',NULL,NULL,'active','2026-01-29 06:37:47','2026-01-29 06:37:47');
/*!40000 ALTER TABLE `inventory_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_stocks`
--

DROP TABLE IF EXISTS `inventory_stocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_stocks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `inventory_item_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `current_stock` decimal(12,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_stocks_item_branch_unique` (`inventory_item_id`,`branch_id`),
  KEY `inventory_stocks_branch_index` (`branch_id`),
  KEY `inventory_stocks_item_index` (`inventory_item_id`),
  CONSTRAINT `inventory_stocks_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `inventory_stocks_item_fk` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=264 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_stocks`
--

LOCK TABLES `inventory_stocks` WRITE;
/*!40000 ALTER TABLE `inventory_stocks` DISABLE KEYS */;
INSERT INTO `inventory_stocks` VALUES (1,1,1,100.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(2,2,1,80.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(3,4,1,601.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(4,5,1,550.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(5,6,1,8.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(6,7,1,100.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(7,8,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(8,9,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(9,10,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(10,11,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(11,12,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(12,13,1,9.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(13,14,1,20.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(14,17,1,20.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(15,18,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(16,20,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(17,21,1,10.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(18,22,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(19,24,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(20,25,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(21,26,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(22,27,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(23,28,1,7.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(24,29,1,7.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(25,30,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(26,31,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(27,32,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(28,33,1,35.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(29,35,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(30,36,1,12.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(31,37,1,6.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(32,38,1,5.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(33,39,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(34,42,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(35,43,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(36,44,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(37,45,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(38,46,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(39,47,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(40,48,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(41,49,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(42,50,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(43,51,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(44,52,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(45,53,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(46,54,1,10.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(47,55,1,6.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(48,56,1,4.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(49,57,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(50,58,1,20.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(51,59,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(52,60,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(53,61,1,4.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(54,62,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(55,63,1,35.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(56,65,1,10.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(57,66,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(58,67,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(59,68,1,10.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(60,69,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(61,71,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(62,72,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(63,73,1,4.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(64,74,1,4.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(65,75,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(66,76,1,29.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(67,77,1,29.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(68,78,1,4.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(69,79,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(70,80,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(71,81,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(72,84,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(73,85,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(74,86,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(75,87,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(76,88,1,5.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(77,89,1,4.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(78,90,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(79,92,1,14.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(80,95,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(81,96,1,126.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(82,97,1,200.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(83,99,1,11.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(84,101,1,440.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(85,102,1,3.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(86,103,1,150.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(87,104,1,100.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(88,105,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(89,106,1,100.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(90,107,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(91,108,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(92,109,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(93,110,1,8.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(94,111,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(95,112,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(96,113,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(97,114,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(98,115,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(99,117,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(100,119,1,100.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(101,120,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(102,121,1,3.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(103,122,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(104,123,1,0.000,'2026-01-28 10:52:21','2026-01-28 22:02:52'),(105,124,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(106,125,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(107,126,1,13.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(108,127,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(109,128,1,60.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(110,129,1,650.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(111,130,1,200.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(112,133,1,200.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(113,134,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(114,135,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(115,136,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(116,137,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(117,138,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(118,139,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(119,140,1,10.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(120,141,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(121,143,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(122,144,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(123,145,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(124,146,1,4.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(125,147,1,15.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(126,148,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(127,149,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(128,150,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(129,151,1,12.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(130,152,1,3.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(131,153,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(132,154,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(133,155,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(134,156,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(135,157,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(136,158,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(137,159,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(138,160,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(139,161,1,10.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(140,162,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(141,163,1,5.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(142,164,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(143,165,1,24.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(144,166,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(145,167,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(146,168,1,350.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(147,169,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(148,170,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(149,171,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(150,172,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(151,173,1,5.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(152,175,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(153,176,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(154,177,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(155,178,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(156,179,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(157,180,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(158,181,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(159,182,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(160,183,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(161,185,1,78.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(162,186,1,19.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(163,188,1,7.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(164,189,1,400.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(165,191,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(166,192,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(167,193,1,4.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(168,194,1,300.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(169,195,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(170,196,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(171,197,1,14.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(172,198,1,13.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(173,199,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(174,200,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(175,201,1,12.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(176,202,1,12.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(177,203,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(178,204,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(179,205,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(180,206,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(181,207,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(182,208,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(183,209,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(184,210,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(185,211,1,36.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(186,212,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(187,213,1,3.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(188,214,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(189,215,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(190,216,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(191,217,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(192,218,1,100.000,'2026-01-28 10:52:21','2026-01-28 21:28:22'),(193,219,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(194,220,1,12.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(195,221,1,7.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(196,222,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(197,223,1,24.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(198,224,1,400.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(199,226,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(200,227,1,400.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(201,229,1,250.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(202,230,1,450.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(203,232,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(204,233,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(205,234,1,4.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(206,235,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(207,236,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(208,237,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(209,238,1,80.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(210,239,1,11.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(211,240,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(212,241,1,10.000,'2026-01-28 10:52:21','2026-01-28 21:56:51'),(213,242,1,18.000,'2026-01-28 10:52:21','2026-01-28 21:59:59'),(214,243,1,5.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(215,244,1,20.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(216,245,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(217,246,1,17.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(218,247,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(219,248,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(220,249,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(221,250,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(222,251,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(223,252,1,6.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(224,253,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(225,254,1,3.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(226,255,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(227,256,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(228,257,1,41.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(229,258,1,3.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(230,260,1,3.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(231,262,1,20.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(232,263,1,2.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(233,264,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(234,265,1,36.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(235,266,1,3.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(236,267,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(237,268,1,0.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(238,270,1,1000.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(239,272,1,1300.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(240,273,1,30.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(241,274,1,1.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(242,275,1,3.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(243,276,1,22.000,'2026-01-28 10:52:21','2026-01-28 10:52:21'),(256,218,2,20.000,'2026-01-28 21:25:00','2026-01-28 21:28:22'),(259,241,2,1.000,'2026-01-28 21:56:51','2026-01-28 21:56:51'),(260,242,2,2.000,'2026-01-28 21:56:51','2026-01-28 21:59:59'),(262,123,2,2.000,'2026-01-28 22:02:52','2026-01-28 22:02:52'),(263,277,1,1.917,'2026-01-29 06:37:47','2026-01-29 06:47:44');
/*!40000 ALTER TABLE `inventory_stocks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_transactions`
--

DROP TABLE IF EXISTS `inventory_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL DEFAULT 1,
  `transaction_type` enum('in','out','adjustment') DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost` decimal(12,4) DEFAULT NULL,
  `total_cost` decimal(12,4) DEFAULT NULL,
  `reference_type` enum('purchase_order','work_order','manual','recipe','transfer') NOT NULL DEFAULT 'manual',
  `reference_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `transaction_date` timestamp NULL DEFAULT current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `item_id` (`item_id`),
  KEY `user_id` (`user_id`),
  KEY `inventory_transactions_item_id_index` (`item_id`),
  KEY `inventory_transactions_user_id_index` (`user_id`),
  KEY `inventory_transactions_type_index` (`transaction_type`),
  KEY `inventory_transactions_reference_type_index` (`reference_type`),
  KEY `inventory_transactions_date_index` (`transaction_date`),
  KEY `inventory_transactions_reference_index` (`reference_type`,`reference_id`),
  KEY `inventory_transactions_branch_index` (`branch_id`),
  CONSTRAINT `inv_tx_item_fk` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `inventory_transactions_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `inventory_transactions_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=285 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_transactions`
--

LOCK TABLES `inventory_transactions` WRITE;
/*!40000 ALTER TABLE `inventory_transactions` DISABLE KEYS */;
INSERT INTO `inventory_transactions` VALUES (4,4,1,'in',1245.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 10:49:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(5,5,1,'in',800.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 10:52:27','2025-12-12 17:53:40','2025-12-12 17:53:40'),(6,7,1,'in',200.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 10:53:37','2025-12-12 17:53:40','2025-12-12 17:53:40'),(7,9,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 10:54:27','2025-12-12 17:53:40','2025-12-12 17:53:40'),(8,10,1,'in',18.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 10:54:39','2025-12-12 17:53:40','2025-12-12 17:53:40'),(9,13,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 10:55:44','2025-12-12 17:53:40','2025-12-12 17:53:40'),(10,37,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:08:02','2025-12-12 17:53:40','2025-12-12 17:53:40'),(11,90,1,'in',17.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:19:57','2025-12-12 17:53:40','2025-12-12 17:53:40'),(12,101,1,'in',550.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:21:37','2025-12-12 17:53:40','2025-12-12 17:53:40'),(13,101,1,'out',50.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:22:10','2025-12-12 17:53:40','2025-12-12 17:53:40'),(14,168,1,'in',200.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:22:41','2025-12-12 17:53:40','2025-12-12 17:53:40'),(15,104,1,'in',150.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:23:38','2025-12-12 17:53:40','2025-12-12 17:53:40'),(16,133,1,'in',300.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:24:48','2025-12-12 17:53:40','2025-12-12 17:53:40'),(17,194,1,'in',800.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:27:40','2025-12-12 17:53:40','2025-12-12 17:53:40'),(18,230,1,'in',950.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:29:08','2025-12-12 17:53:40','2025-12-12 17:53:40'),(19,97,1,'in',2045.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:34:33','2025-12-12 17:53:40','2025-12-12 17:53:40'),(20,227,1,'in',800.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:35:55','2025-12-12 17:53:40','2025-12-12 17:53:40'),(21,130,1,'in',850.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:39:37','2025-12-12 17:53:40','2025-12-12 17:53:40'),(22,227,1,'out',250.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:39:46','2025-12-12 17:53:40','2025-12-12 17:53:40'),(23,92,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:41:20','2025-12-12 17:53:40','2025-12-12 17:53:40'),(24,103,1,'in',20.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:45:22','2025-12-12 17:53:40','2025-12-12 17:53:40'),(25,188,1,'in',11.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:46:12','2025-12-12 17:53:40','2025-12-12 17:53:40'),(26,163,1,'in',18.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:46:49','2025-12-12 17:53:40','2025-12-12 17:53:40'),(27,89,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:47:24','2025-12-12 17:53:40','2025-12-12 17:53:40'),(29,242,1,'in',36.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:48:20','2025-12-12 17:53:40','2025-12-12 17:53:40'),(30,186,1,'in',14.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:48:58','2025-12-12 17:53:40','2025-12-12 17:53:40'),(31,128,1,'in',46.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:52:59','2025-12-12 17:53:40','2025-12-12 17:53:40'),(32,233,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:53:27','2025-12-12 17:53:40','2025-12-12 17:53:40'),(33,232,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:53:40','2025-12-12 17:53:40','2025-12-12 17:53:40'),(34,246,1,'in',17.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:54:15','2025-12-12 17:53:40','2025-12-12 17:53:40'),(35,96,1,'in',126.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:58:34','2025-12-12 17:53:40','2025-12-12 17:53:40'),(36,163,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 11:58:56','2025-12-12 17:53:40','2025-12-12 17:53:40'),(37,221,1,'in',9.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 12:00:16','2025-12-12 17:53:40','2025-12-12 17:53:40'),(38,107,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 12:00:59','2025-12-12 17:53:40','2025-12-12 17:53:40'),(39,127,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 12:02:23','2025-12-12 17:53:40','2025-12-12 17:53:40'),(41,192,1,'in',60.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-11 12:06:39','2025-12-12 17:53:40','2025-12-12 17:53:40'),(43,12,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 07:27:31','2025-12-12 17:53:40','2025-12-12 17:53:40'),(44,213,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 07:33:23','2025-12-12 17:53:40','2025-12-12 17:53:40'),(45,1,1,'in',10.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 10:55:45','2025-12-12 17:53:40','2025-12-12 17:53:40'),(46,63,1,'in',24.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 11:03:14','2025-12-12 17:53:40','2025-12-12 17:53:40'),(47,65,1,'in',10.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 11:03:54','2025-12-12 17:53:40','2025-12-12 17:53:40'),(48,211,1,'in',36.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 11:04:39','2025-12-12 17:53:40','2025-12-12 17:53:40'),(49,6,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 11:05:29','2025-12-12 17:53:40','2025-12-12 17:53:40'),(50,7,1,'out',50.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 11:05:48','2025-12-12 17:53:40','2025-12-12 17:53:40'),(52,17,1,'in',20.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 11:09:17','2025-12-12 17:53:40','2025-12-12 17:53:40'),(54,21,1,'in',10.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 11:10:48','2025-12-12 17:53:40','2025-12-12 17:53:40'),(55,22,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 11:18:40','2025-12-12 17:53:40','2025-12-12 17:53:40'),(56,35,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 12:32:07','2025-12-12 17:53:40','2025-12-12 17:53:40'),(57,36,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 12:55:04','2025-12-12 17:53:40','2025-12-12 17:53:40'),(58,37,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-12 12:55:38','2025-12-12 17:53:40','2025-12-12 17:53:40'),(59,221,1,'out',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-13 06:54:31','2025-12-12 17:53:40','2025-12-12 17:53:40'),(60,221,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-13 06:54:40','2025-12-12 17:53:40','2025-12-12 17:53:40'),(61,229,1,'in',100.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-13 06:56:29','2025-12-12 17:53:40','2025-12-12 17:53:40'),(62,194,1,'in',380.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-13 06:58:10','2025-12-12 17:53:40','2025-12-12 17:53:40'),(64,1,1,'in',90.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 07:23:40','2025-12-12 17:53:40','2025-12-12 17:53:40'),(65,104,1,'in',150.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 07:29:39','2025-12-12 17:53:40','2025-12-12 17:53:40'),(66,103,1,'in',130.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 07:33:01','2025-12-12 17:53:40','2025-12-12 17:53:40'),(67,92,1,'in',7.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 07:34:37','2025-12-12 17:53:40','2025-12-12 17:53:40'),(68,92,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 07:34:55','2025-12-12 17:53:40','2025-12-12 17:53:40'),(69,168,1,'out',50.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 07:43:35','2025-12-12 17:53:40','2025-12-12 17:53:40'),(70,272,1,'in',900.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:04:55','2025-12-12 17:53:40','2025-12-12 17:53:40'),(71,185,1,'in',27.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:06:30','2025-12-12 17:53:40','2025-12-12 17:53:40'),(72,242,1,'out',9.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:07:19','2025-12-12 17:53:40','2025-12-12 17:53:40'),(73,197,1,'in',14.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:16:35','2025-12-12 17:53:40','2025-12-12 17:53:40'),(74,185,1,'in',51.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:17:03','2025-12-12 17:53:40','2025-12-12 17:53:40'),(75,188,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:18:21','2025-12-12 17:53:40','2025-12-12 17:53:40'),(76,189,1,'in',150.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:18:53','2025-12-12 17:53:40','2025-12-12 17:53:40'),(77,247,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:19:50','2025-12-12 17:53:40','2025-12-12 17:53:40'),(78,163,1,'out',14.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:20:17','2025-12-12 17:53:40','2025-12-12 17:53:40'),(79,241,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:21:03','2025-12-12 17:53:40','2025-12-12 17:53:40'),(80,240,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:21:46','2025-12-12 17:53:40','2025-12-12 17:53:40'),(81,239,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:22:30','2025-12-12 17:53:40','2025-12-12 17:53:40'),(82,270,1,'in',1000.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:28:53','2025-12-12 17:53:40','2025-12-12 17:53:40'),(83,193,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:31:52','2025-12-12 17:53:40','2025-12-12 17:53:40'),(84,234,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:35:59','2025-12-12 17:53:40','2025-12-12 17:53:40'),(85,173,1,'in',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:36:40','2025-12-12 17:53:40','2025-12-12 17:53:40'),(86,236,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:37:19','2025-12-12 17:53:40','2025-12-12 17:53:40'),(87,236,1,'out',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:37:52','2025-12-12 17:53:40','2025-12-12 17:53:40'),(88,237,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:38:06','2025-12-12 17:53:40','2025-12-12 17:53:40'),(89,44,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:39:43','2025-12-12 17:53:40','2025-12-12 17:53:40'),(90,151,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:40:24','2025-12-12 17:53:40','2025-12-12 17:53:40'),(91,44,1,'out',10.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 08:40:59','2025-12-12 17:53:40','2025-12-12 17:53:40'),(92,238,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:12:22','2025-12-12 17:53:40','2025-12-12 17:53:40'),(93,238,1,'in',78.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:13:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(94,265,1,'in',36.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:20:48','2025-12-12 17:53:40','2025-12-12 17:53:40'),(95,68,1,'in',10.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:31:02','2025-12-12 17:53:40','2025-12-12 17:53:40'),(96,273,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:37:32','2025-12-12 17:53:40','2025-12-12 17:53:40'),(97,262,1,'in',20.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:38:14','2025-12-12 17:53:40','2025-12-12 17:53:40'),(98,254,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:38:35','2025-12-12 17:53:40','2025-12-12 17:53:40'),(99,260,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:38:47','2025-12-12 17:53:40','2025-12-12 17:53:40'),(100,8,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:39:55','2025-12-12 17:53:40','2025-12-12 17:53:40'),(101,251,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 10:56:32','2025-12-12 17:53:40','2025-12-12 17:53:40'),(102,226,1,'in',475.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:03:47','2025-12-12 17:53:40','2025-12-12 17:53:40'),(103,224,1,'in',70.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:04:05','2025-12-12 17:53:40','2025-12-12 17:53:40'),(104,52,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:08:50','2025-12-12 17:53:40','2025-12-12 17:53:40'),(105,220,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:09:38','2025-12-12 17:53:40','2025-12-12 17:53:40'),(106,215,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:10:26','2025-12-12 17:53:40','2025-12-12 17:53:40'),(107,54,1,'in',10.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:19:29','2025-12-12 17:53:40','2025-12-12 17:53:40'),(108,244,1,'in',20.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:20:29','2025-12-12 17:53:40','2025-12-12 17:53:40'),(109,223,1,'in',24.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:21:09','2025-12-12 17:53:40','2025-12-12 17:53:40'),(110,243,1,'in',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:21:35','2025-12-12 17:53:40','2025-12-12 17:53:40'),(111,88,1,'in',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:22:03','2025-12-12 17:53:40','2025-12-12 17:53:40'),(112,86,1,'in',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:23:01','2025-12-12 17:53:40','2025-12-12 17:53:40'),(113,87,1,'in',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:23:26','2025-12-12 17:53:40','2025-12-12 17:53:40'),(114,87,1,'out',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:39:53','2025-12-12 17:53:40','2025-12-12 17:53:40'),(115,85,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:41:16','2025-12-12 17:53:40','2025-12-12 17:53:40'),(116,86,1,'out',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:41:27','2025-12-12 17:53:40','2025-12-12 17:53:40'),(117,126,1,'in',13.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 11:43:43','2025-12-12 17:53:40','2025-12-12 17:53:40'),(118,29,1,'in',7.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:23:15','2025-12-12 17:53:40','2025-12-12 17:53:40'),(119,66,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:23:47','2025-12-12 17:53:40','2025-12-12 17:53:40'),(120,30,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:26:53','2025-12-12 17:53:40','2025-12-12 17:53:40'),(121,266,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:28:36','2025-12-12 17:53:40','2025-12-12 17:53:40'),(122,25,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:28:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(123,25,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:28:58','2025-12-12 17:53:40','2025-12-12 17:53:40'),(124,121,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:29:23','2025-12-12 17:53:40','2025-12-12 17:53:40'),(125,78,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:29:43','2025-12-12 17:53:40','2025-12-12 17:53:40'),(126,264,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:30:40','2025-12-12 17:53:40','2025-12-12 17:53:40'),(127,28,1,'in',7.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:31:30','2025-12-12 17:53:40','2025-12-12 17:53:40'),(128,211,1,'in',22.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:31:44','2025-12-12 17:53:40','2025-12-12 17:53:40'),(129,123,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:32:11','2025-12-12 17:53:40','2025-12-12 17:53:40'),(130,274,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:32:58','2025-12-12 17:53:40','2025-12-12 17:53:40'),(131,198,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:34:28','2025-12-12 17:53:40','2025-12-12 17:53:40'),(132,14,1,'in',20.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:35:14','2025-12-12 17:53:40','2025-12-12 17:53:40'),(133,63,1,'in',11.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:35:38','2025-12-12 17:53:40','2025-12-12 17:53:40'),(134,147,1,'in',24.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:36:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(135,80,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:37:10','2025-12-12 17:53:40','2025-12-12 17:53:40'),(136,55,1,'in',6.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:37:26','2025-12-12 17:53:40','2025-12-12 17:53:40'),(137,56,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:37:45','2025-12-12 17:53:40','2025-12-12 17:53:40'),(138,57,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:38:09','2025-12-12 17:53:40','2025-12-12 17:53:40'),(139,275,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:39:06','2025-12-12 17:53:40','2025-12-12 17:53:40'),(140,252,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:39:30','2025-12-12 17:53:40','2025-12-12 17:53:40'),(141,155,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-15 13:39:44','2025-12-12 17:53:40','2025-12-12 17:53:40'),(142,8,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 09:57:28','2025-12-12 17:53:40','2025-12-12 17:53:40'),(143,252,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 10:53:08','2025-12-12 17:53:40','2025-12-12 17:53:40'),(144,198,1,'in',9.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 10:53:47','2025-12-12 17:53:40','2025-12-12 17:53:40'),(145,61,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 10:54:26','2025-12-12 17:53:40','2025-12-12 17:53:40'),(146,73,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 10:55:05','2025-12-12 17:53:40','2025-12-12 17:53:40'),(147,110,1,'in',8.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 10:55:44','2025-12-12 17:53:40','2025-12-12 17:53:40'),(148,77,1,'in',29.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 10:56:13','2025-12-12 17:53:40','2025-12-12 17:53:40'),(149,74,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 10:57:03','2025-12-12 17:53:40','2025-12-12 17:53:40'),(150,76,1,'in',29.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 11:32:07','2025-12-12 17:53:40','2025-12-12 17:53:40'),(151,202,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 11:32:30','2025-12-12 17:53:40','2025-12-12 17:53:40'),(152,140,1,'in',10.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 11:33:56','2025-12-12 17:53:40','2025-12-12 17:53:40'),(153,182,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-16 12:17:13','2025-12-12 17:53:40','2025-12-12 17:53:40'),(154,33,1,'in',35.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 11:49:58','2025-12-12 17:53:40','2025-12-12 17:53:40'),(155,165,1,'in',24.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:26:13','2025-12-12 17:53:40','2025-12-12 17:53:40'),(156,106,1,'in',100.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:29:54','2025-12-12 17:53:40','2025-12-12 17:53:40'),(157,102,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:30:46','2025-12-12 17:53:40','2025-12-12 17:53:40'),(158,108,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:32:00','2025-12-12 17:53:40','2025-12-12 17:53:40'),(159,107,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:32:42','2025-12-12 17:53:40','2025-12-12 17:53:40'),(160,129,1,'in',500.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:33:22','2025-12-12 17:53:40','2025-12-12 17:53:40'),(161,119,1,'in',100.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:35:24','2025-12-12 17:53:40','2025-12-12 17:53:40'),(162,258,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:36:25','2025-12-12 17:53:40','2025-12-12 17:53:40'),(163,263,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:36:44','2025-12-12 17:53:40','2025-12-12 17:53:40'),(164,212,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:41:29','2025-12-12 17:53:40','2025-12-12 17:53:40'),(165,146,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:52:19','2025-12-12 17:53:40','2025-12-12 17:53:40'),(166,257,1,'in',13.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-19 12:52:26','2025-12-12 17:53:40','2025-12-12 17:53:40'),(167,214,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 06:02:45','2025-12-12 17:53:40','2025-12-12 17:53:40'),(168,99,1,'in',11.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 06:37:56','2025-12-12 17:53:40','2025-12-12 17:53:40'),(170,13,1,'out',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 06:38:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(171,248,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 07:13:02','2025-12-12 17:53:40','2025-12-12 17:53:40'),(172,31,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 07:27:44','2025-12-12 17:53:40','2025-12-12 17:53:40'),(173,50,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 07:28:16','2025-12-12 17:53:40','2025-12-12 17:53:40'),(174,51,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 07:50:05','2025-12-12 17:53:40','2025-12-12 17:53:40'),(175,2,1,'in',80.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 10:08:41','2025-12-12 17:53:40','2025-12-12 17:53:40'),(177,38,1,'in',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 11:50:15','2025-12-12 17:53:40','2025-12-12 17:53:40'),(178,163,1,'in',6.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 11:57:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(179,163,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-20 11:57:57','2025-12-12 17:53:40','2025-12-12 17:53:40'),(180,247,1,'out',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:32:59','2025-12-12 17:53:40','2025-12-12 17:53:40'),(181,10,1,'out',15.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:33:17','2025-12-12 17:53:40','2025-12-12 17:53:40'),(182,192,1,'out',60.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:33:49','2025-12-12 17:53:40','2025-12-12 17:53:40'),(183,168,1,'out',50.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:34:21','2025-12-12 17:53:40','2025-12-12 17:53:40'),(184,101,1,'out',250.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:34:39','2025-12-12 17:53:40','2025-12-12 17:53:40'),(185,242,1,'out',17.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:35:31','2025-12-12 17:53:40','2025-12-12 17:53:40'),(187,189,1,'in',150.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:37:10','2025-12-12 17:53:40','2025-12-12 17:53:40'),(189,224,1,'out',70.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:38:37','2025-12-12 17:53:40','2025-12-12 17:53:40'),(190,226,1,'out',475.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:38:47','2025-12-12 17:53:40','2025-12-12 17:53:40'),(191,229,1,'in',150.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 07:40:22','2025-12-12 17:53:40','2025-12-12 17:53:40'),(192,257,1,'in',29.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 09:51:13','2025-12-12 17:53:40','2025-12-12 17:53:40'),(193,39,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 09:54:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(194,211,1,'out',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 10:28:57','2025-12-12 17:53:40','2025-12-12 17:53:40'),(195,147,1,'out',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-22 10:29:12','2025-12-12 17:53:40','2025-12-12 17:53:40'),(196,273,1,'in',18.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-23 10:03:17','2025-12-12 17:53:40','2025-12-12 17:53:40'),(197,6,1,'in',6.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-23 10:03:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(198,257,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-23 10:14:38','2025-12-12 17:53:40','2025-12-12 17:53:40'),(199,212,1,'out',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-23 10:15:03','2025-12-12 17:53:40','2025-12-12 17:53:40'),(200,8,1,'in',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-23 11:55:47','2025-12-12 17:53:40','2025-12-12 17:53:40'),(201,4,1,'out',545.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:42:24','2025-12-12 17:53:40','2025-12-12 17:53:40'),(202,5,1,'out',200.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:42:53','2025-12-12 17:53:40','2025-12-12 17:53:40'),(203,7,1,'in',300.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:44:07','2025-12-12 17:53:40','2025-12-12 17:53:40'),(204,7,1,'out',250.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:44:36','2025-12-12 17:53:40','2025-12-12 17:53:40'),(205,8,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:44:44','2025-12-12 17:53:40','2025-12-12 17:53:40'),(206,10,1,'out',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:44:54','2025-12-12 17:53:40','2025-12-12 17:53:40'),(207,22,1,'in',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:45:18','2025-12-12 17:53:40','2025-12-12 17:53:40'),(208,90,1,'out',17.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:45:42','2025-12-12 17:53:40','2025-12-12 17:53:40'),(209,89,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:45:55','2025-12-12 17:53:40','2025-12-12 17:53:40'),(210,97,1,'out',795.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:46:58','2025-12-12 17:53:40','2025-12-12 17:53:40'),(211,101,1,'out',125.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:47:50','2025-12-12 17:53:40','2025-12-12 17:53:40'),(212,128,1,'in',14.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:48:38','2025-12-12 17:53:40','2025-12-12 17:53:40'),(213,130,1,'out',700.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:49:05','2025-12-12 17:53:40','2025-12-12 17:53:40'),(214,130,1,'in',150.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:49:28','2025-12-12 17:53:40','2025-12-12 17:53:40'),(216,189,1,'out',100.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:50:46','2025-12-12 17:53:40','2025-12-12 17:53:40'),(217,194,1,'out',692.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:51:34','2025-12-12 17:53:40','2025-12-12 17:53:40'),(218,227,1,'in',100.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:52:00','2025-12-12 17:53:40','2025-12-12 17:53:40'),(219,242,1,'out',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:52:26','2025-12-12 17:53:40','2025-12-12 17:53:40'),(220,272,1,'in',400.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-24 07:53:00','2025-12-12 17:53:40','2025-12-12 17:53:40'),(221,242,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-25 08:04:19','2025-12-12 17:53:40','2025-12-12 17:53:40'),(222,242,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-25 08:05:19','2025-12-12 17:53:40','2025-12-12 17:53:40'),(223,163,1,'out',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-25 08:05:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(224,163,1,'out',1.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-25 08:06:00','2025-12-12 17:53:40','2025-12-12 17:53:40'),(225,211,1,'out',10.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-26 05:35:33','2025-12-12 17:53:40','2025-12-12 17:53:40'),(226,37,1,'out',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-26 12:05:14','2025-12-12 17:53:40','2025-12-12 17:53:40'),(227,58,1,'in',20.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-26 12:06:01','2025-12-12 17:53:40','2025-12-12 17:53:40'),(228,147,1,'out',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-26 13:09:51','2025-12-12 17:53:40','2025-12-12 17:53:40'),(229,186,1,'in',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 08:21:56','2025-12-12 17:53:40','2025-12-12 17:53:40'),(230,101,1,'in',315.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:34:07','2025-12-12 17:53:40','2025-12-12 17:53:40'),(231,168,1,'in',250.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:35:20','2025-12-12 17:53:40','2025-12-12 17:53:40'),(232,7,1,'out',50.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:36:14','2025-12-12 17:53:40','2025-12-12 17:53:40'),(233,104,1,'out',200.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:37:57','2025-12-12 17:53:40','2025-12-12 17:53:40'),(234,133,1,'out',50.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:38:29','2025-12-12 17:53:40','2025-12-12 17:53:40'),(235,133,1,'out',50.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:38:56','2025-12-12 17:53:40','2025-12-12 17:53:40'),(236,129,1,'in',150.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:39:31','2025-12-12 17:53:40','2025-12-12 17:53:40'),(237,230,1,'out',943.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:40:44','2025-12-12 17:53:40','2025-12-12 17:53:40'),(238,230,1,'in',443.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:41:14','2025-12-12 17:53:40','2025-12-12 17:53:40'),(239,194,1,'out',188.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:41:34','2025-12-12 17:53:40','2025-12-12 17:53:40'),(240,4,1,'out',100.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:42:04','2025-12-12 17:53:40','2025-12-12 17:53:40'),(241,130,1,'out',100.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:42:27','2025-12-12 17:53:40','2025-12-12 17:53:40'),(242,227,1,'out',250.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:43:13','2025-12-12 17:53:40','2025-12-12 17:53:40'),(243,89,1,'out',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:44:04','2025-12-12 17:53:40','2025-12-12 17:53:40'),(245,163,1,'out',7.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:58:17','2025-12-12 17:53:40','2025-12-12 17:53:40'),(246,188,1,'out',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:58:52','2025-12-12 17:53:40','2025-12-12 17:53:40'),(247,5,1,'out',50.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:59:12','2025-12-12 17:53:40','2025-12-12 17:53:40'),(248,97,1,'out',350.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 09:59:58','2025-12-12 17:53:40','2025-12-12 17:53:40'),(250,189,1,'out',100.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 10:04:19','2025-12-12 17:53:40','2025-12-12 17:53:40'),(251,276,1,'in',22.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 10:05:50','2025-12-12 17:53:40','2025-12-12 17:53:40'),(252,152,1,'in',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 10:38:38','2025-12-12 17:53:40','2025-12-12 17:53:40'),(253,201,1,'in',12.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-29 10:45:22','2025-12-12 17:53:40','2025-12-12 17:53:40'),(254,224,1,'in',400.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-30 10:10:34','2025-12-12 17:53:40','2025-12-12 17:53:40'),(255,242,1,'in',11.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-30 10:11:34','2025-12-12 17:53:40','2025-12-12 17:53:40'),(256,189,1,'in',300.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-30 10:12:05','2025-12-12 17:53:40','2025-12-12 17:53:40'),(257,102,1,'in',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-11-30 11:58:24','2025-12-12 17:53:40','2025-12-12 17:53:40'),(258,264,1,'out',2.000,NULL,NULL,'manual',NULL,1,NULL,'2025-12-02 06:05:47','2025-12-12 17:53:40','2025-12-12 17:53:40'),(259,39,1,'out',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-12-02 07:42:40','2025-12-12 17:53:40','2025-12-12 17:53:40'),(260,37,1,'out',3.000,NULL,NULL,'manual',NULL,1,NULL,'2025-12-02 08:37:01','2025-12-12 17:53:40','2025-12-12 17:53:40'),(261,89,1,'out',5.000,NULL,NULL,'manual',NULL,1,NULL,'2025-12-04 06:22:09','2025-12-12 17:53:40','2025-12-12 17:53:40'),(262,7,1,'out',50.000,NULL,NULL,'manual',NULL,1,NULL,'2025-12-04 06:27:47','2025-12-12 17:53:40','2025-12-12 17:53:40'),(263,8,1,'out',4.000,NULL,NULL,'manual',NULL,1,NULL,'2025-12-04 06:27:57','2025-12-12 17:53:40','2025-12-12 17:53:40'),(264,97,1,'out',700.000,NULL,NULL,'manual',NULL,1,NULL,'2025-12-04 06:28:56','2025-12-12 17:53:40','2025-12-12 17:53:40'),(265,4,1,'in',1.000,NULL,NULL,'purchase_order',1,1,'','2025-12-07 19:30:45','2025-12-12 17:53:40','2025-12-12 17:53:40'),(266,218,1,'adjustment',100.000,NULL,NULL,'manual',NULL,1,NULL,'2025-12-12 16:55:04','2025-12-12 16:55:04','2025-12-12 16:55:04'),(267,239,1,'in',10.000,NULL,NULL,'purchase_order',3,1,'PO PO-1234568','2025-12-12 20:04:20','2025-12-12 20:04:20','2025-12-12 20:04:20'),(268,241,1,'in',10.000,NULL,NULL,'purchase_order',3,1,'PO PO-1234568','2025-12-12 20:04:20','2025-12-12 20:04:20','2025-12-12 20:04:20'),(269,242,1,'in',6.000,NULL,NULL,'purchase_order',4,1,'PO PO-1234569','2025-12-12 21:06:07','2025-12-12 21:06:07','2025-12-12 21:06:07'),(270,161,1,'in',10.000,NULL,NULL,'purchase_order',5,1,'PO PO-1234570','2025-12-13 08:51:55','2025-12-13 08:51:55','2025-12-13 08:51:55'),(271,218,2,'adjustment',10.000,79.2000,792.0000,'manual',NULL,1,NULL,'2026-01-28 21:26:49','2026-01-28 21:26:49','2026-01-28 21:26:49'),(272,218,1,'adjustment',10.000,79.2000,792.0000,'manual',NULL,1,NULL,'2026-01-28 21:26:59','2026-01-28 21:26:59','2026-01-28 21:26:59'),(273,218,1,'out',10.000,79.2000,-792.0000,'transfer',1,1,'Transfer to branch 2','2026-01-28 21:28:22','2026-01-28 21:28:22','2026-01-28 21:28:22'),(274,218,2,'in',10.000,79.2000,792.0000,'transfer',1,1,'Transfer from branch 1','2026-01-28 21:28:22','2026-01-28 21:28:22','2026-01-28 21:28:22'),(275,241,1,'out',1.000,40.0000,-40.0000,'transfer',4,1,'Transfer to branch 2','2026-01-28 21:56:51','2026-01-28 21:56:51','2026-01-28 21:56:51'),(276,241,2,'in',1.000,40.0000,40.0000,'transfer',4,1,'Transfer from branch 1','2026-01-28 21:56:51','2026-01-28 21:56:51','2026-01-28 21:56:51'),(277,242,1,'out',1.000,30.0000,-30.0000,'transfer',4,1,'Transfer to branch 2','2026-01-28 21:56:51','2026-01-28 21:56:51','2026-01-28 21:56:51'),(278,242,2,'in',1.000,30.0000,30.0000,'transfer',4,1,'Transfer from branch 1','2026-01-28 21:56:51','2026-01-28 21:56:51','2026-01-28 21:56:51'),(279,242,1,'out',1.000,30.0000,-30.0000,'transfer',5,1,'Transfer to branch 2','2026-01-28 21:59:59','2026-01-28 21:59:59','2026-01-28 21:59:59'),(280,242,2,'in',1.000,30.0000,30.0000,'transfer',5,1,'Transfer from branch 1','2026-01-28 21:59:59','2026-01-28 21:59:59','2026-01-28 21:59:59'),(281,123,1,'out',2.000,5.9500,-11.9000,'transfer',7,1,'Transfer to branch 2','2026-01-28 22:02:52','2026-01-28 22:02:52','2026-01-28 22:02:52'),(282,123,2,'in',2.000,5.9500,11.9000,'transfer',7,1,'Transfer from branch 1','2026-01-28 22:02:52','2026-01-28 22:02:52','2026-01-28 22:02:52'),(283,277,1,'adjustment',2.000,100.0000,200.0000,'manual',NULL,1,'Initial stock','2026-01-29 06:37:47','2026-01-29 06:37:47','2026-01-29 06:37:47'),(284,277,1,'out',0.083,100.0000,-8.3000,'recipe',1,1,'Recipe test production #1','2026-01-29 06:47:44','2026-01-29 06:47:44','2026-01-29 06:47:44');
/*!40000 ALTER TABLE `inventory_transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_transfer_lines`
--

DROP TABLE IF EXISTS `inventory_transfer_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_transfer_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_id` bigint(20) unsigned NOT NULL,
  `inventory_item_id` int(11) NOT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_cost_snapshot` decimal(12,4) DEFAULT NULL,
  `total_cost` decimal(12,4) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_transfer_lines_transfer_index` (`transfer_id`),
  KEY `inventory_transfer_lines_item_index` (`inventory_item_id`),
  CONSTRAINT `inventory_transfer_lines_item_fk` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `inventory_transfer_lines_transfer_fk` FOREIGN KEY (`transfer_id`) REFERENCES `inventory_transfers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_transfer_lines`
--

LOCK TABLES `inventory_transfer_lines` WRITE;
/*!40000 ALTER TABLE `inventory_transfer_lines` DISABLE KEYS */;
INSERT INTO `inventory_transfer_lines` VALUES (1,1,218,10.000,79.2000,792.0000,'2026-01-28 21:28:22','2026-01-28 21:28:22'),(2,4,241,1.000,40.0000,40.0000,'2026-01-28 21:56:51','2026-01-28 21:56:51'),(3,4,242,1.000,30.0000,30.0000,'2026-01-28 21:56:51','2026-01-28 21:56:51'),(4,5,242,1.000,30.0000,30.0000,'2026-01-28 21:59:59','2026-01-28 21:59:59'),(5,7,123,2.000,5.9500,11.9000,'2026-01-28 22:02:52','2026-01-28 22:02:52');
/*!40000 ALTER TABLE `inventory_transfer_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_transfers`
--

DROP TABLE IF EXISTS `inventory_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_branch_id` int(11) NOT NULL,
  `to_branch_id` int(11) NOT NULL,
  `transfer_date` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_transfers_from_branch_index` (`from_branch_id`),
  KEY `inventory_transfers_to_branch_index` (`to_branch_id`),
  KEY `inventory_transfers_status_index` (`status`),
  KEY `inventory_transfers_date_index` (`transfer_date`),
  KEY `inventory_transfers_created_by_fk` (`created_by`),
  KEY `inventory_transfers_posted_by_fk` (`posted_by`),
  CONSTRAINT `inventory_transfers_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `inventory_transfers_from_branch_fk` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `inventory_transfers_posted_by_fk` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `inventory_transfers_to_branch_fk` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_transfers`
--

LOCK TABLES `inventory_transfers` WRITE;
/*!40000 ALTER TABLE `inventory_transfers` DISABLE KEYS */;
INSERT INTO `inventory_transfers` VALUES (1,1,2,'2026-01-28','posted',NULL,1,1,'2026-01-28 21:28:22','2026-01-28 21:28:22','2026-01-28 21:28:22'),(4,1,2,'2026-01-28','posted',NULL,1,1,'2026-01-28 21:56:51','2026-01-28 21:56:51','2026-01-28 21:56:51'),(5,1,2,'2026-01-28','posted',NULL,1,1,'2026-01-28 21:59:59','2026-01-28 21:59:59','2026-01-28 21:59:59'),(7,1,2,'2026-01-28','posted',NULL,1,1,'2026-01-28 22:02:52','2026-01-28 22:02:52','2026-01-28 22:02:52');
/*!40000 ALTER TABLE `inventory_transfers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `job_batches`
--

DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `job_batches`
--

LOCK TABLES `job_batches` WRITE;
/*!40000 ALTER TABLE `job_batches` DISABLE KEYS */;
/*!40000 ALTER TABLE `job_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `jobs`
--

DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `jobs`
--

LOCK TABLES `jobs` WRITE;
/*!40000 ALTER TABLE `jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ledger_accounts`
--

DROP TABLE IF EXISTS `ledger_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ledger_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ledger_accounts_code_unique` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ledger_accounts`
--

LOCK TABLES `ledger_accounts` WRITE;
/*!40000 ALTER TABLE `ledger_accounts` DISABLE KEYS */;
INSERT INTO `ledger_accounts` VALUES (1,'1000','Cash','asset',1,'2026-01-28 06:27:10','2026-01-28 06:27:10'),(2,'1200','Inventory','asset',1,'2026-01-28 06:27:10','2026-01-28 06:27:10'),(3,'1300','Supplier Advances','asset',1,'2026-01-28 06:27:10','2026-01-28 06:27:10'),(4,'1400','Input Tax','asset',1,'2026-01-28 06:27:10','2026-01-28 06:27:10'),(5,'2000','Accounts Payable','liability',1,'2026-01-28 06:27:10','2026-01-28 06:27:10'),(6,'2100','GRNI Clearing','liability',1,'2026-01-28 06:27:10','2026-01-28 06:27:10'),(7,'5000','COGS','expense',1,'2026-01-28 06:27:10','2026-01-28 06:27:10'),(8,'5100','Inventory Adjustments','expense',1,'2026-01-28 06:27:10','2026-01-28 06:27:10'),(9,'6000','General Expense','expense',1,'2026-01-28 06:27:10','2026-01-28 06:27:10'),(10,'1100','Petty Cash','asset',1,'2026-01-29 15:02:51','2026-01-29 15:02:51'),(11,'2200','Customer Advances','liability',1,'2026-02-01 20:49:29','2026-02-01 20:49:29'),(12,'1500','Accounts Receivable','asset',1,'2026-02-01 20:51:28','2026-02-01 20:51:28'),(13,'4000','Sales Revenue','income',1,'2026-02-01 20:51:28','2026-02-01 20:51:28');
/*!40000 ALTER TABLE `ledger_accounts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `maintenance_schedules`
--

DROP TABLE IF EXISTS `maintenance_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `asset_id` int(11) NOT NULL,
  `schedule_type` enum('daily','weekly','monthly','quarterly','yearly','custom') DEFAULT NULL,
  `frequency_value` int(11) DEFAULT NULL,
  `frequency_unit` enum('days','weeks','months') DEFAULT NULL,
  `last_maintenance` date DEFAULT NULL,
  `next_maintenance` date DEFAULT NULL,
  `assigned_technician` varchar(100) DEFAULT NULL,
  `status` enum('active','paused') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_schedules`
--

LOCK TABLES `maintenance_schedules` WRITE;
/*!40000 ALTER TABLE `maintenance_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `maintenance_schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `meal_plan_request_orders`
--

DROP TABLE IF EXISTS `meal_plan_request_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_plan_request_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `meal_plan_request_id` bigint(20) unsigned NOT NULL,
  `order_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mpr_orders_unique` (`meal_plan_request_id`,`order_id`),
  KEY `mpr_orders_order_id_index` (`order_id`),
  CONSTRAINT `mpr_orders_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `mpr_orders_request_fk` FOREIGN KEY (`meal_plan_request_id`) REFERENCES `meal_plan_requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `meal_plan_request_orders`
--

LOCK TABLES `meal_plan_request_orders` WRITE;
/*!40000 ALTER TABLE `meal_plan_request_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_plan_request_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `meal_plan_requests`
--

DROP TABLE IF EXISTS `meal_plan_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_plan_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_phone` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_address` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plan_meals` smallint(5) unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `meal_plan_requests`
--

LOCK TABLES `meal_plan_requests` WRITE;
/*!40000 ALTER TABLE `meal_plan_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_plan_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `meal_subscription_days`
--

DROP TABLE IF EXISTS `meal_subscription_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_subscription_days` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint(20) unsigned NOT NULL,
  `weekday` tinyint(4) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `meal_subscription_days_unique` (`subscription_id`,`weekday`),
  KEY `meal_subscription_days_sub_id_idx` (`subscription_id`),
  CONSTRAINT `meal_subscription_days_sub_fk` FOREIGN KEY (`subscription_id`) REFERENCES `meal_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `meal_subscription_days`
--

LOCK TABLES `meal_subscription_days` WRITE;
/*!40000 ALTER TABLE `meal_subscription_days` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_subscription_days` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `meal_subscription_orders`
--

DROP TABLE IF EXISTS `meal_subscription_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_subscription_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint(20) unsigned NOT NULL,
  `order_id` int(11) NOT NULL,
  `service_date` date NOT NULL,
  `branch_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `meal_sub_orders_subscription_order_unique` (`subscription_id`,`order_id`),
  KEY `meal_sub_orders_order_id_idx` (`order_id`),
  KEY `meal_sub_orders_service_date_idx` (`service_date`),
  KEY `meal_sub_orders_branch_fk` (`branch_id`),
  CONSTRAINT `meal_sub_orders_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `meal_sub_orders_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meal_sub_orders_sub_fk` FOREIGN KEY (`subscription_id`) REFERENCES `meal_subscriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meal_sub_orders_subscription_fk` FOREIGN KEY (`subscription_id`) REFERENCES `meal_subscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `meal_subscription_orders_branch_id_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `meal_subscription_orders`
--

LOCK TABLES `meal_subscription_orders` WRITE;
/*!40000 ALTER TABLE `meal_subscription_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_subscription_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `meal_subscription_pauses`
--

DROP TABLE IF EXISTS `meal_subscription_pauses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_subscription_pauses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_id` bigint(20) unsigned NOT NULL,
  `pause_start` date NOT NULL,
  `pause_end` date NOT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `meal_subscription_pauses_sub_id_idx` (`subscription_id`),
  KEY `meal_sub_pauses_created_by_fk` (`created_by`),
  CONSTRAINT `meal_sub_pauses_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `meal_subscription_pauses_sub_fk` FOREIGN KEY (`subscription_id`) REFERENCES `meal_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `meal_subscription_pauses`
--

LOCK TABLES `meal_subscription_pauses` WRITE;
/*!40000 ALTER TABLE `meal_subscription_pauses` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_subscription_pauses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `meal_subscriptions`
--

DROP TABLE IF EXISTS `meal_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meal_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `status` enum('active','paused','cancelled','expired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `plan_meals_total` int(11) DEFAULT NULL,
  `meals_used` int(11) NOT NULL DEFAULT 0,
  `meal_plan_request_id` bigint(20) unsigned DEFAULT NULL,
  `default_order_type` enum('Delivery','Takeaway') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Delivery',
  `delivery_time` time DEFAULT NULL,
  `address_snapshot` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_snapshot` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preferred_role` enum('main','diet','vegetarian') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'main',
  `include_salad` tinyint(1) NOT NULL DEFAULT 1,
  `include_dessert` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `meal_subscriptions_subscription_code_unique` (`subscription_code`),
  KEY `meal_subscriptions_customer_id_foreign` (`customer_id`),
  KEY `meal_subscriptions_mpr_id_idx` (`meal_plan_request_id`),
  KEY `meal_subscriptions_created_by_fk` (`created_by`),
  KEY `meal_subscriptions_branch_fk` (`branch_id`),
  CONSTRAINT `meal_subscriptions_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `meal_subscriptions_branch_id_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `meal_subscriptions_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `meal_subscriptions_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `meal_subscriptions_mpr_fk` FOREIGN KEY (`meal_plan_request_id`) REFERENCES `meal_plan_requests` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `meal_subscriptions`
--

LOCK TABLES `meal_subscriptions` WRITE;
/*!40000 ALTER TABLE `meal_subscriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `meal_subscriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `menu_item_branches`
--

DROP TABLE IF EXISTS `menu_item_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `menu_item_branches` (
  `menu_item_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`menu_item_id`,`branch_id`),
  KEY `menu_item_branches_branch_index` (`branch_id`),
  CONSTRAINT `menu_item_branches_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `menu_item_branches_item_fk` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_item_branches`
--

LOCK TABLES `menu_item_branches` WRITE;
/*!40000 ALTER TABLE `menu_item_branches` DISABLE KEYS */;
INSERT INTO `menu_item_branches` VALUES (1,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(2,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(3,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(4,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(5,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(6,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(7,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(8,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(9,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(10,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(11,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(12,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(12,2,'2026-01-28 22:37:32','2026-01-28 22:37:32'),(13,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(14,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(15,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(15,2,'2026-01-28 22:37:31','2026-01-28 22:37:31'),(16,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(17,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(18,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(19,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(20,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(21,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(21,2,'2026-01-28 22:37:29','2026-01-28 22:37:29'),(22,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(23,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(24,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(25,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(26,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(27,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(28,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(29,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(30,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(30,2,'2026-01-28 22:37:38','2026-01-28 22:37:38'),(31,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(32,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(33,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(34,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(35,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(36,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(37,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(38,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(39,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(40,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(41,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(42,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(43,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(44,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(45,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(46,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(47,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(48,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(49,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(50,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(51,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(52,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(53,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(54,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(55,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(56,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(57,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(58,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(59,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(60,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(61,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(62,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(63,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(64,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(65,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(66,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(67,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(68,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(69,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(70,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(71,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(72,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(73,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(73,2,'2026-01-28 22:37:07','2026-01-28 22:37:07'),(74,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(74,2,'2026-01-28 22:37:27','2026-01-28 22:37:27'),(75,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(76,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(77,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(78,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(79,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(80,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(81,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(82,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(83,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(84,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(85,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(86,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(87,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(88,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(89,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(90,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(91,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(92,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(93,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(94,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(95,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(96,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(97,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(98,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(99,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(100,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(101,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(102,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(103,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(104,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(105,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(106,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(107,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(108,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(109,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(110,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(111,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(112,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(112,2,'2026-01-28 22:37:05','2026-01-28 22:37:05'),(113,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(114,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(115,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(116,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(117,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(118,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(119,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(120,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(121,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(122,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(123,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(124,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(125,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(126,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(127,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(128,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(129,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(130,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(131,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(132,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(133,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(134,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(135,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(136,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(137,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(138,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(139,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(140,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(141,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(142,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(143,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(144,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(145,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(146,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(147,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(148,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(149,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(150,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(151,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(152,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(153,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(154,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(155,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(156,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(157,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(158,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(159,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(160,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(161,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(162,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(163,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(164,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(165,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(166,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(167,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(168,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(169,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(170,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(171,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(172,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(173,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(174,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(175,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(176,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(177,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(178,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(179,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(180,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(181,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(181,2,'2026-01-28 22:43:57','2026-01-28 22:43:57'),(182,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(183,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(184,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(185,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(186,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(187,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(188,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(189,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(190,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(191,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(192,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(193,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(194,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(195,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(196,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(197,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(198,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(199,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(200,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(201,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(202,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(203,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(204,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(205,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(206,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(207,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(208,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(209,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(210,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(211,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(212,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(213,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(214,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(215,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(216,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(217,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(218,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(219,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(220,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(221,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(222,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(223,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(224,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(225,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(226,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(227,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(228,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(229,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(230,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(231,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(232,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(233,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(234,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(235,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(236,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(237,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(238,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(239,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(240,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(241,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(242,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(243,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(244,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(245,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(246,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(247,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(248,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(249,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(250,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(251,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(252,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(253,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(254,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(255,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(256,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(257,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(258,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(259,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(260,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(261,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(262,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(263,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(264,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(265,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(266,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(267,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(268,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(269,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(270,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(271,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(272,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(273,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(274,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(275,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(276,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(277,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(278,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(279,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(280,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(281,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(282,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(283,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(284,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(285,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(286,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(287,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(288,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(289,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(290,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(291,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(292,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(293,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(294,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(295,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(296,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(297,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(298,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(299,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(300,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(301,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(302,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(303,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(304,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(305,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(306,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(307,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(308,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(309,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(310,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(311,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(312,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(313,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(314,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(315,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(316,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(317,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(318,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(319,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(320,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(321,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(322,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(323,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(324,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(325,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(326,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(327,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(328,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(329,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(330,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(331,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(332,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(333,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(334,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(335,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(336,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(337,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(338,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(339,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(340,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(341,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(342,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(343,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(344,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(345,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(346,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(347,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(348,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(349,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(350,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(351,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(352,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(353,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(354,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(354,2,'2026-01-28 22:37:11','2026-01-28 22:37:11'),(355,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(355,2,'2026-01-28 22:37:12','2026-01-28 22:37:12'),(356,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(357,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(358,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(359,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(360,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(361,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(362,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(363,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(364,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(365,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(366,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(367,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(368,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(369,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(370,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(371,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(372,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(373,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(374,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(375,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(376,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(377,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(377,2,'2026-01-28 22:37:30','2026-01-28 22:37:30'),(378,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(379,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(380,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(381,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(382,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(383,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(384,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(385,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(386,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(387,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(388,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(389,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(390,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(391,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(392,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(393,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(394,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(395,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(396,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(397,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(398,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(399,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(400,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(401,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(402,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(403,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(404,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(405,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(406,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(407,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(408,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(409,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(410,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(411,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(412,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(413,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(414,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(415,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(416,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(417,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(418,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(419,1,'2026-01-28 22:36:56','2026-01-28 22:36:56'),(420,1,'2026-01-28 22:36:56','2026-01-28 22:36:56');
/*!40000 ALTER TABLE `menu_item_branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `menu_items`
--

DROP TABLE IF EXISTS `menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `menu_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `arabic_name` varchar(255) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `recipe_id` int(11) DEFAULT NULL,
  `selling_price_per_unit` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit` varchar(20) NOT NULL DEFAULT 'each',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_menu_items_code` (`code`),
  KEY `idx_menu_items_name` (`name`),
  KEY `idx_menu_items_category` (`category_id`),
  KEY `idx_menu_items_recipe` (`recipe_id`),
  KEY `idx_menu_items_is_active` (`is_active`),
  KEY `menu_items_display_order_index` (`display_order`),
  KEY `menu_items_active_order_index` (`is_active`,`display_order`),
  KEY `menu_items_status_index` (`status`),
  KEY `menu_items_code_index` (`code`),
  KEY `menu_items_name_index` (`name`),
  KEY `menu_items_arabic_name_index` (`arabic_name`),
  CONSTRAINT `menu_items_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `menu_items_recipe_id_foreign` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=421 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_items`
--

LOCK TABLES `menu_items` WRITE;
/*!40000 ALTER TABLE `menu_items` DISABLE KEYS */;
INSERT INTO `menu_items` VALUES (1,'2','Mini Zaatar',NULL,NULL,NULL,35.000,'each',0.00,1,2,'active','2025-12-10 22:40:06','2025-12-12 20:50:36'),(2,'3','Cheese Fatayer',NULL,NULL,NULL,40.000,'each',0.00,1,4,'active','2025-12-10 22:40:06','2025-12-12 20:50:28'),(3,'4','Mini Pizza',NULL,NULL,NULL,40.000,'each',0.00,1,1,'active','2025-12-10 22:40:06','2025-12-12 20:50:31'),(4,'5','Mini Kishk',NULL,NULL,NULL,40.000,'each',0.00,1,4,'active','2025-12-10 22:40:06',NULL),(5,'6','Fatayer Bakleh',NULL,NULL,NULL,40.000,'each',0.00,1,5,'active','2025-12-10 22:40:06',NULL),(6,'7','Fatayer Spinach',NULL,NULL,NULL,35.000,'each',0.00,1,6,'active','2025-12-10 22:40:06',NULL),(7,'8','Puff Pastry Hot Dog',NULL,NULL,NULL,60.000,'each',0.00,1,7,'active','2025-12-10 22:40:06',NULL),(8,'9','Sfiha Lahme',NULL,NULL,NULL,40.000,'each',0.00,1,8,'active','2025-12-10 22:40:06',NULL),(9,'10','Mini Falafel',NULL,NULL,NULL,50.000,'each',0.00,1,9,'active','2025-12-10 22:40:06',NULL),(10,'11','Mini Mushroom Quiche',NULL,NULL,NULL,120.000,'each',0.00,1,10,'active','2025-12-10 22:40:06',NULL),(11,'12','Spring Rolls',NULL,NULL,NULL,40.000,'each',0.00,1,11,'active','2025-12-10 22:40:06',NULL),(12,'13','Tuna Sandwich',NULL,NULL,NULL,72.000,'each',0.00,1,12,'active','2025-12-10 22:40:06',NULL),(13,'14','Chicken Sandwich',NULL,NULL,NULL,72.000,'each',0.00,1,13,'active','2025-12-10 22:40:06',NULL),(14,'15','Halloumi Sandwich',NULL,NULL,NULL,72.000,'each',0.00,1,14,'active','2025-12-10 22:40:06',NULL),(15,'16','Turkey Sandwich',NULL,NULL,NULL,72.000,'each',0.00,1,15,'active','2025-12-10 22:40:06',NULL),(16,'17','Boiled Eggs Sandwich',NULL,NULL,NULL,72.000,'each',0.00,1,16,'active','2025-12-10 22:40:06',NULL),(17,'18','Hotdog',NULL,NULL,NULL,35.000,'each',0.00,1,17,'active','2025-12-10 22:40:06',NULL),(18,'19','Mini Burger Beef',NULL,NULL,NULL,72.000,'each',0.00,1,18,'active','2025-12-10 22:40:06',NULL),(19,'20','Mini Burger Chicken',NULL,NULL,NULL,72.000,'each',0.00,1,19,'active','2025-12-10 22:40:06',NULL),(20,'21','Shrimps Spring Rolls ',NULL,NULL,NULL,45.000,'each',0.00,1,20,'active','2025-12-10 22:40:06',NULL),(21,'22','Vegetable Plate',NULL,NULL,NULL,120.000,'each',0.00,1,21,'active','2025-12-10 22:40:06',NULL),(22,'23','Fawarigh',NULL,NULL,NULL,160.000,'each',0.00,1,22,'active','2025-12-10 22:40:06',NULL),(23,'24','croissant Turkey',NULL,NULL,NULL,120.000,'each',0.00,1,23,'active','2025-12-10 22:40:06',NULL),(24,'25','Croissant Zaator',NULL,NULL,NULL,96.000,'each',0.00,1,24,'active','2025-12-10 22:40:06',NULL),(25,'26','Croissant Chocolate',NULL,NULL,NULL,96.000,'each',0.00,1,25,'active','2025-12-10 22:40:06',NULL),(26,'27','Spniach Pie',NULL,NULL,NULL,120.000,'each',0.00,1,26,'active','2025-12-10 22:40:06',NULL),(27,'28','Mushroom Pie',NULL,NULL,NULL,130.000,'each',0.00,1,27,'active','2025-12-10 22:40:06',NULL),(28,'29','Eggs Cheese',NULL,NULL,NULL,120.000,'each',0.00,1,28,'active','2025-12-10 22:40:06',NULL),(29,'30','croissant halloumi',NULL,NULL,NULL,120.000,'each',0.00,1,29,'active','2025-12-10 22:40:06',NULL),(30,'31','Tuna puff pastry',NULL,NULL,NULL,96.000,'each',0.00,1,30,'active','2025-12-10 22:40:06',NULL),(31,'32','Tahini',NULL,NULL,NULL,60.000,'each',0.00,1,31,'active','2025-12-10 22:40:06',NULL),(32,'33','Steak Veg',NULL,NULL,NULL,320.000,'each',0.00,1,32,'active','2025-12-10 22:40:06',NULL),(33,'34','Tacos Plate',NULL,NULL,NULL,180.000,'each',0.00,1,33,'active','2025-12-10 22:40:06',NULL),(34,'35','Pilaf Rice',NULL,NULL,NULL,170.000,'each',0.00,1,34,'active','2025-12-10 22:40:06',NULL),(35,'36','Lazy Cake','كيك كسول',NULL,NULL,100.000,'each',0.00,1,35,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(36,'37','EggPlant',NULL,NULL,NULL,180.000,'each',0.00,1,36,'active','2025-12-10 22:40:06',NULL),(37,'38','Machewi Mix',NULL,NULL,NULL,185.000,'each',0.00,1,37,'active','2025-12-10 22:40:06',NULL),(38,'39','Chicken Potato',NULL,NULL,NULL,150.000,'each',0.00,1,38,'active','2025-12-10 22:40:06',NULL),(39,'40','Sweet potato',NULL,NULL,NULL,120.000,'each',0.00,1,39,'active','2025-12-10 22:40:06',NULL),(40,'41','Bruchetta',NULL,NULL,NULL,30.000,'each',0.00,1,40,'active','2025-12-10 22:40:06',NULL),(41,'42','Mozzarella Sticks ',NULL,NULL,NULL,100.000,'each',0.00,1,41,'active','2025-12-10 22:40:06',NULL),(42,'43','Salmon Pie',NULL,NULL,NULL,180.000,'each',0.00,1,42,'active','2025-12-10 22:40:06',NULL),(43,'44','Shrimp with cocktail sauce',NULL,NULL,NULL,80.000,'each',0.00,1,43,'active','2025-12-10 22:40:06',NULL),(44,'45','Mtabbal',NULL,NULL,NULL,70.000,'each',0.00,1,44,'active','2025-12-10 22:40:06',NULL),(45,'46','Shrimp Avocado Cups',NULL,NULL,NULL,96.000,'each',0.00,1,45,'active','2025-12-10 22:40:06',NULL),(46,'47','Carrot Cucumber Couliflower Plat',NULL,NULL,NULL,50.000,'each',0.00,1,46,'active','2025-12-10 22:40:06',NULL),(47,'48','Hommos Makdous',NULL,NULL,NULL,90.000,'each',0.00,1,47,'active','2025-12-10 22:40:06',NULL),(48,'49','Burghul with Tomato',NULL,NULL,NULL,80.000,'each',0.00,1,48,'active','2025-12-10 22:40:06',NULL),(49,'50','Chicken Noodles',NULL,NULL,NULL,120.000,'each',0.00,1,49,'active','2025-12-10 22:40:06',NULL),(50,'51','Quiche Salmon',NULL,NULL,NULL,180.000,'each',0.00,1,50,'active','2025-12-10 22:40:06',NULL),(51,'52','Chicken Bites Dozen',NULL,NULL,NULL,48.000,'each',0.00,1,51,'active','2025-12-10 22:40:06',NULL),(52,'53','Kebbeh Raw',NULL,NULL,NULL,120.000,'each',0.00,1,52,'active','2025-12-10 22:40:06',NULL),(53,'54','Chicken Strogonoff ',NULL,NULL,NULL,160.000,'each',0.00,1,53,'active','2025-12-10 22:40:06',NULL),(54,'55','Strogonoff Beef',NULL,NULL,NULL,160.000,'each',0.00,1,54,'active','2025-12-10 22:40:06',NULL),(55,'56','Water Bones',NULL,NULL,NULL,270.000,'each',0.00,1,55,'active','2025-12-10 22:40:06',NULL),(56,'57','Kebbeh Bi Laban','كبة بلبن',NULL,NULL,140.000,'each',0.00,1,56,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(57,'58','Shrimp Fajita ',NULL,NULL,NULL,280.000,'each',0.00,1,57,'active','2025-12-10 22:40:06',NULL),(58,'59','Cheese Rolls 18pcs',NULL,NULL,NULL,52.500,'each',0.00,1,58,'active','2025-12-10 22:40:06',NULL),(59,'60','Fatayer Cheese',NULL,NULL,NULL,40.000,'each',0.00,1,59,'active','2025-12-10 22:40:06',NULL),(60,'61','Hindbeh',NULL,NULL,NULL,100.000,'each',0.00,1,60,'active','2025-12-10 22:40:06',NULL),(61,'62','Mehchi Silik',NULL,NULL,NULL,130.000,'each',0.00,1,61,'active','2025-12-10 22:40:06',NULL),(62,'63','Fattet Batenjen',NULL,NULL,NULL,100.000,'each',0.00,1,62,'active','2025-12-10 22:40:06',NULL),(63,'64','Tarator',NULL,NULL,NULL,90.000,'each',0.00,1,63,'active','2025-12-10 22:40:06',NULL),(64,'65','Mdardara with fried Onions',NULL,NULL,NULL,100.000,'each',0.00,1,64,'active','2025-12-10 22:40:06',NULL),(65,'66','Hommos','حمص',NULL,NULL,17.000,'each',0.00,1,65,'active','2025-12-10 22:40:06',NULL),(66,'67','Hommos Layla','حمص ليلى',NULL,NULL,24.000,'each',0.00,1,66,'active','2025-12-10 22:40:06',NULL),(67,'68','Hommos Beiruti','حمص بيروتي',NULL,NULL,22.000,'each',0.00,1,67,'active','2025-12-10 22:40:06',NULL),(68,'69','Hommos Ras Asfour','حمص راس عصفور',NULL,NULL,26.000,'each',0.00,1,68,'active','2025-12-10 22:40:06',NULL),(69,'70','Hommos Shawarma','حمص شاورما',NULL,NULL,26.000,'each',0.00,1,69,'active','2025-12-10 22:40:06',NULL),(70,'71','Hommos Qawarma','حمص بالقورما',NULL,NULL,26.000,'each',0.00,1,70,'active','2025-12-10 22:40:06',NULL),(71,'72','Fish Tajen','طاجن سمك',NULL,NULL,32.000,'each',0.00,1,71,'active','2025-12-10 22:40:06',NULL),(72,'73','Eggplant Moutabal','متبل باذنجان',NULL,NULL,20.000,'each',0.00,1,72,'active','2025-12-10 22:40:06',NULL),(73,'74','Al Raheb Salad','سلطة الراهب',NULL,NULL,22.000,'each',0.00,1,73,'active','2025-12-10 22:40:06',NULL),(74,'75','Vine Leaves ','ورق عنب بالزيت',NULL,NULL,24.000,'each',0.00,1,74,'active','2025-12-10 22:40:06',NULL),(75,'76','Labneh','لبنة',NULL,NULL,18.000,'each',0.00,1,75,'active','2025-12-10 22:40:06',NULL),(76,'77','Labneh with Gralic & Mint','لبنة بالثوم و النعنع',NULL,NULL,20.000,'each',0.00,1,76,'active','2025-12-10 22:40:06',NULL),(77,'78','Beetroots  Moutabal','شمندر متبل',NULL,NULL,22.000,'each',0.00,1,77,'active','2025-12-10 22:40:06',NULL),(78,'79','Eggplant Mosakaa','مسقعة باذنجان',NULL,NULL,22.000,'each',0.00,1,78,'active','2025-12-10 22:40:06',NULL),(79,'80','Moujadara with Fried Onion','مجدرة مصفاية ',NULL,NULL,18.000,'each',0.00,1,79,'active','2025-12-10 22:40:06',NULL),(80,'81','Shanklish','شنكليش',NULL,NULL,24.000,'each',0.00,1,80,'active','2025-12-10 22:40:06',NULL),(81,'82','Loubieh Bl Zeit','لوبية بالزيت',NULL,NULL,22.000,'each',0.00,1,81,'active','2025-12-10 22:40:06',NULL),(82,'83','Daily Dish 1','الطبق اليومي',NULL,NULL,55.000,'each',0.00,1,82,'active','2025-12-10 22:40:06',NULL),(83,'84','Daily Dish 2',NULL,NULL,NULL,50.000,'each',0.00,1,83,'active','2025-12-10 22:40:06',NULL),(84,'85','Daily dish M.S.',NULL,NULL,NULL,40.000,'each',0.00,1,84,'active','2025-12-10 22:40:06',NULL),(85,'86','Aluminum Pot 175 * 100 pcs per ctn Big size',NULL,NULL,NULL,0.100,'each',0.00,1,85,'active','2025-12-10 22:40:06',NULL),(86,'87','Glass Cleaner',NULL,NULL,NULL,0.100,'each',0.00,1,86,'active','2025-12-10 22:40:06',NULL),(87,'90','Daily Dish Full Set','الطبق اليومي',NULL,NULL,65.000,'each',0.00,1,89,'active','2025-12-10 22:40:06',NULL),(88,'91','Iftar Box Small',NULL,NULL,NULL,18.000,'each',0.00,1,90,'active','2025-12-10 22:40:06',NULL),(89,'92','Main Dish 1 Portion',NULL,NULL,NULL,200.000,'each',0.00,1,91,'active','2025-12-10 22:40:06',NULL),(90,'93','Main Dish Half Portion',NULL,NULL,NULL,130.000,'each',0.00,1,92,'active','2025-12-10 22:40:06',NULL),(91,'94','Asian Daily Dish',NULL,NULL,NULL,12.000,'each',0.00,1,93,'active','2025-12-10 22:40:06',NULL),(92,'95','funderdome cake',NULL,NULL,NULL,7996.000,'each',0.00,1,94,'active','2025-12-10 22:40:06',NULL),(93,'96','Daily Record',NULL,NULL,NULL,0.100,'each',0.00,1,95,'active','2025-12-10 22:40:06',NULL),(94,'97','Daily Dish Monthly 26 Days',NULL,NULL,NULL,42.300,'each',0.00,1,96,'active','2025-12-10 22:40:06',NULL),(95,'99','Kameh Kg',NULL,NULL,NULL,100.000,'each',0.00,1,98,'active','2025-12-10 22:40:06',NULL),(96,'100','Kashta 12pcs',NULL,NULL,NULL,50.000,'each',0.00,1,99,'active','2025-12-10 22:40:06',NULL),(97,'101','Kashta 6pcs',NULL,NULL,NULL,25.000,'each',0.00,1,100,'active','2025-12-10 22:40:06',NULL),(98,'102','Jouz 12Pcs',NULL,NULL,NULL,50.000,'each',0.00,1,101,'active','2025-12-10 22:40:06',NULL),(99,'103','Jouz 6Pcs',NULL,NULL,NULL,25.000,'each',0.00,1,102,'active','2025-12-10 22:40:06',NULL),(100,'104','Maacroun Half Portion',NULL,NULL,NULL,60.000,'each',0.00,1,103,'active','2025-12-10 22:40:06',NULL),(101,'105','Ouwaymat Half Portion',NULL,NULL,NULL,50.000,'each',0.00,1,104,'active','2025-12-10 22:40:06',NULL),(102,'106','ouwaymat',NULL,NULL,NULL,100.000,'each',0.00,1,105,'active','2025-12-10 22:40:06',NULL),(103,'107','Fruit Salad',NULL,NULL,NULL,220.000,'each',0.00,1,106,'active','2025-12-10 22:40:06',NULL),(104,'108','Rice Pudding','رز بحليب',NULL,NULL,12.000,'each',0.00,1,107,'active','2025-12-10 22:40:06','2025-12-14 21:48:39'),(105,'109','Mouhallabiyah','مهلبيه',NULL,NULL,12.000,'each',0.00,1,108,'active','2025-12-10 22:40:06',NULL),(106,'110','Meghli with nuts','مغلي بالمكسرات',NULL,NULL,12.000,'each',0.00,1,109,'active','2025-12-10 22:40:06',NULL),(107,'111','Biscuit Au Chocolate','بسكوت بالشوكولاته',NULL,NULL,15.000,'each',0.00,1,110,'active','2025-12-10 22:40:06',NULL),(108,'112','Water','مياه معدنية (350مل)',NULL,NULL,4.000,'each',0.00,1,111,'active','2025-12-10 22:40:06',NULL),(109,'113','Pepsi','بيبسي ',NULL,NULL,6.000,'each',0.00,1,112,'active','2025-12-10 22:40:06',NULL),(110,'114','Diet Pepsi','بيبسي دايت',NULL,NULL,6.000,'each',0.00,1,113,'active','2025-12-10 22:40:06',NULL),(111,'115','Mirinda','ميريندا',NULL,NULL,6.000,'each',0.00,1,114,'active','2025-12-10 22:40:06',NULL),(112,'116','7UP','سفن أب ',NULL,NULL,6.000,'each',0.00,1,115,'active','2025-12-10 22:40:06',NULL),(113,'117','Diet 7UP','سفن أب دايت',NULL,NULL,6.000,'each',0.00,1,116,'active','2025-12-10 22:40:06',NULL),(114,'118','Strawberry Mojito','مهيتو بالفراوله',NULL,NULL,22.000,'each',0.00,1,117,'active','2025-12-10 22:40:06',NULL),(115,'119','Passion Fruit Mojito','موهيتو بالفاكهة الاستوائية',NULL,NULL,22.000,'each',0.00,1,118,'active','2025-12-10 22:40:06',NULL),(116,'120','Classic Mojito','كلاسيك موهيتو',NULL,NULL,22.000,'each',0.00,1,119,'active','2025-12-10 22:40:06',NULL),(117,'121','Lemonade','ليموناضه',NULL,NULL,15.000,'each',0.00,1,120,'active','2025-12-10 22:40:06',NULL),(118,'122','Minted Lemonade','ليموناضه بالنعناع',NULL,NULL,16.000,'each',0.00,1,121,'active','2025-12-10 22:40:06',NULL),(119,'123','Fresh orange Juice','عصير برتقال',NULL,NULL,15.000,'each',0.00,1,122,'active','2025-12-10 22:40:06',NULL),(120,'124','Fresh Apple Juice','عصير تفاح',NULL,NULL,15.000,'each',0.00,1,123,'active','2025-12-10 22:40:06',NULL),(121,'125','Fresh Carrot Juice','عصير جزر',NULL,NULL,15.000,'each',0.00,1,124,'active','2025-12-10 22:40:06',NULL),(122,'126','Catering',NULL,NULL,NULL,100.000,'each',0.00,1,125,'active','2025-12-10 22:40:06',NULL),(123,'127','Funderdome Cakes',NULL,NULL,NULL,5250.000,'each',0.00,1,126,'active','2025-12-10 22:40:06',NULL),(124,'128','Caboodle ',NULL,NULL,NULL,4340.000,'each',0.00,1,127,'active','2025-12-10 22:40:06',NULL),(125,'129','Miscellaneous',NULL,NULL,NULL,6461.000,'each',0.00,1,128,'active','2025-12-10 22:40:06',NULL),(126,'130','TeKnwledge Coffee Break ',NULL,NULL,NULL,50.000,'each',0.00,1,129,'active','2025-12-10 22:40:06',NULL),(127,'131','TeKnowledge Full Catering ',NULL,NULL,NULL,120.000,'each',0.00,1,130,'active','2025-12-10 22:40:06',NULL),(128,'132','Teknowledge Special Coffee Breack  snacks part of Full day',NULL,NULL,NULL,20.000,'each',0.00,1,131,'active','2025-12-10 22:40:06',NULL),(129,'133','Teknowledge Full Breakfast only ',NULL,NULL,NULL,50.000,'each',0.00,1,132,'active','2025-12-10 22:40:06',NULL),(130,'134','beef burger',NULL,NULL,NULL,25.000,'each',0.00,1,133,'active','2025-12-10 22:40:06',NULL),(131,'135','chicken burger',NULL,NULL,NULL,25.000,'each',0.00,1,134,'active','2025-12-10 22:40:06',NULL),(132,'136','chicken chawarma',NULL,NULL,NULL,20.000,'each',0.00,1,135,'active','2025-12-10 22:40:06',NULL),(133,'137','Beef Chawarma',NULL,NULL,NULL,20.000,'each',0.00,1,136,'active','2025-12-10 22:40:06',NULL),(134,'138','Hot dog',NULL,NULL,NULL,15.000,'each',0.00,1,137,'active','2025-12-10 22:40:06',NULL),(135,'139','Chips',NULL,NULL,NULL,5.000,'each',0.00,1,138,'active','2025-12-10 22:40:06',NULL),(136,'140','Coca Cola',NULL,NULL,NULL,8.000,'each',0.00,1,139,'active','2025-12-10 22:40:06',NULL),(137,'141','Coca Cola Zero',NULL,NULL,NULL,5.000,'each',0.00,1,140,'active','2025-12-10 22:40:06',NULL),(138,'142','Sprite',NULL,NULL,NULL,5.000,'each',0.00,1,141,'active','2025-12-10 22:40:06',NULL),(139,'143','Arwa Water',NULL,NULL,NULL,5.000,'each',0.00,1,142,'active','2025-12-10 22:40:06',NULL),(140,'144','Special Box',NULL,NULL,NULL,40.000,'each',0.00,1,143,'active','2025-12-10 22:40:06',NULL),(141,'145','Meal Subscription 20 days',NULL,NULL,NULL,800.000,'each',0.00,1,144,'active','2025-12-10 22:40:06',NULL),(142,'146','Catering Event',NULL,NULL,NULL,2560.000,'each',0.00,1,145,'active','2025-12-10 22:40:06',NULL),(143,'147','Waiter/Waitress - Monthly Rate 22 Days',NULL,NULL,NULL,0.100,'each',0.00,1,146,'active','2025-12-10 22:40:06',NULL),(144,'148','Delivery Charge',NULL,NULL,NULL,20.000,'each',0.00,1,147,'active','2025-12-10 22:40:06',NULL),(145,'149','Avocado Sauce ',NULL,NULL,NULL,30.000,'each',0.00,1,148,'active','2025-12-10 22:40:06',NULL),(146,'150','Chips ',NULL,NULL,NULL,25.000,'each',0.00,1,149,'active','2025-12-10 22:40:06',NULL),(147,'151','Batenjen With Cheese',NULL,NULL,NULL,170.000,'each',0.00,1,150,'active','2025-12-10 22:40:06',NULL),(148,'152','Talabat Orders',NULL,NULL,NULL,0.100,'each',0.00,1,151,'active','2025-12-10 22:40:06',NULL),(149,'153','Snoonu Orders',NULL,NULL,NULL,0.100,'each',0.00,1,152,'active','2025-12-10 22:40:06',NULL),(150,'154','Rafeeq Orders',NULL,NULL,NULL,0.100,'each',0.00,1,153,'active','2025-12-10 22:40:06',NULL),(151,'155','Keeta Orders',NULL,NULL,NULL,0.010,'each',0.00,1,154,'active','2025-12-10 22:40:06',NULL),(152,'156','Kids Box',NULL,NULL,NULL,20.000,'each',0.00,1,155,'active','2025-12-10 22:40:06',NULL),(153,'157','Kamhieh',NULL,NULL,NULL,600.000,'each',0.00,1,156,'active','2025-12-10 22:40:06',NULL),(154,'158','Lamb Chops Raw',NULL,NULL,NULL,160.000,'each',0.00,1,157,'active','2025-12-10 22:40:06',NULL),(155,'159','Delivery Platform Payment',NULL,NULL,NULL,100.000,'each',0.00,1,158,'active','2025-12-10 22:40:06',NULL),(156,'160','Mix Grill Platter',NULL,NULL,NULL,185.000,'each',0.00,1,159,'active','2025-12-10 22:40:06',NULL),(157,'161','Fish Grilled',NULL,NULL,NULL,120.000,'each',0.00,1,160,'active','2025-12-10 22:40:06',NULL),(158,'162','Farrouj Mechwi (1000 G)','فروج مشوي',NULL,NULL,49.000,'each',0.00,1,161,'active','2025-12-10 22:40:06',NULL),(159,'163','Farrouj Mechwi  (Half) (500 G)','نصف فروج مشوي',NULL,NULL,28.000,'each',0.00,1,162,'active','2025-12-10 22:40:06',NULL),(160,'164','Kafta Plate (3 skewers)','صحن كفتة',NULL,NULL,49.000,'each',0.00,1,163,'active','2025-12-10 22:40:06',NULL),(161,'165','Taouk Plate ','صحن طاووق',NULL,NULL,42.000,'each',0.00,1,164,'active','2025-12-10 22:40:06',NULL),(162,'166','Chekaf Plate (3 skewers)','صحن شقف',NULL,NULL,62.000,'each',0.00,1,165,'active','2025-12-10 22:40:06',NULL),(163,'167','Kafta Djej Plate (3 skewers)','صحن كفتة دجاج',NULL,NULL,40.000,'each',0.00,1,166,'active','2025-12-10 22:40:06',NULL),(164,'168','Lamb Chops Plate (450 G)','صحن ريش مشوي',NULL,NULL,75.000,'each',0.00,1,167,'active','2025-12-10 22:40:06',NULL),(165,'169','Mix Grill Platter (4 skewers)','صحن مشاوي مشكل',NULL,NULL,62.000,'each',0.00,1,168,'active','2025-12-10 22:40:06',NULL),(166,'170','Shawarma Meat Plate (220 G)','صحن شورما لحمة',NULL,NULL,38.000,'each',0.00,1,169,'active','2025-12-10 22:40:06',NULL),(167,'171','Shawarma Chicken Plate (220 G)','صحن شورما دجاج',NULL,NULL,36.000,'each',0.00,1,170,'active','2025-12-10 22:40:06',NULL),(168,'172','Grilled Shrimp Plate (380 G)','روبيان مشوي',NULL,NULL,75.000,'each',0.00,1,171,'active','2025-12-10 22:40:06',NULL),(169,'173','Arayes Kafta Plate (150 G)','صحن عرايس كفتة',NULL,NULL,28.000,'each',0.00,1,172,'active','2025-12-10 22:40:06',NULL),(170,'174','Tochka (150 G)','طوشكا',NULL,NULL,32.000,'each',0.00,1,173,'active','2025-12-10 22:40:06',NULL),(171,'175','Kafta (KG)','كفتة (كيلو)',NULL,NULL,145.000,'each',0.00,1,174,'active','2025-12-10 22:40:06',NULL),(172,'176','Taouk (KG)','طاووق (كيلو)',NULL,NULL,120.000,'each',0.00,1,175,'active','2025-12-10 22:40:06',NULL),(173,'177','Chekaf (KG)','شقف (كيلو)',NULL,NULL,165.000,'each',0.00,1,176,'active','2025-12-10 22:40:06',NULL),(174,'178','Kafta Djej (KG)','كفتة دجاج (كيلو)',NULL,NULL,90.000,'each',0.00,1,177,'active','2025-12-10 22:40:06',NULL),(175,'179','Lamb Chops (KG)','ريش مشوي (كيلو)',NULL,NULL,175.000,'each',0.00,1,178,'active','2025-12-10 22:40:06',NULL),(176,'180','Mix Grill (KG)','مشاوي مشكل (كيلو)',NULL,NULL,130.000,'each',0.00,1,179,'active','2025-12-10 22:40:06',NULL),(177,'181','Grilled Shrimp (KG)','روبيان مشوي (كيلو)',NULL,NULL,180.000,'each',0.00,1,180,'active','2025-12-10 22:40:06',NULL),(178,'182','Pumpkin Kebbeh 1 Dozen',NULL,NULL,NULL,40.000,'each',0.00,1,181,'active','2025-12-10 22:40:06',NULL),(179,'183','Cheese Rolls 12 pcs',NULL,NULL,NULL,35.000,'each',0.00,1,182,'active','2025-12-10 22:40:06',NULL),(180,'184','Msakhan Chicken',NULL,NULL,NULL,40.000,'each',0.00,1,183,'active','2025-12-10 22:40:06',NULL),(181,'185','Zaatar',NULL,NULL,NULL,35.000,'each',0.00,1,184,'active','2025-12-10 22:40:06',NULL),(182,'186','Kebbeh 6 pcs',NULL,NULL,NULL,24.000,'each',0.00,1,185,'active','2025-12-10 22:40:06',NULL),(183,'187','Chicken Strips',NULL,NULL,NULL,160.000,'each',0.00,1,186,'active','2025-12-10 22:40:06',NULL),(184,'188','Chiken potato',NULL,NULL,NULL,150.000,'each',0.00,1,187,'active','2025-12-10 22:40:06',NULL),(185,'189','Soup',NULL,NULL,NULL,80.000,'each',0.00,1,188,'active','2025-12-10 22:40:06',NULL),(186,'190','Falafel 12pcs',NULL,NULL,NULL,40.000,'each',0.00,1,189,'active','2025-12-10 22:40:06',NULL),(187,'191','Sojok Pomegranate Syrup (120 G)','سجق بدبس الرمان',NULL,NULL,34.000,'each',0.00,1,190,'active','2025-12-10 22:40:06',NULL),(188,'192','Sojok with Vegetable (120 G)','سجق بالخضار',NULL,NULL,32.000,'each',0.00,1,191,'active','2025-12-10 22:40:06',NULL),(189,'193','Makanek Pomegranate Syrup (120 G)','نقانق بدس الرمان',NULL,NULL,32.000,'each',0.00,1,192,'active','2025-12-10 22:40:06',NULL),(190,'194','Makanek With Lemon & Garlic (120 G)','نقانق بالليمون والثوم',NULL,NULL,30.000,'each',0.00,1,193,'active','2025-12-10 22:40:06',NULL),(191,'195','Chicken Liver with Lemon & Garlic (150 G)','كبدة الدجاج بالحامض و الثوم',NULL,NULL,22.000,'each',0.00,1,194,'active','2025-12-10 22:40:06',NULL),(192,'196','Chicken Liver with Ponegranate Syrup (150 G)','كبدة الدجاج بدبس الرمان',NULL,NULL,24.000,'each',0.00,1,195,'active','2025-12-10 22:40:06',NULL),(193,'197','Kafta Hmaimees','كفتة حميميص',NULL,NULL,32.000,'each',0.00,1,196,'active','2025-12-10 22:40:06',NULL),(194,'198','Mutton Liver (150 G)','كبدة الغنم',NULL,NULL,22.000,'each',0.00,1,197,'active','2025-12-10 22:40:06',NULL),(195,'199','MUTTON HEAD','راس نيفا غنم',NULL,NULL,120.000,'each',0.00,1,198,'active','2025-12-10 22:40:06',NULL),(196,'200','Asafir Tyan (6 pieces)','عصفور التين ',NULL,NULL,90.000,'each',0.00,1,199,'active','2025-12-10 22:40:06',NULL),(197,'201','Chicken Wings Provencale (450 G)','جوانح دجاج بالكزبرة و الثوم',NULL,NULL,36.000,'each',0.00,1,200,'active','2025-12-10 22:40:06',NULL),(198,'202','Batata Harra','بطاطا حرة',NULL,NULL,20.000,'each',0.00,1,201,'active','2025-12-10 22:40:06',NULL),(199,'203','French Fries','بطاطا مقلية',NULL,NULL,17.000,'each',0.00,1,202,'active','2025-12-10 22:40:06',NULL),(200,'204','Kebbeh 12 pcs','كبة أقراص',NULL,NULL,45.000,'each',0.00,1,203,'active','2025-12-10 22:40:06',NULL),(201,'205','Sambousek Lahmeh','سمبوسك باللحمة (6 حبّات)',NULL,NULL,40.000,'each',0.00,1,204,'active','2025-12-10 22:40:06',NULL),(202,'206','Sambousek Cheese ','سمبوسك باللجبنة (6 حبّات)',NULL,NULL,40.000,'each',0.00,1,205,'active','2025-12-10 22:40:06',NULL),(203,'207','Cheese & Basterma Roll (6 pcs)','رقائق البسترما بالجبنة (6 حبّات)',NULL,NULL,24.000,'each',0.00,1,206,'active','2025-12-10 22:40:06',NULL),(204,'208','Cheese Roll (6 pcs)','رقائق بالجبنة (6 حبّات)',NULL,NULL,18.000,'each',0.00,1,207,'active','2025-12-10 22:40:06',NULL),(205,'209','Fatayer Spinach (6 pcs)','فطائر السبانخ (6 حبّات)',NULL,NULL,22.000,'each',0.00,1,208,'active','2025-12-10 22:40:06',NULL),(206,'210','Fatayer Green Zaatar  (6 pcs)','فطائر بالزعتر الأخضر (6 حبّات)',NULL,NULL,24.000,'each',0.00,1,209,'active','2025-12-10 22:40:06',NULL),(207,'211','Fatayer Potato (6 pcs)','فطائر بالبطاطا (6 حبّات)',NULL,NULL,22.000,'each',0.00,1,210,'active','2025-12-10 22:40:06',NULL),(208,'212','Fatayer Bakleh (6 pcs)','فطائر البقلة (6 حبّات)',NULL,NULL,24.000,'each',0.00,1,211,'active','2025-12-10 22:40:06',NULL),(209,'213','Mix Moajanat (12 pcs)','معجنات مشكلة (12 حبة)',NULL,NULL,45.000,'each',0.00,1,212,'active','2025-12-10 22:40:06',NULL),(210,'214','Grilled Halloumi','حلوم مشوي',NULL,NULL,29.000,'each',0.00,1,213,'active','2025-12-10 22:40:06',NULL),(211,'215','Bayd Ghanam (150 G)','بيض غنم',NULL,NULL,24.000,'each',0.00,1,214,'active','2025-12-10 22:40:06',NULL),(212,'216','Lamb Brains (150 G)','نخاعات غنم',NULL,NULL,25.000,'each',0.00,1,215,'active','2025-12-10 22:40:06',NULL),(213,'217','Lamb tongues (180 G)','لسانات غنم',NULL,NULL,28.000,'each',0.00,1,216,'active','2025-12-10 22:40:06',NULL),(214,'218','Warak 3inab Veg',NULL,NULL,NULL,130.000,'each',0.00,1,217,'active','2025-12-10 22:40:06',NULL),(215,'219','Kebbeh Bil Sayniye','كبة بالصينية',NULL,NULL,120.000,'each',0.00,1,218,'active','2025-12-10 22:40:06','2025-12-14 21:48:39'),(216,'220','Kafta with Potato','كفتة مع بطاطا',NULL,NULL,200.000,'each',0.00,1,219,'active','2025-12-10 22:40:06','2025-12-14 21:48:39'),(217,'221','Koussa Mehchi',NULL,NULL,NULL,200.000,'each',0.00,1,220,'active','2025-12-10 22:40:06',NULL),(218,'222','Warak 3inab Meat',NULL,NULL,NULL,350.000,'each',0.00,1,221,'active','2025-12-10 22:40:06',NULL),(219,'223','Roast Beef Mashed Potato Veg',NULL,NULL,NULL,250.000,'each',0.00,1,222,'active','2025-12-10 22:40:06',NULL),(220,'224','roast beef and veg',NULL,NULL,NULL,280.000,'each',0.00,1,223,'active','2025-12-10 22:40:06',NULL),(221,'225','Potato Soufle',NULL,NULL,NULL,150.000,'each',0.00,1,224,'active','2025-12-10 22:40:06',NULL),(222,'226','Siyyadiyeh',NULL,NULL,NULL,300.000,'each',0.00,1,225,'active','2025-12-10 22:40:06',NULL),(223,'227','Roast Beef',NULL,NULL,NULL,220.000,'each',0.00,1,226,'active','2025-12-10 22:40:06',NULL),(224,'228','Kharouf Mehchi',NULL,NULL,NULL,320.000,'each',0.00,1,227,'active','2025-12-10 22:40:06',NULL),(225,'229','Maintenance works',NULL,NULL,NULL,0.100,'each',0.00,1,228,'active','2025-12-10 22:40:06',NULL),(226,'230','Chicken Supreme','دجاج سوبريم',NULL,NULL,260.000,'each',0.00,1,229,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(227,'231','Rice with Meat',NULL,NULL,NULL,300.000,'each',0.00,1,230,'active','2025-12-10 22:40:06',NULL),(228,'232','Chicken Caju with Nuts',NULL,NULL,NULL,260.000,'each',0.00,1,231,'active','2025-12-10 22:40:06',NULL),(229,'233','Chicken With Rice',NULL,NULL,NULL,180.000,'each',0.00,1,232,'active','2025-12-10 22:40:06',NULL),(230,'234','Pumpkin Kebbeh','كبة يقطين',NULL,NULL,100.000,'each',0.00,1,233,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(231,'235','Shrimp Provencial',NULL,NULL,NULL,160.000,'each',0.00,1,234,'active','2025-12-10 22:40:06',NULL),(232,'236','Gigot with Vegetables',NULL,NULL,NULL,500.000,'each',0.00,1,235,'active','2025-12-10 22:40:06',NULL),(233,'237','Lazagna',NULL,NULL,NULL,120.000,'each',0.00,1,236,'active','2025-12-10 22:40:06',NULL),(234,'238','Spinach Pie',NULL,NULL,NULL,120.000,'each',0.00,1,237,'active','2025-12-10 22:40:06',NULL),(235,'239','Sawarma Chicken 12 Pcs',NULL,NULL,NULL,48.000,'each',0.00,1,238,'active','2025-12-10 22:40:06',NULL),(236,'240','Mini Shawarma Meat ',NULL,NULL,NULL,48.000,'each',0.00,1,239,'active','2025-12-10 22:40:06',NULL),(237,'241','Samkeh Harra',NULL,NULL,NULL,300.000,'each',0.00,1,240,'active','2025-12-10 22:40:06',NULL),(238,'242','Creamy pasta shrimp',NULL,NULL,NULL,200.000,'each',0.00,1,241,'active','2025-12-10 22:40:06',NULL),(239,'243','Chicken spinach',NULL,NULL,NULL,180.000,'each',0.00,1,242,'active','2025-12-10 22:40:06',NULL),(240,'244','Chicken Nouille','دجاج نودلز',NULL,NULL,160.000,'each',0.00,1,243,'active','2025-12-10 22:40:06','2025-12-14 21:48:39'),(241,'245','Philadelphia','فيلادلفيا',NULL,NULL,14.000,'each',0.00,1,244,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(242,'246','Kharouf Mechi Warak 3inab',NULL,NULL,NULL,650.000,'each',0.00,1,245,'active','2025-12-10 22:40:06',NULL),(243,'247','Eggplant wiht Cheese',NULL,NULL,NULL,130.000,'each',0.00,1,246,'active','2025-12-10 22:40:06',NULL),(244,'248','Chicken Wings',NULL,NULL,NULL,100.000,'each',0.00,1,247,'active','2025-12-10 22:40:06',NULL),(245,'249','Pasta Alfredo',NULL,NULL,NULL,220.000,'each',0.00,1,248,'active','2025-12-10 22:40:06',NULL),(246,'250','Shrimp Noodles',NULL,NULL,NULL,250.000,'each',0.00,1,249,'active','2025-12-10 22:40:06',NULL),(247,'251','Moghrabiye',NULL,NULL,NULL,180.000,'each',0.00,1,250,'active','2025-12-10 22:40:06',NULL),(248,'252','Moujadara ',NULL,NULL,NULL,110.000,'each',0.00,1,251,'active','2025-12-10 22:40:06',NULL),(249,'253','Mloukhiye',NULL,NULL,NULL,220.000,'each',0.00,1,252,'active','2025-12-10 22:40:06',NULL),(250,'254','Riz Aa Djej',NULL,NULL,NULL,280.000,'each',0.00,1,253,'active','2025-12-10 22:40:06',NULL),(251,'255','Mehchi Malfouf','محشي ملفوف',NULL,NULL,180.000,'each',0.00,1,254,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(252,'256','Daoud Basha with Rice',NULL,NULL,NULL,180.000,'each',0.00,1,255,'active','2025-12-10 22:40:06',NULL),(253,'257','Spinach with Rice','سبانخ مع أرز',NULL,NULL,180.000,'each',0.00,1,256,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(254,'258','Frickeh Chicken',NULL,NULL,NULL,200.000,'each',0.00,1,257,'active','2025-12-10 22:40:06',NULL),(255,'259','Koussa Kablama',NULL,NULL,NULL,180.000,'each',0.00,1,258,'active','2025-12-10 22:40:06',NULL),(256,'260','chich Barak with Rice','شيش برك مع أرز',NULL,NULL,180.000,'each',0.00,1,259,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(257,'261','Hrissi',NULL,NULL,NULL,240.000,'each',0.00,1,260,'active','2025-12-10 22:40:06',NULL),(258,'262','Oriental Rice with Chicken',NULL,NULL,NULL,260.000,'each',0.00,1,261,'active','2025-12-10 22:40:06',NULL),(259,'263','Briyani Meat',NULL,NULL,NULL,200.000,'each',0.00,1,262,'active','2025-12-10 22:40:06',NULL),(260,'264','Kameh Half Portion',NULL,NULL,NULL,50.000,'each',0.00,1,263,'active','2025-12-10 22:40:06',NULL),(261,'265','Chich Barak 120 Pcs',NULL,NULL,NULL,325.000,'each',0.00,1,264,'active','2025-12-10 22:40:06',NULL),(262,'266','Salmon with Vegetables',NULL,NULL,NULL,250.000,'each',0.00,1,265,'active','2025-12-10 22:40:06',NULL),(263,'267','Chicken Alfredo','دجاج ألفريدو',NULL,NULL,280.000,'each',0.00,1,266,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(264,'268','Paella',NULL,NULL,NULL,200.000,'each',0.00,1,267,'active','2025-12-10 22:40:06',NULL),(265,'269','Mehchi Malfouf Raw',NULL,NULL,NULL,90.000,'each',0.00,1,268,'active','2025-12-10 22:40:06',NULL),(266,'270','Rice with meat Plate',NULL,NULL,NULL,30.000,'each',0.00,1,269,'active','2025-12-10 22:40:06',NULL),(267,'271','Rice with Chicken Meal',NULL,NULL,NULL,35.000,'each',0.00,1,270,'active','2025-12-10 22:40:06',NULL),(268,'272','Syrian Lamb Shank  (choice of Rice Majbous - Bukhari - Biryani - Mandi)','موزات غنم سوري (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,75.000,'each',0.00,1,271,'active','2025-12-10 22:40:06',NULL),(269,'273','Australian Lamb Shank  (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','موزات غنم استرالي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,35.000,'each',0.00,1,272,'active','2025-12-10 22:40:06',NULL),(270,'274','Kafta Lamb (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','كفتة غنم (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,45.000,'each',0.00,1,273,'active','2025-12-10 22:40:06',NULL),(271,'275','Mix Grill (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','مشاوي مشكل (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,55.000,'each',0.00,1,274,'active','2025-12-10 22:40:06',NULL),(272,'276','Half Chicken   (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','تصف فروج مشوي (اختيار الارز من المجبوس -  البخاري - برياني - مندي)',NULL,NULL,35.000,'each',0.00,1,275,'active','2025-12-10 22:40:06',NULL),(273,'277','Shrimp   (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','روبيان (اختيار الارز من المجبوس -  البخاري - برياني - مندي)',NULL,NULL,65.000,'each',0.00,1,276,'active','2025-12-10 22:40:06',NULL),(274,'278','chicken Escalope ',NULL,NULL,NULL,8.000,'each',0.00,1,277,'active','2025-12-10 22:40:06',NULL),(275,'279','Chicken Burger',NULL,NULL,NULL,10.000,'each',0.00,1,278,'active','2025-12-10 22:40:06',NULL),(276,'280','Beef Burger','برجر لحم',NULL,NULL,10.000,'each',0.00,1,279,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(277,'281','Cutlery',NULL,NULL,NULL,10.000,'each',0.00,1,280,'active','2025-12-10 22:40:06',NULL),(278,'282','Bakdounis cut 1 Box',NULL,NULL,NULL,45.000,'each',0.00,1,281,'active','2025-12-10 22:40:06',NULL),(279,'283','Lemon Juice ',NULL,NULL,NULL,15.000,'each',0.00,1,282,'active','2025-12-10 22:40:06',NULL),(280,'284','Perfecta Mayonnaise',NULL,NULL,NULL,0.100,'each',0.00,1,283,'active','2025-12-10 22:40:06',NULL),(281,'285','Tomato Cut 1 Box',NULL,NULL,NULL,20.000,'each',0.00,1,284,'active','2025-12-10 22:40:06',NULL),(282,'286','Mustard Sauce',NULL,NULL,NULL,0.100,'each',0.00,1,285,'active','2025-12-10 22:40:06',NULL),(283,'287','Ba2dounis',NULL,NULL,NULL,50.000,'each',0.00,1,286,'active','2025-12-10 22:40:06',NULL),(284,'288','Raheb Salad',NULL,NULL,NULL,70.000,'each',0.00,1,287,'active','2025-12-10 22:40:06',NULL),(285,'289','Bakleh Salad',NULL,NULL,NULL,120.000,'each',0.00,1,288,'active','2025-12-10 22:40:06',NULL),(286,'290','Caterart Fetta Salad',NULL,NULL,NULL,220.000,'each',0.00,1,289,'active','2025-12-10 22:40:06',NULL),(287,'291','Crab Salad',NULL,NULL,NULL,200.000,'each',0.00,1,290,'active','2025-12-10 22:40:06',NULL),(288,'292','Blue Cheese Salad',NULL,NULL,NULL,200.000,'each',0.00,1,291,'active','2025-12-10 22:40:06',NULL),(289,'293','Strawberry Salad',NULL,NULL,NULL,220.000,'each',0.00,1,292,'active','2025-12-10 22:40:06',NULL),(290,'294','Chicken Quinoa Salad',NULL,NULL,NULL,220.000,'each',0.00,1,293,'active','2025-12-10 22:40:06',NULL),(291,'295','Fresh Salad','سلطة طازجة',NULL,NULL,220.000,'each',0.00,1,294,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(292,'296','Salad ',NULL,NULL,NULL,10.000,'each',0.00,1,295,'active','2025-12-10 22:40:06',NULL),(293,'297','Special Salad',NULL,NULL,NULL,180.000,'each',0.00,1,296,'active','2025-12-10 22:40:06',NULL),(294,'298','Kale salad ',NULL,NULL,NULL,200.000,'each',0.00,1,297,'active','2025-12-10 22:40:06',NULL),(295,'299','Rocca With Goat Cheese Salad',NULL,NULL,NULL,180.000,'each',0.00,1,298,'active','2025-12-10 22:40:06',NULL),(296,'300','Goat Cheese Salad',NULL,NULL,NULL,220.000,'each',0.00,1,299,'active','2025-12-10 22:40:06',NULL),(297,'301','Halloumi Salad','سلطة حلوم',NULL,NULL,160.000,'each',0.00,1,300,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(298,'302','Pasta Salad',NULL,NULL,NULL,180.000,'each',0.00,1,301,'active','2025-12-10 22:40:06',NULL),(299,'303','Caesar Salad',NULL,NULL,NULL,180.000,'each',0.00,1,302,'active','2025-12-10 22:40:06',NULL),(300,'304','Greek Salad','سلطة يونانية',NULL,NULL,29.000,'each',0.00,1,303,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(301,'305','Salata Jabaliyyeh','سلطة جبلية ',NULL,NULL,32.000,'each',0.00,1,304,'active','2025-12-10 22:40:06',NULL),(302,'306','Fattouch','فتوش',NULL,NULL,24.000,'each',0.00,1,305,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(303,'307','Tabbouleh','تبولة',NULL,NULL,20.000,'each',0.00,1,306,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(304,'308','Rocca Salad','سلطة الجرجير',NULL,NULL,25.000,'each',0.00,1,307,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(305,'309','Spicy Olives Salad','سلطة الزيتون بالحر',NULL,NULL,24.000,'each',0.00,1,308,'active','2025-12-10 22:40:06',NULL),(306,'310','Layla Special Beets salad','سلطة ليلى',NULL,NULL,29.000,'each',0.00,1,309,'active','2025-12-10 22:40:06',NULL),(307,'311','Yougurt & Cucumber Salad','خيار باللبن',NULL,NULL,15.000,'each',0.00,1,310,'active','2025-12-10 22:40:06',NULL),(308,'312','Roast Beef Mini Sandwish 12pcs',NULL,NULL,NULL,72.000,'each',0.00,1,311,'active','2025-12-10 22:40:06',NULL),(309,'313','Chich Barak 80 Pcs',NULL,NULL,NULL,100.000,'each',0.00,1,312,'active','2025-12-10 22:40:06',NULL),(310,'314','Fajita Chicken',NULL,NULL,NULL,14.000,'each',0.00,1,313,'active','2025-12-10 22:40:06',NULL),(311,'315','Fajita Shrimp',NULL,NULL,NULL,16.000,'each',0.00,1,314,'active','2025-12-10 22:40:06',NULL),(312,'316','Steak Sandwich',NULL,NULL,NULL,96.000,'each',0.00,1,315,'active','2025-12-10 22:40:06',NULL),(313,'317','Mini Shawarma Chicken ',NULL,NULL,NULL,60.000,'each',0.00,1,316,'active','2025-12-10 22:40:06',NULL),(314,'318','Sujuk',NULL,NULL,NULL,15.000,'each',0.00,1,317,'active','2025-12-10 22:40:06',NULL),(315,'319','Assorted Sandwishes',NULL,NULL,NULL,15.000,'each',0.00,1,318,'active','2025-12-10 22:40:06',NULL),(316,'320','Shawarma Chicken','شاورما دجاج',NULL,NULL,15.000,'each',0.00,1,319,'active','2025-12-10 22:40:06','2025-12-14 21:48:39'),(317,'321','Shawarma Meat','شاورما لحمة',NULL,NULL,16.000,'each',0.00,1,320,'active','2025-12-10 22:40:06',NULL),(318,'322','Sojok','سجق',NULL,NULL,18.000,'each',0.00,1,321,'active','2025-12-10 22:40:06',NULL),(319,'323','Makanek','مقانق',NULL,NULL,18.000,'each',0.00,1,322,'active','2025-12-10 22:40:06',NULL),(320,'324','Chicken Liver','سودة الدجاج',NULL,NULL,17.000,'each',0.00,1,323,'active','2025-12-10 22:40:06',NULL),(321,'325','Kafta','كفتة',NULL,NULL,18.000,'each',0.00,1,324,'active','2025-12-10 22:40:06',NULL),(322,'326','Shish Taouk','شيش طاووق',NULL,NULL,18.000,'each',0.00,1,325,'active','2025-12-10 22:40:06','2025-12-14 21:48:38'),(323,'327','Chekaf','شقف',NULL,NULL,19.000,'each',0.00,1,326,'active','2025-12-10 22:40:06',NULL),(324,'328','Kafta Djej','كفتة دجاج',NULL,NULL,17.000,'each',0.00,1,327,'active','2025-12-10 22:40:06',NULL),(325,'329','Chicken Marrouch','دجاج مروش',NULL,NULL,17.000,'each',0.00,1,328,'active','2025-12-10 22:40:06',NULL),(326,'330','Sawdit Ghanam','سودة غنم',NULL,NULL,17.000,'each',0.00,1,329,'active','2025-12-10 22:40:06',NULL),(327,'331','Special Layla sandwich','سندويس ليلى ',NULL,NULL,20.000,'each',0.00,1,330,'active','2025-12-10 22:40:06',NULL),(328,'332','Grilled Lamb Burger (Platter)','برغر غنم مشوي (صحن)',NULL,NULL,35.000,'each',0.00,1,331,'active','2025-12-10 22:40:06',NULL),(329,'333','Grilled Chicken Burger (Platter)','برغر دجاج (صحن)',NULL,NULL,32.000,'each',0.00,1,332,'active','2025-12-10 22:40:06',NULL),(330,'334','Bayd Ghanam','بيض غنم',NULL,NULL,18.000,'each',0.00,1,333,'active','2025-12-10 22:40:06',NULL),(331,'335','Lamb Brains','نخاعات غنم',NULL,NULL,17.000,'each',0.00,1,334,'active','2025-12-10 22:40:06',NULL),(332,'336','Lamb tongues','لسانات غنم',NULL,NULL,19.000,'each',0.00,1,335,'active','2025-12-10 22:40:06',NULL),(333,'337','Maamoul Dates','معمول بالتمر',NULL,NULL,140.000,'each',0.00,1,336,'active','2025-12-10 22:40:06',NULL),(334,'338','Maamoul Jozz',NULL,NULL,NULL,150.000,'each',0.00,1,337,'active','2025-12-10 22:40:06',NULL),(335,'339','Maamoul Pistachio','معمول بالفستق',NULL,NULL,160.000,'each',0.00,1,338,'active','2025-12-10 22:40:06',NULL),(336,'340','Maamoul Mix',NULL,NULL,NULL,160.000,'each',0.00,1,339,'active','2025-12-10 22:40:06',NULL),(337,'341','Meghli',NULL,NULL,NULL,20.000,'each',0.00,1,340,'active','2025-12-10 22:40:06',NULL),(338,'342','Custom Cake',NULL,NULL,NULL,350.000,'each',0.00,1,341,'active','2025-12-10 22:40:06',NULL),(339,'343','Big Bread',NULL,NULL,NULL,10.000,'each',0.00,1,342,'active','2025-12-10 22:40:06',NULL),(340,'344','Snayniye',NULL,NULL,NULL,20.000,'each',0.00,1,343,'active','2025-12-10 22:40:06',NULL),(341,'345','Amhiyye',NULL,NULL,NULL,450.000,'each',0.00,1,344,'active','2025-12-10 22:40:06',NULL),(342,'346','Small Bread',NULL,NULL,NULL,6.000,'each',0.00,1,345,'active','2025-12-10 22:40:06',NULL),(343,'347','chich barak 40 pcs',NULL,NULL,NULL,40.000,'each',0.00,1,346,'active','2025-12-10 22:40:06',NULL),(344,'348','Croissant ',NULL,NULL,NULL,6.000,'each',0.00,1,347,'active','2025-12-10 22:40:06',NULL),(345,'349','Popsicle',NULL,NULL,NULL,14.000,'each',0.00,1,348,'active','2025-12-10 22:40:06',NULL),(346,'350','Maakaroun 1 Kg',NULL,NULL,NULL,120.000,'each',0.00,1,349,'active','2025-12-10 22:40:06',NULL),(347,'351','Sfouf','صفوف',NULL,NULL,100.000,'each',0.00,1,350,'active','2025-12-10 22:40:06','2025-12-14 21:48:39'),(348,'352','Cupcake with custome design',NULL,NULL,NULL,8.000,'each',0.00,1,351,'active','2025-12-10 22:40:06',NULL),(349,'353','Tacos Beef',NULL,NULL,NULL,140.000,'each',0.00,1,352,'active','2025-12-10 22:40:06',NULL),(350,'354','Australian Lamb WHOLE (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','خروف كامل استرالي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,1.000,'each',0.00,1,353,'active','2025-12-10 22:40:06',NULL),(351,'355','Australian Lamb HALF (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','نصف خروف استرالي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,650.000,'each',0.00,1,354,'active','2025-12-10 22:40:06',NULL),(352,'356','Australian Lamb QUARTER (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','ربع خروف استرالي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,350.000,'each',0.00,1,355,'active','2025-12-10 22:40:06',NULL),(353,'357','Arabic Lamb WHOLE (choice of Rice Majbous - Bukhari - Biryani - Mandi)','خروف كامل عربي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,1.000,'each',0.00,1,356,'active','2025-12-10 22:40:06',NULL),(354,'358','Arabic Lamb HALF (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','نصف خروف عربي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,790.000,'each',0.00,1,357,'active','2025-12-10 22:40:06',NULL),(355,'359','Arabic Lamb QUARTER (choice of Rice Majbous - Bukhari - Biryani - Mandi)','ربع خروف عربي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,400.000,'each',0.00,1,358,'active','2025-12-10 22:40:06',NULL),(356,'360','Syrian Lamb WHOLE (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','خروف كامل سوري (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,1.000,'each',0.00,1,359,'active','2025-12-10 22:40:06',NULL),(357,'361','Syrian Lamb HALF (choice of Rice Majbous - Bukhari - Biryani - Mandi)','نصف خروف سوري (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,975.000,'each',0.00,1,360,'active','2025-12-10 22:40:06',NULL),(358,'362','Syrian Lamb QUARTER (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','ربع خروف سوري (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,500.000,'each',0.00,1,361,'active','2025-12-10 22:40:06',NULL),(359,'MI-474225','Pasta bolognese','باستا بولونيز',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(360,'MI-503690','Eggplant msakaa','مسقعة',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(361,'MI-501915','Tarte','تارت',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(362,'MI-934152','Daoud bacha with rice','داود باشا مع أرز',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(363,'MI-937612','Mehchi koussa','محشي كوسا',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(364,'MI-003910','Fish fillet with vegetables','فيليه سمك مع خضار',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(365,'MI-942656','Orange Cake','كيك برتقال',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(366,'MI-861381','Beef stroganoff','ستروغانوف لحم',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(367,'MI-743867','Chicken shawarma','شاورما دجاج',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(368,'MI-766964','Loubye with oil','لوبيا بالزيت',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(369,'MI-748465','Cookies','بسكويت',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(370,'MI-564627','Chicken kaju nuts','دجاج كاجو',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(371,'MI-971238','Potato souffle','سوفليه بطاطا',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(372,'MI-560900','Pasta pesto','باستا بيستو',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(373,'MI-202565','Green Salad','سلطة خضراء',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(374,'MI-193109','Banana cake','كيك موز',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(375,'MI-098323','Falafel','فلافل',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(376,'MI-094121','Mix Kabab','كبة مشكلة',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(377,'MI-514294','Vanilla cake','كيك فانيليا',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(378,'MI-444169','Chicken stroganoff','ستروغانوف دجاج',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(379,'MI-872527','Kabab orfali','كبة أورفالي',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(380,'MI-617567','Noodles vegetables','نودلز خضار',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(381,'MI-642960','Vine leaves with meat','ورق عنب باللحم',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(382,'MI-569195','Mjadara','مجدرة',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(383,'MI-025468','Muffins','مافن',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(384,'MI-922733','Chicken biryani','برياني دجاج',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(385,'MI-137547','Okra with oil','بامية بالزيت',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(386,'MI-372907','Chocolate cake','كيك شوكولاتة',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(387,'MI-802015','Coconut chicken curry','كاري دجاج بالكوكو',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(388,'MI-068409','Penne Arrabbiata','بيني أرابياتا',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(389,'MI-875921','Brownies','براونيز',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(390,'MI-783855','Lasagna','لازانيا',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(391,'MI-925300','Mdardara','مدردرة',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(392,'MI-897360','Cake','كيك',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(393,'MI-604375','Grilled kafta','كفتة مشوية',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(394,'MI-230855','Shrimp with rice','روبيان مع أرز',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(395,'MI-677566','Oriental rice with meat','أرز شرقي باللحم',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(396,'MI-037672','Noodles chicken','نودلز دجاج',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(397,'MI-565142','Coconut shrimp curry','كاري روبيان بالكوكو',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(398,'MI-218890','Carrot Cake','كيك جزر',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(399,'MI-371737','Mashed potato with meat balls','بطاطا مهروسة مع كرات لحم',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(400,'MI-510767','Quinoa salad','سلطة كينوا',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(401,'MI-435838','Creamy shrimp pasta','باستا روبيان كريمية',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(402,'MI-050797','Moughrabiye','مغربية',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(403,'MI-475079','Fassolia with oil','فاصوليا بالزيت',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(404,'MI-265226','Kabab khishkhash','كبة خيشخاش',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(405,'MI-113261','Meat balls with mashed','كرات لحم مع مهروس',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(406,'MI-697718','Shrimp kaju nuts','روبيان كاجو',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(407,'MI-679031','Grilled chicken','دجاج مشوي',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:38','2025-12-14 21:48:38'),(408,'MI-741868','Siyadiye','صيادية',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(409,'MI-215194','Kafta bi tahini','كفتة بالطحينة',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(410,'MI-419091','Fish and chips','سمك ورقائق',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(411,'MI-882758','Malfouf salad or Green salad','سلطة ملفوف أو سلطة خضراء',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(412,'MI-459272','Kabab khichkhach','كبة خيشخاش',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(413,'MI-713207','Frikeh chicken','فريكة دجاج',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(414,'MI-603781','Shawarma beef','شاورما لحم',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(415,'MI-878589','Loubye bi zeit','لوبيا بالزيت',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(416,'MI-957982','Roast beef with mashed potato','لحم مشوي مع بطاطا مهروسة',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(417,'MI-050882','Bazella with rice','بازيلا مع أرز',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(418,'MI-718220','Sheikh el mehchi','شيخ المحشي',NULL,NULL,0.000,'each',0.00,1,0,'active','2025-12-14 21:48:39','2025-12-14 21:48:39'),(419,'363','Salad Panache',NULL,6,NULL,180.000,'each',0.00,1,362,'active','2025-12-20 12:58:15','2025-12-20 12:58:15'),(420,'364','Layla Fetta Salad',NULL,6,NULL,220.000,'each',0.00,1,363,'active','2025-12-20 13:36:15','2025-12-20 13:36:15');
/*!40000 ALTER TABLE `menu_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2025_09_02_075243_add_two_factor_columns_to_users_table',1),(5,'2025_12_11_000300_update_users_table_for_auth',1),(6,'2025_12_11_205203_create_permission_tables',1),(7,'2025_12_12_000400_update_categories_table',1),(8,'2025_12_12_000450_update_suppliers_table_indexes_and_optional_soft_delete',2),(9,'2025_12_12_000500_customers_add_optional_audit_fields_and_indexes',2),(10,'2025_12_12_000600_inventory_add_indexes_and_foreign_keys_safely',2),(11,'2025_12_12_120000_create_session_and_password_tables_if_missing',2),(12,'2025_12_12_000700_menu_items_add_safe_indexes_and_foreign_keys',3),(13,'2025_12_12_000850_create_purchase_orders_tables_if_missing',3),(14,'2025_12_12_000900_purchase_orders_add_safe_indexes_and_foreign_keys',3),(15,'2025_12_13_000910_add_payment_fields_to_purchase_orders',4),(16,'2025_12_13_001000_ap_add_indexes_and_foreign_keys_safely',5),(17,'2025_12_15_000000_expenses_add_indexes_and_foreign_keys_safely',6),(18,'2025_12_16_000000_petty_cash_add_indexes_and_foreign_keys_safely',7),(19,'2025_12_20_000000_recipes_add_indexes_and_foreign_keys_safely',8),(20,'2025_12_21_000000_create_daily_dish_menus_table',9),(21,'2025_12_21_000100_create_daily_dish_menu_items_table',9),(22,'2025_12_22_000000_create_meal_subscriptions_table',10),(23,'2025_12_22_000100_create_meal_subscription_days_table',10),(24,'2025_12_22_000200_create_meal_subscription_pauses_table',11),(25,'2025_12_23_000000_create_meal_subscription_orders_table',11),(26,'2025_12_14_000001_create_subscription_order_runs_table',12),(27,'2025_12_14_000002_create_subscription_order_run_errors_table',12),(28,'2025_12_14_000003_create_ops_events_table',12),(29,'2025_12_14_000010_add_status_to_menu_items_table',13),(30,'2025_12_14_000020_update_orders_for_website_orders',13),(31,'2025_12_14_000040_add_quota_and_request_link_to_meal_subscriptions',14),(32,'2025_12_20_000050_add_order_discount_to_orders_table',15),(33,'2025_12_14_000000_rebuild_schema_from_dump',16),(34,'2025_12_14_000030_create_meal_plan_requests_table',17),(35,'2025_12_23_000010_add_menu_item_search_indexes',17),(36,'2026_01_27_000001_add_cost_fields_to_inventory_transactions',17),(37,'2026_01_27_000002_add_search_indexes',17),(38,'2026_01_27_000003_create_branches_table',17),(39,'2026_01_27_000004_add_branch_foreign_keys',17),(40,'2026_01_27_000005_create_meal_plan_request_orders_table',17),(41,'2026_01_27_000006_create_personal_access_tokens_table',17),(42,'2026_01_27_000007_add_ap_invoice_audit_columns',17),(43,'2026_01_27_000008_add_core_foreign_keys_safely',17),(44,'2026_01_27_000009_update_inventory_quantities_to_decimal',17),(45,'2026_01_27_000010_create_ledger_tables',18),(46,'2026_01_28_000011_update_recipe_overhead_pct_precision',19),(47,'2026_01_28_000012_add_additional_foreign_keys_safely',20),(48,'2026_01_28_000013_add_inventory_stocks_and_branch_to_transactions',21),(49,'2026_01_28_000014_create_inventory_transfers_table',22),(50,'2026_01_28_000015_create_inventory_transfer_lines_table',22),(51,'2026_01_28_000016_add_inventory_transfer_foreign_keys_and_reference_type',22),(52,'2026_01_28_000017_create_menu_item_branches_table',23),(53,'2026_01_29_000001_drop_meal_plan_request_order_ids',24),(54,'2026_01_29_000002_drop_inventory_items_current_stock',25),(55,'2026_01_29_000003_normalize_subscription_fk_types_and_add_fks',26),(56,'2026_01_29_000004_add_posting_audit_to_payments',26),(57,'2026_01_29_000005_add_void_audit_fields',27),(58,'2026_01_29_000006_ensure_void_audit_columns',27),(59,'2026_01_29_000007_create_finance_settings_table',28),(60,'2026_01_29_000008_add_branch_id_to_gl_batch_lines',29),(61,'2026_01_29_000009_create_order_number_sequences_table',30),(62,'2026_01_29_000010_add_unique_to_orders_order_number_if_clean',30),(63,'2026_01_29_000011_create_document_sequences_table',31),(64,'2026_01_29_000012_create_pos_shifts_table',31),(65,'2026_01_29_000013_create_sales_tables',31),(66,'2026_01_29_000014_create_payments_and_allocations_tables',31),(67,'2026_01_29_000015_create_ar_invoices_tables',31),(68,'2026_01_29_000014_add_pos_columns_to_sales',32),(69,'2026_01_29_000015_add_pos_reference_credit_discounts',33),(70,'2026_01_29_000016_update_ar_invoices_for_sales_fields',34),(71,'2026_01_29_000017_create_payment_terms_table',35),(72,'2026_01_29_000018_add_payment_term_id_to_ar_invoices',35),(73,'2026_01_30_000001_update_currency_defaults_to_qar',36),(74,'2026_01_30_000002_add_pos_reference_to_ar_invoices',36),(75,'2026_01_30_000003_add_unit_to_menu_items_table',37),(77,'2026_01_30_000004_add_daily_dish_pricing_fields',38),(78,'2026_01_30_000005_allow_multiple_meal_subscription_orders_per_day',38),(79,'2026_01_30_000010_add_order_invoice_linking',39),(80,'2026_02_04_000001_create_pos_terminals_table',40),(81,'2026_02_04_000002_create_pos_document_sequences_table',40),(82,'2026_02_04_000003_create_restaurant_table_management_tables',40),(83,'2026_02_04_000004_create_pos_sync_events_table',40),(84,'2026_02_04_000005_alter_pos_shifts_add_terminal_fields',40),(85,'2026_02_04_000006_alter_ar_invoices_add_pos_traceability',40),(86,'2026_02_04_000007_alter_payments_add_pos_idempotency',40),(87,'2026_02_04_000008_alter_petty_cash_expenses_add_pos_idempotency',40);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_permissions`
--

DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_permissions`
--

LOCK TABLES `model_has_permissions` WRITE;
/*!40000 ALTER TABLE `model_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `model_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `model_has_roles`
--

DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `model_has_roles`
--

LOCK TABLES `model_has_roles` WRITE;
/*!40000 ALTER TABLE `model_has_roles` DISABLE KEYS */;
INSERT INTO `model_has_roles` VALUES (1,'App\\Models\\User',1);
/*!40000 ALTER TABLE `model_has_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ops_events`
--

DROP TABLE IF EXISTS `ops_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ops_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `service_date` date DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `order_item_id` int(11) DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ops_events_branch_date_type_idx` (`branch_id`,`service_date`,`event_type`),
  KEY `ops_events_order_idx` (`order_id`),
  KEY `ops_events_order_item_fk` (`order_item_id`),
  KEY `ops_events_actor_fk` (`actor_user_id`),
  CONSTRAINT `ops_events_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `ops_events_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `ops_events_branch_id_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `ops_events_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `ops_events_order_item_fk` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ops_events`
--

LOCK TABLES `ops_events` WRITE;
/*!40000 ALTER TABLE `ops_events` DISABLE KEYS */;
INSERT INTO `ops_events` VALUES (1,'item_status_changed',1,'2026-01-29',1,1,1,'{\"from\":\"Pending\",\"to\":\"InProduction\"}','2026-01-29 20:24:24'),(2,'item_status_changed',1,'2026-01-29',1,1,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-01-29 20:24:25'),(3,'item_status_changed',1,'2026-01-29',1,1,1,'{\"from\":\"Ready\",\"to\":\"Completed\"}','2026-01-29 20:24:26'),(4,'order_status_changed',1,'2026-02-01',2,NULL,1,'{\"from\":\"Draft\",\"to\":\"Confirmed\"}','2026-02-01 09:19:09'),(5,'order_status_changed',1,'2026-02-01',2,NULL,1,'{\"from\":\"Confirmed\",\"to\":\"InProduction\"}','2026-02-01 20:02:20'),(6,'order_status_changed',1,'2026-02-01',2,NULL,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:02:22'),(7,'item_status_changed',1,'2026-02-01',2,2,1,'{\"from\":\"Pending\",\"to\":\"InProduction\"}','2026-02-01 20:02:30'),(8,'item_status_changed',1,'2026-02-01',2,2,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:02:31'),(9,'item_status_changed',1,'2026-02-01',2,2,1,'{\"from\":\"Ready\",\"to\":\"Completed\"}','2026-02-01 20:02:31'),(10,'item_status_changed',1,'2026-02-01',2,3,1,'{\"from\":\"Pending\",\"to\":\"InProduction\"}','2026-02-01 20:02:32'),(11,'item_status_changed',1,'2026-02-01',2,3,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:02:32'),(12,'item_status_changed',1,'2026-02-01',2,3,1,'{\"from\":\"Ready\",\"to\":\"Completed\"}','2026-02-01 20:02:33'),(13,'item_status_changed',1,'2026-02-01',2,4,1,'{\"from\":\"Pending\",\"to\":\"InProduction\"}','2026-02-01 20:02:33'),(14,'item_status_changed',1,'2026-02-01',2,4,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:02:34'),(15,'item_status_changed',1,'2026-02-01',2,4,1,'{\"from\":\"Ready\",\"to\":\"Completed\"}','2026-02-01 20:02:35'),(16,'order_status_changed',1,'2026-02-01',3,NULL,1,'{\"from\":\"Draft\",\"to\":\"Confirmed\"}','2026-02-01 20:13:30'),(17,'item_status_changed',1,'2026-02-01',3,5,1,'{\"from\":\"Pending\",\"to\":\"InProduction\"}','2026-02-01 20:14:29'),(18,'order_status_changed',1,'2026-02-01',3,NULL,1,'{\"from\":\"Confirmed\",\"to\":\"InProduction\"}','2026-02-01 20:14:40'),(19,'order_status_changed',1,'2026-02-01',3,NULL,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:14:42'),(20,'item_status_changed',1,'2026-02-01',3,5,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:14:49'),(21,'item_status_changed',1,'2026-02-01',3,5,1,'{\"from\":\"Ready\",\"to\":\"Completed\"}','2026-02-01 20:14:50'),(22,'item_status_changed',1,'2026-02-01',3,6,1,'{\"from\":\"Pending\",\"to\":\"InProduction\"}','2026-02-01 20:15:36'),(23,'item_status_changed',1,'2026-02-01',3,7,1,'{\"from\":\"Pending\",\"to\":\"InProduction\"}','2026-02-01 20:15:36'),(24,'item_status_changed',1,'2026-02-01',3,6,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:15:37'),(25,'item_status_changed',1,'2026-02-01',3,7,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:15:37'),(26,'item_status_changed',1,'2026-02-01',3,6,1,'{\"from\":\"Ready\",\"to\":\"Completed\"}','2026-02-01 20:15:38'),(27,'item_status_changed',1,'2026-02-01',3,7,1,'{\"from\":\"Ready\",\"to\":\"Completed\"}','2026-02-01 20:15:39'),(28,'order_status_changed',1,'2026-02-02',4,NULL,1,'{\"from\":\"Draft\",\"to\":\"Confirmed\"}','2026-02-01 20:19:12'),(29,'order_status_changed',1,'2026-02-02',4,NULL,1,'{\"from\":\"Confirmed\",\"to\":\"InProduction\"}','2026-02-01 20:21:42'),(30,'order_status_changed',1,'2026-02-02',4,NULL,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:21:43'),(31,'order_status_changed',1,'2026-02-02',6,NULL,1,'{\"from\":\"Draft\",\"to\":\"Confirmed\"}','2026-02-01 20:26:47'),(32,'order_status_changed',1,'2026-02-02',7,NULL,1,'{\"from\":\"Draft\",\"to\":\"Confirmed\"}','2026-02-01 20:35:06'),(33,'order_status_changed',1,'2026-02-01',8,NULL,1,'{\"from\":\"Draft\",\"to\":\"Confirmed\"}','2026-02-01 20:36:04'),(34,'order_status_changed',1,'2026-02-01',8,NULL,1,'{\"from\":\"Confirmed\",\"to\":\"InProduction\"}','2026-02-01 20:36:09'),(35,'order_status_changed',1,'2026-02-01',8,NULL,1,'{\"from\":\"InProduction\",\"to\":\"Ready\"}','2026-02-01 20:36:09'),(36,'order_status_changed',1,'2026-02-02',9,NULL,1,'{\"from\":\"Draft\",\"to\":\"Confirmed\"}','2026-02-01 21:27:28');
/*!40000 ALTER TABLE `ops_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `menu_item_id` int(11) NOT NULL,
  `description_snapshot` varchar(255) NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `unit_price` decimal(12,3) NOT NULL,
  `discount_amount` decimal(12,3) NOT NULL DEFAULT 0.000,
  `line_total` decimal(12,3) NOT NULL,
  `status` enum('Pending','InProduction','Ready','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `role` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_menu_item` (`menu_item_id`),
  CONSTRAINT `order_items_menu_item_fk` FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `order_items_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `order_number_sequences`
--

DROP TABLE IF EXISTS `order_number_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `order_number_sequences` (
  `year` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `next_number` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_number_sequences`
--

LOCK TABLES `order_number_sequences` WRITE;
/*!40000 ALTER TABLE `order_number_sequences` DISABLE KEYS */;
INSERT INTO `order_number_sequences` VALUES ('2026',9,'2026-01-29 20:02:33','2026-02-01 21:27:27');
/*!40000 ALTER TABLE `order_number_sequences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(30) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `source` enum('POS','Phone','WhatsApp','Subscription','Backoffice','Website') NOT NULL DEFAULT 'Backoffice',
  `is_daily_dish` tinyint(1) NOT NULL DEFAULT 0,
  `daily_dish_portion_type` varchar(20) DEFAULT NULL,
  `daily_dish_portion_quantity` int(10) unsigned DEFAULT NULL,
  `type` enum('DineIn','Takeaway','Delivery','Pastry') NOT NULL,
  `status` enum('Draft','Confirmed','InProduction','Ready','OutForDelivery','Delivered','Cancelled') NOT NULL DEFAULT 'Draft',
  `invoiced_at` timestamp NULL DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `customer_name_snapshot` varchar(255) DEFAULT NULL,
  `customer_phone_snapshot` varchar(50) DEFAULT NULL,
  `customer_email_snapshot` varchar(255) DEFAULT NULL,
  `delivery_address_snapshot` text DEFAULT NULL,
  `scheduled_date` date NOT NULL,
  `scheduled_time` time DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `order_discount_amount` decimal(10,3) NOT NULL DEFAULT 0.000,
  `total_before_tax` decimal(12,3) NOT NULL DEFAULT 0.000,
  `tax_amount` decimal(12,3) NOT NULL DEFAULT 0.000,
  `total_amount` decimal(12,3) NOT NULL DEFAULT 0.000,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_orders_order_number` (`order_number`),
  UNIQUE KEY `orders_order_number_unique` (`order_number`),
  KEY `idx_orders_scheduled_status` (`scheduled_date`,`status`),
  KEY `idx_orders_branch_date` (`branch_id`,`scheduled_date`),
  KEY `idx_orders_customer` (`customer_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `orders_branch_id_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `orders_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `orders_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_reset_tokens`
--

LOCK TABLES `password_reset_tokens` WRITE;
/*!40000 ALTER TABLE `password_reset_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_reset_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_allocations`
--

DROP TABLE IF EXISTS `payment_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_id` bigint(20) unsigned NOT NULL,
  `allocatable_type` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `allocatable_id` bigint(20) unsigned NOT NULL,
  `amount_cents` bigint(20) NOT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_allocations_payment_id_index` (`payment_id`),
  KEY `payment_allocations_allocatable_index` (`allocatable_type`,`allocatable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_allocations`
--

LOCK TABLES `payment_allocations` WRITE;
/*!40000 ALTER TABLE `payment_allocations` DISABLE KEYS */;
/*!40000 ALTER TABLE `payment_allocations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payment_terms`
--

DROP TABLE IF EXISTS `payment_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_terms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `days` int(10) unsigned NOT NULL DEFAULT 0,
  `is_credit` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_terms_credit_active_index` (`is_credit`,`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payment_terms`
--

LOCK TABLES `payment_terms` WRITE;
/*!40000 ALTER TABLE `payment_terms` DISABLE KEYS */;
INSERT INTO `payment_terms` VALUES (1,'Immediate',0,0,1,'2026-01-29 19:44:34','2026-01-29 19:44:34'),(2,'Credit - 15 days',15,1,1,'2026-01-29 19:44:34','2026-01-29 19:44:34'),(3,'Credit - 30 days',30,1,1,'2026-01-29 19:44:34','2026-01-29 19:44:34'),(4,'Credit - 45 days',45,1,1,'2026-01-29 19:44:34','2026-01-29 19:44:34');
/*!40000 ALTER TABLE `payment_terms` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `client_uuid` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `terminal_id` bigint(20) unsigned DEFAULT NULL,
  `pos_shift_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_cents` bigint(20) NOT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'QAR',
  `received_at` timestamp NULL DEFAULT NULL,
  `reference` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_client_uuid_unique` (`client_uuid`),
  KEY `payments_branch_source_received_at_index` (`branch_id`,`source`,`received_at`),
  KEY `payments_customer_received_at_index` (`customer_id`,`received_at`),
  KEY `payments_terminal_received_at_index` (`terminal_id`,`received_at`),
  KEY `payments_shift_fk` (`pos_shift_id`),
  CONSTRAINT `payments_shift_fk` FOREIGN KEY (`pos_shift_id`) REFERENCES `pos_shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_terminal_fk` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payments`
--

LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permissions`
--

LOCK TABLES `permissions` WRITE;
/*!40000 ALTER TABLE `permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `petty_cash_expenses`
--

DROP TABLE IF EXISTS `petty_cash_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_uuid` char(36) DEFAULT NULL,
  `terminal_id` bigint(20) unsigned DEFAULT NULL,
  `pos_shift_id` bigint(20) unsigned DEFAULT NULL,
  `wallet_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('draft','submitted','approved','rejected') DEFAULT 'submitted',
  `receipt_path` varchar(255) DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `petty_cash_expenses_client_uuid_unique` (`client_uuid`),
  KEY `submitted_by` (`submitted_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_petty_cash_expenses_wallet` (`wallet_id`),
  KEY `idx_petty_cash_expenses_category` (`category_id`),
  KEY `idx_petty_cash_expenses_date` (`expense_date`),
  KEY `petty_cash_expenses_wallet_id_index` (`wallet_id`),
  KEY `petty_cash_expenses_category_id_index` (`category_id`),
  KEY `petty_cash_expenses_status_index` (`status`),
  KEY `petty_cash_expenses_expense_date_index` (`expense_date`),
  KEY `petty_cash_expenses_submitted_by_index` (`submitted_by`),
  KEY `petty_cash_expenses_approved_by_index` (`approved_by`),
  KEY `petty_cash_expenses_pos_shift_id_index` (`pos_shift_id`),
  KEY `petty_cash_expenses_terminal_fk` (`terminal_id`),
  CONSTRAINT `petty_cash_expenses_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_expenses_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`),
  CONSTRAINT `petty_cash_expenses_shift_fk` FOREIGN KEY (`pos_shift_id`) REFERENCES `pos_shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_expenses_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_expenses_terminal_fk` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_expenses_wallet_id_foreign` FOREIGN KEY (`wallet_id`) REFERENCES `petty_cash_wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_expenses`
--

LOCK TABLES `petty_cash_expenses` WRITE;
/*!40000 ALTER TABLE `petty_cash_expenses` DISABLE KEYS */;
INSERT INTO `petty_cash_expenses` VALUES (1,NULL,NULL,NULL,1,1,'2025-12-10','test',100.00,0.00,100.00,'approved',NULL,1,NULL,NULL,'2025-12-10 16:46:44'),(2,NULL,NULL,NULL,1,1,'2025-12-13','test',100.00,0.00,100.00,'rejected',NULL,1,1,'2025-12-13 18:53:46','2025-12-13 19:51:57'),(3,NULL,NULL,NULL,1,2,'2025-12-13','test',15.00,0.00,15.00,'approved',NULL,1,1,'2025-12-13 19:03:24','2025-12-13 19:54:14'),(4,NULL,NULL,NULL,2,1,'2025-12-13','test',450.00,0.00,450.00,'approved',NULL,1,1,'2025-12-13 19:08:03','2025-12-13 20:07:56'),(5,NULL,NULL,NULL,2,1,'2026-01-29','Boxes',100.00,0.00,100.00,'approved',NULL,1,1,'2026-01-29 16:09:47','2026-01-29 17:09:41');
/*!40000 ALTER TABLE `petty_cash_expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `petty_cash_issues`
--

DROP TABLE IF EXISTS `petty_cash_issues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_issues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wallet_id` int(11) NOT NULL,
  `issue_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` enum('cash','card','bank_transfer','cheque','other') DEFAULT 'cash',
  `reference` varchar(100) DEFAULT NULL,
  `issued_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `issued_by` (`issued_by`),
  KEY `idx_petty_cash_issues_wallet` (`wallet_id`),
  KEY `petty_cash_issues_wallet_id_index` (`wallet_id`),
  KEY `petty_cash_issues_issue_date_index` (`issue_date`),
  KEY `petty_cash_issues_issued_by_index` (`issued_by`),
  KEY `petty_cash_issues_voided_by_fk` (`voided_by`),
  CONSTRAINT `petty_cash_issues_issued_by_foreign` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_issues_voided_by_fk` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `petty_cash_issues_wallet_id_foreign` FOREIGN KEY (`wallet_id`) REFERENCES `petty_cash_wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_issues`
--

LOCK TABLES `petty_cash_issues` WRITE;
/*!40000 ALTER TABLE `petty_cash_issues` DISABLE KEYS */;
INSERT INTO `petty_cash_issues` VALUES (1,1,'2025-12-10',1000.00,'cash','',1,'2025-12-10 16:48:21',NULL,NULL),(2,1,'2025-12-13',1000.00,'cash',NULL,1,'2025-12-13 20:03:19',NULL,NULL),(3,2,'2026-01-29',500.00,'cash',NULL,1,'2026-01-29 16:02:51',NULL,NULL),(4,1,'2026-01-29',100.00,'cash',NULL,1,'2026-01-29 17:09:23',NULL,NULL);
/*!40000 ALTER TABLE `petty_cash_issues` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `petty_cash_reconciliations`
--

DROP TABLE IF EXISTS `petty_cash_reconciliations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_reconciliations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `wallet_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `expected_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `counted_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `variance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `note` text DEFAULT NULL,
  `reconciled_by` int(11) DEFAULT NULL,
  `reconciled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reconciled_by` (`reconciled_by`),
  KEY `idx_petty_cash_recon_wallet` (`wallet_id`),
  KEY `petty_cash_reconciliations_wallet_id_index` (`wallet_id`),
  KEY `petty_cash_reconciliations_period_start_index` (`period_start`),
  KEY `petty_cash_reconciliations_period_end_index` (`period_end`),
  KEY `petty_cash_reconciliations_reconciled_by_index` (`reconciled_by`),
  KEY `petty_cash_reconciliations_voided_by_fk` (`voided_by`),
  CONSTRAINT `petty_cash_reconciliations_reconciled_by_foreign` FOREIGN KEY (`reconciled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `petty_cash_reconciliations_voided_by_fk` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `petty_cash_reconciliations_wallet_id_foreign` FOREIGN KEY (`wallet_id`) REFERENCES `petty_cash_wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_reconciliations`
--

LOCK TABLES `petty_cash_reconciliations` WRITE;
/*!40000 ALTER TABLE `petty_cash_reconciliations` DISABLE KEYS */;
INSERT INTO `petty_cash_reconciliations` VALUES (1,1,'2025-12-13','2025-12-13',985.00,900.00,-85.00,NULL,1,'2025-12-13 19:06:01',NULL,NULL),(2,2,'2025-12-13','2025-12-13',550.00,500.00,-50.00,NULL,1,'2025-12-13 19:08:17',NULL,NULL);
/*!40000 ALTER TABLE `petty_cash_reconciliations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `petty_cash_wallets`
--

DROP TABLE IF EXISTS `petty_cash_wallets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_wallets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `driver_id` int(11) NOT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `target_float` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_wallet_driver` (`driver_id`),
  KEY `created_by` (`created_by`),
  KEY `petty_cash_wallets_driver_id_index` (`driver_id`),
  KEY `petty_cash_wallets_active_index` (`active`),
  CONSTRAINT `petty_cash_wallets_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_wallets`
--

LOCK TABLES `petty_cash_wallets` WRITE;
/*!40000 ALTER TABLE `petty_cash_wallets` DISABLE KEYS */;
INSERT INTO `petty_cash_wallets` VALUES (1,1,'Test',500.00,1000.00,1,1,'2025-12-10 16:16:02'),(2,2,'Michel',1000.00,900.00,1,1,'2025-12-13 20:07:40');
/*!40000 ALTER TABLE `petty_cash_wallets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_document_sequences`
--

DROP TABLE IF EXISTS `pos_document_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_document_sequences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `terminal_id` bigint(20) unsigned NOT NULL,
  `business_date` date NOT NULL,
  `last_number` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_doc_seq_terminal_date_unique` (`terminal_id`,`business_date`),
  KEY `pos_doc_seq_business_date_index` (`business_date`),
  CONSTRAINT `pos_doc_seq_terminal_fk` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_document_sequences`
--

LOCK TABLES `pos_document_sequences` WRITE;
/*!40000 ALTER TABLE `pos_document_sequences` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos_document_sequences` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_shifts`
--

DROP TABLE IF EXISTS `pos_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_shifts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `terminal_id` bigint(20) unsigned DEFAULT NULL,
  `device_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `opening_cash_cents` bigint(20) NOT NULL DEFAULT 0,
  `closing_cash_cents` bigint(20) DEFAULT NULL,
  `expected_cash_cents` bigint(20) DEFAULT NULL,
  `variance_cents` bigint(20) DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_shifts_one_active_per_user_branch` (`branch_id`,`user_id`,`active`),
  KEY `pos_shifts_branch_status_index` (`branch_id`,`status`),
  KEY `pos_shifts_user_status_index` (`user_id`,`status`),
  KEY `pos_shifts_opened_at_index` (`opened_at`),
  KEY `pos_shifts_terminal_status_index` (`terminal_id`,`status`),
  CONSTRAINT `pos_shifts_terminal_fk` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_shifts`
--

LOCK TABLES `pos_shifts` WRITE;
/*!40000 ALTER TABLE `pos_shifts` DISABLE KEYS */;
INSERT INTO `pos_shifts` VALUES (1,1,NULL,NULL,1,NULL,'closed',100000,0,100000,-100000,'2026-01-29 17:35:14','2026-01-29 18:02:45',NULL,1,1,'2026-01-29 17:35:14','2026-01-29 18:02:45'),(2,1,NULL,NULL,1,NULL,'closed',0,0,0,0,'2026-01-29 18:02:47','2026-01-29 18:14:54',NULL,1,1,'2026-01-29 18:02:47','2026-01-29 18:14:54'),(3,2,NULL,NULL,1,1,'open',0,NULL,NULL,NULL,'2026-01-29 18:14:57',NULL,NULL,1,NULL,'2026-01-29 18:14:57','2026-01-29 18:14:57');
/*!40000 ALTER TABLE `pos_shifts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_sync_events`
--

DROP TABLE IF EXISTS `pos_sync_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_sync_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `terminal_id` bigint(20) unsigned NOT NULL,
  `event_id` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `server_entity_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `server_entity_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'applied',
  `applied_at` timestamp NULL DEFAULT NULL,
  `error_code` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_sync_events_client_uuid_unique` (`client_uuid`),
  UNIQUE KEY `pos_sync_events_terminal_event_unique` (`terminal_id`,`event_id`),
  KEY `pos_sync_events_terminal_type_applied_index` (`terminal_id`,`type`,`applied_at`),
  CONSTRAINT `pos_sync_events_terminal_fk` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_sync_events`
--

LOCK TABLES `pos_sync_events` WRITE;
/*!40000 ALTER TABLE `pos_sync_events` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos_sync_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pos_terminals`
--

DROP TABLE IF EXISTS `pos_terminals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_terminals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pos_terminals_branch_code_unique` (`branch_id`,`code`),
  UNIQUE KEY `pos_terminals_device_id_unique` (`device_id`),
  KEY `pos_terminals_branch_active_index` (`branch_id`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pos_terminals`
--

LOCK TABLES `pos_terminals` WRITE;
/*!40000 ALTER TABLE `pos_terminals` DISABLE KEYS */;
/*!40000 ALTER TABLE `pos_terminals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_order_items`
--

DROP TABLE IF EXISTS `purchase_order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `quantity` decimal(12,3) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `total_price` decimal(10,2) DEFAULT 0.00,
  `received_quantity` decimal(12,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `item_id` (`item_id`),
  KEY `purchase_order_items_purchase_order_id_index` (`purchase_order_id`),
  KEY `purchase_order_items_item_id_index` (`item_id`),
  CONSTRAINT `po_items_item_fk` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `po_items_po_fk` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `purchase_order_items_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_order_items_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_items`
--

LOCK TABLES `purchase_order_items` WRITE;
/*!40000 ALTER TABLE `purchase_order_items` DISABLE KEYS */;
INSERT INTO `purchase_order_items` VALUES (1,1,4,1.000,89.00,89.00,1.000,'2025-12-07 13:42:36'),(2,3,239,10.000,49.00,490.00,10.000,'2025-12-12 20:59:53'),(3,3,241,10.000,40.00,400.00,10.000,'2025-12-12 20:59:53'),(4,4,242,6.000,30.00,180.00,6.000,'2025-12-12 22:05:57'),(5,5,161,10.000,45.00,450.00,10.000,'2025-12-13 09:51:12');
/*!40000 ALTER TABLE `purchase_order_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `purchase_orders`
--

DROP TABLE IF EXISTS `purchase_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(50) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `order_date` date DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `status` enum('draft','pending','approved','received','cancelled') DEFAULT 'draft',
  `total_amount` decimal(10,2) DEFAULT 0.00,
  `received_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `payment_terms` varchar(255) DEFAULT NULL,
  `payment_type` varchar(100) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `created_by` (`created_by`),
  KEY `purchase_orders_supplier_id_index` (`supplier_id`),
  KEY `purchase_orders_status_index` (`status`),
  KEY `purchase_orders_order_date_index` (`order_date`),
  KEY `purchase_orders_created_by_index` (`created_by`),
  CONSTRAINT `purchase_orders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
INSERT INTO `purchase_orders` VALUES (1,'1234567',1,'2025-12-07','2025-12-30','received',89.00,'2025-12-07','',NULL,NULL,1,'2025-12-07 13:42:36','2025-12-07 19:30:45'),(3,'PO-1234568',33,'2025-12-12','2025-12-14','received',890.00,'2025-12-12',NULL,'Credit','Cheque',1,'2025-12-12 19:59:53','2025-12-12 20:04:20'),(4,'PO-1234569',33,'2025-12-13','2025-12-18','received',180.00,'2025-12-12',NULL,'Credit','Bank Transfer',1,'2025-12-12 21:05:57','2025-12-12 21:06:07'),(5,'PO-1234570',32,'2025-12-13','2025-12-14','received',450.00,'2025-12-13',NULL,'Credit','Bank Transfer',1,'2025-12-13 08:51:12','2025-12-13 08:51:55');
/*!40000 ALTER TABLE `purchase_orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recipe_items`
--

DROP TABLE IF EXISTS `recipe_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipe_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipe_id` int(11) NOT NULL,
  `inventory_item_id` int(11) NOT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `quantity_type` enum('unit','package') DEFAULT 'unit',
  `cost_type` enum('ingredient','packaging','labour','transport','other') DEFAULT 'ingredient',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `recipe_id` (`recipe_id`),
  KEY `inventory_item_id` (`inventory_item_id`),
  KEY `recipe_items_recipe_id_index` (`recipe_id`),
  KEY `recipe_items_inventory_item_id_index` (`inventory_item_id`),
  KEY `recipe_items_cost_type_index` (`cost_type`),
  CONSTRAINT `recipe_items_inventory_item_id_foreign` FOREIGN KEY (`inventory_item_id`) REFERENCES `inventory_items` (`id`),
  CONSTRAINT `recipe_items_recipe_id_foreign` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recipe_items`
--

LOCK TABLES `recipe_items` WRITE;
/*!40000 ALTER TABLE `recipe_items` DISABLE KEYS */;
INSERT INTO `recipe_items` VALUES (17,1,254,5.000,'KG','unit','ingredient','2025-12-06 08:09:14','2025-12-06 08:09:14'),(18,1,255,11.000,'KG','unit','ingredient','2025-12-06 08:09:14','2025-12-06 08:09:14'),(19,1,1,14.000,'EA','unit','ingredient','2025-12-06 08:09:14','2025-12-06 08:09:14'),(20,1,73,0.002,'KG','unit','ingredient','2025-12-06 08:09:14','2025-12-06 08:09:14'),(21,2,277,2.000,'Pcs','unit','packaging','2026-01-29 06:39:37','2026-01-29 06:39:37');
/*!40000 ALTER TABLE `recipe_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recipe_productions`
--

DROP TABLE IF EXISTS `recipe_productions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipe_productions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipe_id` int(11) NOT NULL,
  `produced_quantity` decimal(10,3) NOT NULL,
  `production_date` datetime DEFAULT current_timestamp(),
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `recipe_id` (`recipe_id`),
  KEY `created_by` (`created_by`),
  KEY `recipe_productions_recipe_id_index` (`recipe_id`),
  KEY `recipe_productions_production_date_index` (`production_date`),
  KEY `recipe_productions_created_by_index` (`created_by`),
  CONSTRAINT `recipe_productions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `recipe_productions_recipe_id_foreign` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recipe_productions`
--

LOCK TABLES `recipe_productions` WRITE;
/*!40000 ALTER TABLE `recipe_productions` DISABLE KEYS */;
INSERT INTO `recipe_productions` VALUES (1,2,10.000,'2026-01-29 07:47:00','test','test',1,'2026-01-29 07:47:44');
/*!40000 ALTER TABLE `recipe_productions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recipes`
--

DROP TABLE IF EXISTS `recipes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `recipes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `yield_quantity` decimal(10,3) NOT NULL,
  `yield_unit` varchar(50) NOT NULL,
  `overhead_pct` decimal(6,2) NOT NULL DEFAULT 0.00,
  `selling_price_per_unit` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `recipes_category_id_index` (`category_id`),
  KEY `recipes_name_index` (`name`),
  CONSTRAINT `recipes_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recipes`
--

LOCK TABLES `recipes` WRITE;
/*!40000 ALTER TABLE `recipes` DISABLE KEYS */;
INSERT INTO `recipes` VALUES (1,'Chicken Curry','',1,55.000,'Box',0.12,12.00,'2025-12-05 14:34:59','2025-12-06 08:09:14'),(2,'test',NULL,9,10.000,'Dish',12.00,55.00,'2026-01-29 06:39:37','2026-01-29 06:39:37');
/*!40000 ALTER TABLE `recipes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `restaurant_areas`
--

DROP TABLE IF EXISTS `restaurant_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `restaurant_areas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `restaurant_areas_branch_active_index` (`branch_id`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `restaurant_areas`
--

LOCK TABLES `restaurant_areas` WRITE;
/*!40000 ALTER TABLE `restaurant_areas` DISABLE KEYS */;
/*!40000 ALTER TABLE `restaurant_areas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `restaurant_table_sessions`
--

DROP TABLE IF EXISTS `restaurant_table_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `restaurant_table_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `table_id` bigint(20) unsigned NOT NULL,
  `status` enum('open','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `opened_by` bigint(20) unsigned DEFAULT NULL,
  `device_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `terminal_id` bigint(20) unsigned DEFAULT NULL,
  `pos_shift_id` bigint(20) unsigned DEFAULT NULL,
  `opened_at` datetime NOT NULL,
  `closed_at` datetime DEFAULT NULL,
  `guests` int(10) unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active_table_id` bigint(20) unsigned GENERATED ALWAYS AS (if(`active` = 1,`table_id`,NULL)) STORED,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `restaurant_table_sessions_one_active_per_table` (`active_table_id`),
  KEY `restaurant_table_sessions_branch_status_index` (`branch_id`,`status`),
  KEY `restaurant_table_sessions_terminal_status_index` (`terminal_id`,`status`),
  KEY `restaurant_table_sessions_table_fk` (`table_id`),
  KEY `restaurant_table_sessions_shift_fk` (`pos_shift_id`),
  CONSTRAINT `restaurant_table_sessions_shift_fk` FOREIGN KEY (`pos_shift_id`) REFERENCES `pos_shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `restaurant_table_sessions_table_fk` FOREIGN KEY (`table_id`) REFERENCES `restaurant_tables` (`id`) ON DELETE CASCADE,
  CONSTRAINT `restaurant_table_sessions_terminal_fk` FOREIGN KEY (`terminal_id`) REFERENCES `pos_terminals` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `restaurant_table_sessions`
--

LOCK TABLES `restaurant_table_sessions` WRITE;
/*!40000 ALTER TABLE `restaurant_table_sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `restaurant_table_sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `restaurant_tables`
--

DROP TABLE IF EXISTS `restaurant_tables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `restaurant_tables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `area_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `capacity` int(10) unsigned DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `restaurant_tables_branch_code_unique` (`branch_id`,`code`),
  KEY `restaurant_tables_branch_active_index` (`branch_id`,`active`),
  KEY `restaurant_tables_area_order_index` (`area_id`,`display_order`),
  CONSTRAINT `restaurant_tables_area_fk` FOREIGN KEY (`area_id`) REFERENCES `restaurant_areas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `restaurant_tables`
--

LOCK TABLES `restaurant_tables` WRITE;
/*!40000 ALTER TABLE `restaurant_tables` DISABLE KEYS */;
/*!40000 ALTER TABLE `restaurant_tables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_has_permissions`
--

DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_has_permissions`
--

LOCK TABLES `role_has_permissions` WRITE;
/*!40000 ALTER TABLE `role_has_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_has_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `guard_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'admin','web','2025-12-12 15:32:12','2025-12-12 15:32:12');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sale_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` bigint(20) unsigned NOT NULL,
  `sellable_type` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sellable_id` bigint(20) unsigned DEFAULT NULL,
  `name_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku_snapshot` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_rate_bps` int(10) unsigned NOT NULL DEFAULT 0,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `unit_price_cents` bigint(20) NOT NULL DEFAULT 0,
  `discount_cents` bigint(20) NOT NULL DEFAULT 0,
  `discount_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `discount_value` bigint(20) NOT NULL DEFAULT 0,
  `tax_cents` bigint(20) NOT NULL DEFAULT 0,
  `line_total_cents` bigint(20) NOT NULL DEFAULT 0,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_items_sale_id_index` (`sale_id`),
  KEY `sale_items_sellable_index` (`sellable_type`,`sellable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_items`
--

LOCK TABLES `sale_items` WRITE;
/*!40000 ALTER TABLE `sale_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `sale_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `branch_id` int(10) unsigned NOT NULL,
  `pos_shift_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `sale_number` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `order_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'QAR',
  `subtotal_cents` bigint(20) NOT NULL DEFAULT 0,
  `discount_total_cents` bigint(20) NOT NULL DEFAULT 0,
  `global_discount_cents` bigint(20) NOT NULL DEFAULT 0,
  `global_discount_type` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fixed',
  `global_discount_value` bigint(20) NOT NULL DEFAULT 0,
  `is_credit` tinyint(1) NOT NULL DEFAULT 0,
  `pos_date` date DEFAULT NULL,
  `credit_invoice_id` bigint(20) unsigned DEFAULT NULL,
  `tax_total_cents` bigint(20) NOT NULL DEFAULT 0,
  `total_cents` bigint(20) NOT NULL DEFAULT 0,
  `paid_total_cents` bigint(20) NOT NULL DEFAULT 0,
  `due_total_cents` bigint(20) NOT NULL DEFAULT 0,
  `held_at` timestamp NULL DEFAULT NULL,
  `held_by` bigint(20) unsigned DEFAULT NULL,
  `kot_printed_at` timestamp NULL DEFAULT NULL,
  `kot_printed_by` bigint(20) unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pos_reference` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sales_branch_sale_number_unique` (`branch_id`,`sale_number`),
  KEY `sales_branch_status_created_at_index` (`branch_id`,`status`,`created_at`),
  KEY `sales_customer_status_index` (`customer_id`,`status`),
  KEY `sales_pos_shift_id_index` (`pos_shift_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales`
--

LOCK TABLES `sales` WRITE;
/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
/*!40000 ALTER TABLE `sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('bB8lzafTWivTGUwX5b9tPAVU1x0ma6I2P1t9HdiR',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','YTo0OntzOjY6Il90b2tlbiI7czo0MDoiMDQ0VUlxRGZmUUw1VWtvQkM0M0FFMktKeXV2ZThwdTZqeHR3ODZBSyI7czo1MDoibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MTtzOjk6Il9wcmV2aW91cyI7YToyOntzOjM6InVybCI7czoyODoiaHR0cDovL2xvY2FsaG9zdDo4MDAwL29yZGVycyI7czo1OiJyb3V0ZSI7czoxMjoib3JkZXJzLmluZGV4Ijt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319fQ==',1766334308),('niVOCuABvbR3SxMDKzdVWsFLUnf4pEdVQxY1ymeu',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Cursor/2.2.43 Chrome/138.0.7204.251 Electron/37.7.0 Safari/537.36','YTo0OntzOjY6Il90b2tlbiI7czo0MDoiUWRnNW5yNGd6OXJGOWdJOUFZcldneUJjcVFlbFpWMFhPR0lBZUcxdCI7czo5OiJfcHJldmlvdXMiO2E6Mjp7czozOiJ1cmwiO3M6NDY6Imh0dHA6Ly8xMjcuMC4wLjE6ODAwMC9raXRjaGVuL29wcy8xLzIwMjUtMTItMjEiO3M6NToicm91dGUiO3M6MTE6ImtpdGNoZW4ub3BzIjt9czo2OiJfZmxhc2giO2E6Mjp7czozOiJvbGQiO2E6MDp7fXM6MzoibmV3IjthOjA6e319czo1MDoibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MTt9',1766325771),('tmUop0xpp1iVBz6ZYLmTXd7jO0BdbftYLZP3IqVO',1,'127.0.0.1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36','YTo0OntzOjY6Il90b2tlbiI7czo0MDoiMEpia01xT3g0NUVuaWROU1YzR0N0NVVOUUZuV0RzSlZMOFAwYkZDSyI7czo1MDoibG9naW5fd2ViXzU5YmEzNmFkZGMyYjJmOTQwMTU4MGYwMTRjN2Y1OGVhNGUzMDk4OWQiO2k6MTtzOjk6Il9wcmV2aW91cyI7YToyOntzOjM6InVybCI7czo0NjoiaHR0cDovL2xvY2FsaG9zdDo4MDAwL2tpdGNoZW4vb3BzLzEvMjAyNS0xMi0yMSI7czo1OiJyb3V0ZSI7czoxMToia2l0Y2hlbi5vcHMiO31zOjY6Il9mbGFzaCI7YToyOntzOjM6Im9sZCI7YTowOnt9czozOiJuZXciO2E6MDp7fX19',1766346248);
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subledger_entries`
--

DROP TABLE IF EXISTS `subledger_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subledger_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `event` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entry_date` date NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'posted',
  `posted_at` timestamp NULL DEFAULT NULL,
  `posted_by` int(11) DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subledger_entries_source_type_source_id_event_unique` (`source_type`,`source_id`,`event`),
  KEY `subledger_entries_source_type_source_id_index` (`source_type`,`source_id`),
  KEY `subledger_entries_entry_date_index` (`entry_date`),
  KEY `subledger_entries_posted_by_foreign` (`posted_by`),
  KEY `subledger_entries_voided_by_foreign` (`voided_by`),
  KEY `subledger_entries_branch_id_foreign` (`branch_id`),
  CONSTRAINT `subledger_entries_branch_id_foreign` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subledger_entries_posted_by_foreign` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subledger_entries_voided_by_foreign` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subledger_entries`
--

LOCK TABLES `subledger_entries` WRITE;
/*!40000 ALTER TABLE `subledger_entries` DISABLE KEYS */;
/*!40000 ALTER TABLE `subledger_entries` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subledger_lines`
--

DROP TABLE IF EXISTS `subledger_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subledger_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entry_id` bigint(20) unsigned NOT NULL,
  `account_id` bigint(20) unsigned NOT NULL,
  `debit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `credit` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `memo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `subledger_lines_entry_id_account_id_index` (`entry_id`,`account_id`),
  KEY `subledger_lines_account_id_foreign` (`account_id`),
  CONSTRAINT `subledger_lines_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `ledger_accounts` (`id`),
  CONSTRAINT `subledger_lines_entry_id_foreign` FOREIGN KEY (`entry_id`) REFERENCES `subledger_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subledger_lines`
--

LOCK TABLES `subledger_lines` WRITE;
/*!40000 ALTER TABLE `subledger_lines` DISABLE KEYS */;
/*!40000 ALTER TABLE `subledger_lines` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscription_order_run_errors`
--

DROP TABLE IF EXISTS `subscription_order_run_errors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_order_run_errors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) unsigned NOT NULL,
  `subscription_id` bigint(20) unsigned DEFAULT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sub_order_run_errors_run_idx` (`run_id`),
  KEY `sub_order_run_errors_sub_fk` (`subscription_id`),
  CONSTRAINT `sub_order_run_errors_run_fk` FOREIGN KEY (`run_id`) REFERENCES `subscription_order_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `sub_order_run_errors_sub_fk` FOREIGN KEY (`subscription_id`) REFERENCES `meal_subscriptions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_order_run_errors`
--

LOCK TABLES `subscription_order_run_errors` WRITE;
/*!40000 ALTER TABLE `subscription_order_run_errors` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscription_order_run_errors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subscription_order_runs`
--

DROP TABLE IF EXISTS `subscription_order_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_order_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `service_date` date NOT NULL,
  `branch_id` int(11) NOT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `created_count` int(11) NOT NULL DEFAULT 0,
  `skipped_existing_count` int(11) NOT NULL DEFAULT 0,
  `skipped_no_menu_count` int(11) NOT NULL DEFAULT 0,
  `skipped_no_items_count` int(11) NOT NULL DEFAULT 0,
  `error_summary` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sub_order_runs_branch_date_status_idx` (`branch_id`,`service_date`,`status`),
  KEY `sub_order_runs_date_branch_idx` (`service_date`,`branch_id`),
  KEY `sub_order_runs_created_by_fk` (`created_by`),
  CONSTRAINT `sub_order_runs_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `sub_order_runs_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `subscription_order_runs_branch_id_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subscription_order_runs`
--

LOCK TABLES `subscription_order_runs` WRITE;
/*!40000 ALTER TABLE `subscription_order_runs` DISABLE KEYS */;
/*!40000 ALTER TABLE `subscription_order_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `qid_cr` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `suppliers_status_index` (`status`),
  KEY `suppliers_name_index` (`name`),
  KEY `suppliers_contact_person_index` (`contact_person`),
  KEY `suppliers_email_index` (`email`),
  KEY `suppliers_phone_index` (`phone`),
  KEY `suppliers_qid_cr_index` (`qid_cr`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'TechSupplies Inc.','John Smith','john@techsupplies.com','+1-555-123-4567','123 Tech Street, Silicon Valley, CA',NULL,'active','2025-10-29 09:51:50','2025-10-29 09:51:50'),(2,'Office Essentials','Mary Johnson','mary@officeessentials.com','+1-555-987-6543','456 Office Blvd, New York, NY',NULL,'active','2025-10-29 09:51:50','2025-10-29 09:51:50'),(3,'Maintenance Pro','Robert Brown','robert@maintenancepro.com','+1-555-456-7890','789 Tool Ave, Chicago, IL',NULL,'active','2025-10-29 09:51:50','2025-10-29 09:51:50'),(4,'Delta group',NULL,'deltagroup_marketing@deltaco.com.qa',NULL,NULL,'1175205','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(5,'Delta group',NULL,'deltagroup_marketing@deltaco.com.qa',NULL,'Doha','1175205','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(6,'Verde W.l.l.','66868306','roy@verde.qa','66868306','Doha','167979','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(7,'Al Majed Marketing & Distribution','70492285','businessdevelopment@almajedgroup.me','70492285','Doha','1175719','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(8,'Al Maktab Al Qatari Al Hollandi','33813582','food@hollandi.com','33813582','Doha','1175721','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(9,'Yacoob Trading & Contracting','55502705','hsayeh@yatco-qatar.com','55502705','Doha','1175722','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(10,'Gulf Center','59918445','r.basheer@gcfsqatar.com','59918445','Doha','1175723','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(11,'Happy Land Trading & Marketing','55212992','sales@happylandqatar.com','55212992','Doha','1175724','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(12,'Caramel','70473565','info@carameldoha.com','70473565','Doha','1175725','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(13,'Bluefin','66969615','sergio.berbari@bluefinqa.com','66969615',NULL,'1175726','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(14,'Watania W.L.L',NULL,'info@wataniafire.com',NULL,'Doha','1176011','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(15,'Deep Seafood',NULL,NULL,NULL,'Doha','1176110','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(16,'Al Zaman International Catering & Trading','55946999','sales@alzamantrading.com','55946999','Doha','1176307','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(17,'Tesla International Group Contracting & Trading w.l.l.',NULL,NULL,NULL,'Doha','1176443','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(18,'Ideal Qatar',NULL,NULL,NULL,'Doha','1176490','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(19,'Flamex Trade',NULL,NULL,NULL,'Doha','1176560','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(20,'Fresh Meat Factory',NULL,NULL,NULL,'Doha','1176702','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(21,'Awafia Trading W.L.L',NULL,NULL,NULL,'Doha','1177407','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(22,'Tredos Trading',NULL,'ameen@tredostrading.com',NULL,'Doha','1177621','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(23,'Benina Food',NULL,NULL,NULL,'Doha','1177867','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(24,'Qnited Trading Company',NULL,NULL,NULL,'Doha','1178137','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(25,'Packon Trading',NULL,NULL,NULL,'Doha','1178149','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(26,'Fahed Foods w.l.l.',NULL,NULL,NULL,'Doha','1178452','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(27,'BRF',NULL,NULL,NULL,'Doha','1178528','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(28,'Pilot Parties Processing',NULL,NULL,NULL,'Doha','110689','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(29,'Valencia International Trading Company w.l.l.',NULL,NULL,NULL,'Doha','1178929','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(30,'RealPack',NULL,NULL,NULL,'Doha','1179020','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(31,'Friendly Food Qatar w.l.l.',NULL,NULL,NULL,'Doha','1179056','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(32,'Al Hattab For Food Stuffs & Trading',NULL,NULL,NULL,'Doha','1179796','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(33,'Al Rayes Laundry Equipment & Accessories w.l.l.',NULL,NULL,NULL,'Doha','1180027','active','2025-12-10 20:45:16','2025-12-10 20:45:16'),(34,'International Foodstuff Group',NULL,NULL,NULL,'Doha','1180211','active','2025-12-10 20:45:16','2025-12-10 20:45:16');
/*!40000 ALTER TABLE `suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `users_username_unique` (`username`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','','$2y$12$rrWmqICIihad1/vmQiC5m.Cg7zJ80dI7iZdvKsvpSw/mgaOsu0XMe','sJgwLhkyYrRnk1i5s7FDnZEJLFS8srA0YR7aTrxFhW8uZrxDDVbTNOSu3JSL','admin@example.com',NULL,'active',NULL,NULL,NULL,'2025-10-29 09:51:18',NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `work_orders`
--

DROP TABLE IF EXISTS `work_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `work_order_number` varchar(50) NOT NULL,
  `asset_id` int(11) DEFAULT NULL,
  `maintenance_schedule_id` int(11) DEFAULT NULL,
  `maintenance_type` enum('preventive','corrective','emergency') DEFAULT 'preventive',
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `description` text DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled') DEFAULT 'pending',
  `scheduled_date` date DEFAULT NULL,
  `completed_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `work_order_number` (`work_order_number`),
  KEY `asset_id` (`asset_id`),
  KEY `maintenance_schedule_id` (`maintenance_schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `work_orders`
--

LOCK TABLES `work_orders` WRITE;
/*!40000 ALTER TABLE `work_orders` DISABLE KEYS */;
/*!40000 ALTER TABLE `work_orders` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-02-04 14:08:50
