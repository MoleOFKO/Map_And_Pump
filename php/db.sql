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
  CONSTRAINT `fk_booking_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_booking_car_license_id` FOREIGN KEY (`car_license_id`) REFERENCES `car_license` (`car_license_id`) ON DELETE NO ACTION ON UPDATE NO ACTION,
  CONSTRAINT `fk_booking_branch_id` FOREIGN KEY (`station_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=690002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `booking`
--

LOCK TABLES `booking` WRITE;
/*!40000 ALTER TABLE `booking` DISABLE KEYS */;
INSERT INTO `booking` VALUES (120007,4,1,1,3,'This is me','This is me','expired',0,NULL,'2026-08-12 08:22:02','2026-08-12 08:22:02'),(120010,4,1,1,3,'MP-866-15WI','MP-866-15WI','expired',0,NULL,'2026-08-12 08:45:44','2026-08-12 08:45:44'),(120012,4,1,3,4,'','MP-654-Z2RT','expired',0,NULL,'2026-08-12 09:12:00','2026-08-12 09:12:00'),(180002,4,1,1,2,'MP-321-H8KQ|Danko(Mdy)|Octane 95|15.0L','MP-321-H8KQ','cancelled',0,NULL,'2026-08-14 06:32:06','2026-08-14 06:32:06'),(180003,4,1,1,2,'MP-563-H7KQ|Danko(Mdy)|Octane 95|10L','MP-563-H7KQ','cancelled',0,NULL,'2026-08-14 06:55:21','2026-08-14 06:55:21'),(180006,4,NULL,1,2,'MP-999-TEST2|Danko(Mdy)|Octane 95|15.0L','MP-999-TEST2','notfilled',0,NULL,'2026-08-15 07:26:28','2026-08-14 07:26:28'),(180007,4,NULL,1,2,'MP-TEST-CAR-1|Danko(Mdy)|Octane 95|15.0L','MP-TEST-CAR-1','notfilled',0,NULL,'2026-08-15 07:27:00','2026-08-14 07:27:00'),(180008,4,NULL,1,2,'MP-TEST-CAR-2|Danko(Mdy)|Octane 95|15.0L','MP-TEST-CAR-2','notfilled',0,NULL,'2026-08-15 07:28:11','2026-08-14 07:28:11'),(480002,210014,330002,3,3,'MP-203-PK0Y|Danko(TKT)|Disel|20L','MP-203-PK0Y','notfilled',0,NULL,'2026-08-26 09:40:38','2026-08-26 08:40:38'),(600002,330014,510002,3,3,'MP-926-ALTA|Danko(TKT)|Disel|20L','MP-926-ALTA','expired',0,NULL,'2026-09-04 07:39:12','2026-09-04 06:39:12'),(600003,330014,510002,3,3,'MP-814-GGDU|Danko(TKT)|Disel|20L','MP-814-GGDU','notfilled',0,NULL,'2026-09-04 07:46:25','2026-09-04 06:46:25'),(600004,210014,330002,3,3,'MP-163-NX9Y|Danko(TKT)|Disel|20L','MP-163-NX9Y','notfilled',0,NULL,'2026-09-04 09:20:04','2026-09-04 08:20:04'),(660002,330014,510002,480008,3,'MP-809-KU9T|Max Energy Fuel Station|Disel|20L','MP-809-KU9T','notfilled',0,NULL,'2026-09-10 09:57:51','2026-09-10 08:57:51');
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
  `manager_user_id` int DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=540005;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `branches`
--

LOCK TABLES `branches` WRITE;
/*!40000 ALTER TABLE `branches` DISABLE KEYS */;
INSERT INTO `branches` VALUES (1,'ST-1','Danko(Mdy)','Yangon','Yangon','Mandalay',NULL,NULL,'01-524-XXXX','denko.sanchaung@mappump.mm',330014,'06:00:00','22:00:00',0,4,3,'active','Yangon Central','2022-03-15',NULL,'2026-08-23 16:32:29','2026-08-23 16:50:30'),(2,'ST-2','ADMIN TEST 20260805 Station','Yangon','Yangon','Mandalay Region — Test Township',NULL,NULL,NULL,NULL,330014,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(3,'ST-3','Danko(TKT)','Yangon','Yangon','Tarkayta Yangon',NULL,NULL,NULL,NULL,330014,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(60003,'ST-60003','Myanko','Yangon','Yangon','South Dagon',NULL,NULL,NULL,NULL,330014,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(60004,'ST-60004','Shoko','Yangon','Yangon','North Dagon',NULL,NULL,NULL,NULL,330014,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(60005,'ST-60005','Niko','Yangon','Yangon','East Dagon',NULL,NULL,NULL,NULL,330014,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(60006,'ST-60006','Eko','Yangon','Yangon','Wast Dagon',NULL,NULL,NULL,NULL,330014,'06:00:00','22:00:00',0,4,3,'closed',NULL,NULL,NULL,'2026-08-23 16:50:30','2026-08-23 16:50:30'),(270003,'ST-270003','Dokan(Myanma Gonyi Street)','Yangon','Yangon','Myanmar Gonyi Street',16.8070450,96.1992290,NULL,NULL,330014,'06:00:00','22:00:00',0,4,3,'closed',NULL,NULL,NULL,'2026-09-04 17:01:29','2026-09-10 09:23:40'),(480004,'ST-480004','Denko Fuel Station','Yangon','Yangon','No. 1, Map & Pump Test Road',16.8324150,96.1558210,NULL,NULL,840020,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-09-07 08:24:53','2026-09-07 08:24:53'),(480005,'ST-480005','Max Energy Fuel Station','Yangon','Yangon','No. 2, Map & Pump Test Road',16.8324150,96.1558210,NULL,NULL,840020,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-09-07 08:24:53','2026-09-07 08:24:53'),(480006,'ST-480006','PT Power Fuel Station','Yangon','Yangon','No. 3, Map & Pump Test Road',16.8251020,96.1328450,NULL,NULL,840020,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-09-07 08:24:54','2026-09-07 08:24:54'),(480007,'ST-480007','BOC / MPE Fuel Station','Yangon','Yangon','No. 4, Map & Pump Test Road',16.7812040,96.1284100,NULL,NULL,840020,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-09-07 08:24:54','2026-09-07 08:24:54'),(480008,'ST-480008','Max Energy Fuel Station','Yangon','Yangon','No. 5, Map & Pump Test Road',16.7865210,96.1739020,NULL,NULL,840020,'06:00:00','22:00:00',0,4,3,'active',NULL,NULL,NULL,'2026-09-07 08:24:54','2026-09-07 08:24:54');
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
  CONSTRAINT `fk_business_scope_branch_id` FOREIGN KEY (`station_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=270001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `business_account_scope`
--

LOCK TABLES `business_account_scope` WRITE;
/*!40000 ALTER TABLE `business_account_scope` DISABLE KEYS */;
INSERT INTO `business_account_scope` VALUES (30001,4,3,2,NULL,NULL,'rejected','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30002,5,3,3,NULL,NULL,'approved','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30003,6,3,4,NULL,NULL,'suspended','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30004,7,3,5,NULL,NULL,'removed','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30005,8,3,3,NULL,NULL,'pending','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30006,10,1,NULL,NULL,NULL,'pending','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30007,12,3,8,NULL,NULL,'pending','2026-08-23 16:50:29','2026-08-23 16:50:29'),(30008,60014,3,60010,NULL,NULL,'approved','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30009,60018,1,NULL,NULL,NULL,'approved','2026-08-23 16:50:30','2026-08-23 16:50:30'),(30010,90014,3,90010,NULL,NULL,'approved','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30011,210014,5,2,330014,270003,'active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(30012,240014,2,NULL,NULL,NULL,'active','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30013,270015,3,180010,NULL,NULL,'approved','2026-08-23 16:17:22','2026-08-23 16:17:22'),(30014,330014,4,2,NULL,1,'active','2026-08-23 16:50:30','2026-09-10 08:24:35'),(30015,420015,3,2,NULL,NULL,'active','2026-08-22 03:16:12','2026-08-22 03:16:12'),(150007,840020,4,480010,NULL,NULL,'active','2026-09-07 08:08:34','2026-09-07 08:08:34'),(150009,780020,4,2,NULL,60004,'active','2026-09-07 08:40:07','2026-09-07 08:40:07');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=600002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `car_license`
--

LOCK TABLES `car_license` WRITE;
/*!40000 ALTER TABLE `car_license` DISABLE KEYS */;
INSERT INTO `car_license` VALUES (1,'1234',NULL,NULL,NULL,NULL,2000,NULL,'Yamaha',NULL,'Yangon',1,NULL,'2026-08-04 06:26:11','2026-08-04 06:26:11'),(120002,'TEST-9999',NULL,NULL,NULL,NULL,2024,NULL,'Sedan',NULL,'Mandalay',10,NULL,'2026-08-14 07:48:44','2026-08-14 07:48:44'),(330002,'1A/1234','KoShane','/php/Customer/uploads/car-licenses/car-license-front-210014-ca16dee7b720028e.jpg','/php/Customer/uploads/car-licenses/car-license-back-210014-178b28ee44d5b868.jpg',NULL,2000,'Ho','Bus','JMA2324567435423','YGN',0,'2023-07-24','2026-09-10 08:20:59','2026-09-10 08:20:59'),(330003,'1A/1234','KoShae','/php/Customer/uploads/car-licenses/car-license-front-210014-604a563a1d6d4ee5.jpg','/php/Customer/uploads/car-licenses/car-license-back-210014-af28e153d84badcf.jpg',NULL,2002,'BMW','SUV','JMA2324567435423','YGN',35,'2026-08-01','2026-08-26 08:19:10','2026-08-26 08:19:10'),(450002,'1A/1234','Test Staff','/php/Customer/uploads/car-licenses/car-license-front-600017-e6f7887fd302a711.jpg','/php/Customer/uploads/car-licenses/car-license-back-600017-d9934c5b27254009.webp',NULL,2007,'BMW','Sedan','JJJJJ2222MDIDFDF','Yangon,Myanmar',0,'2025-01-31','2026-09-07 08:54:49','2026-09-07 08:54:49'),(480002,'2B/7832','Yum Yum','/php/Customer/uploads/car-licenses/car-license-front-750019-b06e231e4e1266da.png','/php/Customer/uploads/car-licenses/car-license-back-750019-ee877d3b991ccfb5.png',NULL,2006,'Honda','Motorcycle','JBMH2355432QER','Hlaing Thar Yar',20,'2024-08-01','2026-09-01 07:33:05','2026-09-01 07:33:05'),(510002,'1A/1234','Kaung Kaung','/php/Customer/uploads/car-licenses/car-license-front-330014-5a52db2b43f84327.jpg','/php/Customer/uploads/car-licenses/car-license-back-330014-9210d951dc141563.jpg',NULL,1950,'BMW','Sedan','JJJJJ2222MDIDFDF','Bahan',0,'2025-01-06','2026-09-04 06:37:23','2026-09-04 06:37:23');
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
  `usage_id` int DEFAULT NULL,
  `station_id` int DEFAULT NULL,
  `rating` tinyint DEFAULT NULL,
  `feedback` text COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `staff_read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`feedback_id`) /*T![clustered_index] CLUSTERED */,
  KEY `user_id_idx` (`user_id`),
  CONSTRAINT `fk_feedback_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=420001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback`
--

LOCK TABLES `feedback` WRITE;
/*!40000 ALTER TABLE `feedback` DISABLE KEYS */;
INSERT INTO `feedback` VALUES (1,150014,NULL,NULL,NULL,'Low Skill','2026-08-10 09:05:38',NULL),(330001,600017,180001,3,5,'[Usage #180001 | Booking MP-904-6G6J | Station Danko(TKT) | Fuel Disel | Liters 20 | Filled 2026-08-31 08:45:58 | Rating 5/5]\nHaha','2026-09-08 07:29:16',NULL);
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
  `customer_read_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reply_id`) /*T![clustered_index] CLUSTERED */
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=420001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `feedback_replies`
--

LOCK TABLES `feedback_replies` WRITE;
/*!40000 ALTER TABLE `feedback_replies` DISABLE KEYS */;
INSERT INTO `feedback_replies` VALUES (1,1,210014,'Thank You',NULL,'2026-08-10 09:08:47'),(330001,1,600017,'Why?',NULL,'2026-08-31 08:48:05'),(330002,1,600017,'why',NULL,'2026-08-31 08:49:28');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=570011;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `headquarter`
--

LOCK TABLES `headquarter` WRITE;
/*!40000 ALTER TABLE `headquarter` DISABLE KEYS */;
INSERT INTO `headquarter` VALUES (2,'Special One',600016,'uploads/logo_1788772026.jpg','09-10000001','aer@gmail.com','','Yangon Region — 10 Test Road, Pending Township','LIC-TEST-PENDING-20260805','2026-09-07 09:06:52','2026-09-07 09:06:52',NULL,NULL),(3,'ADMIN TEST 20260805 HQ Active',NULL,'uploads/admin-test-20260805-active.png','09-10000002','amar@gmail.com',NULL,'Mandalay Region — 20 Test Road, Active Township','LIC-TEST-ACTIVE-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL,NULL),(4,'ADMIN TEST 20260805 HQ Suspended',NULL,'uploads/admin-test-20260805-suspended.png','09-10000003','kor@gmail.com',NULL,'Naypyidaw — 30 Test Road, Suspended Township','LIC-TEST-SUSPENDED-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL,NULL),(5,'ADMIN TEST 20260805 HQ Removed',NULL,'uploads/admin-test-20260805-removed.png','09-10000004','dankor@gmail.com',NULL,'Yangon Region — 40 Test Road, Removed Township','LIC-TEST-REMOVED-20260805','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL,NULL),(6,'Dango',NULL,'hq.png','09881938483','maxer@gmail.com',NULL,'BanyarDala Street','jjko.png','2026-08-05 07:08:32','2026-08-05 07:08:32',NULL,NULL),(8,'CODEX REGISTRATION VERIFY 20260805',NULL,'uploads/codex-verify-logo.png','09-77770000','codex.verify.20260805@example.test',NULL,'Yangon Region — No. 88, Verification Road, Test Township, Yangon','BL-CODEX-VERIFY-20260805','2026-08-05 07:26:06','2031-12-31 23:59:59',NULL,NULL),(60010,'Apex Fueling Group - Yangon HQ',NULL,'uploads/apex-fueling-logo.png','09-421234567','thura.min.apex.20260805@example.test',NULL,'Yangon Region — No. 123, Pyay Road, Kamayut Township, Yangon','BL-YGN-APEX-2026-001','2026-08-07 08:46:02','2031-12-31 23:59:59',NULL,NULL),(90010,'testing group',NULL,'uploads/testing-logo.png','09223344555','tester@gmail.com',NULL,'Yangon Region — tteesstteerr','BL-YGN-test-2026-001','2026-08-08 07:26:10','2030-02-25 23:59:59',NULL,NULL),(180010,'tempany',NULL,'https://static.wikia.nocookie.net/my-three-beautiful-wives-are-vampires/images/4/46/Concepts.png/revision/latest?cb=20250104003556','09454512708','temp45@gmail.com',NULL,'Naypyidaw Region — Stella City, Pepper Township','BL-YGN-temp-2026-001','2026-08-12 07:14:37','2029-01-14 23:59:59',NULL,NULL),(480010,'Test HQ Company',600016,'uploads/logo_1788767478.jpg','09-000000001','test.hq@mapandpump.test','Test HQ','Yangon Region - Test Township','TEST-HQ-LICENSE-2026','2026-09-07 07:51:21','2026-09-07 07:51:21',NULL,'2031-12-31');
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
  `action_type` varchar(20) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `reason` text COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `notification_status` varchar(30) COLLATE utf8mb4_0900_ai_ci NOT NULL DEFAULT 'not_configured',
  `notification_note` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`action_id`) /*T![clustered_index] CLUSTERED */,
  KEY `idx_hq_account_actions_hq` (`headquarter_id`),
  KEY `idx_hq_account_actions_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=420002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hq_account_actions`
--

LOCK TABLES `hq_account_actions` WRITE;
/*!40000 ALTER TABLE `hq_account_actions` DISABLE KEYS */;
INSERT INTO `hq_account_actions` VALUES (390002,3,'suspend','asdf','failed','Email delivery failed: Call to undefined function smtp_send_mail()','2026-09-11 08:27:29'),(390003,3,'remove','asdf','failed','Email delivery failed: Call to undefined function smtp_send_mail()','2026-09-11 08:27:39');
/*!40000 ALTER TABLE `hq_account_actions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hq_account_invitations`
--

DROP TABLE IF EXISTS `hq_account_invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hq_account_invitations` (
  `invitation_id` int unsigned NOT NULL AUTO_INCREMENT,
  `headquarter_id` int NOT NULL,
  `invited_user_id` int NOT NULL,
  `invited_email` varchar(255) NOT NULL,
  `business_role_id` int NOT NULL,
  `station_id` int NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `notification_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`invitation_id`) /*T![clustered_index] CLUSTERED */,
  KEY `idx_hq_invitation_user_status` (`invited_user_id`,`status`),
  KEY `idx_hq_invitation_hq` (`headquarter_id`),
  KEY `fk_hq_invitation_role` (`business_role_id`),
  KEY `fk_hq_invitation_station` (`station_id`),
  CONSTRAINT `fk_hq_invitation_hq` FOREIGN KEY (`headquarter_id`) REFERENCES `headquarter` (`headquarter_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hq_invitation_user` FOREIGN KEY (`invited_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hq_invitation_role` FOREIGN KEY (`business_role_id`) REFERENCES `roles` (`role_id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_hq_invitation_branch` FOREIGN KEY (`station_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=30001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hq_account_invitations`
--

LOCK TABLES `hq_account_invitations` WRITE;
/*!40000 ALTER TABLE `hq_account_invitations` DISABLE KEYS */;
INSERT INTO `hq_account_invitations` VALUES (1,2,780020,'satoo1020@gmail.com',4,60004,'accepted',480006,'2026-09-07 08:27:53','2026-09-07 08:40:07');
/*!40000 ALTER TABLE `hq_account_invitations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hq_receipt`
--

DROP TABLE IF EXISTS `hq_receipt`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hq_receipt` (
  `hq_receipt_id` int NOT NULL AUTO_INCREMENT,
  `hq_id` int DEFAULT NULL,
  `request_id` int unsigned DEFAULT NULL,
  `receipt_type` varchar(20) NOT NULL,
  `receipt_image` varchar(255) NOT NULL,
  PRIMARY KEY (`hq_receipt_id`) /*T![clustered_index] CLUSTERED */,
  KEY `hq_receipt_hq_id_idx` (`hq_id`),
  KEY `hq_receipt_request_id_idx` (`request_id`),
  CONSTRAINT `hq_receipt_hq_id` FOREIGN KEY (`hq_id`) REFERENCES `headquarter` (`headquarter_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hq_receipt`
--

LOCK TABLES `hq_receipt` WRITE;
/*!40000 ALTER TABLE `hq_receipt` DISABLE KEYS */;
/*!40000 ALTER TABLE `hq_receipt` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `invitations`
--

DROP TABLE IF EXISTS `invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invitations` (
  `invitation_id` int unsigned NOT NULL AUTO_INCREMENT,
  `headquarter_id` int NOT NULL,
  `invited_user_id` int NOT NULL,
  `invited_email` varchar(255) NOT NULL,
  `business_role_id` int NOT NULL,
  `station_id` int NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `notification_id` int unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`invitation_id`) /*T![clustered_index] CLUSTERED */,
  KEY `idx_invitations_user_status` (`invited_user_id`,`status`),
  KEY `idx_invitations_hq` (`headquarter_id`),
  KEY `fk_invitations_role` (`business_role_id`),
  KEY `fk_invitations_station` (`station_id`),
  CONSTRAINT `fk_invitations_hq` FOREIGN KEY (`headquarter_id`) REFERENCES `headquarter` (`headquarter_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invitations_user` FOREIGN KEY (`invited_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invitations_role` FOREIGN KEY (`business_role_id`) REFERENCES `roles` (`role_id`) ON DELETE NO ACTION,
  CONSTRAINT `fk_invitations_branch` FOREIGN KEY (`station_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=60001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `invitations`
--

LOCK TABLES `invitations` WRITE;
/*!40000 ALTER TABLE `invitations` DISABLE KEYS */;
INSERT INTO `invitations` VALUES (1,2,780020,'satoo1020@gmail.com',4,60004,'accepted',480006,'2026-09-07 08:27:53','2026-09-07 08:40:07');
/*!40000 ALTER TABLE `invitations` ENABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=570001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `log`
--

LOCK TABLES `log` WRITE;
/*!40000 ALTER TABLE `log` DISABLE KEYS */;
INSERT INTO `log` VALUES (30001,60018,4,'Updated admin profile avatar.','2026-08-14 07:47:08'),(30002,60018,4,'Updated admin profile avatar.','2026-08-14 07:47:09'),(30003,60018,4,'Generated report #30001 (hq_performance).','2026-08-14 07:47:09'),(90001,60018,NULL,'Marked Customer user #270014 as suspended.','2026-08-14 07:58:24'),(360001,210014,NULL,'Approved pending account #10 (test_active).','2026-08-31 08:06:19'),(390001,210014,NULL,'Approved pending account #750019 (Yum Yum).','2026-09-01 07:27:01'),(420001,210014,NULL,'Approved pending account #330014 (Kaung Kaung).','2026-09-04 06:34:43'),(420002,60018,NULL,'Updated admin profile details.','2026-09-04 07:42:54'),(420003,330014,4,'Edited staff #1','2026-09-04 08:23:52'),(420004,330014,4,'Deleted staff #2','2026-09-04 08:24:07'),(420005,330014,4,'Disabled staff #4','2026-09-04 08:27:54'),(420006,330014,4,'Activated staff #4','2026-09-04 08:27:56'),(450001,210014,NULL,'Approved pending account #810019 (yatiaung2357).','2026-09-04 14:23:03'),(480001,330014,4,'Deleted staff #1','2026-09-10 06:35:08'),(480002,330014,4,'Cancelled token MP-321-H8KQ','2026-09-10 07:26:17'),(480003,330014,4,'Cancelled token MP-563-H7KQ','2026-09-10 07:26:23'),(540001,60018,NULL,'Approved pending account #900020 (danielmelnedez).','2026-09-11 08:58:59'),(540002,60018,NULL,'Rejected pending account #12 (codex_verify_owner).','2026-09-11 08:59:37');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=420002;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=2540004;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (3,7,5,'admin-test-active-owner-20260805@example.test','request_approved','Admin test request approved','This seeded notification is available for approval workflow testing.','not_configured','Seeded test notification; no email was sent.','2026-08-05 06:32:38'),(30004,6,5,'admin-test-active-owner-20260805@example.test','request_approved','Map & Pump request TEST-20260805-002: Approved','Your request TEST-20260805-002 for ADMIN TEST 20260805 HQ Active has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-07 07:20:30'),(60004,30010,4,'admin-test-pending-owner-20260805@example.test','request_rejected','Map & Pump request HQ-REG-2: Rejected','Your request HQ-REG-2 for ADMIN TEST 20260805 HQ Pending was rejected.\n\nReason:\ntest','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-07 08:59:54'),(60005,30012,60014,'thura.min.apex.20260805@example.test','request_approved','Map & Pump request HQ-REG-60010: Approved','Your request HQ-REG-60010 for Apex Fueling Group - Yangon HQ has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-07 09:00:02'),(150004,60010,90014,'tester@gmail.com','request_approved','Map & Pump request HQ-REG-90010: Approved','Your request HQ-REG-90010 for testing group has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-12 07:12:42'),(300004,1,270015,'temp45@gmail.com','request_approved','Map & Pump request HQ-REG-180010: Approved','Your request HQ-REG-180010 for tempany has been approved.','not_configured','Email delivery is not configured; the notification was recorded for later delivery.','2026-08-21 08:23:48'),(480004,NULL,780020,'satoo1020@gmail.com','moderator_hire','Moderator access invitation','I want you!','accepted','{\"offered_role_id\":2,\"invited_by_user_id\":60018,\"offered_role_name\":\"Moderator\"}','2026-09-07 06:40:49'),(480005,NULL,810019,'yatiaung2357@gmail.com','moderator_hire','Moderator access invitation','Test....','pending','{\"offered_role_id\":2,\"invited_by_user_id\":60018,\"offered_role_name\":\"Moderator\"}','2026-09-07 07:47:49'),(480006,NULL,780020,'satoo1020@gmail.com','hq_invitation','New Map & Pump HQ invitation','Kaung Kaung HQ invited you to join Special One as a Branch Manager at Shoko. Accept or decline this invitation in your Map & Pump account before continuing.','failed','Email delivery failed.','2026-09-07 08:27:53'),(540005,NULL,900020,'this.fake.random.email.address.987654321@gmail.com','account_approved','Map & Pump account verification: Approved','Your Map & Pump account verification has been approved. You can now use the account.','failed','Email delivery failed: SMTP email is not configured. Set SMTP_USERNAME and SMTP_PASSWORD. SMTP_FROM_EMAIL is optional and defaults to SMTP_USERNAME.','2026-09-11 08:58:59'),(540006,NULL,12,'codex.verify.20260805@example.test','account_rejected','Map & Pump account verification: Rejected','Your Map & Pump account verification was rejected.\n\nReason:\ntest','failed','Email delivery failed: SMTP email is not configured. Set SMTP_USERNAME and SMTP_PASSWORD. SMTP_FROM_EMAIL is optional and defaults to SMTP_USERNAME.','2026-09-11 08:59:37');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=450002;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=450001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `report`
--

LOCK TABLES `report` WRITE;
/*!40000 ALTER TABLE `report` DISABLE KEYS */;
INSERT INTO `report` VALUES (30001,60018,2,NULL,'normal','August HQ performance summary','[scope=hq_performance][format=pdf][start=2022-12-01][end=2023-12-01] HQ records: 9; active linked accounts: 2','2026-08-10 08:25:41'),(90001,330014,4,NULL,'normal','station performance','This station performs at top1 in this week','2026-08-14 07:34:16'),(390001,600017,5,NULL,'low','Good but bad temper','Why: Good but customer don\'t use polite language','2026-08-31 08:47:37'),(390002,600017,5,NULL,'normal','Good but bad temper','Recent problem: Low Skill','2026-08-31 08:56:54');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=450002;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=180001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `staff`
--

LOCK TABLES `staff` WRITE;
/*!40000 ALTER TABLE `staff` DISABLE KEYS */;
INSERT INTO `staff` VALUES (3,'ST-2093',1,'Xiao','Pump Attendant','Afternoon','09-425-100003',1,'Off Duty',NULL,'2026-08-23 16:41:43','2026-08-23 16:41:43'),(4,'ST-2094',1,'Henry','Supervisor','Afternoon','09-660-100004',1,'Leave',NULL,'2026-08-23 16:41:43','2026-09-04 08:27:56'),(5,'ST-2095',1,'Thiago','Pump Attendant','Night','09-511-100005',1,'Off Duty',NULL,'2026-08-23 16:41:43','2026-08-23 16:41:43');
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
  KEY `fk_station_scope_branch_id` (`station_id`),
  CONSTRAINT `fk_station_scope_hq_id` FOREIGN KEY (`headquarter_id`) REFERENCES `headquarter` (`headquarter_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_station_scope_manager_user_id` FOREIGN KEY (`branch_manager_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_station_scope_branch_id` FOREIGN KEY (`station_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `station_business_scope`
--

LOCK TABLES `station_business_scope` WRITE;
/*!40000 ALTER TABLE `station_business_scope` DISABLE KEYS */;
INSERT INTO `station_business_scope` VALUES (1,2,330014,'2026-08-23 16:50:30','2026-09-07 08:04:36'),(2,2,330014,'2026-08-23 16:50:30','2026-09-07 08:04:36'),(3,2,330014,'2026-08-23 16:50:30','2026-09-07 08:04:36'),(60003,2,330014,'2026-08-23 16:50:30','2026-09-07 08:04:36'),(60004,2,330014,'2026-08-23 16:50:30','2026-09-07 08:04:37'),(60005,2,330014,'2026-08-23 16:50:30','2026-09-07 08:04:37'),(60006,2,330014,'2026-08-23 16:50:30','2026-09-07 08:04:37'),(270003,2,330014,'2026-08-22 03:21:40','2026-09-07 08:04:37'),(480004,480010,840020,'2026-09-07 08:08:35','2026-09-07 08:08:35'),(480005,480010,840020,'2026-09-07 08:08:35','2026-09-07 08:08:35'),(480006,480010,840020,'2026-09-07 08:08:37','2026-09-07 08:08:37'),(480007,480010,840020,'2026-09-07 08:08:38','2026-09-07 08:08:38'),(480008,480010,840020,'2026-09-07 08:08:38','2026-09-07 08:08:38');
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
  CONSTRAINT `fk_station_oil_inventory_branch_id` FOREIGN KEY (`station_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=450002;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `station_oil_inventory`
--

LOCK TABLES `station_oil_inventory` WRITE;
/*!40000 ALTER TABLE `station_oil_inventory` DISABLE KEYS */;
INSERT INTO `station_oil_inventory` VALUES (1,1,1,4620,1,6000.000,'2026-08-12 07:39:03'),(2,1,2,5830,1,6000.000,'2026-08-12 07:39:38'),(3,1,3,2650,1,6000.000,'2026-08-12 07:35:41'),(4,1,4,7006,1,6000.000,'2026-08-12 07:40:05'),(5,2,1,4620,1,7500.000,'2026-08-12 07:39:03'),(6,2,2,5830,1,500.000,'2026-08-12 07:39:38'),(7,3,3,2650,1,900.000,'2026-08-12 07:35:41'),(8,2,4,7006,1,6000.000,'2026-08-12 07:40:05'),(390006,480004,1,3000,1,5000.000,'2026-09-07 08:08:35'),(390007,480004,2,3250,1,5000.000,'2026-09-07 08:08:35'),(390008,480004,3,3500,1,5000.000,'2026-09-07 08:08:35'),(390009,480004,4,3750,1,5000.000,'2026-09-07 08:08:35'),(390010,480005,1,3000,1,5000.000,'2026-09-07 08:08:35'),(390011,480005,2,3250,1,5000.000,'2026-09-07 08:08:36'),(390012,480005,3,3500,1,5000.000,'2026-09-07 08:08:36'),(390013,480005,4,3750,1,5000.000,'2026-09-07 08:08:36'),(390014,480006,1,3000,1,5000.000,'2026-09-07 08:08:37'),(390015,480006,2,3250,1,5000.000,'2026-09-07 08:08:37'),(390016,480006,3,3500,1,5000.000,'2026-09-07 08:08:37'),(390017,480006,4,3750,1,5000.000,'2026-09-07 08:08:37'),(390018,480007,1,3000,1,5000.000,'2026-09-07 08:08:38'),(390019,480007,2,3250,1,5000.000,'2026-09-07 08:08:38'),(390020,480007,3,3500,1,5000.000,'2026-09-07 08:08:38'),(390021,480007,4,3750,1,5000.000,'2026-09-07 08:08:38'),(390022,480008,1,3000,1,5000.000,'2026-09-07 08:08:38'),(390023,480008,2,3250,1,5000.000,'2026-09-07 08:08:39'),(390024,480008,3,3500,1,5000.000,'2026-09-07 08:08:39'),(390025,480008,4,3750,1,5000.000,'2026-09-07 08:08:39');
/*!40000 ALTER TABLE `station_oil_inventory` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `station_to_branch_backup`
--

DROP TABLE IF EXISTS `station_to_branch_backup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `station_to_branch_backup` (
  `station_id` int NOT NULL AUTO_INCREMENT,
  `station_name` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `location` varchar(255) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `station_address` varchar(255) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `station_township` varchar(45) COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `business_user_id` int NOT NULL,
  `status` varchar(15) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`station_id`) /*T![clustered_index] CLUSTERED */,
  KEY `fk_business_user_id_idx` (`business_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=540005;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `station_to_branch_backup`
--

LOCK TABLES `station_to_branch_backup` WRITE;
/*!40000 ALTER TABLE `station_to_branch_backup` DISABLE KEYS */;
INSERT INTO `station_to_branch_backup` VALUES (1,'Danko(Mdy)','','Mandalay','Yangon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(2,'ADMIN TEST 20260805 Station','','Mandalay Region — Test Township','Yangon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(3,'Danko(TKT)','','Tarkayta Yangon','Yangon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(60003,'Myanko','','South Dagon','Yangon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(60004,'Shoko','','North Dagon','Yangon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(60005,'Niko','','East Dagon','Yangon',330014,'Active','2026-08-23 16:50:30','2026-08-23 16:50:30'),(60006,'Eko','','Wast Dagon','Yangon',330014,'Decommissioned','2026-08-23 16:50:30','2026-08-23 16:50:30'),(270003,'Dokan(Myanma Gonyi Street)','16.807045, 96.199229','Myanmar Gonyi Street','Yangon',330014,'Active','2026-09-04 17:01:29','2026-09-04 17:01:29'),(480004,'Denko Fuel Station','16.832415, 96.155821','No. 1, Map & Pump Test Road','Yangon',840020,'Active','2026-09-07 08:24:53','2026-09-07 08:24:53'),(480005,'Max Energy Fuel Station','16.832415, 96.155821','No. 2, Map & Pump Test Road','Yangon',840020,'Active','2026-09-07 08:24:53','2026-09-07 08:24:53'),(480006,'PT Power Fuel Station','16.825102, 96.132845','No. 3, Map & Pump Test Road','Yangon',840020,'Active','2026-09-07 08:24:54','2026-09-07 08:24:54'),(480007,'BOC / MPE Fuel Station','16.781204, 96.128410','No. 4, Map & Pump Test Road','Yangon',840020,'Active','2026-09-07 08:24:54','2026-09-07 08:24:54'),(480008,'Max Energy Fuel Station','16.786521, 96.173902','No. 5, Map & Pump Test Road','Yangon',840020,'Active','2026-09-07 08:24:54','2026-09-07 08:24:54');
/*!40000 ALTER TABLE `station_to_branch_backup` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Temporary view structure for view `stations`
--

DROP TABLE IF EXISTS `stations`;
/*!50001 DROP VIEW IF EXISTS `stations`*/;
SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `stations` AS SELECT 
 1 AS `station_id`,
 1 AS `station_name`,
 1 AS `location`,
 1 AS `station_address`,
 1 AS `station_township`,
 1 AS `business_user_id`,
 1 AS `status`,
 1 AS `created_at`,
 1 AS `updated_at`*/;
SET character_set_client = @saved_cs_client;

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
  KEY `idx_system_requests_headquarter` (`headquarter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=343697;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=90001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tanks`
--

LOCK TABLES `tanks` WRITE;
/*!40000 ALTER TABLE `tanks` DISABLE KEYS */;
INSERT INTO `tanks` VALUES (30001,480008,'dsl','Diesel',12000,5000,25,1,'2026-09-10 08:25:17'),(30002,480008,'p92','Petrol 92',15000,5000,25,1,'2026-09-10 08:25:17'),(30003,3,'dsl','Diesel',12000,900,25,1,'2026-09-10 08:25:17'),(30004,2,'p92','Petrol 92',15000,7500,25,1,'2026-09-10 08:25:17'),(30005,480006,'p92','Petrol 92',15000,5000,25,1,'2026-09-10 08:25:17'),(30006,480006,'p95','Petrol 95',10000,5000,25,1,'2026-09-10 08:25:17'),(30007,480005,'p92','Petrol 92',15000,5000,25,1,'2026-09-10 08:25:17'),(30008,1,'p92','Petrol 92',15000,6000,25,1,'2026-09-10 08:25:17'),(30009,480007,'p92','Petrol 92',15000,5000,25,1,'2026-09-10 08:25:17'),(30010,2,'p95','Petrol 95',10000,500,25,1,'2026-09-10 08:25:17'),(30011,480004,'pdsl','Premium Diesel',12000,5000,25,1,'2026-09-10 08:25:17'),(30012,480006,'pdsl','Premium Diesel',12000,5000,25,1,'2026-09-10 08:25:17'),(30013,480006,'dsl','Diesel',12000,5000,25,1,'2026-09-10 08:25:17'),(30014,480005,'p95','Petrol 95',10000,5000,25,1,'2026-09-10 08:25:17'),(30015,480004,'dsl','Diesel',12000,5000,25,1,'2026-09-10 08:25:17'),(30016,1,'pdsl','Premium Diesel',12000,6000,25,1,'2026-09-10 08:25:17'),(30017,480008,'p95','Petrol 95',10000,5000,25,1,'2026-09-10 08:25:17'),(30018,480004,'p92','Petrol 92',15000,5000,25,1,'2026-09-10 08:25:17'),(30019,480005,'dsl','Diesel',12000,5000,25,1,'2026-09-10 08:25:17'),(30020,480004,'p95','Petrol 95',10000,5000,25,1,'2026-09-10 08:25:17'),(30021,480007,'p95','Petrol 95',10000,5000,25,1,'2026-09-10 08:25:17'),(30022,480007,'pdsl','Premium Diesel',12000,5000,25,1,'2026-09-10 08:25:17'),(30023,1,'dsl','Diesel',12000,6000,25,1,'2026-09-10 08:25:17'),(30024,480005,'pdsl','Premium Diesel',12000,5000,25,1,'2026-09-10 08:25:17'),(30025,1,'p95','Petrol 95',10000,6000,25,1,'2026-09-10 08:25:17'),(30026,2,'pdsl','Premium Diesel',12000,6000,25,1,'2026-09-10 08:25:17'),(30027,480008,'pdsl','Premium Diesel',12000,5000,25,1,'2026-09-10 08:25:17'),(30028,480007,'dsl','Diesel',12000,5000,25,1,'2026-09-10 08:25:17');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=270001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usage_history`
--

LOCK TABLES `usage_history` WRITE;
/*!40000 ALTER TABLE `usage_history` DISABLE KEYS */;
INSERT INTO `usage_history` VALUES (1,1,210014,1,1,3,'MP-250-0A3I','MP-250-0A3I',1.00,NULL,NULL,'filled','2026-08-08 07:32:39','2026-08-08 07:32:39','2026-08-08 07:32:39',NULL,'2026-08-23 16:17:04'),(2,180004,210014,NULL,1,2,'MP-999-TEST1|Danko(Mdy)|Octane 95|15.0L','MP-999-TEST1',1.00,NULL,NULL,'filled','2026-08-14 07:13:57','2026-08-15 07:13:54','2026-08-14 07:22:49',NULL,'2026-08-23 16:17:04'),(3,180005,210014,NULL,1,2,'MP-999-TEST1|Danko(Mdy)|Octane 95|15.0L','MP-999-TEST1',1.00,NULL,NULL,'filled','2026-08-14 07:15:39','2026-08-16 07:15:39','2026-08-14 07:22:19',NULL,'2026-08-23 16:17:04'),(4,180009,210014,NULL,1,2,'MP-CAR-VERIFY-072827|Danko(Mdy)|Octane 95|15.0L','MP-CAR-VERIFY-072827',0.00,NULL,NULL,'filled','2026-08-14 07:28:29','2026-08-15 07:28:29','2026-08-14 07:48:44',NULL,'2026-08-23 16:17:04'),(120001,450003,210014,330003,3,3,'MP-817-4PHB|Danko(TKT)|Disel|20L','MP-817-4PHB',35.00,2650.00,92750.00,'filled','2026-08-26 08:18:26','2026-08-26 09:18:26','2026-08-26 08:19:09',210014,'2026-08-26 08:19:10'),(180001,540002,600017,450002,3,3,'MP-904-6G6J|Danko(TKT)|Disel|20L','MP-904-6G6J',20.00,2650.00,53000.00,'filled','2026-08-31 08:44:15','2026-08-31 09:44:15','2026-08-31 08:45:58',600017,'2026-08-31 08:45:58'),(210001,570002,750019,480002,3,3,'MP-813-KSZX|Danko(TKT)|Disel|20L','MP-813-KSZX',20.00,2650.00,53000.00,'filled','2026-09-01 07:30:27','2026-09-01 08:30:27','2026-09-01 07:33:05',600017,'2026-09-01 07:33:05');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=330001;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_account_actions`
--

LOCK TABLES `user_account_actions` WRITE;
/*!40000 ALTER TABLE `user_account_actions` DISABLE KEYS */;
INSERT INTO `user_account_actions` VALUES (1,270014,'suspend','test','not_configured','Email delivery is not configured; the reason was recorded in the HQ action history.','2026-08-14 07:58:24'),(240003,630019,'remove','Cuz ur GAY!!','failed','Email delivery failed: Call to undefined function smtp_send_mail()','2026-09-07 09:10:21'),(300001,210014,'suspend','adf','failed','Email delivery failed: Call to undefined function smtp_send_mail()','2026-09-11 08:08:55');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin AUTO_INCREMENT=390003;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_car`
--

LOCK TABLES `user_car` WRITE;
/*!40000 ALTER TABLE `user_car` DISABLE KEYS */;
INSERT INTO `user_car` VALUES (1,270014,1,1,'2026-08-24 07:23:42','2026-08-24 07:23:42'),(2,4,120002,0,'2026-08-24 07:23:42','2026-08-24 07:23:42'),(150003,210014,330002,1,'2026-08-24 07:23:42','2026-08-26 08:40:13'),(150004,210014,330003,0,'2026-08-24 07:23:42','2026-08-26 08:40:13'),(270003,600017,450002,1,'2026-08-31 08:42:33','2026-08-31 08:42:33'),(300003,750019,480002,1,'2026-09-01 07:30:07','2026-09-01 07:30:07'),(330003,330014,510002,1,'2026-09-04 06:37:23','2026-09-04 06:37:23');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=630003;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_license`
--

LOCK TABLES `user_license` WRITE;
/*!40000 ALTER TABLE `user_license` DISABLE KEYS */;
INSERT INTO `user_license` VALUES (1,'image',NULL,'1234','B','12345678','34567','2000-12-20','O','2016-09-10','pending','2026-08-04 06:24:11','2026-08-04 06:24:11'),(2,'license_image.jpg',NULL,'123456789','John Doe','555-1234','NRC123456','1990-01-01','O+','2025-12-31','pending','2026-08-05 06:37:28','2026-08-05 06:37:28'),(150003,'/php/Customer/uploads/licenses/license-150014-8d2fcee6de20d961.jpg',NULL,'YGN-1233','MoleOFKO','+95 9 799 055836','10/TAKETA(N)/123452','2000-12-21','O-','2030-11-11','approved','2026-08-15 05:47:13','2026-08-15 05:47:13'),(180003,'/php/Customer/uploads/licenses/license-210014-be77ccfa45961d78.jpg','/php/Customer/uploads/licenses/license-face-210014-8c9f216da4982fa2.jpg','YGN-1234','KoShane','+95 9 799 055836','10/TAKETA(N)/123452','2000-02-02','O-','2030-01-01','approved','2026-08-16 19:34:09','2026-08-16 19:34:09'),(210003,'/php/Customer/uploads/licenses/license-420015-45f3dc2d7151f1e6.jpg','/php/Customer/uploads/licenses/license-face-420015-69f9e339f2d5e5b8.jpg','L/12345/23','Kaung Kaung','+959799055836','14/YAKANA(N)123456','2012-12-31','AB-','2030-12-12','approved','2026-08-20 08:52:23','2026-08-20 08:52:23'),(420003,'/php/Customer/uploads/licenses/license-600017-9147ef44a3cf93c3.jpg','/php/Customer/uploads/licenses/license-face-600017-df2ef3bbb0c94b3b.jpg','L/11111/11','Test Staff','0911111111','1/KAMANA(E)111112','2001-01-01','A+','2030-02-28','approved','2026-08-31 08:37:01','2026-08-31 08:37:01'),(450003,'/php/Customer/uploads/licenses/license-750019-4891906d6f7e5f89.png','/php/Customer/uploads/licenses/license-face-750019-e950183acd71f562.jpg','L/56112/21','Yum Yum','09671129043','12/MAGADA(N)991123','1990-01-01','A+','2029-12-31','approved','2026-09-01 07:27:01','2026-09-01 07:27:01'),(480003,'/php/Customer/uploads/licenses/license-330014-eceac094a014b23d.webp','/php/Customer/uploads/licenses/license-face-330014-e5ef690b0e57a84f.jpg','L/11111/11','Kaung Kaung','0911111111','1/KAMANA(N)111112','2006-01-01','B+','2030-02-28','approved','2026-09-04 06:34:43','2026-09-04 06:34:43'),(510003,'/php/Customer/uploads/licenses/license-810019-1d0117ce3556b1a6.jpg','/php/Customer/uploads/licenses/license-face-810019-5e3bbd2fcf2b56a5.jpg','B/78906/25','yatiaung2357','0944660466','10/MAGADA(N)568907','2005-06-04','O-','2026-10-05','approved','2026-09-04 14:23:03','2026-09-04 14:23:03'),(540003,'/php/Customer/uploads/licenses/license-630020-a60235602b0958d5.png','/php/Customer/uploads/licenses/license-face-630020-d89ae2e064799e74.jpg','A/11111/11','Yan Aung','09111111111','7/PAMANA(N)263241','2005-06-29','O+','2026-09-05','pending','2026-09-05 12:56:28','2026-09-05 12:56:28'),(570003,'/php/Customer/uploads/licenses/license-840021-af6378fb2db1562a.png','/php/Customer/uploads/licenses/license-face-840021-f02d133728ebfe08.jpg','A/12345/23','Test Me','+959799055836','14/AHLANA(E)123456','2000-01-01','O-','2031-11-20','pending','2026-09-07 08:17:35','2026-09-07 08:17:35'),(600003,'/php/Customer/uploads/licenses/license-600016-6a4d3f4604507fbf.jpg','/php/Customer/uploads/licenses/license-face-600016-52773dc4dfa5fe16.jpg','L/12345/23','Test HQ','+95912345678','13/YAKANA(T)123456','2011-06-10','O+','2026-12-03','approved','2026-09-10 09:12:11','2026-09-10 09:12:11');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci AUTO_INCREMENT=960019;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (4,'000004','admin_test_pending_owner','admin-test-pending-owner-20260805@example.test','$2y$12$TjVx96cvj5VBIYSZRyZ2AeDZsh/CG6fXWEjpEmcP89zlEbyTArXzm',NULL,2,6,3,6,NULL,'rejected',0,'2026-08-23 16:50:29','2026-08-23 16:50:29',NULL,NULL,NULL,NULL),(5,'000005','admin_test_active_owner','admin-test-active-owner-20260805@example.test','$2y$12$t7uyDkU/8Lyu4LzIZllkUuHjv0HJM0BniOpCcR1tA.n7SOdoU.JVa',NULL,3,6,3,6,NULL,'removed',0,'2026-09-11 08:27:39','2026-09-11 08:27:39',NULL,NULL,NULL,NULL),(6,'000006','admin_test_suspended_owner','admin-test-suspended-owner-20260805@example.test','$2y$12$gcUUWoJ/VSfvM2T.4z6.puCIK98Nmd8HBwqj9kKhScvXSlxv5BSh6',NULL,4,6,3,6,NULL,'suspended',0,'2026-08-23 16:50:29','2026-08-23 16:50:29',NULL,NULL,NULL,NULL),(7,'000007','admin_test_removed_owner','admin-test-removed-owner-20260805@example.test','$2y$12$3exwXVIQRqKrhuIw52qq5eir1Y862gHkJo/WG1QseaKMu7h5M5WrS',NULL,5,6,3,6,NULL,'removed',0,'2026-08-23 16:50:29','2026-08-23 16:50:29',NULL,NULL,NULL,NULL),(8,'000008','admin_test_active_operator','admin-test-active-operator-20260805@example.test','$2y$12$NCWhFV6J1W7EG5G9SPQbnOAaon0DfimXpTA3nMwvldQvUqNuQtq0q',NULL,3,6,3,6,NULL,'removed',0,'2026-09-11 08:27:39','2026-09-11 08:27:39',NULL,NULL,NULL,NULL),(10,'00000A','test_active','admin-test-active-operator-2026005@example.test','$2y$12$NCWhFV6J1W7EG5G9SPQbnOAaon0DfimXpTA3nMwvldQvUqNuQtq0q',NULL,NULL,6,1,6,NULL,'approved',0,'2026-08-31 08:06:18','2026-08-31 08:06:18',NULL,NULL,NULL,NULL),(12,'00000C','codex_verify_owner','codex.verify.20260805@example.test','$2y$12$CFhA7Kc/ioNIRtIvpAl/zuAEtggXfKu5BVb/bKv.wpZUUPYEJNtq6',NULL,8,6,3,6,NULL,'rejected',0,'2026-09-11 08:59:37','2026-09-11 08:59:37',NULL,NULL,NULL,NULL),(60014,'00EA6E','thura_min','thura.min.apex.20260805@example.test','$2y$12$E1GPNjcZHJ9GNYujJ3IEiOZoXFbHBYVxtnxLDaQzJ9eq6eyEEvh7K',NULL,60010,6,3,6,NULL,'approved',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(60018,'00EA72','Beak Ga-in','eithinzarmon@gmail.com','$2y$12$hQ1p3zKXAOXiSowHMtX9b.ppxkkLmE7afcvYvb0n83yuaANm4IpFa',NULL,NULL,6,1,1,NULL,'approved',0,'2026-09-12 08:14:55','2026-09-12 08:14:55',NULL,NULL,'/php/Admin(WebOwner)/uploads/admin-60018-91a6a033ed0d732a.jpg','2026-09-12 08:14:55'),(90014,'015F9E','tester_testing','tester@gmail.com','$2y$12$pkK4Y60pVgKwWFwdAiyXjuIE/6v645sV2lOoDVH2RV9MNttr8NmI6',NULL,90010,6,3,6,NULL,'approved',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(150014,'0249FE','MoleOFKO','shanekhant23@gmail.com','$2y$12$R.Aa6RBMhT7ROiteN9wFg.4bXcJLZDNjd5JlXbQG.bv5jTJU1Ij4i',150003,NULL,6,NULL,6,NULL,'active',0,'2026-09-04 07:34:50','2026-09-04 07:34:50','google','100787895739316965029','https://lh3.googleusercontent.com/a/ACg8ocJDnzcBRStX0x8DYtMJdMkWUkiL79x6k68sixAToMLC-xMLtlE=s96-c','2026-08-28 06:49:27'),(210014,'03345E','KoShane','shanekopersonal@gmail.com','$2y$12$kY8nqDP4RnlrO.G1ao076ehpykOz5x6R6/YrS0o9QeeSE7ZgQRZQu',180003,2,6,2,6,NULL,'suspended',0,'2026-09-11 08:08:54','2026-09-11 08:08:54',NULL,NULL,'/php/Moderators(UnderAdmin)/uploads/moderator_210014_1788158885.jpg','2026-09-10 08:20:59'),(240014,'03A98E','KoShab','mapa.and.pump@gmail.com','$2y$12$mgqjrg5LoQeLu7kQgrCh1uNqz2h17sq42oJipzDIwpakJUQsco52K',180003,NULL,6,1,1,NULL,'active',0,'2026-09-04 08:35:20','2026-09-04 08:35:20','google','118311246391062428561','https://lh3.googleusercontent.com/a/ACg8ocIXZque38Mkmro0Ieg_CVZtgFvpoGTrQKxjw6gzx6qGk4FY=s96-c','2026-08-29 04:40:57'),(270014,'041EBE','Larry-sama','koshan2104@gmail.com','$2y$12$f6VHNFpbbWY9hSX5BiFdw.QNaK5O0SUMo91nqI66NcL1KBCljsxxC',NULL,NULL,6,NULL,6,NULL,'suspended',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(270015,'041EBF','temp_temp','temp45@gmail.com','$2y$12$caTKDBBZre2OkSHh4xf3ReBFTeQ78.Q6NyYVxxuLd/A80sWVp6oB6',NULL,180010,6,3,6,NULL,'approved',0,'2026-09-11 09:25:15','2026-09-11 09:25:15',NULL,NULL,NULL,NULL),(330014,'05091E','Test Branch Manager','test.branchmanager@mapandpump.test','$2y$12$BBVB5FgampfqB98X4v5theBNR9SEFlxNAuXr5c8u9xjQyZqbq/NE6',480003,2,6,4,4,NULL,'active',0,'2026-09-10 09:04:27','2026-09-10 09:04:27',NULL,NULL,NULL,'2026-09-10 09:04:27'),(420014,'0668AE','Test Me','bhonen94@gmail.com','$2y$12$uRed5QfY3CAWxZaaglmkNea/sfz38RITpP0KeI8jZO8LkapvHhaSS',NULL,NULL,6,NULL,6,NULL,'active',0,'2026-08-23 16:17:22','2026-08-23 16:17:22',NULL,NULL,NULL,NULL),(420015,'0668AF','Kaung Kaung HQ','kaungzin23may@gmail.com','$2y$10$4PXW5VHtOKMQyO6597rLK.GP2dIWf.DYjqcW//zhXWpaq33D62mdi',210003,2,6,3,3,NULL,'active',0,'2026-09-12 05:06:50','2026-09-12 05:06:50',NULL,NULL,'/php/HQ/uploads/profile/hq-profile-420015-acf5f5a2b3fbc9ee.jpg','2026-09-12 05:06:50'),(600014,'0927CE','Test Admin','test.admin@mapandpump.test','$2y$12$awtEws/29QtZLz/AJlBhWOXyrE96xZ2X9hBL7ZD0p9xfzxoW.YTEG',NULL,NULL,6,1,1,NULL,'active',0,'2026-09-11 19:36:59','2026-09-11 19:36:59',NULL,NULL,NULL,'2026-09-11 19:36:59'),(600015,'0927CF','Test Moderator','test.moderator@mapandpump.test','$2y$12$FiGXeOMmq7O1E6CpSMqrnuBvm75b5l1biKOhdhh/9IPboadaAqf1y',NULL,NULL,6,2,2,NULL,'active',0,'2026-09-10 08:54:57','2026-09-10 08:54:57',NULL,NULL,NULL,'2026-09-10 08:54:57'),(600016,'0927D0','Test HQ','test.hq@mapandpump.test','$2y$12$mLrbOowJ5Jp0r2zCiVf8v.GhfcWX4jezJqHr/K.fYIAbKNEMNDQwK',600003,480010,6,3,6,NULL,'active',0,'2026-09-10 09:11:08','2026-09-10 09:11:08',NULL,NULL,NULL,'2026-09-10 09:11:08'),(600017,'0927D1','Test Staff','test.staff@mapandpump.test','$2y$12$c5wZ3HcOcDr7s5Ui8UOLWudr5AnSD/fYemk/yA3uNzQ4OUu/wMTwS',420003,480010,6,5,6,1,'active',0,'2026-09-10 09:25:45','2026-09-10 09:25:45',NULL,NULL,NULL,'2026-09-10 09:25:45'),(630019,'099D03','yan aung bala','yan60421@gmail.com','$2y$12$UDgj/mBIehPxL5F4WOraoOJjteyNoIgW/gL6dr4b/RKiR328tCwxC',NULL,NULL,6,NULL,6,NULL,'removed',0,'2026-09-07 09:10:21','2026-09-07 09:10:21','google','108332824446244416656','https://lh3.googleusercontent.com/a/ACg8ocLYGAxKp4X8VLY-EfLvNrZlGiBqn9Us6I1CGvd7YRrmcdWwtA=s96-c','2026-09-06 09:02:54'),(630020,'099D04','Yan Aung','yanaung1106@gmail.com','$2y$12$t1tfBywjkf6v9m66maOKHub.gOemptXycvhGs0WJi5EqidTlLnMja',540003,NULL,6,NULL,6,NULL,'pending_verification',0,'2026-09-07 08:20:41','2026-09-07 08:20:41','google','116848264870935136373','https://lh3.googleusercontent.com/a/ACg8ocKRgoMg7gTOePWQSVaMB_Nblf4ZiBZ-WXYhy--99oXPcmk_sUc=s96-c','2026-09-07 08:20:41'),(720019,'0AFC93','danielmelnedez','icec9270@gmail.com','$2y$10$i0Hq1vQm5UqjDMT2ujT1Juv4/.Nh1i6P6QZPX4ojfUMz9Uvq5teSW',NULL,NULL,6,NULL,6,NULL,'active',0,'2026-08-31 16:07:45','2026-08-31 16:07:45',NULL,NULL,NULL,NULL),(750019,'0B71C3','Yum Yum','yanaungbala5678@gmail.com','$2y$10$xmMfOdzJXzf8TxB9D8yGf.r5af5Q.7a/5P899d.QXsiQw64vsj.7u',450003,NULL,6,NULL,6,NULL,'approved',0,'2026-09-01 07:37:20','2026-09-01 07:37:20',NULL,NULL,NULL,NULL),(780020,'0BE6F4','TempTest','satoo1020@gmail.com','$2y$12$aP8AEyAssHPLFy.9Iao6Ve01VNsU.OXkxi7e7POhzsO5wHTKWcT9a',NULL,2,2,4,6,NULL,'active',0,'2026-09-07 08:41:21','2026-09-07 08:41:21',NULL,NULL,NULL,'2026-09-07 08:41:21'),(810019,'0C5C23','yatiaung2357','yatiaung2357@gmail.com','$2y$10$hvabpyJWe0SGYIhGtJDv7.dY0N7Upf2NpuvS/8PPqJi4KM2TpEmiK',510003,NULL,6,NULL,6,NULL,'approved',0,'2026-09-04 14:23:03','2026-09-04 14:23:03',NULL,NULL,NULL,NULL),(840020,NULL,'Test HQ Branch Manager','test.branchmanager.480010@mapandpump.test','$2y$12$cr.J4NwXuuChOqUS1OObVeVMA5o5LJkjKKGXvS9HGl3Rrb794ddfa',NULL,480010,6,4,4,NULL,'active',0,'2026-09-07 08:08:34','2026-09-07 08:08:34',NULL,NULL,NULL,NULL),(840021,'0CD155','Test Me','atuwahipil68@gmail.com','$2y$12$lmh7cw9dleA/wuwtKW8xA.pe7EZwyBmznCYL.LD7d9MkyoSshxP5i',570003,NULL,6,NULL,6,NULL,'pending_verification',0,'2026-09-07 08:17:35','2026-09-07 08:17:35',NULL,NULL,NULL,'2026-09-07 08:16:08'),(870019,NULL,'danielmelnedez','this.is.a.very.random.fake.email.987654321@gmail.com','$2y$10$uKIdIdT/vtNBTGq8LGSvq.Vtov3r251XMreQDu0q2Qd7ndjeXOwr6',NULL,NULL,6,NULL,6,NULL,'pending_verification',0,'2026-09-08 09:08:56','2026-09-08 09:08:56',NULL,NULL,NULL,NULL),(870020,NULL,'danielmelnedez','this.is.a.ver@gmail.com','$2y$10$/zcjta.Ed8xb2TRQC3QOWunFHxtXqlUpNpzZcC0WxQzWsDI7k8pzi',NULL,NULL,6,NULL,6,NULL,'pending_verification',0,'2026-09-08 09:09:23','2026-09-08 09:09:23',NULL,NULL,NULL,NULL),(900019,NULL,'danielmelnedez','this.is.a.fake.nonexistent.gmail.address1984293@gmail.com','$2y$10$MD1ZY6UMH.wafSXttHkHXO3u7GsSqldFp0HfP5s8YPGVtL9VTWAIC',NULL,NULL,6,NULL,6,NULL,'pending_verification',0,'2026-09-10 06:27:00','2026-09-10 06:27:00',NULL,NULL,NULL,NULL),(900020,NULL,'danielmelnedez','this.fake.random.email.address.987654321@gmail.com','$2y$12$dSrnIaJ.sxlns90heAyQ8eZyOVdTon5b882Oi/GUT8DMtNy2TWhmK',NULL,NULL,6,NULL,6,NULL,'approved',0,'2026-09-11 08:58:59','2026-09-11 08:58:59',NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Final view structure for view `stations`
--

/*!50001 DROP VIEW IF EXISTS `stations`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`3sdV1BWxtCzY7YS.root`@`%` SQL SECURITY DEFINER */
/*!50001 VIEW `stations` (`station_id`, `station_name`, `location`, `station_address`, `station_township`, `business_user_id`, `status`, `created_at`, `updated_at`) AS SELECT `b`.`branch_id` AS `station_id`,`b`.`branch_name` AS `station_name`,CASE WHEN `b`.`latitude` IS NOT NULL AND `b`.`longitude` IS NOT NULL THEN CONCAT(FORMAT(`b`.`latitude`, 6), _UTF8MB4', ', FORMAT(`b`.`longitude`, 6)) ELSE _UTF8MB4'' END AS `location`,`b`.`address` AS `station_address`,COALESCE(`b`.`township`, _UTF8MB4'Unknown') AS `station_township`,`b`.`manager_user_id` AS `business_user_id`,CASE `b`.`status` WHEN _UTF8MB4'active' THEN _UTF8MB4'Active' WHEN _UTF8MB4'maintenance' THEN _UTF8MB4'Maintenance' ELSE _UTF8MB4'Decommissioned' END AS `status`,`b`.`created_at` AS `created_at`,`b`.`updated_at` AS `updated_at` FROM `map_and_pump`.`branches` AS `b` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-09-12 14:52:16
