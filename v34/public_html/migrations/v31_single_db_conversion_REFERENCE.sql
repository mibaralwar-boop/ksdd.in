-- Finance 360 V31 — REFERENCE ONLY: the exact DDL that tools/v31-convert.php executed on the staging copy (MariaDB 10.11,
-- demo KSDD data, schema F3 v13 / CRM v6). Do NOT run this file by hand: the tool records row hashes first, is resumable,
-- verifies every table afterwards and supports --rollback. Tenant 1 / branch 1 = the founding restaurant.
-- Verified on staging: 129/129 tables identical after conversion.

CREATE TABLE IF NOT EXISTS mt_ctx (conn_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL, branch_id INT UNSIGNED NOT NULL, set_at DATETIME NOT NULL, KEY k_set(set_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mt_tables (name VARCHAR(64) NOT NULL PRIMARY KEY, scope ENUM('branch','tenant') NOT NULL DEFAULT 'branch', original_json LONGTEXT NOT NULL, converted_at DATETIME NOT NULL, rows_before BIGINT NOT NULL DEFAULT 0, hash_before CHAR(64) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `dd_attendance` DROP FOREIGN KEY `dd_attendance_ibfk_1`;

ALTER TABLE `dd_bills` DROP FOREIGN KEY `dd_bills_ibfk_1`;

ALTER TABLE `dd_bills` DROP FOREIGN KEY `dd_bills_ibfk_2`;

ALTER TABLE `dd_ledger` DROP FOREIGN KEY `dd_ledger_employee`;

ALTER TABLE `dd_payroll` DROP FOREIGN KEY `dd_payroll_ibfk_1`;

ALTER TABLE `dd_paytm` DROP FOREIGN KEY `dd_paytm_ibfk_1`;

ALTER TABLE `dd_paytm` DROP FOREIGN KEY `dd_paytm_ibfk_2`;

ALTER TABLE `dd_stock_moves` DROP FOREIGN KEY `dd_stock_moves_ibfk_1`;

ALTER TABLE `f3_designations` DROP FOREIGN KEY `f3_desig_dept`;

ALTER TABLE `dd_archive` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_archive` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_archive` DROP INDEX `kind`, ADD INDEX `kind` (`tenant_id`,`branch_id`,`kind`,`ref`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_attendance` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_attendance` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_attendance` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`employee_id`,`date`);

ALTER TABLE `dd_audit` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_audit` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_audit` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bank_imports` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bank_imports` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bank_imports` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bank_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bank_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bank_lines` DROP INDEX `account`, ADD INDEX `account` (`tenant_id`,`branch_id`,`account`,`status`,`txn_date`), DROP INDEX `fingerprint`, ADD UNIQUE INDEX `fingerprint` (`tenant_id`,`branch_id`,`fingerprint`), DROP INDEX `ledger_id`, ADD UNIQUE INDEX `ledger_id` (`tenant_id`,`branch_id`,`ledger_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bills` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bills` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bills` DROP INDEX `idx_bill_due`, ADD INDEX `idx_bill_due` (`tenant_id`,`branch_id`,`status`,`due_date`), DROP INDEX `ledger_id`, ADD INDEX `ledger_id` (`tenant_id`,`branch_id`,`ledger_id`), DROP INDEX `supplier_id`, ADD UNIQUE INDEX `supplier_id` (`tenant_id`,`branch_id`,`supplier_id`,`reference`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bill_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bill_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bill_lines` DROP INDEX `bill_id`, ADD INDEX `bill_id` (`tenant_id`,`branch_id`,`bill_id`), DROP INDEX `stock_move_id`, ADD UNIQUE INDEX `stock_move_id` (`tenant_id`,`branch_id`,`stock_move_id`), DROP INDEX `supplier_id`, ADD INDEX `supplier_id` (`tenant_id`,`branch_id`,`supplier_id`,`item_id`,`id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bill_payments` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bill_payments` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bill_payments` DROP INDEX `bill_id`, ADD INDEX `bill_id` (`tenant_id`,`branch_id`,`bill_id`), DROP INDEX `idx_pay_ledger`, ADD INDEX `idx_pay_ledger` (`tenant_id`,`branch_id`,`ledger_id`), DROP INDEX `uq_pay_bill`, ADD UNIQUE INDEX `uq_pay_bill` (`tenant_id`,`branch_id`,`ledger_id`,`bill_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bk_imports` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bk_imports` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bk_imports` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bk_items` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bk_items` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bk_items` DROP INDEX `idx_bki_barcode`, ADD INDEX `idx_bki_barcode` (`tenant_id`,`branch_id`,`barcode`), DROP INDEX `idx_bki_cat`, ADD INDEX `idx_bki_cat` (`tenant_id`,`branch_id`,`category`,`active`), DROP INDEX `idx_bki_code`, ADD INDEX `idx_bki_code` (`tenant_id`,`branch_id`,`code`), DROP INDEX `name_key`, ADD UNIQUE INDEX `name_key` (`tenant_id`,`branch_id`,`name_key`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bk_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bk_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bk_lines` DROP INDEX `item_id`, ADD INDEX `item_id` (`tenant_id`,`branch_id`,`item_id`), DROP INDEX `txn_id`, ADD INDEX `txn_id` (`tenant_id`,`branch_id`,`txn_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bk_months` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bk_months` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bk_months` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`month`);

ALTER TABLE `dd_bk_moves` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bk_moves` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bk_moves` DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`), DROP INDEX `import_key`, ADD UNIQUE INDEX `import_key` (`tenant_id`,`branch_id`,`import_key`), DROP INDEX `item_id`, ADD INDEX `item_id` (`tenant_id`,`branch_id`,`item_id`,`date`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bk_parties` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bk_parties` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bk_parties` DROP INDEX `idx_bkp_kind`, ADD INDEX `idx_bkp_kind` (`tenant_id`,`branch_id`,`kind`,`active`), DROP INDEX `name_key`, ADD UNIQUE INDEX `name_key` (`tenant_id`,`branch_id`,`name_key`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_bk_txns` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_bk_txns` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_bk_txns` DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`), DROP INDEX `idx_bk_source`, ADD INDEX `idx_bk_source` (`tenant_id`,`branch_id`,`source`,`date`), DROP INDEX `import_key`, ADD UNIQUE INDEX `import_key` (`tenant_id`,`branch_id`,`import_key`), DROP INDEX `party_id`, ADD INDEX `party_id` (`tenant_id`,`branch_id`,`party_id`), DROP INDEX `type`, ADD INDEX `type` (`tenant_id`,`branch_id`,`type`,`date`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_budgets` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_budgets` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_budgets` DROP INDEX `uq_budget`, ADD UNIQUE INDEX `uq_budget` (`tenant_id`,`branch_id`,`month`,`category`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_cash_counts` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_cash_counts` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_cash_counts` DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`,`kind`), DROP INDEX `idx_count_drawer`, ADD INDEX `idx_count_drawer` (`tenant_id`,`branch_id`,`date`,`drawer`,`kind`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_checklist_runs` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_checklist_runs` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_checklist_runs` DROP INDEX `uq_run`, ADD UNIQUE INDEX `uq_run` (`tenant_id`,`branch_id`,`date`,`template`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_counter_closes` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_counter_closes` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_counter_closes` DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `idx_cc_day`, ADD INDEX `idx_cc_day` (`tenant_id`,`branch_id`,`business_date`,`status`), DROP INDEX `uq_cc`, ADD UNIQUE INDEX `uq_cc` (`tenant_id`,`branch_id`,`business_date`,`counter`,`revision`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_days` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_days` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_days` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`date`);

ALTER TABLE `dd_day_snapshots` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_day_snapshots` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_day_snapshots` DROP INDEX `uq_day_ver`, ADD UNIQUE INDEX `uq_day_ver` (`tenant_id`,`branch_id`,`date`,`version`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_drawer_openings` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_drawer_openings` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_drawer_openings` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`date`,`drawer`);

ALTER TABLE `dd_drawer_transfers` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_drawer_transfers` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_drawer_transfers` DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_driver_visits` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_driver_visits` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_driver_visits` DROP INDEX `uq_visit`, ADD UNIQUE INDEX `uq_visit` (`tenant_id`,`branch_id`,`kind`,`vehicle_no`,`visit_date`,`trip_ref`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_email_queue` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_email_queue` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_email_queue` DROP INDEX `idem_key`, ADD UNIQUE INDEX `idem_key` (`tenant_id`,`branch_id`,`idem_key`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`next_attempt_at`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_employees` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_employees` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_employees` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_employee_identifiers` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_employee_identifiers` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_employee_identifiers` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`), DROP INDEX `uq_ext`, ADD UNIQUE INDEX `uq_ext` (`tenant_id`,`branch_id`,`source`,`external_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_feedback` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_feedback` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_feedback` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_files` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_files` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_files` DROP INDEX `sha256`, ADD INDEX `sha256` (`tenant_id`,`branch_id`,`sha256`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_fin_events` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_fin_events` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_fin_events` DROP INDEX `idx_evt_ledger`, ADD INDEX `idx_evt_ledger` (`tenant_id`,`branch_id`,`ledger_id`), DROP INDEX `idx_evt_status`, ADD INDEX `idx_evt_status` (`tenant_id`,`branch_id`,`status`,`business_date`), DROP INDEX `uq_evt_src`, ADD UNIQUE INDEX `uq_evt_src` (`tenant_id`,`branch_id`,`source_system`,`source_table`,`source_id`,`source_rev`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_gl_accounts` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_gl_accounts` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_gl_accounts` DROP INDEX `code`, ADD UNIQUE INDEX `code` (`tenant_id`,`branch_id`,`code`), DROP INDEX `system_key`, ADD UNIQUE INDEX `system_key` (`tenant_id`,`branch_id`,`system_key`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_gl_category_map` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_gl_category_map` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_gl_category_map` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`category`);

ALTER TABLE `dd_gl_journals` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_gl_journals` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_gl_journals` DROP INDEX `business_date`, ADD INDEX `business_date` (`tenant_id`,`branch_id`,`business_date`), DROP INDEX `idx_gl_src_type`, ADD INDEX `idx_gl_src_type` (`tenant_id`,`branch_id`,`source_type`,`business_date`), DROP INDEX `journal_no`, ADD UNIQUE INDEX `journal_no` (`tenant_id`,`branch_id`,`journal_no`), DROP INDEX `period`, ADD INDEX `period` (`tenant_id`,`branch_id`,`period`), DROP INDEX `source_key`, ADD UNIQUE INDEX `source_key` (`tenant_id`,`branch_id`,`source_key`), DROP INDEX `source_type`, ADD INDEX `source_type` (`tenant_id`,`branch_id`,`source_type`,`source_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_gl_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_gl_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_gl_lines` DROP INDEX `account_id`, ADD INDEX `account_id` (`tenant_id`,`branch_id`,`account_id`), DROP INDEX `journal_id`, ADD INDEX `journal_id` (`tenant_id`,`branch_id`,`journal_id`), DROP INDEX `party_type`, ADD INDEX `party_type` (`tenant_id`,`branch_id`,`party_type`,`party_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_goods_receipts` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_goods_receipts` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_goods_receipts` DROP INDEX `bill_id`, ADD UNIQUE INDEX `bill_id` (`tenant_id`,`branch_id`,`bill_id`), DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `receipt_no`, ADD UNIQUE INDEX `receipt_no` (`tenant_id`,`branch_id`,`receipt_no`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`), DROP INDEX `supplier_id`, ADD INDEX `supplier_id` (`tenant_id`,`branch_id`,`supplier_id`,`status`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_handovers` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_handovers` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_handovers` DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_inbox` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_inbox` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_inbox` DROP INDEX `dedupe_key`, ADD UNIQUE INDEX `dedupe_key` (`tenant_id`,`branch_id`,`dedupe_key`), DROP INDEX `ref_table`, ADD INDEX `ref_table` (`tenant_id`,`branch_id`,`ref_table`,`ref_id`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`type`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_inbox_events` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_inbox_events` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_inbox_events` DROP INDEX `inbox_id`, ADD INDEX `inbox_id` (`tenant_id`,`branch_id`,`inbox_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_inventory` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_inventory` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_inventory` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_jobs` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_jobs` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_jobs` DROP INDEX `idem_key`, ADD UNIQUE INDEX `idem_key` (`tenant_id`,`branch_id`,`idem_key`), DROP INDEX `job`, ADD INDEX `job` (`tenant_id`,`branch_id`,`job`,`started_at`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_kitchen_requests` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_kitchen_requests` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_kitchen_requests` DROP INDEX `uq_req`, ADD UNIQUE INDEX `uq_req` (`tenant_id`,`branch_id`,`target_date`,`location_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_kitchen_request_items` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_kitchen_request_items` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_kitchen_request_items` DROP INDEX `uq_req_item`, ADD UNIQUE INDEX `uq_req_item` (`tenant_id`,`branch_id`,`request_id`,`item_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_ledger` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_ledger` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_ledger` DROP INDEX `idx_day`, ADD INDEX `idx_day` (`tenant_id`,`branch_id`,`date`,`voided`), DROP INDEX `idx_ledger_drawer`, ADD INDEX `idx_ledger_drawer` (`tenant_id`,`branch_id`,`date`,`drawer`), DROP INDEX `idx_payroll`, ADD INDEX `idx_payroll` (`tenant_id`,`branch_id`,`employee_id`,`payroll_month`,`type`), DROP INDEX `source_key`, ADD UNIQUE INDEX `source_key` (`tenant_id`,`branch_id`,`source_key`), DROP INDEX `uq_receipt`, ADD UNIQUE INDEX `uq_receipt` (`tenant_id`,`branch_id`,`receipt_no`), DROP INDEX `uq_voucher`, ADD UNIQUE INDEX `uq_voucher` (`tenant_id`,`branch_id`,`voucher_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_locations` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_locations` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_locations` DROP INDEX `name`, ADD UNIQUE INDEX `name` (`tenant_id`,`branch_id`,`name`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_login_attempts` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_login_attempts` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_login_attempts` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_menu` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_menu` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_menu` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_migrations` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_migrations` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_migrations` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`version`);

ALTER TABLE `dd_monthly_closures` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_monthly_closures` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_monthly_closures` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`month`);

ALTER TABLE `dd_orders` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_orders` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_orders` DROP INDEX `reference`, ADD UNIQUE INDEX `reference` (`tenant_id`,`branch_id`,`reference`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_payroll` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_payroll` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_payroll` DROP INDEX `employee_id`, ADD UNIQUE INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`,`month`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_payroll_details` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_payroll_details` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_payroll_details` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`payroll_id`);

ALTER TABLE `dd_payroll_import_batches` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_payroll_import_batches` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_payroll_import_batches` DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `confirm_key`, ADD UNIQUE INDEX `confirm_key` (`tenant_id`,`branch_id`,`confirm_key`), DROP INDEX `period`, ADD INDEX `period` (`tenant_id`,`branch_id`,`period`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_payroll_import_rows` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_payroll_import_rows` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_payroll_import_rows` DROP INDEX `external_id`, ADD INDEX `external_id` (`tenant_id`,`branch_id`,`external_id`), DROP INDEX `uq_batch_row`, ADD UNIQUE INDEX `uq_batch_row` (`tenant_id`,`branch_id`,`batch_id`,`row_no`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_paytm` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_paytm` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_paytm` DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`), DROP INDEX `external_id`, ADD UNIQUE INDEX `external_id` (`tenant_id`,`branch_id`,`external_id`), DROP INDEX `ledger_id`, ADD INDEX `ledger_id` (`tenant_id`,`branch_id`,`ledger_id`), DROP INDEX `matched_id`, ADD UNIQUE INDEX `matched_id` (`tenant_id`,`branch_id`,`matched_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_pos_days` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_pos_days` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_pos_days` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`business_date`);

ALTER TABLE `dd_po_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_po_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_po_lines` DROP INDEX `po_id`, ADD INDEX `po_id` (`tenant_id`,`branch_id`,`po_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_purchase_orders` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_purchase_orders` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_purchase_orders` DROP INDEX `po_no`, ADD UNIQUE INDEX `po_no` (`tenant_id`,`branch_id`,`po_no`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_receipt_files` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_receipt_files` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_receipt_files` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`receipt_id`,`file_id`), DROP INDEX `file_id`, ADD UNIQUE INDEX `file_id` (`tenant_id`,`branch_id`,`file_id`);

ALTER TABLE `dd_recurring_obligations` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_recurring_obligations` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_recurring_obligations` DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`due_date`), DROP INDEX `uq_obl`, ADD UNIQUE INDEX `uq_obl` (`tenant_id`,`branch_id`,`template_id`,`period`), DROP INDEX `voucher_id`, ADD UNIQUE INDEX `voucher_id` (`tenant_id`,`branch_id`,`voucher_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_recurring_templates` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_recurring_templates` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_recurring_templates` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_request_keys` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_request_keys` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_request_keys` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`scope`,`client_key`);

ALTER TABLE `dd_reservations` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_reservations` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_reservations` DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_sal_entries` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_sal_entries` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_sal_entries` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`,`kind`), DROP INDEX `month`, ADD INDEX `month` (`tenant_id`,`branch_id`,`month`,`kind`), DROP INDEX `row_id`, ADD INDEX `row_id` (`tenant_id`,`branch_id`,`row_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_sal_imports` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_sal_imports` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_sal_imports` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_sal_months` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_sal_months` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_sal_months` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`month`);

ALTER TABLE `dd_sal_rows` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_sal_rows` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_sal_rows` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`), DROP INDEX `uq_sal_row`, ADD UNIQUE INDEX `uq_sal_row` (`tenant_id`,`branch_id`,`month`,`employee_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_sequences` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_sequences` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_sequences` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`name`);

ALTER TABLE `dd_settings` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_settings` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_settings` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`key`);

ALTER TABLE `dd_staff_records` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_staff_records` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_staff_records` DROP INDEX `kind`, ADD INDEX `kind` (`tenant_id`,`branch_id`,`kind`,`date`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_stock_balances` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_stock_balances` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_stock_balances` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`item_id`,`location_id`);

ALTER TABLE `dd_stock_consumptions` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_stock_consumptions` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_stock_consumptions` DROP INDEX `business_date`, ADD INDEX `business_date` (`tenant_id`,`branch_id`,`business_date`,`location_id`), DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `consumption_no`, ADD UNIQUE INDEX `consumption_no` (`tenant_id`,`branch_id`,`consumption_no`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_stock_consumption_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_stock_consumption_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_stock_consumption_lines` DROP INDEX `business_date`, ADD INDEX `business_date` (`tenant_id`,`branch_id`,`business_date`,`location_id`,`item_id`), DROP INDEX `consumption_id`, ADD INDEX `consumption_id` (`tenant_id`,`branch_id`,`consumption_id`), DROP INDEX `used_move_id`, ADD UNIQUE INDEX `used_move_id` (`tenant_id`,`branch_id`,`used_move_id`), DROP INDEX `waste_move_id`, ADD UNIQUE INDEX `waste_move_id` (`tenant_id`,`branch_id`,`waste_move_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_stock_counts` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_stock_counts` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_stock_counts` DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_stock_moves` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_stock_moves` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_stock_moves` DROP INDEX `idx_move_date`, ADD INDEX `idx_move_date` (`tenant_id`,`branch_id`,`date`,`item_id`), DROP INDEX `idx_move_kind`, ADD INDEX `idx_move_kind` (`tenant_id`,`branch_id`,`kind`,`date`), DROP INDEX `item_id`, ADD INDEX `item_id` (`tenant_id`,`branch_id`,`item_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_suppliers` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_suppliers` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_suppliers` ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_supplier_credits` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_supplier_credits` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_supplier_credits` DROP INDEX `ledger_id`, ADD UNIQUE INDEX `ledger_id` (`tenant_id`,`branch_id`,`ledger_id`), DROP INDEX `source_key`, ADD UNIQUE INDEX `source_key` (`tenant_id`,`branch_id`,`source_key`), DROP INDEX `supplier_id`, ADD INDEX `supplier_id` (`tenant_id`,`branch_id`,`supplier_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_supplier_terms` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_supplier_terms` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_supplier_terms` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`supplier_id`);

ALTER TABLE `dd_unit_conversions` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_unit_conversions` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_unit_conversions` DROP INDEX `uq_conv`, ADD UNIQUE INDEX `uq_conv` (`tenant_id`,`branch_id`,`item_id`,`from_unit`,`to_unit`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_users` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_users` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_users` DROP INDEX `email`, ADD UNIQUE INDEX `email` (`tenant_id`,`branch_id`,`email`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_vouchers` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_vouchers` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_vouchers` DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `ledger_id`, ADD UNIQUE INDEX `ledger_id` (`tenant_id`,`branch_id`,`ledger_id`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`kind`), DROP INDEX `voucher_no`, ADD UNIQUE INDEX `voucher_no` (`tenant_id`,`branch_id`,`voucher_no`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `dd_voucher_allocations` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `dd_voucher_allocations` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `dd_voucher_allocations` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`voucher_id`,`bill_id`);

ALTER TABLE `f3_assignments` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_assignments` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_assignments` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`,`effective_from`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_bill_files` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_bill_files` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_bill_files` DROP INDEX `bill_id`, ADD INDEX `bill_id` (`tenant_id`,`branch_id`,`bill_id`), DROP INDEX `uq_bf`, ADD UNIQUE INDEX `uq_bf` (`tenant_id`,`branch_id`,`bill_id`,`file_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_closings` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_closings` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_closings` DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`), DROP INDEX `uq_close_day`, ADD UNIQUE INDEX `uq_close_day` (`tenant_id`,`branch_id`,`business_date`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_closing_events` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_closing_events` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_closing_events` DROP INDEX `business_date`, ADD INDEX `business_date` (`tenant_id`,`branch_id`,`business_date`), DROP INDEX `closing_id`, ADD INDEX `closing_id` (`tenant_id`,`branch_id`,`closing_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_closing_files` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_closing_files` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_closing_files` DROP INDEX `business_date`, ADD INDEX `business_date` (`tenant_id`,`branch_id`,`business_date`), DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `uq_cf`, ADD UNIQUE INDEX `uq_cf` (`tenant_id`,`branch_id`,`closing_id`,`file_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_corrections` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_corrections` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_corrections` DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_departments` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_departments` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_departments` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`id`), DROP INDEX `code`, ADD UNIQUE INDEX `code` (`tenant_id`,`branch_id`,`code`);

ALTER TABLE `f3_designations` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_designations` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_designations` DROP INDEX `uq_desig`, ADD UNIQUE INDEX `uq_desig` (`tenant_id`,`branch_id`,`dept_id`,`name`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_doc_inbox` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_doc_inbox` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_doc_inbox` DROP INDEX `file_id`, ADD INDEX `file_id` (`tenant_id`,`branch_id`,`file_id`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_drive_folders` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_drive_folders` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_drive_folders` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`path_hash`);

ALTER TABLE `f3_drive_items` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_drive_items` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_drive_items` DROP INDEX `kind`, ADD INDEX `kind` (`tenant_id`,`branch_id`,`kind`,`created_at`), DROP INDEX `sha256`, ADD INDEX `sha256` (`tenant_id`,`branch_id`,`sha256`), DROP INDEX `source_key`, ADD UNIQUE INDEX `source_key` (`tenant_id`,`branch_id`,`source_key`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`next_try_at`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_exceptions` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_exceptions` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_exceptions` DROP INDEX `fingerprint`, ADD UNIQUE INDEX `fingerprint` (`tenant_id`,`branch_id`,`fingerprint`), DROP INDEX `kind`, ADD INDEX `kind` (`tenant_id`,`branch_id`,`kind`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`last_seen`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_exception_log` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_exception_log` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_exception_log` DROP INDEX `exception_id`, ADD INDEX `exception_id` (`tenant_id`,`branch_id`,`exception_id`,`id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_expense_files` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_expense_files` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_expense_files` DROP INDEX `txn_id`, ADD INDEX `txn_id` (`tenant_id`,`branch_id`,`txn_id`), DROP INDEX `uq_ef`, ADD UNIQUE INDEX `uq_ef` (`tenant_id`,`branch_id`,`txn_id`,`file_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_expense_meta` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_expense_meta` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_expense_meta` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`txn_id`), DROP INDEX `business_date`, ADD INDEX `business_date` (`tenant_id`,`branch_id`,`business_date`), DROP INDEX `review_status`, ADD INDEX `review_status` (`tenant_id`,`branch_id`,`review_status`);

ALTER TABLE `f3_ext_ids` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_ext_ids` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_ext_ids` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`), DROP INDEX `uq_ext`, ADD UNIQUE INDEX `uq_ext` (`tenant_id`,`branch_id`,`source`,`ext_key`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_handovers` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_handovers` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_handovers` DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `from_user`, ADD INDEX `from_user` (`tenant_id`,`branch_id`,`from_user`), DROP INDEX `ho_no`, ADD UNIQUE INDEX `ho_no` (`tenant_id`,`branch_id`,`ho_no`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`date`), DROP INDEX `to_user`, ADD INDEX `to_user` (`tenant_id`,`branch_id`,`to_user`,`status`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_import_batches` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_import_batches` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_import_batches` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`batch_id`);

ALTER TABLE `f3_import_rows` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_import_rows` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_import_rows` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`), DROP INDEX `uq_ir`, ADD UNIQUE INDEX `uq_ir` (`tenant_id`,`branch_id`,`batch_id`,`row_key`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_int_events` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_int_events` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_int_events` DROP INDEX `level`, ADD INDEX `level` (`tenant_id`,`branch_id`,`level`,`id`), DROP INDEX `service`, ADD INDEX `service` (`tenant_id`,`branch_id`,`service`,`at`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_int_secrets` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_int_secrets` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_int_secrets` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`name`);

ALTER TABLE `f3_item_units` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_item_units` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_item_units` DROP INDEX `uq_iu`, ADD UNIQUE INDEX `uq_iu` (`tenant_id`,`branch_id`,`item_id`,`unit`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_job_runs` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_job_runs` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_job_runs` DROP INDEX `job`, ADD INDEX `job` (`tenant_id`,`branch_id`,`job`,`id`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`started_at`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_notifications` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_notifications` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_notifications` DROP INDEX `dedupe_key`, ADD UNIQUE INDEX `dedupe_key` (`tenant_id`,`branch_id`,`dedupe_key`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`perm`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_notif_user` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_notif_user` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_notif_user` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`notification_id`,`user_id`);

ALTER TABLE `f3_pay_acks` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_pay_acks` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_pay_acks` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`), DROP INDEX `period`, ADD INDEX `period` (`tenant_id`,`branch_id`,`period`,`status`), DROP INDEX `uq_pa`, ADD UNIQUE INDEX `uq_pa` (`tenant_id`,`branch_id`,`payment_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_pay_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_pay_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_pay_lines` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`), DROP INDEX `uq_pay_line`, ADD UNIQUE INDEX `uq_pay_line` (`tenant_id`,`branch_id`,`period`,`employee_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_pay_payments` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_pay_payments` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_pay_payments` DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `pay_no`, ADD UNIQUE INDEX `pay_no` (`tenant_id`,`branch_id`,`pay_no`), DROP INDEX `period`, ADD INDEX `period` (`tenant_id`,`branch_id`,`period`,`employee_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_pay_periods` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_pay_periods` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_pay_periods` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`period`);

ALTER TABLE `f3_purchase_bills` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_purchase_bills` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_purchase_bills` DROP INDEX `bill_no`, ADD UNIQUE INDEX `bill_no` (`tenant_id`,`branch_id`,`bill_no`), DROP INDEX `invoice_key`, ADD INDEX `invoice_key` (`tenant_id`,`branch_id`,`invoice_key`), DROP INDEX `post_date`, ADD INDEX `post_date` (`tenant_id`,`branch_id`,`post_date`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`receipt_status`), DROP INDEX `supplier_id`, ADD INDEX `supplier_id` (`tenant_id`,`branch_id`,`supplier_id`,`status`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_purchase_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_purchase_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_purchase_lines` DROP INDEX `bill_id`, ADD INDEX `bill_id` (`tenant_id`,`branch_id`,`bill_id`), DROP INDEX `item_id`, ADD INDEX `item_id` (`tenant_id`,`branch_id`,`item_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_purchase_returns` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_purchase_returns` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_purchase_returns` DROP INDEX `bill_id`, ADD INDEX `bill_id` (`tenant_id`,`branch_id`,`bill_id`), DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `return_no`, ADD UNIQUE INDEX `return_no` (`tenant_id`,`branch_id`,`return_no`), DROP INDEX `supplier_id`, ADD INDEX `supplier_id` (`tenant_id`,`branch_id`,`supplier_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_purchase_return_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_purchase_return_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_purchase_return_lines` DROP INDEX `return_id`, ADD INDEX `return_id` (`tenant_id`,`branch_id`,`return_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_receipts` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_receipts` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_receipts` DROP INDEX `bill_id`, ADD INDEX `bill_id` (`tenant_id`,`branch_id`,`bill_id`), DROP INDEX `receipt_no`, ADD UNIQUE INDEX `receipt_no` (`tenant_id`,`branch_id`,`receipt_no`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_salary_rates` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_salary_rates` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_salary_rates` DROP INDEX `uq_rate`, ADD UNIQUE INDEX `uq_rate` (`tenant_id`,`branch_id`,`employee_id`,`effective_from`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_sal_history` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_sal_history` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_sal_history` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`), DROP INDEX `period`, ADD INDEX `period` (`tenant_id`,`branch_id`,`period`,`superseded_by`), DROP INDEX `uq_sh`, ADD UNIQUE INDEX `uq_sh` (`tenant_id`,`branch_id`,`batch_id`,`period`,`sheet_row`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_sequences` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_sequences` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_sequences` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`name`);

ALTER TABLE `f3_staff_txns` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_staff_txns` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_staff_txns` DROP INDEX `applied_period`, ADD INDEX `applied_period` (`tenant_id`,`branch_id`,`applied_period`), DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`), DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`,`kind`,`status`), DROP INDEX `idx_f3_st_period`, ADD INDEX `idx_f3_st_period` (`tenant_id`,`branch_id`,`employee_id`,`applied_period`), DROP INDEX `txn_no`, ADD UNIQUE INDEX `txn_no` (`tenant_id`,`branch_id`,`txn_no`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_stock_balances` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_stock_balances` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_stock_balances` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`item_id`,`location`);

ALTER TABLE `f3_stock_counts` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_stock_counts` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_stock_counts` DROP INDEX `count_no`, ADD UNIQUE INDEX `count_no` (`tenant_id`,`branch_id`,`count_no`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_stock_count_lines` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_stock_count_lines` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_stock_count_lines` DROP INDEX `uq_cl`, ADD UNIQUE INDEX `uq_cl` (`tenant_id`,`branch_id`,`count_id`,`item_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_stock_moves` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_stock_moves` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_stock_moves` DROP INDEX `date`, ADD INDEX `date` (`tenant_id`,`branch_id`,`date`), DROP INDEX `idem_key`, ADD UNIQUE INDEX `idem_key` (`tenant_id`,`branch_id`,`idem_key`), DROP INDEX `item_id`, ADD INDEX `item_id` (`tenant_id`,`branch_id`,`item_id`,`date`), DROP INDEX `kind`, ADD INDEX `kind` (`tenant_id`,`branch_id`,`kind`,`date`), DROP INDEX `move_no`, ADD UNIQUE INDEX `move_no` (`tenant_id`,`branch_id`,`move_no`), DROP INDEX `source_type`, ADD INDEX `source_type` (`tenant_id`,`branch_id`,`source_type`,`source_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_supplier_allocations` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_supplier_allocations` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_supplier_allocations` DROP INDEX `bill_id`, ADD INDEX `bill_id` (`tenant_id`,`branch_id`,`bill_id`), DROP INDEX `uq_alloc`, ADD UNIQUE INDEX `uq_alloc` (`tenant_id`,`branch_id`,`payment_txn_id`,`bill_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_supplier_payments` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_supplier_payments` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_supplier_payments` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`txn_id`), DROP INDEX `client_key`, ADD UNIQUE INDEX `client_key` (`tenant_id`,`branch_id`,`client_key`), DROP INDEX `pay_no`, ADD UNIQUE INDEX `pay_no` (`tenant_id`,`branch_id`,`pay_no`), DROP INDEX `supplier_id`, ADD INDEX `supplier_id` (`tenant_id`,`branch_id`,`supplier_id`);

ALTER TABLE `f3_user_access` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_user_access` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_user_access` DROP PRIMARY KEY, ADD PRIMARY KEY (`tenant_id`,`branch_id`,`user_id`);

ALTER TABLE `f3_wa_messages` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_wa_messages` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_wa_messages` DROP INDEX `idem_key`, ADD UNIQUE INDEX `idem_key` (`tenant_id`,`branch_id`,`idem_key`), DROP INDEX `provider_id`, ADD INDEX `provider_id` (`tenant_id`,`branch_id`,`provider_id`), DROP INDEX `recipient_id`, ADD INDEX `recipient_id` (`tenant_id`,`branch_id`,`recipient_id`,`created_at`), DROP INDEX `status`, ADD INDEX `status` (`tenant_id`,`branch_id`,`status`,`next_try_at`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_wa_recipients` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_wa_recipients` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_wa_recipients` DROP INDEX `employee_id`, ADD INDEX `employee_id` (`tenant_id`,`branch_id`,`employee_id`), DROP INDEX `phone_hash`, ADD UNIQUE INDEX `phone_hash` (`tenant_id`,`branch_id`,`phone_hash`), DROP INDEX `supplier_id`, ADD INDEX `supplier_id` (`tenant_id`,`branch_id`,`supplier_id`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

ALTER TABLE `f3_wa_webhooks` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT 1, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1;

ALTER TABLE `f3_wa_webhooks` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0;

ALTER TABLE `f3_wa_webhooks` DROP INDEX `body_hash`, ADD UNIQUE INDEX `body_hash` (`tenant_id`,`branch_id`,`body_hash`), ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`id`);

RENAME TABLE `dd_archive` TO `mt_dd_archive`, `dd_attendance` TO `mt_dd_attendance`, `dd_audit` TO `mt_dd_audit`, `dd_bank_imports` TO `mt_dd_bank_imports`, `dd_bank_lines` TO `mt_dd_bank_lines`, `dd_bills` TO `mt_dd_bills`, `dd_bill_lines` TO `mt_dd_bill_lines`, `dd_bill_payments` TO `mt_dd_bill_payments`, `dd_bk_imports` TO `mt_dd_bk_imports`, `dd_bk_items` TO `mt_dd_bk_items`, `dd_bk_lines` TO `mt_dd_bk_lines`, `dd_bk_months` TO `mt_dd_bk_months`, `dd_bk_moves` TO `mt_dd_bk_moves`, `dd_bk_parties` TO `mt_dd_bk_parties`, `dd_bk_txns` TO `mt_dd_bk_txns`, `dd_budgets` TO `mt_dd_budgets`, `dd_cash_counts` TO `mt_dd_cash_counts`, `dd_checklist_runs` TO `mt_dd_checklist_runs`, `dd_counter_closes` TO `mt_dd_counter_closes`, `dd_days` TO `mt_dd_days`, `dd_day_snapshots` TO `mt_dd_day_snapshots`, `dd_drawer_openings` TO `mt_dd_drawer_openings`, `dd_drawer_transfers` TO `mt_dd_drawer_transfers`, `dd_driver_visits` TO `mt_dd_driver_visits`, `dd_email_queue` TO `mt_dd_email_queue`, `dd_employees` TO `mt_dd_employees`, `dd_employee_identifiers` TO `mt_dd_employee_identifiers`, `dd_feedback` TO `mt_dd_feedback`, `dd_files` TO `mt_dd_files`, `dd_fin_events` TO `mt_dd_fin_events`, `dd_gl_accounts` TO `mt_dd_gl_accounts`, `dd_gl_category_map` TO `mt_dd_gl_category_map`, `dd_gl_journals` TO `mt_dd_gl_journals`, `dd_gl_lines` TO `mt_dd_gl_lines`, `dd_goods_receipts` TO `mt_dd_goods_receipts`, `dd_handovers` TO `mt_dd_handovers`, `dd_inbox` TO `mt_dd_inbox`, `dd_inbox_events` TO `mt_dd_inbox_events`, `dd_inventory` TO `mt_dd_inventory`, `dd_jobs` TO `mt_dd_jobs`, `dd_kitchen_requests` TO `mt_dd_kitchen_requests`, `dd_kitchen_request_items` TO `mt_dd_kitchen_request_items`, `dd_ledger` TO `mt_dd_ledger`, `dd_locations` TO `mt_dd_locations`, `dd_login_attempts` TO `mt_dd_login_attempts`, `dd_menu` TO `mt_dd_menu`, `dd_migrations` TO `mt_dd_migrations`, `dd_monthly_closures` TO `mt_dd_monthly_closures`, `dd_orders` TO `mt_dd_orders`, `dd_payroll` TO `mt_dd_payroll`, `dd_payroll_details` TO `mt_dd_payroll_details`, `dd_payroll_import_batches` TO `mt_dd_payroll_import_batches`, `dd_payroll_import_rows` TO `mt_dd_payroll_import_rows`, `dd_paytm` TO `mt_dd_paytm`, `dd_pos_days` TO `mt_dd_pos_days`, `dd_po_lines` TO `mt_dd_po_lines`, `dd_purchase_orders` TO `mt_dd_purchase_orders`, `dd_receipt_files` TO `mt_dd_receipt_files`, `dd_recurring_obligations` TO `mt_dd_recurring_obligations`, `dd_recurring_templates` TO `mt_dd_recurring_templates`, `dd_request_keys` TO `mt_dd_request_keys`, `dd_reservations` TO `mt_dd_reservations`, `dd_sal_entries` TO `mt_dd_sal_entries`, `dd_sal_imports` TO `mt_dd_sal_imports`, `dd_sal_months` TO `mt_dd_sal_months`, `dd_sal_rows` TO `mt_dd_sal_rows`, `dd_sequences` TO `mt_dd_sequences`, `dd_settings` TO `mt_dd_settings`, `dd_staff_records` TO `mt_dd_staff_records`, `dd_stock_balances` TO `mt_dd_stock_balances`, `dd_stock_consumptions` TO `mt_dd_stock_consumptions`, `dd_stock_consumption_lines` TO `mt_dd_stock_consumption_lines`, `dd_stock_counts` TO `mt_dd_stock_counts`, `dd_stock_moves` TO `mt_dd_stock_moves`, `dd_suppliers` TO `mt_dd_suppliers`, `dd_supplier_credits` TO `mt_dd_supplier_credits`, `dd_supplier_terms` TO `mt_dd_supplier_terms`, `dd_unit_conversions` TO `mt_dd_unit_conversions`, `dd_users` TO `mt_dd_users`, `dd_vouchers` TO `mt_dd_vouchers`, `dd_voucher_allocations` TO `mt_dd_voucher_allocations`, `f3_assignments` TO `mt_f3_assignments`, `f3_bill_files` TO `mt_f3_bill_files`, `f3_closings` TO `mt_f3_closings`, `f3_closing_events` TO `mt_f3_closing_events`, `f3_closing_files` TO `mt_f3_closing_files`, `f3_corrections` TO `mt_f3_corrections`, `f3_departments` TO `mt_f3_departments`, `f3_designations` TO `mt_f3_designations`, `f3_doc_inbox` TO `mt_f3_doc_inbox`, `f3_drive_folders` TO `mt_f3_drive_folders`, `f3_drive_items` TO `mt_f3_drive_items`, `f3_exceptions` TO `mt_f3_exceptions`, `f3_exception_log` TO `mt_f3_exception_log`, `f3_expense_files` TO `mt_f3_expense_files`, `f3_expense_meta` TO `mt_f3_expense_meta`, `f3_ext_ids` TO `mt_f3_ext_ids`, `f3_handovers` TO `mt_f3_handovers`, `f3_import_batches` TO `mt_f3_import_batches`, `f3_import_rows` TO `mt_f3_import_rows`, `f3_int_events` TO `mt_f3_int_events`, `f3_int_secrets` TO `mt_f3_int_secrets`, `f3_item_units` TO `mt_f3_item_units`, `f3_job_runs` TO `mt_f3_job_runs`, `f3_notifications` TO `mt_f3_notifications`, `f3_notif_user` TO `mt_f3_notif_user`, `f3_pay_acks` TO `mt_f3_pay_acks`, `f3_pay_lines` TO `mt_f3_pay_lines`, `f3_pay_payments` TO `mt_f3_pay_payments`, `f3_pay_periods` TO `mt_f3_pay_periods`, `f3_purchase_bills` TO `mt_f3_purchase_bills`, `f3_purchase_lines` TO `mt_f3_purchase_lines`, `f3_purchase_returns` TO `mt_f3_purchase_returns`, `f3_purchase_return_lines` TO `mt_f3_purchase_return_lines`, `f3_receipts` TO `mt_f3_receipts`, `f3_salary_rates` TO `mt_f3_salary_rates`, `f3_sal_history` TO `mt_f3_sal_history`, `f3_sequences` TO `mt_f3_sequences`, `f3_staff_txns` TO `mt_f3_staff_txns`, `f3_stock_balances` TO `mt_f3_stock_balances`, `f3_stock_counts` TO `mt_f3_stock_counts`, `f3_stock_count_lines` TO `mt_f3_stock_count_lines`, `f3_stock_moves` TO `mt_f3_stock_moves`, `f3_supplier_allocations` TO `mt_f3_supplier_allocations`, `f3_supplier_payments` TO `mt_f3_supplier_payments`, `f3_user_access` TO `mt_f3_user_access`, `f3_wa_messages` TO `mt_f3_wa_messages`, `f3_wa_recipients` TO `mt_f3_wa_recipients`, `f3_wa_webhooks` TO `mt_f3_wa_webhooks`;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_archive` AS SELECT `id`,`kind`,`ref`,`payload`,`sha256`,`reason`,`created_by`,`created_at` FROM `mt_dd_archive` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_attendance` AS SELECT `employee_id`,`date`,`status` FROM `mt_dd_attendance` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_audit` AS SELECT `id`,`at`,`user_id`,`action`,`detail` FROM `mt_dd_audit` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bank_imports` AS SELECT `id`,`account`,`file_sha256`,`added`,`skipped`,`created_by`,`created_at` FROM `mt_dd_bank_imports` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bank_lines` AS SELECT `id`,`import_id`,`account`,`txn_date`,`description`,`reference`,`debit`,`credit`,`balance`,`fingerprint`,`ledger_id`,`status`,`note`,`matched_by`,`matched_at` FROM `mt_dd_bank_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bills` AS SELECT `id`,`supplier_id`,`reference`,`date`,`due_date`,`amount`,`ledger_id`,`paid`,`status`,`proof_file_id`,`po_id`,`created_by`,`kind`,`credited`,`receipt_id`,`original_due_date`,`voided` FROM `mt_dd_bills` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bill_lines` AS SELECT `id`,`bill_id`,`receipt_id`,`stock_move_id`,`po_line_id`,`item_id`,`item_name`,`purchase_qty`,`purchase_unit`,`conversion_factor`,`stock_qty`,`stock_unit`,`rate`,`gross`,`discount`,`tax`,`total`,`batch`,`expiry`,`supplier_id`,`created_by`,`created_at` FROM `mt_dd_bill_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bill_payments` AS SELECT `id`,`bill_id`,`ledger_id`,`amount`,`created_at` FROM `mt_dd_bill_payments` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bk_imports` AS SELECT `id`,`file_name`,`file_sha256`,`kinds`,`result`,`created_by`,`created_at` FROM `mt_dd_bk_imports` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bk_items` AS SELECT `id`,`name`,`name_key`,`unit`,`category`,`code`,`is_stock`,`reorder`,`opening_qty`,`std_rate`,`active`,`created_at`,`merged_into`,`sku`,`barcode`,`subcategory`,`brand`,`description`,`photo_file_id`,`purchase_unit`,`min_level`,`max_level`,`location`,`default_supplier_id`,`tax_rate_bp`,`opening_date`,`crm_item_id`,`updated_at`,`f3_lead_days` FROM `mt_dd_bk_items` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bk_lines` AS SELECT `id`,`txn_id`,`item_id`,`qty`,`unit`,`rate`,`amount`,`discount`,`tax`,`orig_item_id` FROM `mt_dd_bk_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bk_months` AS SELECT `month`,`status`,`counted_cash`,`note`,`locked_by`,`locked_at` FROM `mt_dd_bk_months` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bk_moves` AS SELECT `id`,`item_id`,`date`,`qty`,`kind`,`note`,`source`,`import_key`,`voided`,`created_by`,`created_at`,`orig_item_id` FROM `mt_dd_bk_moves` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bk_parties` AS SELECT `id`,`name`,`name_key`,`kind`,`phone`,`gstin`,`opening`,`credit_days`,`employee_id`,`aliases`,`note`,`active`,`created_at`,`merged_into`,`code`,`legal_name`,`contact_person`,`whatsapp`,`phone2`,`email`,`address`,`city`,`state`,`pin`,`pan_enc`,`bank_name`,`bank_account_enc`,`bank_last4`,`ifsc`,`upi_id`,`opening_as_of`,`credit_limit`,`default_terms`,`default_category`,`photo_file_id`,`crm_supplier_id`,`updated_at` FROM `mt_dd_bk_parties` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_bk_txns` AS SELECT `id`,`date`,`type`,`party_id`,`category`,`total`,`paid`,`account`,`to_account`,`bill_no`,`description`,`source`,`import_key`,`voided`,`void_reason`,`created_by`,`created_at`,`updated_at` FROM `mt_dd_bk_txns` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_budgets` AS SELECT `id`,`month`,`category`,`amount`,`updated_by`,`updated_at` FROM `mt_dd_budgets` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_cash_counts` AS SELECT `id`,`date`,`kind`,`drawer`,`denominations`,`total`,`counted_by`,`created_at` FROM `mt_dd_cash_counts` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_checklist_runs` AS SELECT `id`,`date`,`template`,`items`,`done`,`completed_by`,`updated_at` FROM `mt_dd_checklist_runs` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_counter_closes` AS SELECT `id`,`business_date`,`counter`,`drawer`,`revision`,`status`,`orders_success`,`orders_cancelled`,`orders_returned`,`orders_complimentary`,`net`,`discount`,`tax`,`refund`,`rounding`,`total`,`unpaid`,`cash`,`card`,`upi`,`other`,`other_additive`,`residual`,`evidence_ref`,`note`,`client_key`,`created_by`,`created_at`,`superseded_by` FROM `mt_dd_counter_closes` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_days` AS SELECT `date`,`opening`,`opening_set`,`counted`,`status`,`note`,`closed_by`,`workflow`,`opening_source`,`expected`,`variance`,`variance_status`,`submitted_by`,`submitted_at`,`reviewed_by`,`reviewed_at`,`closed_at` FROM `mt_dd_days` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_day_snapshots` AS SELECT `id`,`date`,`version`,`label`,`payload`,`sha256`,`created_by`,`created_at` FROM `mt_dd_day_snapshots` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_drawer_openings` AS SELECT `date`,`drawer`,`opening`,`set_by`,`set_at` FROM `mt_dd_drawer_openings` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_drawer_transfers` AS SELECT `id`,`date`,`from_drawer`,`to_drawer`,`amount`,`note`,`created_by`,`acknowledged_by`,`acknowledged_at`,`voided`,`created_at` FROM `mt_dd_drawer_transfers` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_driver_visits` AS SELECT `id`,`kind`,`driver_name`,`phone`,`vehicle_no`,`yellow_plate`,`visit_date`,`trip_ref`,`passengers`,`amount`,`free_food`,`voucher_id`,`created_by`,`created_at` FROM `mt_dd_driver_visits` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_email_queue` AS SELECT `id`,`idem_key`,`kind`,`snapshot_id`,`recipients`,`cc`,`subject`,`status`,`attempts`,`last_error`,`smtp_reply`,`next_attempt_at`,`sent_at`,`created_by`,`created_at` FROM `mt_dd_email_queue` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_employees` AS SELECT `id`,`name`,`job`,`salary`,`pay_basis`,`active`,`code`,`department`,`designation`,`status`,`joined_on`,`left_on`,`created_by`,`section`,`aliases`,`phone`,`pay_mode`,`bank_name`,`ifsc`,`account_last4`,`f3_dept_id`,`f3_desig_id`,`legal_name`,`address`,`photo_file_id`,`bank_account_enc`,`upi_id`,`notes`,`documents`,`exit_reason`,`updated_at` FROM `mt_dd_employees` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_employee_identifiers` AS SELECT `id`,`source`,`external_id`,`employee_id`,`verified_by`,`created_at` FROM `mt_dd_employee_identifiers` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_feedback` AS SELECT `id`,`date`,`rating`,`comment`,`status`,`response` FROM `mt_dd_feedback` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_files` AS SELECT `id`,`path`,`mime`,`size`,`sha256`,`original_name`,`uploaded_by`,`created_at` FROM `mt_dd_files` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_fin_events` AS SELECT `id`,`source_system`,`source_table`,`source_id`,`source_rev`,`kind`,`business_date`,`earned_period`,`amount`,`account`,`employee_id`,`party`,`memo`,`payload`,`payload_sha`,`status`,`journal_id`,`ledger_id`,`linked_event_id`,`decided_by`,`decided_at`,`decision_note`,`created_at` FROM `mt_dd_fin_events` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_gl_accounts` AS SELECT `id`,`code`,`name`,`name_hi`,`type`,`normal`,`system_key`,`active`,`created_at` FROM `mt_dd_gl_accounts` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_gl_category_map` AS SELECT `category`,`account_code`,`updated_by`,`updated_at` FROM `mt_dd_gl_category_map` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_gl_journals` AS SELECT `id`,`journal_no`,`business_date`,`period`,`source_type`,`source_id`,`source_key`,`memo`,`status`,`reverses_id`,`total`,`created_by`,`created_at` FROM `mt_dd_gl_journals` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_gl_lines` AS SELECT `id`,`journal_id`,`account_id`,`debit`,`credit`,`memo`,`party_type`,`party_id` FROM `mt_dd_gl_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_goods_receipts` AS SELECT `id`,`receipt_no`,`supplier_id`,`location_id`,`invoice_ref`,`invoice_date`,`po_id`,`status`,`hold_reason`,`subtotal`,`bill_discount`,`bill_tax`,`other_charges`,`rounding`,`calculated_total`,`invoice_total`,`difference`,`payload`,`bill_id`,`note`,`review_note`,`client_key`,`created_by`,`reviewed_by`,`reviewed_at`,`created_at` FROM `mt_dd_goods_receipts` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_handovers` AS SELECT `id`,`date`,`outgoing_user`,`incoming_user`,`count_id`,`expected`,`counted`,`variance`,`note`,`out_ack_at`,`in_ack_at`,`status`,`created_at` FROM `mt_dd_handovers` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_inbox` AS SELECT `id`,`type`,`module`,`priority`,`title`,`detail`,`amount`,`ref_table`,`ref_id`,`business_date`,`raised_by`,`assigned_to`,`status`,`due_at`,`dedupe_key`,`resolved_by`,`resolved_at`,`created_at` FROM `mt_dd_inbox` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_inbox_events` AS SELECT `id`,`inbox_id`,`user_id`,`action`,`note`,`at` FROM `mt_dd_inbox_events` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_inventory` AS SELECT `id`,`name`,`unit`,`quantity`,`reorder`,`cost`,`name_hi`,`category`,`aliases`,`safety_stock`,`active`,`cost_source`,`track_daily`,`budget_group` FROM `mt_dd_inventory` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_jobs` AS SELECT `id`,`job`,`idem_key`,`business_date`,`trigger_by`,`status`,`processed`,`skipped`,`errors`,`retry_count`,`started_at`,`finished_at` FROM `mt_dd_jobs` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_kitchen_requests` AS SELECT `id`,`target_date`,`location_id`,`status`,`note`,`created_by`,`submitted_at`,`verified_by`,`verified_at`,`acknowledged_by` FROM `mt_dd_kitchen_requests` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_kitchen_request_items` AS SELECT `id`,`request_id`,`item_id`,`required_qty`,`kitchen_available`,`shortage_qty`,`store_available`,`to_issue`,`to_purchase`,`issued_qty`,`received_qty`,`priority`,`note`,`purchase_status` FROM `mt_dd_kitchen_request_items` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_ledger` AS SELECT `id`,`date`,`name`,`type`,`account`,`to_account`,`amount`,`category`,`reference`,`description`,`employee_id`,`payroll_month`,`source`,`source_key`,`voided`,`created_by`,`created_at`,`receipt_no`,`drawer`,`payee`,`proof_file_id`,`voucher_id` FROM `mt_dd_ledger` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_locations` AS SELECT `id`,`name`,`name_hi`,`kind`,`active` FROM `mt_dd_locations` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_login_attempts` AS SELECT `id`,`attempts`,`expires` FROM `mt_dd_login_attempts` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_menu` AS SELECT `id`,`name`,`category`,`price`,`active` FROM `mt_dd_menu` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_migrations` AS SELECT `version`,`applied_at`,`note` FROM `mt_dd_migrations` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_monthly_closures` AS SELECT `month`,`status`,`quality`,`flags`,`report`,`report_sha256`,`note`,`closed_by`,`closed_at`,`reopened_by`,`reopen_reason`,`updated_at` FROM `mt_dd_monthly_closures` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_orders` AS SELECT `id`,`date`,`kind`,`table_no`,`items`,`subtotal`,`discount`,`tax`,`total`,`reference` FROM `mt_dd_orders` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_payroll` AS SELECT `id`,`employee_id`,`month`,`base`,`overtime`,`bonus`,`deductions`,`advance`,`gross`,`net`,`approved`,`source`,`approved_by`,`approved_at`,`paid_days`,`note`,`calc_policy`,`calc_days` FROM `mt_dd_payroll` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_payroll_details` AS SELECT `payroll_id`,`batch_id`,`import_row_id`,`external_id`,`source`,`sheet_to_pay`,`sheet_advance`,`crm_recoverable`,`reconcile_note`,`updated_at` FROM `mt_dd_payroll_details` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_payroll_import_batches` AS SELECT `id`,`client_key`,`period`,`file_name`,`file_sha256`,`file_path`,`sheet_name`,`status`,`total_rows`,`result`,`confirm_key`,`created_by`,`confirmed_by`,`created_at`,`confirmed_at` FROM `mt_dd_payroll_import_batches` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_payroll_import_rows` AS SELECT `id`,`batch_id`,`row_no`,`external_id`,`source_name`,`clean_name`,`source_status`,`department`,`designation`,`mapped`,`row_hash`,`match_employee_id`,`match_kind`,`issues`,`action`,`status`,`result_employee_id`,`result_payroll_id` FROM `mt_dd_payroll_import_rows` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_paytm` AS SELECT `id`,`external_id`,`date`,`reference`,`amount`,`status`,`kind`,`fee`,`ledger_id`,`matched_id` FROM `mt_dd_paytm` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_pos_days` AS SELECT `business_date`,`revision`,`cash`,`upi`,`card`,`other`,`gross`,`net`,`discount`,`refund`,`voids`,`tax`,`bills`,`source`,`file_sha256`,`status`,`reconcile_note`,`pending_payload`,`imported_by`,`imported_at` FROM `mt_dd_pos_days` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_po_lines` AS SELECT `id`,`po_id`,`item_id`,`quantity`,`rate`,`received_qty` FROM `mt_dd_po_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_purchase_orders` AS SELECT `id`,`po_no`,`supplier_id`,`expected_date`,`status`,`note`,`created_by`,`created_at` FROM `mt_dd_purchase_orders` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_receipt_files` AS SELECT `receipt_id`,`file_id` FROM `mt_dd_receipt_files` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_recurring_obligations` AS SELECT `id`,`template_id`,`period`,`expected_amount`,`actual_amount`,`bill_ref`,`bill_date`,`due_date`,`proof_file_id`,`status`,`voucher_id`,`paid_ledger_id`,`note`,`created_at` FROM `mt_dd_recurring_obligations` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_recurring_templates` AS SELECT `id`,`name`,`category`,`payee`,`supplier_id`,`amount_mode`,`amount`,`frequency`,`bill_day`,`due_day`,`start_month`,`end_month`,`reminder_days`,`proof_required`,`contract_ref`,`active`,`created_by`,`updated_at` FROM `mt_dd_recurring_templates` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_request_keys` AS SELECT `scope`,`client_key`,`payload_sha`,`response`,`user_id`,`created_at` FROM `mt_dd_request_keys` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_reservations` AS SELECT `id`,`date`,`time`,`name`,`phone`,`covers`,`status`,`notes` FROM `mt_dd_reservations` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_sal_entries` AS SELECT `id`,`row_id`,`month`,`employee_id`,`kind`,`amount`,`date`,`mode`,`note`,`source`,`voided`,`void_reason`,`created_by`,`created_at` FROM `mt_dd_sal_entries` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_sal_imports` AS SELECT `id`,`file_name`,`file_sha256`,`months`,`result`,`created_by`,`created_at` FROM `mt_dd_sal_imports` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_sal_months` AS SELECT `month`,`days`,`status`,`tolerance`,`carry_forward`,`source`,`note`,`created_by`,`created_at`,`locked_by`,`locked_at` FROM `mt_dd_sal_months` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_sal_rows` AS SELECT `id`,`month`,`employee_id`,`section`,`department`,`sort`,`monthly`,`present`,`leave_days`,`bf`,`pay_mode`,`note`,`source_name`,`excel_row`,`updated_by`,`updated_at`,`app_days`,`app_wage`,`app_penalty`,`app_advance`,`app_to_pay`,`app_ext_id` FROM `mt_dd_sal_rows` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_sequences` AS SELECT `name`,`value` FROM `mt_dd_sequences` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_settings` AS SELECT `key`,`value` FROM `mt_dd_settings` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_staff_records` AS SELECT `id`,`kind`,`date`,`payload`,`created_by`,`created_at` FROM `mt_dd_staff_records` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_stock_balances` AS SELECT `item_id`,`location_id`,`quantity` FROM `mt_dd_stock_balances` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_stock_consumptions` AS SELECT `id`,`consumption_no`,`business_date`,`location_id`,`mode`,`status`,`note`,`client_key`,`created_by`,`decided_by`,`decided_at`,`decision_note`,`created_at` FROM `mt_dd_stock_consumptions` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_stock_consumption_lines` AS SELECT `id`,`consumption_id`,`business_date`,`location_id`,`item_id`,`available_qty`,`used_qty`,`waste_qty`,`waste_reason`,`counted_qty`,`expected_close`,`variance_qty`,`unit_cost`,`used_value`,`waste_value`,`used_move_id`,`waste_move_id`,`count_id`,`status`,`no_usage_reason` FROM `mt_dd_stock_consumption_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_stock_counts` AS SELECT `id`,`date`,`item_id`,`location_id`,`system_qty`,`counted_qty`,`difference`,`reason`,`status`,`counted_by`,`approved_by`,`move_id`,`created_at` FROM `mt_dd_stock_counts` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_stock_moves` AS SELECT `id`,`item_id`,`date`,`quantity`,`reason`,`kind`,`from_location`,`to_location`,`supplier_id`,`rate`,`reference`,`request_item_id`,`po_line_id`,`proof_file_id`,`created_by`,`created_at`,`cost_value` FROM `mt_dd_stock_moves` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_suppliers` AS SELECT `id`,`name`,`phone`,`notes`,`category`,`credit_days`,`opening_payable`,`active`,`gstin`,`name_key`,`updated_at` FROM `mt_dd_suppliers` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_supplier_credits` AS SELECT `id`,`supplier_id`,`bill_id`,`kind`,`amount`,`reference`,`reason`,`ledger_id`,`source_key`,`created_by`,`created_at`,`applied_at` FROM `mt_dd_supplier_credits` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_supplier_terms` AS SELECT `supplier_id`,`mode`,`cycle_days`,`anchor_date`,`weekday`,`day_of_month`,`credit_days`,`reminder_days`,`updated_by`,`updated_at` FROM `mt_dd_supplier_terms` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_unit_conversions` AS SELECT `id`,`item_id`,`from_unit`,`to_unit`,`factor` FROM `mt_dd_unit_conversions` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_users` AS SELECT `id`,`name`,`email`,`password`,`role`,`session_rev`,`active`,`employee_id`,`drawer`,`department`,`lang` FROM `mt_dd_users` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_vouchers` AS SELECT `id`,`voucher_no`,`kind`,`date`,`payee`,`amount`,`category`,`description`,`employee_id`,`payroll_month`,`bill_id`,`driver_visit_id`,`proof_file_id`,`status`,`requested_by`,`approved_by`,`approved_at`,`paid_by`,`paid_at`,`ledger_id`,`client_key`,`created_at`,`supplier_id`,`is_advance`,`obligation_id` FROM `mt_dd_vouchers` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `dd_voucher_allocations` AS SELECT `voucher_id`,`bill_id`,`amount` FROM `mt_dd_voucher_allocations` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_assignments` AS SELECT `id`,`employee_id`,`dept_id`,`desig_id`,`effective_from`,`note`,`created_by`,`created_at` FROM `mt_f3_assignments` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_bill_files` AS SELECT `id`,`bill_id`,`file_id`,`kind`,`sort`,`removed`,`created_by`,`created_at` FROM `mt_f3_bill_files` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_closings` AS SELECT `id`,`business_date`,`status`,`revision`,`version`,`step`,`cash_sale`,`card_sale`,`upi_sale`,`total_sale`,`expenses_confirmed`,`actual_cash`,`diff_reason`,`opening_cash`,`other_cash_in`,`cash_out`,`expenses_total`,`expenses_cash`,`expected_cash`,`accounting_variance`,`operational_diff`,`sales_post_status`,`variance_class`,`variance_basis`,`variance_amount`,`variance_txn_id`,`review_note`,`snapshot`,`created_by`,`updated_by`,`submitted_by`,`submitted_at`,`approved_by`,`approved_at`,`reopened_by`,`reopened_at`,`reopen_reason`,`created_at`,`updated_at` FROM `mt_f3_closings` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_closing_events` AS SELECT `id`,`closing_id`,`business_date`,`action`,`revision`,`note`,`snapshot`,`user_id`,`at` FROM `mt_f3_closing_events` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_closing_files` AS SELECT `id`,`closing_id`,`business_date`,`revision`,`file_id`,`kind`,`client_key`,`created_by`,`created_at`,`removed_at`,`removed_by` FROM `mt_f3_closing_files` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_corrections` AS SELECT `id`,`kind`,`business_date`,`ref`,`reason`,`status`,`requested_by`,`requested_at`,`decided_by`,`decided_at`,`decision_note` FROM `mt_f3_corrections` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_departments` AS SELECT `id`,`code`,`name`,`name_hi`,`sort` FROM `mt_f3_departments` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_designations` AS SELECT `id`,`dept_id`,`name`,`name_hi`,`sort`,`active`,`created_at` FROM `mt_f3_designations` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_doc_inbox` AS SELECT `id`,`file_id`,`kind_guess`,`status`,`linked_type`,`linked_id`,`note`,`duplicate_of`,`duplicate_note`,`uploaded_by`,`decided_by`,`decided_at`,`created_at` FROM `mt_f3_doc_inbox` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_drive_folders` AS SELECT `path_hash`,`path`,`drive_id`,`created_at` FROM `mt_f3_drive_folders` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_drive_items` AS SELECT `id`,`source_key`,`kind`,`ref`,`file_id`,`local_path`,`name`,`folder`,`mime`,`is_sensitive`,`encrypted`,`sha256`,`size`,`status`,`attempts`,`next_try_at`,`last_error`,`drive_file_id`,`web_link`,`created_by`,`created_at`,`updated_at`,`uploaded_at`,`verified_at` FROM `mt_f3_drive_items` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_exceptions` AS SELECT `id`,`fingerprint`,`kind`,`area`,`title`,`detail`,`how`,`route`,`status`,`assigned_to`,`first_seen`,`last_seen`,`resolved_by`,`resolved_at`,`resolution_note` FROM `mt_f3_exceptions` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_exception_log` AS SELECT `id`,`exception_id`,`user_id`,`action`,`note`,`at` FROM `mt_f3_exception_log` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_expense_files` AS SELECT `id`,`txn_id`,`file_id`,`sort`,`removed`,`created_by`,`created_at` FROM `mt_f3_expense_files` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_expense_meta` AS SELECT `txn_id`,`business_date`,`file_id`,`review_status`,`review_note`,`reviewed_by`,`reviewed_at`,`created_by`,`created_at` FROM `mt_f3_expense_meta` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_ext_ids` AS SELECT `id`,`source`,`ext_key`,`employee_id`,`batch_id`,`created_at` FROM `mt_f3_ext_ids` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_handovers` AS SELECT `id`,`ho_no`,`date`,`from_user`,`to_user`,`from_account`,`to_account`,`amount`,`accepted_amount`,`denominations`,`note`,`status`,`response_note`,`bk_txn_id`,`client_key`,`responded_by`,`responded_at`,`created_at` FROM `mt_f3_handovers` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_import_batches` AS SELECT `batch_id`,`kind`,`status`,`files`,`decisions`,`summary`,`before_counts`,`after_counts`,`created_by`,`created_at`,`applied_by`,`applied_at`,`backup_name`,`rolled_back_by`,`rolled_back_at`,`rollback_note`,`applied_seq`,`decision_fps` FROM `mt_f3_import_batches` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_import_rows` AS SELECT `id`,`batch_id`,`row_key`,`action`,`employee_id`,`before_json`,`after_json`,`created_rate_ids`,`created_assignment_ids`,`created_ext_ids`,`note`,`created_at` FROM `mt_f3_import_rows` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_int_events` AS SELECT `id`,`service`,`event`,`level`,`message`,`ref`,`user_id`,`at` FROM `mt_f3_int_events` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_int_secrets` AS SELECT `name`,`value_enc`,`updated_by`,`updated_at` FROM `mt_f3_int_secrets` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_item_units` AS SELECT `id`,`item_id`,`unit`,`factor`,`note`,`created_by`,`created_at` FROM `mt_f3_item_units` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_job_runs` AS SELECT `id`,`job`,`status`,`message`,`items`,`started_at`,`finished_at` FROM `mt_f3_job_runs` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_notifications` AS SELECT `id`,`kind`,`dedupe_key`,`title`,`body`,`perm`,`ref`,`status`,`created_at`,`done_at` FROM `mt_f3_notifications` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_notif_user` AS SELECT `notification_id`,`user_id`,`snooze_until`,`seen_at` FROM `mt_f3_notif_user` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_pay_acks` AS SELECT `id`,`period`,`employee_id`,`payment_id`,`status`,`method`,`note`,`amount_claimed`,`recorded_by`,`created_at`,`resolution`,`resolved_by`,`resolved_at` FROM `mt_f3_pay_acks` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_pay_lines` AS SELECT `id`,`period`,`employee_id`,`state`,`version`,`emp_name`,`emp_code`,`dept_id`,`desig_id`,`desig_name`,`monthly`,`eligible_days`,`present`,`half_days`,`leave_taken`,`paid_leave_allowed`,`other_paid_leave`,`unpaid_leave`,`payable_days`,`gross_base`,`additions`,`other_earnings`,`gross_earned`,`penalties`,`advance_recovery`,`other_deductions`,`net_payable`,`ot_paid_info`,`carried_forward`,`cf_source`,`adjust_note`,`skip_reason`,`calc`,`updated_by`,`updated_at`,`absent`,`paid_leave`,`rest_days`,`penalty_ids`,`saved_at` FROM `mt_f3_pay_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_pay_payments` AS SELECT `id`,`pay_no`,`period`,`employee_id`,`date`,`amount`,`mode`,`reference`,`bk_txn_id`,`voided`,`void_reason`,`client_key`,`created_by`,`created_at` FROM `mt_f3_pay_payments` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_pay_periods` AS SELECT `period`,`status`,`days`,`divisor`,`divisor_mode`,`version`,`created_by`,`created_at`,`submitted_by`,`submitted_at`,`approved_by`,`approved_at`,`locked_by`,`locked_at`,`unlocked_by`,`unlocked_at`,`unlock_reason`,`snapshot`,`leave_encash`,`calc_mode` FROM `mt_f3_pay_periods` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_purchase_bills` AS SELECT `id`,`bill_no`,`supplier_id`,`invoice_no`,`invoice_key`,`invoice_no_missing`,`invoice_date`,`receipt_date`,`due_date`,`terms`,`location`,`challan_no`,`reference`,`gstin`,`tax_invoice`,`notes`,`subtotal`,`line_discount`,`line_tax`,`bill_discount`,`transport`,`other_tax`,`rounding`,`grand_total`,`status`,`receipt_status`,`post_date`,`bk_txn_id`,`dup_reason`,`category`,`submitted_by`,`approved_by`,`approved_at`,`step`,`version`,`created_by`,`created_at`,`updated_at`,`posted_by`,`posted_at`,`reversed_by`,`reversed_at`,`reverse_reason` FROM `mt_f3_purchase_bills` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_purchase_lines` AS SELECT `id`,`bill_id`,`sort`,`item_id`,`item_name`,`unit`,`factor`,`base_unit`,`qty`,`base_qty`,`rate`,`gross`,`disc_type`,`disc_value`,`discount`,`tax_rate_bp`,`tax`,`line_total`,`batch`,`expiry`,`mrp`,`notes`,`photo_file_id`,`cost_total`,`received_base`,`returned_base` FROM `mt_f3_purchase_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_purchase_returns` AS SELECT `id`,`return_no`,`kind`,`bill_id`,`supplier_id`,`date`,`reason`,`total`,`refund`,`refund_mode`,`bk_txn_id`,`client_key`,`created_by`,`created_at` FROM `mt_f3_purchase_returns` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_purchase_return_lines` AS SELECT `id`,`return_id`,`bill_line_id`,`item_id`,`base_qty`,`amount`,`move_id` FROM `mt_f3_purchase_return_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_receipts` AS SELECT `id`,`receipt_no`,`bill_id`,`date`,`note`,`created_by`,`created_at` FROM `mt_f3_receipts` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_salary_rates` AS SELECT `id`,`employee_id`,`effective_from`,`monthly`,`basis`,`note`,`source`,`created_by`,`created_at` FROM `mt_f3_salary_rates` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_sal_history` AS SELECT `id`,`batch_id`,`period`,`sheet`,`sheet_row`,`section`,`sno`,`name`,`hr_code`,`employee_id`,`designation`,`monthly`,`present`,`leave_days`,`working_days`,`total_salary`,`advance`,`debt`,`net_salary`,`pay_mode`,`paid`,`balance`,`pay_status`,`superseded_by`,`created_at` FROM `mt_f3_sal_history` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_sequences` AS SELECT `name`,`value` FROM `mt_f3_sequences` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_staff_txns` AS SELECT `id`,`txn_no`,`employee_id`,`kind`,`date`,`amount`,`mode`,`status`,`reason`,`reference`,`hours`,`installment`,`file_id`,`period`,`applied_period`,`bk_txn_id`,`reverses_id`,`client_key`,`created_by`,`created_at`,`decided_by`,`decided_at`,`decision_note` FROM `mt_f3_staff_txns` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_stock_balances` AS SELECT `item_id`,`location`,`qty`,`value`,`updated_at` FROM `mt_f3_stock_balances` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_stock_counts` AS SELECT `id`,`count_no`,`location`,`date`,`status`,`scope`,`note`,`started_by`,`submitted_by`,`submitted_at`,`approved_by`,`approved_at`,`created_at`,`start_move_id` FROM `mt_f3_stock_counts` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_stock_count_lines` AS SELECT `id`,`count_id`,`item_id`,`expected`,`counted`,`reason`,`counted_by`,`counted_at`,`move_id`,`book_at_count`,`move_id_at_count`,`approved_expected`,`post_count_qty` FROM `mt_f3_stock_count_lines` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_stock_moves` AS SELECT `id`,`move_no`,`item_id`,`location`,`date`,`kind`,`qty`,`entered_qty`,`entered_unit`,`factor`,`value`,`qty_after`,`value_after`,`source_type`,`source_id`,`source_line_id`,`reason`,`cost_center`,`reverses_id`,`reversed_by`,`idem_key`,`created_by`,`approved_by`,`created_at` FROM `mt_f3_stock_moves` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_supplier_allocations` AS SELECT `id`,`payment_txn_id`,`bill_id`,`amount`,`voided`,`created_by`,`created_at` FROM `mt_f3_supplier_allocations` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_supplier_payments` AS SELECT `txn_id`,`supplier_id`,`pay_no`,`kind`,`reference`,`file_id`,`client_key`,`created_by`,`created_at` FROM `mt_f3_supplier_payments` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_user_access` AS SELECT `user_id`,`template`,`overrides`,`updated_by`,`updated_at`,`limits` FROM `mt_f3_user_access` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_wa_messages` AS SELECT `id`,`idem_key`,`event`,`recipient_id`,`ref`,`campaign`,`params_enc`,`summary`,`status`,`attempts`,`next_try_at`,`provider_id`,`last_error`,`created_at`,`updated_at`,`sent_at`,`delivered_at`,`read_at`,`failed_at` FROM `mt_f3_wa_messages` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_wa_recipients` AS SELECT `id`,`name`,`phone_enc`,`phone_hash`,`phone_last4`,`role`,`employee_id`,`supplier_id`,`events`,`consent`,`consent_source`,`consent_at`,`consent_by`,`opted_out_at`,`opt_out_source`,`active`,`created_by`,`created_at`,`updated_at` FROM `mt_f3_wa_recipients` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `f3_wa_webhooks` AS SELECT `id`,`body_hash`,`sig_ok`,`statuses`,`matched`,`inbound`,`note`,`received_at` FROM `mt_f3_wa_webhooks` WHERE `tenant_id`=(SELECT `tenant_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) AND `branch_id`=(SELECT `branch_id` FROM `mt_ctx` WHERE `conn_id`=CONNECTION_ID()) WITH CASCADED CHECK OPTION;

ALTER TABLE `mt_dd_attendance` ADD CONSTRAINT `dd_attendance_ibfk_1` FOREIGN KEY (`tenant_id`,`branch_id`,`employee_id`) REFERENCES `mt_dd_employees` (`tenant_id`,`branch_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `mt_dd_bills` ADD CONSTRAINT `dd_bills_ibfk_1` FOREIGN KEY (`tenant_id`,`branch_id`,`supplier_id`) REFERENCES `mt_dd_suppliers` (`tenant_id`,`branch_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `mt_dd_bills` ADD CONSTRAINT `dd_bills_ibfk_2` FOREIGN KEY (`tenant_id`,`branch_id`,`ledger_id`) REFERENCES `mt_dd_ledger` (`tenant_id`,`branch_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `mt_dd_ledger` ADD CONSTRAINT `dd_ledger_employee` FOREIGN KEY (`tenant_id`,`branch_id`,`employee_id`) REFERENCES `mt_dd_employees` (`tenant_id`,`branch_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `mt_dd_payroll` ADD CONSTRAINT `dd_payroll_ibfk_1` FOREIGN KEY (`tenant_id`,`branch_id`,`employee_id`) REFERENCES `mt_dd_employees` (`tenant_id`,`branch_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `mt_dd_paytm` ADD CONSTRAINT `dd_paytm_ibfk_1` FOREIGN KEY (`tenant_id`,`branch_id`,`ledger_id`) REFERENCES `mt_dd_ledger` (`tenant_id`,`branch_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `mt_dd_paytm` ADD CONSTRAINT `dd_paytm_ibfk_2` FOREIGN KEY (`tenant_id`,`branch_id`,`matched_id`) REFERENCES `mt_dd_ledger` (`tenant_id`,`branch_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `mt_dd_stock_moves` ADD CONSTRAINT `dd_stock_moves_ibfk_1` FOREIGN KEY (`tenant_id`,`branch_id`,`item_id`) REFERENCES `mt_dd_inventory` (`tenant_id`,`branch_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE `mt_f3_designations` ADD CONSTRAINT `f3_desig_dept` FOREIGN KEY (`tenant_id`,`branch_id`,`dept_id`) REFERENCES `mt_f3_departments` (`tenant_id`,`branch_id`,`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
