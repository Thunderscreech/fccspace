/*M!999999\- enable the sandbox mode */ 

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
DROP TABLE IF EXISTS `ELS`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ELS` (
  `ELS_id` int(16) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(128) NOT NULL,
  `application_pub_date` datetime NOT NULL DEFAULT current_timestamp(),
  `application_title` varchar(64) NOT NULL,
  PRIMARY KEY (`ELS_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `faalaunchlicenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `faalaunchlicenses` (
  `launcher_id` int(11) NOT NULL AUTO_INCREMENT,
  `license` varchar(64) NOT NULL,
  `license_url` varchar(512) NOT NULL,
  `company` varchar(64) NOT NULL,
  `vehicle` varchar(32) NOT NULL,
  `location` varchar(32) NOT NULL,
  `expiration` date NOT NULL,
  `last_updated` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`launcher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fcc_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fcc_applications` (
  `application_id` int(16) NOT NULL AUTO_INCREMENT,
  `application_description` varchar(256) NOT NULL,
  `application_purpose` varchar(4096) DEFAULT NULL,
  `application_explanation` varchar(8192) DEFAULT NULL,
  `applicant_name` varchar(128) DEFAULT NULL,
  `application_address` varchar(512) DEFAULT NULL,
  `application_pub_date` datetime NOT NULL,
  `dates` varchar(64) DEFAULT NULL,
  `application_title` varchar(96) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `post_text` text DEFAULT NULL,
  `mastodon_done` tinyint(1) NOT NULL DEFAULT 1,
  `bluesky_done` tinyint(1) NOT NULL DEFAULT 1,
  `post_attempts` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`application_id`),
  KEY `idx_title` (`application_title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fcc_skipped`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fcc_skipped` (
  `application_title` varchar(96) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `permanent` tinyint(1) NOT NULL DEFAULT 0,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `first_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `last_seen` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`application_title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ibfs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ibfs` (
  `ibfs_id` int(11) NOT NULL AUTO_INCREMENT,
  `file_number` varchar(64) NOT NULL,
  `last_update` datetime NOT NULL,
  `company_name` varchar(128) NOT NULL,
  PRIMARY KEY (`ibfs_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `itu_filings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `itu_filings` (
  `itu_item` int(11) NOT NULL AUTO_INCREMENT,
  `ntd_id` varchar(16) DEFAULT NULL,
  `submission_id` int(11) NOT NULL,
  `submission_type` varchar(128) DEFAULT NULL,
  `org_name` varchar(24) DEFAULT NULL,
  `station_name` varchar(128) NOT NULL,
  `longitude` varchar(32) NOT NULL,
  `br_reg_date` date NOT NULL,
  `post_text` text DEFAULT NULL,
  `posted` tinyint(1) NOT NULL DEFAULT 1,
  `post_attempts` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`itu_item`),
  UNIQUE KEY `uniq_submission` (`submission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `itu_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `itu_members` (
  `member_id` int(11) NOT NULL AUTO_INCREMENT,
  `symbol` varchar(32) NOT NULL,
  `designation` varchar(64) NOT NULL,
  `region` varchar(8) NOT NULL,
  PRIMARY KEY (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nasa_procurement`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nasa_procurement` (
  `procurement_id` int(11) NOT NULL AUTO_INCREMENT,
  `procurement_title` varchar(128) DEFAULT NULL,
  `procurement_url` varchar(128) DEFAULT NULL,
  `added_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`procurement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `status` (
  `status_data` varchar(32) NOT NULL,
  `status_item` varchar(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

