<?php
declare(strict_types=1);
// STAGING ONLY. Builds a fresh staging database exactly the way production was built (schema.sql → installer owner →
// CRM migrations 2–6 → Salary Register → Books 360), then loads clearly-labelled DEMO legacy data so the V16
// migration can be tested against "existing records". Refuses to run unless config db_name contains 'staging' or 'test'.
// Usage: php tests/staging_setup.php [--no-demo]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'lib.php';
$c = cfg();
if (!preg_match('/(staging|test)/', (string)($c['db_name'] ?? ''))) { fwrite(STDERR, "Refusing: db_name must contain 'staging' or 'test'.\n"); exit(1); }
$pdo = db();
// wipe staging DB
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) $pdo->exec("DROP TABLE `$t`");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
foreach (explode(';', file_get_contents('schema.sql')) as $sql) if (trim($sql) !== '') $pdo->exec($sql);
run('INSERT INTO dd_users(name,email,password,role) VALUES(?,?,?,?)', 'Staging Owner', 'owner@staging.test', password_hash('OwnerPass#2026', PASSWORD_DEFAULT), 'owner');
run("INSERT INTO dd_settings(`key`,value) VALUES('restaurant','Khadak Singh Da Dhaba'),('installed','1'),('schema_version','1')");
require_once __DIR__.'/../migrations.php'; migrate_all(null);
require_once __DIR__.'/../lib360.php'; require_once __DIR__.'/../salary360.php'; require_once __DIR__.'/../books360.php';
sal_migrate(); bk_migrate();
echo "Legacy schema built: CRM v" . schema_version() . ", salary " . setting('salary_schema') . ", books " . setting('books_schema') . "\n";
if (in_array('--no-demo', $argv, true)) exit(0);

// ---------------- DEMO legacy data (sanitized, fictional) ----------------
$owner = ['id' => 1, 'role' => 'owner', 'name' => 'Staging Owner'];
foreach ([['Demo Manager','manager@staging.test','manager'],['Demo Accounts','accounts@staging.test','accountant'],['Demo Counter','counter@staging.test','counter'],['Demo Cashier','cashier@staging.test','cashier'],['Demo Chef','chef@staging.test','chef'],['Demo Store','store@staging.test','storekeeper']] as $u)
    run('INSERT INTO dd_users(name,email,password,role) VALUES(?,?,?,?)', $u[0], $u[1], password_hash('StagingPass#2026', PASSWORD_DEFAULT), $u[2]);
