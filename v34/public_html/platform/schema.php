<?php
declare(strict_types=1);
// Platform (control-plane) schema. Additive, versioned, idempotent. Touches ONLY the platform database —
// never a restaurant's ledger database. Rollback: drop the platform database (no restaurant data lives here).
require_once __DIR__ . '/core.php';

function p_schema_version(): int { try { return (int)(pval("SELECT v FROM p_meta WHERE k='schema'") ?? 0); } catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) === 1146) return 0; throw $e; } }

function p_migrations(): array {
    $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
    1 => [
"CREATE TABLE IF NOT EXISTS p_meta (k VARCHAR(40) PRIMARY KEY, v VARCHAR(250) NOT NULL)$T",
"CREATE TABLE IF NOT EXISTS p_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NOT NULL)$T",
"CREATE TABLE IF NOT EXISTS p_users (id INT AUTO_INCREMENT PRIMARY KEY, uid CHAR(12) NOT NULL UNIQUE, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL,
  mobile VARCHAR(20) NULL, password_hash VARCHAR(255) NOT NULL, email_verified_at DATETIME NULL, status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  session_rev INT NOT NULL DEFAULT 1, legacy_source VARCHAR(60) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, last_login_at DATETIME NULL)$T",
"CREATE TABLE IF NOT EXISTS p_tenants (id INT AUTO_INCREMENT PRIMARY KEY, uid CHAR(12) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, status ENUM('provisioning','active','suspended','closed') NOT NULL DEFAULT 'provisioning',
  owner_user_id INT NOT NULL, contact_phone VARCHAR(20) NULL, contact_email VARCHAR(190) NULL, address VARCHAR(300) NULL, city VARCHAR(80) NULL, state VARCHAR(80) NULL, pincode VARCHAR(10) NULL,
  logo_path VARCHAR(200) NULL, brand_json TEXT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, activated_at DATETIME NULL, status_reason VARCHAR(300) NULL,
  KEY(owner_user_id), CONSTRAINT fk_t_owner FOREIGN KEY (owner_user_id) REFERENCES p_users(id))$T",
"CREATE TABLE IF NOT EXISTS p_branches (id INT AUTO_INCREMENT PRIMARY KEY, uid CHAR(12) NOT NULL UNIQUE, tenant_id INT NOT NULL, name VARCHAR(160) NOT NULL, address VARCHAR(300) NULL, city VARCHAR(80) NULL,
  cutoff_hour TINYINT NOT NULL DEFAULT 4, status ENUM('provisioning','active','disabled') NOT NULL DEFAULT 'provisioning', is_primary TINYINT NOT NULL DEFAULT 0,
  db_host VARCHAR(120) NULL, db_port INT NOT NULL DEFAULT 3306, db_name VARCHAR(64) NULL, db_user VARCHAR(80) NULL, db_pass_enc TEXT NULL, storage VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_branch_db (db_host, db_port, db_name), UNIQUE KEY u_storage (storage), UNIQUE KEY u_tb (tenant_id, id),
  CONSTRAINT fk_b_tenant FOREIGN KEY (tenant_id) REFERENCES p_tenants(id))$T",
"CREATE TABLE IF NOT EXISTS p_tenant_members (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, user_id INT NOT NULL, role ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
  status ENUM('active','revoked') NOT NULL DEFAULT 'active', invited_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_tm (tenant_id, user_id),
  CONSTRAINT fk_tm_t FOREIGN KEY (tenant_id) REFERENCES p_tenants(id), CONSTRAINT fk_tm_u FOREIGN KEY (user_id) REFERENCES p_users(id))$T",
// Composite FK (tenant_id, branch_id) → p_branches(tenant_id, id): a branch membership can never point at another tenant's branch.
"CREATE TABLE IF NOT EXISTS p_branch_members (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, branch_id INT NOT NULL, user_id INT NOT NULL, local_user_id INT NOT NULL,
  template VARCHAR(40) NOT NULL DEFAULT 'none', status ENUM('active','revoked') NOT NULL DEFAULT 'active', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY u_bm (branch_id, user_id), UNIQUE KEY u_local (branch_id, local_user_id), KEY(user_id),
  CONSTRAINT fk_bm_b FOREIGN KEY (tenant_id, branch_id) REFERENCES p_branches(tenant_id, id), CONSTRAINT fk_bm_tm FOREIGN KEY (tenant_id, user_id) REFERENCES p_tenant_members(tenant_id, user_id))$T",
