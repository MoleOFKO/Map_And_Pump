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
  `car_license_id` int DEFAULT NULL,
  `station_id` int NOT NULL,
  `oil_type_id` int NOT NULL,
  `qr_code` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `qr_refrence_code` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `filled_gas` int NOT NULL DEFAULT '0',
  `time_of_filled` datetime DEFAULT NULL,
  `expire_at` timestamp NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`booking_id`) /*T![clustered_index] CLUSTERED */,
  KEY `user_id_idx` (`user_id`),
  KEY `station_id_idx` (`station_id`),
  KEY `oil_type_idx` (`oil_type_id`),
  KEY `fk_booking_car_license_id_idx` (`car_license_id`),
  CONSTRAINT `fk_booking_oil_type` FOREIGN KEY (`oil_type_id`) REFERENCES `oil_types` (`oil_type_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_car_license_id` FOREIGN KEY (`car_license_id`) REFERENCES `car_license` (`car_license_id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=510002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking`
--

LOCK TABLES `booking` WRITE;
/*!40000 ALTER TABLE `booking` DISABLE KEYS */;
INSERT INTO `booking` VALUES (120007,4,1,1,3,'This is me','This is me','expired',0,NULL,'2026-08-12 08:22:02','2026-08-12 08:22:02'),(120010,4,1,1,3,'MP-866-15WI','MP-866-15WI','expired',0,NULL,'2026-08-12 08:45:44','2026-08-12 08:45:44'),(120012,4,1,3,4,'','MP-654-Z2RT','expired',0,NULL,'2026-08-12 09:12:00','2026-08-12 09:12:00'),(180002,4,1,1,2,'MP-321-H8KQ|Danko(Mdy)|Octane 95|15.0L','MP-321-H8KQ','notfilled',0,NULL,'2026-08-14 06:32:06','2026-08-14 06:32:06'),(180003,4,1,1,2,'MP-563-H7KQ|Danko(Mdy)|Octane 95|10L','MP-563-H7KQ','notfilled',0,NULL,'2026-08-14 06:55:21','2026-08-14 06:55:21'),(180006,4,NULL,1,2,'MP-999-TEST2|Danko(Mdy)|Octane 95|15.0L','MP-999-TEST2','notfilled',0,NULL,'2026-08-15 07:26:28','2026-08-14 07:26:28'),(180007,4,NULL,1,2,'MP-TEST-CAR-1|Danko(Mdy)|Octane 95|15.0L','MP-TEST-CAR-1','notfilled',0,NULL,'2026-08-15 07:27:00','2026-08-14 07:27:00'),(180008,4,NULL,1,2,'MP-TEST-CAR-2|Danko(Mdy)|Octane 95|15.0L','MP-TEST-CAR-2','notfilled',0,NULL,'2026-08-15 07:28:11','2026-08-14 07:28:11'),(480002,210014,330002,3,3,'MP-203-PK0Y|Danko(TKT)|Disel|20L','MP-203-PK0Y','notfilled',0,NULL,'2026-08-26 09:40:38','2026-08-26 08:40:38');
/*!40000 ALTER TABLE `booking` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `branches` (
  `branch_id` int NOT NULL AUTO_INCREMENT,
  `branch_code` varchar(20) NOT NULL,
  `branch_name` varchar(120) NOT NULL,
  `city` varchar(60) NOT NULL,
  `township` varchar(60) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(120) DEFAULT NULL,
  `opening_time` time DEFAULT '06:00:00',
  `closing_time` time DEFAULT '22:00:00',
  `is_24hr` tinyint(1) DEFAULT '0',
  `pump_count` int DEFAULT '4',
  `tank_count` int DEFAULT '3',
  `status` enum('active','maintenance','closed') DEFAULT 'active',
  `hq_region` varchar(60) DEFAULT NULL,
  `established_at` date DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`branch_id`) /*T![clustered_index] CLUSTERED */,
  UNIQUE KEY `branch_code` (`branch_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=120001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
INSERT INTO `branches` VALUES (1,'YGN-014','Denko - Sanchaung','Yangon','Sanchaung','Baho Road, Sanchaung Township, Yangon',NULL,NULL,'01-524-XXXX','denko.sanchaung@mappump.mm','06:00:00','22:00:00',0,4,3,'active','Yangon Central','2022-03-15',NULL,'2026-08-23 16:32:29','2026-08-23 16:32:29');
/*!40000 ALTER TABLE `branches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `business_account_scope`
--

DROP TABLE IF EXISTS `business_account_scope`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `business_account_scope` (
  `business_account_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `business_role_id` int NOT NULL,
  `headquarter_id` int DEFAULT NULL,
  `branch_manager_user_id` int DEFAULT NULL,
  `station_id` int DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`business_account_id`) /*T![clustered_index] CLUSTERED */,
  UNIQUE KEY `uq_business_account_scope_user` (`user_id`),
  KEY `idx_business_account_scope_role` (`business_role_id`),
  KEY `idx_business_account_scope_hq_role` (`headquarter_id`,`business_role_id`,`status`),
  KEY `idx_business_account_scope_manager` (`branch_manager_user_id`),
  KEY `idx_business_account_scope_station` (`station_id`),
  CONSTRAINT `fk_business_scope_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_business_scope_role_id` FOREIGN KEY (`business_role_id`) REFERENCES `roles` (`role_id`) ON DELETE NO ACTION ON UPDATE CASCADE,
  CONSTRAINT `fk_business_scope_hq_id` FOREIGN KEY (`headquarter_id`) REFERENCES `headquarter` (`headquarter_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_business_scope_manager_user_id` FOREIGN KEY (`branch_manager_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_business_scope_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=120001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_account_scope`
--

LOCK TABLES `business_account_scope` WRITE;
/*!40000 ALTER TABLE `business_account_scope` DISABLE KEYS */;
INSERT INTO `business_account_scope` VALUES (30001,4,3,2,NULL,NULL,'rejected','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30002,5,3,3,NULL,NULL,'approved','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30003,6,3,4,NULL,NULL,'suspended','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30004,7,3,5,NULL,NULL,'removed','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30005,8,3,3,NULL,NULL,'pending','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30006,10,1,NULL,NULL,NULL,'pending','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30007,12,3,8,NULL,NULL,'pending','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30008,60014,3,60010,NULL,NULL,'approved','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30009,60018,1,NULL,NULL,NULL,'approved','2026-08-23 16:50:30','2026-08-23 16:50:30'),(30010,90014,3,90010,NULL,NULL,'approved','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30011,210014,5,2,330014,270003,'active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(30012,240014,2,NULL,NULL,NULL,'active','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30013,270015,3,180010,NULL,NULL,'approved','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30014,330014,4,2,NULL,NULL,'active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(30015,420015,3,2,NULL,NULL,'active','2026-08-22 03:16:12','2026-08-22 03:16:12');
/*!40000 ALTER TABLE `business_account_scope` ENABLE KEYS */;
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
  `car_owner_name` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `car_license_front_image` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `car_license_back_image` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `car_license_image` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `model_year` int NOT NULL,
  `vehicle_name` varchar(100) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `vehicle_type` varchar(100) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `chassis_number` varchar(20) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `filled_gas` int NOT NULL DEFAULT '0',
  `issue_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`car_license_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=420002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `car_license`
--

LOCK TABLES `car_license` WRITE;
/*!40000 ALTER TABLE `car_license` DISABLE KEYS */;
INSERT INTO `car_license` VALUES (1,'1234',NULL,NULL,NULL,NULL,2000,NULL,'Yamaha',NULL,'Yangon',1,NULL,'2026-08-04 06:26:11','2026-08-04 06:26:11'),(120002,'TEST-9999',NULL,NULL,NULL,NULL,2024,NULL,'Sedan',NULL,'Mandalay',10,NULL,'2026-08-14 07:48:44','2026-08-14 07:48:44'),(330002,'1A/1234','KoShane','/php/Customer/uploads/car-licenses/car-license-front-210014-ca16dee7b720028e.jpg','/php/Customer/uploads/car-licenses/car-license-back-210014-178b28ee44d5b868.jpg',NULL,2000,'Ho','Bus','JMA2324567435423','YGN',0,'2023-07-24','2026-08-24 06:16:19','2026-08-24 06:16:19'),(330003,'1A/1234','KoShae','/php/Customer/uploads/car-licenses/car-license-front-210014-604a563a1d6d4ee5.jpg','/php/Customer/uploads/car-licenses/car-license-back-210014-af28e153d84badcf.jpg',NULL,2002,'BMW','SUV','JMA2324567435423','YGN',35,'2026-08-01','2026-08-26 08:19:10','2026-08-26 08:19:10');
/*!40000 ALTER TABLE `car_license` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `deliveries`
--

DROP TABLE IF EXISTS `deliveries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deliveries` (
  `delivery_id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int NOT NULL,
  `tank_id` int DEFAULT NULL,
  `fuel_code` varchar(10) NOT NULL,
  `quantity_l` int NOT NULL,
  `supplier` varchar(120) DEFAULT NULL,
  `received_by` varchar(120) DEFAULT NULL,
  `received_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`delivery_id`) /*T![clustered_index] CLUSTERED */,
  KEY `idx_delivery_branch_date` (`branch_id`,`received_at`),
  KEY `idx_deliveries_tank_id` (`tank_id`),
  CONSTRAINT `fk_deliveries_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_deliveries_tank_id` FOREIGN KEY (`tank_id`) REFERENCES `tanks` (`tank_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `deliveries`
--

LOCK TABLES `deliveries` WRITE;
/*!40000 ALTER TABLE `deliveries` DISABLE KEYS */;
/*!40000 ALTER TABLE `deliveries` ENABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=300001;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=300001;
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
  `headquarters_owner_id` int DEFAULT NULL,
  `company_logo` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `contact_number` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `headquarter_email` varchar(225) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `headquarter_bio` varchar(45) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `business_license` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `expire_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `business_license_image` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `business_license_expire_date` varchar(45) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  PRIMARY KEY (`headquarter_id`) /*T![clustered_index] CLUSTERED */,
  UNIQUE KEY `headquarter_email_UNIQUE` (`headquarter_email`),
  UNIQUE KEY `company_logo_UNIQUE` (`company_logo`),
  KEY `fk_heardqurater_owner_id_idx` (`headquarters_owner_id`),
  CONSTRAINT `fk_heardqurater_owner_id` FOREIGN KEY (`headquarters_owner_id`) REFERENCES `users` (`user_id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=480010;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `headquarter`
--

LOCK TABLES `headquarter` WRITE;
/*!40000 ALTER TABLE `headquarter` DISABLE KEYS */;
INSERT INTO `headquarter` VALUES (2,'ADMIN TEST 20260805 HQ Pending',420015,'uploads/logo_1787211069.jpg','09-10000001','aer@gmail.com','','Yangon Region — 10 Test Road, Pending Township','LIC-TEST-PENDING-20260805','2026-08-21 07:26:50','2026-08-21 07:26:50',NULL,NULL),(3,'ADMIN TEST 20260805 HQ Active',NULL,'uploads/admin-test-20260805-active.png','09-10000002','amar@gmail.com',NULL,'Mandalay Region — 20 Test Road, Active Township','LIC-TEST-ACTIVE-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL,NULL),(4,'ADMIN TEST 20260805 HQ Suspended',NULL,'uploads/admin-test-20260805-suspended.png','09-10000003','kor@gmail.com',NULL,'Naypyidaw — 30 Test Road, Suspended Township','LIC-TEST-SUSPENDED-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL,NULL),(5,'ADMIN TEST 20260805 HQ Removed',NULL,'uploads/admin-test-20260805-removed.png','09-10000004','dankor@gmail.com',NULL,'Yangon Region — 40 Test Road, Removed Township','LIC-TEST-REMOVED-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL,NULL),(6,'Dango',NULL,'hq.png','09881938483','maxer@gmail.com',NULL,'BanyarDala Street','jjko.png','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL,NULL),(8,'CODEX REGISTRATION VERIFY 20260805',NULL,'uploads/codex-verify-logo.png','09-77770000','codex.verify.20260805@example.test',NULL,'Yangon Region — No. 88, Verification Road, Test Township, Yangon','BL-CODEX-VERIFY-20260805','2026-08-05 07:26:06','2031-12-31 23:59:59',NULL,NULL),(60010,'Apex Fueling Group - Yangon HQ',NULL,'uploads/apex-fueling-logo.png','09-421234567','thura.min.apex.20260805@example.test',NULL,'Yangon Region — No. 123, Pyay Road, Kamayut Township, Yangon','BL-YGN-APEX-2026-001','2026-08-07 08:46:02','2031-12-31 23:59:59',NULL,NULL),(90010,'testing group',NULL,'uploads/testing-logo.png','09223344555','tester@gmail.com',NULL,'Yangon Region — tteesstteerr','BL-YGN-test-2026-001','2026-08-08 07:26:10','2030-02-25 23:59:59',NULL,NULL),(180010,'tempany',NULL,'https://static.wikia.nocookie.net/my-three-beautiful-wives-are-vampires/images/4/46/Concepts.png/revision/latest?cb=20250104003556','09454512708','temp45@gmail.com',NULL,'Naypyidaw Region — Stella City, Pepper Township','BL-YGN-temp-2026-001','2026-08-12 07:14:37','2029-01-14 23:59:59',NULL,NULL);
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=360002;
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
  `role_id` int DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_log_user_id_idx` (`user_id`),
  KEY `fk_log_role_id_idx` (`role_id`),
  CONSTRAINT `fk_log_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_log_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE NO ACTION ON UPDATE NO ACTION
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=330001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `log`
--

LOCK TABLES `log` WRITE;
/*!40000 ALTER TABLE `log` DISABLE KEYS */;
INSERT INTO `log` VALUES (30001,60018,4,'Updated admin profile avatar.','2026-08-14 07:47:08'),(30002,60018,4,'Updated admin profile avatar.','2026-08-14 07:47:09'),(30003,60018,4,'Generated report #30001 (hq_performance).','2026-08-14 07:47:09'),(90001,60018,NULL,'Marked Customer user #270014 as suspended.','2026-08-14 07:58:24');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=360002;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=450004;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (3,7,5,'admin-test-active-owner-20260805@example.test','request_approved','Admin test request approved','This seeded notification is available for approval workflow testing.','not_configured','Seeded test notification; no email was sent.','2026-08-05 06:32:38'),(30004,6,5,'admin-test-active-owner-20260805@example.test','request_approved','Map & Pump request TEST-20260805-002: Approved','Your request TEST-20260805-002 for ADMIN TEST 20260805 HQ Active has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-07 07:20:30'),(60004,30010,4,'admin-test-pending-owner-20260805@example.test','request_rejected','Map & Pump request HQ-REG-2: Rejected','Your request HQ-REG-2 for ADMIN TEST 20260805 HQ Pending was rejected.\n\nReason:\ntest','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-07 08:59:54'),(60005,30012,60014,'thura.min.apex.20260805@example.test','request_approved','Map & Pump request HQ-REG-60010: Approved','Your request HQ-REG-60010 for Apex Fueling Group - Yangon HQ has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-07 09:00:02'),(150004,60010,90014,'tester@gmail.com','request_approved','Map & Pump request HQ-REG-90010: Approved','Your request HQ-REG-90010 for testing group has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-12 07:12:42'),(300004,1,270015,'temp45@gmail.com','request_approved','Map & Pump request HQ-REG-180010: Approved','Your request HQ-REG-180010 for tempany has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-21 08:23:48');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=390002;
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
-- Table structure for table `pumps`
--

DROP TABLE IF EXISTS `pumps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pumps` (
  `pump_id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int NOT NULL,
  `pump_no` int NOT NULL,
  `status` enum('active','idle','maintenance','offline') DEFAULT 'idle',
  `current_token` varchar(20) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pump_id`) /*T![clustered_index] CLUSTERED */,
  UNIQUE KEY `uniq_branch_pumpno` (`branch_id`,`pump_no`),
  CONSTRAINT `fk_pumps_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pumps`
--

LOCK TABLES `pumps` WRITE;
/*!40000 ALTER TABLE `pumps` DISABLE KEYS */;
/*!40000 ALTER TABLE `pumps` ENABLE KEYS */;
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
  `image` int DEFAULT NULL,
  `importance` varchar(10) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `short_summery` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`report_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_report_user_id_idx` (`user_id`),
  KEY `fk_report_role_id_idx` (`role_id`),
  CONSTRAINT `fk_report_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_report_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=360001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report`
--

LOCK TABLES `report` WRITE;
/*!40000 ALTER TABLE `report` DISABLE KEYS */;
INSERT INTO `report` VALUES (30001,60018,2,NULL,'normal','August HQ performance summary','[scope=hq_performance][format=pdf][start=2022-12-01][end=2023-12-01] HQ records: 9; active linked accounts: 2','2026-08-10 08:25:41'),(90001,330014,4,NULL,'normal','station performance','This station performs at top1 in this week','2026-08-14 07:34:16');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=390002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `roles`
--

LOCK TABLES `roles` WRITE;
/*!40000 ALTER TABLE `roles` DISABLE KEYS */;
INSERT INTO `roles` VALUES (1,'Admin'),(2,'Moderator'),(3,'HQ'),(4,'BranchManager'),(5,'Staff'),(6,'Customer');
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
-- Table structure for table `staff`
--

DROP TABLE IF EXISTS `staff`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `staff` (
  `staff_id` int NOT NULL AUTO_INCREMENT,
  `staff_code` varchar(20) NOT NULL,
  `branch_id` int NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `role` enum('Pump Attendant','Cashier','Supervisor') DEFAULT 'Pump Attendant',
  `shift` enum('Morning','Afternoon','Night') DEFAULT 'Morning',
  `contact` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `duty_status` enum('On Duty','Off Duty','Leave') DEFAULT 'Off Duty',
  `hired_at` date DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`staff_id`) /*T![clustered_index] CLUSTERED */,
  KEY `idx_staff_branch` (`branch_id`),
  KEY `idx_staff_shift` (`shift`),
  UNIQUE KEY `staff_code` (`staff_code`),
  CONSTRAINT `fk_staff_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=120001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff`
--

LOCK TABLES `staff` WRITE;
/*!40000 ALTER TABLE `staff` DISABLE KEYS */;
INSERT INTO `staff` VALUES (1,'ST-2091',1,'Joao','Pump Attendant','Morning','09-751-100001',1,'On Duty',NULL,'2026-08-23 16:41:43','2026-08-23 16:41:43'),(2,'ST-2092',1,'Leon','Cashier','Morning','09-799-100002',1,'On Duty',NULL,'2026-08-23 16:41:43','2026-08-23 16:41:43'),(3,'ST-2093',1,'Xiao','Pump Attendant','Afternoon','09-425-100003',1,'Off Duty',NULL,'2026-08-23 16:41:43','2026-08-23 16:41:43'),(4,'ST-2094',1,'Henry','Supervisor','Afternoon','09-660-100004',1,'Leave',NULL,'2026-08-23 16:41:43','2026-08-23 16:41:43'),(5,'ST-2095',1,'Thiago','Pump Attendant','Night','09-511-100005',1,'Off Duty',NULL,'2026-08-23 16:41:43','2026-08-23 16:41:43');
/*!40000 ALTER TABLE `staff` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `station_business_scope`
--

DROP TABLE IF EXISTS `station_business_scope`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `station_business_scope` (
  `station_id` int NOT NULL,
  `headquarter_id` int NOT NULL,
  `branch_manager_user_id` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`station_id`) /*T![clustered_index] CLUSTERED */,
  KEY `idx_station_business_scope_hq_manager` (`headquarter_id`,`branch_manager_user_id`),
  KEY `idx_station_business_scope_manager` (`branch_manager_user_id`),
  CONSTRAINT `fk_station_scope_station_id` FOREIGN KEY (`station_id`) REFERENCES `stations` (`station_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_station_scope_hq_id` FOREIGN KEY (`headquarter_id`) REFERENCES `headquarter` (`headquarter_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_station_scope_manager_user_id` FOREIGN KEY (`branch_manager_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `station_business_scope`
--

LOCK TABLES `station_business_scope` WRITE;
/*!40000 ALTER TABLE `station_business_scope` DISABLE KEYS */;
INSERT INTO `station_business_scope` VALUES (1,2,330014,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(2,2,330014,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(3,2,330014,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(60003,2,330014,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(60004,2,330014,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(60005,2,330014,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(60006,2,330014,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(270003,2,330014,'2026-08-22 03:21:40','2026-08-22 03:21:40');
/*!40000 ALTER TABLE `station_business_scope` ENABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=360002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `station_oil_inventory`
--

LOCK TABLES `station_oil_inventory` WRITE;
/*!40000 ALTER TABLE `station_oil_inventory` DISABLE KEYS */;
INSERT INTO `station_oil_inventory` VALUES (1,1,1,4620,1,6000.000,'2026-08-12 07:39:03'),(2,1,2,5830,1,6000.000,'2026-08-12 07:39:38'),(3,1,3,2650,1,6000.000,'2026-08-12 07:35:41'),(4,1,4,7006,1,6000.000,'2026-08-12 07:40:05'),(5,2,1,4620,1,7500.000,'2026-08-12 07:39:03'),(6,2,2,5830,1,500.000,'2026-08-12 07:39:38'),(7,3,3,2650,1,900.000,'2026-08-12 07:35:41'),(8,2,4,7006,1,6000.000,'2026-08-12 07:40:05');
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
  `station_address` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `business_user_id` int NOT NULL,
  `status` varchar(15) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`station_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_business_user_id_idx` (`business_user_id`),
  CONSTRAINT `fk_business_user_id` FOREIGN KEY (`business_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=450003;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stations`
--

LOCK TABLES `stations` WRITE;
/*!40000 ALTER TABLE `stations` DISABLE KEYS */;
INSERT INTO `stations` VALUES (1,'Danko(Mdy)','','Mandalay',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(2,'ADMIN TEST 20260805 Station','','Mandalay Region — Test Township',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(3,'Danko(TKT)','','Tarkayta Yangon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(60003,'Myanko','','South Dagon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(60004,'Shoko','','North Dagon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(60005,'Niko','','East Dagon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(60006,'Eko','','Wast Dagon',330014,'Decommissioned','2026-08-23 16:50:30','2026-08-23 16:50:30'),(270003,'Dokan(Myanma Gonyi Street)','16.807045, 96.199229','Myanmar Gonyi Street',330014,'Active','2026-08-22 03:21:40','2026-08-22 03:21:40');
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
  `reference_code` varchar(40) NOT NULL,
  `request_type` varchar(60) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `submitter_user_id` int unsigned DEFAULT NULL,
  `role_id` int unsigned DEFAULT NULL,
  `headquarter_id` int unsigned DEFAULT NULL,
  `sender_entity` varchar(255) NOT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `sender_email` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `request_details` text DEFAULT NULL,
  `document_name` varchar(255) DEFAULT NULL,
  `document_path` varchar(500) DEFAULT NULL,
  `headquarter_owner_email` varchar(255) DEFAULT NULL,
  `headquarter_email` varchar(255) DEFAULT NULL,
  `headquarter_company_logo` varchar(255) DEFAULT NULL,
  `headquarter_contact_number` varchar(50) DEFAULT NULL,
  `headquarter_expire_at` datetime DEFAULT NULL,
  `review_reason` text DEFAULT NULL,
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
  KEY `idx_system_requests_hq` (`headquarter_id`),
  KEY `idx_system_requests_headquarter` (`headquarter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=283697;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_requests`
--

LOCK TABLES `system_requests` WRITE;
/*!40000 ALTER TABLE `system_requests` DISABLE KEYS */;
INSERT INTO `system_requests` VALUES (1,'HQ-REG-180010','hq_registration','approved',270015,3,180010,'tempany','temp_temp','temp45@gmail.com','Naypyidaw Region — Stella City, Pepper Township','Legacy HQ account registered before the approval-only workflow. Contact: 09454512708. License expiry: 2029-01-14.','Business license','BL-YGN-temp-2026-001','temp45@gmail.com','temp45@gmail.com','https://static.wikia.nocookie.net/my-three-beautiful-wives-are-vampires/images/4/46/Concepts.png/revision/latest?cb=20250104003556','09454512708','2029-01-14 23:59:59','Approved by administrator.',60018,'2026-08-21 08:23:48','2026-08-14 09:20:01','2026-08-21 08:23:48');
/*!40000 ALTER TABLE `system_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tanks`
--

DROP TABLE IF EXISTS `tanks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tanks` (
  `tank_id` int NOT NULL AUTO_INCREMENT,
  `branch_id` int NOT NULL,
  `fuel_code` varchar(10) NOT NULL,
  `fuel_name` varchar(40) NOT NULL,
  `capacity_l` int NOT NULL,
  `current_l` int NOT NULL DEFAULT '0',
  `alert_pct` int NOT NULL DEFAULT '25',
  `is_enabled` tinyint(1) DEFAULT '1',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tank_id`) /*T![clustered_index] CLUSTERED */,
  UNIQUE KEY `uniq_branch_fuel` (`branch_id`,`fuel_code`),
  CONSTRAINT `fk_tanks_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tanks`
--

LOCK TABLES `tanks` WRITE;
/*!40000 ALTER TABLE `tanks` DISABLE KEYS */;
/*!40000 ALTER TABLE `tanks` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usage_history`
--

DROP TABLE IF EXISTS `usage_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `usage_history` (
  `usage_id` int NOT NULL AUTO_INCREMENT,
  `booking_id` int NOT NULL,
  `user_id` int NOT NULL,
  `car_license_id` int DEFAULT NULL,
  `station_id` int NOT NULL,
  `oil_type_id` int NOT NULL,
  `qr_code` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `qr_refrence_code` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `filled_liters` decimal(10,2) NOT NULL DEFAULT '0.00',
  `price_per_litre` decimal(12,2) DEFAULT NULL,
  `total_cost` decimal(12,2) DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'filled',
  `booked_at` timestamp NULL DEFAULT NULL,
  `expire_at` timestamp NULL DEFAULT NULL,
  `filled_at` datetime NOT NULL,
  `staff_user_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`usage_id`) /*T![clustered_index] CLUSTERED */,
  UNIQUE KEY `uq_usage_history_booking` (`booking_id`),
  KEY `idx_usage_history_user_filled` (`user_id`,`filled_at`),
  KEY `idx_usage_history_station` (`station_id`),
  KEY `idx_usage_history_car` (`car_license_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=150001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usage_history`
--

LOCK TABLES `usage_history` WRITE;
/*!40000 ALTER TABLE `usage_history` DISABLE KEYS */;
INSERT INTO `usage_history` VALUES (1,1,210014,1,1,3,'MP-250-0A3I','MP-250-0A3I',1.00,NULL,NULL,'filled','2026-08-08 07:32:39','2026-08-08 07:32:39','2026-08-08 07:32:39',NULL,'2026-08-23 16:17:04'),(2,180004,210014,NULL,1,2,'MP-999-TEST1|Danko(Mdy)|Octane 95|15.0L','MP-999-TEST1',1.00,NULL,NULL,'filled','2026-08-14 07:13:57','2026-08-15 07:13:54','2026-08-14 07:22:49',NULL,'2026-08-23 16:17:04'),(3,180005,210014,NULL,1,2,'MP-999-TEST1|Danko(Mdy)|Octane 95|15.0L','MP-999-TEST1',1.00,NULL,NULL,'filled','2026-08-14 07:15:39','2026-08-16 07:15:39','2026-08-14 07:22:19',NULL,'2026-08-23 16:17:04'),(4,180009,210014,NULL,1,2,'MP-CAR-VERIFY-072827|Danko(Mdy)|Octane 95|15.0L','MP-CAR-VERIFY-072827',0.00,NULL,NULL,'filled','2026-08-14 07:28:29','2026-08-15 07:28:29','2026-08-14 07:48:44',NULL,'2026-08-23 16:17:04'),(120001,450003,210014,330003,3,3,'MP-817-4PHB|Danko(TKT)|Disel|20L','MP-817-4PHB',35.00,2650.00,92750.00,'filled','2026-08-26 08:18:26','2026-08-26 09:18:26','2026-08-26 08:19:09',210014,'2026-08-26 08:19:10');
/*!40000 ALTER TABLE `usage_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_account_actions`
--

DROP TABLE IF EXISTS `user_account_actions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_account_actions` (
  `action_id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `action_type` varchar(20) NOT NULL,
  `reason` text NOT NULL,
  `notification_status` varchar(30) NOT NULL DEFAULT 'not_configured',
  `notification_note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`action_id`) /*T![clustered_index] CLUSTERED */,
  KEY `idx_user_account_actions_user` (`user_id`),
  KEY `idx_user_account_actions_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=210001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_account_actions`
--

LOCK TABLES `user_account_actions` WRITE;
/*!40000 ALTER TABLE `user_account_actions` DISABLE KEYS */;
INSERT INTO `user_account_actions` VALUES (1,270014,'suspend','test','not_configured','Email delivery is not configured; the reason was recorded in the HQ action history.','2026-08-14 07:58:24');
/*!40000 ALTER TABLE `user_account_actions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_car`
--

DROP TABLE IF EXISTS `user_car`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_car` (
  `user_car_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `car_id` int NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_car_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_user_car_user_id_idx` (`user_id`),
  KEY `fk_user_car_car_id_idx` (`car_id`),
  KEY `idx_user_car_active` (`user_id`,`is_active`),
  UNIQUE KEY `uq_user_car_user_car` (`user_id`,`car_id`),
  CONSTRAINT `fk_user_car_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_car_car_id` FOREIGN KEY (`car_id`) REFERENCES `car_license` (`car_license_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=240003;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_car`
--

LOCK TABLES `user_car` WRITE;
/*!40000 ALTER TABLE `user_car` DISABLE KEYS */;
INSERT INTO `user_car` VALUES (1,270014,1,1,'2026-08-24 07:23:42','2026-08-24 07:23:42'),(2,4,120002,0,'2026-08-24 07:23:42','2026-08-24 07:23:42'),(150003,210014,330002,1,'2026-08-24 07:23:42','2026-08-26 08:40:13'),(150004,210014,330003,0,'2026-08-24 07:23:42','2026-08-26 08:40:13');
/*!40000 ALTER TABLE `user_car` ENABLE KEYS */;
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
  `face_image` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `license_number` varchar(100) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `license_name` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `phone_number` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `nrc_number` varchar(100) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `date_of_birth` date NOT NULL,
  `blood_type` varchar(10) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `license_last_valid_date` date NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`license_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=390003;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_license`
--

LOCK TABLES `user_license` WRITE;
/*!40000 ALTER TABLE `user_license` DISABLE KEYS */;
INSERT INTO `user_license` VALUES (1,'image',NULL,'1234','B','12345678','34567','2000-12-20','O','2016-09-10','pending','2026-08-04 06:24:11','2026-08-04 06:24:11'),(2,'license_image.jpg',NULL,'123456789','John Doe','555-1234','NRC123456','1990-01-01','O+','2025-12-31','pending','2026-08-05 06:37:28','2026-08-05 06:37:28'),(150003,'/php/Customer/uploads/licenses/license-150014-8d2fcee6de20d961.jpg',NULL,'YGN-1233','MoleOFKO','+95 9 799 055836','10/TAKETA(N)/123452','2000-12-21','O-','2030-11-11','approved','2026-08-15 05:47:13','2026-08-15 05:47:13'),(180003,'/php/Customer/uploads/licenses/license-210014-be77ccfa45961d78.jpg','/php/Customer/uploads/licenses/license-face-210014-8c9f216da4982fa2.jpg','YGN-1234','KoShane','+95 9 799 055836','10/TAKETA(N)/123452','2000-02-02','O-','2030-01-01','approved','2026-08-16 19:34:09','2026-08-16 19:34:09'),(210003,'/php/Customer/uploads/licenses/license-420015-45f3dc2d7151f1e6.jpg','/php/Customer/uploads/licenses/license-face-420015-69f9e339f2d5e5b8.jpg','L/12345/23','Kaung Kaung','+959799055836','14/YAKANA(N)123456','2012-12-31','AB-','2030-12-12','approved','2026-08-20 08:52:23','2026-08-20 08:52:23');
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
  `user_public_id` varchar(16) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `user_name` varchar(225) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `hashed_password` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `license_id` int DEFAULT NULL,
  `headquarter_id` int DEFAULT NULL,
  `role_id` int DEFAULT '6',
  `business_role_id` int DEFAULT NULL,
  `current_role_id` int DEFAULT '6',
  `branch_id` int DEFAULT NULL,
  `status` varchar(50) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'active',
  `had_point` int NOT NULL DEFAULT '0',
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
  KEY `fk_current_role_id_idx` (`current_role_id`),
  KEY `idx_users_public_id` (`user_public_id`),
  KEY `fk_business_branch_id_idx` (`branch_id`),
  CONSTRAINT `fk_users_headquarter_id` FOREIGN KEY (`headquarter_id`) REFERENCES `headquarter` (`headquarter_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_users_license_id` FOREIGN KEY (`license_id`) REFERENCES `user_license` (`license_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_business_role_id` FOREIGN KEY (`business_role_id`) REFERENCES `roles` (`role_id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_current_role_id` FOREIGN KEY (`current_role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_business_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=600014;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (4,'000004','admin_test_pending_owner','admin-test-pending-owner-20260805@example.test','$2y$12$TjVx96cvj5VBIYSZRyZ2AeDZsh/CG6fXWEjpEmcP89zlEbyTArXzm',NULL,2,6,3,6,NULL,'rejected',0,'2026-08-23 16:50:29','2026-08-23 16:50:29',NULL,NULL,NULL,NULL),(5,'000005','admin_test_active_owner','admin-test-active-owner-20260805@example.test','$2y$12$t7uyDkU/8Lyu4LzIZllkUuHjv0HJM0BniOpCcR1tA.n7SOdoU.JVa',NULL,3,6,3,6,NULL,'approved',0,'2026-08-23 16:50:29','2026-08-23 16:50:29',NULL,NULL,NULL,NULL),(6,'000006','admin_test_suspended_owner','admin-test-suspended-owner-20260805@example.test','$2y$12$gcUUWoJ/VSfvM2T.4z6.puCIK98Nmd8HBwqj9kKhScvXSlxv5BSh6',NULL,4,6,3,6,NULL,'suspended',0,'2026-08-23 16:50:29','2026-08-23 16:50:29',NULL,NULL,NULL,NULL),(7,'000007','admin_test_removed_owner','admin-test-removed-owner-20260805@example.test','$2y$12$3exwXVIQRqKrhuIw52qq5eir1Y862gHkJo/WG1QseaKMu7h5M5WrS',NULL,5,6,3,6,NULL,'removed',0,'2026-08-23 16:50:29','2026-08-23 16:50:29',NULL,NULL,NULL,NULL),(8,'000008','admin_test_active_operator','admin-test-active-operator-20260805@example.test','$2y$12$NCWhFV6J1W7EG5G9SPQbnOAaon0DfimXpTA3nMwvldQvUqNuQtq0q',NULL,3,6,3,6,NULL,'pending',0,'2026-08-23 16:50:29','2026-08-23 16:50:29',NULL,NULL,NULL,NULL),(10,'00000A','test_active','admin-test-active-operator-2026005@example.test','$2y$12$NCWhFV6J1W7EG5G9SPQbnOAaon0DfimXpTA3nMwvldQvUqNuQtq0q',NULL,NULL,6,1,6,NULL,'pending',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(12,'00000C','codex_verify_owner','codex.verify.20260805@example.test','$2y$12$CFhA7Kc/ioNIRtIvpAl/zuAEtggXfKu5BVb/bKv.wpZUUPYEJNtq6',NULL,8,6,3,6,NULL,'pending',0,'2026-08-23 16:50:29','2026-08-23 16:50:29',NULL,NULL,NULL,NULL),(60014,'00EA6E','thura_min','thura.min.apex.20260805@example.test','$2y$12$E1GPNjcZHJ9GNYujJ3IEiOZoXFbHBYVxtnxLDaQzJ9eq6eyEEvh7K',NULL,60010,6,3,6,NULL,'approved',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(60018,'00EA72','Ei Thinzar Mon','eithinzarmon@gmail.com','$2y$12$hQ1p3zKXAOXiSowHMtX9b.ppxkkLmE7afcvYvb0n83yuaANm4IpFa',NULL,NULL,6,1,1,NULL,'approved',0,'2026-08-26 08:12:42','2026-08-26 08:12:42',NULL,NULL,'/php/Admin(WebOwner)/uploads/admin-60018-91a6a033ed0d732a.jpg',NULL),(90014,'015F9E','tester_testing','tester@gmail.com','$2y$12$pkK4Y60pVgKwWFwdAiyXjuIE/6v645sV2lOoDVH2RV9MNttr8NmI6',NULL,90010,6,3,6,NULL,'approved',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(150014,'0249FE','MoleOFKO','shanekhant23@gmail.com','$2y$12$R.Aa6RBMhT7ROiteN9wFg.4bXcJLZDNjd5JlXbQG.bv5jTJU1Ij4i',150003,NULL,6,NULL,6,NULL,'active',0,'2026-08-28 06:49:27','2026-08-28 06:49:27','google','100787895739316965029','https://lh3.googleusercontent.com/a/ACg8ocJDnzcBRStX0x8DYtMJdMkWUkiL79x6k68sixAToMLC-xMLtlE=s96-c','2026-08-28 06:49:27'),(210014,'03345E','KoShane','shanekopersonal@gmail.com','$2y$12$kY8nqDP4RnlrO.G1ao076ehpykOz5x6R6/YrS0o9QeeSE7ZgQRZQu',180003,2,6,5,5,NULL,'active',0,'2026-08-28 06:34:45','2026-08-28 06:34:45',NULL,NULL,NULL,NULL),(240014,'03A98E','KoShab','mapa.and.pump@gmail.com','$2y$12$mgqjrg5LoQeLu7kQgrCh1uNqz2h17sq42oJipzDIwpakJUQsco52K',180003,NULL,6,2,2,NULL,'active',0,'2026-08-28 06:47:51','2026-08-28 06:47:51','google','118311246391062428561','https://lh3.googleusercontent.com/a/ACg8ocIXZque38Mkmro0Ieg_CVZtgFvpoGTrQKxjw6gzx6qGk4FY=s96-c','2026-08-28 06:47:51'),(270014,'041EBE','Larry-sama','koshan2104@gmail.com','$2y$12$f6VHNFpbbWY9hSX5BiFdw.QNaK5O0SUMo91nqI66NcL1KBCljsxxC',NULL,NULL,6,NULL,6,NULL,'suspended',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(270015,'041EBF','temp_temp','temp45@gmail.com','$2y$12$caTKDBBZre2OkSHh4xf3ReBFTeQ78.Q6NyYVxxuLd/A80sWVp6oB6',NULL,180010,6,3,6,NULL,'approved',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(330014,'05091E','Kaung Kaung','kaungzin365@gmail.com','$2y$10$wbiYdwea.zVPHMgyvwg9GOu8maL.zTquJtuW7.Yc68/qPjN4g9vMS',NULL,2,6,4,4,NULL,'active',0,'2026-08-24 07:03:28','2026-08-24 07:03:28',NULL,NULL,NULL,NULL),(420014,'0668AE','Test Me','bhonen94@gmail.com','$2y$12$uRed5QfY3CAWxZaaglmkNea/sfz38RITpP0KeI8jZO8LkapvHhaSS',NULL,NULL,6,NULL,6,NULL,'active',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(420015,'0668AF','Kaung Kaung HQ','kaungzin23may@gmail.com','$2y$10$4PXW5VHtOKMQyO6597rLK.GP2dIWf.DYjqcW//zhXWpaq33D62mdi',210003,2,6,3,3,NULL,'active',0,'2026-08-25 07:46:04','2026-08-25 07:46:04',NULL,NULL,NULL,NULL),(540014,'083D6E','Yan Aung Bala','yanaungbala5678@gmail.com','$2y$12$dgMgYHInE9e3NRkglOEXOux3CLwFfIB8bR0/zxodyM0AjiGpPVMsK',NULL,NULL,6,NULL,6,NULL,'active',0,'2026-08-24 07:49:57','2026-08-24 07:49:57','google','113301012897426888448','https://lh3.googleusercontent.com/a/ACg8ocLiyDuyH8de1FAO4NgeEmAI6MGs7_Kp9JUCRLZp3Y3Om5x3IA=s96-c','2026-08-24 07:49:57');
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

-- Dump completed on 2026-08-28 13:20:46
