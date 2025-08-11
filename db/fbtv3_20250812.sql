-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:8889
-- Generation Time: Aug 11, 2025 at 05:06 PM
-- Server version: 8.0.40
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `fbtv3`
--
CREATE DATABASE IF NOT EXISTS `fbtv3` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `fbtv3`;

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

DROP TABLE IF EXISTS `admin_users`;
CREATE TABLE `admin_users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role_id` int DEFAULT '2',
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `status` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admin_users`
--

INSERT INTO `admin_users` (`id`, `username`, `password`, `email`, `role_id`, `created`, `updated`, `status`) VALUES
(1, 'Individual57', '$2y$10$kgzs5JI7uP36qnEEjUJTfeyfulqLtVp8sTJTLmIG0RcGko2mYM5rC', 'individual57@gmail.com', 1, '2025-08-01 11:32:28', '2025-08-10 11:40:33', 1),
(2, 'Tester', '$2y$10$xfEcfX3aKcekIYPqoMuyVO1/QT5dy7HoBLCK.XVgcdoy5Qa5bWD5C', 'tester@gmail.com', 2, '2025-08-01 11:37:04', '2025-08-11 17:02:30', 1);

-- --------------------------------------------------------

--
-- Table structure for table `default_text`
--

DROP TABLE IF EXISTS `default_text`;
CREATE TABLE `default_text` (
  `id` int NOT NULL,
  `key_name` varchar(255) NOT NULL,
  `translations` json DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deletion_log`
--