"CREATE TABLE IF NOT EXISTS p_db_pool (id INT AUTO_INCREMENT PRIMARY KEY, db_host VARCHAR(120) NOT NULL, db_port INT NOT NULL DEFAULT 3306, db_name VARCHAR(64) NOT NULL, db_user VARCHAR(80) NOT NULL,
  db_pass_enc TEXT NOT NULL, status ENUM('available','claimed','retired') NOT NULL DEFAULT 'available', branch_id INT NULL, added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, claimed_at DATETIME NULL,
  UNIQUE KEY u_pool (db_host, db_port, db_name))$T",
"CREATE TABLE IF NOT EXISTS p_plans (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(40) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, description TEXT NULL, public TINYINT NOT NULL DEFAULT 1,
  active TINYINT NOT NULL DEFAULT 1, sort INT NOT NULL DEFAULT 0)$T",
"CREATE TABLE IF NOT EXISTS p_plan_versions (id INT AUTO_INCREMENT PRIMARY KEY, plan_id INT NOT NULL, version INT NOT NULL, term ENUM('monthly','quarterly','half_yearly','yearly') NOT NULL, months TINYINT NOT NULL,
  price_paise BIGINT NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'INR', max_branches INT NOT NULL DEFAULT 1, max_users INT NOT NULL DEFAULT 5, modules_json TEXT NOT NULL, grace_days INT NOT NULL DEFAULT 3,
  effective_from DATETIME NOT NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY u_pv (plan_id, term, version),
  CONSTRAINT fk_pv_plan FOREIGN KEY (plan_id) REFERENCES p_plans(id))$T",
"CREATE TABLE IF NOT EXISTS p_subscriptions (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL UNIQUE, plan_version_id INT NULL, kind ENUM('trial','paid','complimentary') NOT NULL DEFAULT 'trial',
  status ENUM('trialing','active','cancelled','suspended') NOT NULL DEFAULT 'trialing', current_period_end DATETIME NULL, cancel_at_period_end TINYINT NOT NULL DEFAULT 0,
  suspended_reason VARCHAR(300) NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_s_t FOREIGN KEY (tenant_id) REFERENCES p_tenants(id))$T",
// Every day of access is one row here; order_id UNIQUE = one paid order can never extend a subscription twice.
"CREATE TABLE IF NOT EXISTS p_sub_periods (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, source ENUM('trial','payment','admin','legacy') NOT NULL, order_id INT NULL UNIQUE,
  plan_version_id INT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, admin_id INT NULL, reason VARCHAR(300) NULL, revoked_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY(tenant_id, ends_at), CONSTRAINT fk_sp_t FOREIGN KEY (tenant_id) REFERENCES p_tenants(id))$T",
// auto_key = 'owner:<user id>' for the automatic sign-up trial → one automatic trial per owner account, ever.
"CREATE TABLE IF NOT EXISTS p_trial_grants (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, owner_user_id INT NOT NULL, auto_key VARCHAR(40) NULL UNIQUE, days INT NOT NULL,
  starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, admin_id INT NULL, reason VARCHAR(300) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tg_t FOREIGN KEY (tenant_id) REFERENCES p_tenants(id))$T",
"CREATE TABLE IF NOT EXISTS p_orders (id INT AUTO_INCREMENT PRIMARY KEY, uid CHAR(16) NOT NULL UNIQUE, tenant_id INT NOT NULL, plan_version_id INT NOT NULL, amount_paise BIGINT NOT NULL, currency CHAR(3) NOT NULL,
  months TINYINT NOT NULL, status ENUM('created','paid','failed','refunded','expired','review') NOT NULL DEFAULT 'created', provider VARCHAR(20) NOT NULL, provider_order_id VARCHAR(80) NULL UNIQUE,
  provider_payment_id VARCHAR(80) NULL, receipt_no VARCHAR(30) NULL UNIQUE, created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, paid_at DATETIME NULL, note VARCHAR(300) NULL, billing_name VARCHAR(160) NULL, gateway_mode VARCHAR(10) NOT NULL DEFAULT 'test',
  KEY(tenant_id), CONSTRAINT fk_o_t FOREIGN KEY (tenant_id) REFERENCES p_tenants(id), CONSTRAINT fk_o_pv FOREIGN KEY (plan_version_id) REFERENCES p_plan_versions(id))$T",
"CREATE TABLE IF NOT EXISTS p_payments (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL, provider_payment_id VARCHAR(80) NOT NULL UNIQUE, amount_paise BIGINT NOT NULL, currency CHAR(3) NOT NULL,
  status VARCHAR(20) NOT NULL, method VARCHAR(30) NULL, refunded_paise BIGINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
  CONSTRAINT fk_pay_o FOREIGN KEY (order_id) REFERENCES p_orders(id))$T",
