-- Finance 360 V31 platform schema v3 (applied automatically by p_migrate(); shown for review). $T = ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
CREATE TABLE IF NOT EXISTS p_branch_transfers (id BIGINT AUTO_INCREMENT PRIMARY KEY, uid CHAR(12) NOT NULL UNIQUE, tenant_id INT NOT NULL, from_branch_id INT NOT NULL, to_branch_id INT NOT NULL,
  kind ENUM('stock','cash') NOT NULL, status ENUM('in_transit','received','cancelled') NOT NULL DEFAULT 'in_transit', sent_date DATE NOT NULL, received_date DATE NULL, payload MEDIUMTEXT NOT NULL,
  value_paise BIGINT NOT NULL DEFAULT 0, note VARCHAR(250) NULL, cancel_reason VARCHAR(250) NULL, sent_by_local BIGINT UNSIGNED NULL, sent_by_platform INT NULL, received_by_local BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, received_at DATETIME NULL, KEY k_to (tenant_id, to_branch_id, status), KEY k_from (tenant_id, from_branch_id, status),
  CONSTRAINT fk_bt_from FOREIGN KEY (tenant_id, from_branch_id) REFERENCES p_branches(tenant_id, id), CONSTRAINT fk_bt_to FOREIGN KEY (tenant_id, to_branch_id) REFERENCES p_branches(tenant_id, id),
  CONSTRAINT ck_bt_diff CHECK (from_branch_id <> to_branch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE p_device_tokens ADD COLUMN tenant_id INT NULL, ADD COLUMN branch_id INT NULL, ADD COLUMN last_push_at DATETIME NULL, ADD KEY k_dev_scope (tenant_id, branch_id);

CREATE TABLE IF NOT EXISTS p_push_marks (branch_id INT NOT NULL PRIMARY KEY, last_notification_id BIGINT NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS p_data_requests (id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, kind ENUM('export','deletion') NOT NULL, status ENUM('requested','approved','rejected','done','cancelled') NOT NULL DEFAULT 'requested',
  reason VARCHAR(500) NULL, requested_by INT NOT NULL, decided_by_admin INT NULL, decided_at DATETIME NULL, retain_until DATE NULL, note VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY(tenant_id, kind, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