// Employees as the Salary Register stores them (legacy sections Manager / Waiter / UT Staff / Kitchen; designations free text)
$emps = [
 ['DEMO Ramesh K','KS001','Manager','Manager',2500000],['DEMO Sita D','KS002','Manager','Cashier',1500000],
 ['DEMO Amit UT','KS003','UT Staff','UT Staff',1100000],['DEMO Raju UT','KS004','UT Staff','Wiping',1000000],
 ['DEMO Chef Balwant','KS005','Kitchen','Chef',3000000],['DEMO Cook Mohan','KS006','Kitchen','Cook',1800000],['DEMO Helper Gopal','KS007','Kitchen','Kitchen Helper',1200000],
 ['DEMO Captain Vikram','KS008','Waiter','Captain',1600000],['DEMO Waiter Sunil','KS009','Waiter','Waiter',1200000],['DEMO Guard Harpal','KS010','Waiter','Gaurd',1300000],
 ['DEMO Left Staff','KS011','Waiter','Waiter',1100000],
];
foreach ($emps as $i => $e) {
    run("INSERT INTO dd_employees(name,job,salary,pay_basis,active,code,section,designation,status,joined_on,phone,pay_mode,bank_name,ifsc,account_last4) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        $e[0], $e[3], $e[4], 'monthly', $i === 10 ? 0 : 1, $e[1], $e[2], $e[3], $i === 10 ? 'left' : 'active', '2025-04-01', '90000000' . sprintf('%02d', $i), 'Cash', 'Demo Bank', 'DEMO0001234', sprintf('%04d', 1000 + $i));
}
// One locked legacy Salary Register month (Sept 2026) with entries → carried-forward balances
run("INSERT INTO dd_sal_months(month,days,status,tolerance,carry_forward,source,created_by) VALUES('2026-09',30,'locked',1000,1,'app',1)");
foreach (rows('SELECT * FROM dd_employees WHERE active=1') as $k => $e) {
    run('INSERT INTO dd_sal_rows(month,employee_id,section,sort,monthly,present,leave_days,bf,pay_mode) VALUES(?,?,?,?,?,?,?,?,?)', '2026-09', $e['id'], $e['section'], $k, $e['salary'], 29, 1, 0, 'Cash');
    $rid = (int)db()->lastInsertId();
    run("INSERT INTO dd_sal_entries(row_id,month,employee_id,kind,amount,date,mode,note) VALUES(?,?,?,?,?,?,?,?)", $rid, '2026-09', $e['id'], 'payment', (int)$e['salary'] - ($k === 0 ? 300000 : 0), '2026-10-05', 'Cash', 'DEMO salary paid');
    if ($k === 2) run("INSERT INTO dd_sal_entries(row_id,month,employee_id,kind,amount,date,mode,note) VALUES(?,?,?,?,?,?,?,?)", $rid, '2026-09', $e['id'], 'advance', 200000, '2026-09-15', 'Cash', 'DEMO advance');
}
// Books 360 daybook for Oct 2026: opening cash, Petpooja-style sales per tender, Cash Access, expenses, a purchase, a transfer
set_setting('bk_opening_cash', '5000000');
set_setting('bk_start_date', '2026-10-01');
$sup = bk_party_id('DEMO Sharma Vegetables', 'supplier');
for ($d = 1; $d <= 29; $d++) {
    $date = sprintf('2026-10-%02d', $d);
    foreach ([['Cash', 2200000 + $d * 1000], ['UPI / Paytm', 1800000], ['Card', 500000]] as [$acct, $amt])
        run("INSERT INTO dd_bk_txns(date,type,total,paid,account,description,source,created_by) VALUES(?,?,?,?,?,?,'app',1)", $date, 'sale', $amt, $amt, $acct, 'DEMO Petpooja daily sale');
    run("INSERT INTO dd_bk_txns(date,type,category,total,paid,account,description,source,created_by) VALUES(?,?,?,?,?,?,?,'app',1)", $date, 'expense', 'Vegetable', 150000, 150000, 'Cash', 'DEMO vegetables');
    if ($d % 3 === 0) run("INSERT INTO dd_bk_txns(date,type,total,paid,account,description,source,created_by) VALUES(?,?,?,?,?,?,'app',1)", $date, 'cash_access', 20000, 20000, 'Cash', 'DEMO Cash Access (legacy)');
    if ($d % 7 === 0) run("INSERT INTO dd_bk_txns(date,type,party_id,total,paid,account,bill_no,description,source,created_by) VALUES(?,?,?,?,?,?,?,?,'app',1)", $date, 'purchase', $sup, 900000, 400000, 'Cash', 'DEMO-B' . $d, 'DEMO veg purchase');
    if ($d % 10 === 0) run("INSERT INTO dd_bk_txns(date,type,total,paid,account,to_account,description,source,created_by) VALUES(?,?,?,?,?,?,?,'app',1)", $date, 'transfer', 3000000, 3000000, 'Cash', 'Bank of India', 'DEMO cash deposit');
}
// Itemised legacy Books purchases + a consumption move, so the V17 cut-over opening-stock copy has real history to reconcile
$onion = bk_item_id('DEMO Onion', 'kg', 'Vegetable');
foreach (rows("SELECT id FROM dd_bk_txns WHERE type='purchase' AND bill_no IN ('DEMO-B7','DEMO-B14')") as $t) run('INSERT INTO dd_bk_lines(txn_id,item_id,qty,unit,rate,amount) VALUES(?,?,?,?,?,?)', $t['id'], $onion, '300.000', 'kg', 3000, 900000);
run("INSERT INTO dd_bk_moves(item_id,date,qty,kind,note,created_by) VALUES(?,?,?,?,?,1)", $onion, '2026-10-20', '-50.000', 'consume', 'DEMO kitchen use');
// Legacy CRM POS-style counter close (order counters that V16 must hide, data kept)
run("INSERT INTO dd_counter_closes(business_date,counter,total,cash,card,upi,orders_success,orders_cancelled,created_by) VALUES('2026-10-20','Main',4500000,2200000,500000,1800000,120,3,1)");
run("INSERT INTO dd_reservations(date,time,name,covers,status) VALUES('2026-10-25','20:00','DEMO Guest',4,'booked')");
run("INSERT INTO dd_feedback(date,rating,comment) VALUES('2026-10-25',5,'DEMO feedback')");
run("INSERT INTO dd_menu(name,category,price) VALUES('DEMO Dal Makhani','Main',32000)");
audit($owner, 'staging.demo_seed', ['note' => 'DEMO data only']);
echo "Demo legacy data loaded.\n";
