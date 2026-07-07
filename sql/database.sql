-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: ipmanage
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (41,1,'add_switch','switch',3,'Added switch router utama (192.168.5.1)','2026-05-09 10:28:13'),(42,NULL,'poll_switch','switch',3,'Discovered 11 mappings on router utama','2026-05-09 10:33:33'),(43,1,'add_switch','switch',4,'Added switch cisco-sw (192.168.2.6)','2026-05-09 10:34:11'),(44,1,'delete_switch','switch',4,'Deleted switch ID 4','2026-05-09 10:35:58'),(45,1,'add_switch','switch',5,'Added switch cisco-sw (192.168.2.5)','2026-05-09 10:36:14'),(46,NULL,'poll_switch','switch',3,'Discovered 11 mappings on router utama','2026-05-09 10:36:23');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bug_reports`
--

DROP TABLE IF EXISTS `bug_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bug_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `system_info` text DEFAULT NULL,
  `status` enum('pending','resolved') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bug_reports`
--

LOCK TABLES `bug_reports` WRITE;
/*!40000 ALTER TABLE `bug_reports` DISABLE KEYS */;
/*!40000 ALTER TABLE `bug_reports` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ip_addresses`
--

DROP TABLE IF EXISTS `ip_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ip_addresses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subnet_id` int(11) NOT NULL,
  `ip_addr` varchar(45) NOT NULL,
  `description` text DEFAULT NULL,
  `hostname` varchar(100) DEFAULT NULL,
  `state` enum('active','reserved','offline','dhcp') NOT NULL DEFAULT 'active',
  `last_seen` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `mac_addr` varchar(20) DEFAULT NULL,
  `vendor` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `conflict_detected` tinyint(1) NOT NULL DEFAULT 0,
  `confidence_score` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `data_sources` varchar(100) DEFAULT NULL,
  `fail_count` int(11) NOT NULL DEFAULT 0,
  `asset_tag` varchar(100) DEFAULT NULL,
  `owner` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_subnet_ip` (`subnet_id`,`ip_addr`),
  KEY `subnet_id` (`subnet_id`),
  KEY `idx_mac` (`mac_addr`),
  KEY `idx_host` (`hostname`),
  CONSTRAINT `ip_addresses_ibfk_1` FOREIGN KEY (`subnet_id`) REFERENCES `subnets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ip_addresses`
--

LOCK TABLES `ip_addresses` WRITE;
/*!40000 ALTER TABLE `ip_addresses` DISABLE KEYS */;
/*!40000 ALTER TABLE `ip_addresses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `netwatch`
--

DROP TABLE IF EXISTS `netwatch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `netwatch` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `host` varchar(100) NOT NULL,
  `ping_interval` int(11) NOT NULL DEFAULT 60,
  `status` enum('up','down','unknown') NOT NULL DEFAULT 'unknown',
  `fail_count` int(11) NOT NULL DEFAULT 0,
  `fail_threshold` int(11) NOT NULL DEFAULT 3,
  `last_up` timestamp NULL DEFAULT NULL,
  `last_down` timestamp NULL DEFAULT NULL,
  `last_check` timestamp NULL DEFAULT NULL,
  `maintenance_until` datetime DEFAULT NULL,
  `notify` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `netwatch`
--

LOCK TABLES `netwatch` WRITE;
/*!40000 ALTER TABLE `netwatch` DISABLE KEYS */;
/*!40000 ALTER TABLE `netwatch` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `netwatch_history`
--

DROP TABLE IF EXISTS `netwatch_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `netwatch_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `netwatch_id` int(11) NOT NULL,
  `latency` float DEFAULT 0,
  `status` enum('up','down','unknown') DEFAULT 'unknown',
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_netwatch_time` (`netwatch_id`,`recorded_at`),
  CONSTRAINT `netwatch_history_ibfk_1` FOREIGN KEY (`netwatch_id`) REFERENCES `netwatch` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `netwatch_history`
--

