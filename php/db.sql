-- MySQL dump 10.13  Distrib 8.0.44, for Win64 (x86_64)
--
-- Host: gateway01.ap-southeast-1.prod.aws.tidbcloud.com    Database: map_and_pump
-- ------------------------------------------------------
-- Server version	8.0.11-TiDB-v8.5.3-serverless

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
-- Table structure for table `booking`
--

DROP TABLE IF EXISTS `booking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking` (
  `booking_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `station_id` int NOT NULL,
  `oil_type_id` int NOT NULL,
  `qr_code` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `qr_refrence_code` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `filled` tinyint NOT NULL,
  `time_of_filled` datetime DEFAULT NULL,
  `expire_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`booking_id`) /*T![clustered_index] CLUSTERED */,
  KEY `user_id_idx` (`user_id`),
  KEY `station_id_idx` (`station_id`),
  KEY `oil_type_idx` (`oil_type_id`),
  CONSTRAINT `fk_booking_oil_type` FOREIGN KEY (`oil_type_id`) REFERENCES `oil_types` (`oil_type_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=120002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking`
--

LOCK TABLES `booking` WRITE;
/*!40000 ALTER TABLE `booking` DISABLE KEYS */;
INSERT INTO `booking` VALUES (1,4,1,3,'qr','MP-250-0A3I|Yangon Terminal Center|Octane 95|20L','filled',1,'2026-08-08 07:32:39','2026-08-08 07:32:39','2026-08-08 07:32:39');
/*!40000 ALTER TABLE `booking` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `car_license`
--

DROP TABLE IF EXISTS `car_license`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `car_license` (
  `car_license_id` int NOT NULL AUTO_INCREMENT,
  `car_plate_number` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `model_year` int NOT NULL,
  `vehicle_type` varchar(100) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `location` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `filled_gas` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`car_license_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=90002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `car_license`
--

LOCK TABLES `car_license` WRITE;
/*!40000 ALTER TABLE `car_license` DISABLE KEYS */;
INSERT INTO `car_license` VALUES (1,'1234',2000,'Yamaha','Yangon',1,'2026-08-04 06:26:11','2026-08-04 06:26:11');
/*!40000 ALTER TABLE `car_license` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feedback`
--

DROP TABLE IF EXISTS `feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feedback` (
  `feedback_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `feedback` text COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`feedback_id`) /*T![clustered_index] CLUSTERED */,
  KEY `user_id_idx` (`user_id`),
  CONSTRAINT `fk_feedback_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=30001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback`
--

LOCK TABLES `feedback` WRITE;
/*!40000 ALTER TABLE `feedback` DISABLE KEYS */;
INSERT INTO `feedback` VALUES (1,150014,'Low Skill','2026-08-10 09:05:38');
/*!40000 ALTER TABLE `feedback` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `feedback_replies`
--

DROP TABLE IF EXISTS `feedback_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feedback_replies` (
  `reply_id` int NOT NULL AUTO_INCREMENT,
  `feedback_id` int NOT NULL,
  `staff_id` int NOT NULL,
  `reply_text` text NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reply_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=30001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback_replies`
--

LOCK TABLES `feedback_replies` WRITE;
/*!40000 ALTER TABLE `feedback_replies` DISABLE KEYS */;
INSERT INTO `feedback_replies` VALUES (1,1,210014,'Thank You','2026-08-10 09:08:47');
/*!40000 ALTER TABLE `feedback_replies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `headquarter`
--

DROP TABLE IF EXISTS `headquarter`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `headquarter` (
  `headquarter_id` int NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `company_logo` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `contact_number` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `headquarter_email` varchar(225) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `headquarter_bio` varchar(45) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `business_license` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expire_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `business_license_expire_date` varchar(45) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  PRIMARY KEY (`headquarter_id`) /*T![clustered_index] CLUSTERED */,
  UNIQUE KEY `headquarter_email_UNIQUE` (`headquarter_email`),
  UNIQUE KEY `company_logo_UNIQUE` (`company_logo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=180010;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `headquarter`
--

LOCK TABLES `headquarter` WRITE;
/*!40000 ALTER TABLE `headquarter` DISABLE KEYS */;
INSERT INTO `headquarter` VALUES (2,'ADMIN TEST 20260805 HQ Pending','uploads/admin-test-20260805-pending.png','09-10000001','aer@gmail.com',NULL,'Yangon Region — 10 Test Road, Pending Township','LIC-TEST-PENDING-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL),(3,'ADMIN TEST 20260805 HQ Active','uploads/admin-test-20260805-active.png','09-10000002','amar@gmail.com',NULL,'Mandalay Region — 20 Test Road, Active Township','LIC-TEST-ACTIVE-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL),(4,'ADMIN TEST 20260805 HQ Suspended','uploads/admin-test-20260805-suspended.png','09-10000003','kor@gmail.com',NULL,'Naypyidaw — 30 Test Road, Suspended Township','LIC-TEST-SUSPENDED-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL),(5,'ADMIN TEST 20260805 HQ Removed','uploads/admin-test-20260805-removed.png','09-10000004','dankor@gmail.com',NULL,'Yangon Region — 40 Test Road, Removed Township','LIC-TEST-REMOVED-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL),(6,'Dango','hq.png','09881938483','maxer@gmail.com',NULL,'BanyarDala Street','jjko.png','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL),(8,'CODEX REGISTRATION VERIFY 20260805','uploads/codex-verify-logo.png','09-77770000','codex.verify.20260805@example.test',NULL,'Yangon Region — No. 88, Verification Road, Test Township, Yangon','BL-CODEX-VERIFY-20260805','2026-08-05 07:26:06','2031-12-31 23:59:59',NULL),(60010,'Apex Fueling Group - Yangon HQ','uploads/apex-fueling-logo.png','09-421234567','thura.min.apex.20260805@example.test',NULL,'Yangon Region — No. 123, Pyay Road, Kamayut Township, Yangon','BL-YGN-APEX-2026-001','2026-08-07 08:46:02','2031-12-31 23:59:59',NULL),(90010,'testing group','uploads/testing-logo.png','09223344555','tester@gmail.com',NULL,'Yangon Region — tteesstteerr','BL-YGN-test-2026-001','2026-08-08 07:26:10','2030-02-25 23:59:59',NULL);
/*!40000 ALTER TABLE `headquarter` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hq_account_actions`
--

DROP TABLE IF EXISTS `hq_account_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hq_account_actions` (
  `action_id` int unsigned NOT NULL AUTO_INCREMENT,
  `headquarter_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `action_type` varchar(20) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `reason` text COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `notification_status` varchar(30) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'not_configured',
  `notification_note` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`action_id`) /*T![clustered_index] CLUSTERED */,
  KEY `idx_hq_account_actions_hq` (`headquarter_id`),
  KEY `idx_hq_account_actions_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=90002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hq_account_actions`
--

LOCK TABLES `hq_account_actions` WRITE;
/*!40000 ALTER TABLE `hq_account_actions` DISABLE KEYS */;
INSERT INTO `hq_account_actions` VALUES (1,4,6,'suspend','Seeded action history entry for suspend/remove detail testing.','not_configured','Seeded test action; no email was sent.','2026-08-05 06:32:38');
/*!40000 ALTER TABLE `hq_account_actions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `log`
--

DROP TABLE IF EXISTS `log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `log` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `action` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_log_user_id_idx` (`user_id`),
  CONSTRAINT `fk_log_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=60001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `log`
--

LOCK TABLES `log` WRITE;
/*!40000 ALTER TABLE `log` DISABLE KEYS */;
INSERT INTO `log` VALUES (30001,60018,'Updated admin profile avatar.','2026-08-10 08:09:53'),(30002,60018,'Updated admin profile avatar.','2026-08-10 08:11:09'),(30003,60018,'Generated report #30001 (hq_performance).','2026-08-10 08:25:41');
/*!40000 ALTER TABLE `log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `lwk_license`
--

DROP TABLE IF EXISTS `lwk_license`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lwk_license` (
  `lwk_license_id` int NOT NULL AUTO_INCREMENT,
  `plate_number` varchar(100) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`lwk_license_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=90002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `lwk_license`
--

LOCK TABLES `lwk_license` WRITE;
/*!40000 ALTER TABLE `lwk_license` DISABLE KEYS */;
INSERT INTO `lwk_license` VALUES (1,'1N-1234','2026-08-07 07:05:47'),(2,'2N-1334','2026-08-07 07:05:47'),(3,'1N-3243','2026-08-07 07:05:47'),(4,'4N-3424','2026-08-07 07:05:47'),(5,'5G-3453','2026-08-07 07:05:47'),(6,'9E-9999','2026-08-07 07:05:47'),(7,'9E-2344','2026-08-07 07:05:48'),(8,'8P-3213','2026-08-07 07:05:48');
/*!40000 ALTER TABLE `lwk_license` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `new_table`
--

DROP TABLE IF EXISTS `new_table`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `new_table` (
  `user_car_id` int NOT NULL,
  `user_id` int NOT NULL,
  `car_id` int NOT NULL,
  PRIMARY KEY (`user_car_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_user_id_idx` (`user_id`),
  KEY `fk_car_id_idx` (`car_id`),
  CONSTRAINT `fk_car_id` FOREIGN KEY (`car_id`) REFERENCES `car_license` (`car_license_id`),
  CONSTRAINT `fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `new_table`
--

LOCK TABLES `new_table` WRITE;
/*!40000 ALTER TABLE `new_table` DISABLE KEYS */;
/*!40000 ALTER TABLE `new_table` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `notification_id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_id` int unsigned DEFAULT NULL,
  `recipient_user_id` int unsigned DEFAULT NULL,
  `recipient_email` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `notification_type` varchar(40) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `message` text COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `delivery_status` varchar(30) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'not_configured',
  `delivery_note` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`) /*T![clustered_index] CLUSTERED */,
  KEY `idx_notifications_request` (`request_id`),
  KEY `idx_notifications_recipient` (`recipient_user_id`),
  KEY `idx_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=150004;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (3,7,5,'admin-test-active-owner-20260805@example.test','request_approved','Admin test request approved','This seeded notification is available for approval workflow testing.','not_configured','Seeded test notification; no email was sent.','2026-08-05 06:32:38'),(30004,6,5,'admin-test-active-owner-20260805@example.test','request_approved','Map & Pump request TEST-20260805-002: Approved','Your request TEST-20260805-002 for ADMIN TEST 20260805 HQ Active has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-07 07:20:30'),(60004,30010,4,'admin-test-pending-owner-20260805@example.test','request_rejected','Map & Pump request HQ-REG-2: Rejected','Your request HQ-REG-2 for ADMIN TEST 20260805 HQ Pending was rejected.\n\nReason:\ntest','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-07 08:59:54'),(60005,30012,60014,'thura.min.apex.20260805@example.test','request_approved','Map & Pump request HQ-REG-60010: Approved','Your request HQ-REG-60010 for Apex Fueling Group - Yangon HQ has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-07 09:00:02');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `oil_types`
--

DROP TABLE IF EXISTS `oil_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oil_types` (
  `oil_type_id` int NOT NULL AUTO_INCREMENT,
  `oil_name` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`oil_type_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=120002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `oil_types`
--

LOCK TABLES `oil_types` WRITE;
/*!40000 ALTER TABLE `oil_types` DISABLE KEYS */;
INSERT INTO `oil_types` VALUES (1,'Octane 92'),(2,'Octane 95'),(3,'Disel'),(4,'Premium Disel');
/*!40000 ALTER TABLE `oil_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `report`
--

DROP TABLE IF EXISTS `report`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `report` (
  `report_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `role_id` int NOT NULL,
  `importance` varchar(10) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `short_summery` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_report_user_id_idx` (`user_id`),
  KEY `fk_report_role_id_idx` (`role_id`),
  CONSTRAINT `fk_report_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_report_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=60001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report`
--

LOCK TABLES `report` WRITE;
/*!40000 ALTER TABLE `report` DISABLE KEYS */;
INSERT INTO `report` VALUES (30001,60018,2,'normal','August HQ performance summary','[scope=hq_performance][format=pdf][start=2022-12-01][end=2023-12-01] HQ records: 9; active linked accounts: 2','2026-08-10 08:25:41');
/*!40000 ALTER TABLE `report` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `role_id` int NOT NULL AUTO_INCREMENT,
  `role_name` varchar(100) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  PRIMARY KEY (`role_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=120002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Admin'),(2,'Moderator'),(3,'HQ'),(4,'Manager'),(5,'Staff'),(6,'Customer');
/*!40000 ALTER TABLE `roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `session_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expires_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`session_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_sessions_user_id_idx` (`user_id`),
  CONSTRAINT `fk_sessions_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `station_oil_inventory`
--

DROP TABLE IF EXISTS `station_oil_inventory`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `station_oil_inventory` (
  `inventory_id` int NOT NULL AUTO_INCREMENT,
  `station_id` int NOT NULL,
  `oil_type_id` int NOT NULL,
  `price_per_litre` int NOT NULL,
  `is_active` tinyint(1) NOT NULL,
  `current_amount_liters` decimal(12,3) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`inventory_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_station_oil_inventory_station_id_idx` (`station_id`),
  KEY `fk_station_oil_inventory_oil_type_id` (`oil_type_id`),
  CONSTRAINT `fk_station_oil_inventory_oil_type_id` FOREIGN KEY (`oil_type_id`) REFERENCES `oil_types` (`oil_type_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_station_oil_inventory_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=90002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `station_oil_inventory`
--

LOCK TABLES `station_oil_inventory` WRITE;
/*!40000 ALTER TABLE `station_oil_inventory` DISABLE KEYS */;
INSERT INTO `station_oil_inventory` VALUES (1,1,1,500,1,6000.000,'2026-08-07 09:08:55'),(2,1,2,256,1,6000.000,'2026-08-07 09:08:55'),(3,1,3,215,1,6000.000,'2026-08-07 09:08:56'),(4,1,4,559,1,6000.000,'2026-08-07 09:08:56'),(5,2,1,568,1,7500.000,'2026-08-07 09:08:57'),(6,2,2,658,1,500.000,'2026-08-07 09:08:57'),(7,3,3,569,1,900.000,'2026-08-07 09:08:58'),(8,2,4,564,1,6000.000,'2026-08-07 09:08:58');
/*!40000 ALTER TABLE `station_oil_inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stations`
--

DROP TABLE IF EXISTS `stations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stations` (
  `station_id` int NOT NULL AUTO_INCREMENT,
  `station_name` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `location` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`station_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=150003;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stations`
--

LOCK TABLES `stations` WRITE;
/*!40000 ALTER TABLE `stations` DISABLE KEYS */;
INSERT INTO `stations` VALUES (1,'Danko(Mdy)','Mandalay','2026-08-03 08:47:41','2026-08-03 08:47:41'),(2,'ADMIN TEST 20260805 Station','Mandalay Region — Test Township','2026-08-05 06:32:35','2026-08-05 06:32:35'),(3,'Danko(TKT)','Tarkayta Yangon','2026-08-07 06:55:41','2026-08-07 06:55:41'),(60003,'Myanko','South Dagon','2026-08-07 08:42:07','2026-08-07 08:42:07'),(60004,'Shoko','North Dagon','2026-08-07 08:42:07','2026-08-07 08:42:07'),(60005,'Niko','East Dagon','2026-08-07 08:42:07','2026-08-07 08:42:07'),(60006,'Eko','Wast Dagon','2026-08-07 08:42:08','2026-08-07 08:42:08');
/*!40000 ALTER TABLE `stations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_requests`
--

DROP TABLE IF EXISTS `system_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_requests` (
  `request_id` int unsigned NOT NULL AUTO_INCREMENT,
  `reference_code` varchar(40) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `request_type` varchar(60) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'pending',
  `submitter_user_id` int unsigned DEFAULT NULL,
  `headquarter_id` int unsigned DEFAULT NULL,
  `sender_entity` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `sender_name` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `sender_email` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `request_details` text COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `document_name` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `document_path` varchar(500) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `review_reason` text COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`) /*T![clustered_index] CLUSTERED */,
  UNIQUE KEY `uq_system_requests_reference` (`reference_code`),
  KEY `idx_system_requests_status` (`status`),
  KEY `idx_system_requests_type` (`request_type`),
  KEY `idx_system_requests_created` (`created_at`),
  KEY `idx_system_requests_user` (`submitter_user_id`),
  KEY `idx_system_requests_hq` (`headquarter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=150010;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_requests`
--

LOCK TABLES `system_requests` WRITE;
/*!40000 ALTER TABLE `system_requests` DISABLE KEYS */;
INSERT INTO `system_requests` VALUES (5,'TEST-20260805-001','new_station_registration','pending',4,2,'ADMIN TEST 20260805 HQ Pending','Admin Test Pending Owner','admin-test-pending-owner-20260805@example.test','Yangon Region — 10 Test Road, Pending Township','New station registration request for the test district.','station-registration-test.pdf','uploads/admin-test-20260805/001-station-registration-test.pdf',NULL,NULL,NULL,'2026-08-05 06:32:37','2026-08-05 06:32:37'),(6,'TEST-20260805-002','license_modification','approved',5,3,'ADMIN TEST 20260805 HQ Active','Admin Test Active Owner','admin-test-active-owner-20260805@example.test','Mandalay Region — 20 Test Road, Active Township','License modification request awaiting administrator review.','license-modification-test.pdf','uploads/admin-test-20260805/002-license-modification-test.pdf','Approved by administrator.',NULL,'2026-08-07 07:20:30','2026-08-05 06:32:37','2026-08-07 07:20:30'),(7,'TEST-20260805-003','license_modification','approved',5,3,'ADMIN TEST 20260805 HQ Active','Admin Test Active Owner','admin-test-active-owner-20260805@example.test','Mandalay Region — 20 Test Road, Active Township','Previously approved license modification for summary-card testing.','license-approved-test.pdf','uploads/admin-test-20260805/003-license-approved-test.pdf','Seeded approved request for admin summary and notification testing.',NULL,'2026-08-05 06:32:37','2026-08-05 06:32:37','2026-08-05 06:32:37'),(8,'TEST-20260805-004','account_deactivation','rejected',7,5,'ADMIN TEST 20260805 HQ Removed','Admin Test Removed Owner','admin-test-removed-owner-20260805@example.test','Yangon Region — 40 Test Road, Removed Township','Deactivation request rejected for filter and rejection-message testing.','deactivation-rejected-test.pdf','uploads/admin-test-20260805/004-deactivation-rejected-test.pdf','Seeded rejection reason for the request rejection modal test.',NULL,'2026-08-05 06:32:38','2026-08-05 06:32:37','2026-08-05 06:32:38'),(9,'TEST-20260805-005','station','flagged',6,4,'ADMIN TEST 20260805 HQ Suspended','Admin Test Suspended Owner','admin-test-suspended-owner-20260805@example.test','Naypyidaw — 30 Test Road, Suspended Township','Station request flagged for review and escalation testing.','station-flagged-test.pdf','uploads/admin-test-20260805/005-station-flagged-test.pdf','Seeded flagged reason for status display and filter testing.',NULL,'2026-08-05 06:32:38','2026-08-05 06:32:37','2026-08-05 06:32:38'),(30010,'HQ-REG-2','hq_registration','rejected',4,2,'ADMIN TEST 20260805 HQ Pending','admin_test_pending_owner','admin-test-pending-owner-20260805@example.test','Yangon Region — 10 Test Road, Pending Township','HQ account registered through Register New Headquarter. Contact: 09-10000001. License expiry: 2026-08-05.','Business license','LIC-TEST-PENDING-20260805','test',NULL,'2026-08-07 08:59:54','2026-08-07 08:52:37','2026-08-07 08:59:54'),(30011,'HQ-REG-8','hq_registration','pending',12,8,'CODEX REGISTRATION VERIFY 20260805','codex_verify_owner','codex.verify.20260805@example.test','Yangon Region — No. 88, Verification Road, Test Township, Yangon','HQ account registered through Register New Headquarter. Contact: 09-77770000. License expiry: 2031-12-31.','Business license','BL-CODEX-VERIFY-20260805',NULL,NULL,NULL,'2026-08-07 08:52:37','2026-08-07 08:52:37'),(30012,'HQ-REG-60010','hq_registration','approved',60014,60010,'Apex Fueling Group - Yangon HQ','thura_min','thura.min.apex.20260805@example.test','Yangon Region — No. 123, Pyay Road, Kamayut Township, Yangon','HQ account registered through Register New Headquarter. Contact: 09-421234567. License expiry: 2031-12-31.','Business license','BL-YGN-APEX-2026-001','Approved by administrator.',NULL,'2026-08-07 09:00:02','2026-08-07 08:52:37','2026-08-07 09:00:02'),(60010,'HQ-REG-90010','hq_registration','pending',90014,90010,'testing group','tester testing','tester@gmail.com','Yangon Region — tteesstteerr','New HQ registration submitted from the admin Register New Headquarter panel. Contact: 09223344555. Company logo: uploads/testing-logo.png. License expiry: 2030-02-25.','Business license','BL-YGN-test-2026-001',NULL,NULL,NULL,'2026-08-08 07:26:10','2026-08-08 07:26:10');
/*!40000 ALTER TABLE `system_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_license`
--

DROP TABLE IF EXISTS `user_license`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_license` (
  `license_id` int NOT NULL AUTO_INCREMENT,
  `license_image` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `license_number` varchar(100) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `license_name` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `phone_number` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `nrc_number` varchar(100) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `date_of_birth` date NOT NULL,
  `blood_type` varchar(10) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `license_last_valid_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`license_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=90003;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_license`
--

LOCK TABLES `user_license` WRITE;
/*!40000 ALTER TABLE `user_license` DISABLE KEYS */;
INSERT INTO `user_license` VALUES (1,'image','1234','B','12345678','34567','2000-12-20','O','2016-09-10','2026-08-04 06:24:11','2026-08-04 06:24:11'),(2,'license_image.jpg','123456789','John Doe','555-1234','NRC123456','1990-01-01','O+','2025-12-31','2026-08-05 06:37:28','2026-08-05 06:37:28');
/*!40000 ALTER TABLE `user_license` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `user_name` varchar(225) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `hashed_password` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `license_id` int DEFAULT NULL,
  `car_license_id` int DEFAULT NULL,
  `headquarter_id` int DEFAULT NULL,
  `role_id` int DEFAULT NULL,
  `business_role_id` int DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `oauth_provider` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `oauth_provider_id` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `oauth_avatar_url` varchar(500) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`user_id`) /*T![clustered_index] CLUSTERED */,
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_oauth_provider_id` (`oauth_provider`,`oauth_provider_id`),
  KEY `fk_users_license_id_idx` (`license_id`),
  KEY `fk_users_headquarter_id_idx` (`headquarter_id`),
  KEY `fk_users_role_id_idx` (`role_id`),
  KEY `fk_business_role_id_idx` (`business_role_id`),
  CONSTRAINT `fk_users_headquarter_id` FOREIGN KEY (`headquarter_id`) REFERENCES `headquarter` (`headquarter_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_users_license_id` FOREIGN KEY (`license_id`) REFERENCES `user_license` (`license_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_business_role_id` FOREIGN KEY (`business_role_id`) REFERENCES `roles` (`role_id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=300014;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (4,'admin_test_pending_owner','admin-test-pending-owner-20260805@example.test','$2y$12$TjVx96cvj5VBIYSZRyZ2AeDZsh/CG6fXWEjpEmcP89zlEbyTArXzm',NULL,NULL,2,1,NULL,'rejected','2026-08-07 08:59:54','2026-08-07 08:59:54',NULL,NULL,NULL,NULL),(5,'admin_test_active_owner','admin-test-active-owner-20260805@example.test','$2y$12$t7uyDkU/8Lyu4LzIZllkUuHjv0HJM0BniOpCcR1tA.n7SOdoU.JVa',NULL,NULL,3,1,NULL,'approved','2026-08-07 07:20:30','2026-08-07 07:20:30',NULL,NULL,NULL,NULL),(6,'admin_test_suspended_owner','admin-test-suspended-owner-20260805@example.test','$2y$12$gcUUWoJ/VSfvM2T.4z6.puCIK98Nmd8HBwqj9kKhScvXSlxv5BSh6',NULL,NULL,4,1,NULL,'suspended','2026-08-05 06:32:36','2026-08-05 06:32:36',NULL,NULL,NULL,NULL),(7,'admin_test_removed_owner','admin-test-removed-owner-20260805@example.test','$2y$12$3exwXVIQRqKrhuIw52qq5eir1Y862gHkJo/WG1QseaKMu7h5M5WrS',NULL,NULL,5,1,NULL,'removed','2026-08-05 06:32:36','2026-08-05 06:32:36',NULL,NULL,NULL,NULL),(8,'admin_test_active_operator','admin-test-active-operator-20260805@example.test','$2y$12$NCWhFV6J1W7EG5G9SPQbnOAaon0DfimXpTA3nMwvldQvUqNuQtq0q',NULL,NULL,3,1,NULL,'pending','2026-08-05 06:32:37','2026-08-05 06:32:37',NULL,NULL,NULL,NULL),(10,'test_active','admin-test-active-operator-2026005@example.test','$2y$12$NCWhFV6J1W7EG5G9SPQbnOAaon0DfimXpTA3nMwvldQvUqNuQtq0q',NULL,NULL,NULL,1,NULL,'pending','2026-08-05 06:40:44','2026-08-05 06:40:44',NULL,NULL,NULL,NULL),(12,'codex_verify_owner','codex.verify.20260805@example.test','$2y$12$CFhA7Kc/ioNIRtIvpAl/zuAEtggXfKu5BVb/bKv.wpZUUPYEJNtq6',NULL,NULL,8,1,NULL,'pending','2026-08-05 07:26:06','2026-08-05 07:26:06',NULL,NULL,NULL,NULL),(60014,'thura_min','thura.min.apex.20260805@example.test','$2y$12$E1GPNjcZHJ9GNYujJ3IEiOZoXFbHBYVxtnxLDaQzJ9eq6eyEEvh7K',NULL,NULL,60010,3,NULL,'approved','2026-08-07 09:00:02','2026-08-07 09:00:02',NULL,NULL,NULL,NULL),(60018,'Ei Thinzar Mon','eithinzarmon@gmail.com','$2y$12$hQ1p3zKXAOXiSowHMtX9b.ppxkkLmE7afcvYvb0n83yuaANm4IpFa',NULL,NULL,NULL,2,NULL,'approved','2026-08-10 08:11:09','2026-08-10 08:11:09',NULL,NULL,'/php/Admin(WebOwner)/uploads/admin-60018-91a6a033ed0d732a.jpg',NULL),(90014,'tester_testing','tester@gmail.com','$2y$12$pkK4Y60pVgKwWFwdAiyXjuIE/6v645sV2lOoDVH2RV9MNttr8NmI6',NULL,NULL,90010,3,NULL,'pending','2026-08-08 07:26:10','2026-08-08 07:26:10',NULL,NULL,NULL,NULL),(150014,'MoleOFKO','shanekhant23@gmail.com','$2y$12$R.Aa6RBMhT7ROiteN9wFg.4bXcJLZDNjd5JlXbQG.bv5jTJU1Ij4i',NULL,NULL,NULL,6,NULL,'active','2026-08-08 16:13:42','2026-08-08 16:13:42',NULL,NULL,NULL,NULL),(210014,'KoShane','shanekopersonal@gmail.com','$2y$12$kY8nqDP4RnlrO.G1ao076ehpykOz5x6R6/YrS0o9QeeSE7ZgQRZQu',NULL,NULL,NULL,5,NULL,'active','2026-08-10 07:05:09','2026-08-10 07:05:09',NULL,NULL,NULL,NULL),(240014,'KoShab','mapa.and.pump@gmail.com','$2y$12$mgqjrg5LoQeLu7kQgrCh1uNqz2h17sq42oJipzDIwpakJUQsco52K',NULL,NULL,NULL,2,NULL,'active','2026-08-10 07:36:02','2026-08-10 07:36:02',NULL,NULL,'/php/Moderators(UnderAdmin)/uploads/moderator_240014_1786347243.png',NULL),(270014,'Larry-sama','koshan2104@gmail.com','$2y$12$f6VHNFpbbWY9hSX5BiFdw.QNaK5O0SUMo91nqI66NcL1KBCljsxxC',NULL,NULL,NULL,6,NULL,'active','2026-08-12 07:00:37','2026-08-12 07:00:37',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-12 13:34:52