DROP TABLE IF EXISTS `deletion_log`;
CREATE TABLE `deletion_log` (
  `id` int NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `record_id` int NOT NULL,
  `record_name` varchar(255) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_by` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dhis2_instances`
--

DROP TABLE IF EXISTS `dhis2_instances`;
CREATE TABLE `dhis2_instances` (
  `id` int NOT NULL,
  `url` text NOT NULL,
  `username` text NOT NULL,
  `password` text NOT NULL,
  `instance_key` varchar(255) NOT NULL,
  `description` text,
  `status` int DEFAULT '1',
  `created` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `dhis2_instances`
--

INSERT INTO `dhis2_instances` (`id`, `url`, `username`, `password`, `instance_key`, `description`, `status`, `created`) VALUES
(1, 'https://tests.dhis2.hispuganda.org/hmis/', 'hisp.edwin', 'RWR3MW5AMjAyNQ==', 'UiO', 'Development instance for DHIS2', 1, '2025-05-14 14:41:40'),
(2, 'https://hmis.moh.go.ug', 'admin', 'Vm0wd2QyUXlVWGxXYTJoV1YwZG9WbFl3WkZOVU1WcHpXa2M1VjFKc2JETlhhMUpUVmpKS1IySkVUbGhoTVVwVVZqQmFTMlJIVmtWUmJVWlhWbXhzTTFadGNFSmxSbVJJVm10a1dHSkdjRTlaYlhSTFZsWmFkR05GWkZwV01VcEpWbTEwVjFWdFNsWlhiRkpYWWxob2VsUlVSbUZqVms1eFZXeHdWMDFFUlRCV2EyTXhWREZrU0ZOclpHcFRSVXBZVkZWYWQxUkdiSEZTYlVacVRWWndNRlZ0ZUd0VWJGcDFVV3hvVjFKc2NGaFdha3BIVTBaYWRWSnNTbGRTTTAwMQ==', 'eHMIS', 'National HMIS system', 1, '2025-05-14 14:41:40'),
(3, 'https://epivac.moh.go.ug', 'admin', 'VmpKMGEyTXlWblJWYTJ4VVltdEtZVlJVUmtaUFVUMDk=', 'EPIVAC', 'National Immunization Registry', 0, '2025-05-14 14:41:40'),
(4, 'https://emisuganda.org/emis', 'hisp.medwin', 'RW1pc0AyMDI1', 'EMIS', 'Emis System', 1, '2025-05-14 14:41:40');

-- --------------------------------------------------------

--
-- Table structure for table `dhis2_option_set_mapping`
--

DROP TABLE IF EXISTS `dhis2_option_set_mapping`;
CREATE TABLE `dhis2_option_set_mapping` (
  `id` int NOT NULL,
  `local_value` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Value in our system',
  `dhis2_option_code` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL COMMENT 'Code in DHIS2 option set',
  `dhis2_option_set_id` varchar(11) NOT NULL COMMENT 'DHIS2 option set UID',
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `survey_id` int DEFAULT NULL COMMENT 'Survey that created this mapping'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Triggers `dhis2_option_set_mapping`
--
DROP TRIGGER IF EXISTS `trim_dhis2_option_mapping_insert`;
DELIMITER $$
CREATE TRIGGER `trim_dhis2_option_mapping_insert` BEFORE INSERT ON `dhis2_option_set_mapping` FOR EACH ROW BEGIN
                SET NEW.local_value = TRIM(NEW.local_value);
                SET NEW.dhis2_option_code = TRIM(NEW.dhis2_option_code);
            END
$$
DELIMITER ;
DROP TRIGGER IF EXISTS `trim_dhis2_option_mapping_update`;
DELIMITER $$
CREATE TRIGGER `trim_dhis2_option_mapping_update` BEFORE UPDATE ON `dhis2_option_set_mapping` FOR EACH ROW BEGIN
                SET NEW.local_value = TRIM(NEW.local_value);
                SET NEW.dhis2_option_code = TRIM(NEW.dhis2_option_code);
            END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `dhis2_submission_log`
--

DROP TABLE IF EXISTS `dhis2_submission_log`;
CREATE TABLE `dhis2_submission_log` (
  `id` int NOT NULL,
  `submission_id` int NOT NULL,
  `status` enum('SUCCESS','FAILED') NOT NULL,
  `submitted_at` datetime NOT NULL,
  `payload_sent` json DEFAULT NULL COMMENT 'JSON payload sent to DHIS2',
  `dhis2_response` json DEFAULT NULL COMMENT 'JSON response received from DHIS2',
  `dhis2_message` text COMMENT 'Summary message from DHIS2 response or internal error',
  `retries` int DEFAULT '0' COMMENT 'Number of times submission was retried'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `dhis2_submission_log`
--

INSERT INTO `dhis2_submission_log` (`id`, `submission_id`, `status`, `submitted_at`, `payload_sent`, `dhis2_response`, `dhis2_message`, `retries`) VALUES
(1, 1, 'SUCCESS', '2025-08-11 16:58:27', '{\"events\": {\"aHufHcZAnm5_1\": {\"eventDate\": \"2025-08-11\", \"dataValues\": {\"ahf2oYbp4wQ\": \"true\", \"lID4wrE2yVu\": \"3\", \"uEEIHjPYMGO\": \"1\"}, \"programStage\": \"aHufHcZAnm5\"}, \"pkSb9eGI2qY_1\": {\"eventDate\": \"2025-08-11\", \"dataValues\": {\"Zw49RKyXYRj\": \"true\", \"ebmdvu4hMqa\": \"African Women Rising\", \"fkipjGtgOHg\": \"C:\\\\fakepath\\\\Geneva Global.xlsx\", \"jwJogFZa78i\": \"2014-2022\"}, \"programStage\": \"pkSb9eGI2qY\"}, \"pkSb9eGI2qY_2\": {\"eventDate\": \"2025-08-11\", \"dataValues\": {\"Zw49RKyXYRj\": \"false\", \"ebmdvu4hMqa\": \"Aga Khan Foundation\", \"fkipjGtgOHg\": \"C:\\\\fakepath\\\\World Vision Schools.xlsx\", \"jwJogFZa78i\": \"2015-2016\"}, \"programStage\": \"pkSb9eGI2qY\"}}, \"trackedEntityAttributes\": {\"Brip9mXEPEK\": \"+256779337444\", \"KhpZKRtUL6W\": \"Page one\", \"mfVkIGKYTzq\": \"+256770114556\"}}', '{\"status\": \"SUCCESS\", \"tei_id\": \"Id7f77f2251\", \"enrollment_id\": \"Jf0f22f7667\", \"tracker_response\": {\"stats\": {\"total\": 5, \"created\": 5, \"deleted\": 0, \"ignored\": 0, \"updated\": 0}, \"status\": \"OK\", \"bundleReport\": {\"typeReportMap\": {\"EVENT\": {\"stats\": {\"total\": 3, \"created\": 3, \"deleted\": 0, \"ignored\": 0, \"updated\": 0}, \"trackerType\": \"EVENT\", \"objectReports\": [{\"uid\": \"na25903a4bd\", \"index\": 0, \"trackerType\": \"EVENT\", \"errorReports\": []}, {\"uid\": \"W93a2240330\", \"index\": 1, \"trackerType\": \"EVENT\", \"errorReports\": []}, {\"uid\": \"jc012292324\", \"index\": 2, \"trackerType\": \"EVENT\", \"errorReports\": []}]}, \"ENROLLMENT\": {\"stats\": {\"total\": 1, \"created\": 1, \"deleted\": 0, \"ignored\": 0, \"updated\": 0}, \"trackerType\": \"ENROLLMENT\", \"objectReports\": [{\"uid\": \"Jf0f22f7667\", \"index\": 0, \"trackerType\": \"ENROLLMENT\", \"errorReports\": []}]}, \"RELATIONSHIP\": {\"stats\": {\"total\": 0, \"created\": 0, \"deleted\": 0, \"ignored\": 0, \"updated\": 0}, \"trackerType\": \"RELATIONSHIP\", \"objectReports\": []}, \"TRACKED_ENTITY\": {\"stats\": {\"total\": 1, \"created\": 1, \"deleted\": 0, \"ignored\": 0, \"updated\": 0}, \"trackerType\": \"TRACKED_ENTITY\", \"objectReports\": [{\"uid\": \"Id7f77f2251\", \"index\": 0, \"trackerType\": \"TRACKED_ENTITY\", \"errorReports\": []}]}}}, \"validationReport\": {\"errorReports\": [], \"warningReports\": []}}}', 'Tracker submission successful', 0);

-- --------------------------------------------------------

--
-- Table structure for table `dhis2_system_field_mapping`
--

DROP TABLE IF EXISTS `dhis2_system_field_mapping`;
CREATE TABLE `dhis2_system_field_mapping` (
  `id` int NOT NULL,
  `field_name` varchar(50) NOT NULL COMMENT 'Local field name (age, sex, etc)',
  `dhis2_dataelement_id` varchar(11) NOT NULL COMMENT 'DHIS2 UID for the data element',
  `dhis2_option_set_id` varchar(11) DEFAULT NULL COMMENT 'DHIS2 option set UID if applicable',
  `description` varchar(255) DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `dhis2_attribute_id` varchar(11) DEFAULT NULL COMMENT 'DHIS2 Tracked Entity Attribute UID for system fields',
  `survey_id` int DEFAULT NULL COMMENT 'Survey that created this mapping'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `location`
--

DROP TABLE IF EXISTS `location`;
CREATE TABLE `location` (
  `id` bigint NOT NULL,
  `instance_key` varchar(64) NOT NULL,
  `uid` varchar(11) NOT NULL,
  `name` varchar(128) NOT NULL,
  `path` varchar(128) NOT NULL,
  `hierarchylevel` int NOT NULL,
  `parent_id` bigint DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `option_set`
--

DROP TABLE IF EXISTS `option_set`;
CREATE TABLE `option_set` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `option_set_values`
--

DROP TABLE IF EXISTS `option_set_values`;
CREATE TABLE `option_set_values` (
  `id` int NOT NULL,
  `option_set_id` int NOT NULL,
  `option_value` varchar(255) NOT NULL,
  `translations` json DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
CREATE TABLE `permissions` (
  `id` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `description` text,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `display_name`, `category`, `description`, `created`) VALUES
(1, 'user_create', 'Create Users', 'user_management', 'Create new administrator accounts', '2025-08-01 11:20:51'),
(2, 'user_read', 'View Users', 'user_management', 'View list of users and their details', '2025-08-01 11:20:51'),
(3, 'user_update', 'Update Users', 'user_management', 'Edit user information and status', '2025-08-01 11:20:51'),
(4, 'user_delete', 'Delete Users', 'user_management', 'Delete user accounts', '2025-08-01 11:20:51'),
(5, 'user_role_assign', 'Assign Roles', 'user_management', 'Assign roles to other users', '2025-08-01 11:20:51'),
(6, 'survey_create', 'Create Surveys', 'survey_management', 'Create new surveys', '2025-08-01 11:20:51'),
(7, 'survey_read', 'View Surveys', 'survey_management', 'View surveys and responses', '2025-08-01 11:20:51'),
(8, 'survey_update', 'Update Surveys', 'survey_management', 'Edit existing surveys', '2025-08-01 11:20:51'),
(9, 'survey_delete', 'Delete Surveys', 'survey_management', 'Delete surveys and their data', '2025-08-01 11:20:51'),
(10, 'survey_deploy', 'Deploy Surveys', 'survey_management', 'Deploy surveys to public', '2025-08-01 11:20:51'),
(11, 'question_create', 'Create Questions', 'question_management', 'Create new questions', '2025-08-01 11:20:51'),
(12, 'question_read', 'View Questions', 'question_management', 'View question bank', '2025-08-01 11:20:51'),
(13, 'question_update', 'Update Questions', 'question_management', 'Edit existing questions', '2025-08-01 11:20:51'),
(14, 'question_delete', 'Delete Questions', 'question_management', 'Delete questions', '2025-08-01 11:20:51'),
(15, 'config_dhis2', 'DHIS2 Configuration', 'system_config', 'Configure DHIS2 integration settings', '2025-08-01 11:20:51'),
(16, 'config_system', 'System Settings', 'system_config', 'Access system configuration and settings', '2025-08-01 11:20:51'),
(17, 'payload_checker', 'Payload Checker', 'system_config', 'Access DHIS2 payload checker tool', '2025-08-01 11:20:51'),
(18, 'data_export', 'Export Data', 'data_management', 'Export survey data and reports', '2025-08-01 11:20:51'),
(19, 'data_import', 'Import Data', 'data_management', 'Import data into the system', '2025-08-01 11:20:51'),
(20, 'data_delete', 'Delete Data', 'data_management', 'Delete survey responses and data', '2025-08-01 11:20:51');

-- --------------------------------------------------------

--
-- Table structure for table `question`
--

DROP TABLE IF EXISTS `question`;
CREATE TABLE `question` (
  `id` int NOT NULL,
  `label` varchar(255) NOT NULL,
  `question_type` enum('text','textarea','radio','checkbox','select','rating','number','integer','decimal','percentage','date','datetime','time','year','month','email','phone','url','national_id','country','region','city','postal_code','currency','file_upload','signature','coordinates','color','likert_scale','net_promoter_score','star_rating') DEFAULT NULL,
  `is_required` tinyint(1) DEFAULT '0',
  `translations` json DEFAULT NULL,
  `option_set_id` int DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `validation_rules` json DEFAULT NULL,
  `skip_logic` json DEFAULT NULL,
  `min_selections` int DEFAULT NULL,
  `max_selections` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `question_dhis2_mapping`
--

DROP TABLE IF EXISTS `question_dhis2_mapping`;
CREATE TABLE `question_dhis2_mapping` (
  `id` int NOT NULL,
  `question_id` int NOT NULL,
  `dhis2_dataelement_id` varchar(20) DEFAULT NULL COMMENT 'DHIS2 Data Element UID or special identifier if question maps to a DE',
  `dhis2_option_set_id` varchar(11) DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `dhis2_attribute_id` varchar(11) DEFAULT NULL COMMENT 'DHIS2 Tracked Entity Attribute UID if question maps to a TEA',
  `dhis2_program_stage_id` varchar(11) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'DHIS2 Program Stage UID for data elements'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
CREATE TABLE `role_permissions` (
  `id` int NOT NULL,
  `role_id` int NOT NULL,
  `permission_id` int NOT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created`) VALUES
(1, 1, 15, '2025-08-01 11:20:51'),
(2, 1, 16, '2025-08-01 11:20:51'),
(3, 1, 20, '2025-08-01 11:20:51'),
(4, 1, 18, '2025-08-01 11:20:51'),
(5, 1, 19, '2025-08-01 11:20:51'),
(6, 1, 17, '2025-08-01 11:20:51'),
(7, 1, 11, '2025-08-01 11:20:51'),
(8, 1, 14, '2025-08-01 11:20:51'),
(9, 1, 12, '2025-08-01 11:20:51'),
(10, 1, 13, '2025-08-01 11:20:51'),
(11, 1, 6, '2025-08-01 11:20:51'),
(12, 1, 9, '2025-08-01 11:20:51'),
(13, 1, 10, '2025-08-01 11:20:51'),
(14, 1, 7, '2025-08-01 11:20:51'),
(15, 1, 8, '2025-08-01 11:20:51'),
(16, 1, 1, '2025-08-01 11:20:51'),
(17, 1, 4, '2025-08-01 11:20:51'),
(18, 1, 2, '2025-08-01 11:20:51'),
(19, 1, 5, '2025-08-01 11:20:51'),
(20, 1, 3, '2025-08-01 11:20:51'),
(32, 2, 15, '2025-08-01 11:20:51'),
(33, 2, 16, '2025-08-01 11:20:51'),
(34, 2, 20, '2025-08-01 11:20:51'),
(35, 2, 18, '2025-08-01 11:20:51'),
(36, 2, 19, '2025-08-01 11:20:51'),
(37, 2, 17, '2025-08-01 11:20:51'),
(38, 2, 11, '2025-08-01 11:20:51'),
(39, 2, 14, '2025-08-01 11:20:51'),
(40, 2, 12, '2025-08-01 11:20:51'),
(41, 2, 13, '2025-08-01 11:20:51'),
(42, 2, 6, '2025-08-01 11:20:51'),
(43, 2, 9, '2025-08-01 11:20:51'),
(44, 2, 10, '2025-08-01 11:20:51'),
(45, 2, 7, '2025-08-01 11:20:51'),
(46, 2, 8, '2025-08-01 11:20:51'),
(47, 2, 2, '2025-08-01 11:20:51'),
(48, 2, 3, '2025-08-01 11:20:51'),
(63, 3, 6, '2025-08-01 11:20:51'),
(64, 3, 7, '2025-08-01 11:20:51'),
(65, 3, 8, '2025-08-01 11:20:51'),
(66, 3, 10, '2025-08-01 11:20:51'),
(67, 3, 11, '2025-08-01 11:20:51'),
(68, 3, 12, '2025-08-01 11:20:51'),
(69, 3, 13, '2025-08-01 11:20:51'),
(70, 3, 18, '2025-08-01 11:20:51'),
(71, 3, 19, '2025-08-01 11:20:51'),
(78, 4, 12, '2025-08-01 11:20:51'),
(79, 4, 7, '2025-08-01 11:20:51'),
(80, 4, 2, '2025-08-01 11:20:51');

-- --------------------------------------------------------

--
-- Table structure for table `stage_questions`
--

DROP TABLE IF EXISTS `stage_questions`;
CREATE TABLE `stage_questions` (
  `id` int NOT NULL,
  `stage_id` int NOT NULL,
  `question_id` int NOT NULL,
  `question_order` int NOT NULL DEFAULT '1',
  `is_required` tinyint(1) DEFAULT '1',
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submission`
--

DROP TABLE IF EXISTS `submission`;
CREATE TABLE `submission` (
  `id` int NOT NULL,
  `uid` varchar(11) NOT NULL,
  `location_id` bigint DEFAULT NULL,
  `survey_id` int DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `tracked_entity_instance` varchar(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `submission_response`
--

DROP TABLE IF EXISTS `submission_response`;
CREATE TABLE `submission_response` (
  `id` int NOT NULL,
  `submission_id` int NOT NULL,
  `question_id` int NOT NULL,
  `response_value` text NOT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `stage_occurrence_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `survey`
--

DROP TABLE IF EXISTS `survey`;
CREATE TABLE `survey` (
  `id` int NOT NULL,
  `name` varchar(250) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `type` enum('local','dhis2') NOT NULL DEFAULT 'local',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `dhis2_instance` varchar(50) DEFAULT NULL,
  `program_dataset` varchar(50) DEFAULT NULL,
  `dhis2_tracked_entity_type_uid` varchar(11) DEFAULT NULL COMMENT 'Stores the DHIS2 Tracked Entity Type UID for tracker programs',
  `dhis2_program_uid` varchar(11) DEFAULT NULL,
  `tracked_entity_type` varchar(11) DEFAULT NULL,
  `program_type` enum('local','event','tracker','aggregate') DEFAULT 'local',
  `domain_type` varchar(100) DEFAULT NULL COMMENT 'DHIS2 domain type'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Triggers `survey`
--
DROP TRIGGER IF EXISTS `set_default_dates_and_program_dataset`;
DELIMITER $$
CREATE TRIGGER `set_default_dates_and_program_dataset` BEFORE INSERT ON `survey` FOR EACH ROW BEGIN
   
    -- Set default start_date for 'dhis2' type to the current date
    IF NEW.type = 'dhis2' AND NEW.start_date IS NULL THEN
        SET NEW.start_date = CURDATE();
    END IF;

    -- Set default end_date to 1 year after start_date for all types
    IF NEW.start_date IS NOT NULL AND NEW.end_date IS NULL THEN
        SET NEW.end_date = DATE_ADD(NEW.start_date, INTERVAL 1 YEAR);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `survey_dependencies`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `survey_dependencies`;
CREATE TABLE `survey_dependencies` (
`dhis2_log_count` bigint
,`dhis2_mapping_count` bigint
,`option_mapping_count` bigint
,`question_count` bigint
,`submission_count` bigint
,`survey_id` int
,`survey_name` varchar(250)
,`survey_type` enum('local','dhis2')
);

-- --------------------------------------------------------

--
-- Table structure for table `survey_question`
--

DROP TABLE IF EXISTS `survey_question`;
CREATE TABLE `survey_question` (
  `survey_id` int NOT NULL,
  `question_id` int NOT NULL,
  `position` int DEFAULT NULL,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `id` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `survey_settings`
--

DROP TABLE IF EXISTS `survey_settings`;
CREATE TABLE `survey_settings` (
  `id` int NOT NULL,
  `survey_id` int NOT NULL,
  `logo_path` varchar(255) DEFAULT 'asets/asets/img/loog.jpg',
  `show_logo` tinyint(1) DEFAULT '1',
  `flag_black_color` varchar(7) DEFAULT '#000000',
  `flag_yellow_color` varchar(7) DEFAULT '#FCD116',
  `flag_red_color` varchar(7) DEFAULT '#D21034',
  `show_flag_bar` tinyint(1) DEFAULT '1',
  `title_text` varchar(255) DEFAULT 'Ministry of Health Client Satisfaction Feedback Tool',
  `show_title` tinyint(1) DEFAULT '1',
  `subheading_text` varchar(1000) DEFAULT 'This tool is used to obtain clients'' feedback about their experience with the services and promote quality improvement, accountability, and transparency within the healthcare system.',
  `show_subheading` tinyint(1) DEFAULT '1',
  `show_submit_button` tinyint(1) DEFAULT '1',
  `rating_instruction1_text` varchar(500) DEFAULT '1. Please rate each of the following parameters according to your experience today on a scale of 1 to 4.',
  `rating_instruction2_text` varchar(500) DEFAULT 'where ''0'' means Poor, ''1'' Fair, ''2'' Good and ''3'' Excellent',
  `show_rating_instructions` tinyint(1) DEFAULT '1',
  `show_facility_section` tinyint(1) DEFAULT '1',
  `republic_title_text` varchar(255) DEFAULT 'THE REPUBLIC OF UGANDA',
  `show_republic_title_share` tinyint(1) DEFAULT '1',
  `ministry_subtitle_text` varchar(255) DEFAULT 'MINISTRY OF HEALTH',
  `show_ministry_subtitle_share` tinyint(1) DEFAULT '1',
  `qr_instructions_text` varchar(1000) DEFAULT 'Scan this QR Code to Give Your Feedback on Services Received',
  `show_qr_instructions_share` tinyint(1) DEFAULT '1',
  `footer_note_text` varchar(500) DEFAULT 'Thank you for helping us improve our services.',
  `show_footer_note_share` tinyint(1) DEFAULT '1',
  `selected_instance_key` varchar(64) DEFAULT NULL,
  `selected_hierarchy_level` int DEFAULT NULL,
  `show_numbering` tinyint(1) DEFAULT '1' COMMENT 'Whether to show question numbers',
  `numbering_style` varchar(20) DEFAULT 'numeric' COMMENT 'Style of numbering: numeric, \r\n  alphabetic_lower, alphabetic_upper, roman_lower, roman_upper, none'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `survey_stages`
--

DROP TABLE IF EXISTS `survey_stages`;
CREATE TABLE `survey_stages` (
  `id` int NOT NULL,
  `survey_id` int NOT NULL,
  `stage_name` varchar(255) NOT NULL,
  `stage_description` text,
  `dhis2_program_stage_id` varchar(11) NOT NULL,
  `stage_order` int NOT NULL DEFAULT '1',
  `is_repeatable` tinyint(1) DEFAULT '0',
  `min_occurrences` int DEFAULT '1',
  `max_occurrences` int DEFAULT '1',
  `stage_color` varchar(7) DEFAULT '#667eea',
  `is_active` tinyint(1) DEFAULT '1',
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracker_groupings`
--

DROP TABLE IF EXISTS `tracker_groupings`;
CREATE TABLE `tracker_groupings` (
  `id` int NOT NULL,
  `survey_id` int NOT NULL,
  `stage_id` varchar(255) NOT NULL,
  `group_title` varchar(255) NOT NULL,
  `questions` json NOT NULL,
  `group_order` int DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracker_submissions`
--

DROP TABLE IF EXISTS `tracker_submissions`;
CREATE TABLE `tracker_submissions` (
  `id` int NOT NULL,
  `uid` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `survey_id` int NOT NULL,
  `tracked_entity_instance` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `selected_facility_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `selected_facility_name` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `selected_orgunit_uid` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `form_data` json DEFAULT NULL,
  `dhis2_response` json DEFAULT NULL,
  `submission_status` enum('submitted','failed') COLLATE utf8mb4_unicode_ci DEFAULT 'submitted',
  `submitted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_session_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_log`
--

DROP TABLE IF EXISTS `user_activity_log`;
CREATE TABLE `user_activity_log` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int DEFAULT NULL,
  `details` json DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user_activity_log`
--

INSERT INTO `user_activity_log` (`id`, `user_id`, `action`, `target_type`, `target_id`, `details`, `ip_address`, `user_agent`, `created`) VALUES
(1, 1, 'user_created', 'admin_users', 2, '{\"email\": \"tester@gmail.com\", \"role_id\": 4, \"username\": \"Tester\"}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-01 11:37:04'),
(2, 1, 'user_role_changed', 'admin_users', 2, '{\"new_role_id\": 4}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-03 14:35:17'),
(3, 1, 'user_role_changed', 'admin_users', 2, '{\"new_role_id\": 4}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-03 14:35:23'),
(4, 1, 'user_role_changed', 'admin_users', 2, '{\"new_role_id\": 4}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-03 14:35:26'),
(5, 1, 'user_role_changed', 'admin_users', 2, '{\"new_role_id\": 4}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-03 14:35:27'),
(6, 1, 'user_status_changed', 'admin_users', 2, '{\"action\": \"deactivated\", \"new_status\": 0}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-03 14:35:31'),
(7, 1, 'user_role_changed', 'admin_users', 2, '{\"new_role_id\": 4}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-03 14:35:34'),
(8, 1, 'user_status_changed', 'admin_users', 2, '{\"action\": \"activated\", \"new_status\": 1}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-03 14:36:29'),
(9, 1, 'user_status_changed', 'admin_users', 2, '{\"action\": \"deactivated\", \"new_status\": 0}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-04 08:28:08'),
(10, 1, 'user_role_changed', 'admin_users', 2, '{\"new_role_id\": 4}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-04 08:28:16'),
(11, 1, 'user_role_changed', 'admin_users', 2, '{\"new_role_id\": 4}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-04 10:24:33'),
(12, 1, 'user_role_changed', 'admin_users', 2, '{\"new_role_id\": 3}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-04 10:24:41'),
(13, 1, 'user_status_changed', 'admin_users', 2, '{\"action\": \"activated\", \"new_status\": 1}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36', '2025-08-04 10:24:43'),
(14, 1, 'user_role_changed', 'admin_users', 2, '{\"new_role_id\": 2}', '::1', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '2025-08-08 12:50:28');

-- --------------------------------------------------------

--
-- Stand-in structure for view `user_permissions_view`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `user_permissions_view`;
CREATE TABLE `user_permissions_view` (
`email` varchar(100)
,`permission_category` varchar(50)
,`permission_display_name` varchar(100)
,`permission_name` varchar(50)
,`role_display_name` varchar(100)
,`role_name` varchar(50)
,`user_id` int
,`username` varchar(50)
);

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

DROP TABLE IF EXISTS `user_roles`;
CREATE TABLE `user_roles` (
  `id` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `description` text,
  `created` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`id`, `name`, `display_name`, `description`, `created`, `updated`) VALUES
(1, 'super_admin', 'Super Administrator', 'Has full access to all system features including user management, system configuration, and all survey operations', '2025-08-01 11:20:51', '2025-08-01 11:20:51'),
(2, 'admin', 'Administrator', 'Can manage surveys, view reports, and perform most administrative tasks but cannot manage other users', '2025-08-01 11:20:51', '2025-08-01 11:20:51'),
(3, 'editor', 'Editor', 'Can create and edit surveys, manage questions, but cannot delete surveys or access system settings', '2025-08-01 11:20:51', '2025-08-01 11:20:51'),
(4, 'viewer', 'Viewer', 'Can only view surveys and reports, no editing or administrative capabilities', '2025-08-01 11:20:51', '2025-08-01 11:20:51');

-- --------------------------------------------------------

--
-- Structure for view `survey_dependencies`
--
DROP TABLE IF EXISTS `survey_dependencies`;

DROP VIEW IF EXISTS `survey_dependencies`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `survey_dependencies`  AS SELECT `s`.`id` AS `survey_id`, `s`.`name` AS `survey_name`, `s`.`type` AS `survey_type`, count(distinct `sq`.`question_id`) AS `question_count`, count(distinct `qm`.`id`) AS `dhis2_mapping_count`, count(distinct `sub`.`id`) AS `submission_count`, count(distinct `dsl`.`id`) AS `dhis2_log_count`, count(distinct `osm`.`id`) AS `option_mapping_count` FROM (((((`survey` `s` left join `survey_question` `sq` on((`s`.`id` = `sq`.`survey_id`))) left join `question_dhis2_mapping` `qm` on((`sq`.`question_id` = `qm`.`question_id`))) left join `submission` `sub` on((`s`.`id` = `sub`.`survey_id`))) left join `dhis2_submission_log` `dsl` on((`sub`.`id` = `dsl`.`submission_id`))) left join `dhis2_option_set_mapping` `osm` on((`s`.`id` = `osm`.`survey_id`))) GROUP BY `s`.`id`, `s`.`name`, `s`.`type` ;

-- --------------------------------------------------------

--
-- Structure for view `user_permissions_view`
--
DROP TABLE IF EXISTS `user_permissions_view`;

DROP VIEW IF EXISTS `user_permissions_view`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `user_permissions_view`  AS SELECT `au`.`id` AS `user_id`, `au`.`username` AS `username`, `au`.`email` AS `email`, `ur`.`name` AS `role_name`, `ur`.`display_name` AS `role_display_name`, `p`.`name` AS `permission_name`, `p`.`display_name` AS `permission_display_name`, `p`.`category` AS `permission_category` FROM (((`admin_users` `au` left join `user_roles` `ur` on((`au`.`role_id` = `ur`.`id`))) left join `role_permissions` `rp` on((`ur`.`id` = `rp`.`role_id`))) left join `permissions` `p` on((`rp`.`permission_id` = `p`.`id`))) WHERE (`au`.`status` = 1) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_admin_users_role_id` (`role_id`);

--
-- Indexes for table `default_text`
--
ALTER TABLE `default_text`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key_name` (`key_name`);

--
-- Indexes for table `deletion_log`
--
ALTER TABLE `deletion_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_table_record` (`table_name`,`record_id`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- Indexes for table `dhis2_instances`
--
ALTER TABLE `dhis2_instances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`instance_key`);

--
-- Indexes for table `dhis2_option_set_mapping`
--
ALTER TABLE `dhis2_option_set_mapping`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `local_value` (`local_value`,`dhis2_option_set_id`),
  ADD KEY `idx_survey_id_mapping` (`survey_id`);

--
-- Indexes for table `dhis2_submission_log`
--
ALTER TABLE `dhis2_submission_log`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `submission_id` (`submission_id`),
  ADD KEY `idx_submission_id_log` (`submission_id`);

--
-- Indexes for table `dhis2_system_field_mapping`
--
ALTER TABLE `dhis2_system_field_mapping`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `field_name` (`field_name`),
  ADD KEY `dhis2_system_field_mapping_survey_fk` (`survey_id`);

--
-- Indexes for table `location`
--
ALTER TABLE `location`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `option_set`
--
ALTER TABLE `option_set`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_name` (`name`);

--
-- Indexes for table `option_set_values`
--
ALTER TABLE `option_set_values`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_option_set_value` (`option_set_id`,`option_value`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `question`
--
ALTER TABLE `question`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_question_label` (`label`),
  ADD KEY `idx_question_type` (`question_type`),
  ADD KEY `idx_question_type_required` (`question_type`,`is_required`),
  ADD KEY `idx_question_created` (`created`),
  ADD KEY `idx_question_updated` (`updated`),
  ADD KEY `option_set_id` (`option_set_id`);

--
-- Indexes for table `question_dhis2_mapping`
--
ALTER TABLE `question_dhis2_mapping`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `question_id` (`question_id`,`dhis2_dataelement_id`),
  ADD KEY `idx_question_id_dhis2` (`question_id`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_role_permission` (`role_id`,`permission_id`),
  ADD KEY `permission_id` (`permission_id`);

--
-- Indexes for table `stage_questions`
--
ALTER TABLE `stage_questions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_stage_question` (`stage_id`,`question_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `submission`
--
ALTER TABLE `submission`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_ibfk_7` (`survey_id`),
  ADD KEY `location_id` (`location_id`),
  ADD KEY `idx_survey_id_sub` (`survey_id`);

--
-- Indexes for table `submission_response`
--
ALTER TABLE `submission_response`
  ADD PRIMARY KEY (`id`),
  ADD KEY `submission_id` (`submission_id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `survey`
--
ALTER TABLE `survey`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `survey_question`
--
ALTER TABLE `survey_question`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_survey_question` (`survey_id`,`question_id`),
  ADD KEY `fk_question_id` (`question_id`),
  ADD KEY `idx_survey_id` (`survey_id`);

--
-- Indexes for table `survey_settings`
--
ALTER TABLE `survey_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `survey_id` (`survey_id`),
  ADD KEY `idx_survey_id_settings` (`survey_id`);

--
-- Indexes for table `survey_stages`
--
ALTER TABLE `survey_stages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_survey_stage_order` (`survey_id`,`stage_order`),
  ADD KEY `idx_survey_stages_survey_id` (`survey_id`);

--
-- Indexes for table `tracker_groupings`
--
ALTER TABLE `tracker_groupings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_survey_stage` (`survey_id`,`stage_id`);

--
-- Indexes for table `tracker_submissions`
--
ALTER TABLE `tracker_submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uid` (`uid`),
  ADD KEY `idx_survey_id` (`survey_id`),
  ADD KEY `idx_tei` (`tracked_entity_instance`),
  ADD KEY `idx_facility` (`selected_facility_id`),
  ADD KEY `idx_status` (`submission_status`),
  ADD KEY `idx_uid` (`uid`);

--
-- Indexes for table `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_action` (`user_id`,`action`),
  ADD KEY `idx_created` (`created`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `default_text`
--
ALTER TABLE `default_text`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deletion_log`
--
ALTER TABLE `deletion_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dhis2_instances`
--
ALTER TABLE `dhis2_instances`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `dhis2_option_set_mapping`
--
ALTER TABLE `dhis2_option_set_mapping`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dhis2_submission_log`
--
ALTER TABLE `dhis2_submission_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `dhis2_system_field_mapping`
--
ALTER TABLE `dhis2_system_field_mapping`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `location`
--
ALTER TABLE `location`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `option_set`
--
ALTER TABLE `option_set`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `option_set_values`
--
ALTER TABLE `option_set_values`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `question`
--
ALTER TABLE `question`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `question_dhis2_mapping`
--
ALTER TABLE `question_dhis2_mapping`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `stage_questions`
--
ALTER TABLE `stage_questions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `submission`
--
ALTER TABLE `submission`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `submission_response`
--
ALTER TABLE `submission_response`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `survey`
--
ALTER TABLE `survey`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `survey_question`
--
ALTER TABLE `survey_question`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `survey_settings`
--
ALTER TABLE `survey_settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT for table `survey_stages`
--
ALTER TABLE `survey_stages`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tracker_groupings`
--
ALTER TABLE `tracker_groupings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `tracker_submissions`
--
ALTER TABLE `tracker_submissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_activity_log`
--
ALTER TABLE `user_activity_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `user_roles`
--
ALTER TABLE `user_roles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD CONSTRAINT `fk_admin_users_role_id` FOREIGN KEY (`role_id`) REFERENCES `user_roles` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `dhis2_option_set_mapping`
--
ALTER TABLE `dhis2_option_set_mapping`
  ADD CONSTRAINT `dhis2_option_set_mapping_survey_fk` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `dhis2_system_field_mapping`
--
ALTER TABLE `dhis2_system_field_mapping`
  ADD CONSTRAINT `dhis2_system_field_mapping_survey_fk` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `option_set_values`
--
ALTER TABLE `option_set_values`
  ADD CONSTRAINT `fk_option_set_id` FOREIGN KEY (`option_set_id`) REFERENCES `option_set` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `question`
--
ALTER TABLE `question`
  ADD CONSTRAINT `question_ibfk_1` FOREIGN KEY (`option_set_id`) REFERENCES `option_set` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `question_dhis2_mapping`
--
ALTER TABLE `question_dhis2_mapping`
  ADD CONSTRAINT `question_dhis2_mapping_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `question` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `user_roles` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stage_questions`
--
ALTER TABLE `stage_questions`
  ADD CONSTRAINT `stage_questions_ibfk_1` FOREIGN KEY (`stage_id`) REFERENCES `survey_stages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `stage_questions_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `question` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `submission`
--
ALTER TABLE `submission`
  ADD CONSTRAINT `submission_ibfk_7` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `submission_ibfk_8` FOREIGN KEY (`location_id`) REFERENCES `location` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `submission_response`
--
ALTER TABLE `submission_response`
  ADD CONSTRAINT `fk_question_id` FOREIGN KEY (`question_id`) REFERENCES `question` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_submission_id` FOREIGN KEY (`submission_id`) REFERENCES `submission` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `survey_question`
--
ALTER TABLE `survey_question`
  ADD CONSTRAINT `survey_question_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `question` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `survey_question_ibfk_2` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `survey_settings`
--
ALTER TABLE `survey_settings`
  ADD CONSTRAINT `survey_settings_ibfk_1` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `survey_stages`
--
ALTER TABLE `survey_stages`
  ADD CONSTRAINT `survey_stages_ibfk_1` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tracker_groupings`
--
ALTER TABLE `tracker_groupings`
  ADD CONSTRAINT `tracker_groupings_ibfk_1` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tracker_submissions`
--
ALTER TABLE `tracker_submissions`
  ADD CONSTRAINT `tracker_submissions_ibfk_1` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_activity_log`
--
ALTER TABLE `user_activity_log`
  ADD CONSTRAINT `user_activity_log_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