LOCK TABLES `netwatch_history` WRITE;
/*!40000 ALTER TABLE `netwatch_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `netwatch_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sections`
--

DROP TABLE IF EXISTS `sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sections`
--

LOCK TABLES `sections` WRITE;
/*!40000 ALTER TABLE `sections` DISABLE KEYS */;
INSERT INTO `sections` VALUES (1,'Default Section','Automatically created default section');
/*!40000 ALTER TABLE `sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `server_assets`
--

DROP TABLE IF EXISTS `server_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `server_assets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hostname` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `category` varchar(50) DEFAULT 'General',
  `username` varchar(100) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `is_encrypted` tinyint(1) DEFAULT 0,
  `port` int(11) DEFAULT 22,
  `status` varchar(20) DEFAULT 'UNKNOWN',
  `last_check` timestamp NULL DEFAULT NULL,
  `installed_apps` text DEFAULT NULL,
  `missing_apps` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `server_assets`
--

LOCK TABLES `server_assets` WRITE;
/*!40000 ALTER TABLE `server_assets` DISABLE KEYS */;
/*!40000 ALTER TABLE `server_assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'telegram_enabled','0','2026-03-29 06:00:00'),(2,'telegram_bot_token','','2026-03-29 06:00:00'),(3,'telegram_chat_id','','2026-03-29 06:00:00'),(4,'email_enabled','0','2026-03-29 06:00:00'),(5,'admin_email','','2026-03-29 06:00:00'),(6,'nmap_enabled','0','2026-03-29 06:00:00'),(7,'discovery_aggressive','1','2026-03-31 07:30:00'),(52,'masscan_enabled','0','2026-06-23 00:00:00'),(53,'masscan_rate','1000','2026-06-23 00:00:00'),(41,'subnet_limit_threshold','80','2026-03-31 07:30:00'),(42,'offline_fail_threshold','3','2026-03-31 07:30:00'),(43,'discord_enabled','0','2026-05-09 10:27:34'),(44,'slack_enabled','0','2026-05-09 10:27:34'),(45,'custom_netwatch_template','','2026-05-09 10:27:34'),(46,'retention_port_history','30','2026-05-10 12:00:00'),(47,'retention_health_history','30','2026-05-10 12:00:00'),(48,'retention_netwatch_history','30','2026-05-10 12:00:00'),(49,'retention_audit_logs','90','2026-05-10 12:00:00'),(50,'retention_auto_cleanup','1','2026-05-10 12:00:00'),(51,'last_db_cleanup','0','2026-05-10 12:00:00');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stats_history`1
--

DROP TABLE IF EXISTS `stats_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stats_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `snapshot_date` date NOT NULL,
  `total_active` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_date` (`snapshot_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stats_history`
--

LOCK TABLES `stats_history` WRITE;
/*!40000 ALTER TABLE `stats_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `stats_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subnets`
--

DROP TABLE IF EXISTS `subnets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subnets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subnet` varchar(45) NOT NULL,
  `mask` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `vlan_id` int(11) DEFAULT NULL,
  `master_subnet` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `scan_interval` int(11) DEFAULT 0,
  `last_scan` timestamp NULL DEFAULT NULL,
  `last_limit_alert` timestamp NULL DEFAULT NULL,
  `utilization_threshold` int(11) DEFAULT NULL,
  `parent_switch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `section_id` (`section_id`),
  KEY `vlan_id` (`vlan_id`),
  CONSTRAINT `subnets_ibfk_1` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `subnets_ibfk_2` FOREIGN KEY (`vlan_id`) REFERENCES `vlans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subnets`
--

LOCK TABLES `subnets` WRITE;
/*!40000 ALTER TABLE `subnets` DISABLE KEYS */;
/*!40000 ALTER TABLE `subnets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `switch_health_history`
--

DROP TABLE IF EXISTS `switch_health_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `switch_health_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `switch_id` int(11) NOT NULL,
  `cpu_usage` int(11) NOT NULL DEFAULT 0,
  `memory_usage` int(11) NOT NULL DEFAULT 0,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_switch_time` (`switch_id`,`recorded_at`),
  CONSTRAINT `switch_health_history_ibfk_1` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `switch_health_history`
--

LOCK TABLES `switch_health_history` WRITE;
/*!40000 ALTER TABLE `switch_health_history` DISABLE KEYS */;
INSERT INTO `switch_health_history` VALUES (1,3,0,7,'2026-05-09 10:30:48'),(2,3,0,7,'2026-05-09 10:33:33'),(4,3,0,7,'2026-05-09 10:36:23');
/*!40000 ALTER TABLE `switch_health_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `switch_port_history`
--

DROP TABLE IF EXISTS `switch_port_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `switch_port_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `switch_id` int(11) DEFAULT NULL,
  `port_name` varchar(100) DEFAULT NULL,
  `rx_bps` bigint(20) DEFAULT NULL,
  `tx_bps` bigint(20) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `switch_id` (`switch_id`,`port_name`),
  KEY `recorded_at` (`recorded_at`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `switch_port_history`
--

LOCK TABLES `switch_port_history` WRITE;
/*!40000 ALTER TABLE `switch_port_history` DISABLE KEYS */;
INSERT INTO `switch_port_history` VALUES (1,3,'ether1-inet',602749,41583,'2026-05-09 10:36:23'),(2,3,'ether2-pc',0,0,'2026-05-09 10:36:23'),(3,3,'ether3-to-Sw',391616,37576,'2026-05-09 10:36:23'),(4,3,'ether4-to-cctv',499,872,'2026-05-09 10:36:23'),(5,3,'ether5-backup',78364,998157,'2026-05-09 10:36:23');
/*!40000 ALTER TABLE `switch_port_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `switch_port_latest_counters`
--

DROP TABLE IF EXISTS `switch_port_latest_counters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `switch_port_latest_counters` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `switch_id` int(11) DEFAULT NULL,
  `port_name` varchar(100) DEFAULT NULL,
  `last_rx_octets` bigint(20) unsigned DEFAULT NULL,
  `last_tx_octets` bigint(20) unsigned DEFAULT NULL,
  `last_poll` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `switch_id` (`switch_id`,`port_name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `switch_port_latest_counters`
--

LOCK TABLES `switch_port_latest_counters` WRITE;
/*!40000 ALTER TABLE `switch_port_latest_counters` DISABLE KEYS */;
INSERT INTO `switch_port_latest_counters` VALUES (1,3,'ether1-inet',25244689831,3738811799,'2026-05-09 10:36:23'),(2,3,'ether2-pc',2065667251,5911166319,'2026-05-09 10:36:23'),(3,3,'ether3-to-Sw',8431292,1321935,'2026-05-09 10:36:23'),(4,3,'ether4-to-cctv',695989650,2463212145,'2026-05-09 10:36:23'),(5,3,'ether5-backup',1575426370,17462571739,'2026-05-09 10:36:23');
/*!40000 ALTER TABLE `switch_port_latest_counters` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `switch_port_map`
--

DROP TABLE IF EXISTS `switch_port_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `switch_port_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mac_addr` varchar(100) NOT NULL,
  `switch_id` int(11) NOT NULL,
  `port_name` varchar(100) NOT NULL,
  `vlan_id` int(11) DEFAULT NULL,
  `vlan_name` varchar(100) DEFAULT NULL,
  `port_status` varchar(20) DEFAULT NULL,
  `port_type` varchar(30) DEFAULT NULL,
  `port_speed` varchar(10) DEFAULT NULL,
  `port_alias` varchar(200) DEFAULT NULL,
  `sfp_vendor` varchar(100) DEFAULT NULL,
  `sfp_part` varchar(100) DEFAULT NULL,
  `sfp_serial` varchar(100) DEFAULT NULL,
  `sfp_rx_power` varchar(50) DEFAULT NULL,
  `sfp_tx_power` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `mac_switch` (`mac_addr`,`switch_id`),
  KEY `switch_id` (`switch_id`),
  CONSTRAINT `switch_port_map_ibfk_1` FOREIGN KEY (`switch_id`) REFERENCES `switches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=255 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `switch_port_map`
--

LOCK TABLES `switch_port_map` WRITE;
/*!40000 ALTER TABLE `switch_port_map` DISABLE KEYS */;
INSERT INTO `switch_port_map` VALUES (223,'10:60:4B:71:B0:C4',3,'ether5-backup',100,NULL,'up','ethernet','1G','','2026-05-09 10:36:23'),(224,'74:4D:28:AD:1B:FD',3,'Port 0',200,NULL,NULL,NULL,NULL,NULL,'2026-05-09 10:36:23'),(225,'74:4D:28:AD:1B:FE',3,'ether3-to-Sw',200,NULL,'up','ethernet','1G','','2026-05-09 10:36:23'),(226,'74:4D:28:AD:1C:00',3,'ether5-backup',100,NULL,'up','ethernet','1G','','2026-05-09 10:36:23'),(227,'90:8D:78:D8:2E:EE',3,'ether3-to-Sw',100,NULL,'up','ethernet','1G','','2026-05-09 10:36:23'),(228,'BC:F1:F2:96:C1:01',3,'ether3-to-Sw',200,NULL,'up','ethernet','1G','','2026-05-09 10:36:23'),(229,'4C:F5:DC:5E:E9:D2',3,'ether4-to-cctv',200,NULL,'up','ethernet','10M','','2026-05-09 10:36:23'),(232,'74:4D:28:AD:1B:FF',3,'ether4-to-cctv',200,NULL,'up','ethernet','10M','','2026-05-09 10:36:23'),(234,'',3,'ether1-inet',NULL,NULL,'up','ethernet','1G',NULL,'2026-05-09 10:33:34');
/*!40000 ALTER TABLE `switch_port_map` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `switches`
--

DROP TABLE IF EXISTS `switches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `switches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `ip_addr` varchar(45) NOT NULL,
  `community` varchar(100) DEFAULT 'public',
  `snmp_version` enum('1','2c','3') DEFAULT '2c',
  `description` text DEFAULT NULL,
  `last_poll` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `model` varchar(100) DEFAULT NULL,
  `uptime` varchar(100) DEFAULT NULL,
  `cpu_usage` int(11) DEFAULT 0,
  `memory_usage` int(11) DEFAULT 0,
  `system_info` text DEFAULT NULL,
  `total_ports` int(11) DEFAULT 0,
  `active_ports` int(11) DEFAULT 0,
  `parent_switch_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `switches`
--

LOCK TABLES `switches` WRITE;
/*!40000 ALTER TABLE `switches` DISABLE KEYS */;
INSERT INTO `switches` VALUES (3,'router utama','192.168.5.1','public','2c',NULL,'2026-05-09 10:36:23','2026-05-09 10:28:13','MikroTik','17d 23h 56m',0,7,'RouterOS RB450Gx4',5,4,NULL),(5,'cisco-sw','192.168.2.5','habib','2c',NULL,NULL,'2026-05-09 10:36:14',NULL,NULL,0,0,NULL,0,0,NULL);
/*!40000 ALTER TABLE `switches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `topology_links`
--

DROP TABLE IF EXISTS `topology_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `topology_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_switch_id` int(11) NOT NULL,
  `target_type` enum('switch','subnet') NOT NULL,
  `target_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `parent_switch_id` (`parent_switch_id`,`target_type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `topology_links`
--

LOCK TABLES `topology_links` WRITE;
/*!40000 ALTER TABLE `topology_links` DISABLE KEYS */;
/*!40000 ALTER TABLE `topology_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `role` enum('admin','user','viewer') NOT NULL DEFAULT 'viewer',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','$2y$10$iC1CpjbPVLpFx1BcbSTUsOZ52qhELYqHrKyADN/z9DF2UArhZEnPK',NULL,'admin','2026-03-27 04:17:59');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `vlans`
--

DROP TABLE IF EXISTS `vlans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vlans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `number` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `number` (`number`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `vlans`
--

LOCK TABLES `vlans` WRITE;
/*!40000 ALTER TABLE `vlans` DISABLE KEYS */;
/*!40000 ALTER TABLE `vlans` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-05-09 17:38:20
