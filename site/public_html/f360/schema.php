<?php
declare(strict_types=1);
// Finance 360 V16 — versioned, ADDITIVE migrations. Each step: CREATE TABLE IF NOT EXISTS / ADD COLUMN if missing /
// INSERT IGNORE. No existing row is deleted; existing employee/department values are only copied into NEW columns.
// Version stored in dd_settings.f3_schema. Rollback: see migrations/ (V23: f360_v23_rollback.sql drops only the V23 additions).
const F3_SCHEMA = 13;

function f3_schema_version(): int { try { return (int)setting('f3_schema', '0'); } catch (PDOException $e) { return 0; } }
function f3_ready(): void { if (f3_schema_version() < F3_SCHEMA) fail('Finance 360 database update is pending. Owner: open migrate-f360.php (adds tables/columns only; nothing is deleted).', 503); }

function f3_migrations(): array { return [
 1 => function () {
  $E = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  $t = [
   "f3_departments (id TINYINT UNSIGNED PRIMARY KEY,code VARCHAR(20) NOT NULL UNIQUE,name VARCHAR(60) NOT NULL,name_hi VARCHAR(60) NULL,sort TINYINT NOT NULL)",
   "f3_designations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,dept_id TINYINT UNSIGNED NOT NULL,name VARCHAR(80) NOT NULL,name_hi VARCHAR(80) NULL,sort INT NOT NULL DEFAULT 0,active TINYINT NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_desig(dept_id,name),CONSTRAINT f3_desig_dept FOREIGN KEY(dept_id) REFERENCES f3_departments(id))",
   "f3_salary_rates (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,employee_id BIGINT UNSIGNED NOT NULL,effective_from DATE NOT NULL,monthly BIGINT NOT NULL,basis VARCHAR(20) NOT NULL DEFAULT 'monthly',note VARCHAR(250) NULL,source VARCHAR(20) NOT NULL DEFAULT 'app',created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_rate(employee_id,effective_from),CHECK(monthly>=0))",
   "f3_assignments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,employee_id BIGINT UNSIGNED NOT NULL,dept_id TINYINT UNSIGNED NOT NULL,desig_id BIGINT UNSIGNED NOT NULL,effective_from DATE NOT NULL,note VARCHAR(250) NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(employee_id,effective_from))",
   "f3_user_access (user_id BIGINT UNSIGNED PRIMARY KEY,template VARCHAR(30) NOT NULL,overrides TEXT NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
   "f3_closings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_date DATE NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'draft',revision INT NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1,step TINYINT NOT NULL DEFAULT 1,
     cash_sale BIGINT NULL,card_sale BIGINT NULL,upi_sale BIGINT NULL,total_sale BIGINT NULL,expenses_confirmed VARCHAR(12) NULL,actual_cash BIGINT NULL,diff_reason VARCHAR(500) NULL,
     opening_cash BIGINT NULL,other_cash_in BIGINT NULL,cash_out BIGINT NULL,expenses_total BIGINT NULL,expenses_cash BIGINT NULL,expected_cash BIGINT NULL,accounting_variance BIGINT NULL,operational_diff BIGINT NULL,
     sales_post_status VARCHAR(20) NOT NULL DEFAULT 'none',variance_class VARCHAR(20) NOT NULL DEFAULT 'pending',variance_basis VARCHAR(12) NULL,variance_amount BIGINT NULL,variance_txn_id BIGINT UNSIGNED NULL,review_note VARCHAR(500) NULL,
     snapshot MEDIUMTEXT NULL,created_by BIGINT UNSIGNED NULL,updated_by BIGINT UNSIGNED NULL,submitted_by BIGINT UNSIGNED NULL,submitted_at DATETIME NULL,approved_by BIGINT UNSIGNED NULL,approved_at DATETIME NULL,
     reopened_by BIGINT UNSIGNED NULL,reopened_at DATETIME NULL,reopen_reason VARCHAR(500) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     UNIQUE KEY uq_close_day(business_date),INDEX(status))",
   "f3_closing_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,closing_id BIGINT UNSIGNED NOT NULL,business_date DATE NOT NULL,action VARCHAR(30) NOT NULL,revision INT NOT NULL,note VARCHAR(500) NULL,snapshot MEDIUMTEXT NULL,user_id BIGINT UNSIGNED NULL,at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(closing_id),INDEX(business_date))",
   "f3_expense_meta (txn_id BIGINT UNSIGNED PRIMARY KEY,business_date DATE NOT NULL,file_id BIGINT UNSIGNED NULL,review_status VARCHAR(12) NOT NULL DEFAULT 'pending',review_note VARCHAR(250) NULL,reviewed_by BIGINT UNSIGNED NULL,reviewed_at DATETIME NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(business_date),INDEX(review_status))",
   "f3_staff_txns (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,txn_no VARCHAR(30) NOT NULL UNIQUE,employee_id BIGINT UNSIGNED NOT NULL,kind VARCHAR(24) NOT NULL,date DATE NOT NULL,amount BIGINT NOT NULL,mode VARCHAR(10) NULL,
     status VARCHAR(20) NOT NULL,reason VARCHAR(500) NOT NULL,reference VARCHAR(120) NULL,hours DECIMAL(6,2) NULL,installment BIGINT NULL,file_id BIGINT UNSIGNED NULL,period CHAR(7) NULL,applied_period CHAR(7) NULL,bk_txn_id BIGINT UNSIGNED NULL,
     reverses_id BIGINT UNSIGNED NULL,client_key VARCHAR(64) NULL UNIQUE,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,decided_by BIGINT UNSIGNED NULL,decided_at DATETIME NULL,decision_note VARCHAR(250) NULL,
     INDEX(employee_id,kind,status),INDEX(date),INDEX(applied_period),CHECK(amount>0))",
   "f3_pay_periods (period CHAR(7) PRIMARY KEY,status VARCHAR(16) NOT NULL DEFAULT 'draft',days TINYINT NOT NULL,divisor TINYINT NOT NULL,divisor_mode VARCHAR(12) NOT NULL,version INT NOT NULL DEFAULT 1,
     created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,submitted_by BIGINT UNSIGNED NULL,submitted_at DATETIME NULL,approved_by BIGINT UNSIGNED NULL,approved_at DATETIME NULL,locked_by BIGINT UNSIGNED NULL,locked_at DATETIME NULL,
     unlocked_by BIGINT UNSIGNED NULL,unlocked_at DATETIME NULL,unlock_reason VARCHAR(500) NULL,snapshot MEDIUMTEXT NULL)",
   "f3_pay_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,period CHAR(7) NOT NULL,employee_id BIGINT UNSIGNED NOT NULL,state VARCHAR(12) NOT NULL DEFAULT 'pending',version INT NOT NULL DEFAULT 1,
     emp_name VARCHAR(160) NOT NULL,emp_code VARCHAR(40) NULL,dept_id TINYINT UNSIGNED NULL,desig_id BIGINT UNSIGNED NULL,desig_name VARCHAR(80) NULL,monthly BIGINT NOT NULL DEFAULT 0,eligible_days DECIMAL(5,2) NOT NULL DEFAULT 0,
     present DECIMAL(5,2) NULL,half_days DECIMAL(5,2) NULL,leave_taken DECIMAL(5,2) NULL,paid_leave_allowed DECIMAL(5,2) NULL,other_paid_leave DECIMAL(5,2) NULL,
     unpaid_leave DECIMAL(5,2) NOT NULL DEFAULT 0,payable_days DECIMAL(5,2) NOT NULL DEFAULT 0,gross_base BIGINT NOT NULL DEFAULT 0,additions BIGINT NOT NULL DEFAULT 0,other_earnings BIGINT NOT NULL DEFAULT 0,gross_earned BIGINT NOT NULL DEFAULT 0,
     penalties BIGINT NOT NULL DEFAULT 0,advance_recovery BIGINT NOT NULL DEFAULT 0,other_deductions BIGINT NOT NULL DEFAULT 0,net_payable BIGINT NOT NULL DEFAULT 0,ot_paid_info BIGINT NOT NULL DEFAULT 0,carried_forward BIGINT NOT NULL DEFAULT 0,
     cf_source VARCHAR(20) NULL,adjust_note VARCHAR(500) NULL,skip_reason VARCHAR(250) NULL,calc MEDIUMTEXT NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     UNIQUE KEY uq_pay_line(period,employee_id),INDEX(employee_id))",
   "f3_pay_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,pay_no VARCHAR(30) NOT NULL UNIQUE,period CHAR(7) NOT NULL,employee_id BIGINT UNSIGNED NOT NULL,date DATE NOT NULL,amount BIGINT NOT NULL,mode VARCHAR(10) NOT NULL,reference VARCHAR(120) NULL,
     bk_txn_id BIGINT UNSIGNED NULL,voided TINYINT NOT NULL DEFAULT 0,void_reason VARCHAR(250) NULL,client_key VARCHAR(64) NULL UNIQUE,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(period,employee_id),CHECK(amount>0))",
   "f3_notifications (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(30) NOT NULL,dedupe_key VARCHAR(120) NOT NULL UNIQUE,title VARCHAR(200) NOT NULL,body TEXT NULL,perm VARCHAR(40) NOT NULL,ref VARCHAR(120) NULL,status VARCHAR(10) NOT NULL DEFAULT 'open',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,done_at DATETIME NULL,INDEX(status,perm))",
   "f3_notif_user (notification_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NOT NULL,snooze_until DATETIME NULL,seen_at DATETIME NULL,PRIMARY KEY(notification_id,user_id))",
   "f3_corrections (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(20) NOT NULL,business_date DATE NULL,ref VARCHAR(120) NULL,reason VARCHAR(1000) NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'open',requested_by BIGINT UNSIGNED NOT NULL,requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,decided_by BIGINT UNSIGNED NULL,decided_at DATETIME NULL,decision_note VARCHAR(500) NULL,INDEX(status))",
   "f3_sequences (name VARCHAR(40) PRIMARY KEY,value BIGINT NOT NULL DEFAULT 0)",
  ];
  foreach ($t as $sql) db()->exec('CREATE TABLE IF NOT EXISTS ' . preg_replace('/\s+/', ' ', $sql) . $E);
  foreach (F3_DEPTS as $id => [$n, $h]) run('INSERT IGNORE INTO f3_departments(id,code,name,name_hi,sort) VALUES(?,?,?,?,?)', $id, strtolower($n), $n, $h, $id);
  $seed = [1 => ['Manager', 'Supervisor', 'Cashier'], 2 => ['UT Staff'], 3 => ['Chef', 'Cook', 'Kitchen Helper'], 4 => ['Captain', 'Waiter', 'Guard']];
  foreach ($seed as $d => $list) foreach ($list as $i => $n) run('INSERT IGNORE INTO f3_designations(dept_id,name,sort) VALUES(?,?,?)', $d, $n, $i + 1);
  // Employee master: new columns only.
  foreach (['f3_dept_id' => 'TINYINT UNSIGNED NULL', 'f3_desig_id' => 'BIGINT UNSIGNED NULL', 'legal_name' => 'VARCHAR(160) NULL', 'address' => 'VARCHAR(400) NULL', 'photo_file_id' => 'BIGINT UNSIGNED NULL',
            'bank_account_enc' => 'TEXT NULL', 'upi_id' => 'VARCHAR(80) NULL', 'notes' => 'TEXT NULL', 'documents' => 'TEXT NULL', 'exit_reason' => 'VARCHAR(250) NULL', 'updated_at' => 'DATETIME NULL',
            'code' => 'VARCHAR(40) NULL', 'section' => 'VARCHAR(40) NULL', 'designation' => 'VARCHAR(80) NULL', 'department' => 'VARCHAR(80) NULL', 'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'", 'joined_on' => 'DATE NULL', 'left_on' => 'DATE NULL',
            'phone' => 'VARCHAR(20) NULL', 'pay_mode' => 'VARCHAR(40) NULL', 'bank_name' => 'VARCHAR(80) NULL', 'ifsc' => 'VARCHAR(20) NULL', 'account_last4' => 'VARCHAR(4) NULL'] as $c => $d) mig_add_column('dd_employees', $c, $d);
  f3_map_legacy_staff();
  foreach (rows('SELECT e.id,e.salary,e.pay_basis,e.joined_on FROM dd_employees e WHERE NOT EXISTS (SELECT 1 FROM f3_salary_rates r WHERE r.employee_id=e.id)') as $e)
      run("INSERT IGNORE INTO f3_salary_rates(employee_id,effective_from,monthly,basis,note,source) VALUES(?,?,?,?,?,'migration')", $e['id'], $e['joined_on'] ?: '2000-01-01', max(0, (int)$e['salary']), $e['pay_basis'] ?: 'monthly', 'Copied from existing employee master at V16 upgrade');
  foreach (F3_SETTING_DEFAULTS as $k => $v) run('INSERT IGNORE INTO dd_settings(`key`,value) VALUES(?,?)', $k, $v);
  run("UPDATE dd_settings SET value=? WHERE `key`='f3_closing_start' AND value=''", f3_target_date());
  run("UPDATE dd_settings SET value=? WHERE `key`='f3_payroll_start' AND value=''", f3_prev_month(substr(f3_business_date(), 0, 7)));
  // Books: new expense category for recoverable staff advances (NOT an expense) — added to the existing JSON, nothing removed.
  if (function_exists('bk_installed') && bk_installed()) {
      $cats = bk_categories();
      foreach (['Staff Advance (recoverable)' => 'Staff advance (not expense)', 'Overtime Wages' => 'Staff', 'Salary - Current Month' => 'Staff', 'Salary - Previous Month' => 'Staff', 'Other Staff Sallery' => 'Staff'] as $c => $g) if (!isset($cats[$c])) $cats[$c] = $g;
      set_setting('bk_categories', json_encode($cats, JSON_UNESCAPED_UNICODE));
  }
 },
 2 => function () {
  mig_add_index('f3_staff_txns', 'idx_f3_st_period', 'INDEX idx_f3_st_period(employee_id,applied_period)');
  mig_add_index('dd_bk_txns', 'idx_bk_source', 'INDEX idx_bk_source(source,date)');
 },
 3 => function () { // Leave-encash policy is frozen per salary month (like the divisor) so later setting changes never alter a sheet.
  mig_add_column('f3_pay_periods', 'leave_encash', 'TINYINT NULL');
  run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('f3_leave_encash','1')");
 },
 4 => function () { // V17: Purchases, Suppliers, Item master, one stock ledger (Books 360 parties/items stay canonical; new fields are additive)
  $E = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  foreach (['code' => 'VARCHAR(30) NULL', 'legal_name' => 'VARCHAR(160) NULL', 'contact_person' => 'VARCHAR(120) NULL', 'whatsapp' => 'VARCHAR(20) NULL', 'phone2' => 'VARCHAR(20) NULL', 'email' => 'VARCHAR(160) NULL',
            'address' => 'VARCHAR(400) NULL', 'city' => 'VARCHAR(80) NULL', 'state' => 'VARCHAR(80) NULL', 'pin' => 'VARCHAR(10) NULL', 'pan_enc' => 'TEXT NULL', 'bank_name' => 'VARCHAR(80) NULL', 'bank_account_enc' => 'TEXT NULL',
            'bank_last4' => 'VARCHAR(4) NULL', 'ifsc' => 'VARCHAR(20) NULL', 'upi_id' => 'VARCHAR(80) NULL', 'opening_as_of' => 'DATE NULL', 'credit_limit' => 'BIGINT NULL', 'default_terms' => 'VARCHAR(120) NULL',
            'default_category' => 'VARCHAR(80) NULL', 'photo_file_id' => 'BIGINT UNSIGNED NULL', 'crm_supplier_id' => 'BIGINT UNSIGNED NULL', 'updated_at' => 'DATETIME NULL'] as $c => $d) mig_add_column('dd_bk_parties', $c, $d);
  mig_add_index('dd_bk_parties', 'idx_bkp_kind', 'INDEX idx_bkp_kind(kind,active)');
  foreach (['sku' => 'VARCHAR(40) NULL', 'barcode' => 'VARCHAR(60) NULL', 'subcategory' => 'VARCHAR(80) NULL', 'brand' => 'VARCHAR(80) NULL', 'description' => 'VARCHAR(500) NULL', 'photo_file_id' => 'BIGINT UNSIGNED NULL',
            'purchase_unit' => 'VARCHAR(20) NULL', 'min_level' => 'DECIMAL(18,3) NOT NULL DEFAULT 0', 'max_level' => 'DECIMAL(18,3) NULL', 'location' => "VARCHAR(60) NOT NULL DEFAULT 'Main Store'",
            'default_supplier_id' => 'BIGINT UNSIGNED NULL', 'tax_rate_bp' => 'INT NOT NULL DEFAULT 0', 'opening_date' => 'DATE NULL', 'crm_item_id' => 'BIGINT UNSIGNED NULL', 'updated_at' => 'DATETIME NULL'] as $c => $d) mig_add_column('dd_bk_items', $c, $d);
  mig_add_index('dd_bk_items', 'idx_bki_code', 'INDEX idx_bki_code(code)'); mig_add_index('dd_bk_items', 'idx_bki_barcode', 'INDEX idx_bki_barcode(barcode)'); mig_add_index('dd_bk_items', 'idx_bki_cat', 'INDEX idx_bki_cat(category,active)');
  $t = [
   "f3_item_units (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED NOT NULL,unit VARCHAR(20) NOT NULL,factor DECIMAL(18,6) NOT NULL,note VARCHAR(120) NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_iu(item_id,unit),CHECK(factor>0))",
   "f3_stock_balances (item_id BIGINT UNSIGNED NOT NULL,location VARCHAR(60) NOT NULL,qty DECIMAL(18,3) NOT NULL DEFAULT 0,value BIGINT NOT NULL DEFAULT 0,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(item_id,location))",
   "f3_stock_moves (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,move_no VARCHAR(20) NOT NULL UNIQUE,item_id BIGINT UNSIGNED NOT NULL,location VARCHAR(60) NOT NULL,date DATE NOT NULL,kind VARCHAR(16) NOT NULL,qty DECIMAL(18,3) NOT NULL,entered_qty DECIMAL(18,3) NULL,entered_unit VARCHAR(20) NULL,factor DECIMAL(18,6) NULL,
     value BIGINT NOT NULL,qty_after DECIMAL(18,3) NOT NULL,value_after BIGINT NOT NULL,source_type VARCHAR(16) NOT NULL,source_id BIGINT UNSIGNED NULL,source_line_id BIGINT UNSIGNED NULL,reason VARCHAR(250) NULL,cost_center VARCHAR(60) NULL,
     reverses_id BIGINT UNSIGNED NULL,reversed_by BIGINT UNSIGNED NULL,idem_key VARCHAR(80) NOT NULL UNIQUE,created_by BIGINT UNSIGNED NULL,approved_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     INDEX(item_id,date),INDEX(date),INDEX(source_type,source_id),INDEX(kind,date))",
   "f3_purchase_bills (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bill_no VARCHAR(20) NOT NULL UNIQUE,supplier_id BIGINT UNSIGNED NULL,invoice_no VARCHAR(60) NULL,invoice_key VARCHAR(60) NULL,invoice_no_missing TINYINT NOT NULL DEFAULT 0,invoice_date DATE NULL,receipt_date DATE NULL,due_date DATE NULL,terms VARCHAR(120) NULL,
     location VARCHAR(60) NOT NULL DEFAULT 'Main Store',challan_no VARCHAR(60) NULL,reference VARCHAR(120) NULL,gstin VARCHAR(20) NULL,tax_invoice TINYINT NOT NULL DEFAULT 0,notes VARCHAR(1000) NULL,
     subtotal BIGINT NOT NULL DEFAULT 0,line_discount BIGINT NOT NULL DEFAULT 0,line_tax BIGINT NOT NULL DEFAULT 0,bill_discount BIGINT NOT NULL DEFAULT 0,transport BIGINT NOT NULL DEFAULT 0,other_tax BIGINT NOT NULL DEFAULT 0,rounding BIGINT NOT NULL DEFAULT 0,grand_total BIGINT NOT NULL DEFAULT 0,
     status VARCHAR(12) NOT NULL DEFAULT 'draft',receipt_status VARCHAR(12) NOT NULL DEFAULT 'pending',post_date DATE NULL,bk_txn_id BIGINT UNSIGNED NULL,dup_reason VARCHAR(250) NULL,category VARCHAR(80) NULL,submitted_by BIGINT UNSIGNED NULL,approved_by BIGINT UNSIGNED NULL,approved_at DATETIME NULL,step TINYINT NOT NULL DEFAULT 1,version INT NOT NULL DEFAULT 1,
     created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,posted_by BIGINT UNSIGNED NULL,posted_at DATETIME NULL,
     reversed_by BIGINT UNSIGNED NULL,reversed_at DATETIME NULL,reverse_reason VARCHAR(500) NULL,INDEX(supplier_id,status),INDEX(status,receipt_status),INDEX(invoice_key),INDEX(post_date))",
   "f3_purchase_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bill_id BIGINT UNSIGNED NOT NULL,sort INT NOT NULL DEFAULT 0,item_id BIGINT UNSIGNED NULL,item_name VARCHAR(160) NOT NULL,unit VARCHAR(20) NOT NULL,factor DECIMAL(18,6) NOT NULL DEFAULT 1,base_unit VARCHAR(20) NULL,
     qty DECIMAL(18,3) NOT NULL,base_qty DECIMAL(18,3) NOT NULL,rate BIGINT NOT NULL DEFAULT 0,gross BIGINT NOT NULL DEFAULT 0,disc_type VARCHAR(4) NOT NULL DEFAULT 'amt',disc_value BIGINT NOT NULL DEFAULT 0,discount BIGINT NOT NULL DEFAULT 0,tax_rate_bp INT NOT NULL DEFAULT 0,tax BIGINT NOT NULL DEFAULT 0,line_total BIGINT NOT NULL DEFAULT 0,
     batch VARCHAR(60) NULL,expiry DATE NULL,mrp BIGINT NULL,notes VARCHAR(250) NULL,photo_file_id BIGINT UNSIGNED NULL,cost_total BIGINT NOT NULL DEFAULT 0,received_base DECIMAL(18,3) NOT NULL DEFAULT 0,returned_base DECIMAL(18,3) NOT NULL DEFAULT 0,INDEX(bill_id),INDEX(item_id))",
   "f3_bill_files (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bill_id BIGINT UNSIGNED NOT NULL,file_id BIGINT UNSIGNED NOT NULL,kind VARCHAR(10) NOT NULL DEFAULT 'bill',sort INT NOT NULL DEFAULT 0,removed TINYINT NOT NULL DEFAULT 0,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_bf(bill_id,file_id),INDEX(bill_id))",
   "f3_receipts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,receipt_no VARCHAR(20) NOT NULL UNIQUE,bill_id BIGINT UNSIGNED NOT NULL,date DATE NOT NULL,note VARCHAR(250) NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(bill_id))",
   "f3_supplier_allocations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,payment_txn_id BIGINT UNSIGNED NOT NULL,bill_id BIGINT UNSIGNED NOT NULL,amount BIGINT NOT NULL,voided TINYINT NOT NULL DEFAULT 0,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_alloc(payment_txn_id,bill_id),INDEX(bill_id),CHECK(amount>0))",
   "f3_supplier_payments (txn_id BIGINT UNSIGNED PRIMARY KEY,supplier_id BIGINT UNSIGNED NOT NULL,pay_no VARCHAR(20) NOT NULL UNIQUE,kind VARCHAR(12) NOT NULL DEFAULT 'payment',reference VARCHAR(120) NULL,file_id BIGINT UNSIGNED NULL,client_key VARCHAR(64) NULL UNIQUE,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(supplier_id))",
   "f3_purchase_returns (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,return_no VARCHAR(20) NOT NULL UNIQUE,kind VARCHAR(12) NOT NULL DEFAULT 'return',bill_id BIGINT UNSIGNED NULL,supplier_id BIGINT UNSIGNED NOT NULL,date DATE NOT NULL,reason VARCHAR(500) NOT NULL,total BIGINT NOT NULL,refund BIGINT NOT NULL DEFAULT 0,refund_mode VARCHAR(10) NULL,
     bk_txn_id BIGINT UNSIGNED NULL,client_key VARCHAR(64) NULL UNIQUE,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(supplier_id),INDEX(bill_id),CHECK(total>0))",
   "f3_purchase_return_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,return_id BIGINT UNSIGNED NOT NULL,bill_line_id BIGINT UNSIGNED NULL,item_id BIGINT UNSIGNED NOT NULL,base_qty DECIMAL(18,3) NOT NULL,amount BIGINT NOT NULL,move_id BIGINT UNSIGNED NULL,INDEX(return_id))",
   "f3_stock_counts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,count_no VARCHAR(20) NOT NULL UNIQUE,location VARCHAR(60) NOT NULL,date DATE NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'open',scope VARCHAR(80) NULL,note VARCHAR(250) NULL,started_by BIGINT UNSIGNED NULL,submitted_by BIGINT UNSIGNED NULL,submitted_at DATETIME NULL,
     approved_by BIGINT UNSIGNED NULL,approved_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(status))",
   "f3_stock_count_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,count_id BIGINT UNSIGNED NOT NULL,item_id BIGINT UNSIGNED NOT NULL,expected DECIMAL(18,3) NOT NULL,counted DECIMAL(18,3) NULL,reason VARCHAR(250) NULL,counted_by BIGINT UNSIGNED NULL,counted_at DATETIME NULL,move_id BIGINT UNSIGNED NULL,UNIQUE KEY uq_cl(count_id,item_id))",
  ];
  foreach ($t as $sql) db()->exec('CREATE TABLE IF NOT EXISTS ' . preg_replace('/\s+/', ' ', $sql) . $E);
  mig_add_column('f3_user_access', 'limits', 'TEXT NULL');
  foreach (['f3_stock_locations' => json_encode(['Main Store']), 'f3_negative_stock' => '0', 'f3_tax_in_cost' => '1', 'f3_purchase_approval_above' => '0', 'f3_inventory_model' => 'periodic'] as $k => $v) run('INSERT IGNORE INTO dd_settings(`key`,value) VALUES(?,?)', $k, $v);
  // Cut-over: from today Finance 360 is the one stock ledger. Opening stock per item = the existing Books 360 stock (quantity × last purchase rate) — copied once, never re-run.
  $cut = f3_business_date(); run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('f3_stock_cutover',?)", $cut); $cut = setting('f3_stock_cutover', $cut);
  if (function_exists('bk_stock') && !row("SELECT 1 FROM f3_stock_moves WHERE source_type='legacy' LIMIT 1")) {
   foreach (bk_stock(next_date($cut, -1)) as $i) { if (!$i['is_stock'] || !$i['active']) continue; $q = round((float)$i['closing'], 3); if ($q <= 0) continue;
    $val = (int)$i['value']; $no = f3_seq('stockmove', 'SM');
    run("INSERT INTO f3_stock_moves(move_no,item_id,location,date,kind,qty,entered_qty,entered_unit,factor,value,qty_after,value_after,source_type,reason,idem_key) VALUES(?,?,?,?,'opening',?,?,?,1,?,?,?,'legacy',?,?)",
        $no, $i['id'], 'Main Store', next_date($cut, -1), number_format($q, 3, '.', ''), number_format($q, 3, '.', ''), $i['unit'], $val, number_format($q, 3, '.', ''), $val, 'Opening from Books 360 stock at Finance 360 cut-over (qty × last purchase rate)', 'open:legacy:' . $i['id']);
    run('INSERT INTO f3_stock_balances(item_id,location,qty,value) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE qty=VALUES(qty),value=VALUES(value)', $i['id'], 'Main Store', number_format($q, 3, '.', ''), $val); } }
  // Link CRM-module suppliers / items to the canonical Books masters by name (read-only adapter; nothing is merged or deleted).
  try { foreach (rows('SELECT id,name FROM dd_suppliers') as $sp) run('UPDATE dd_bk_parties SET crm_supplier_id=? WHERE name_key=? AND crm_supplier_id IS NULL', $sp['id'], bk_key($sp['name'])); } catch (PDOException $e) {}
  try { foreach (rows('SELECT id,name FROM dd_inventory') as $it) run('UPDATE dd_bk_items SET crm_item_id=? WHERE name_key=? AND crm_item_id IS NULL', $it['id'], bk_key($it['name'])); } catch (PDOException $e) {}
  foreach (rows("SELECT id FROM dd_bk_parties WHERE kind='supplier' AND code IS NULL ORDER BY id") as $sp) run('UPDATE dd_bk_parties SET code=? WHERE id=?', 'SUP' . str_pad((string)$sp['id'], 4, '0', STR_PAD_LEFT), $sp['id']);
 },
 6 => function () { // V19 payroll: six-field entry (present / absent / half / paid leave + advance / penalty), actual calendar-day divisor, chosen penalties, autosave stamp
  foreach (['absent' => 'DECIMAL(5,2) NULL', 'paid_leave' => 'DECIMAL(5,2) NULL', 'rest_days' => 'DECIMAL(5,2) NOT NULL DEFAULT 0', 'penalty_ids' => 'TEXT NULL', 'saved_at' => 'DATETIME NULL'] as $c => $d) mig_add_column('f3_pay_lines', $c, $d);
  mig_add_column('f3_pay_periods', 'calc_mode', "VARCHAR(10) NOT NULL DEFAULT 'v16'");
  run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('f3_rest_days_paid','1')");
  run("UPDATE dd_settings SET value='calendar' WHERE `key`='f3_payroll_divisor'");
  // Months still being prepared move to the V19 rule. Approved / locked months keep the rule they were approved with (history never changes).
  foreach (rows("SELECT * FROM f3_pay_periods WHERE status IN ('draft','review')") as $per) {
   run("UPDATE f3_pay_periods SET calc_mode='v19',divisor=days,divisor_mode='calendar',leave_encash=0,status='draft' WHERE period=?", $per['period']);
   foreach (rows('SELECT * FROM f3_pay_lines WHERE period=?', $per['period']) as $l) {
    if ($l['present'] === null) continue;
    $pl = min((float)$l['leave_taken'], (float)$l['paid_leave_allowed']) + (float)$l['other_paid_leave'];
    $ab = max(0, (float)$l['eligible_days'] - (float)$l['present'] - (float)$l['half_days'] - (float)$l['leave_taken'] - (float)$l['other_paid_leave']) + max(0, (float)$l['leave_taken'] - (float)$l['paid_leave_allowed']);
    run("UPDATE f3_pay_lines SET paid_leave=?,absent=?,state=IF(state='done','draft',state),adjust_note=CONCAT_WS(' · ',adjust_note,'V19: please review (actual-calendar-day rule)') WHERE id=?", number_format($pl, 2, '.', ''), number_format($ab, 2, '.', ''), $l['id']);
   }
  }
 },
 5 => function () { // V17.1: old modules archived (owner read-only). Reversible from Settings; no data touched.
  run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('legacy_archived','1')");
 },
 7 => function () { // V20 — Google Drive + AiSensy WhatsApp integrations. New tables only; credentials are stored AES-256-GCM encrypted (pii_key).
  $E = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  foreach ([
   "f3_int_secrets (name VARCHAR(40) PRIMARY KEY,value_enc TEXT NOT NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
   "f3_int_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,service VARCHAR(10) NOT NULL,event VARCHAR(40) NOT NULL,level VARCHAR(8) NOT NULL DEFAULT 'info',message VARCHAR(500) NOT NULL,ref VARCHAR(120) NULL,user_id BIGINT UNSIGNED NULL,at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(service,at),INDEX(level,id))",
   "f3_drive_folders (path_hash CHAR(64) PRIMARY KEY,path VARCHAR(500) NOT NULL,drive_id VARCHAR(120) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
   "f3_drive_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_key VARCHAR(160) NOT NULL UNIQUE,kind VARCHAR(20) NOT NULL,ref VARCHAR(120) NULL,file_id BIGINT UNSIGNED NULL,local_path VARCHAR(300) NULL,name VARCHAR(250) NOT NULL,folder VARCHAR(400) NOT NULL,
     mime VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',is_sensitive TINYINT NOT NULL DEFAULT 0,encrypted TINYINT NOT NULL DEFAULT 0,sha256 CHAR(64) NULL,size BIGINT NULL,status VARCHAR(12) NOT NULL DEFAULT 'queued',attempts INT NOT NULL DEFAULT 0,next_try_at DATETIME NULL,
     last_error VARCHAR(500) NULL,drive_file_id VARCHAR(120) NULL,web_link VARCHAR(300) NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,uploaded_at DATETIME NULL,verified_at DATETIME NULL,
     INDEX(status,next_try_at),INDEX(kind,created_at),INDEX(sha256))",
   "f3_wa_recipients (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(80) NOT NULL,phone_enc TEXT NOT NULL,phone_hash CHAR(64) NOT NULL UNIQUE,phone_last4 CHAR(4) NOT NULL,role VARCHAR(12) NOT NULL DEFAULT 'manager',employee_id BIGINT UNSIGNED NULL,supplier_id BIGINT UNSIGNED NULL,
     events TEXT NULL,consent TINYINT NOT NULL DEFAULT 0,consent_source VARCHAR(120) NULL,consent_at DATETIME NULL,consent_by BIGINT UNSIGNED NULL,opted_out_at DATETIME NULL,opt_out_source VARCHAR(40) NULL,active TINYINT NOT NULL DEFAULT 1,
     created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(employee_id),INDEX(supplier_id))",
   "f3_wa_messages (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,idem_key VARCHAR(160) NOT NULL UNIQUE,event VARCHAR(30) NOT NULL,recipient_id BIGINT UNSIGNED NOT NULL,ref VARCHAR(120) NULL,campaign VARCHAR(120) NULL,params_enc TEXT NULL,summary VARCHAR(200) NULL,
     status VARCHAR(12) NOT NULL DEFAULT 'queued',attempts INT NOT NULL DEFAULT 0,next_try_at DATETIME NULL,provider_id VARCHAR(120) NULL,last_error VARCHAR(500) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     sent_at DATETIME NULL,delivered_at DATETIME NULL,read_at DATETIME NULL,failed_at DATETIME NULL,INDEX(status,next_try_at),INDEX(recipient_id,created_at),INDEX(provider_id))",
   "f3_wa_webhooks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,body_hash CHAR(64) NOT NULL UNIQUE,sig_ok TINYINT NULL,statuses INT NOT NULL DEFAULT 0,matched INT NOT NULL DEFAULT 0,inbound INT NOT NULL DEFAULT 0,note VARCHAR(250) NULL,received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  ] as $t) run('CREATE TABLE IF NOT EXISTS ' . $t . $E);
  run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('f3_drive_cfg','{}'),('f3_wa_cfg','{}')");
 },
 8 => function () { // V21 — scheduler health log; multiple attachments per expense (PDF / several photos). New tables only.
  $E = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  run("CREATE TABLE IF NOT EXISTS f3_job_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job VARCHAR(30) NOT NULL,status VARCHAR(10) NOT NULL,message VARCHAR(500) NULL,items INT NOT NULL DEFAULT 0,started_at DATETIME NOT NULL,finished_at DATETIME NULL,INDEX(job,id),INDEX(status,started_at))" . $E);
  run("CREATE TABLE IF NOT EXISTS f3_expense_files (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,txn_id BIGINT UNSIGNED NOT NULL,file_id BIGINT UNSIGNED NOT NULL,sort INT NOT NULL DEFAULT 0,removed TINYINT NOT NULL DEFAULT 0,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_ef(txn_id,file_id),INDEX(txn_id))" . $E);
  run("INSERT IGNORE INTO f3_expense_files(txn_id,file_id,sort) SELECT txn_id,file_id,0 FROM f3_expense_meta WHERE file_id IS NOT NULL");
 },
 9 => function () { // One-time employee master import (batch, per-row before snapshots for rollback, external-id links). New tables only.
  $E = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  run("CREATE TABLE IF NOT EXISTS f3_import_batches (batch_id VARCHAR(40) PRIMARY KEY,kind VARCHAR(20) NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'preview',files TEXT NOT NULL,decisions MEDIUMTEXT NULL,summary MEDIUMTEXT NULL,before_counts TEXT NULL,after_counts TEXT NULL,
     created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,applied_by BIGINT UNSIGNED NULL,applied_at DATETIME NULL,backup_name VARCHAR(160) NULL,rolled_back_by BIGINT UNSIGNED NULL,rolled_back_at DATETIME NULL,rollback_note VARCHAR(500) NULL)" . $E);
  run("CREATE TABLE IF NOT EXISTS f3_import_rows (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,batch_id VARCHAR(40) NOT NULL,row_key VARCHAR(60) NOT NULL,action VARCHAR(12) NOT NULL,employee_id BIGINT UNSIGNED NULL,
     before_json MEDIUMTEXT NULL,after_json MEDIUMTEXT NULL,created_rate_ids TEXT NULL,created_assignment_ids TEXT NULL,created_ext_ids TEXT NULL,note VARCHAR(500) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_ir(batch_id,row_key),INDEX(employee_id))" . $E);
  run("CREATE TABLE IF NOT EXISTS f3_ext_ids (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source VARCHAR(20) NOT NULL,ext_key VARCHAR(80) NOT NULL,employee_id BIGINT UNSIGNED NOT NULL,batch_id VARCHAR(40) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_ext(source,ext_key),INDEX(employee_id))" . $E);
 },
 11 => function () { // One text collation for all app tables: mixed collations (old tables vs newer ones) make text comparisons fail with error 1267 on some hosts. Data unchanged.
  $r = f3_fix_collations(); if ($r['failed']) fail('Could not align the text setting (collation) of: ' . implode('; ', $r['failed']) . '. Nothing else was changed; send this message to support.', 500);
 },
 10 => function () { // Old salary months kept as a read-only record (one-click import). Never used for salary, advances, carry-forward or the cash book. New table only.
  $E = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  run("CREATE TABLE IF NOT EXISTS f3_sal_history (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,batch_id VARCHAR(40) NOT NULL,period CHAR(7) NOT NULL,sheet VARCHAR(40) NOT NULL,sheet_row INT NOT NULL,section VARCHAR(20) NULL,sno INT NULL,name VARCHAR(120) NOT NULL,
     hr_code VARCHAR(20) NULL,employee_id BIGINT UNSIGNED NULL,designation VARCHAR(60) NULL,monthly BIGINT NULL,present DECIMAL(6,2) NULL,leave_days DECIMAL(6,2) NULL,working_days DECIMAL(6,2) NULL,total_salary BIGINT NULL,advance BIGINT NULL,debt BIGINT NULL,
     net_salary BIGINT NULL,pay_mode VARCHAR(30) NULL,paid BIGINT NULL,balance BIGINT NULL,pay_status VARCHAR(30) NULL,superseded_by VARCHAR(40) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     UNIQUE KEY uq_sh(batch_id,period,sheet_row),INDEX(period,superseded_by),INDEX(employee_id))" . $E);
  // exact order of applied imports (two applies in the same second must still undo in the right order)
  if (!row("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='f3_import_batches' AND COLUMN_NAME='applied_seq'")) run('ALTER TABLE f3_import_batches ADD COLUMN applied_seq BIGINT UNSIGNED NULL');
 },
 12 => function () { // V23 — post-count-safe stock counts, import decision fingerprints, Cash Handover, Document inbox, salary acknowledgement,
  // financial exception lifecycle, item lead time. New tables + NULLable columns only; no existing row is changed or deleted.
  $E = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
  $col = function (string $t, string $c, string $def) { if (!row('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', $t, $c)) run("ALTER TABLE `$t` ADD COLUMN `$c` $def"); };
  $col('f3_stock_counts', 'start_move_id', 'BIGINT UNSIGNED NULL');
  $col('f3_stock_count_lines', 'book_at_count', 'DECIMAL(18,3) NULL'); $col('f3_stock_count_lines', 'move_id_at_count', 'BIGINT UNSIGNED NULL');
  $col('f3_stock_count_lines', 'approved_expected', 'DECIMAL(18,3) NULL'); $col('f3_stock_count_lines', 'post_count_qty', 'DECIMAL(18,3) NULL');
  $col('f3_import_batches', 'decision_fps', 'MEDIUMTEXT NULL');
  $col('dd_bk_items', 'f3_lead_days', 'INT NULL');
  run("CREATE TABLE IF NOT EXISTS f3_handovers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,ho_no VARCHAR(20) NOT NULL UNIQUE,date DATE NOT NULL,from_user BIGINT UNSIGNED NOT NULL,to_user BIGINT UNSIGNED NOT NULL,from_account VARCHAR(40) NOT NULL,to_account VARCHAR(40) NULL,
     amount BIGINT NOT NULL,accepted_amount BIGINT NULL,denominations TEXT NULL,note VARCHAR(250) NULL,status VARCHAR(12) NOT NULL DEFAULT 'sent',response_note VARCHAR(250) NULL,bk_txn_id BIGINT UNSIGNED NULL,client_key VARCHAR(64) NOT NULL UNIQUE,
     responded_by BIGINT UNSIGNED NULL,responded_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(status,date),INDEX(to_user,status),INDEX(from_user))" . $E);
  run("CREATE TABLE IF NOT EXISTS f3_doc_inbox (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,file_id BIGINT UNSIGNED NOT NULL,kind_guess VARCHAR(12) NOT NULL DEFAULT 'other',status VARCHAR(14) NOT NULL DEFAULT 'unclassified',linked_type VARCHAR(10) NULL,linked_id BIGINT UNSIGNED NULL,
     note VARCHAR(250) NULL,duplicate_of BIGINT UNSIGNED NULL,duplicate_note VARCHAR(160) NULL,uploaded_by BIGINT UNSIGNED NULL,decided_by BIGINT UNSIGNED NULL,decided_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(status,id),INDEX(file_id))" . $E);
  run("CREATE TABLE IF NOT EXISTS f3_pay_acks (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,period CHAR(7) NOT NULL,employee_id BIGINT UNSIGNED NOT NULL,payment_id BIGINT UNSIGNED NULL,status VARCHAR(14) NOT NULL,method VARCHAR(12) NOT NULL DEFAULT 'other',note VARCHAR(500) NULL,
     amount_claimed BIGINT NULL,recorded_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,resolution VARCHAR(500) NULL,resolved_by BIGINT UNSIGNED NULL,resolved_at DATETIME NULL,UNIQUE KEY uq_pa(payment_id),INDEX(period,status),INDEX(employee_id))" . $E);
  run("CREATE TABLE IF NOT EXISTS f3_exceptions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fingerprint CHAR(64) NOT NULL UNIQUE,kind VARCHAR(20) NOT NULL,area VARCHAR(40) NOT NULL,title VARCHAR(250) NOT NULL,detail TEXT NULL,how VARCHAR(500) NULL,route VARCHAR(120) NULL,
     status VARCHAR(14) NOT NULL DEFAULT 'open',assigned_to BIGINT UNSIGNED NULL,first_seen DATETIME NOT NULL,last_seen DATETIME NOT NULL,resolved_by BIGINT UNSIGNED NULL,resolved_at DATETIME NULL,resolution_note VARCHAR(900) NULL,INDEX(status,last_seen),INDEX(kind))" . $E);
  run("CREATE TABLE IF NOT EXISTS f3_exception_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,exception_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NULL,action VARCHAR(20) NOT NULL,note VARCHAR(900) NULL,at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(exception_id,id))" . $E);
  run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('f3_price_alert_pct','15'),('f3_default_lead_days','2'),('f3_reorder_cover_days','7'),('f3_strict_gl','0')");
 },
 13 => function () { // V29 — optional daybook (hard-copy closing register) photos on a daily closing. New table only.
  run("CREATE TABLE IF NOT EXISTS f3_closing_files (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,closing_id BIGINT UNSIGNED NOT NULL,business_date DATE NOT NULL,revision INT NOT NULL,file_id BIGINT UNSIGNED NOT NULL,
     kind VARCHAR(12) NOT NULL DEFAULT 'daybook',client_key VARCHAR(64) NULL UNIQUE,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,removed_at DATETIME NULL,removed_by BIGINT UNSIGNED NULL,
     UNIQUE KEY uq_cf(closing_id,file_id),INDEX(business_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 },
]; }

