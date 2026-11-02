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
DROP TABLE IF EXISTS `p_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `name` varchar(160) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('super_admin','billing_admin','support') NOT NULL DEFAULT 'support',
  `totp_secret_enc` text DEFAULT NULL,
  `totp_enabled` tinyint(4) NOT NULL DEFAULT 0,
  `totp_last_step` bigint(20) NOT NULL DEFAULT 0,
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `session_rev` int(11) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_api_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_api_tokens` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `family` char(24) NOT NULL,
  `kind` enum('access','refresh') NOT NULL,
  `token_sha` char(64) NOT NULL,
  `device_id` varchar(80) NOT NULL,
  `device_name` varchar(120) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `user_rev` int(11) NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_sha` (`token_sha`),
  KEY `user_id` (`user_id`),
  KEY `family` (`family`),
  CONSTRAINT `fk_at_u` FOREIGN KEY (`user_id`) REFERENCES `p_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_audit` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `at` datetime NOT NULL,
  `actor_type` enum('user','admin','system','webhook') NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `detail` text DEFAULT NULL,
  `ip_hash` char(24) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`,`at`),
  KEY `action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_branch_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_branch_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `local_user_id` int(11) NOT NULL,
  `template` varchar(40) NOT NULL DEFAULT 'none',
  `status` enum('active','revoked') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_bm` (`branch_id`,`user_id`),
  UNIQUE KEY `u_local` (`branch_id`,`local_user_id`),
  KEY `user_id` (`user_id`),
  KEY `fk_bm_b` (`tenant_id`,`branch_id`),
  KEY `fk_bm_tm` (`tenant_id`,`user_id`),
  CONSTRAINT `fk_bm_b` FOREIGN KEY (`tenant_id`, `branch_id`) REFERENCES `p_branches` (`tenant_id`, `id`),
  CONSTRAINT `fk_bm_tm` FOREIGN KEY (`tenant_id`, `user_id`) REFERENCES `p_tenant_members` (`tenant_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` char(12) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(160) NOT NULL,
  `address` varchar(300) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `cutoff_hour` tinyint(4) NOT NULL DEFAULT 4,
  `status` enum('provisioning','active','disabled') NOT NULL DEFAULT 'provisioning',
  `is_primary` tinyint(4) NOT NULL DEFAULT 0,
  `db_host` varchar(120) DEFAULT NULL,
  `db_port` int(11) NOT NULL DEFAULT 3306,
  `db_name` varchar(64) DEFAULT NULL,
  `db_user` varchar(80) DEFAULT NULL,
  `db_pass_enc` text DEFAULT NULL,
  `storage` varchar(80) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  UNIQUE KEY `u_storage` (`storage`),
  UNIQUE KEY `u_tb` (`tenant_id`,`id`),
  UNIQUE KEY `u_branch_db` (`db_host`,`db_port`,`db_name`),
  CONSTRAINT `fk_b_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `p_tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_db_pool`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_db_pool` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `db_host` varchar(120) NOT NULL,
  `db_port` int(11) NOT NULL DEFAULT 3306,
  `db_name` varchar(64) NOT NULL,
  `db_user` varchar(80) NOT NULL,
  `db_pass_enc` text NOT NULL,
  `status` enum('available','claimed','retired') NOT NULL DEFAULT 'available',
  `branch_id` int(11) DEFAULT NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  `claimed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_pool` (`db_host`,`db_port`,`db_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_device_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_device_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_sha` char(64) NOT NULL,
  `token_enc` text NOT NULL,
  `platform` varchar(20) NOT NULL DEFAULT 'android',
  `api_family` char(24) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_sha` (`token_sha`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_job_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_job_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `job` varchar(40) NOT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(10) NOT NULL,
  `message` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job` (`job`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_meta` (
  `k` varchar(40) NOT NULL,
  `v` varchar(250) NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_notices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `body` text NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `admin_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` char(16) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `plan_version_id` int(11) NOT NULL,
  `amount_paise` bigint(20) NOT NULL,
  `currency` char(3) NOT NULL,
  `months` tinyint(4) NOT NULL,
  `status` enum('created','paid','failed','refunded','expired','review') NOT NULL DEFAULT 'created',
  `provider` varchar(20) NOT NULL,
  `provider_order_id` varchar(80) DEFAULT NULL,
  `provider_payment_id` varchar(80) DEFAULT NULL,
  `receipt_no` varchar(30) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `paid_at` datetime DEFAULT NULL,
  `note` varchar(300) DEFAULT NULL,
  `billing_name` varchar(160) DEFAULT NULL,
  `gateway_mode` varchar(10) NOT NULL DEFAULT 'test',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  UNIQUE KEY `provider_order_id` (`provider_order_id`),
  UNIQUE KEY `receipt_no` (`receipt_no`),
  KEY `tenant_id` (`tenant_id`),
  KEY `fk_o_pv` (`plan_version_id`),
  CONSTRAINT `fk_o_pv` FOREIGN KEY (`plan_version_id`) REFERENCES `p_plan_versions` (`id`),
  CONSTRAINT `fk_o_t` FOREIGN KEY (`tenant_id`) REFERENCES `p_tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_outbox` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `kind` varchar(40) NOT NULL,
  `dedupe_key` varchar(190) NOT NULL,
  `channel` enum('email','fcm','whatsapp') NOT NULL,
  `recipient` varchar(190) NOT NULL,
  `subject` varchar(250) NOT NULL,
  `body` mediumtext NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `status` enum('queued','sent','failed','skipped','logged') NOT NULL DEFAULT 'queued',
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_error` varchar(250) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `next_at` datetime NOT NULL,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dedupe_key` (`dedupe_key`),
  KEY `status` (`status`,`next_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `provider_payment_id` varchar(80) NOT NULL,
  `amount_paise` bigint(20) NOT NULL,
  `currency` char(3) NOT NULL,
  `status` varchar(20) NOT NULL,
  `method` varchar(30) DEFAULT NULL,
  `refunded_paise` bigint(20) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider_payment_id` (`provider_payment_id`),
  KEY `fk_pay_o` (`order_id`),
  CONSTRAINT `fk_pay_o` FOREIGN KEY (`order_id`) REFERENCES `p_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_plan_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_plan_versions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `version` int(11) NOT NULL,
  `term` enum('monthly','quarterly','half_yearly','yearly') NOT NULL,
  `months` tinyint(4) NOT NULL,
  `price_paise` bigint(20) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'INR',
  `max_branches` int(11) NOT NULL DEFAULT 1,
  `max_users` int(11) NOT NULL DEFAULT 5,
  `modules_json` text NOT NULL,
  `grace_days` int(11) NOT NULL DEFAULT 3,
  `effective_from` datetime NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_pv` (`plan_id`,`term`,`version`),
  CONSTRAINT `fk_pv_plan` FOREIGN KEY (`plan_id`) REFERENCES `p_plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `public` tinyint(4) NOT NULL DEFAULT 1,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `sort` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_rate`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_rate` (
  `k` char(64) NOT NULL,
  `n` int(11) NOT NULL,
  `reset_at` int(11) NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_registrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` char(16) NOT NULL,
  `email` varchar(190) NOT NULL,
  `data_json` text NOT NULL,
  `status` enum('draft','verified','provisioning','complete','failed') NOT NULL DEFAULT 'draft',
  `user_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `error` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_settings` (
  `k` varchar(60) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_sub_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_sub_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `source` enum('trial','payment','admin','legacy') NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `plan_version_id` int(11) DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `reason` varchar(300) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`),
  KEY `tenant_id` (`tenant_id`,`ends_at`),
  CONSTRAINT `fk_sp_t` FOREIGN KEY (`tenant_id`) REFERENCES `p_tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `plan_version_id` int(11) DEFAULT NULL,
  `kind` enum('trial','paid','complimentary') NOT NULL DEFAULT 'trial',
  `status` enum('trialing','active','cancelled','suspended') NOT NULL DEFAULT 'trialing',
  `current_period_end` datetime DEFAULT NULL,
  `cancel_at_period_end` tinyint(4) NOT NULL DEFAULT 0,
  `suspended_reason` varchar(300) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_id` (`tenant_id`),
  CONSTRAINT `fk_s_t` FOREIGN KEY (`tenant_id`) REFERENCES `p_tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_support_grants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_support_grants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `reason` varchar(500) NOT NULL,
  `minutes` int(11) NOT NULL,
  `status` enum('requested','approved','denied','revoked') NOT NULL DEFAULT 'requested',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`,`status`),
  KEY `fk_sg_b` (`tenant_id`,`branch_id`),
  CONSTRAINT `fk_sg_b` FOREIGN KEY (`tenant_id`, `branch_id`) REFERENCES `p_branches` (`tenant_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_tenant_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_tenant_members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('owner','admin','member') NOT NULL DEFAULT 'member',
  `status` enum('active','revoked') NOT NULL DEFAULT 'active',
  `invited_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_tm` (`tenant_id`,`user_id`),
  KEY `fk_tm_u` (`user_id`),
  CONSTRAINT `fk_tm_t` FOREIGN KEY (`tenant_id`) REFERENCES `p_tenants` (`id`),
  CONSTRAINT `fk_tm_u` FOREIGN KEY (`user_id`) REFERENCES `p_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` char(12) NOT NULL,
  `name` varchar(160) NOT NULL,
  `status` enum('provisioning','active','suspended','closed') NOT NULL DEFAULT 'provisioning',
  `owner_user_id` int(11) NOT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `contact_email` varchar(190) DEFAULT NULL,
  `address` varchar(300) DEFAULT NULL,
  `city` varchar(80) DEFAULT NULL,
  `state` varchar(80) DEFAULT NULL,
  `pincode` varchar(10) DEFAULT NULL,
  `logo_path` varchar(200) DEFAULT NULL,
  `brand_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `activated_at` datetime DEFAULT NULL,
  `status_reason` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  KEY `owner_user_id` (`owner_user_id`),
  CONSTRAINT `fk_t_owner` FOREIGN KEY (`owner_user_id`) REFERENCES `p_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `kind` enum('email_verify','password_reset','invite','registration','support_entry') NOT NULL,
  `token_sha` char(64) NOT NULL,
  `data` text DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_sha` (`token_sha`),
  KEY `user_id` (`user_id`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_trial_grants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_trial_grants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `owner_user_id` int(11) NOT NULL,
  `auto_key` varchar(40) DEFAULT NULL,
  `days` int(11) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `reason` varchar(300) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `auto_key` (`auto_key`),
  KEY `fk_tg_t` (`tenant_id`),
  CONSTRAINT `fk_tg_t` FOREIGN KEY (`tenant_id`) REFERENCES `p_tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `uid` char(12) NOT NULL,
  `email` varchar(190) NOT NULL,
  `name` varchar(160) NOT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `status` enum('active','disabled') NOT NULL DEFAULT 'active',
  `session_rev` int(11) NOT NULL DEFAULT 1,
  `legacy_source` varchar(60) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_login_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `p_webhook_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `p_webhook_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(20) NOT NULL,
  `event_id` varchar(100) NOT NULL,
  `event_type` varchar(60) DEFAULT NULL,
  `signature_ok` tinyint(4) NOT NULL,
  `payload_sha` char(64) NOT NULL,
  `payload` mediumtext DEFAULT NULL,
  `status` enum('received','processed','ignored','error','rejected') NOT NULL DEFAULT 'received',
  `message` varchar(300) DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `u_evt` (`provider`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