"CREATE TABLE IF NOT EXISTS p_webhook_events (id INT AUTO_INCREMENT PRIMARY KEY, provider VARCHAR(20) NOT NULL, event_id VARCHAR(100) NOT NULL, event_type VARCHAR(60) NULL, signature_ok TINYINT NOT NULL,
  payload_sha CHAR(64) NOT NULL, payload MEDIUMTEXT NULL, status ENUM('received','processed','ignored','error','rejected') NOT NULL DEFAULT 'received', message VARCHAR(300) NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, processed_at DATETIME NULL, UNIQUE KEY u_evt (provider, event_id))$T",
"CREATE TABLE IF NOT EXISTS p_admins (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL, password_hash VARCHAR(255) NOT NULL,
  role ENUM('super_admin','billing_admin','support') NOT NULL DEFAULT 'support', totp_secret_enc TEXT NULL, totp_enabled TINYINT NOT NULL DEFAULT 0, totp_last_step BIGINT NOT NULL DEFAULT 0,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active', session_rev INT NOT NULL DEFAULT 1, last_login_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$T",
"CREATE TABLE IF NOT EXISTS p_audit (id BIGINT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, actor_type ENUM('user','admin','system','webhook') NOT NULL, actor_id INT NULL, tenant_id INT NULL,
  action VARCHAR(80) NOT NULL, detail TEXT NULL, ip_hash CHAR(24) NULL, KEY(tenant_id, at), KEY(action))$T",
"CREATE TABLE IF NOT EXISTS p_outbox (id BIGINT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(40) NOT NULL, dedupe_key VARCHAR(190) NOT NULL UNIQUE, channel ENUM('email','fcm','whatsapp') NOT NULL,
  recipient VARCHAR(190) NOT NULL, subject VARCHAR(250) NOT NULL, body MEDIUMTEXT NOT NULL, tenant_id INT NULL, status ENUM('queued','sent','failed','skipped','logged') NOT NULL DEFAULT 'queued',
  attempts INT NOT NULL DEFAULT 0, last_error VARCHAR(250) NULL, created_at DATETIME NOT NULL, next_at DATETIME NOT NULL, sent_at DATETIME NULL, KEY(status, next_at))$T",
"CREATE TABLE IF NOT EXISTS p_tokens (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NULL, kind ENUM('email_verify','password_reset','invite','registration','support_entry') NOT NULL, token_sha CHAR(64) NOT NULL UNIQUE,
  data TEXT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY(user_id, kind))$T",
"CREATE TABLE IF NOT EXISTS p_api_tokens (id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, family CHAR(24) NOT NULL, kind ENUM('access','refresh') NOT NULL, token_sha CHAR(64) NOT NULL UNIQUE,
  device_id VARCHAR(80) NOT NULL, device_name VARCHAR(120) NULL, tenant_id INT NULL, branch_id INT NULL, user_rev INT NOT NULL, expires_at DATETIME NOT NULL, revoked_at DATETIME NULL,
  used_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY(user_id), KEY(family), CONSTRAINT fk_at_u FOREIGN KEY (user_id) REFERENCES p_users(id))$T",
"CREATE TABLE IF NOT EXISTS p_rate (k CHAR(64) PRIMARY KEY, n INT NOT NULL, reset_at INT NOT NULL)$T",
"CREATE TABLE IF NOT EXISTS p_registrations (id INT AUTO_INCREMENT PRIMARY KEY, uid CHAR(16) NOT NULL UNIQUE, email VARCHAR(190) NOT NULL, data_json TEXT NOT NULL,
  status ENUM('draft','verified','provisioning','complete','failed') NOT NULL DEFAULT 'draft', user_id INT NULL, tenant_id INT NULL, error VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, KEY(email))$T",
"CREATE TABLE IF NOT EXISTS p_support_grants (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, branch_id INT NOT NULL, admin_id INT NOT NULL, reason VARCHAR(500) NOT NULL, minutes INT NOT NULL,
  status ENUM('requested','approved','denied','revoked') NOT NULL DEFAULT 'requested', requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_by INT NULL, decided_at DATETIME NULL, expires_at DATETIME NULL,
  KEY(tenant_id, status), CONSTRAINT fk_sg_b FOREIGN KEY (tenant_id, branch_id) REFERENCES p_branches(tenant_id, id))$T",
"CREATE TABLE IF NOT EXISTS p_notices (id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(160) NOT NULL, body TEXT NOT NULL, active TINYINT NOT NULL DEFAULT 1, admin_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)$T",
"CREATE TABLE IF NOT EXISTS p_device_tokens (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, token_sha CHAR(64) NOT NULL UNIQUE, token_enc TEXT NOT NULL, platform VARCHAR(20) NOT NULL DEFAULT 'android',
  api_family CHAR(24) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at DATETIME NULL, KEY(user_id))$T",