/** Copy legacy section/department/designation text into the four V16 departments. Only fills NULL f3_* columns (idempotent). */
function f3_map_legacy_staff(): array {
  $out = ['mapped' => 0, 'unmapped' => []];
  foreach (rows('SELECT id,name,section,department,designation,job FROM dd_employees WHERE f3_dept_id IS NULL') as $e) {
    $txt = strtolower(trim(($e['section'] ?? '') . ' ' . ($e['department'] ?? '')));
    $des = trim((string)($e['designation'] ?: $e['job'] ?: ''));
    $dl = strtolower($des);
    $dept = null;
    if (preg_match('/gu?a(u)?rd|security/', $dl)) { $dept = 4; $des = 'Guard'; }
    elseif (preg_match('/manag|management|account|cashier|supervis/', $txt . ' ' . $dl)) $dept = 1;
    elseif (preg_match('/\but\b|ut ?staff|wiping|utility/', $txt . ' ' . $dl)) $dept = 2;
    elseif (preg_match('/kitchen|chef|cook|tandoor|helper/', $txt . ' ' . $dl)) $dept = 3;
    elseif (preg_match('/waiter|service|captain|steward/', $txt . ' ' . $dl)) $dept = 4;
    if (!$dept) { $out['unmapped'][] = $e['name']; continue; }
    if ($des === '' || strtolower($des) === strtolower(F3_DEPTS[$dept][0]) || ($dept === 1 && strtolower($des) === 'management')) $des = [1 => 'Manager', 2 => 'UT Staff', 3 => 'Cook', 4 => 'Waiter'][$dept];
    $fix = ['gaurd' => 'Guard', 'indain' => 'Indian', 'ut' => 'UT Staff', 'ut staff' => 'UT Staff', 'kitchen' => 'Cook', 'helper' => 'Kitchen Helper', 'service' => 'Waiter'];
    $des = $fix[strtolower(trim($des))] ?? trim($des);
    $des = mb_substr(ucwords($des), 0, 80);
    $d = row('SELECT id FROM f3_designations WHERE dept_id=? AND name=?', $dept, $des);
    if (!$d) { run('INSERT INTO f3_designations(dept_id,name,sort) VALUES(?,?,99)', $dept, $des); $did = (int)db()->lastInsertId(); } else $did = (int)$d['id'];
    run('UPDATE dd_employees SET f3_dept_id=?,f3_desig_id=? WHERE id=? AND f3_dept_id IS NULL', $dept, $did, $e['id']);
    run("INSERT INTO f3_assignments(employee_id,dept_id,desig_id,effective_from,note) VALUES(?,?,?,?,?)", $e['id'], $dept, $did, '2000-01-01', 'Mapped from legacy section "' . txt($e['section'] ?? '', 40) . '" / designation "' . txt($e['designation'] ?? '', 60) . '"');
    $out['mapped']++;
  }
  return $out;
}

