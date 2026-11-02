<?php
declare(strict_types=1);
// KSDD CRM 360 — additive, non-destructive schema migrations.
// Every step is idempotent: it checks information_schema before altering, uses CREATE TABLE IF NOT EXISTS,
// and never drops or rewrites existing rows. Works on MySQL 8 and MariaDB 10.6+.

function supplier_key(string $n): string { return strtolower(preg_replace('/[^A-Za-z0-9\x{0900}-\x{097F}]+/u','',$n)); }
function mig_has_column(string $table,string $col): bool { return (bool)row('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',$table,$col); }
function mig_has_index(string $table,string $idx): bool { if(mt_enabled()) return mt_has_index($table,$idx); return (bool)row('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',$table,$idx); }
function mig_add_column(string $table,string $col,string $def): void { if(!mig_has_column($table,$col)) db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def"); }
function mig_add_index(string $table,string $idx,string $def): void { if(!mig_has_index($table,$idx)) db()->exec("ALTER TABLE `$table` ADD $def"); }
const ENGINE = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

function migrations(): array { return [
 2 => function(){ // CRM 360 foundation
  // Users: link to employee, active flag, assigned drawer/department.
  mig_add_column('dd_users','active','TINYINT NOT NULL DEFAULT 1');
  mig_add_column('dd_users','employee_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_users','drawer',"VARCHAR(40) NULL");
  mig_add_column('dd_users','department',"VARCHAR(60) NULL");
  mig_add_column('dd_users','lang',"VARCHAR(5) NOT NULL DEFAULT 'hi'");
  // Ledger: receipt number, drawer, payee, proof, voucher link.
  mig_add_column('dd_ledger','receipt_no','VARCHAR(40) NULL');
  mig_add_index('dd_ledger','uq_receipt','UNIQUE KEY uq_receipt(receipt_no)');
  mig_add_column('dd_ledger','drawer',"VARCHAR(40) NULL");
  mig_add_column('dd_ledger','payee',"VARCHAR(160) NULL");
  mig_add_column('dd_ledger','proof_file_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_ledger','voucher_id','BIGINT UNSIGNED NULL');
  mig_add_index('dd_ledger','uq_voucher','UNIQUE KEY uq_voucher(voucher_id)');
  // Day workflow, variance tracking, carry-forward.
  mig_add_column('dd_days','workflow',"VARCHAR(20) NOT NULL DEFAULT 'draft'");
  mig_add_column('dd_days','opening_source',"VARCHAR(20) NOT NULL DEFAULT 'manual'");
  mig_add_column('dd_days','expected','BIGINT NULL');
  mig_add_column('dd_days','variance','BIGINT NULL');
  mig_add_column('dd_days','variance_status',"VARCHAR(20) NOT NULL DEFAULT 'none'");
  mig_add_column('dd_days','submitted_by','BIGINT UNSIGNED NULL');
  mig_add_column('dd_days','submitted_at','DATETIME NULL');
  mig_add_column('dd_days','reviewed_by','BIGINT UNSIGNED NULL');
  mig_add_column('dd_days','reviewed_at','DATETIME NULL');
  mig_add_column('dd_days','closed_at','DATETIME NULL');
  run("UPDATE dd_days SET workflow='closed' WHERE status='closed' AND workflow='draft'");
  // Inventory as item master with Hindi names and categories.
  mig_add_column('dd_inventory','name_hi',"VARCHAR(160) NULL");
  mig_add_column('dd_inventory','category',"VARCHAR(80) NULL");
  mig_add_column('dd_inventory','aliases',"VARCHAR(400) NULL");
  mig_add_column('dd_inventory','safety_stock','DECIMAL(14,3) NOT NULL DEFAULT 0');
  mig_add_column('dd_inventory','active','TINYINT NOT NULL DEFAULT 1');
  mig_add_column('dd_stock_moves','kind',"VARCHAR(20) NOT NULL DEFAULT 'legacy'");
  mig_add_column('dd_stock_moves','from_location','BIGINT UNSIGNED NULL');
  mig_add_column('dd_stock_moves','to_location','BIGINT UNSIGNED NULL');
  mig_add_column('dd_stock_moves','supplier_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_stock_moves','rate','BIGINT NULL');
  mig_add_column('dd_stock_moves','reference',"VARCHAR(120) NULL");
  mig_add_column('dd_stock_moves','request_item_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_stock_moves','po_line_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_stock_moves','proof_file_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_stock_moves','created_by','BIGINT UNSIGNED NULL');
  mig_add_column('dd_stock_moves','created_at','DATETIME NULL');
  // Supplier bills: partial payments.
  mig_add_column('dd_bills','paid','BIGINT NOT NULL DEFAULT 0');
  mig_add_column('dd_bills','status',"VARCHAR(20) NOT NULL DEFAULT 'unpaid'");
  mig_add_column('dd_bills','proof_file_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_bills','po_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_bills','created_by','BIGINT UNSIGNED NULL');
  mig_add_column('dd_suppliers','category',"VARCHAR(80) NULL");
  mig_add_column('dd_suppliers','credit_days','INT NOT NULL DEFAULT 0');
  mig_add_column('dd_suppliers','opening_payable','BIGINT NOT NULL DEFAULT 0');
  mig_add_column('dd_suppliers','active','TINYINT NOT NULL DEFAULT 1');

  $t=[
  "dd_migrations (version INT PRIMARY KEY,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,note VARCHAR(200))",
  "dd_sequences (name VARCHAR(60) PRIMARY KEY,value BIGINT NOT NULL DEFAULT 0)",
  "dd_files (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,path VARCHAR(255) NOT NULL,mime VARCHAR(60) NOT NULL,size INT NOT NULL,sha256 CHAR(64) NOT NULL,original_name VARCHAR(160),uploaded_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(sha256))",
  "dd_cash_counts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,date DATE NOT NULL,kind VARCHAR(20) NOT NULL,drawer VARCHAR(40) NOT NULL DEFAULT 'Main counter',denominations TEXT NOT NULL,total BIGINT NOT NULL,counted_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(date,kind))",
  "dd_handovers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,date DATE NOT NULL,outgoing_user BIGINT UNSIGNED NOT NULL,incoming_user BIGINT UNSIGNED NOT NULL,count_id BIGINT UNSIGNED NOT NULL,expected BIGINT NOT NULL,counted BIGINT NOT NULL,variance BIGINT NOT NULL,note TEXT,out_ack_at DATETIME NOT NULL,in_ack_at DATETIME NULL,status VARCHAR(20) NOT NULL DEFAULT 'pending',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(date))",
  "dd_day_snapshots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,date DATE NOT NULL,version INT NOT NULL,label VARCHAR(60) NOT NULL,payload MEDIUMTEXT NOT NULL,sha256 CHAR(64) NOT NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_day_ver(date,version))",
  "dd_email_queue (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,idem_key VARCHAR(160) NOT NULL UNIQUE,kind VARCHAR(40) NOT NULL,snapshot_id BIGINT UNSIGNED NULL,recipients TEXT NOT NULL,cc TEXT,subject VARCHAR(250) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'queued',attempts INT NOT NULL DEFAULT 0,last_error TEXT,smtp_reply VARCHAR(250),next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,sent_at DATETIME NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(status,next_attempt_at))",
  "dd_jobs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,job VARCHAR(60) NOT NULL,idem_key VARCHAR(160) NULL UNIQUE,business_date DATE NULL,trigger_by VARCHAR(40) NOT NULL,status VARCHAR(20) NOT NULL,processed INT NOT NULL DEFAULT 0,skipped INT NOT NULL DEFAULT 0,errors TEXT,retry_count INT NOT NULL DEFAULT 0,started_at DATETIME NOT NULL,finished_at DATETIME NULL,INDEX(job,started_at))",
  "dd_inbox (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,type VARCHAR(40) NOT NULL,module VARCHAR(30) NOT NULL,priority VARCHAR(10) NOT NULL DEFAULT 'normal',title VARCHAR(250) NOT NULL,detail TEXT,amount BIGINT NULL,ref_table VARCHAR(40) NULL,ref_id BIGINT UNSIGNED NULL,business_date DATE NULL,raised_by BIGINT UNSIGNED NULL,assigned_to BIGINT UNSIGNED NULL,status VARCHAR(20) NOT NULL DEFAULT 'open',due_at DATETIME NULL,dedupe_key VARCHAR(160) NULL UNIQUE,resolved_by BIGINT UNSIGNED NULL,resolved_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(status,type),INDEX(ref_table,ref_id))",
  "dd_inbox_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,inbox_id BIGINT UNSIGNED NOT NULL,user_id BIGINT UNSIGNED NULL,action VARCHAR(30) NOT NULL,note TEXT,at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(inbox_id))",
  "dd_vouchers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,voucher_no VARCHAR(40) NOT NULL UNIQUE,kind VARCHAR(20) NOT NULL,date DATE NOT NULL,payee VARCHAR(160) NOT NULL,amount BIGINT NOT NULL,category VARCHAR(80),description TEXT,employee_id BIGINT UNSIGNED NULL,payroll_month CHAR(7) NULL,bill_id BIGINT UNSIGNED NULL,driver_visit_id BIGINT UNSIGNED NULL,proof_file_id BIGINT UNSIGNED NULL,status VARCHAR(20) NOT NULL DEFAULT 'requested',requested_by BIGINT UNSIGNED NOT NULL,approved_by BIGINT UNSIGNED NULL,approved_at DATETIME NULL,paid_by BIGINT UNSIGNED NULL,paid_at DATETIME NULL,ledger_id BIGINT UNSIGNED NULL UNIQUE,client_key VARCHAR(64) NULL UNIQUE,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(status,kind),CHECK(amount>0))",
  "dd_bill_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bill_id BIGINT UNSIGNED NOT NULL,ledger_id BIGINT UNSIGNED NOT NULL UNIQUE,amount BIGINT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(bill_id))",
  "dd_locations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(80) NOT NULL UNIQUE,name_hi VARCHAR(80),kind VARCHAR(20) NOT NULL DEFAULT 'kitchen',active TINYINT NOT NULL DEFAULT 1)",
  "dd_stock_balances (item_id BIGINT UNSIGNED NOT NULL,location_id BIGINT UNSIGNED NOT NULL,quantity DECIMAL(14,3) NOT NULL DEFAULT 0,PRIMARY KEY(item_id,location_id))",
  "dd_stock_counts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,date DATE NOT NULL,item_id BIGINT UNSIGNED NOT NULL,location_id BIGINT UNSIGNED NOT NULL,system_qty DECIMAL(14,3) NOT NULL,counted_qty DECIMAL(14,3) NOT NULL,difference DECIMAL(14,3) NOT NULL,reason TEXT,status VARCHAR(20) NOT NULL,counted_by BIGINT UNSIGNED NOT NULL,approved_by BIGINT UNSIGNED NULL,move_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(date))",
  "dd_kitchen_requests (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,target_date DATE NOT NULL,location_id BIGINT UNSIGNED NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'submitted',note TEXT,created_by BIGINT UNSIGNED NOT NULL,submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,verified_by BIGINT UNSIGNED NULL,verified_at DATETIME NULL,acknowledged_by BIGINT UNSIGNED NULL,UNIQUE KEY uq_req(target_date,location_id))",
  "dd_kitchen_request_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,request_id BIGINT UNSIGNED NOT NULL,item_id BIGINT UNSIGNED NOT NULL,required_qty DECIMAL(14,3) NOT NULL,kitchen_available DECIMAL(14,3) NOT NULL DEFAULT 0,shortage_qty DECIMAL(14,3) NOT NULL DEFAULT 0,store_available DECIMAL(14,3) NULL,to_issue DECIMAL(14,3) NULL,to_purchase DECIMAL(14,3) NULL,issued_qty DECIMAL(14,3) NOT NULL DEFAULT 0,received_qty DECIMAL(14,3) NULL,priority VARCHAR(10) NOT NULL DEFAULT 'normal',note VARCHAR(250),purchase_status VARCHAR(20) NOT NULL DEFAULT 'none',UNIQUE KEY uq_req_item(request_id,item_id))",
  "dd_purchase_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,po_no VARCHAR(40) NOT NULL UNIQUE,supplier_id BIGINT UNSIGNED NOT NULL,expected_date DATE NULL,status VARCHAR(20) NOT NULL DEFAULT 'ordered',note TEXT,created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  "dd_po_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,po_id BIGINT UNSIGNED NOT NULL,item_id BIGINT UNSIGNED NOT NULL,quantity DECIMAL(14,3) NOT NULL,rate BIGINT NOT NULL DEFAULT 0,received_qty DECIMAL(14,3) NOT NULL DEFAULT 0,INDEX(po_id))",
  "dd_pos_days (business_date DATE PRIMARY KEY,revision INT NOT NULL DEFAULT 1,cash BIGINT NOT NULL DEFAULT 0,upi BIGINT NOT NULL DEFAULT 0,card BIGINT NOT NULL DEFAULT 0,other BIGINT NOT NULL DEFAULT 0,gross BIGINT NOT NULL DEFAULT 0,net BIGINT NOT NULL DEFAULT 0,discount BIGINT NOT NULL DEFAULT 0,refund BIGINT NOT NULL DEFAULT 0,voids BIGINT NOT NULL DEFAULT 0,tax BIGINT NOT NULL DEFAULT 0,bills INT NULL,source VARCHAR(30) NOT NULL,file_sha256 CHAR(64) NOT NULL,status VARCHAR(20) NOT NULL,reconcile_note TEXT,pending_payload TEXT NULL,imported_by BIGINT UNSIGNED NULL,imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  "dd_driver_visits (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(10) NOT NULL,driver_name VARCHAR(120) NOT NULL,phone VARCHAR(20),vehicle_no VARCHAR(20) NOT NULL,yellow_plate TINYINT NOT NULL DEFAULT 0,visit_date DATE NOT NULL,trip_ref VARCHAR(60) NOT NULL DEFAULT '',passengers INT NULL,amount BIGINT NOT NULL,free_food TINYINT NOT NULL DEFAULT 0,voucher_id BIGINT UNSIGNED NULL,created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_visit(kind,vehicle_no,visit_date,trip_ref))",
  "dd_checklist_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,date DATE NOT NULL,template VARCHAR(40) NOT NULL,items TEXT NOT NULL,done TEXT NOT NULL,completed_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_run(date,template))",
  ];
  foreach($t as $sql) db()->exec('CREATE TABLE IF NOT EXISTS '.$sql.ENGINE);

  // Seed locations and move legacy single-store quantity into Main Store balances (only once, only if empty).
  foreach([['Main Store','मुख्य स्टोर','store'],['Main Kitchen','मुख्य रसोई','kitchen'],['Tandoor','तंदूर','kitchen'],['Beverage Counter','पेय काउंटर','kitchen']] as $l) run('INSERT IGNORE INTO dd_locations(name,name_hi,kind) VALUES(?,?,?)',...$l);
  $store=(int)row("SELECT id FROM dd_locations WHERE name='Main Store'")['id'];
  if(!row('SELECT 1 FROM dd_stock_balances LIMIT 1')) run('INSERT INTO dd_stock_balances(item_id,location_id,quantity) SELECT id,?,quantity FROM dd_inventory WHERE quantity<>0',$store);
  // Legacy paid bills become fully-paid with a payment allocation (no new ledger rows).
  run("INSERT IGNORE INTO dd_bill_payments(bill_id,ledger_id,amount) SELECT id,ledger_id,amount FROM dd_bills WHERE ledger_id IS NOT NULL");
  run("UPDATE dd_bills SET paid=amount,status='paid' WHERE ledger_id IS NOT NULL AND status='unpaid'");
  // Default policies (Admin-editable). Amounts in paise.
  $defaults=['business_day_cutoff_hour'=>'4','cash_out_direct_limit'=>'100000','proof_required_above'=>'50000','manager_approval_limit'=>'500000',
   'taxi_incentive'=>'5000','driver_auto_approve_limit'=>'20000','owner_emails'=>'','email_cc'=>'','email_subject'=>'KSDD Daybook {date} · {status}',
   'checklist_housekeeping'=>json_encode(['Washroom cleaned / वॉशरूम साफ','Dining area cleaned / डाइनिंग साफ','Kitchen floor cleaned / रसोई फर्श साफ','Dustbins emptied / कूड़ादान खाली','Water area checked / पानी जगह चेक'],JSON_UNESCAPED_UNICODE),
   'checklist_opening'=>json_encode(['Staff available','Counter ready','Cash drawer verified','Kitchen ready','Store ready','Dining ready','Washrooms checked','Water supply','Electricity / generator','POS & internet'],JSON_UNESCAPED_UNICODE),
   'checklist_closing'=>json_encode(['POS sales verified','Cash counted','Expenses entered','Kitchen requirement submitted','Store movement reviewed','Deliveries checked','Staff handover done','Cleaning completed','Equipment secured','Pending issues escalated'],JSON_UNESCAPED_UNICODE)];
  foreach($defaults as $k=>$v) run('INSERT IGNORE INTO dd_settings(`key`,value) VALUES(?,?)',$k,$v);
 },
 3 => function(){ // Supplier Bill + Stock IN, supplier dues & payment cycles
  mig_add_column('dd_suppliers','gstin',"VARCHAR(20) NULL");
  mig_add_column('dd_suppliers','name_key',"VARCHAR(160) NULL");
  mig_add_column('dd_suppliers','updated_at','DATETIME NULL');
  foreach(rows('SELECT id,name FROM dd_suppliers WHERE name_key IS NULL') as $s) run('UPDATE dd_suppliers SET name_key=? WHERE id=?',supplier_key($s['name']),$s['id']);
  mig_add_column('dd_bills','kind',"VARCHAR(20) NOT NULL DEFAULT 'invoice'");
  mig_add_column('dd_bills','credited','BIGINT NOT NULL DEFAULT 0');
  mig_add_column('dd_bills','receipt_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_bills','original_due_date','DATE NULL');
  mig_add_column('dd_bills','voided','TINYINT NOT NULL DEFAULT 0');
  mig_add_index('dd_bills','idx_bill_due','INDEX idx_bill_due(status,due_date)');
  // One payment may settle several bills: unique per (ledger,bill) instead of per ledger.
  if(mig_has_index('dd_bill_payments','ledger_id')&&!mig_has_index('dd_bill_payments','uq_pay_bill')) db()->exec('ALTER TABLE dd_bill_payments DROP INDEX ledger_id, ADD UNIQUE KEY uq_pay_bill(ledger_id,bill_id), ADD INDEX idx_pay_ledger(ledger_id)');
  mig_add_column('dd_vouchers','supplier_id','BIGINT UNSIGNED NULL');
  mig_add_column('dd_vouchers','is_advance','TINYINT NOT NULL DEFAULT 0');
  $t=[
  "dd_goods_receipts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,receipt_no VARCHAR(40) NOT NULL UNIQUE,supplier_id BIGINT UNSIGNED NOT NULL,location_id BIGINT UNSIGNED NOT NULL,invoice_ref VARCHAR(120) NULL,invoice_date DATE NULL,po_id BIGINT UNSIGNED NULL,status VARCHAR(20) NOT NULL,hold_reason VARCHAR(40) NULL,subtotal BIGINT NOT NULL DEFAULT 0,bill_discount BIGINT NOT NULL DEFAULT 0,bill_tax BIGINT NOT NULL DEFAULT 0,other_charges BIGINT NOT NULL DEFAULT 0,rounding BIGINT NOT NULL DEFAULT 0,calculated_total BIGINT NOT NULL DEFAULT 0,invoice_total BIGINT NOT NULL DEFAULT 0,difference BIGINT NOT NULL DEFAULT 0,payload MEDIUMTEXT NOT NULL,bill_id BIGINT UNSIGNED NULL UNIQUE,note TEXT,review_note TEXT,client_key VARCHAR(64) NOT NULL UNIQUE,created_by BIGINT UNSIGNED NOT NULL,reviewed_by BIGINT UNSIGNED NULL,reviewed_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(supplier_id,status),INDEX(status))",
  "dd_receipt_files (receipt_id BIGINT UNSIGNED NOT NULL,file_id BIGINT UNSIGNED NOT NULL UNIQUE,PRIMARY KEY(receipt_id,file_id))",
  "dd_bill_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,bill_id BIGINT UNSIGNED NULL,receipt_id BIGINT UNSIGNED NOT NULL,stock_move_id BIGINT UNSIGNED NULL UNIQUE,po_line_id BIGINT UNSIGNED NULL,item_id BIGINT UNSIGNED NOT NULL,item_name VARCHAR(160) NOT NULL,purchase_qty DECIMAL(14,3) NOT NULL,purchase_unit VARCHAR(20) NOT NULL,conversion_factor DECIMAL(14,6) NOT NULL DEFAULT 1,stock_qty DECIMAL(14,3) NOT NULL,stock_unit VARCHAR(20) NOT NULL,rate BIGINT NOT NULL,gross BIGINT NOT NULL,discount BIGINT NOT NULL DEFAULT 0,tax BIGINT NOT NULL DEFAULT 0,total BIGINT NOT NULL,batch VARCHAR(60) NULL,expiry DATE NULL,supplier_id BIGINT UNSIGNED NOT NULL,created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(bill_id),INDEX(supplier_id,item_id,id),CHECK(rate>=0),CHECK(total>=0))",
  "dd_unit_conversions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,item_id BIGINT UNSIGNED NULL,from_unit VARCHAR(20) NOT NULL,to_unit VARCHAR(20) NOT NULL,factor DECIMAL(14,6) NOT NULL,UNIQUE KEY uq_conv(item_id,from_unit,to_unit),CHECK(factor>0))",
  "dd_supplier_terms (supplier_id BIGINT UNSIGNED PRIMARY KEY,mode VARCHAR(20) NOT NULL,cycle_days INT NULL,anchor_date DATE NULL,weekday TINYINT NULL,day_of_month TINYINT NULL,credit_days INT NULL,reminder_days INT NOT NULL DEFAULT 1,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  "dd_supplier_credits (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,supplier_id BIGINT UNSIGNED NOT NULL,bill_id BIGINT UNSIGNED NULL,kind VARCHAR(20) NOT NULL,amount BIGINT NOT NULL,reference VARCHAR(120) NULL,reason TEXT NOT NULL,ledger_id BIGINT UNSIGNED NULL UNIQUE,source_key VARCHAR(120) NULL UNIQUE,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(supplier_id),CHECK(amount>0))",
  "dd_voucher_allocations (voucher_id BIGINT UNSIGNED NOT NULL,bill_id BIGINT UNSIGNED NOT NULL,amount BIGINT NOT NULL,PRIMARY KEY(voucher_id,bill_id),CHECK(amount>0))",
  ];
  foreach($t as $sql) db()->exec('CREATE TABLE IF NOT EXISTS '.$sql.ENGINE);
  // Generic unit conversions (item-specific rows override). No packet/crate sizes are assumed.
  foreach([['g','kg','0.001'],['gram','kg','0.001'],['kg','g','1000'],['ml','litre','0.001'],['litre','ml','1000'],['ltr','litre','1'],['l','litre','1']] as $c)
      if(!row('SELECT id FROM dd_unit_conversions WHERE item_id IS NULL AND from_unit=? AND to_unit=?',$c[0],$c[1])) run('INSERT INTO dd_unit_conversions(item_id,from_unit,to_unit,factor) VALUES(NULL,?,?,?)',...$c);
  // Opening payables become auditable opening bills ONCE. dd_suppliers.opening_payable stays as the record but is never summed again.
  $cut=date('Y-m-d');
  foreach(rows('SELECT id,opening_payable FROM dd_suppliers WHERE opening_payable>0') as $s)
      run("INSERT IGNORE INTO dd_bills(supplier_id,reference,date,due_date,amount,paid,status,kind) VALUES(?,?,?,?,?,0,'unpaid','opening')",$s['id'],'OPENING-'.$s['id'],$cut,$cut,$s['opening_payable']);
  run("UPDATE dd_bills SET original_due_date=due_date WHERE original_due_date IS NULL");
  foreach(['supplier_cutover_date'=>$cut,'invoice_rounding_max'=>'100','rate_change_alert_pct'=>'10','po_over_tolerance_pct'=>'0',
           'supplier_terms_suggestions'=>json_encode(['milk'=>7,'doodh'=>7,'paneer'=>10,'gas'=>15,'lpg'=>15,'cylinder'=>15,'gulab jamun'=>15])] as $k=>$v) run('INSERT IGNORE INTO dd_settings(`key`,value) VALUES(?,?)',$k,$v);
 },
 4 => function(){ // V6: kitchen consumption + valuation, payroll import & advance carry-forward, recurring bills, budgets, bank matching, monthly close
  // Stock valuation & daily usage
  mig_add_column('dd_stock_moves','cost_value','BIGINT NULL');
  mig_add_index('dd_stock_moves','idx_move_date','INDEX idx_move_date(date,item_id)');
  mig_add_index('dd_stock_moves','idx_move_kind','INDEX idx_move_kind(kind,date)');
  mig_add_column('dd_inventory','cost_source',"VARCHAR(20) NOT NULL DEFAULT 'none'");
  mig_add_column('dd_inventory','track_daily','TINYINT NOT NULL DEFAULT 0');
  mig_add_column('dd_inventory','budget_group',"VARCHAR(40) NULL");
  run("UPDATE dd_inventory SET cost_source='receipt' WHERE cost_source='none' AND id IN (SELECT DISTINCT item_id FROM dd_bill_lines)");
  run("UPDATE dd_inventory SET cost_source='manual' WHERE cost_source='none' AND cost>0");
  // High-value daily-tracked defaults (owner can change in the item master). Matching is on name/aliases only; nothing is created.
  foreach(rows('SELECT id,name,COALESCE(name_hi,"") hi,COALESCE(aliases,"") al FROM dd_inventory') as $i){
    if(preg_match('/\b(milk|doodh|paneer|butter|makhan|oil|onions?|pyaz|potato(es)?|aloo|lpg|gas|cylinder|curd|dahi|cream|ghee)\b|दूध|पनीर|मक्खन|तेल|प्याज|आलू|दही|गैस/iu',$i['name'].' '.$i['hi'].' '.$i['al'])) run('UPDATE dd_inventory SET track_daily=1 WHERE id=?',$i['id']); }
  // Employees: identity, status, external payroll IDs
  mig_add_column('dd_employees','code',"VARCHAR(40) NULL");
  mig_add_column('dd_employees','department',"VARCHAR(80) NULL");
  mig_add_column('dd_employees','designation',"VARCHAR(80) NULL");
  mig_add_column('dd_employees','status',"VARCHAR(20) NOT NULL DEFAULT 'active'");
  mig_add_column('dd_employees','joined_on','DATE NULL');
  mig_add_column('dd_employees','left_on','DATE NULL');
  mig_add_column('dd_employees','created_by','BIGINT UNSIGNED NULL');
  run("UPDATE dd_employees SET status='inactive' WHERE active=0 AND status='active'");
  mig_add_column('dd_payroll','source',"VARCHAR(20) NOT NULL DEFAULT 'manual'");
  mig_add_column('dd_payroll','approved_by','BIGINT UNSIGNED NULL');
  mig_add_column('dd_payroll','approved_at','DATETIME NULL');
  mig_add_column('dd_payroll','paid_days','DECIMAL(6,2) NULL');
  mig_add_column('dd_payroll','note',"VARCHAR(250) NULL");
  mig_add_column('dd_vouchers','obligation_id','BIGINT UNSIGNED NULL');
  $t=[
  "dd_stock_consumptions (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,consumption_no VARCHAR(40) NOT NULL UNIQUE,business_date DATE NOT NULL,location_id BIGINT UNSIGNED NOT NULL,mode VARCHAR(10) NOT NULL,status VARCHAR(20) NOT NULL,note TEXT,client_key VARCHAR(64) NOT NULL UNIQUE,created_by BIGINT UNSIGNED NOT NULL,decided_by BIGINT UNSIGNED NULL,decided_at DATETIME NULL,decision_note TEXT,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(business_date,location_id),INDEX(status))",
  "dd_stock_consumption_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,consumption_id BIGINT UNSIGNED NOT NULL,business_date DATE NOT NULL,location_id BIGINT UNSIGNED NOT NULL,item_id BIGINT UNSIGNED NOT NULL,available_qty DECIMAL(14,3) NOT NULL,used_qty DECIMAL(14,3) NOT NULL DEFAULT 0,waste_qty DECIMAL(14,3) NOT NULL DEFAULT 0,waste_reason VARCHAR(250) NULL,counted_qty DECIMAL(14,3) NULL,expected_close DECIMAL(14,3) NULL,variance_qty DECIMAL(14,3) NULL,unit_cost BIGINT NOT NULL DEFAULT 0,used_value BIGINT NOT NULL DEFAULT 0,waste_value BIGINT NOT NULL DEFAULT 0,used_move_id BIGINT UNSIGNED NULL UNIQUE,waste_move_id BIGINT UNSIGNED NULL UNIQUE,count_id BIGINT UNSIGNED NULL,status VARCHAR(20) NOT NULL,no_usage_reason VARCHAR(250) NULL,INDEX(business_date,location_id,item_id),INDEX(consumption_id))",
  "dd_employee_identifiers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source VARCHAR(30) NOT NULL,external_id VARCHAR(64) NOT NULL,employee_id BIGINT UNSIGNED NOT NULL,verified_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_ext(source,external_id),INDEX(employee_id))",
  "dd_payroll_import_batches (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,client_key VARCHAR(64) NOT NULL UNIQUE,period CHAR(7) NOT NULL,file_name VARCHAR(160),file_sha256 CHAR(64) NOT NULL,file_path VARCHAR(255) NULL,sheet_name VARCHAR(120),status VARCHAR(20) NOT NULL DEFAULT 'preview',total_rows INT NOT NULL DEFAULT 0,result TEXT NULL,confirm_key VARCHAR(64) NULL UNIQUE,created_by BIGINT UNSIGNED NOT NULL,confirmed_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,confirmed_at DATETIME NULL,INDEX(period))",
  "dd_payroll_import_rows (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,batch_id BIGINT UNSIGNED NOT NULL,row_no INT NOT NULL,external_id VARCHAR(64) NOT NULL,source_name VARCHAR(160) NOT NULL,clean_name VARCHAR(160) NOT NULL,source_status VARCHAR(20) NULL,department VARCHAR(80) NULL,designation VARCHAR(80) NULL,mapped MEDIUMTEXT NOT NULL,row_hash CHAR(64) NOT NULL,match_employee_id BIGINT UNSIGNED NULL,match_kind VARCHAR(20) NOT NULL DEFAULT 'none',issues TEXT NULL,action VARCHAR(20) NULL,status VARCHAR(30) NOT NULL DEFAULT 'preview',result_employee_id BIGINT UNSIGNED NULL,result_payroll_id BIGINT UNSIGNED NULL,UNIQUE KEY uq_batch_row(batch_id,row_no),INDEX(external_id))",
  "dd_payroll_details (payroll_id BIGINT UNSIGNED PRIMARY KEY,batch_id BIGINT UNSIGNED NULL,import_row_id BIGINT UNSIGNED NULL,external_id VARCHAR(64) NULL,source MEDIUMTEXT NOT NULL,sheet_to_pay BIGINT NULL,sheet_advance BIGINT NULL,crm_recoverable BIGINT NULL,reconcile_note TEXT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  "dd_recurring_templates (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,category VARCHAR(80) NOT NULL,payee VARCHAR(160) NOT NULL,supplier_id BIGINT UNSIGNED NULL,amount_mode VARCHAR(12) NOT NULL DEFAULT 'variable',amount BIGINT NOT NULL DEFAULT 0,frequency VARCHAR(12) NOT NULL DEFAULT 'monthly',bill_day TINYINT NULL,due_day TINYINT NOT NULL DEFAULT 10,start_month CHAR(7) NOT NULL,end_month CHAR(7) NULL,reminder_days INT NOT NULL DEFAULT 3,proof_required TINYINT NOT NULL DEFAULT 1,contract_ref VARCHAR(120) NULL,active TINYINT NOT NULL DEFAULT 1,created_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  "dd_recurring_obligations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,template_id BIGINT UNSIGNED NOT NULL,period CHAR(7) NOT NULL,expected_amount BIGINT NOT NULL DEFAULT 0,actual_amount BIGINT NULL,bill_ref VARCHAR(120) NULL,bill_date DATE NULL,due_date DATE NOT NULL,proof_file_id BIGINT UNSIGNED NULL,status VARCHAR(20) NOT NULL DEFAULT 'expected',voucher_id BIGINT UNSIGNED NULL UNIQUE,paid_ledger_id BIGINT UNSIGNED NULL,note TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_obl(template_id,period),INDEX(status,due_date))",
  "dd_budgets (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,month CHAR(7) NOT NULL,category VARCHAR(80) NOT NULL,amount BIGINT NOT NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_budget(month,category))",
  "dd_bank_imports (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,account VARCHAR(40) NOT NULL,file_sha256 CHAR(64) NOT NULL,added INT NOT NULL DEFAULT 0,skipped INT NOT NULL DEFAULT 0,created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  "dd_bank_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,import_id BIGINT UNSIGNED NOT NULL,account VARCHAR(40) NOT NULL,txn_date DATE NOT NULL,description VARCHAR(250) NOT NULL,reference VARCHAR(120) NULL,debit BIGINT NOT NULL DEFAULT 0,credit BIGINT NOT NULL DEFAULT 0,balance BIGINT NULL,fingerprint CHAR(64) NOT NULL UNIQUE,ledger_id BIGINT UNSIGNED NULL UNIQUE,status VARCHAR(20) NOT NULL DEFAULT 'unmatched',note VARCHAR(250) NULL,matched_by BIGINT UNSIGNED NULL,matched_at DATETIME NULL,INDEX(account,status,txn_date))",
  "dd_monthly_closures (month CHAR(7) PRIMARY KEY,status VARCHAR(10) NOT NULL,quality VARCHAR(20) NOT NULL,flags TEXT NULL,report MEDIUMTEXT NULL,report_sha256 CHAR(64) NULL,note TEXT NULL,closed_by BIGINT UNSIGNED NULL,closed_at DATETIME NULL,reopened_by BIGINT UNSIGNED NULL,reopen_reason TEXT NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  ];
  foreach($t as $sql) db()->exec('CREATE TABLE IF NOT EXISTS '.$sql.ENGINE);
  foreach(['usage_backdate_days'=>'2','usage_waste_photo_above'=>'50000','advance_request_limit'=>'0','bank_match_days'=>'3','payroll_sheet_tolerance'=>'100',
           'non_operating_categories'=>json_encode(['Owner drawing','Owner withdrawal','Transfer','Supplier payment','Staff advance','Salary','Refundable deposit','Fixed asset']),
           'direct_food_categories'=>json_encode(['Vegetables','Milk & dairy','Groceries','LPG / gas'])] as $k=>$v) run('INSERT IGNORE INTO dd_settings(`key`,value) VALUES(?,?)',$k,$v);
 },
 5 => function(){ // ERP V1 §M07: double-entry general ledger (shadow mode), starter chart of accounts, category mapping
  $t=[
  "dd_gl_accounts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,code VARCHAR(20) NOT NULL UNIQUE,name VARCHAR(120) NOT NULL,name_hi VARCHAR(120) NULL,type VARCHAR(12) NOT NULL,normal CHAR(1) NOT NULL,system_key VARCHAR(30) NULL UNIQUE,active TINYINT NOT NULL DEFAULT 1,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  "dd_gl_journals (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,journal_no VARCHAR(40) NOT NULL UNIQUE,business_date DATE NOT NULL,period CHAR(7) NOT NULL,source_type VARCHAR(20) NOT NULL,source_id BIGINT UNSIGNED NULL,source_key VARCHAR(120) NOT NULL UNIQUE,memo VARCHAR(250) NULL,status VARCHAR(12) NOT NULL DEFAULT 'posted',reverses_id BIGINT UNSIGNED NULL,total BIGINT NOT NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(business_date),INDEX(period),INDEX(source_type,source_id),CHECK(total>0))",
  "dd_gl_lines (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,journal_id BIGINT UNSIGNED NOT NULL,account_id BIGINT UNSIGNED NOT NULL,debit BIGINT NOT NULL DEFAULT 0,credit BIGINT NOT NULL DEFAULT 0,memo VARCHAR(160) NULL,party_type VARCHAR(20) NULL,party_id BIGINT UNSIGNED NULL,INDEX(journal_id),INDEX(account_id),INDEX(party_type,party_id),CHECK(debit>=0 AND credit>=0 AND (debit=0 OR credit=0)))",
  "dd_gl_category_map (category VARCHAR(80) PRIMARY KEY,account_code VARCHAR(20) NOT NULL,updated_by BIGINT UNSIGNED NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)",
  ];
  foreach($t as $sql) db()->exec('CREATE TABLE IF NOT EXISTS '.$sql.ENGINE);
  require_once __DIR__.'/gl360.php';
  foreach(GL_COA as $k=>[$code,$name,$hi,$type,$normal]) run('INSERT IGNORE INTO dd_gl_accounts(code,name,name_hi,type,normal,system_key) VALUES(?,?,?,?,?,?)',$code,$name,$hi,$type,$normal,$k);
  foreach(GL_CATEGORY_DEFAULTS as $cat=>$k) run('INSERT IGNORE INTO dd_gl_category_map(category,account_code) VALUES(?,?)',$cat,GL_COA[$k][0]);
  // Journals are NOT generated here (migration stays fast and reversible). Owner runs Accounts → “Update ledger” after reviewing the chart of accounts.
 },
 6 => function(){ // Audit implementation (Oct 2026): unified posting events, replay keys, manual counter closes, drawer cash, archives, as-of reconciliation.
  // ADDITIVE ONLY: new tables, new NULLable/defaulted columns, new indexes, new settings (INSERT IGNORE). No existing row is rewritten or deleted.
  $t=[
  "dd_request_keys (scope VARCHAR(40) NOT NULL,client_key VARCHAR(64) NOT NULL,payload_sha CHAR(64) NOT NULL,response MEDIUMTEXT NULL,user_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(scope,client_key))",
  "dd_fin_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,source_system VARCHAR(12) NOT NULL,source_table VARCHAR(40) NOT NULL,source_id BIGINT UNSIGNED NOT NULL,source_rev INT NOT NULL DEFAULT 1,kind VARCHAR(30) NOT NULL,business_date DATE NULL,earned_period CHAR(7) NULL,amount BIGINT NOT NULL,account VARCHAR(40) NULL,employee_id BIGINT UNSIGNED NULL,party VARCHAR(160) NULL,memo VARCHAR(250) NULL,payload MEDIUMTEXT NOT NULL,payload_sha CHAR(64) NOT NULL,status VARCHAR(12) NOT NULL DEFAULT 'pending',journal_id BIGINT UNSIGNED NULL,ledger_id BIGINT UNSIGNED NULL,linked_event_id BIGINT UNSIGNED NULL,decided_by BIGINT UNSIGNED NULL,decided_at DATETIME NULL,decision_note VARCHAR(250) NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_evt_src(source_system,source_table,source_id,source_rev),INDEX idx_evt_status(status,business_date),INDEX idx_evt_ledger(ledger_id))",
  "dd_counter_closes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,business_date DATE NOT NULL,counter VARCHAR(60) NOT NULL,drawer VARCHAR(40) NOT NULL DEFAULT 'Main counter',revision INT NOT NULL DEFAULT 1,status VARCHAR(12) NOT NULL DEFAULT 'posted',orders_success INT NOT NULL DEFAULT 0,orders_cancelled INT NOT NULL DEFAULT 0,orders_returned INT NOT NULL DEFAULT 0,orders_complimentary INT NOT NULL DEFAULT 0,net BIGINT NOT NULL DEFAULT 0,discount BIGINT NOT NULL DEFAULT 0,tax BIGINT NOT NULL DEFAULT 0,refund BIGINT NOT NULL DEFAULT 0,rounding BIGINT NOT NULL DEFAULT 0,total BIGINT NOT NULL,unpaid BIGINT NOT NULL DEFAULT 0,cash BIGINT NOT NULL DEFAULT 0,card BIGINT NOT NULL DEFAULT 0,upi BIGINT NOT NULL DEFAULT 0,other BIGINT NOT NULL DEFAULT 0,other_additive TINYINT NOT NULL DEFAULT 0,residual BIGINT NOT NULL DEFAULT 0,evidence_ref VARCHAR(120) NULL,note TEXT NULL,client_key VARCHAR(64) NULL UNIQUE,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,superseded_by BIGINT UNSIGNED NULL,UNIQUE KEY uq_cc(business_date,counter,revision),INDEX idx_cc_day(business_date,status),CHECK(total>=0))",
  "dd_drawer_openings (date DATE NOT NULL,drawer VARCHAR(40) NOT NULL,opening BIGINT NOT NULL,set_by BIGINT UNSIGNED NULL,set_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(date,drawer))",
  "dd_drawer_transfers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,date DATE NOT NULL,from_drawer VARCHAR(40) NOT NULL,to_drawer VARCHAR(40) NOT NULL,amount BIGINT NOT NULL,note VARCHAR(250) NULL,created_by BIGINT UNSIGNED NULL,acknowledged_by BIGINT UNSIGNED NULL,acknowledged_at DATETIME NULL,voided TINYINT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(date),CHECK(amount>0))",
  "dd_archive (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(40) NOT NULL,ref VARCHAR(120) NOT NULL,payload MEDIUMTEXT NOT NULL,sha256 CHAR(64) NOT NULL,reason VARCHAR(250) NULL,created_by BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(kind,ref))",
  ];
  foreach($t as $sql) db()->exec('CREATE TABLE IF NOT EXISTS '.$sql.ENGINE);
  // Supplier credits: when a credit/advance was applied to a bill (NULL for legacy rows = date unknown; reports fall back to created_at and say so).
  mig_add_column('dd_supplier_credits','applied_at','DATETIME NULL');
  // Payroll: which proration policy and denominator produced the stored base (NULL = calculated before this version).
  mig_add_column('dd_payroll','calc_policy',"VARCHAR(20) NULL"); mig_add_column('dd_payroll','calc_days','TINYINT NULL');
  mig_add_index('dd_ledger','idx_ledger_drawer','INDEX idx_ledger_drawer(date,drawer)');
  mig_add_index('dd_cash_counts','idx_count_drawer','INDEX idx_count_drawer(date,drawer,kind)');
  mig_add_index('dd_gl_journals','idx_gl_src_type','INDEX idx_gl_src_type(source_type,business_date)');
  require_once __DIR__.'/gl360.php';
  foreach(GL_COA as $k=>[$code,$name,$hi,$type,$normal]) run('INSERT IGNORE INTO dd_gl_accounts(code,name,name_hi,type,normal,system_key) VALUES(?,?,?,?,?,?)',$code,$name,$hi,$type,$normal,$k);
  foreach(GL_CATEGORY_DEFAULTS as $cat=>$k) run('INSERT IGNORE INTO dd_gl_category_map(category,account_code) VALUES(?,?)',$cat,GL_COA[$k][0]);
  foreach(['payroll_monthly_proration'=>'calendar','unify_from'=>'','tax_split_pos_from'=>'','bank_settlement_fee_category'=>'Payment fees',
           'post_account_map'=>json_encode(['books:Cash'=>'1000','books:Bank of India'=>'1100','books:UPI / Paytm'=>'1110','books:Card'=>'1120','books:Owner (Safik Home)'=>'3000','salary:Cash'=>'1000','salary:Online'=>'1110','salary:Bank'=>'1100'],JSON_UNESCAPED_UNICODE)] as $k=>$v)
      run('INSERT IGNORE INTO dd_settings(`key`,value) VALUES(?,?)',$k,$v);
  // Books / Salary Register get their own additive columns if those modules are already set up (otherwise their setup adds them later).
  if(row("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dd_bk_txns'")){ require_once __DIR__.'/books360.php'; bk_migrate(); }
  if(row("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dd_sal_months'")){ require_once __DIR__.'/salary360.php'; sal_migrate(); }
 },
]; }

function schema_version(): int { $r=row("SELECT value FROM dd_settings WHERE `key`='schema_version'"); return $r?(int)$r['value']:1; }
/** Apply pending migrations under a named lock. Returns applied versions. DDL auto-commits in MySQL, so each step is written to be re-runnable. */
function migrate_all(?array $u=null): array {
    $lock='ksdd_migrate_'.substr(hash('sha256',(string)(cfg()['db_name']??'')),0,30);
    if((int)row('SELECT GET_LOCK(?,20) n',$lock)['n']!==1) fail('Another migration is running.',409);
    $applied=[];
    try{
        foreach(migrations() as $v=>$fn){
            if(schema_version()>=$v) continue;
            $fn();
            run('INSERT IGNORE INTO dd_migrations(version,note) VALUES(?,?)',$v,'CRM 360');
            run("INSERT INTO dd_settings(`key`,value) VALUES('schema_version',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",(string)$v);
            audit($u,'migration.apply',['version'=>$v]); $applied[]=$v;
        }
    } finally { run('SELECT RELEASE_LOCK(?)',$lock); }
    return $applied;
}
