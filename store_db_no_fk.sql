-- MySQL dump 10.13  Distrib 8.0.43, for Win64 (x86_64)
--
-- Host: localhost    Database: store
-- ------------------------------------------------------
-- Server version	5.5.5-10.4.32-MariaDB

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
  KEY `invoice_id` (`invoice_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_invoice_items`
--

LOCK TABLES `ap_invoice_items` WRITE;
/*!40000 ALTER TABLE `ap_invoice_items` DISABLE KEYS */;
INSERT INTO `ap_invoice_items` VALUES (1,1,'ITEM-004 - Aluminum Container - 1120 (400pcs)',1.000,89.0000,89.00,'2025-12-07 19:41:39');
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
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_supplier_invoice` (`supplier_id`,`invoice_number`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_ap_invoices_supplier` (`supplier_id`),
  KEY `idx_ap_invoices_due` (`due_date`),
  KEY `idx_ap_invoices_category` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_invoices`
--

LOCK TABLES `ap_invoices` WRITE;
/*!40000 ALTER TABLE `ap_invoices` DISABLE KEYS */;
INSERT INTO `ap_invoices` VALUES (1,1,NULL,0,1,'123444','2025-12-07','2025-12-25',89.00,0.00,89.00,'paid','',1,'2025-12-07 19:41:39','2025-12-07 20:20:52');
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_payment_invoice` (`payment_id`,`invoice_id`),
  KEY `invoice_id` (`invoice_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_payment_allocations`
--

LOCK TABLES `ap_payment_allocations` WRITE;
/*!40000 ALTER TABLE `ap_payment_allocations` DISABLE KEYS */;
INSERT INTO `ap_payment_allocations` VALUES (1,1,1,40.00,'2025-12-07 20:14:51'),(3,3,1,49.00,'2025-12-07 20:20:52');
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_ap_payments_supplier` (`supplier_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ap_payments`
--

LOCK TABLES `ap_payments` WRITE;
/*!40000 ALTER TABLE `ap_payments` DISABLE KEYS */;
INSERT INTO `ap_payments` VALUES (1,1,'2025-12-07',40.00,'bank_transfer','test','',1,'2025-12-07 20:14:51'),(3,1,'2025-12-07',49.00,'cheque','QIB000151','',1,'2025-12-07 20:20:52');
/*!40000 ALTER TABLE `ap_payments` ENABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assets`
--

LOCK TABLES `assets` WRITE;
/*!40000 ALTER TABLE `assets` DISABLE KEYS */;
/*!40000 ALTER TABLE `assets` ENABLE KEYS */;
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
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Raw Materials','',NULL,'2025-10-29 10:51:36'),(2,'Packaging and Accessories','Packaging and Accessories',NULL,'2025-10-29 10:51:36'),(3,'Maintenance','Maintenance tools and materials',NULL,'2025-10-29 10:51:36'),(4,'IT Equipment','Computers, servers, and networking equipment',NULL,'2025-10-29 10:51:36'),(5,'Furniture','Office furniture and fixtures',NULL,'2025-10-29 10:51:36');
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_customers_name` (`name`),
  KEY `idx_customers_phone` (`phone`),
  KEY `idx_customers_type` (`customer_type`),
  KEY `idx_customers_is_active` (`is_active`),
  KEY `idx_customers_payment` (`default_payment_method_id`)
) ENGINE=InnoDB AUTO_INCREMENT=447 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
INSERT INTO `customers` VALUES (1,'100001','GHADA MAALOUF','retail',NULL,'9613960175',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(2,'100002','jackie','retail',NULL,'97455872034',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(3,'100003','Rana Abilmona','retail',NULL,'97470507859',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(4,'100004','VICKY','retail',NULL,'55784194',NULL,NULL,NULL,'Qatar',NULL,3000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(5,'100005','Joyce','retail',NULL,'66543637',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',0,NULL,'2025-12-10 22:23:42',NULL),(6,'100006','ABIR','retail',NULL,'66688230',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(7,'100007','PIA','retail',NULL,'66458801',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(8,'100008','TALAR','retail',NULL,'74770018',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(9,'100009','NADA','retail',NULL,'55341850',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(10,'100010','DIALA','retail',NULL,'55776288',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(11,'100011','NABIL','retail',NULL,'33447353',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(12,'100012','SAMIRA','retail',NULL,'96171194185',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(13,'100013','RAMA Kana','retail',NULL,'66334998',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(14,'100014','MARAH','retail',NULL,'33953636',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(15,'100015','CYNTHIA','retail',NULL,'55537909',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(16,'100016','NANCY','retail',NULL,'55072237',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(17,'100017','MANAL','retail',NULL,'96170578155',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(18,'100018','CARLA TARRAF','retail',NULL,'66594433',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(19,'100019','MARK Chidiac','retail',NULL,'55132686',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(20,'100020','MOUNIRA','retail',NULL,'55363500',NULL,NULL,NULL,'Qatar',NULL,2000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(21,'100021','CARINE','retail',NULL,'33097059',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',0,NULL,'2025-12-10 22:23:42',NULL),(22,'100022','DALAL','retail',NULL,'55602663',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(23,'100023','Alex','retail',NULL,'50632385',NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(24,'100024','Amanda','retail',NULL,'66033398',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(25,'100025','Rouba El Khoury','retail',NULL,'55895004',NULL,NULL,NULL,NULL,NULL,3000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(26,'100026','Dory','retail',NULL,'66446635',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(27,'100027','Janet Chammas','retail',NULL,'97466264633',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(28,'100028','Romy Sengakis','retail',NULL,'97433062444',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(29,'100029','Marie Helene','retail',NULL,'9613868360',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(30,'100030','Bettina Hanna','retail',NULL,'97433839507',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(31,'100031','Emilie Bejjani','retail',NULL,'97452037491',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(32,'100032','Mirna Salem','retail',NULL,'97455862393',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(33,'100033','Carole Azar','retail',NULL,'97470364393',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(34,'100034','Janine','retail',NULL,'55231716',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(35,'100035','Carla Hanna','retail',NULL,'55063368',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(36,'100036','Wissam Hajj','retail',NULL,'33654184',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(37,'100037','Toni Ghanem','retail',NULL,'33747739',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(38,'100038','Emilie Bejjani','retail',NULL,'52037491',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'Credit OK',0,NULL,'2025-12-10 22:23:42',NULL),(39,'100039','Aline Ghassan','retail',NULL,'33156655',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(40,'100040','Rita Chedid','retail',NULL,'30269268',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(41,'100041','Laudy Samaha','retail',NULL,'33199646',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(42,'100042','Yasmine Hasan','retail',NULL,'50357199',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(43,'100043','Mira Chaccour','retail',NULL,'66858975',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(44,'100044','Micheline Feghaly','retail',NULL,'55669625',NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(45,'100045','Layla Al Helou','retail',NULL,'77557753',NULL,NULL,NULL,'Qatar',NULL,3000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(46,'100046','Leila Al Helou','retail',NULL,'97477557753',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(47,'100047','Lina Mchantaf','retail',NULL,'97433685859',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(48,'100048','Remonde Abi Saleh','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(49,'100049','Rita Rahme','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(50,'100050','Nelly Frangieh','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(51,'100051','Dolly Matar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(52,'100052','Karen ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(53,'100053','Dalia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(54,'100054','Carla Kayrouz','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(55,'100055','Carmen Jarrouj','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(56,'100056','Cesar Touma','retail',NULL,'30321507',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(57,'100057','Stephanie Rahme ','retail',NULL,'96170191043',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(58,'100058','Paul Abou Rjeily','retail',NULL,'55096937',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(59,'100059','Zeina Khoury','retail',NULL,'66610276',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(60,'100060','Hiba Kayal','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(61,'100061','Nisreen','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(62,'100062','Jamil ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(63,'100063','Linda Issa','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(64,'100064','Syed Muzammil','retail',NULL,'55004296',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(65,'100065','Mireille Khoury','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(66,'100066','Ghada El Rassi','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(67,'100067','Joyce Riyachi','retail',NULL,'66536920',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(68,'100068','Nancy Wehbe','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(69,'100069','Rana Deeb','retail',NULL,'55878767',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(70,'100070','Nahed ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(71,'100071','Pamela','retail',NULL,'66469523',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(72,'100072','Mahran','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(73,'100073','Shahil','retail',NULL,'70044812',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(74,'100074','DG JONES','retail',NULL,'55878767',NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(75,'100075','UPTC','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,50000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(76,'100076','Amale','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(77,'100077','Ayman ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:42',NULL),(78,'100078','sahar tabet','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:42',NULL),(79,'100079','Joelle Douahi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(80,'100080','Cynthia Abou Jaoude','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(81,'100081','Diana','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',0,NULL,'2025-12-10 22:23:43',NULL),(82,'100082','Dunia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(83,'100083','Marcelle','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(84,'100084','Caroline','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(85,'100085','Marianne Haddad','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(86,'100086','Manal Elias','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(87,'100087','Ahmad Hashimi ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(88,'100088','Kery Ghassan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(89,'100089','bashir bechara','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(90,'100090','waad','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(91,'100091','nadya','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(92,'100092','nour ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(93,'100093','jalal dohhan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(94,'100094','karim','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(95,'100095','cherry on top ','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(96,'100096','amal','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(97,'100097','Rama Abboud','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(98,'100098','Roula','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(99,'100099','Nancy Haddad','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(100,'100100','Bachir','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(101,'100101','Marianna Tannous','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(102,'100102','Nadine Wehbe','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',0,NULL,'2025-12-10 22:23:43',NULL),(103,'100103','Zeinab Ismail','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(104,'100104','Nivine','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(105,'100105','Yara','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(106,'100106','Hiba Abou Assi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(107,'100107','Nagham','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(108,'100108','Carine Adri','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(109,'100109','Djida','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(110,'100110','Michelle Hachem','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(111,'100111','Hadil','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(112,'100112','St Charbel Church','retail',NULL,'66683365',NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(113,'100113','Marina Aneid','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(114,'100114','Rima Al Kouzi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(115,'100115','Christelle Milan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(116,'100116','Joseph Chouaity','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(117,'100117','Rita Jawhar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(118,'100118','Sana','retail',NULL,'33944822',NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(119,'100119','Maic','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'Credit OK',0,NULL,'2025-12-10 22:23:43',NULL),(120,'100120','Rouba Kai','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(121,'100121','Layal','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(122,'100122','Nadine Wehbe','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(123,'100123','soha maalouf','retail',NULL,'77939967',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(124,'100124','Nemr Abou Rjeily','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(125,'100125','Ahmed Abu Rubb','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(126,'100126','GAT Middle East','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(127,'100127','Maria Achkar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(128,'100128','Aya Akhal','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(129,'100129','Hana','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(130,'100130','Suzanne Kanaan','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(131,'100131','Marianne Azzi','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(132,'100132','Chirine Ayache','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(133,'100133','Khouzam','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(134,'100134','Nadim Azar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(135,'100135','Vandana','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(136,'100136','Amale Michlib','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(137,'100137','Mark Karam','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(138,'100138','Samo','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(139,'100139','Michel Kazan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(140,'100140','Rawan Nasser','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(141,'100141','Jennie Nakhoul','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(142,'100142','Bashir mourice','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(143,'100143','nemr Abu Rjeily','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',0,NULL,'2025-12-10 22:23:43',NULL),(144,'100144','Nasri Rbeiz','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(145,'100145','Elias Khoury','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(146,'100146','Diana Hassan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(147,'100147','Diana Gbely','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(148,'100148','Sana Askar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(149,'100149','Khalil Ibrahim','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(150,'100150','Krystal Sarkis','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(151,'100151','Lama Kalash','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(152,'100152','Carla','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(153,'100153','Maya Abou Ramia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(154,'100154','Shadia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(155,'100155','Moune','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(156,'100156','Carol Nadir','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(157,'100157','Elias Khalil','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(158,'100158','Rana Mallah','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(159,'100159','Dima Merhebi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(160,'100160','Nadim Azar','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',0,NULL,'2025-12-10 22:23:43',NULL),(161,'100161','Eliane Chaccour','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(162,'100162','Jean Youssef','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(163,'100163','Nicole Al Kache','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(164,'100164','Pepita','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(165,'100165','Hiba Darwish','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(166,'100166','Riham ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(167,'100167','Samar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(168,'100168','Hiba Hijazi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(169,'100169','Hiam Zakhem','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(170,'100170','Sally Haddad','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(171,'100171','Roula Ismail','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(172,'100172','Sahar Abou Jaoude','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(173,'100173','Ghizlane','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(174,'100174','Chirine Gharzedinne','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(175,'100175','Alysha','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(176,'100176','Marie Cremono','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(177,'100177','Hajar Issa','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(178,'100178','Neevine WAF','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(179,'100179','Rana Abi Akl','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(180,'100180','Georgette Mansour','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(181,'100181','Abir Abou Diab','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(182,'100182','Alia','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(183,'100183','Ola','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(184,'100184','Gisele Sassine','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(185,'100185','Im Abdel Aziz','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(186,'100186','Grace Alam','retail',NULL,'77983225',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(187,'100187','Rania Yammine','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(188,'100188','Jad Ayache','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(189,'100189','Hala Tabbah','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(190,'100190','St Georges And Isaac Church','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,20000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(191,'100191','Maya Hanna','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(192,'100192','Grace Ghoseini','retail',NULL,'66963211',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(193,'100193','machaal','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(194,'100194','Manal El Aawar','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(195,'100195','Aya El Hammoud','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(196,'100196','Lama','retail',NULL,'77776121',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(197,'100197','Najla Azzam','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(198,'100198','Dana Amasha','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(199,'100199','Sarah','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(200,'100200','Eliane Daccache','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(201,'100201','Katia Hanna','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(202,'100202','Fifth Element Management','retail',NULL,'9.71503E+11',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(203,'100203','Carla','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(204,'100204','Sally','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(205,'100205','Racil Ali','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,3000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(206,'100206','Sarah Nahal','retail',NULL,'66813837',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(207,'100207','Sandy','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(208,'100208','Saleh Alayan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(209,'100209','Georges Ghasssan','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(210,'100210','Sabine','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(211,'100211','Nada Nemr','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(212,'100212','Hamda Essa','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(213,'100213','Imad Aneid','retail',NULL,'66298820',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(214,'100214','Joelle Isaac','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(215,'100215','Issam Hichmi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(216,'100216','Joe Khoury','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(217,'100217','Joy Khoury','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(218,'100218','Alford Hughes','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(219,'100219','Ali Bin Ali Medical ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(220,'100220','jhonny','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(221,'100221','Georges Nehme','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(222,'100222','Hoda ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(223,'100223','Michel Achkouty ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(224,'100224','Abrar','retail',NULL,'33833673',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(225,'100225','Ayman','retail',NULL,'55624199',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(226,'100226','Michel Said','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(227,'100227','Ghinwa Bou Abdallah','retail',NULL,'66166804',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(228,'100228','Diana Hoteit','retail',NULL,'50222070',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(229,'100229','Dr Elissar Charrouf','retail',NULL,'77807040',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(230,'100230','Romel Saleh','retail',NULL,'33221130',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(231,'100231','Mireille Saliba','retail',NULL,'33389959',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(232,'100232','suzanne Bassil','retail',NULL,'55578276',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(233,'100233','rouba kaddoura','retail',NULL,'55530648',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(234,'100234','Dunia Abboud','retail',NULL,'55470452',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(235,'100235','Nour Moatassem','retail',NULL,'55651525',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(236,'100236','Daniel Ocean','retail',NULL,'50587777',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(237,'100237','International School of London','retail',NULL,'66181704',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(238,'100238','samer bejjani','retail',NULL,'66900393',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(239,'100239','Mayada','retail',NULL,'66837776',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(240,'100240','Saad Azarieh','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(241,'100241','Carole Hadi','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(242,'100242','Jana Bilal','retail',NULL,'66410190',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(243,'100243','Reem','retail',NULL,'70091802',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(244,'100244','Zahra','retail',NULL,'31630115518',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(245,'100245','Nada Khoury','retail',NULL,'66870907',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(246,'100246','nelly khalil','retail',NULL,'55232965',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(247,'100247','Ramzi Joukhadar','retail',NULL,'55536459',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(248,'100248','Pamela Kachouh','retail',NULL,'66188391',NULL,NULL,NULL,NULL,NULL,1100.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(249,'100249','Sandy semaan','retail',NULL,'33319381',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(250,'100250','Natasha Hammad','retail',NULL,'33655858',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(251,'100251','Layal Fayad','retail',NULL,'55302263',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(252,'100252','Syrine','retail',NULL,'66099840',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(253,'100253','Sunday School ','retail',NULL,'33085578',NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(254,'100254','Zeina Yazbek','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(255,'100255','Caroline Ghossain','retail',NULL,'55874600',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(256,'100256','Hala Kandah','retail',NULL,'30145643',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(257,'100257','Lynn Zoughaibi','retail',NULL,'66615535',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(258,'100258','Elsy Abi Assi','retail',NULL,'33916971',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(259,'100259','Maram Al Kourani','retail',NULL,'55060455',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(260,'100260','Fatima Abbas','retail',NULL,'66668655',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(261,'100261','Bassam Ghazal','retail',NULL,'55372895',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(262,'100262','Nayla Bejjani','retail',NULL,'55804561',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(263,'100263','lama el moatassem','retail',NULL,'55778467',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(264,'100264','Patricia Abboud','retail',NULL,'30436000',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(265,'100265','Alia Ghabar','retail',NULL,'50715725',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(266,'100266','Sana Abou Sleiman','retail',NULL,'50381979',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(267,'100267','Layla Jaber','retail',NULL,'55198117',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(268,'100268','Rana Diab','retail',NULL,'66994468',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(269,'100269','Manuella Kays','retail',NULL,'50585236',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(270,'100270','Ziad Abou Mansour','retail',NULL,'66305669',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(271,'100271','Pamela Azzi','retail',NULL,'66860821',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(272,'100272','Roula Mezher ','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(273,'100273','Roger Abou Malhab','retail',NULL,'66855997',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(274,'100274','Barbar Jabbour','retail',NULL,'31335528',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(275,'100275','Nancy A K','retail',NULL,'55680160',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(276,'100276','Reine Nader','retail',NULL,'50279220',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(277,'100277','Rola Talih','retail',NULL,'33669622',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(278,'100278','Rita Nawar','retail',NULL,'96170503896',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(279,'100279','Eliane Andraos','retail',NULL,'9613228557',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(280,'100280','Toni Maroun','retail',NULL,'33669945',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(281,'100281','Grace Wehbe','retail',NULL,'9613878189',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(282,'100282','Lara Karam','retail',NULL,'77954847',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(283,'100283','Diana Rezkallah','retail',NULL,'77760714',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(284,'100284','Rawan Al Fardan 6','retail',NULL,'33925135',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(285,'100285','Lama El Khatib','retail',NULL,'55876877',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(286,'100286','Hanan Kozbar','retail',NULL,'30064176',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(287,'100287','Ramzi Abou Dayya','retail',NULL,'33353315',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(288,'100288','Antoine Bassil','retail',NULL,'50929900',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',0,NULL,'2025-12-10 22:23:43',NULL),(289,'100289','Salim Saliba','retail',NULL,'50623661',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(290,'100290','Hassan Abou El Khoudoud','retail',NULL,'55679780',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(291,'100291','Karla Kammouge','retail',NULL,'66545301',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(292,'100292','Jad Sleiman','retail',NULL,'50277752',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(293,'100293','Maguy','retail',NULL,'77760928',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(294,'100294','Rawan Hachem','retail',NULL,'51864970',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(295,'100295','Mirvat Kai','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(296,'100296','Antoinette Ibrahim','retail',NULL,'33168442',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(297,'100297','Manal Aloush','retail',NULL,'55067643',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(298,'100298','Hala El Halabi','retail',NULL,'66253399',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(299,'100299','Nazek Arnous','retail',NULL,'66992836',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(300,'100300','Wael Fattouh','retail',NULL,'77451387',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(301,'100301','Micheline Jeaara','retail',NULL,'55781945',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(302,'100302','Elias Eid','retail',NULL,'33777553',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(303,'100303','Yasmina','retail',NULL,'33559937',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(304,'100304','Elie Hani','retail',NULL,'33139205',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(305,'100305','Leen Toukali','retail',NULL,'66326253',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(306,'100306','Gaby  Y Khoury','retail',NULL,'55558939',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(307,'100307','Husseni Mehdi','retail',NULL,'55618469',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(308,'100308','Tony Nawar','retail',NULL,'66480735',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(309,'100309','Thouraya Khachan','retail',NULL,'33303908',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(310,'100310','Maya Chammas','retail',NULL,'55282533',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(311,'100311','Rania Ouwayda','retail',NULL,'55300921',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(312,'100312','Sandy','retail',NULL,'33574797',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(313,'100313','Rayya Mehdi','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(314,'100314','Diala Salloum ','retail',NULL,'33670070',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(315,'100315','Toni Bassil ','retail',NULL,'50929900',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(316,'100316','Tatiana Makari','retail',NULL,'39946699',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(317,'100317','Hannan Hamed','retail',NULL,'30228002',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(318,'100318','Celine Chahine','retail',NULL,'33956299',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(319,'100319','Joanna ','retail',NULL,'33506668',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(320,'100320','Carmen Haddad','retail',NULL,'66789367',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(321,'100321','Mitche Maroun','retail',NULL,'31161746',NULL,NULL,NULL,'Qatar',NULL,800.000,0,'Credit OK',0,NULL,'2025-12-10 22:23:43',NULL),(322,'100322','Elie Makhoul','retail',NULL,'55777658',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(323,'100323','Joseph Arja ','retail',NULL,'66076353',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(324,'100324','Antoine Roukoz','retail',NULL,'50878050',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(325,'100325','Cynthia Layous','retail',NULL,'33141670',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(326,'100326','Iman Bakhach','retail',NULL,'33075507',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(327,'100327','Bilal Zamzam','retail',NULL,'66053077',NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(328,'100328','Micheline Maakaron','retail',NULL,'66188466',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(329,'100329','Ghada Freiha','retail',NULL,'55237522',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(330,'100330','Mayss Bitar ','retail',NULL,'66796532',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(331,'100331','Rania El Lakkis','retail',NULL,'55389023',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(332,'100332','Lyn Sawaya','retail',NULL,'33242672',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(333,'100333','Natacha Gebeo','retail',NULL,'31693663',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(334,'100334','Joelle Nader','retail',NULL,'33240218',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(335,'100335','Hala Attieh','retail',NULL,'33633036',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(336,'100336','Souad Sarkis','retail',NULL,'55620274',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(337,'100337','Josette Yazbeck','retail',NULL,'33259989',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(338,'100338','Mada ','retail',NULL,'55948085',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(339,'100339','Dona Hayek','retail',NULL,'51020707',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(340,'100340','Yasmine Hayek ','retail',NULL,'33182652',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(341,'100341','Diala El Masri ','retail',NULL,'50554438',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(342,'100342','Diala Al MAsri','retail',NULL,'50554438',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(343,'100343','Eliane Chaccour','retail',NULL,'55210216',NULL,NULL,NULL,NULL,NULL,2000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(344,'100344','Neda Kohan','retail',NULL,'33301317',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(345,'100345','Danya Khatib','retail',NULL,'55080829',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(346,'100346','Ahmad Abou Saleh','retail',NULL,'33527842',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(347,'100347','Dina Azar','retail',NULL,'55828072',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(348,'100348','Aya','retail',NULL,'55115679',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(349,'100349','Ogarite Slim','retail',NULL,'66059082',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(350,'100350','Hiba Kayal ','retail',NULL,'30872498',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(351,'100351','Paula Akkari','retail',NULL,'55305674',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(352,'100352','Mohammed Hammoud ','retail',NULL,'55007624',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(353,'100353','jaymmy Assaf ','retail',NULL,'55500621',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(354,'100354','Mahmoud Chhoury','retail',NULL,'33384470',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(355,'100355','Grace Hachem ','retail',NULL,'66574629',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(356,'100356','Hisham Awad ','retail',NULL,'66456892',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(357,'100357','Harley Davidson Qatar','retail',NULL,'123456',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(358,'100358','Djida','retail',NULL,'55489665',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(359,'100359','Aida Peltekian ','retail',NULL,'66002467',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(360,'100360','Fadi Douaidari','retail',NULL,'66150165',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(361,'100361','Dalia Baraka','retail',NULL,'66551279',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(362,'100362','Carla Bacha ','retail',NULL,'33004991',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(363,'100363','Nay Azzam ','retail',NULL,'77211784',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(364,'100364','Eliana Salloum','retail',NULL,'50792444',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(365,'100365','Sabine Haddad','retail',NULL,'66173872',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(366,'100366','Aleen Salloum','retail',NULL,'66724415',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(367,'100367','Maya Saba','retail',NULL,'55727656',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(368,'100368','Carla Kabbara','retail',NULL,'66195259',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(369,'100369','Ghinwa Ibrahim','retail',NULL,'55118480',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(370,'100370','Samer Awadallah','retail',NULL,'55665788',NULL,NULL,NULL,NULL,NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(371,'100371','Hiba Hijazi ','retail',NULL,'66009399',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(372,'100372','Talar','retail',NULL,'74770018',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(373,'100373','Khouzam','retail',NULL,'55500728',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(374,'100374','Emilie Bejjani','retail',NULL,'52037491',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(375,'100375','Hussein Choucair','retail',NULL,'60009808',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(376,'100376','Annie Abdallah ','retail',NULL,'55846209',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(377,'100377','Nour Hadad','retail',NULL,'51288843',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(378,'100378','Sdimktg','retail',NULL,'77959298',NULL,NULL,NULL,'Qatar',NULL,2000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(379,'100379','Rana N','retail',NULL,'66890849',NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(380,'100380','Farah Mokbel','retail',NULL,'50558242',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(381,'100381','Hala Issa','retail',NULL,'77889294',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(382,'100382','Georges Ghawi','retail',NULL,'66647323',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(383,'100383','Mirna Salem ','retail',NULL,'55862393',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(384,'100384','Ghada Trad','retail',NULL,'55739524',NULL,NULL,NULL,'Qatar',NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(385,'100385','Zeina Karam','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(386,'100386','Carla Karam','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(387,'100387','Crystelle Tannouri','retail',NULL,'0',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(388,'100388','Nada Rizkallah','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(389,'100389','Rana El Khoury','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(390,'100390','Rana Khoury','retail',NULL,'0',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(391,'100391','Joanne Abi Nader','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(392,'100392','Veeda','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(393,'100393','Mohammed Sabouh ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(394,'100394','Nabil','retail',NULL,'1234',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(395,'100395','Joceline','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(396,'100396','Berna Noufaily','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(397,'100397','Joe Bou Abboud ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(398,'100398','Margo Beyrouti','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(399,'100399','Leila Dreik ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(400,'100400','Nadine Zeitoun','retail',NULL,'1234',NULL,NULL,NULL,'Qatar',NULL,750.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(401,'100401','Hanan Lattouf','retail',NULL,'1234',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(402,'100402','Jessy Mouawad','retail',NULL,'1234',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(403,'100403','Nagham Lehdo','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(404,'100404','Mirianne ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(405,'100405','Aida Hadchity','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(406,'100406','Jihane ','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(407,'100407','Christel ','retail',NULL,'12345',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(408,'100408','Patricia Toulany','retail',NULL,'51565555',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(409,'100409','Elissa Ayache','retail',NULL,'59922020',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(410,'100410','Christine Basha','retail',NULL,'66040443',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(411,'100411','Cosette Abboud','retail',NULL,'55426343',NULL,NULL,NULL,NULL,NULL,550.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(412,'100412','cynthia abou jaoude','retail',NULL,'55646261',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(413,'100413','Samar Jokhadar','retail',NULL,'66974440',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(414,'100414','Dr Maya Jalloul','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(415,'100415','Mustafa Halabi','retail',NULL,'55850901',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(416,'100416','Amim Nasr','retail',NULL,'30282735',NULL,NULL,NULL,NULL,NULL,500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(417,'100417','Bassam Arab','retail',NULL,'50052663',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(418,'100418','Rana Bou Karim','retail',NULL,'1234',NULL,NULL,NULL,NULL,NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(419,'100419','Dinesh Qsale','retail',NULL,'31006500',NULL,NULL,NULL,NULL,NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(420,'100420','Michel','retail',NULL,'66752347',NULL,NULL,NULL,NULL,NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(421,'100421','Rita ','retail',NULL,'96170503896',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(422,'100422','Elissar','retail',NULL,'97455473100',NULL,NULL,NULL,NULL,NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(423,'100423','alaa','retail',NULL,'97433609688',NULL,NULL,NULL,'Qatar',NULL,1500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(424,'100424','Nina Natout','retail',NULL,'9613151566',NULL,NULL,NULL,'Qatar',NULL,2500.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(425,'100425','Josianne Massoud','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(426,'100426','Antonio','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,1000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(427,'100427','Amer Khatib','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(428,'100428','Teknowledge Services and Solutions LLC','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,50000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(429,'100429','AstraZeneca','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(430,'100430','GAC','retail',NULL,'50304633',NULL,NULL,NULL,'Qatar',NULL,10000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(431,'100431','Keeta','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(432,'100432','95 West Bay Luxury Appartments','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,25000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(433,'100433','Thirty Five WestBay Luxury Appartments','retail',NULL,NULL,NULL,NULL,NULL,NULL,NULL,25000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(434,'100434','Mitche Maroun','retail',NULL,'31161746',NULL,NULL,NULL,'Qatar',NULL,800.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(435,'100435','Karkeh Rest','retail',NULL,'66000117',NULL,NULL,NULL,NULL,NULL,50000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(436,'100436','Muhammad Suheil','retail',NULL,'55090933',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(438,'100438','Funderdome','retail',NULL,'31355811',NULL,NULL,NULL,'Qatar',NULL,5000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(439,'100439','American School of Doha','retail',NULL,NULL,NULL,NULL,NULL,'Qatar',NULL,10000.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(440,'100440','Baladi Express','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(441,'100441','CASH CUSTOMER','retail',NULL,'44413660',NULL,NULL,NULL,'Qatar',NULL,0.000,0,'No Credit Check - Cash Customer',1,NULL,'2025-12-10 22:23:43',NULL),(442,'100442','Clicks','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(443,'100443','Deliveroo','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(444,'100444','Rafeeq','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(445,'100445','Snoonu','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL),(446,'100446','Talabat','retail',NULL,'0','-',NULL,NULL,NULL,NULL,0.000,0,'Credit OK',1,NULL,'2025-12-10 22:23:43',NULL);
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
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
  KEY `idx_expense_attachments_expense` (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_categories`
--

LOCK TABLES `expense_categories` WRITE;
/*!40000 ALTER TABLE `expense_categories` DISABLE KEYS */;
INSERT INTO `expense_categories` VALUES (1,'General','',1,'2025-12-10 17:46:35');
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_expense_payments_expense` (`expense_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_payments`
--

LOCK TABLES `expense_payments` WRITE;
/*!40000 ALTER TABLE `expense_payments` DISABLE KEYS */;
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
  KEY `idx_expenses_date` (`expense_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
INSERT INTO `expenses` VALUES (1,NULL,1,'2025-12-10','test expense',100.00,0.00,100.00,'paid','cash','T001','',1,'2025-12-10 17:53:38');
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
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
  `units_per_package` int(11) DEFAULT 1,
  `package_label` varchar(50) DEFAULT NULL,
  `unit_of_measure` varchar(50) DEFAULT NULL,
  `minimum_stock` int(11) DEFAULT 0,
  `current_stock` int(11) DEFAULT 0,
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
  KEY `fk_inventory_supplier` (`supplier_id`)
) ENGINE=InnoDB AUTO_INCREMENT=277 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_items`
--

LOCK TABLES `inventory_items` WRITE;
/*!40000 ALTER TABLE `inventory_items` DISABLE KEYS */;
INSERT INTO `inventory_items` VALUES (1,'ITEM-001','Frozen Whole Chicken 1100gms - Brazil','Unit Cost: 8.8',1,NULL,1,NULL,'EA',1,100,8.8000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(2,'ITEM-002','Topside Frozen Brazil','Unit Cost: 24.0',1,NULL,1,NULL,'EA',0,80,24.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(4,'ITEM-004','Aluminum Container - 1120 (400pcs)','Supplier: Packon Trading | Unit Cost: 89.0',2,NULL,1,NULL,'EA',150,601,89.0000,'2025-12-07 20:30:45','',NULL,'active','2025-10-29 11:33:20','2025-12-07 20:30:45'),(5,'ITEM-005','Paper Cup Double Wall 500pcs','Supplier: Packon Trading | Unit Cost: 100.0',2,NULL,1,NULL,'EA',200,550,100.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(6,'ITEM-006','French Fries 9x9mm, Skin off 4x2.5Kgs','Unit Cost: 68.0',1,NULL,1,NULL,'EA',1,8,68.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(7,'ITEM-007','Black Rect Container RE 32 Pack On 150pcs/ctn','Supplier: Packon Trading | Unit Cost: 63.0',2,NULL,1,NULL,'EA',100,100,63.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(8,'ITEM-008','Dish Washing Liquid 20ltr 5x4','Supplier: Packon Trading | Unit Cost: 33.0',2,NULL,1,NULL,'EA',1,1,33.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(9,'ITEM-009','Floor Cleaner 5Ltrx4','Supplier: Packon Trading | Unit Cost: 33.0',2,NULL,1,NULL,'EA',1,2,33.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(10,'ITEM-010','Garbage Bag - 90*110 (12KG)','Supplier: Packon Trading | Unit Cost: 48.0',2,NULL,1,NULL,'EA',4,0,48.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(11,'ITEM-011','Frozen Shredded Mozzarella','Unit Cost: 25.0',1,NULL,1,NULL,'EA',0,0,25.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(12,'ITEM-012','Hash Brown Triangular 4x2.5Kgs','Unit Cost: 90.0',1,NULL,1,NULL,'EA',1,2,90.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(13,'ITEM-013','Yellow Bag Medium - 20KG','Supplier: Packon Trading | Unit Cost: 129.0',2,NULL,1,NULL,'EA',2,9,129.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(14,'ITEM-014','Tomato Paste Mechaalany','Unit Cost: 40.0',1,NULL,1,NULL,'EA',5,20,40.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(17,'ITEM-017','Frozen Beef Tenderloin Brazil','Unit Cost: 41.0',1,NULL,1,NULL,'KG',0,20,41.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(18,'ITEM-018','Karan - Black Angus Beef Chilled Eyeround','Unit Cost: 30.0',1,NULL,1,NULL,'EA',0,0,30.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(20,'ITEM-020','Chicken Shawarma Diplomata 4x2.5Kg','Unit Cost: 105.0',1,NULL,1,NULL,'EA',0,0,105.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(21,'ITEM-021','LEBANON 5283000909763 AOUN PARBOILED RICE 2KG','Unit Cost: 14.4',1,NULL,1,NULL,'EA',2,10,14.4000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(22,'ITEM-022','Palm Oil 18 Ltr','Unit Cost: 112.0',1,NULL,1,NULL,'EA',0,2,112.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(24,'ITEM-024','AOUN SODIUM BICARBONATE','Unit Cost: 3.6',1,NULL,1,NULL,'EA',0,0,3.6000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(25,'ITEM-025','AOUN BROWN BURGHUL FINE 4KG','Unit Cost: 20.0',1,NULL,1,NULL,'EA',1,2,20.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(26,'ITEM-026','Happy Gardens Chickpeas 9mm-20 kg','Unit Cost: 180.5',1,NULL,1,NULL,'EA',0,0,180.5000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(27,'ITEM-027','BM Tomato Paste 650 GR - Lebanon','Unit Cost: 8.5',1,NULL,1,NULL,'EA',0,0,8.5000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(28,'ITEM-028','MBM Apple Vinegar 500ML','Unit Cost: 6.25',1,NULL,1,NULL,'EA',1,7,6.2500,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(29,'ITEM-029','Aoun All Spices Powder 500GR','Unit Cost: 35.63',1,NULL,1,NULL,'EA',0,7,35.6300,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(30,'ITEM-030','Aoun Sumac Powder 500 GR','Unit Cost: 25.89',1,NULL,1,NULL,'EA',2,2,25.8900,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(31,'ITEM-031','Aoun 7 Spices 500 GR','Unit Cost: 27.08',1,NULL,1,NULL,'EA',0,1,27.0800,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(32,'ITEM-032','SHP- Gourmet Foods White Onion Powder','Unit Cost: 14.45',1,NULL,1,NULL,'EA',0,0,14.4500,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(33,'ITEM-033','Topside Chilled South Africa','Unit Cost: 24.0',1,NULL,1,NULL,'KG',0,35,24.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(35,'ITEM-035','Frozen Mix Vegetable','Unit Cost: 42.0',1,NULL,1,NULL,'EA',0,1,42.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(36,'ITEM-036','Karak Tea','Unit Cost: 35.0',1,NULL,1,NULL,'KG',2,12,35.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(37,'ITEM-037','Coffee Beans','Unit Cost: 90.0',1,NULL,1,NULL,'KG',3,6,90.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(38,'ITEM-038','Hot Chocolate','Unit Cost: 50.0',1,NULL,1,NULL,'KG',2,5,50.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(39,'ITEM-039','Cappuccino Topping','Unit Cost: 70.0',1,NULL,1,NULL,'KG',1,1,70.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(42,'ITEM-042','Mix Sea Food 20x400 gm','Unit Cost: 170.0',1,NULL,1,NULL,'EA',0,0,170.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(43,'ITEM-043','Tomato Peeled 6 x 2.5Kgs','Unit Cost: 96.0',1,NULL,1,NULL,'EA',0,0,96.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(44,'ITEM-044','Talmera Mild White Cheddar Slice 1x2.27KG','Unit Cost: 77.18',1,NULL,1,NULL,'KG',1,2,77.1800,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(45,'ITEM-045','Yellow Cheddar Slice 1x2.27KG','Unit Cost: 122.5',1,NULL,1,NULL,'EA',0,0,122.5000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(46,'ITEM-046','Tredos Kashkaval 1x2.8KG','Unit Cost: 66.66',1,NULL,1,NULL,'EA',0,0,66.6600,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(47,'ITEM-047','Whole Peeled Tomatoes 6x2.5KG','Unit Cost: 90.0',1,NULL,1,NULL,'EA',0,0,90.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(48,'ITEM-048','Chicken Strips Regular 6x1KG','Unit Cost: 140.0',1,NULL,1,NULL,'EA',0,0,140.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(49,'ITEM-049','Cowland Unsalted Butter Spread 82%','Unit Cost: 360.0',1,NULL,1,NULL,'Each',0,0,360.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(50,'ITEM-050','Corn Flour Bag 25KG','Unit Cost: 90.0',1,NULL,1,NULL,'EA',0,1,90.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(51,'ITEM-051','Salt 25Kgs','Unit Cost: 15.0',1,NULL,1,NULL,'EA',0,1,15.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(52,'ITEM-052','Frozen Strawberry 10x1kg','Unit Cost: 75.0',1,NULL,1,NULL,'EA',0,1,75.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(53,'ITEM-053','Frozen Mango Pulp 16x1Kg','Unit Cost: 105.0',1,NULL,1,NULL,'EA',0,0,105.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(54,'ITEM-054','Fish Fillet - 4 x 2.5 KG','Unit Cost: 73.0',1,NULL,1,NULL,'KG',1,10,73.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(55,'ITEM-055','Benina - Soy Sauce Original 1.86L','Unit Cost: 12.5',1,NULL,1,NULL,'EA',1,6,12.5000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(56,'ITEM-056','Benina - Oyster Sauce 1.9L','Unit Cost: 18.5',1,NULL,1,NULL,'EA',1,4,18.5000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(57,'ITEM-057','Benina - Sweet and Sour 1.9L','Unit Cost: 20.0',1,NULL,1,NULL,'EA',1,1,20.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(58,'ITEM-058','Chilled Beef Tenderlion','Unit Cost: 32.0',1,NULL,1,NULL,'KG',0,20,32.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(59,'ITEM-059','Philadelphia Cream Cheese 6.6KG','Unit Cost: 219.0',1,NULL,1,NULL,'EA',0,0,219.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(60,'ITEM-060','Okra Zero 20x400g','Unit Cost: 90.0',1,NULL,1,NULL,'EA',0,0,90.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(61,'ITEM-061','Tomex Frozen Sweet Corn 4x2.5KG','Unit Cost: 95.0',1,NULL,1,NULL,'EA',1,4,95.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(62,'ITEM-062','Tomex Mix Vegetables 4x2.5KG','Unit Cost: 50.0',1,NULL,1,NULL,'EA',0,0,50.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(63,'ITEM-063','Happy Gardens Foul Medamas 400gr 1x24','Unit Cost: 2.0',1,NULL,1,NULL,'EA',7,35,2.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(65,'ITEM-065','Aoun Green Lentils 900 Gr 1x20','Unit Cost: 9.5',1,NULL,1,NULL,'EA',1,10,9.5000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(66,'ITEM-066','Aoun Garlic Powder 500GR 1x12','Unit Cost: 15.91',1,NULL,1,NULL,'EA',1,2,15.9100,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(67,'ITEM-067','SHP Cinnamon Stick','Unit Cost: 85.0',1,NULL,1,NULL,'EA',0,0,85.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(68,'ITEM-068','Aoun Lime','Unit Cost: 10.2',1,NULL,1,NULL,'EA',0,10,10.2000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(69,'ITEM-069','Frozen Striploin Brazil','Unit Cost: 35.0',1,NULL,1,NULL,'EA',0,0,35.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(71,'ITEM-071','Chilled Striploin Brazil','Unit Cost: 34.0',1,NULL,1,NULL,'EA',0,0,34.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(72,'ITEM-072','Frozen Tenderloin Brazil','Unit Cost: 51.0',1,NULL,1,NULL,'EA',0,0,51.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(73,'ITEM-073','Green Peas - Medium Fine 4x2.5KG','Unit Cost: 63.0',1,NULL,3,'','EA',1,4,63.0000,'2025-12-06 07:52:25','',NULL,'active','2025-10-29 11:33:20','2025-12-06 07:52:25'),(74,'ITEM-074','Green Beans - Cut 4x2.5KG','Unit Cost: 60.0',1,NULL,1,NULL,'EA',1,4,60.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(75,'ITEM-075','Beef Bacon Bits Toppings 6x1KG','Unit Cost: 170.0',1,NULL,1,NULL,'EA',0,0,170.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(76,'ITEM-076','Barilla Penne Rigate 12x500gm','Unit Cost: 100.0',1,NULL,1,NULL,'EA',5,29,100.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(77,'ITEM-077','Barilla Spaghetti (No.5)','Unit Cost: 150.0',1,NULL,1,NULL,'EA',5,29,150.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(78,'ITEM-078','White Chunk Tuna in Sunflower Oil 6x1.8KG','Unit Cost: 230.0',1,NULL,1,NULL,'EA',1,4,230.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(79,'ITEM-079','AOUN BLACK PEPPER POWDER 500GR','Unit Cost: 27.5',1,NULL,1,NULL,'EA',0,0,27.5000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(80,'ITEM-080','Benina Sesame Oil 1.86L','Unit Cost: 65.0',1,NULL,1,NULL,'EA',1,2,65.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(81,'ITEM-081','Fish Filet Box 4x2.5 Kg','Unit Cost: 75.0',1,NULL,1,NULL,'EA',0,0,75.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(84,'ITEM-084','COCOA POWDER 1KG','Unit Cost: 60.0',1,NULL,1,NULL,'EA',0,0,60.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(85,'ITEM-085','DARK COMPOUND BLOCKS 2.5KG','Unit Cost: 45.0',1,NULL,1,NULL,'EA',1,2,45.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(86,'ITEM-086','MILK COMPOUND BLOCKS 2.5KG','Unit Cost: 45.0',1,NULL,1,NULL,'EA',1,2,45.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(87,'ITEM-087','WHITE COMPOUND BLOCKS 2.5KG','Unit Cost: 45.0',1,NULL,1,NULL,'EA',1,2,45.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(88,'ITEM-088','Chocolate Sticks 1KG','Unit Cost: 27.0',1,NULL,1,NULL,'KG',1,5,27.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(89,'ITEM-089','Cling Film 6pcs','Unit Cost: 77.0',2,NULL,1,NULL,'EA',3,4,77.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(90,'ITEM-090','Vinyl Gloves Medium 10pcs','Unit Cost: 51.0',2,NULL,1,NULL,'EA',4,0,51.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(92,'ITEM-092','Aluminum Foil - 6pcs (1.5kg)','Unit Cost: 126.0',2,NULL,1,NULL,'EA',3,14,126.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(95,'ITEM-095','Chana Dal 5 Kgs','Unit Cost: 21.5',1,NULL,1,NULL,'EA',0,0,21.5000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(96,'ITEM-096','Handy Fuel 72 pcs / Ctn','Unit Cost: 97.0',2,NULL,1,NULL,'EA',15,126,97.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(97,'ITEM-097','Plastic Bowl 4OZ - 2000pcs','Unit Cost: 149.0',2,NULL,1,NULL,'EA',200,200,149.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(99,'ITEM-099','Yellow Bag Small - 20KG','Unit Cost: 129.0',1,NULL,1,NULL,'EA',2,11,129.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(101,'ITEM-101','Black Base Container 1 Compartment JPIF TR-1C','Unit Cost: 77.0',2,NULL,1,NULL,'EA',100,440,77.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(102,'ITEM-102','Baking Sheet 500pcs','Unit Cost: 66.0',2,NULL,1,NULL,'EA',1,3,66.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(103,'ITEM-103','Aluminum Platter 6586 - Big','Unit Cost: 40.0',2,NULL,1,NULL,'EA',100,150,40.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(104,'ITEM-104','Black Round Container - RO16','Unit Cost: 43.0',2,NULL,1,NULL,'EA',150,100,43.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(105,'ITEM-105','Aluminum Container - 83185 1x400','Unit Cost: 162.0',1,NULL,1,NULL,'EA',0,0,162.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(106,'ITEM-106','Aluminum Container - 73365 1x100','Unit Cost: 104.0',2,NULL,1,NULL,'EA',20,100,104.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(107,'ITEM-107','Cutlery Pack 500 pcs','Unit Cost: 72.0',2,NULL,1,NULL,'Boxes',1,2,72.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(108,'ITEM-108','Hand Soap 5 ltr x 4','Unit Cost: 33.0',2,NULL,1,NULL,'EA',1,2,33.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(109,'ITEM-109','PE Arm Sleeve 2000/Ctn','Unit Cost: 127.0',1,NULL,1,NULL,'EA',0,0,127.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(110,'ITEM-110','Frz EG Mixed Vegetables 2.5Kgx4 Hi-Chef','Unit Cost: 34.0',1,NULL,1,NULL,'EA',1,8,34.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(111,'ITEM-111','Frz EG Cauli Flower 2.5Kgx4 Hi-Chef','Unit Cost: 35.0',1,NULL,1,NULL,'EA',0,0,35.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(112,'ITEM-112','Frz EG Okra Zero 20gx400 Hi-Chef','Unit Cost: 83.0',1,NULL,1,NULL,'EA',0,0,83.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(113,'ITEM-113','Alpro Almond Barista 8x1Ltr','Unit Cost: 108.0',1,NULL,1,NULL,'EA',0,0,108.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(114,'ITEM-114','Alpro Coconut Barista 12x1Ltr','Unit Cost: 155.0',1,NULL,1,NULL,'EA',0,0,155.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(115,'ITEM-115','Sadia Breast Box','Unit Cost: 130.0',1,NULL,1,NULL,'EA',0,0,130.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(117,'ITEM-117','Sadia Chicken Shawarma - ctn','Unit Cost: 100.0',1,NULL,1,NULL,'EA',0,0,100.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(119,'ITEM-119','Sadia Whole Chicken 1100 Gms - 10 Pcs/Ctn','Unit Cost: 99.0',1,NULL,1,NULL,'EA',0,100,99.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(120,'ITEM-120','Aoun Moghrabiya 900 GR','Unit Cost: 9.6',1,NULL,1,NULL,'EA',0,0,9.6000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(121,'ITEM-121','Aoun Egyptian Rice 5 Kgs','Unit Cost: 27.9',1,NULL,1,NULL,'EA',1,3,27.9000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(122,'ITEM-122','Coco Mazaya Charcoal 10KG','Unit Cost: 84.0',1,NULL,1,NULL,'EA',0,0,84.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(123,'ITEM-123','Tannous Blossom Water 500 ML','Unit Cost: 5.95',1,NULL,1,NULL,'EA',1,2,5.9500,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(124,'ITEM-124','Happy Gardens Green Olives','Unit Cost: 163.2',1,NULL,1,NULL,'EA',0,0,163.2000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(125,'ITEM-125','Happy Gardens Black Olives 12 kgs','Unit Cost: 168.0',1,NULL,1,NULL,'EA',0,0,168.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(126,'ITEM-126','Wooden coffee Stirrer Paper Wrapped-14cm - CTN','Unit Cost: 123.0',2,NULL,1,NULL,'Boxes',2,13,123.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(127,'ITEM-127','Wet wipes 7*11cm -1000p','Unit Cost: 89.0',2,NULL,1,NULL,'Boxes',0,1,89.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(128,'ITEM-128','Q Paper Napkins 33x33cm - CTN','Unit Cost: 61.0',2,NULL,1,NULL,'EA',10,60,61.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(129,'ITEM-129','M/W Rectangular Cont w/Lid 1000ML - 500pcs','Unit Cost: 155.0',2,NULL,1,NULL,'EA',0,650,155.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(130,'ITEM-130','Kraft Salad Bowl 750ML - CTN','Unit Cost: 158.0',2,NULL,1,NULL,'EA',200,200,158.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(133,'ITEM-133','Plastic Rectangular Container 1500 - 300pcs','Unit Cost: 172.0',2,NULL,1,NULL,'EA',100,200,172.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(134,'ITEM-134','Aluminum Pot 173 * 50 pcs per ctn small size','Unit Cost: 105.0',1,NULL,1,NULL,'EA',0,0,105.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(135,'ITEM-135','Tanmiah Plain Chicken Breast 6 OZ - Calibrated 5*2Kg','Unit Cost: 180.0',1,NULL,1,NULL,'EA',0,0,180.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(136,'ITEM-136','Sadia Whole legs 10.8 Kg','Unit Cost: 135.0',1,NULL,1,NULL,'EA',0,0,135.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(137,'ITEM-137','FRZ EG Broccoli 2.5KGx4 Hi-Chef','Unit Cost: 47.0',1,NULL,1,NULL,'EA',0,0,47.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(138,'ITEM-138','FRZ EG Cut Beans 2.5x4kg Hi-Chef','Unit Cost: 43.0',1,NULL,1,NULL,'EA',0,0,43.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(139,'ITEM-139','FZ EG Strawberry 1kgx8 Vegie-Tut','Unit Cost: 60.0',1,NULL,1,NULL,'EA',0,0,60.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(140,'ITEM-140','Shrimp Frozen 11/15 1 Kg','Unit Cost: 28.0',1,NULL,1,NULL,'KG',0,10,28.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(141,'ITEM-141','Fish Filet Frozen - Vietnam 1 Kg','Unit Cost: 9.0',1,NULL,1,NULL,'EA',0,0,9.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(143,'ITEM-143','Cheddar Cheese Sauce (TIN) 3.4 Kg','Unit Cost: 38.33',1,NULL,1,NULL,'EA',0,0,38.3300,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(144,'ITEM-144','Chicken Stock Powder 2 Kg','Unit Cost: 33.33',1,NULL,1,NULL,'EA',0,0,33.3300,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(145,'ITEM-145','Beef Stock Powder 2 Kg','Unit Cost: 33.33',1,NULL,1,NULL,'EA',0,0,33.3300,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(146,'ITEM-146','StarSea Chunk White Tune in sunflower oil 6x1.85Kg','Unit Cost: 225.0',1,NULL,1,NULL,'EA',0,4,225.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(147,'ITEM-147','AlWadi-Pomegrenate Molasses 500mlx12','Unit Cost: 90.0',1,NULL,1,NULL,'EA',5,15,90.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(148,'ITEM-148','FRZ US Beef HotDog 6Inch 6/1Kg Oak Valley','Unit Cost: 72.0',1,NULL,1,NULL,'EA',0,0,72.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(149,'ITEM-149','Candia Puff Pastry Butter Extra Tourage 82% 1Kgx10','Unit Cost: 510.0',1,NULL,1,NULL,'EA',0,0,510.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(150,'ITEM-150','Dodoni - Feta Goat Cheese 200 GRS','Unit Cost: 14.0',1,NULL,1,NULL,'EA',0,0,14.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(151,'ITEM-151','Dodoni - Feta Cheese 200 GRS','Unit Cost: 14.5',1,NULL,1,NULL,'EA',2,12,14.5000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(152,'ITEM-152','AOUN RED LENTILS 900GR','Unit Cost: 9.5',1,NULL,1,NULL,'EA',2,3,9.5000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(153,'ITEM-153','Coombe Castle - Dragon Mild White Cheddar','Unit Cost: 23.0',1,NULL,1,NULL,'KG',0,0,23.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(154,'ITEM-154','Talmera - Monterey Jack Block Cheese','Unit Cost: 34.0',1,NULL,1,NULL,'KG',0,0,34.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(155,'ITEM-155','Bister Dijon Mustard - 5KG','Unit Cost: 70.0',1,NULL,1,NULL,'EA',1,2,70.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(156,'ITEM-156','Kings Harvest - Crispy Fried Onions','Unit Cost: 27.0',1,NULL,1,NULL,'KG',0,0,27.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(157,'ITEM-157','Benina Quinoa Multicolor 1000G','Unit Cost: 23.0',1,NULL,1,NULL,'EA',0,0,23.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(158,'ITEM-158','Colavita Balsamic Vinegar - 5L','Unit Cost: 49.0',1,NULL,1,NULL,'EA',0,0,49.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(159,'ITEM-159','Sugar Renuka 50Kgs','Unit Cost: 108.0',1,NULL,1,NULL,'EA',0,0,108.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(160,'ITEM-160','QFM Flour No1 Bag 50 Kg','Unit Cost: 145.0',1,NULL,1,NULL,'EA',0,0,145.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(161,'ITEM-161','Almond Slice 1 Kg','Unit Cost: 45.0',1,NULL,1,NULL,'EA',0,0,45.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(162,'ITEM-162','Wallnut 1 Kg','Unit Cost: 35.0',1,NULL,1,NULL,'EA',0,0,35.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(163,'ITEM-163','Latex Gloves Large','Unit Cost: 51.0',2,NULL,1,NULL,'EA',4,5,51.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(164,'ITEM-164','Frz EG Chopped Spinach 2.5KGx4 Hi-Chef','Unit Cost: 35.0',1,NULL,1,NULL,'EA',0,0,35.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(165,'ITEM-165','Zeeba Rice 3 kgs x12','Unit Cost: 144.0',1,NULL,1,NULL,'EA',5,24,144.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(166,'ITEM-166','Sadia Hot Dog small size 20x340gr','Unit Cost: 74.0',1,NULL,1,NULL,'EA',0,0,74.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(167,'ITEM-167','Delta Top Side Meat','Unit Cost: 24.0',1,NULL,1,NULL,'KG',0,0,24.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(168,'ITEM-168','Black Base 2 compartement RE 2/32 - 150pcs/Ctn','Unit Cost: 103.0',2,NULL,1,NULL,'EA',50,350,103.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(169,'ITEM-169','FZ EG Mango SlicesFahed Food 1Kgx8 / Box','Unit Cost: 69.0',1,NULL,1,NULL,'EA',0,0,69.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(170,'ITEM-170','Unsalted Butter 82% Beurre Doux 10x1Kg - France','Unit Cost: 495.0',1,NULL,1,NULL,'EA',0,0,495.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(171,'ITEM-171','Croissant Butter 82% Le Grand Tourage 10x1Kg','Unit Cost: 575.0',1,NULL,1,NULL,'EA',0,0,575.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(172,'ITEM-172','Milk Powder - SLG 25 Kgs','Unit Cost: 290.0',1,NULL,1,NULL,'EA',0,0,290.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(173,'ITEM-173','Benina - Parmesan Grana Moravia Cheese 5 Kg','Unit Cost: 250.0',1,NULL,1,NULL,'KG',1,5,250.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(175,'ITEM-175','SUGAR PASTE black 1KG X 12PCS','Unit Cost: 168.0',1,NULL,1,NULL,'EA',0,0,168.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(176,'ITEM-176','Aoun White Kidney Beans 900GR','Unit Cost: 10.79',1,NULL,1,NULL,'EA',0,0,10.7900,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(177,'ITEM-177','Aoun Red Round Beans 900GR','Unit Cost: 13.0',1,NULL,1,NULL,'EA',0,0,13.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(178,'ITEM-178','Aoun Cumin Powder 500GR','Unit Cost: 22.53',1,NULL,1,NULL,'EA',0,0,22.5300,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(179,'ITEM-179','Aoun Corriander Powder 500GR','Unit Cost: 11.64',1,NULL,1,NULL,'EA',0,0,11.6400,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(180,'ITEM-180','Aoun Cinnamon Powder 500GR','Unit Cost: 14.5',1,NULL,1,NULL,'EA',0,0,14.5000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(181,'ITEM-181','Aoun White Pepper Powder 500GR','Unit Cost: 41.8',1,NULL,1,NULL,'EA',0,0,41.8000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(182,'ITEM-182','Happy Gardens Tahina Extra 8.58 NW - GW 9.00','Unit Cost: 162.56',1,NULL,1,NULL,'EA',1,2,162.5600,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(183,'ITEM-183','Happy Gardens Tehina Brl N.W. 10KG','Unit Cost: 160.0',1,NULL,1,NULL,'EA',0,0,160.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(185,'ITEM-185','Toilet Roll 10pcs','Unit Cost: 9.0',2,NULL,1,NULL,'EA',10,78,9.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(186,'ITEM-186','Tissue Box - 30pcs','Unit Cost: 48.0',2,NULL,1,NULL,'EA',5,19,48.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(188,'ITEM-188','Hair Net Black 1000pcs','Unit Cost: 38.0',2,NULL,1,NULL,'EA',4,7,38.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(189,'ITEM-189','Paper Bag 30x25x15 Brown','Unit Cost: 135.0',2,NULL,1,NULL,'EA',100,400,135.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(191,'ITEM-191','HD Spoon Black 1000pcs','Unit Cost: 50.0',1,NULL,1,NULL,'EA',0,0,50.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(192,'ITEM-192','Black Garbage Bag 120x140','Unit Cost: 55.0',2,NULL,1,NULL,'EA',0,0,55.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(193,'ITEM-193','Clorex 4x4ltr','Unit Cost: 36.0',2,NULL,1,NULL,'EA',1,4,36.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(194,'ITEM-194','RP Plastic Container Round 250 ml 500pcs','Unit Cost: 77.0',2,NULL,1,NULL,'EA',200,300,77.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(195,'ITEM-195','Nylon Foil','Unit Cost: 28.0',2,NULL,1,NULL,'EA',0,0,28.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(196,'ITEM-196','Aluminum Plate 6586 - 100pcs','Unit Cost: 48.0',1,NULL,1,NULL,'EA',0,0,48.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(197,'ITEM-197','White Plastic bag 20pkt','Unit Cost: 45.0',2,NULL,1,NULL,'Packets',2,14,45.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(198,'ITEM-198','IT Chopped Tomatoes 2550GMx6 Patisiya','Unit Cost: 75.0',1,NULL,1,NULL,'EA',1,13,75.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(199,'ITEM-199','Aviko H Patatas Bravas 4x2500g','Unit Cost: 110.0',1,NULL,1,NULL,'EA',0,0,110.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(200,'ITEM-200','LAKELAND - MILLAC WHIP TOPPING UNSWEETENED (12X1L)','Unit Cost: 189.0',1,NULL,1,NULL,'EA',0,0,189.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(201,'ITEM-201','GRANORO PENNE RIGATE 24X500G (1026)','Unit Cost: 129.6',1,NULL,1,NULL,'EA',4,12,129.6000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(202,'ITEM-202','GRANORO DEDICATO &quot;NIDI FETTUCCINE&quot; 12X500G (48082)','Unit Cost: 90.0',1,NULL,1,NULL,'EA',2,12,90.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(203,'ITEM-203','ALKARAMAH - SPRING ROLLS SMALL{24X160G}','Unit Cost: 78.0',1,NULL,1,NULL,'EA',0,0,78.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(204,'ITEM-204','EGG LARGE 360PCS (12X30\'s)','Unit Cost: 155.0',1,NULL,1,NULL,'EA',0,0,155.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(205,'ITEM-205','Bake XL - Improver 10Kgs','Unit Cost: 163.0',1,NULL,1,NULL,'EA',0,0,163.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(206,'ITEM-206','Milk Compound Chocolate 5Kgs','Unit Cost: 80.0',1,NULL,1,NULL,'EA',0,0,80.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(207,'ITEM-207','White Compound Chocolate 5Kgs','Unit Cost: 80.0',1,NULL,1,NULL,'EA',0,0,80.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(208,'ITEM-208','Dark Compound Chocolate','Unit Cost: 80.0',1,NULL,1,NULL,'EA',0,0,80.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(209,'ITEM-209','Frozen Mix Berries 2.5kgs Andros Chef','Unit Cost: 87.5',1,NULL,1,NULL,'EA',0,0,87.5000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(210,'ITEM-210','Tomex Spinach Leaf 4x2.5kgs','Unit Cost: 55.0',1,NULL,1,NULL,'EA',0,0,55.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(211,'ITEM-211','Happy Gardens Vine Leaves -Deluxe 908GR 1x12','Unit Cost: 160.65',1,NULL,1,NULL,'EA',5,36,160.6500,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(212,'ITEM-212','Al Wajba Red Vinegar 1ltr x 12 pcs','Unit Cost: 34.0',1,NULL,1,NULL,'EA',0,0,34.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(213,'ITEM-213','Mission Tortilla Wraps Wheat 20A 8x12 120c Original 320g','Unit Cost: 6.0',1,NULL,1,NULL,'EA',1,3,6.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(214,'ITEM-214','Mission Tortilla Wraps 25 A 6x13 105c Original 378 g','Unit Cost: 7.0',1,NULL,1,NULL,'EA',0,2,7.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(215,'ITEM-215','Aviko Crunchy Crispy 9.5mm','Unit Cost: 90.0',1,NULL,1,NULL,'EA',1,1,90.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(216,'ITEM-216','Eye Round Chilled South African','Unit Cost: 32.0',1,NULL,1,NULL,'EA',0,0,32.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(217,'ITEM-217','AOUN WHITE BURGHUL HARD 900Gr','Unit Cost: 5.52',1,NULL,1,NULL,'EA',0,0,5.5200,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(218,'ITEM-218','Al Anabi- Vermicelli 24x400G','Unit Cost: 79.2',1,NULL,1,NULL,'EA',0,0,79.2000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(219,'ITEM-219','QK-South African Chilled Beef Knuckle','Unit Cost: 32.0',1,NULL,1,NULL,'EA',0,0,32.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(220,'ITEM-220','Vizyon White Sugar Paste 1kgx12Pcs','Unit Cost: 180.0',1,NULL,1,NULL,'EA',2,12,180.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(221,'ITEM-221','Piping Bag 10 Pcs','Unit Cost: 195.0',2,NULL,1,NULL,'EA',2,7,195.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(222,'ITEM-222','Vizyon Custard 1Kgx10pcs','Unit Cost: 285.0',1,NULL,1,NULL,'EA',0,0,285.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(223,'ITEM-223','Spiral Whipping Cream 1 Ltrx12pcs','Unit Cost: 140.0',1,NULL,1,NULL,'EA',5,24,140.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(224,'ITEM-224','QNited Paper Bag 31x36x18 200pcs','Unit Cost: 140.0',2,NULL,1,NULL,'EA',30,400,140.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(226,'ITEM-226','Qnited Paper BAg 22x14x21 200 Pcs','Unit Cost: 80.0',2,NULL,1,NULL,'EA',50,0,80.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(227,'ITEM-227','Qnited Kraft Salad Bowl 500ML - 300 pcs/CTN','Unit Cost: 130.0',2,NULL,1,NULL,'EA',150,400,130.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(229,'ITEM-229','Qnited 1 Compartement 250pcs/Ctn','Unit Cost: 90.0',2,NULL,1,NULL,'EA',50,250,90.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(230,'ITEM-230','Qnited Hinged Clear Container Small Size 300pcs/CTN','Unit Cost: 45.0',2,NULL,1,NULL,'EA',200,450,45.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(232,'ITEM-232','Qnited White Plastic Bag 5Kgs','Unit Cost: 36.0',2,NULL,1,NULL,'EA',0,1,36.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(233,'ITEM-233','Qnited Plastic Blue Bag 5Kgs','Unit Cost: 36.0',2,NULL,1,NULL,'EA',0,1,36.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(234,'ITEM-234','Lexquis- Emmental Block','Unit Cost: 35.72',1,NULL,1,NULL,'EA',1,4,35.7200,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(235,'ITEM-235','Mission - Tort Wheat 15 A 8x15 171c Original - 200g','Unit Cost: 5.0',1,NULL,1,NULL,'EA',0,0,5.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(236,'ITEM-236','Talmera - 120 Slice American Cheese Colored SSI - 2.27Kg','Unit Cost: 56.75',1,NULL,1,NULL,'KG',0,0,56.7500,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(237,'ITEM-237','Talmera - 160 Slice American Cheese Colored - 2.27Kg','Unit Cost: 56.75',1,NULL,1,NULL,'KG',1,2,56.7500,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(238,'ITEM-238','Chilled BR Beef Topside BL JBS','Unit Cost: 29.0',1,NULL,1,NULL,'KG',0,80,29.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(239,'ITEM-239','Al Rayes Echo Foamchlor FC313 5ltr','Unit Cost: 49.0',2,NULL,1,NULL,'EA',1,1,49.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(240,'ITEM-240','Al Rayes Echo Zan BK 1 ltr','Unit Cost: 80.0',2,NULL,1,NULL,'EA',1,1,80.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(241,'ITEM-241','Al Rayes Dish Soap Ginger Lemon 5LTR','Unit Cost: 40.0',2,NULL,1,NULL,'EA',1,1,40.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(242,'ITEM-242','Al Rayes Bio Lab Maxi Roll 600 G Green','Unit Cost: 30.0',2,NULL,1,NULL,'EA',6,14,30.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(243,'ITEM-243','Icing sugar 2Kg 5 pkt','Unit Cost: 60.0',1,NULL,1,NULL,'EA',1,5,60.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(244,'ITEM-244','Backaldrin Dry Yeast 20Pc x 500g','Unit Cost: 125.0',1,NULL,1,NULL,'EA',0,20,125.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(245,'ITEM-245','Vanilla Liquid Btl','Unit Cost: 61.0',1,NULL,1,NULL,'EA',0,0,61.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(246,'ITEM-246','U shape PET Juice cup 12OZ 1000pc/Box','Unit Cost: 150.0',2,NULL,1,NULL,'Packets',0,17,150.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(247,'ITEM-247','Stretch film 2.5Kgx6','Unit Cost: 100.0',2,NULL,1,NULL,'EA',1,1,100.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(248,'ITEM-248','Flour Turkey 50 Kgs','Unit Cost: 90.0',1,NULL,1,NULL,'EA',0,1,90.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(249,'ITEM-249','Pure Ghee 1kg','Unit Cost: 37.0',1,NULL,1,NULL,'EA',0,0,37.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(250,'ITEM-250','Hogget Mutton Whole','Unit Cost: 37.0',1,NULL,1,NULL,'EA',0,0,37.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(251,'ITEM-251','Qnited Cocoa Powder 10Kg Reference 900','Unit Cost: 300.0',1,NULL,1,NULL,'EA',1,1,300.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(252,'ITEM-252','Mayonnaise','Unit Cost: 40.0',1,NULL,1,NULL,'EA',1,6,40.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(253,'ITEM-253','Mozarella Sticks 6 KG','Unit Cost: 175.0',1,NULL,1,NULL,'EA',0,0,175.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(254,'ITEM-254','Masoor Dal 15Kg','Unit Cost: 58.0',1,NULL,15,'','EA',1,3,58.0000,'2025-12-06 07:51:36','',NULL,'active','2025-10-29 11:33:20','2025-12-06 07:51:36'),(255,'ITEM-255','Mashhor Indian 1121 Long Grain Basmati Rice 1x35kg','Unit Cost: 140.0',1,NULL,35,'','EA',0,0,140.0000,'2025-12-06 07:51:04','',NULL,'active','2025-10-29 11:33:20','2025-12-06 07:51:04'),(256,'ITEM-256','Pena branca 1500gms  whole chicken','Unit Cost: 88.0',1,NULL,1,NULL,'EA',0,0,88.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(257,'ITEM-257','Sunflower oil 4x5Lit','Unit Cost: 108.0',1,NULL,1,NULL,'EA',5,41,108.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(258,'ITEM-258','Fresh Eggs Grade A -','Unit Cost: 120.0',1,NULL,1,NULL,'Boxes',0,3,120.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(260,'ITEM-260','Sona Masoori Rice 35 Kg','Unit Cost: 82.0',1,NULL,35,'KG','EA',1,3,82.0000,'2025-12-06 07:49:57','',NULL,'active','2025-10-29 11:33:20','2025-12-06 07:49:57'),(262,'ITEM-262','Frozen Mackerel Whole 10kgs','Unit Cost: 65.0',1,NULL,1,NULL,'KG',0,20,65.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(263,'ITEM-263','Sun White Rice 20Kg','Unit Cost: 128.0',1,NULL,1,NULL,'EA',0,2,128.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(264,'ITEM-264','White Vinegar 4x3.78ltr','Unit Cost: 18.0',1,NULL,1,NULL,'EA',1,1,18.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(265,'ITEM-265','HAPPY GARDENS PICKLED GRAPE LEAVES','Unit Cost: 13.06',1,NULL,1,NULL,'EA',5,36,13.0600,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(266,'ITEM-266','AOUN WHITE BURGHUL FINE 4KG','Unit Cost: 23.0',1,NULL,1,NULL,'EA',1,3,23.0000,'2025-12-05 16:24:34','',NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(267,'ITEM-267','Frozen Spinach 10x1kg KLA India','Unit Cost: 37.0',1,NULL,1,NULL,'EA',0,0,37.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(268,'ITEM-268','Beef Slice Alwahid 20x900g','Unit Cost: 309.0',1,NULL,1,NULL,'EA',0,0,309.0000,'2025-12-05 16:24:34',NULL,NULL,'active','2025-10-29 11:33:20','2025-12-05 16:24:34'),(270,'ITEM-269','Paper Cup 4 Oz - Small','',2,NULL,1,NULL,'EA',100,1000,NULL,NULL,'',NULL,'active','2025-11-11 13:05:15','2025-11-15 09:28:53'),(272,'ITEM-271','Sauce Cup 20z (20pcksts x1000pcs)','QAR 95',2,NULL,1,NULL,'EA',200,1300,NULL,NULL,'',NULL,'active','2025-11-15 09:04:55','2025-11-24 08:53:00'),(273,'ITEM-272','Chicken Breast Aurora 2Kgx6','QAR 159',1,NULL,1,NULL,'KG',5,30,NULL,NULL,'',NULL,'active','2025-11-15 11:37:32','2025-11-23 11:03:26'),(274,'ITEM-273','Rose Water','',1,NULL,1,NULL,'0',0,1,NULL,NULL,'',NULL,'active','2025-11-15 14:32:58','2025-11-15 14:33:12'),(275,'ITEM-274','Tomato Ketchup','',1,NULL,1,NULL,'EA',1,3,NULL,NULL,'',NULL,'active','2025-11-15 14:39:06','2025-11-15 14:39:06'),(276,'ITEM-275','Sponge','',2,NULL,1,NULL,'EA',5,22,NULL,NULL,'',NULL,'active','2025-11-29 11:05:50','2025-11-29 11:05:50');
/*!40000 ALTER TABLE `inventory_items` ENABLE KEYS */;
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
  `transaction_type` enum('in','out','adjustment') DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `reference_type` enum('purchase_order','work_order','manual','recipe') NOT NULL DEFAULT 'manual',
  `reference_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `transaction_date` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `item_id` (`item_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=266 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_transactions`
--

LOCK TABLES `inventory_transactions` WRITE;
/*!40000 ALTER TABLE `inventory_transactions` DISABLE KEYS */;
INSERT INTO `inventory_transactions` VALUES (4,4,'in',1245,'manual',NULL,1,NULL,'2025-11-11 11:49:51'),(5,5,'in',800,'manual',NULL,1,NULL,'2025-11-11 11:52:27'),(6,7,'in',200,'manual',NULL,1,NULL,'2025-11-11 11:53:37'),(7,9,'in',2,'manual',NULL,1,NULL,'2025-11-11 11:54:27'),(8,10,'in',18,'manual',NULL,1,NULL,'2025-11-11 11:54:39'),(9,13,'in',12,'manual',NULL,1,NULL,'2025-11-11 11:55:44'),(10,37,'in',12,'manual',NULL,1,NULL,'2025-11-11 12:08:02'),(11,90,'in',17,'manual',NULL,1,NULL,'2025-11-11 12:19:57'),(12,101,'in',550,'manual',NULL,1,NULL,'2025-11-11 12:21:37'),(13,101,'out',50,'manual',NULL,1,NULL,'2025-11-11 12:22:10'),(14,168,'in',200,'manual',NULL,1,NULL,'2025-11-11 12:22:41'),(15,104,'in',150,'manual',NULL,1,NULL,'2025-11-11 12:23:38'),(16,133,'in',300,'manual',NULL,1,NULL,'2025-11-11 12:24:48'),(17,194,'in',800,'manual',NULL,1,NULL,'2025-11-11 12:27:40'),(18,230,'in',950,'manual',NULL,1,NULL,'2025-11-11 12:29:08'),(19,97,'in',2045,'manual',NULL,1,NULL,'2025-11-11 12:34:33'),(20,227,'in',800,'manual',NULL,1,NULL,'2025-11-11 12:35:55'),(21,130,'in',850,'manual',NULL,1,NULL,'2025-11-11 12:39:37'),(22,227,'out',250,'manual',NULL,1,NULL,'2025-11-11 12:39:46'),(23,92,'in',4,'manual',NULL,1,NULL,'2025-11-11 12:41:20'),(24,103,'in',20,'manual',NULL,1,NULL,'2025-11-11 12:45:22'),(25,188,'in',11,'manual',NULL,1,NULL,'2025-11-11 12:46:12'),(26,163,'in',18,'manual',NULL,1,NULL,'2025-11-11 12:46:49'),(27,89,'in',12,'manual',NULL,1,NULL,'2025-11-11 12:47:24'),(29,242,'in',36,'manual',NULL,1,NULL,'2025-11-11 12:48:20'),(30,186,'in',14,'manual',NULL,1,NULL,'2025-11-11 12:48:58'),(31,128,'in',46,'manual',NULL,1,NULL,'2025-11-11 12:52:59'),(32,233,'in',1,'manual',NULL,1,NULL,'2025-11-11 12:53:27'),(33,232,'in',1,'manual',NULL,1,NULL,'2025-11-11 12:53:40'),(34,246,'in',17,'manual',NULL,1,NULL,'2025-11-11 12:54:15'),(35,96,'in',126,'manual',NULL,1,NULL,'2025-11-11 12:58:34'),(36,163,'in',2,'manual',NULL,1,NULL,'2025-11-11 12:58:56'),(37,221,'in',9,'manual',NULL,1,NULL,'2025-11-11 13:00:16'),(38,107,'in',3,'manual',NULL,1,NULL,'2025-11-11 13:00:59'),(39,127,'in',1,'manual',NULL,1,NULL,'2025-11-11 13:02:23'),(41,192,'in',60,'manual',NULL,1,NULL,'2025-11-11 13:06:39'),(43,12,'in',2,'manual',NULL,1,NULL,'2025-11-12 08:27:31'),(44,213,'in',3,'manual',NULL,1,NULL,'2025-11-12 08:33:23'),(45,1,'in',10,'manual',NULL,1,NULL,'2025-11-12 11:55:45'),(46,63,'in',24,'manual',NULL,1,NULL,'2025-11-12 12:03:14'),(47,65,'in',10,'manual',NULL,1,NULL,'2025-11-12 12:03:54'),(48,211,'in',36,'manual',NULL,1,NULL,'2025-11-12 12:04:39'),(49,6,'in',2,'manual',NULL,1,NULL,'2025-11-12 12:05:29'),(50,7,'out',50,'manual',NULL,1,NULL,'2025-11-12 12:05:48'),(52,17,'in',20,'manual',NULL,1,NULL,'2025-11-12 12:09:17'),(54,21,'in',10,'manual',NULL,1,NULL,'2025-11-12 12:10:48'),(55,22,'in',1,'manual',NULL,1,NULL,'2025-11-12 12:18:40'),(56,35,'in',1,'manual',NULL,1,NULL,'2025-11-12 13:32:07'),(57,36,'in',12,'manual',NULL,1,NULL,'2025-11-12 13:55:04'),(58,37,'out',1,'manual',NULL,1,NULL,'2025-11-12 13:55:38'),(59,221,'out',3,'manual',NULL,1,NULL,'2025-11-13 07:54:31'),(60,221,'in',1,'manual',NULL,1,NULL,'2025-11-13 07:54:40'),(61,229,'in',100,'manual',NULL,1,NULL,'2025-11-13 07:56:29'),(62,194,'in',380,'manual',NULL,1,NULL,'2025-11-13 07:58:10'),(64,1,'in',90,'manual',NULL,1,NULL,'2025-11-15 08:23:40'),(65,104,'in',150,'manual',NULL,1,NULL,'2025-11-15 08:29:39'),(66,103,'in',130,'manual',NULL,1,NULL,'2025-11-15 08:33:01'),(67,92,'in',7,'manual',NULL,1,NULL,'2025-11-15 08:34:37'),(68,92,'in',3,'manual',NULL,1,NULL,'2025-11-15 08:34:55'),(69,168,'out',50,'manual',NULL,1,NULL,'2025-11-15 08:43:35'),(70,272,'in',900,'manual',NULL,1,NULL,'2025-11-15 09:04:55'),(71,185,'in',27,'manual',NULL,1,NULL,'2025-11-15 09:06:30'),(72,242,'out',9,'manual',NULL,1,NULL,'2025-11-15 09:07:19'),(73,197,'in',14,'manual',NULL,1,NULL,'2025-11-15 09:16:35'),(74,185,'in',51,'manual',NULL,1,NULL,'2025-11-15 09:17:03'),(75,188,'out',1,'manual',NULL,1,NULL,'2025-11-15 09:18:21'),(76,189,'in',150,'manual',NULL,1,NULL,'2025-11-15 09:18:53'),(77,247,'in',3,'manual',NULL,1,NULL,'2025-11-15 09:19:50'),(78,163,'out',14,'manual',NULL,1,NULL,'2025-11-15 09:20:17'),(79,241,'in',1,'manual',NULL,1,NULL,'2025-11-15 09:21:03'),(80,240,'in',1,'manual',NULL,1,NULL,'2025-11-15 09:21:46'),(81,239,'in',1,'manual',NULL,1,NULL,'2025-11-15 09:22:30'),(82,270,'in',1000,'manual',NULL,1,NULL,'2025-11-15 09:28:53'),(83,193,'in',4,'manual',NULL,1,NULL,'2025-11-15 09:31:52'),(84,234,'in',4,'manual',NULL,1,NULL,'2025-11-15 09:35:59'),(85,173,'in',5,'manual',NULL,1,NULL,'2025-11-15 09:36:40'),(86,236,'in',2,'manual',NULL,1,NULL,'2025-11-15 09:37:19'),(87,236,'out',2,'manual',NULL,1,NULL,'2025-11-15 09:37:52'),(88,237,'in',2,'manual',NULL,1,NULL,'2025-11-15 09:38:06'),(89,44,'in',12,'manual',NULL,1,NULL,'2025-11-15 09:39:43'),(90,151,'in',12,'manual',NULL,1,NULL,'2025-11-15 09:40:24'),(91,44,'out',10,'manual',NULL,1,NULL,'2025-11-15 09:40:59'),(92,238,'in',2,'manual',NULL,1,NULL,'2025-11-15 11:12:22'),(93,238,'in',78,'manual',NULL,1,NULL,'2025-11-15 11:13:51'),(94,265,'in',36,'manual',NULL,1,NULL,'2025-11-15 11:20:48'),(95,68,'in',10,'manual',NULL,1,NULL,'2025-11-15 11:31:02'),(96,273,'in',12,'manual',NULL,1,NULL,'2025-11-15 11:37:32'),(97,262,'in',20,'manual',NULL,1,NULL,'2025-11-15 11:38:14'),(98,254,'in',3,'manual',NULL,1,NULL,'2025-11-15 11:38:35'),(99,260,'in',3,'manual',NULL,1,NULL,'2025-11-15 11:38:47'),(100,8,'in',1,'manual',NULL,1,NULL,'2025-11-15 11:39:55'),(101,251,'in',1,'manual',NULL,1,NULL,'2025-11-15 11:56:32'),(102,226,'in',475,'manual',NULL,1,NULL,'2025-11-15 12:03:47'),(103,224,'in',70,'manual',NULL,1,NULL,'2025-11-15 12:04:05'),(104,52,'in',1,'manual',NULL,1,NULL,'2025-11-15 12:08:50'),(105,220,'in',12,'manual',NULL,1,NULL,'2025-11-15 12:09:38'),(106,215,'in',1,'manual',NULL,1,NULL,'2025-11-15 12:10:26'),(107,54,'in',10,'manual',NULL,1,NULL,'2025-11-15 12:19:29'),(108,244,'in',20,'manual',NULL,1,NULL,'2025-11-15 12:20:29'),(109,223,'in',24,'manual',NULL,1,NULL,'2025-11-15 12:21:09'),(110,243,'in',5,'manual',NULL,1,NULL,'2025-11-15 12:21:35'),(111,88,'in',5,'manual',NULL,1,NULL,'2025-11-15 12:22:03'),(112,86,'in',5,'manual',NULL,1,NULL,'2025-11-15 12:23:01'),(113,87,'in',5,'manual',NULL,1,NULL,'2025-11-15 12:23:26'),(114,87,'out',3,'manual',NULL,1,NULL,'2025-11-15 12:39:53'),(115,85,'in',2,'manual',NULL,1,NULL,'2025-11-15 12:41:16'),(116,86,'out',3,'manual',NULL,1,NULL,'2025-11-15 12:41:27'),(117,126,'in',13,'manual',NULL,1,NULL,'2025-11-15 12:43:43'),(118,29,'in',7,'manual',NULL,1,NULL,'2025-11-15 14:23:15'),(119,66,'in',2,'manual',NULL,1,NULL,'2025-11-15 14:23:47'),(120,30,'in',2,'manual',NULL,1,NULL,'2025-11-15 14:26:53'),(121,266,'in',3,'manual',NULL,1,NULL,'2025-11-15 14:28:36'),(122,25,'in',3,'manual',NULL,1,NULL,'2025-11-15 14:28:51'),(123,25,'out',1,'manual',NULL,1,NULL,'2025-11-15 14:28:58'),(124,121,'in',3,'manual',NULL,1,NULL,'2025-11-15 14:29:23'),(125,78,'in',4,'manual',NULL,1,NULL,'2025-11-15 14:29:43'),(126,264,'in',3,'manual',NULL,1,NULL,'2025-11-15 14:30:40'),(127,28,'in',7,'manual',NULL,1,NULL,'2025-11-15 14:31:30'),(128,211,'in',22,'manual',NULL,1,NULL,'2025-11-15 14:31:44'),(129,123,'in',2,'manual',NULL,1,NULL,'2025-11-15 14:32:11'),(130,274,'in',1,'manual',NULL,1,NULL,'2025-11-15 14:32:58'),(131,198,'in',4,'manual',NULL,1,NULL,'2025-11-15 14:34:28'),(132,14,'in',20,'manual',NULL,1,NULL,'2025-11-15 14:35:14'),(133,63,'in',11,'manual',NULL,1,NULL,'2025-11-15 14:35:38'),(134,147,'in',24,'manual',NULL,1,NULL,'2025-11-15 14:36:51'),(135,80,'in',2,'manual',NULL,1,NULL,'2025-11-15 14:37:10'),(136,55,'in',6,'manual',NULL,1,NULL,'2025-11-15 14:37:26'),(137,56,'in',4,'manual',NULL,1,NULL,'2025-11-15 14:37:45'),(138,57,'in',1,'manual',NULL,1,NULL,'2025-11-15 14:38:09'),(139,275,'in',3,'manual',NULL,1,NULL,'2025-11-15 14:39:06'),(140,252,'in',2,'manual',NULL,1,NULL,'2025-11-15 14:39:30'),(141,155,'in',2,'manual',NULL,1,NULL,'2025-11-15 14:39:44'),(142,8,'out',1,'manual',NULL,1,NULL,'2025-11-16 10:57:28'),(143,252,'in',4,'manual',NULL,1,NULL,'2025-11-16 11:53:08'),(144,198,'in',9,'manual',NULL,1,NULL,'2025-11-16 11:53:47'),(145,61,'in',4,'manual',NULL,1,NULL,'2025-11-16 11:54:26'),(146,73,'in',4,'manual',NULL,1,NULL,'2025-11-16 11:55:05'),(147,110,'in',8,'manual',NULL,1,NULL,'2025-11-16 11:55:44'),(148,77,'in',29,'manual',NULL,1,NULL,'2025-11-16 11:56:13'),(149,74,'in',4,'manual',NULL,1,NULL,'2025-11-16 11:57:03'),(150,76,'in',29,'manual',NULL,1,NULL,'2025-11-16 12:32:07'),(151,202,'in',12,'manual',NULL,1,NULL,'2025-11-16 12:32:30'),(152,140,'in',10,'manual',NULL,1,NULL,'2025-11-16 12:33:56'),(153,182,'in',2,'manual',NULL,1,NULL,'2025-11-16 13:17:13'),(154,33,'in',35,'manual',NULL,1,NULL,'2025-11-19 12:49:58'),(155,165,'in',24,'manual',NULL,1,NULL,'2025-11-19 13:26:13'),(156,106,'in',100,'manual',NULL,1,NULL,'2025-11-19 13:29:54'),(157,102,'in',1,'manual',NULL,1,NULL,'2025-11-19 13:30:46'),(158,108,'in',2,'manual',NULL,1,NULL,'2025-11-19 13:32:00'),(159,107,'out',1,'manual',NULL,1,NULL,'2025-11-19 13:32:42'),(160,129,'in',500,'manual',NULL,1,NULL,'2025-11-19 13:33:22'),(161,119,'in',100,'manual',NULL,1,NULL,'2025-11-19 13:35:24'),(162,258,'in',3,'manual',NULL,1,NULL,'2025-11-19 13:36:25'),(163,263,'in',2,'manual',NULL,1,NULL,'2025-11-19 13:36:44'),(164,212,'in',2,'manual',NULL,1,NULL,'2025-11-19 13:41:29'),(165,146,'in',4,'manual',NULL,1,NULL,'2025-11-19 13:52:19'),(166,257,'in',13,'manual',NULL,1,NULL,'2025-11-19 13:52:26'),(167,214,'in',2,'manual',NULL,1,NULL,'2025-11-20 07:02:45'),(168,99,'in',11,'manual',NULL,1,NULL,'2025-11-20 07:37:56'),(170,13,'out',3,'manual',NULL,1,NULL,'2025-11-20 07:38:51'),(171,248,'in',1,'manual',NULL,1,NULL,'2025-11-20 08:13:02'),(172,31,'in',1,'manual',NULL,1,NULL,'2025-11-20 08:27:44'),(173,50,'in',1,'manual',NULL,1,NULL,'2025-11-20 08:28:16'),(174,51,'in',1,'manual',NULL,1,NULL,'2025-11-20 08:50:05'),(175,2,'in',80,'manual',NULL,1,NULL,'2025-11-20 11:08:41'),(177,38,'in',5,'manual',NULL,1,NULL,'2025-11-20 12:50:15'),(178,163,'in',6,'manual',NULL,1,NULL,'2025-11-20 12:57:51'),(179,163,'in',4,'manual',NULL,1,NULL,'2025-11-20 12:57:57'),(180,247,'out',2,'manual',NULL,1,NULL,'2025-11-22 08:32:59'),(181,10,'out',15,'manual',NULL,1,NULL,'2025-11-22 08:33:17'),(182,192,'out',60,'manual',NULL,1,NULL,'2025-11-22 08:33:49'),(183,168,'out',50,'manual',NULL,1,NULL,'2025-11-22 08:34:21'),(184,101,'out',250,'manual',NULL,1,NULL,'2025-11-22 08:34:39'),(185,242,'out',17,'manual',NULL,1,NULL,'2025-11-22 08:35:31'),(187,189,'in',150,'manual',NULL,1,NULL,'2025-11-22 08:37:10'),(189,224,'out',70,'manual',NULL,1,NULL,'2025-11-22 08:38:37'),(190,226,'out',475,'manual',NULL,1,NULL,'2025-11-22 08:38:47'),(191,229,'in',150,'manual',NULL,1,NULL,'2025-11-22 08:40:22'),(192,257,'in',29,'manual',NULL,1,NULL,'2025-11-22 10:51:13'),(193,39,'in',4,'manual',NULL,1,NULL,'2025-11-22 10:54:51'),(194,211,'out',12,'manual',NULL,1,NULL,'2025-11-22 11:28:57'),(195,147,'out',4,'manual',NULL,1,NULL,'2025-11-22 11:29:12'),(196,273,'in',18,'manual',NULL,1,NULL,'2025-11-23 11:03:17'),(197,6,'in',6,'manual',NULL,1,NULL,'2025-11-23 11:03:51'),(198,257,'out',1,'manual',NULL,1,NULL,'2025-11-23 11:14:38'),(199,212,'out',2,'manual',NULL,1,NULL,'2025-11-23 11:15:03'),(200,8,'in',4,'manual',NULL,1,NULL,'2025-11-23 12:55:47'),(201,4,'out',545,'manual',NULL,1,NULL,'2025-11-24 08:42:24'),(202,5,'out',200,'manual',NULL,1,NULL,'2025-11-24 08:42:53'),(203,7,'in',300,'manual',NULL,1,NULL,'2025-11-24 08:44:07'),(204,7,'out',250,'manual',NULL,1,NULL,'2025-11-24 08:44:36'),(205,8,'in',1,'manual',NULL,1,NULL,'2025-11-24 08:44:44'),(206,10,'out',3,'manual',NULL,1,NULL,'2025-11-24 08:44:54'),(207,22,'in',1,'manual',NULL,1,NULL,'2025-11-24 08:45:18'),(208,90,'out',17,'manual',NULL,1,NULL,'2025-11-24 08:45:42'),(209,89,'out',1,'manual',NULL,1,NULL,'2025-11-24 08:45:55'),(210,97,'out',795,'manual',NULL,1,NULL,'2025-11-24 08:46:58'),(211,101,'out',125,'manual',NULL,1,NULL,'2025-11-24 08:47:50'),(212,128,'in',14,'manual',NULL,1,NULL,'2025-11-24 08:48:38'),(213,130,'out',700,'manual',NULL,1,NULL,'2025-11-24 08:49:05'),(214,130,'in',150,'manual',NULL,1,NULL,'2025-11-24 08:49:28'),(216,189,'out',100,'manual',NULL,1,NULL,'2025-11-24 08:50:46'),(217,194,'out',692,'manual',NULL,1,NULL,'2025-11-24 08:51:34'),(218,227,'in',100,'manual',NULL,1,NULL,'2025-11-24 08:52:00'),(219,242,'out',5,'manual',NULL,1,NULL,'2025-11-24 08:52:26'),(220,272,'in',400,'manual',NULL,1,NULL,'2025-11-24 08:53:00'),(221,242,'out',1,'manual',NULL,1,NULL,'2025-11-25 09:04:19'),(222,242,'out',1,'manual',NULL,1,NULL,'2025-11-25 09:05:19'),(223,163,'out',3,'manual',NULL,1,NULL,'2025-11-25 09:05:51'),(224,163,'out',1,'manual',NULL,1,NULL,'2025-11-25 09:06:00'),(225,211,'out',10,'manual',NULL,1,NULL,'2025-11-26 06:35:33'),(226,37,'out',2,'manual',NULL,1,NULL,'2025-11-26 13:05:14'),(227,58,'in',20,'manual',NULL,1,NULL,'2025-11-26 13:06:01'),(228,147,'out',5,'manual',NULL,1,NULL,'2025-11-26 14:09:51'),(229,186,'in',5,'manual',NULL,1,NULL,'2025-11-29 09:21:56'),(230,101,'in',315,'manual',NULL,1,NULL,'2025-11-29 10:34:07'),(231,168,'in',250,'manual',NULL,1,NULL,'2025-11-29 10:35:20'),(232,7,'out',50,'manual',NULL,1,NULL,'2025-11-29 10:36:14'),(233,104,'out',200,'manual',NULL,1,NULL,'2025-11-29 10:37:57'),(234,133,'out',50,'manual',NULL,1,NULL,'2025-11-29 10:38:29'),(235,133,'out',50,'manual',NULL,1,NULL,'2025-11-29 10:38:56'),(236,129,'in',150,'manual',NULL,1,NULL,'2025-11-29 10:39:31'),(237,230,'out',943,'manual',NULL,1,NULL,'2025-11-29 10:40:44'),(238,230,'in',443,'manual',NULL,1,NULL,'2025-11-29 10:41:14'),(239,194,'out',188,'manual',NULL,1,NULL,'2025-11-29 10:41:34'),(240,4,'out',100,'manual',NULL,1,NULL,'2025-11-29 10:42:04'),(241,130,'out',100,'manual',NULL,1,NULL,'2025-11-29 10:42:27'),(242,227,'out',250,'manual',NULL,1,NULL,'2025-11-29 10:43:13'),(243,89,'out',2,'manual',NULL,1,NULL,'2025-11-29 10:44:04'),(245,163,'out',7,'manual',NULL,1,NULL,'2025-11-29 10:58:17'),(246,188,'out',3,'manual',NULL,1,NULL,'2025-11-29 10:58:52'),(247,5,'out',50,'manual',NULL,1,NULL,'2025-11-29 10:59:12'),(248,97,'out',350,'manual',NULL,1,NULL,'2025-11-29 10:59:58'),(250,189,'out',100,'manual',NULL,1,NULL,'2025-11-29 11:04:19'),(251,276,'in',22,'manual',NULL,1,NULL,'2025-11-29 11:05:50'),(252,152,'in',3,'manual',NULL,1,NULL,'2025-11-29 11:38:38'),(253,201,'in',12,'manual',NULL,1,NULL,'2025-11-29 11:45:22'),(254,224,'in',400,'manual',NULL,1,NULL,'2025-11-30 11:10:34'),(255,242,'in',11,'manual',NULL,1,NULL,'2025-11-30 11:11:34'),(256,189,'in',300,'manual',NULL,1,NULL,'2025-11-30 11:12:05'),(257,102,'in',2,'manual',NULL,1,NULL,'2025-11-30 12:58:24'),(258,264,'out',2,'manual',NULL,1,NULL,'2025-12-02 07:05:47'),(259,39,'out',3,'manual',NULL,1,NULL,'2025-12-02 08:42:40'),(260,37,'out',3,'manual',NULL,1,NULL,'2025-12-02 09:37:01'),(261,89,'out',5,'manual',NULL,1,NULL,'2025-12-04 07:22:09'),(262,7,'out',50,'manual',NULL,1,NULL,'2025-12-04 07:27:47'),(263,8,'out',4,'manual',NULL,1,NULL,'2025-12-04 07:27:57'),(264,97,'out',700,'manual',NULL,1,NULL,'2025-12-04 07:28:56'),(265,4,'in',1,'purchase_order',1,1,'','2025-12-07 20:30:45');
/*!40000 ALTER TABLE `inventory_transactions` ENABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `maintenance_schedules`
--

LOCK TABLES `maintenance_schedules` WRITE;
/*!40000 ALTER TABLE `maintenance_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `maintenance_schedules` ENABLE KEYS */;
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
  `tax_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_menu_items_code` (`code`),
  KEY `idx_menu_items_name` (`name`),
  KEY `idx_menu_items_category` (`category_id`),
  KEY `idx_menu_items_recipe` (`recipe_id`),
  KEY `idx_menu_items_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=359 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `menu_items`
--

LOCK TABLES `menu_items` WRITE;
/*!40000 ALTER TABLE `menu_items` DISABLE KEYS */;
INSERT INTO `menu_items` VALUES (1,'2','Mini Zaatar',NULL,NULL,NULL,35.000,0.00,1,1,'2025-12-10 22:40:06',NULL),(2,'3','Cheese Fatayer',NULL,NULL,NULL,40.000,0.00,1,2,'2025-12-10 22:40:06',NULL),(3,'4','Mini Pizza',NULL,NULL,NULL,40.000,0.00,1,3,'2025-12-10 22:40:06',NULL),(4,'5','Mini Kishk',NULL,NULL,NULL,40.000,0.00,1,4,'2025-12-10 22:40:06',NULL),(5,'6','Fatayer Bakleh',NULL,NULL,NULL,40.000,0.00,1,5,'2025-12-10 22:40:06',NULL),(6,'7','Fatayer Spinach',NULL,NULL,NULL,35.000,0.00,1,6,'2025-12-10 22:40:06',NULL),(7,'8','Puff Pastry Hot Dog',NULL,NULL,NULL,60.000,0.00,1,7,'2025-12-10 22:40:06',NULL),(8,'9','Sfiha Lahme',NULL,NULL,NULL,40.000,0.00,1,8,'2025-12-10 22:40:06',NULL),(9,'10','Mini Falafel',NULL,NULL,NULL,50.000,0.00,1,9,'2025-12-10 22:40:06',NULL),(10,'11','Mini Mushroom Quiche',NULL,NULL,NULL,120.000,0.00,1,10,'2025-12-10 22:40:06',NULL),(11,'12','Spring Rolls',NULL,NULL,NULL,40.000,0.00,1,11,'2025-12-10 22:40:06',NULL),(12,'13','Tuna Sandwich',NULL,NULL,NULL,72.000,0.00,1,12,'2025-12-10 22:40:06',NULL),(13,'14','Chicken Sandwich',NULL,NULL,NULL,72.000,0.00,1,13,'2025-12-10 22:40:06',NULL),(14,'15','Halloumi Sandwich',NULL,NULL,NULL,72.000,0.00,1,14,'2025-12-10 22:40:06',NULL),(15,'16','Turkey Sandwich',NULL,NULL,NULL,72.000,0.00,1,15,'2025-12-10 22:40:06',NULL),(16,'17','Boiled Eggs Sandwich',NULL,NULL,NULL,72.000,0.00,1,16,'2025-12-10 22:40:06',NULL),(17,'18','Hotdog',NULL,NULL,NULL,35.000,0.00,1,17,'2025-12-10 22:40:06',NULL),(18,'19','Mini Burger Beef',NULL,NULL,NULL,72.000,0.00,1,18,'2025-12-10 22:40:06',NULL),(19,'20','Mini Burger Chicken',NULL,NULL,NULL,72.000,0.00,1,19,'2025-12-10 22:40:06',NULL),(20,'21','Shrimps Spring Rolls ',NULL,NULL,NULL,45.000,0.00,1,20,'2025-12-10 22:40:06',NULL),(21,'22','Vegetable Plate',NULL,NULL,NULL,120.000,0.00,1,21,'2025-12-10 22:40:06',NULL),(22,'23','Fawarigh',NULL,NULL,NULL,160.000,0.00,1,22,'2025-12-10 22:40:06',NULL),(23,'24','croissant Turkey',NULL,NULL,NULL,120.000,0.00,1,23,'2025-12-10 22:40:06',NULL),(24,'25','Croissant Zaator',NULL,NULL,NULL,96.000,0.00,1,24,'2025-12-10 22:40:06',NULL),(25,'26','Croissant Chocolate',NULL,NULL,NULL,96.000,0.00,1,25,'2025-12-10 22:40:06',NULL),(26,'27','Spniach Pie',NULL,NULL,NULL,120.000,0.00,1,26,'2025-12-10 22:40:06',NULL),(27,'28','Mushroom Pie',NULL,NULL,NULL,130.000,0.00,1,27,'2025-12-10 22:40:06',NULL),(28,'29','Eggs Cheese',NULL,NULL,NULL,120.000,0.00,1,28,'2025-12-10 22:40:06',NULL),(29,'30','croissant halloumi',NULL,NULL,NULL,120.000,0.00,1,29,'2025-12-10 22:40:06',NULL),(30,'31','Tuna puff pastry',NULL,NULL,NULL,96.000,0.00,1,30,'2025-12-10 22:40:06',NULL),(31,'32','Tahini',NULL,NULL,NULL,60.000,0.00,1,31,'2025-12-10 22:40:06',NULL),(32,'33','Steak Veg',NULL,NULL,NULL,320.000,0.00,1,32,'2025-12-10 22:40:06',NULL),(33,'34','Tacos Plate',NULL,NULL,NULL,180.000,0.00,1,33,'2025-12-10 22:40:06',NULL),(34,'35','Pilaf Rice',NULL,NULL,NULL,170.000,0.00,1,34,'2025-12-10 22:40:06',NULL),(35,'36','Lazy Cake',NULL,NULL,NULL,100.000,0.00,1,35,'2025-12-10 22:40:06',NULL),(36,'37','EggPlant',NULL,NULL,NULL,180.000,0.00,1,36,'2025-12-10 22:40:06',NULL),(37,'38','Machewi Mix',NULL,NULL,NULL,185.000,0.00,1,37,'2025-12-10 22:40:06',NULL),(38,'39','Chicken Potato',NULL,NULL,NULL,150.000,0.00,1,38,'2025-12-10 22:40:06',NULL),(39,'40','Sweet potato',NULL,NULL,NULL,120.000,0.00,1,39,'2025-12-10 22:40:06',NULL),(40,'41','Bruchetta',NULL,NULL,NULL,30.000,0.00,1,40,'2025-12-10 22:40:06',NULL),(41,'42','Mozzarella Sticks ',NULL,NULL,NULL,100.000,0.00,1,41,'2025-12-10 22:40:06',NULL),(42,'43','Salmon Pie',NULL,NULL,NULL,180.000,0.00,1,42,'2025-12-10 22:40:06',NULL),(43,'44','Shrimp with cocktail sauce',NULL,NULL,NULL,80.000,0.00,1,43,'2025-12-10 22:40:06',NULL),(44,'45','Mtabbal',NULL,NULL,NULL,70.000,0.00,1,44,'2025-12-10 22:40:06',NULL),(45,'46','Shrimp Avocado Cups',NULL,NULL,NULL,96.000,0.00,1,45,'2025-12-10 22:40:06',NULL),(46,'47','Carrot Cucumber Couliflower Plat',NULL,NULL,NULL,50.000,0.00,1,46,'2025-12-10 22:40:06',NULL),(47,'48','Hommos Makdous',NULL,NULL,NULL,90.000,0.00,1,47,'2025-12-10 22:40:06',NULL),(48,'49','Burghul with Tomato',NULL,NULL,NULL,80.000,0.00,1,48,'2025-12-10 22:40:06',NULL),(49,'50','Chicken Noodles',NULL,NULL,NULL,120.000,0.00,1,49,'2025-12-10 22:40:06',NULL),(50,'51','Quiche Salmon',NULL,NULL,NULL,180.000,0.00,1,50,'2025-12-10 22:40:06',NULL),(51,'52','Chicken Bites Dozen',NULL,NULL,NULL,48.000,0.00,1,51,'2025-12-10 22:40:06',NULL),(52,'53','Kebbeh Raw',NULL,NULL,NULL,120.000,0.00,1,52,'2025-12-10 22:40:06',NULL),(53,'54','Chicken Strogonoff ',NULL,NULL,NULL,160.000,0.00,1,53,'2025-12-10 22:40:06',NULL),(54,'55','Strogonoff Beef',NULL,NULL,NULL,160.000,0.00,1,54,'2025-12-10 22:40:06',NULL),(55,'56','Water Bones',NULL,NULL,NULL,270.000,0.00,1,55,'2025-12-10 22:40:06',NULL),(56,'57','Kebbeh Bi Laban',NULL,NULL,NULL,140.000,0.00,1,56,'2025-12-10 22:40:06',NULL),(57,'58','Shrimp Fajita ',NULL,NULL,NULL,280.000,0.00,1,57,'2025-12-10 22:40:06',NULL),(58,'59','Cheese Rolls 18pcs',NULL,NULL,NULL,52.500,0.00,1,58,'2025-12-10 22:40:06',NULL),(59,'60','Fatayer Cheese',NULL,NULL,NULL,40.000,0.00,1,59,'2025-12-10 22:40:06',NULL),(60,'61','Hindbeh',NULL,NULL,NULL,100.000,0.00,1,60,'2025-12-10 22:40:06',NULL),(61,'62','Mehchi Silik',NULL,NULL,NULL,130.000,0.00,1,61,'2025-12-10 22:40:06',NULL),(62,'63','Fattet Batenjen',NULL,NULL,NULL,100.000,0.00,1,62,'2025-12-10 22:40:06',NULL),(63,'64','Tarator',NULL,NULL,NULL,90.000,0.00,1,63,'2025-12-10 22:40:06',NULL),(64,'65','Mdardara with fried Onions',NULL,NULL,NULL,100.000,0.00,1,64,'2025-12-10 22:40:06',NULL),(65,'66','Hommos','حمص',NULL,NULL,17.000,0.00,1,65,'2025-12-10 22:40:06',NULL),(66,'67','Hommos Layla','حمص ليلى',NULL,NULL,24.000,0.00,1,66,'2025-12-10 22:40:06',NULL),(67,'68','Hommos Beiruti','حمص بيروتي',NULL,NULL,22.000,0.00,1,67,'2025-12-10 22:40:06',NULL),(68,'69','Hommos Ras Asfour','حمص راس عصفور',NULL,NULL,26.000,0.00,1,68,'2025-12-10 22:40:06',NULL),(69,'70','Hommos Shawarma','حمص شاورما',NULL,NULL,26.000,0.00,1,69,'2025-12-10 22:40:06',NULL),(70,'71','Hommos Qawarma','حمص بالقورما',NULL,NULL,26.000,0.00,1,70,'2025-12-10 22:40:06',NULL),(71,'72','Fish Tajen','طاجن سمك',NULL,NULL,32.000,0.00,1,71,'2025-12-10 22:40:06',NULL),(72,'73','Eggplant Moutabal','متبل باذنجان',NULL,NULL,20.000,0.00,1,72,'2025-12-10 22:40:06',NULL),(73,'74','Al Raheb Salad','سلطة الراهب',NULL,NULL,22.000,0.00,1,73,'2025-12-10 22:40:06',NULL),(74,'75','Vine Leaves ','ورق عنب بالزيت',NULL,NULL,24.000,0.00,1,74,'2025-12-10 22:40:06',NULL),(75,'76','Labneh','لبنة',NULL,NULL,18.000,0.00,1,75,'2025-12-10 22:40:06',NULL),(76,'77','Labneh with Gralic & Mint','لبنة بالثوم و النعنع',NULL,NULL,20.000,0.00,1,76,'2025-12-10 22:40:06',NULL),(77,'78','Beetroots  Moutabal','شمندر متبل',NULL,NULL,22.000,0.00,1,77,'2025-12-10 22:40:06',NULL),(78,'79','Eggplant Mosakaa','مسقعة باذنجان',NULL,NULL,22.000,0.00,1,78,'2025-12-10 22:40:06',NULL),(79,'80','Moujadara with Fried Onion','مجدرة مصفاية ',NULL,NULL,18.000,0.00,1,79,'2025-12-10 22:40:06',NULL),(80,'81','Shanklish','شنكليش',NULL,NULL,24.000,0.00,1,80,'2025-12-10 22:40:06',NULL),(81,'82','Loubieh Bl Zeit','لوبية بالزيت',NULL,NULL,22.000,0.00,1,81,'2025-12-10 22:40:06',NULL),(82,'83','Daily Dish 1','الطبق اليومي',NULL,NULL,55.000,0.00,1,82,'2025-12-10 22:40:06',NULL),(83,'84','Daily Dish 2',NULL,NULL,NULL,50.000,0.00,1,83,'2025-12-10 22:40:06',NULL),(84,'85','Daily dish M.S.',NULL,NULL,NULL,40.000,0.00,1,84,'2025-12-10 22:40:06',NULL),(85,'86','Aluminum Pot 175 * 100 pcs per ctn Big size',NULL,NULL,NULL,0.100,0.00,1,85,'2025-12-10 22:40:06',NULL),(86,'87','Glass Cleaner',NULL,NULL,NULL,0.100,0.00,1,86,'2025-12-10 22:40:06',NULL),(87,'90','Daily Dish Full Set','الطبق اليومي',NULL,NULL,65.000,0.00,1,89,'2025-12-10 22:40:06',NULL),(88,'91','Iftar Box Small',NULL,NULL,NULL,18.000,0.00,1,90,'2025-12-10 22:40:06',NULL),(89,'92','Main Dish 1 Portion',NULL,NULL,NULL,200.000,0.00,1,91,'2025-12-10 22:40:06',NULL),(90,'93','Main Dish Half Portion',NULL,NULL,NULL,130.000,0.00,1,92,'2025-12-10 22:40:06',NULL),(91,'94','Asian Daily Dish',NULL,NULL,NULL,12.000,0.00,1,93,'2025-12-10 22:40:06',NULL),(92,'95','funderdome cake',NULL,NULL,NULL,7996.000,0.00,1,94,'2025-12-10 22:40:06',NULL),(93,'96','Daily Record',NULL,NULL,NULL,0.100,0.00,1,95,'2025-12-10 22:40:06',NULL),(94,'97','Daily Dish Monthly 26 Days',NULL,NULL,NULL,42.300,0.00,1,96,'2025-12-10 22:40:06',NULL),(95,'99','Kameh Kg',NULL,NULL,NULL,100.000,0.00,1,98,'2025-12-10 22:40:06',NULL),(96,'100','Kashta 12pcs',NULL,NULL,NULL,50.000,0.00,1,99,'2025-12-10 22:40:06',NULL),(97,'101','Kashta 6pcs',NULL,NULL,NULL,25.000,0.00,1,100,'2025-12-10 22:40:06',NULL),(98,'102','Jouz 12Pcs',NULL,NULL,NULL,50.000,0.00,1,101,'2025-12-10 22:40:06',NULL),(99,'103','Jouz 6Pcs',NULL,NULL,NULL,25.000,0.00,1,102,'2025-12-10 22:40:06',NULL),(100,'104','Maacroun Half Portion',NULL,NULL,NULL,60.000,0.00,1,103,'2025-12-10 22:40:06',NULL),(101,'105','Ouwaymat Half Portion',NULL,NULL,NULL,50.000,0.00,1,104,'2025-12-10 22:40:06',NULL),(102,'106','ouwaymat',NULL,NULL,NULL,100.000,0.00,1,105,'2025-12-10 22:40:06',NULL),(103,'107','Fruit Salad',NULL,NULL,NULL,220.000,0.00,1,106,'2025-12-10 22:40:06',NULL),(104,'108','Rice Pudding','رز بحليب',NULL,NULL,12.000,0.00,1,107,'2025-12-10 22:40:06',NULL),(105,'109','Mouhallabiyah','مهلبيه',NULL,NULL,12.000,0.00,1,108,'2025-12-10 22:40:06',NULL),(106,'110','Meghli with nuts','مغلي بالمكسرات',NULL,NULL,12.000,0.00,1,109,'2025-12-10 22:40:06',NULL),(107,'111','Biscuit Au Chocolate','بسكوت بالشوكولاته',NULL,NULL,15.000,0.00,1,110,'2025-12-10 22:40:06',NULL),(108,'112','Water','مياه معدنية (350مل)',NULL,NULL,4.000,0.00,1,111,'2025-12-10 22:40:06',NULL),(109,'113','Pepsi','بيبسي ',NULL,NULL,6.000,0.00,1,112,'2025-12-10 22:40:06',NULL),(110,'114','Diet Pepsi','بيبسي دايت',NULL,NULL,6.000,0.00,1,113,'2025-12-10 22:40:06',NULL),(111,'115','Mirinda','ميريندا',NULL,NULL,6.000,0.00,1,114,'2025-12-10 22:40:06',NULL),(112,'116','7UP','سفن أب ',NULL,NULL,6.000,0.00,1,115,'2025-12-10 22:40:06',NULL),(113,'117','Diet 7UP','سفن أب دايت',NULL,NULL,6.000,0.00,1,116,'2025-12-10 22:40:06',NULL),(114,'118','Strawberry Mojito','مهيتو بالفراوله',NULL,NULL,22.000,0.00,1,117,'2025-12-10 22:40:06',NULL),(115,'119','Passion Fruit Mojito','موهيتو بالفاكهة الاستوائية',NULL,NULL,22.000,0.00,1,118,'2025-12-10 22:40:06',NULL),(116,'120','Classic Mojito','كلاسيك موهيتو',NULL,NULL,22.000,0.00,1,119,'2025-12-10 22:40:06',NULL),(117,'121','Lemonade','ليموناضه',NULL,NULL,15.000,0.00,1,120,'2025-12-10 22:40:06',NULL),(118,'122','Minted Lemonade','ليموناضه بالنعناع',NULL,NULL,16.000,0.00,1,121,'2025-12-10 22:40:06',NULL),(119,'123','Fresh orange Juice','عصير برتقال',NULL,NULL,15.000,0.00,1,122,'2025-12-10 22:40:06',NULL),(120,'124','Fresh Apple Juice','عصير تفاح',NULL,NULL,15.000,0.00,1,123,'2025-12-10 22:40:06',NULL),(121,'125','Fresh Carrot Juice','عصير جزر',NULL,NULL,15.000,0.00,1,124,'2025-12-10 22:40:06',NULL),(122,'126','Catering',NULL,NULL,NULL,100.000,0.00,1,125,'2025-12-10 22:40:06',NULL),(123,'127','Funderdome Cakes',NULL,NULL,NULL,5250.000,0.00,1,126,'2025-12-10 22:40:06',NULL),(124,'128','Caboodle ',NULL,NULL,NULL,4340.000,0.00,1,127,'2025-12-10 22:40:06',NULL),(125,'129','Miscellaneous',NULL,NULL,NULL,6461.000,0.00,1,128,'2025-12-10 22:40:06',NULL),(126,'130','TeKnwledge Coffee Break ',NULL,NULL,NULL,50.000,0.00,1,129,'2025-12-10 22:40:06',NULL),(127,'131','TeKnowledge Full Catering ',NULL,NULL,NULL,120.000,0.00,1,130,'2025-12-10 22:40:06',NULL),(128,'132','Teknowledge Special Coffee Breack  snacks part of Full day',NULL,NULL,NULL,20.000,0.00,1,131,'2025-12-10 22:40:06',NULL),(129,'133','Teknowledge Full Breakfast only ',NULL,NULL,NULL,50.000,0.00,1,132,'2025-12-10 22:40:06',NULL),(130,'134','beef burger',NULL,NULL,NULL,25.000,0.00,1,133,'2025-12-10 22:40:06',NULL),(131,'135','chicken burger',NULL,NULL,NULL,25.000,0.00,1,134,'2025-12-10 22:40:06',NULL),(132,'136','chicken chawarma',NULL,NULL,NULL,20.000,0.00,1,135,'2025-12-10 22:40:06',NULL),(133,'137','Beef Chawarma',NULL,NULL,NULL,20.000,0.00,1,136,'2025-12-10 22:40:06',NULL),(134,'138','Hot dog',NULL,NULL,NULL,15.000,0.00,1,137,'2025-12-10 22:40:06',NULL),(135,'139','Chips',NULL,NULL,NULL,5.000,0.00,1,138,'2025-12-10 22:40:06',NULL),(136,'140','Coca Cola',NULL,NULL,NULL,8.000,0.00,1,139,'2025-12-10 22:40:06',NULL),(137,'141','Coca Cola Zero',NULL,NULL,NULL,5.000,0.00,1,140,'2025-12-10 22:40:06',NULL),(138,'142','Sprite',NULL,NULL,NULL,5.000,0.00,1,141,'2025-12-10 22:40:06',NULL),(139,'143','Arwa Water',NULL,NULL,NULL,5.000,0.00,1,142,'2025-12-10 22:40:06',NULL),(140,'144','Special Box',NULL,NULL,NULL,40.000,0.00,1,143,'2025-12-10 22:40:06',NULL),(141,'145','Meal Subscription 20 days',NULL,NULL,NULL,800.000,0.00,1,144,'2025-12-10 22:40:06',NULL),(142,'146','Catering Event',NULL,NULL,NULL,2560.000,0.00,1,145,'2025-12-10 22:40:06',NULL),(143,'147','Waiter/Waitress - Monthly Rate 22 Days',NULL,NULL,NULL,0.100,0.00,1,146,'2025-12-10 22:40:06',NULL),(144,'148','Delivery Charge',NULL,NULL,NULL,20.000,0.00,1,147,'2025-12-10 22:40:06',NULL),(145,'149','Avocado Sauce ',NULL,NULL,NULL,30.000,0.00,1,148,'2025-12-10 22:40:06',NULL),(146,'150','Chips ',NULL,NULL,NULL,25.000,0.00,1,149,'2025-12-10 22:40:06',NULL),(147,'151','Batenjen With Cheese',NULL,NULL,NULL,170.000,0.00,1,150,'2025-12-10 22:40:06',NULL),(148,'152','Talabat Orders',NULL,NULL,NULL,0.100,0.00,1,151,'2025-12-10 22:40:06',NULL),(149,'153','Snoonu Orders',NULL,NULL,NULL,0.100,0.00,1,152,'2025-12-10 22:40:06',NULL),(150,'154','Rafeeq Orders',NULL,NULL,NULL,0.100,0.00,1,153,'2025-12-10 22:40:06',NULL),(151,'155','Keeta Orders',NULL,NULL,NULL,0.010,0.00,1,154,'2025-12-10 22:40:06',NULL),(152,'156','Kids Box',NULL,NULL,NULL,20.000,0.00,1,155,'2025-12-10 22:40:06',NULL),(153,'157','Kamhieh',NULL,NULL,NULL,600.000,0.00,1,156,'2025-12-10 22:40:06',NULL),(154,'158','Lamb Chops Raw',NULL,NULL,NULL,160.000,0.00,1,157,'2025-12-10 22:40:06',NULL),(155,'159','Delivery Platform Payment',NULL,NULL,NULL,100.000,0.00,1,158,'2025-12-10 22:40:06',NULL),(156,'160','Mix Grill Platter',NULL,NULL,NULL,185.000,0.00,1,159,'2025-12-10 22:40:06',NULL),(157,'161','Fish Grilled',NULL,NULL,NULL,120.000,0.00,1,160,'2025-12-10 22:40:06',NULL),(158,'162','Farrouj Mechwi (1000 G)','فروج مشوي',NULL,NULL,49.000,0.00,1,161,'2025-12-10 22:40:06',NULL),(159,'163','Farrouj Mechwi  (Half) (500 G)','نصف فروج مشوي',NULL,NULL,28.000,0.00,1,162,'2025-12-10 22:40:06',NULL),(160,'164','Kafta Plate (3 skewers)','صحن كفتة',NULL,NULL,49.000,0.00,1,163,'2025-12-10 22:40:06',NULL),(161,'165','Taouk Plate ','صحن طاووق',NULL,NULL,42.000,0.00,1,164,'2025-12-10 22:40:06',NULL),(162,'166','Chekaf Plate (3 skewers)','صحن شقف',NULL,NULL,62.000,0.00,1,165,'2025-12-10 22:40:06',NULL),(163,'167','Kafta Djej Plate (3 skewers)','صحن كفتة دجاج',NULL,NULL,40.000,0.00,1,166,'2025-12-10 22:40:06',NULL),(164,'168','Lamb Chops Plate (450 G)','صحن ريش مشوي',NULL,NULL,75.000,0.00,1,167,'2025-12-10 22:40:06',NULL),(165,'169','Mix Grill Platter (4 skewers)','صحن مشاوي مشكل',NULL,NULL,62.000,0.00,1,168,'2025-12-10 22:40:06',NULL),(166,'170','Shawarma Meat Plate (220 G)','صحن شورما لحمة',NULL,NULL,38.000,0.00,1,169,'2025-12-10 22:40:06',NULL),(167,'171','Shawarma Chicken Plate (220 G)','صحن شورما دجاج',NULL,NULL,36.000,0.00,1,170,'2025-12-10 22:40:06',NULL),(168,'172','Grilled Shrimp Plate (380 G)','روبيان مشوي',NULL,NULL,75.000,0.00,1,171,'2025-12-10 22:40:06',NULL),(169,'173','Arayes Kafta Plate (150 G)','صحن عرايس كفتة',NULL,NULL,28.000,0.00,1,172,'2025-12-10 22:40:06',NULL),(170,'174','Tochka (150 G)','طوشكا',NULL,NULL,32.000,0.00,1,173,'2025-12-10 22:40:06',NULL),(171,'175','Kafta (KG)','كفتة (كيلو)',NULL,NULL,145.000,0.00,1,174,'2025-12-10 22:40:06',NULL),(172,'176','Taouk (KG)','طاووق (كيلو)',NULL,NULL,120.000,0.00,1,175,'2025-12-10 22:40:06',NULL),(173,'177','Chekaf (KG)','شقف (كيلو)',NULL,NULL,165.000,0.00,1,176,'2025-12-10 22:40:06',NULL),(174,'178','Kafta Djej (KG)','كفتة دجاج (كيلو)',NULL,NULL,90.000,0.00,1,177,'2025-12-10 22:40:06',NULL),(175,'179','Lamb Chops (KG)','ريش مشوي (كيلو)',NULL,NULL,175.000,0.00,1,178,'2025-12-10 22:40:06',NULL),(176,'180','Mix Grill (KG)','مشاوي مشكل (كيلو)',NULL,NULL,130.000,0.00,1,179,'2025-12-10 22:40:06',NULL),(177,'181','Grilled Shrimp (KG)','روبيان مشوي (كيلو)',NULL,NULL,180.000,0.00,1,180,'2025-12-10 22:40:06',NULL),(178,'182','Pumpkin Kebbeh 1 Dozen',NULL,NULL,NULL,40.000,0.00,1,181,'2025-12-10 22:40:06',NULL),(179,'183','Cheese Rolls 12 pcs',NULL,NULL,NULL,35.000,0.00,1,182,'2025-12-10 22:40:06',NULL),(180,'184','Msakhan Chicken',NULL,NULL,NULL,40.000,0.00,1,183,'2025-12-10 22:40:06',NULL),(181,'185','Zaatar',NULL,NULL,NULL,35.000,0.00,1,184,'2025-12-10 22:40:06',NULL),(182,'186','Kebbeh 6 pcs',NULL,NULL,NULL,24.000,0.00,1,185,'2025-12-10 22:40:06',NULL),(183,'187','Chicken Strips',NULL,NULL,NULL,160.000,0.00,1,186,'2025-12-10 22:40:06',NULL),(184,'188','Chiken potato',NULL,NULL,NULL,150.000,0.00,1,187,'2025-12-10 22:40:06',NULL),(185,'189','Soup',NULL,NULL,NULL,80.000,0.00,1,188,'2025-12-10 22:40:06',NULL),(186,'190','Falafel 12pcs',NULL,NULL,NULL,40.000,0.00,1,189,'2025-12-10 22:40:06',NULL),(187,'191','Sojok Pomegranate Syrup (120 G)','سجق بدبس الرمان',NULL,NULL,34.000,0.00,1,190,'2025-12-10 22:40:06',NULL),(188,'192','Sojok with Vegetable (120 G)','سجق بالخضار',NULL,NULL,32.000,0.00,1,191,'2025-12-10 22:40:06',NULL),(189,'193','Makanek Pomegranate Syrup (120 G)','نقانق بدس الرمان',NULL,NULL,32.000,0.00,1,192,'2025-12-10 22:40:06',NULL),(190,'194','Makanek With Lemon & Garlic (120 G)','نقانق بالليمون والثوم',NULL,NULL,30.000,0.00,1,193,'2025-12-10 22:40:06',NULL),(191,'195','Chicken Liver with Lemon & Garlic (150 G)','كبدة الدجاج بالحامض و الثوم',NULL,NULL,22.000,0.00,1,194,'2025-12-10 22:40:06',NULL),(192,'196','Chicken Liver with Ponegranate Syrup (150 G)','كبدة الدجاج بدبس الرمان',NULL,NULL,24.000,0.00,1,195,'2025-12-10 22:40:06',NULL),(193,'197','Kafta Hmaimees','كفتة حميميص',NULL,NULL,32.000,0.00,1,196,'2025-12-10 22:40:06',NULL),(194,'198','Mutton Liver (150 G)','كبدة الغنم',NULL,NULL,22.000,0.00,1,197,'2025-12-10 22:40:06',NULL),(195,'199','MUTTON HEAD','راس نيفا غنم',NULL,NULL,120.000,0.00,1,198,'2025-12-10 22:40:06',NULL),(196,'200','Asafir Tyan (6 pieces)','عصفور التين ',NULL,NULL,90.000,0.00,1,199,'2025-12-10 22:40:06',NULL),(197,'201','Chicken Wings Provencale (450 G)','جوانح دجاج بالكزبرة و الثوم',NULL,NULL,36.000,0.00,1,200,'2025-12-10 22:40:06',NULL),(198,'202','Batata Harra','بطاطا حرة',NULL,NULL,20.000,0.00,1,201,'2025-12-10 22:40:06',NULL),(199,'203','French Fries','بطاطا مقلية',NULL,NULL,17.000,0.00,1,202,'2025-12-10 22:40:06',NULL),(200,'204','Kebbeh 12 pcs','كبة أقراص',NULL,NULL,45.000,0.00,1,203,'2025-12-10 22:40:06',NULL),(201,'205','Sambousek Lahmeh','سمبوسك باللحمة (6 حبّات)',NULL,NULL,40.000,0.00,1,204,'2025-12-10 22:40:06',NULL),(202,'206','Sambousek Cheese ','سمبوسك باللجبنة (6 حبّات)',NULL,NULL,40.000,0.00,1,205,'2025-12-10 22:40:06',NULL),(203,'207','Cheese & Basterma Roll (6 pcs)','رقائق البسترما بالجبنة (6 حبّات)',NULL,NULL,24.000,0.00,1,206,'2025-12-10 22:40:06',NULL),(204,'208','Cheese Roll (6 pcs)','رقائق بالجبنة (6 حبّات)',NULL,NULL,18.000,0.00,1,207,'2025-12-10 22:40:06',NULL),(205,'209','Fatayer Spinach (6 pcs)','فطائر السبانخ (6 حبّات)',NULL,NULL,22.000,0.00,1,208,'2025-12-10 22:40:06',NULL),(206,'210','Fatayer Green Zaatar  (6 pcs)','فطائر بالزعتر الأخضر (6 حبّات)',NULL,NULL,24.000,0.00,1,209,'2025-12-10 22:40:06',NULL),(207,'211','Fatayer Potato (6 pcs)','فطائر بالبطاطا (6 حبّات)',NULL,NULL,22.000,0.00,1,210,'2025-12-10 22:40:06',NULL),(208,'212','Fatayer Bakleh (6 pcs)','فطائر البقلة (6 حبّات)',NULL,NULL,24.000,0.00,1,211,'2025-12-10 22:40:06',NULL),(209,'213','Mix Moajanat (12 pcs)','معجنات مشكلة (12 حبة)',NULL,NULL,45.000,0.00,1,212,'2025-12-10 22:40:06',NULL),(210,'214','Grilled Halloumi','حلوم مشوي',NULL,NULL,29.000,0.00,1,213,'2025-12-10 22:40:06',NULL),(211,'215','Bayd Ghanam (150 G)','بيض غنم',NULL,NULL,24.000,0.00,1,214,'2025-12-10 22:40:06',NULL),(212,'216','Lamb Brains (150 G)','نخاعات غنم',NULL,NULL,25.000,0.00,1,215,'2025-12-10 22:40:06',NULL),(213,'217','Lamb tongues (180 G)','لسانات غنم',NULL,NULL,28.000,0.00,1,216,'2025-12-10 22:40:06',NULL),(214,'218','Warak 3inab Veg',NULL,NULL,NULL,130.000,0.00,1,217,'2025-12-10 22:40:06',NULL),(215,'219','Kebbeh Bil Sayniye',NULL,NULL,NULL,120.000,0.00,1,218,'2025-12-10 22:40:06',NULL),(216,'220','Kafta with Potato',NULL,NULL,NULL,200.000,0.00,1,219,'2025-12-10 22:40:06',NULL),(217,'221','Koussa Mehchi',NULL,NULL,NULL,200.000,0.00,1,220,'2025-12-10 22:40:06',NULL),(218,'222','Warak 3inab Meat',NULL,NULL,NULL,350.000,0.00,1,221,'2025-12-10 22:40:06',NULL),(219,'223','Roast Beef Mashed Potato Veg',NULL,NULL,NULL,250.000,0.00,1,222,'2025-12-10 22:40:06',NULL),(220,'224','roast beef and veg',NULL,NULL,NULL,280.000,0.00,1,223,'2025-12-10 22:40:06',NULL),(221,'225','Potato Soufle',NULL,NULL,NULL,150.000,0.00,1,224,'2025-12-10 22:40:06',NULL),(222,'226','Siyyadiyeh',NULL,NULL,NULL,300.000,0.00,1,225,'2025-12-10 22:40:06',NULL),(223,'227','Roast Beef',NULL,NULL,NULL,220.000,0.00,1,226,'2025-12-10 22:40:06',NULL),(224,'228','Kharouf Mehchi',NULL,NULL,NULL,320.000,0.00,1,227,'2025-12-10 22:40:06',NULL),(225,'229','Maintenance works',NULL,NULL,NULL,0.100,0.00,1,228,'2025-12-10 22:40:06',NULL),(226,'230','Chicken Supreme',NULL,NULL,NULL,260.000,0.00,1,229,'2025-12-10 22:40:06',NULL),(227,'231','Rice with Meat',NULL,NULL,NULL,300.000,0.00,1,230,'2025-12-10 22:40:06',NULL),(228,'232','Chicken Caju with Nuts',NULL,NULL,NULL,260.000,0.00,1,231,'2025-12-10 22:40:06',NULL),(229,'233','Chicken With Rice',NULL,NULL,NULL,180.000,0.00,1,232,'2025-12-10 22:40:06',NULL),(230,'234','Pumpkin Kebbeh',NULL,NULL,NULL,100.000,0.00,1,233,'2025-12-10 22:40:06',NULL),(231,'235','Shrimp Provencial',NULL,NULL,NULL,160.000,0.00,1,234,'2025-12-10 22:40:06',NULL),(232,'236','Gigot with Vegetables',NULL,NULL,NULL,500.000,0.00,1,235,'2025-12-10 22:40:06',NULL),(233,'237','Lazagna',NULL,NULL,NULL,120.000,0.00,1,236,'2025-12-10 22:40:06',NULL),(234,'238','Spinach Pie',NULL,NULL,NULL,120.000,0.00,1,237,'2025-12-10 22:40:06',NULL),(235,'239','Sawarma Chicken 12 Pcs',NULL,NULL,NULL,48.000,0.00,1,238,'2025-12-10 22:40:06',NULL),(236,'240','Mini Shawarma Meat ',NULL,NULL,NULL,48.000,0.00,1,239,'2025-12-10 22:40:06',NULL),(237,'241','Samkeh Harra',NULL,NULL,NULL,300.000,0.00,1,240,'2025-12-10 22:40:06',NULL),(238,'242','Creamy pasta shrimp',NULL,NULL,NULL,200.000,0.00,1,241,'2025-12-10 22:40:06',NULL),(239,'243','Chicken spinach',NULL,NULL,NULL,180.000,0.00,1,242,'2025-12-10 22:40:06',NULL),(240,'244','Chicken Nouille',NULL,NULL,NULL,160.000,0.00,1,243,'2025-12-10 22:40:06',NULL),(241,'245','Philadelphia',NULL,NULL,NULL,14.000,0.00,1,244,'2025-12-10 22:40:06',NULL),(242,'246','Kharouf Mechi Warak 3inab',NULL,NULL,NULL,650.000,0.00,1,245,'2025-12-10 22:40:06',NULL),(243,'247','Eggplant wiht Cheese',NULL,NULL,NULL,130.000,0.00,1,246,'2025-12-10 22:40:06',NULL),(244,'248','Chicken Wings',NULL,NULL,NULL,100.000,0.00,1,247,'2025-12-10 22:40:06',NULL),(245,'249','Pasta Alfredo',NULL,NULL,NULL,220.000,0.00,1,248,'2025-12-10 22:40:06',NULL),(246,'250','Shrimp Noodles',NULL,NULL,NULL,250.000,0.00,1,249,'2025-12-10 22:40:06',NULL),(247,'251','Moghrabiye',NULL,NULL,NULL,180.000,0.00,1,250,'2025-12-10 22:40:06',NULL),(248,'252','Moujadara ',NULL,NULL,NULL,110.000,0.00,1,251,'2025-12-10 22:40:06',NULL),(249,'253','Mloukhiye',NULL,NULL,NULL,220.000,0.00,1,252,'2025-12-10 22:40:06',NULL),(250,'254','Riz Aa Djej',NULL,NULL,NULL,280.000,0.00,1,253,'2025-12-10 22:40:06',NULL),(251,'255','Mehchi Malfouf',NULL,NULL,NULL,180.000,0.00,1,254,'2025-12-10 22:40:06',NULL),(252,'256','Daoud Basha with Rice',NULL,NULL,NULL,180.000,0.00,1,255,'2025-12-10 22:40:06',NULL),(253,'257','Spinach with Rice',NULL,NULL,NULL,180.000,0.00,1,256,'2025-12-10 22:40:06',NULL),(254,'258','Frickeh Chicken',NULL,NULL,NULL,200.000,0.00,1,257,'2025-12-10 22:40:06',NULL),(255,'259','Koussa Kablama',NULL,NULL,NULL,180.000,0.00,1,258,'2025-12-10 22:40:06',NULL),(256,'260','chich Barak with Rice',NULL,NULL,NULL,180.000,0.00,1,259,'2025-12-10 22:40:06',NULL),(257,'261','Hrissi',NULL,NULL,NULL,240.000,0.00,1,260,'2025-12-10 22:40:06',NULL),(258,'262','Oriental Rice with Chicken',NULL,NULL,NULL,260.000,0.00,1,261,'2025-12-10 22:40:06',NULL),(259,'263','Briyani Meat',NULL,NULL,NULL,200.000,0.00,1,262,'2025-12-10 22:40:06',NULL),(260,'264','Kameh Half Portion',NULL,NULL,NULL,50.000,0.00,1,263,'2025-12-10 22:40:06',NULL),(261,'265','Chich Barak 120 Pcs',NULL,NULL,NULL,325.000,0.00,1,264,'2025-12-10 22:40:06',NULL),(262,'266','Salmon with Vegetables',NULL,NULL,NULL,250.000,0.00,1,265,'2025-12-10 22:40:06',NULL),(263,'267','Chicken Alfredo',NULL,NULL,NULL,280.000,0.00,1,266,'2025-12-10 22:40:06',NULL),(264,'268','Paella',NULL,NULL,NULL,200.000,0.00,1,267,'2025-12-10 22:40:06',NULL),(265,'269','Mehchi Malfouf Raw',NULL,NULL,NULL,90.000,0.00,1,268,'2025-12-10 22:40:06',NULL),(266,'270','Rice with meat Plate',NULL,NULL,NULL,30.000,0.00,1,269,'2025-12-10 22:40:06',NULL),(267,'271','Rice with Chicken Meal',NULL,NULL,NULL,35.000,0.00,1,270,'2025-12-10 22:40:06',NULL),(268,'272','Syrian Lamb Shank  (choice of Rice Majbous - Bukhari - Biryani - Mandi)','موزات غنم سوري (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,75.000,0.00,1,271,'2025-12-10 22:40:06',NULL),(269,'273','Australian Lamb Shank  (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','موزات غنم استرالي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,35.000,0.00,1,272,'2025-12-10 22:40:06',NULL),(270,'274','Kafta Lamb (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','كفتة غنم (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,45.000,0.00,1,273,'2025-12-10 22:40:06',NULL),(271,'275','Mix Grill (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','مشاوي مشكل (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,55.000,0.00,1,274,'2025-12-10 22:40:06',NULL),(272,'276','Half Chicken   (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','تصف فروج مشوي (اختيار الارز من المجبوس -  البخاري - برياني - مندي)',NULL,NULL,35.000,0.00,1,275,'2025-12-10 22:40:06',NULL),(273,'277','Shrimp   (choice of Rice Majbous - Bukhari - Moutabaq - Mandi)','روبيان (اختيار الارز من المجبوس -  البخاري - برياني - مندي)',NULL,NULL,65.000,0.00,1,276,'2025-12-10 22:40:06',NULL),(274,'278','chicken Escalope ',NULL,NULL,NULL,8.000,0.00,1,277,'2025-12-10 22:40:06',NULL),(275,'279','Chicken Burger',NULL,NULL,NULL,10.000,0.00,1,278,'2025-12-10 22:40:06',NULL),(276,'280','Beef Burger',NULL,NULL,NULL,10.000,0.00,1,279,'2025-12-10 22:40:06',NULL),(277,'281','Cutlery',NULL,NULL,NULL,10.000,0.00,1,280,'2025-12-10 22:40:06',NULL),(278,'282','Bakdounis cut 1 Box',NULL,NULL,NULL,45.000,0.00,1,281,'2025-12-10 22:40:06',NULL),(279,'283','Lemon Juice ',NULL,NULL,NULL,15.000,0.00,1,282,'2025-12-10 22:40:06',NULL),(280,'284','Perfecta Mayonnaise',NULL,NULL,NULL,0.100,0.00,1,283,'2025-12-10 22:40:06',NULL),(281,'285','Tomato Cut 1 Box',NULL,NULL,NULL,20.000,0.00,1,284,'2025-12-10 22:40:06',NULL),(282,'286','Mustard Sauce',NULL,NULL,NULL,0.100,0.00,1,285,'2025-12-10 22:40:06',NULL),(283,'287','Ba2dounis',NULL,NULL,NULL,50.000,0.00,1,286,'2025-12-10 22:40:06',NULL),(284,'288','Raheb Salad',NULL,NULL,NULL,70.000,0.00,1,287,'2025-12-10 22:40:06',NULL),(285,'289','Bakleh Salad',NULL,NULL,NULL,120.000,0.00,1,288,'2025-12-10 22:40:06',NULL),(286,'290','Caterart Fetta Salad',NULL,NULL,NULL,220.000,0.00,1,289,'2025-12-10 22:40:06',NULL),(287,'291','Crab Salad',NULL,NULL,NULL,200.000,0.00,1,290,'2025-12-10 22:40:06',NULL),(288,'292','Blue Cheese Salad',NULL,NULL,NULL,200.000,0.00,1,291,'2025-12-10 22:40:06',NULL),(289,'293','Strawberry Salad',NULL,NULL,NULL,220.000,0.00,1,292,'2025-12-10 22:40:06',NULL),(290,'294','Chicken Quinoa Salad',NULL,NULL,NULL,220.000,0.00,1,293,'2025-12-10 22:40:06',NULL),(291,'295','Fresh Salad',NULL,NULL,NULL,220.000,0.00,1,294,'2025-12-10 22:40:06',NULL),(292,'296','Salad ',NULL,NULL,NULL,10.000,0.00,1,295,'2025-12-10 22:40:06',NULL),(293,'297','Special Salad',NULL,NULL,NULL,180.000,0.00,1,296,'2025-12-10 22:40:06',NULL),(294,'298','Kale salad ',NULL,NULL,NULL,200.000,0.00,1,297,'2025-12-10 22:40:06',NULL),(295,'299','Rocca With Goat Cheese Salad',NULL,NULL,NULL,180.000,0.00,1,298,'2025-12-10 22:40:06',NULL),(296,'300','Goat Cheese Salad',NULL,NULL,NULL,220.000,0.00,1,299,'2025-12-10 22:40:06',NULL),(297,'301','Halloumi Salad',NULL,NULL,NULL,160.000,0.00,1,300,'2025-12-10 22:40:06',NULL),(298,'302','Pasta Salad',NULL,NULL,NULL,180.000,0.00,1,301,'2025-12-10 22:40:06',NULL),(299,'303','Caesar Salad',NULL,NULL,NULL,180.000,0.00,1,302,'2025-12-10 22:40:06',NULL),(300,'304','Greek Salad','سلطة يونانية',NULL,NULL,29.000,0.00,1,303,'2025-12-10 22:40:06',NULL),(301,'305','Salata Jabaliyyeh','سلطة جبلية ',NULL,NULL,32.000,0.00,1,304,'2025-12-10 22:40:06',NULL),(302,'306','Fattouch','فتوش',NULL,NULL,24.000,0.00,1,305,'2025-12-10 22:40:06',NULL),(303,'307','Tabbouleh','تبولة',NULL,NULL,20.000,0.00,1,306,'2025-12-10 22:40:06',NULL),(304,'308','Rocca Salad','سلطة الجرجير',NULL,NULL,25.000,0.00,1,307,'2025-12-10 22:40:06',NULL),(305,'309','Spicy Olives Salad','سلطة الزيتون بالحر',NULL,NULL,24.000,0.00,1,308,'2025-12-10 22:40:06',NULL),(306,'310','Layla Special Beets salad','سلطة ليلى',NULL,NULL,29.000,0.00,1,309,'2025-12-10 22:40:06',NULL),(307,'311','Yougurt & Cucumber Salad','خيار باللبن',NULL,NULL,15.000,0.00,1,310,'2025-12-10 22:40:06',NULL),(308,'312','Roast Beef Mini Sandwish 12pcs',NULL,NULL,NULL,72.000,0.00,1,311,'2025-12-10 22:40:06',NULL),(309,'313','Chich Barak 80 Pcs',NULL,NULL,NULL,100.000,0.00,1,312,'2025-12-10 22:40:06',NULL),(310,'314','Fajita Chicken',NULL,NULL,NULL,14.000,0.00,1,313,'2025-12-10 22:40:06',NULL),(311,'315','Fajita Shrimp',NULL,NULL,NULL,16.000,0.00,1,314,'2025-12-10 22:40:06',NULL),(312,'316','Steak Sandwich',NULL,NULL,NULL,96.000,0.00,1,315,'2025-12-10 22:40:06',NULL),(313,'317','Mini Shawarma Chicken ',NULL,NULL,NULL,60.000,0.00,1,316,'2025-12-10 22:40:06',NULL),(314,'318','Sujuk',NULL,NULL,NULL,15.000,0.00,1,317,'2025-12-10 22:40:06',NULL),(315,'319','Assorted Sandwishes',NULL,NULL,NULL,15.000,0.00,1,318,'2025-12-10 22:40:06',NULL),(316,'320','Shawarma Chicken','شاورما دجاج',NULL,NULL,15.000,0.00,1,319,'2025-12-10 22:40:06',NULL),(317,'321','Shawarma Meat','شاورما لحمة',NULL,NULL,16.000,0.00,1,320,'2025-12-10 22:40:06',NULL),(318,'322','Sojok','سجق',NULL,NULL,18.000,0.00,1,321,'2025-12-10 22:40:06',NULL),(319,'323','Makanek','مقانق',NULL,NULL,18.000,0.00,1,322,'2025-12-10 22:40:06',NULL),(320,'324','Chicken Liver','سودة الدجاج',NULL,NULL,17.000,0.00,1,323,'2025-12-10 22:40:06',NULL),(321,'325','Kafta','كفتة',NULL,NULL,18.000,0.00,1,324,'2025-12-10 22:40:06',NULL),(322,'326','Shish Taouk','شيش طاووق',NULL,NULL,18.000,0.00,1,325,'2025-12-10 22:40:06',NULL),(323,'327','Chekaf','شقف',NULL,NULL,19.000,0.00,1,326,'2025-12-10 22:40:06',NULL),(324,'328','Kafta Djej','كفتة دجاج',NULL,NULL,17.000,0.00,1,327,'2025-12-10 22:40:06',NULL),(325,'329','Chicken Marrouch','دجاج مروش',NULL,NULL,17.000,0.00,1,328,'2025-12-10 22:40:06',NULL),(326,'330','Sawdit Ghanam','سودة غنم',NULL,NULL,17.000,0.00,1,329,'2025-12-10 22:40:06',NULL),(327,'331','Special Layla sandwich','سندويس ليلى ',NULL,NULL,20.000,0.00,1,330,'2025-12-10 22:40:06',NULL),(328,'332','Grilled Lamb Burger (Platter)','برغر غنم مشوي (صحن)',NULL,NULL,35.000,0.00,1,331,'2025-12-10 22:40:06',NULL),(329,'333','Grilled Chicken Burger (Platter)','برغر دجاج (صحن)',NULL,NULL,32.000,0.00,1,332,'2025-12-10 22:40:06',NULL),(330,'334','Bayd Ghanam','بيض غنم',NULL,NULL,18.000,0.00,1,333,'2025-12-10 22:40:06',NULL),(331,'335','Lamb Brains','نخاعات غنم',NULL,NULL,17.000,0.00,1,334,'2025-12-10 22:40:06',NULL),(332,'336','Lamb tongues','لسانات غنم',NULL,NULL,19.000,0.00,1,335,'2025-12-10 22:40:06',NULL),(333,'337','Maamoul Dates','معمول بالتمر',NULL,NULL,140.000,0.00,1,336,'2025-12-10 22:40:06',NULL),(334,'338','Maamoul Jozz',NULL,NULL,NULL,150.000,0.00,1,337,'2025-12-10 22:40:06',NULL),(335,'339','Maamoul Pistachio','معمول بالفستق',NULL,NULL,160.000,0.00,1,338,'2025-12-10 22:40:06',NULL),(336,'340','Maamoul Mix',NULL,NULL,NULL,160.000,0.00,1,339,'2025-12-10 22:40:06',NULL),(337,'341','Meghli',NULL,NULL,NULL,20.000,0.00,1,340,'2025-12-10 22:40:06',NULL),(338,'342','Custom Cake',NULL,NULL,NULL,350.000,0.00,1,341,'2025-12-10 22:40:06',NULL),(339,'343','Big Bread',NULL,NULL,NULL,10.000,0.00,1,342,'2025-12-10 22:40:06',NULL),(340,'344','Snayniye',NULL,NULL,NULL,20.000,0.00,1,343,'2025-12-10 22:40:06',NULL),(341,'345','Amhiyye',NULL,NULL,NULL,450.000,0.00,1,344,'2025-12-10 22:40:06',NULL),(342,'346','Small Bread',NULL,NULL,NULL,6.000,0.00,1,345,'2025-12-10 22:40:06',NULL),(343,'347','chich barak 40 pcs',NULL,NULL,NULL,40.000,0.00,1,346,'2025-12-10 22:40:06',NULL),(344,'348','Croissant ',NULL,NULL,NULL,6.000,0.00,1,347,'2025-12-10 22:40:06',NULL),(345,'349','Popsicle',NULL,NULL,NULL,14.000,0.00,1,348,'2025-12-10 22:40:06',NULL),(346,'350','Maakaroun 1 Kg',NULL,NULL,NULL,120.000,0.00,1,349,'2025-12-10 22:40:06',NULL),(347,'351','Sfouf',NULL,NULL,NULL,100.000,0.00,1,350,'2025-12-10 22:40:06',NULL),(348,'352','Cupcake with custome design',NULL,NULL,NULL,8.000,0.00,1,351,'2025-12-10 22:40:06',NULL),(349,'353','Tacos Beef',NULL,NULL,NULL,140.000,0.00,1,352,'2025-12-10 22:40:06',NULL),(350,'354','Australian Lamb WHOLE (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','خروف كامل استرالي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,1.000,0.00,1,353,'2025-12-10 22:40:06',NULL),(351,'355','Australian Lamb HALF (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','نصف خروف استرالي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,650.000,0.00,1,354,'2025-12-10 22:40:06',NULL),(352,'356','Australian Lamb QUARTER (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','ربع خروف استرالي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,350.000,0.00,1,355,'2025-12-10 22:40:06',NULL),(353,'357','Arabic Lamb WHOLE (choice of Rice Majbous - Bukhari - Biryani - Mandi)','خروف كامل عربي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,1.000,0.00,1,356,'2025-12-10 22:40:06',NULL),(354,'358','Arabic Lamb HALF (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','نصف خروف عربي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,790.000,0.00,1,357,'2025-12-10 22:40:06',NULL),(355,'359','Arabic Lamb QUARTER (choice of Rice Majbous - Bukhari - Biryani - Mandi)','ربع خروف عربي (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,400.000,0.00,1,358,'2025-12-10 22:40:06',NULL),(356,'360','Syrian Lamb WHOLE (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','خروف كامل سوري (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,1.000,0.00,1,359,'2025-12-10 22:40:06',NULL),(357,'361','Syrian Lamb HALF (choice of Rice Majbous - Bukhari - Biryani - Mandi)','نصف خروف سوري (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,975.000,0.00,1,360,'2025-12-10 22:40:06',NULL),(358,'362','Syrian Lamb QUARTER (choice of Rice Majbous - Bukhari - Moutabak - Biryani - Mandi)','ربع خروف سوري (اختيار الارز من المجبوس -  البخاري - مطبق - مندي)',NULL,NULL,500.000,0.00,1,361,'2025-12-10 22:40:06',NULL);
/*!40000 ALTER TABLE `menu_items` ENABLE KEYS */;
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
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order` (`order_id`),
  KEY `idx_order_items_menu_item` (`menu_item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `order_items`
--

LOCK TABLES `order_items` WRITE;
/*!40000 ALTER TABLE `order_items` DISABLE KEYS */;
INSERT INTO `order_items` VALUES (1,1,232,'Gigot with Vegetables (236)',1.000,500.000,0.000,500.000,'Completed',0),(2,1,286,'Caterart Fetta Salad (290)',1.000,220.000,0.000,220.000,'Completed',1),(3,2,2,'Cheese Fatayer (3)',1.000,40.000,0.000,40.000,'Completed',0),(4,2,10,'Mini Mushroom Quiche (11)',1.000,120.000,0.000,120.000,'Completed',1),(5,2,4,'Mini Kishk (5)',1.000,40.000,0.000,40.000,'Completed',2),(6,3,4,'Mini Kishk (5)',1.000,40.000,0.000,40.000,'Pending',0),(7,3,5,'Fatayer Bakleh (6)',1.000,40.000,0.000,40.000,'Pending',1),(10,4,23,'croissant Turkey (24)',1.000,120.000,0.000,120.000,'Pending',2),(11,4,26,'Spniach Pie (27)',1.000,120.000,0.000,120.000,'Pending',3),(12,5,6,'Fatayer Spinach (7)',1.000,35.000,0.000,35.000,'Completed',0),(13,5,5,'Fatayer Bakleh (6)',1.000,40.000,0.000,40.000,'Completed',1),(14,5,3,'Mini Pizza (4)',1.000,40.000,0.000,40.000,'Completed',2),(15,5,4,'Mini Kishk (5)',1.000,40.000,0.000,40.000,'Completed',3),(16,5,17,'Hotdog (18)',1.000,35.000,0.000,35.000,'Completed',4);
/*!40000 ALTER TABLE `order_items` ENABLE KEYS */;
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
  `source` enum('POS','Phone','WhatsApp','Subscription','Backoffice') NOT NULL DEFAULT 'Backoffice',
  `is_daily_dish` tinyint(1) NOT NULL DEFAULT 0,
  `type` enum('DineIn','Takeaway','Delivery','Pastry') NOT NULL,
  `status` enum('Draft','Confirmed','InProduction','Ready','OutForDelivery','Delivered','Cancelled') NOT NULL DEFAULT 'Draft',
  `customer_id` int(11) DEFAULT NULL,
  `customer_name_snapshot` varchar(255) DEFAULT NULL,
  `customer_phone_snapshot` varchar(50) DEFAULT NULL,
  `delivery_address_snapshot` text DEFAULT NULL,
  `scheduled_date` date NOT NULL,
  `scheduled_time` time DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `total_before_tax` decimal(12,3) NOT NULL DEFAULT 0.000,
  `tax_amount` decimal(12,3) NOT NULL DEFAULT 0.000,
  `total_amount` decimal(12,3) NOT NULL DEFAULT 0.000,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_orders_order_number` (`order_number`),
  KEY `idx_orders_scheduled_status` (`scheduled_date`,`status`),
  KEY `idx_orders_branch_date` (`branch_id`,`scheduled_date`),
  KEY `idx_orders_customer` (`customer_id`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `orders`
--

LOCK TABLES `orders` WRITE;
/*!40000 ALTER TABLE `orders` DISABLE KEYS */;
INSERT INTO `orders` VALUES (1,'ORD2025-000001',1,'Backoffice',0,'Takeaway','Delivered',420,'Michel','66752347','Unit 95, Zone 55, Street 340, Building Number 184\nJBK Villas Salwa Road','2025-12-11','18:00:00','',720.000,0.000,720.000,1,'2025-12-10 23:00:50','2025-12-10 23:01:52'),(2,'ORD2025-000002',1,'Backoffice',0,'Takeaway','Delivered',6,'Abir','66688230',NULL,'2025-12-11','13:11:00','',200.000,0.000,200.000,1,'2025-12-11 01:12:13','2025-12-11 20:50:26'),(3,'ORD2025-000003',1,'Backoffice',0,'Takeaway','Confirmed',224,'abrar','33833673',NULL,'2025-12-11','13:18:00','',80.000,0.000,80.000,1,'2025-12-11 01:19:12','2025-12-11 01:20:36'),(4,'ORD2025-000004',1,'Backoffice',0,'Takeaway','Confirmed',76,'Amale','','','2025-12-11','16:19:00','',240.000,0.000,240.000,1,'2025-12-11 01:20:09','2025-12-11 01:20:30'),(5,'ORD2025-000005',1,'Backoffice',0,'Takeaway','Delivered',359,'Aida Peltekian','66002467',NULL,'2025-12-11','15:00:00','',190.000,0.000,190.000,1,'2025-12-11 19:55:03','2025-12-11 19:55:33');
/*!40000 ALTER TABLE `orders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `petty_cash_expenses`
--

DROP TABLE IF EXISTS `petty_cash_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  KEY `submitted_by` (`submitted_by`),
  KEY `approved_by` (`approved_by`),
  KEY `idx_petty_cash_expenses_wallet` (`wallet_id`),
  KEY `idx_petty_cash_expenses_category` (`category_id`),
  KEY `idx_petty_cash_expenses_date` (`expense_date`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_expenses`
--

LOCK TABLES `petty_cash_expenses` WRITE;
/*!40000 ALTER TABLE `petty_cash_expenses` DISABLE KEYS */;
INSERT INTO `petty_cash_expenses` VALUES (1,1,1,'2025-12-10','test',100.00,0.00,100.00,'approved',NULL,1,NULL,NULL,'2025-12-10 17:46:44');
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
  PRIMARY KEY (`id`),
  KEY `issued_by` (`issued_by`),
  KEY `idx_petty_cash_issues_wallet` (`wallet_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_issues`
--

LOCK TABLES `petty_cash_issues` WRITE;
/*!40000 ALTER TABLE `petty_cash_issues` DISABLE KEYS */;
INSERT INTO `petty_cash_issues` VALUES (1,1,'2025-12-10',1000.00,'cash','',1,'2025-12-10 17:48:21');
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
  PRIMARY KEY (`id`),
  KEY `reconciled_by` (`reconciled_by`),
  KEY `idx_petty_cash_recon_wallet` (`wallet_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_reconciliations`
--

LOCK TABLES `petty_cash_reconciliations` WRITE;
/*!40000 ALTER TABLE `petty_cash_reconciliations` DISABLE KEYS */;
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
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `petty_cash_wallets`
--

LOCK TABLES `petty_cash_wallets` WRITE;
/*!40000 ALTER TABLE `petty_cash_wallets` DISABLE KEYS */;
INSERT INTO `petty_cash_wallets` VALUES (1,1,'Test',500.00,0.00,1,1,'2025-12-10 17:16:02');
/*!40000 ALTER TABLE `petty_cash_wallets` ENABLE KEYS */;
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
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `total_price` decimal(10,2) DEFAULT 0.00,
  `received_quantity` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `item_id` (`item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_order_items`
--

LOCK TABLES `purchase_order_items` WRITE;
/*!40000 ALTER TABLE `purchase_order_items` DISABLE KEYS */;
INSERT INTO `purchase_order_items` VALUES (1,1,4,1,89.00,89.00,1,'2025-12-07 14:42:36');
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
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `po_number` (`po_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `purchase_orders`
--

LOCK TABLES `purchase_orders` WRITE;
/*!40000 ALTER TABLE `purchase_orders` DISABLE KEYS */;
INSERT INTO `purchase_orders` VALUES (1,'1234567',1,'2025-12-07','2025-12-30','received',89.00,'2025-12-07','',1,'2025-12-07 14:42:36','2025-12-07 20:30:45');
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
  KEY `inventory_item_id` (`inventory_item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recipe_items`
--

LOCK TABLES `recipe_items` WRITE;
/*!40000 ALTER TABLE `recipe_items` DISABLE KEYS */;
INSERT INTO `recipe_items` VALUES (17,1,254,5.000,'KG','unit','ingredient','2025-12-06 09:09:14','2025-12-06 09:09:14'),(18,1,255,11.000,'KG','unit','ingredient','2025-12-06 09:09:14','2025-12-06 09:09:14'),(19,1,1,14.000,'EA','unit','ingredient','2025-12-06 09:09:14','2025-12-06 09:09:14'),(20,1,73,0.002,'KG','unit','ingredient','2025-12-06 09:09:14','2025-12-06 09:09:14');
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
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recipe_productions`
--

LOCK TABLES `recipe_productions` WRITE;
/*!40000 ALTER TABLE `recipe_productions` DISABLE KEYS */;
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
  `overhead_pct` decimal(5,4) DEFAULT 0.0000,
  `selling_price_per_unit` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recipes`
--

LOCK TABLES `recipes` WRITE;
/*!40000 ALTER TABLE `recipes` DISABLE KEYS */;
INSERT INTO `recipes` VALUES (1,'Chicken Curry','',1,55.000,'Box',0.1200,12.00,'2025-12-05 15:34:59','2025-12-06 09:09:14');
/*!40000 ALTER TABLE `recipes` ENABLE KEYS */;
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
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `suppliers`
--

LOCK TABLES `suppliers` WRITE;
/*!40000 ALTER TABLE `suppliers` DISABLE KEYS */;
INSERT INTO `suppliers` VALUES (1,'TechSupplies Inc.','John Smith','john@techsupplies.com','+1-555-123-4567','123 Tech Street, Silicon Valley, CA',NULL,'active','2025-10-29 10:51:50','2025-10-29 10:51:50'),(2,'Office Essentials','Mary Johnson','mary@officeessentials.com','+1-555-987-6543','456 Office Blvd, New York, NY',NULL,'active','2025-10-29 10:51:50','2025-10-29 10:51:50'),(3,'Maintenance Pro','Robert Brown','robert@maintenancepro.com','+1-555-456-7890','789 Tool Ave, Chicago, IL',NULL,'active','2025-10-29 10:51:50','2025-10-29 10:51:50'),(4,'Delta group',NULL,'deltagroup_marketing@deltaco.com.qa',NULL,NULL,'1175205','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(5,'Delta group',NULL,'deltagroup_marketing@deltaco.com.qa',NULL,'Doha','1175205','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(6,'Verde W.l.l.','66868306','roy@verde.qa','66868306','Doha','167979','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(7,'Al Majed Marketing & Distribution','70492285','businessdevelopment@almajedgroup.me','70492285','Doha','1175719','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(8,'Al Maktab Al Qatari Al Hollandi','33813582','food@hollandi.com','33813582','Doha','1175721','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(9,'Yacoob Trading & Contracting','55502705','hsayeh@yatco-qatar.com','55502705','Doha','1175722','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(10,'Gulf Center','59918445','r.basheer@gcfsqatar.com','59918445','Doha','1175723','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(11,'Happy Land Trading & Marketing','55212992','sales@happylandqatar.com','55212992','Doha','1175724','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(12,'Caramel','70473565','info@carameldoha.com','70473565','Doha','1175725','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(13,'Bluefin','66969615','sergio.berbari@bluefinqa.com','66969615',NULL,'1175726','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(14,'Watania W.L.L',NULL,'info@wataniafire.com',NULL,'Doha','1176011','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(15,'Deep Seafood',NULL,NULL,NULL,'Doha','1176110','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(16,'Al Zaman International Catering & Trading','55946999','sales@alzamantrading.com','55946999','Doha','1176307','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(17,'Tesla International Group Contracting & Trading w.l.l.',NULL,NULL,NULL,'Doha','1176443','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(18,'Ideal Qatar',NULL,NULL,NULL,'Doha','1176490','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(19,'Flamex Trade',NULL,NULL,NULL,'Doha','1176560','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(20,'Fresh Meat Factory',NULL,NULL,NULL,'Doha','1176702','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(21,'Awafia Trading W.L.L',NULL,NULL,NULL,'Doha','1177407','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(22,'Tredos Trading',NULL,'ameen@tredostrading.com',NULL,'Doha','1177621','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(23,'Benina Food',NULL,NULL,NULL,'Doha','1177867','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(24,'Qnited Trading Company',NULL,NULL,NULL,'Doha','1178137','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(25,'Packon Trading',NULL,NULL,NULL,'Doha','1178149','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(26,'Fahed Foods w.l.l.',NULL,NULL,NULL,'Doha','1178452','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(27,'BRF',NULL,NULL,NULL,'Doha','1178528','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(28,'Pilot Parties Processing',NULL,NULL,NULL,'Doha','110689','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(29,'Valencia International Trading Company w.l.l.',NULL,NULL,NULL,'Doha','1178929','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(30,'RealPack',NULL,NULL,NULL,'Doha','1179020','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(31,'Friendly Food Qatar w.l.l.',NULL,NULL,NULL,'Doha','1179056','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(32,'Al Hattab For Food Stuffs & Trading',NULL,NULL,NULL,'Doha','1179796','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(33,'Al Rayes Laundry Equipment & Accessories w.l.l.',NULL,NULL,NULL,'Doha','1180027','active','2025-12-10 21:45:16','2025-12-10 21:45:16'),(34,'International Foodstuff Group',NULL,NULL,NULL,'Doha','1180211','active','2025-12-10 21:45:16','2025-12-10 21:45:16');
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
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','admin123','admin@example.com','active','2025-10-29 10:51:18');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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

-- Dump completed on 2025-12-11 21:57:37