"CREATE TABLE IF NOT EXISTS p_job_runs (id BIGINT AUTO_INCREMENT PRIMARY KEY, job VARCHAR(40) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME NULL, status VARCHAR(10) NOT NULL, message VARCHAR(300) NULL, KEY(job, started_at))$T",
    ],
    2 => [ // default plans (prices are DATA, managed by Super Admin; these placeholders are inactive until the owner approves pricing)
"INSERT IGNORE INTO p_plans(id,code,name,description,public,active,sort) VALUES (1,'standard','Standard','Daily closing, expenses, staff & salary, purchases, suppliers, stock, reports.',1,1,1),(2,'legacy_complimentary','Founding restaurant (complimentary)','Internal plan for the original restaurant migrated from the single-restaurant system.',0,1,99)",
    ],
    3 => [ // V31 — single database: inter-branch transfers, push devices bound to tenant/branch, deletion/export requests
"CREATE TABLE IF NOT EXISTS p_branch_transfers (id BIGINT AUTO_INCREMENT PRIMARY KEY, uid CHAR(12) NOT NULL UNIQUE, tenant_id INT NOT NULL, from_branch_id INT NOT NULL, to_branch_id INT NOT NULL,
  kind ENUM('stock','cash') NOT NULL, status ENUM('in_transit','received','cancelled') NOT NULL DEFAULT 'in_transit', sent_date DATE NOT NULL, received_date DATE NULL, payload MEDIUMTEXT NOT NULL,
  value_paise BIGINT NOT NULL DEFAULT 0, note VARCHAR(250) NULL, cancel_reason VARCHAR(250) NULL, sent_by_local BIGINT UNSIGNED NULL, sent_by_platform INT NULL, received_by_local BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, received_at DATETIME NULL, KEY k_to (tenant_id, to_branch_id, status), KEY k_from (tenant_id, from_branch_id, status),
  CONSTRAINT fk_bt_from FOREIGN KEY (tenant_id, from_branch_id) REFERENCES p_branches(tenant_id, id), CONSTRAINT fk_bt_to FOREIGN KEY (tenant_id, to_branch_id) REFERENCES p_branches(tenant_id, id),
  CONSTRAINT ck_bt_diff CHECK (from_branch_id <> to_branch_id))$T",
"ALTER TABLE p_device_tokens ADD COLUMN tenant_id INT NULL, ADD COLUMN branch_id INT NULL, ADD COLUMN last_push_at DATETIME NULL, ADD KEY k_dev_scope (tenant_id, branch_id)",
"CREATE TABLE IF NOT EXISTS p_push_marks (branch_id INT NOT NULL PRIMARY KEY, last_notification_id BIGINT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)$T",
"CREATE TABLE IF NOT EXISTS p_data_requests (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, kind ENUM('export','deletion') NOT NULL, status ENUM('requested','approved','rejected','done','cancelled') NOT NULL DEFAULT 'requested',
  reason VARCHAR(500) NULL, requested_by INT NOT NULL, decided_by_admin INT NULL, decided_at DATETIME NULL, retain_until DATE NULL, note VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY(tenant_id, kind, status))$T",
    ],
    ];
}
function p_migrate(): array {
    $have = p_schema_version(); $done = [];
    foreach (p_migrations() as $v => $stmts) {
        if ($v <= $have) continue;
        foreach ($stmts as $sql) pdb()->exec($sql);
        if ($v === 2) p_seed_plan_versions();
        prun("INSERT INTO p_meta(k,v) VALUES('schema',?) ON DUPLICATE KEY UPDATE v=VALUES(v)", (string)$v); $done[] = $v;
    }
    return $done;
}
const P_ALL_MODULES = ['closing' => true, 'expenses' => true, 'staff' => true, 'payroll' => true, 'purchases' => true, 'stock' => true, 'reports' => true, 'integrations' => true];
/** Seed plan versions with price 0 and active=0: a price must be set and approved by the Super Admin before checkout is possible. */
function p_seed_plan_versions(): void {
    $m = json_encode(P_ALL_MODULES);
    foreach ([['monthly', 1], ['quarterly', 3], ['half_yearly', 6], ['yearly', 12]] as [$term, $months])
        prun("INSERT IGNORE INTO p_plan_versions(plan_id,version,term,months,price_paise,currency,max_branches,max_users,modules_json,grace_days,effective_from,active) VALUES(1,1,?,?,0,'INR',3,15,?,3,UTC_TIMESTAMP(),0)", $term, $months, $m);
    prun("INSERT IGNORE INTO p_plan_versions(plan_id,version,term,months,price_paise,currency,max_branches,max_users,modules_json,grace_days,effective_from,active) VALUES(2,1,'yearly',12,0,'INR',10,100,?,30,UTC_TIMESTAMP(),1)", $m);
}