function f3_migrate_all(?array $u = null): array {
  $lock = 'ksdd_f3_migrate';
  if ((int)row('SELECT GET_LOCK(?,20) n', $lock)['n'] !== 1) fail('Another migration is running.', 409);
  $applied = [];
  try {
    if (schema_version() < CRM_SCHEMA) migrate_all($u);
    if (!bk_installed()) bk_migrate();
    if (!function_exists('sal_migrate')) require_once dirname(__DIR__) . '/salary360.php';
    if (!sal_installed()) sal_migrate();
    $steps = f3_migrations(); ksort($steps);
    foreach ($steps as $v => $fn) {
      if (f3_schema_version() >= $v) continue;
      $fn();
      run("INSERT INTO dd_settings(`key`,value) VALUES('f3_schema',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", (string)$v);
      audit($u, 'f360.migration.apply', ['version' => $v]); $applied[] = $v;
    }
    if (f3_schema_version() >= 11) { $r = f3_fix_collations(); if ($r['converted']) audit($u, 'f360.migration.collation', $r); } // tables added later are aligned too
  } finally { run('SELECT RELEASE_LOCK(?)', $lock); }
  return $applied;
}
/** The collation every app table should use: the database default (if utf8mb4), else the one of the staff table. */
function f3_db_collation_target(): string {
  $d = row('SELECT DEFAULT_CHARACTER_SET_NAME cs, DEFAULT_COLLATION_NAME co FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()');
  $t = ($d && $d['cs'] === 'utf8mb4') ? (string)$d['co'] : (string)(row("SELECT TABLE_COLLATION c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dd_employees'")['c'] ?? '');
  return preg_match('/^utf8mb4_[a-z0-9_]+$/', $t) ? $t : 'utf8mb4_unicode_ci';
}
/** App tables (dd_*, f3_*) whose table or text-column collation differs from the target. Read-only. */
function f3_collation_report(): array {
  $t = f3_db_collation_target();
  $bad = array_column(rows("SELECT DISTINCT x.TABLE_NAME n FROM information_schema.TABLES x LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA=x.TABLE_SCHEMA AND c.TABLE_NAME=x.TABLE_NAME AND c.COLLATION_NAME IS NOT NULL AND c.COLLATION_NAME<>?
     WHERE x.TABLE_SCHEMA=DATABASE() AND x.TABLE_TYPE='BASE TABLE' AND (x.TABLE_NAME LIKE 'dd\\_%' OR x.TABLE_NAME LIKE 'f3\\_%') AND (x.TABLE_COLLATION<>? OR c.COLUMN_NAME IS NOT NULL) ORDER BY x.TABLE_NAME", $t, $t), 'n');
  return ['target' => $t, 'tables' => $bad];
}
/** Convert those tables to the target collation (text kept as is; only the comparison rule changes). */
function f3_fix_collations(): array {
  $r = f3_collation_report(); $out = ['target' => $r['target'], 'converted' => [], 'failed' => []]; if (!$r['tables']) return $out;
  db()->exec('SET FOREIGN_KEY_CHECKS=0');
  try { foreach ($r['tables'] as $tb) { if (!preg_match('/^(dd|f3)_[A-Za-z0-9_]+$/', $tb)) continue;
      try { db()->exec('ALTER TABLE `' . $tb . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . $r['target']); $out['converted'][] = $tb; }
      catch (PDOException $e) { $out['failed'][] = $tb . ': ' . ($e->errorInfo[2] ?? $e->getMessage()); } } }
  finally { db()->exec('SET FOREIGN_KEY_CHECKS=1'); }
  return $out;
}
function f3_seq(string $name, string $prefix): string {
  run('INSERT INTO f3_sequences(name,value) VALUES(?,1) ON DUPLICATE KEY UPDATE value=LAST_INSERT_ID(value+1)', $name);
  $n = (int)db()->lastInsertId(); if ($n === 0) $n = (int)row('SELECT value FROM f3_sequences WHERE name=?', $name)['value'];
  return $prefix . str_pad((string)$n, 5, '0', STR_PAD_LEFT);
}
