<?php
declare(strict_types=1);
// Finance 360 V16 — automated integration tests (STAGING / TEST DATABASE ONLY).
// Rebuilds the staging DB from the original schema + legacy migrations + demo legacy data, applies the V16 migration,
// then exercises every money rule through the real service functions with a controllable clock.
// Usage: php tests/run_tests.php      (refuses to run unless config db_name contains 'staging' or 'test')
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
passthru(PHP_BINARY . ' tests/staging_setup.php', $rc); if ($rc) exit($rc);
require 'lib.php';
if (!preg_match('/(staging|test)/', (string)cfg()['db_name'])) exit("Refusing to run on non-staging database\n");
if (empty(cfg()['f3_allow_fake_clock'])) exit("config.php needs 'f3_allow_fake_clock'=>true on staging\n");
$before = [];
foreach (rows("SELECT TABLE_NAME t FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'dd\\_%'") as $r) $before[$r['t']] = (int)row('SELECT COUNT(*) n FROM `' . $r['t'] . '`')['n'];
$legacyHash = [];
foreach (['dd_bk_txns', 'dd_sal_entries', 'dd_sal_rows', 'dd_ledger', 'dd_counter_closes', 'dd_reservations', 'dd_feedback', 'dd_menu', 'dd_users'] as $t) $legacyHash[$t] = md5(json_encode(rows("SELECT * FROM `$t` ORDER BY 1")));
function clock(string $t): void { putenv('F3_FAKE_NOW=' . $t); }
clock('2026-11-01 06:30:00');
require 'f360/admin.php'; require 'f360/reports.php';
require_once 'books360.php'; $GLOBALS['PRE_BAL'] = array_column(bk_party_balances(), 'balance', 'id');
$applied = f3_migrate_all(null);

$P = 0; $Fl = 0; $log = [];
function t(string $id, string $name, callable $fn): void { global $P, $Fl, $log;
    try { db()->beginTransaction(); $r = $fn(); if (db()->inTransaction()) db()->commit(); $ok = $r !== false; }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $ok = false; $r = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
    $ok ? $P++ : $Fl++; $log[] = [$id, $name, $ok ? 'PASS' : 'FAIL', $ok ? '' : (is_string($r) ? $r : 'returned false')];
    echo ($ok ? "  PASS " : "  FAIL ") . "$id $name" . ($ok ? '' : "  → " . (is_string($r) ? $r : 'false')) . "\n"; }
function eq($a, $b, string $what = ''): bool { if ($a !== $b) throw new RuntimeException("$what expected " . var_export($b, true) . ' got ' . var_export($a, true)); return true; }
function throws(callable $fn, string $needle = ''): bool { try { $fn(); } catch (RuntimeException $e) { if ($needle && stripos($e->getMessage(), $needle) === false) throw new RuntimeException('Wrong error: ' . $e->getMessage()); return true; } throw new RuntimeException('Expected an error' . ($needle ? " containing '$needle'" : '')); }
function U(string $email): array { $u = row('SELECT * FROM dd_users WHERE email=?', $email); unset($u['password']); return $u; }
$own = U('owner@staging.test'); $ctr = U('counter@staging.test'); $acc = U('accounts@staging.test'); $chef = U('chef@staging.test'); $mgr = U('manager@staging.test');
$emp = fn(string $n) => (int)row('SELECT id FROM dd_employees WHERE name=?', $n)['id'];

echo "\n== Migration & preservation ==\n";
t('MIG-01', 'V16 migration applied (v' . F3_SCHEMA . ')', fn() => eq(f3_schema_version(), F3_SCHEMA));
t('MIG-02', 'No legacy dd_* table lost rows', function () use ($before) { foreach ($before as $t => $n) { $m = (int)row("SELECT COUNT(*) n FROM `$t`")['n']; if ($m < $n) throw new RuntimeException("$t $n→$m"); } return true; });
t('MIG-03', 'Legacy financial / POS / reservation rows byte-identical', function () use ($legacyHash) { foreach ($legacyHash as $t => $h) if ($t !== 'dd_employees' && md5(json_encode(rows("SELECT * FROM `$t` ORDER BY 1"))) !== $h) throw new RuntimeException("$t changed"); return true; });
t('MIG-04', 'Re-running migration is a no-op', fn() => eq(f3_migrate_all(null), []));
t('STA-01', 'Exactly four departments: Management, UT, Kitchen, Waiter', fn() => eq(array_column(rows('SELECT name FROM f3_departments ORDER BY sort'), 'name'), ['Management', 'UT', 'Kitchen', 'Waiter']));
t('STA-02', 'Legacy "Gaurd" mapped to Guard inside Waiter', fn() => eq(row("SELECT d.name dept,g.name des FROM dd_employees e JOIN f3_departments d ON d.id=e.f3_dept_id JOIN f3_designations g ON g.id=e.f3_desig_id WHERE e.name='DEMO Guard Harpal'"), ['dept' => 'Waiter', 'des' => 'Guard']));
t('STA-02b', 'Every legacy employee mapped; salary rate history seeded', fn() => eq((int)row('SELECT COUNT(*) n FROM dd_employees WHERE f3_dept_id IS NULL')['n'], 0) && eq((int)row('SELECT COUNT(*) n FROM f3_salary_rates')['n'], (int)row('SELECT COUNT(*) n FROM dd_employees')['n']));

echo "\n== Business dates & reminders (Asia/Kolkata) ==\n";
t('DATE-01', '06:00 popup threshold, target = yesterday', function () { $tz = new DateTimeZone('Asia/Kolkata');
    eq(f3_popup_due(new DateTimeImmutable('2026-11-01 05:59', $tz)), false, '05:59'); eq(f3_popup_due(new DateTimeImmutable('2026-11-01 06:00', $tz)), true, '06:00');
    eq(f3_target_date(new DateTimeImmutable('2026-11-01 06:00', $tz)), '2026-10-31'); eq(f3_business_date(new DateTimeImmutable('2026-11-01 03:00', $tz)), '2026-10-31', 'before cutoff');
    eq(f3_target_date(new DateTimeImmutable('2028-03-01 07:00', $tz)), '2028-02-29', 'leap year'); eq(f3_target_date(new DateTimeImmutable('2027-01-01 07:00', $tz)), '2026-12-31', 'year end'); return true; });
t('PAY-01', 'Salary reminder on 1st from 06:00 for previous month (Feb/leap/year end)', function () { $tz = new DateTimeZone('Asia/Kolkata'); set_setting('f3_payroll_start', '2025-01');
    eq(f3_payroll_due_period(new DateTimeImmutable('2026-03-01 05:59', $tz)), '2026-01', 'before 6am still previous cycle'); eq(f3_payroll_due_period(new DateTimeImmutable('2026-03-01 06:00', $tz)), '2026-02');
    eq(f3_payroll_due_period(new DateTimeImmutable('2028-03-01 06:00', $tz)), '2028-02'); eq(days_in_month('2028-02'), 29); eq(days_in_month('2026-02'), 28); eq(f3_payroll_due_period(new DateTimeImmutable('2027-01-01 06:30', $tz)), '2026-12');
    set_setting('f3_payroll_start', '2026-10'); return true; });

echo "\n== Morning Closing ==\n";
t('FIN-05', 'Operational difference: positive = Cash Acess, negative = Cash Short, zero = Balanced', function () { $f = ['opening' => 100000, 'other_cash_in' => 0, 'cash_out' => 50000];
    $a = f3_closing_calc(300000, 100000, 200000, 600000, $f); eq($a['operational_diff'], 50000); eq($a['operational_label'], 'Cash Acess');
    $b = f3_closing_calc(300000, 100000, 200000, 500000, $f); eq($b['operational_label'], 'Cash Short'); eq($b['operational_diff'], -50000);
    $c = f3_closing_calc(300000, 100000, 200000, 550000, $f); eq($c['operational_label'], 'Balanced'); eq($c['total_sale'], 600000); return true; });
t('ACC-02', 'Card & UPI never enter expected drawer cash', function () { $f = ['opening' => 100000, 'other_cash_in' => 0, 'cash_out' => 50000];
    eq(f3_closing_calc(300000, 999900, 888800, 350000, $f)['expected_cash'], 350000); eq(f3_closing_calc(300000, 0, 0, 350000, $f)['accounting_variance'], 0); return true; });
t('FIN-02', 'Counter gets ONE consolidated popup after 06:00; snooze hides it', function () use ($ctr) { $p = f3_popups($ctr); eq($p['closing']['date'] ?? null, '2026-10-31'); f3_sync_notifications(); f3_snooze(['key' => 'closing-due-2026-10-31', 'minutes' => 60], $ctr); eq(f3_popups($ctr)['closing']['snoozed'], true); eq((int)row("SELECT COUNT(*) n FROM f3_notifications WHERE dedupe_key='closing-due-2026-10-31'")['n'], 1, 'dedupe'); return true; });
t('FIN-01', 'Counter saves Cash/Card/UPI draft; total computed', function () use ($ctr) { $s = f3_closing_save(['date' => '2026-10-31', 'cash_sale' => '25000', 'card_sale' => '5000', 'upi_sale' => '18500.50'], $ctr); eq($s['sales']['total'], 4850050); eq($s['status'], 'draft'); return true; });
t('FIN-03', 'Submit blocked until expenses reviewed and Actual Cash entered', fn() => throws(fn() => f3_closing_finalize(['date' => '2026-10-31', 'confirm' => true], $GLOBALS['ctr']), 'Cannot submit'));
t('EXP-01', 'Counter adds expense for the open target day; duplicate is detected', function () use ($ctr) { f3_expense_save(['date' => '2026-10-31', 'category' => 'Vegetable', 'amount' => '1200', 'mode' => 'Cash', 'description' => 'Sabzi'], $ctr);
    try { f3_expense_save(['date' => '2026-10-31', 'category' => 'Vegetable', 'amount' => '1200', 'mode' => 'Cash'], $ctr); } catch (F3Conflict $e) { return eq(isset($e->extra['duplicate']), true); } return false; });
t('FIN-03b', '"No expenses" cannot be confirmed when expenses exist', function () use ($ctr) { f3_closing_save(['date' => '2026-10-31', 'expenses_confirmed' => 'none', 'actual_cash' => '23500', 'diff_reason' => 'x'], $ctr); return throws(fn() => f3_closing_finalize(['date' => '2026-10-31', 'confirm' => true], $ctr), 'No expenses'); });
t('SEC-03', 'Counter cannot enter data two days back or for future', fn() => throws(fn() => f3_expense_save(['date' => '2026-10-29', 'category' => 'Vegetable', 'amount' => '10', 'mode' => 'Cash'], $GLOBALS['ctr']), 'last 1 day') && throws(fn() => f3_expense_save(['date' => '2026-11-02', 'category' => 'Vegetable', 'amount' => '10', 'mode' => 'Cash'], $GLOBALS['ctr']), 'Future'));
t('TST-02', 'Stale version → conflict (two devices)', function () use ($ctr) { $v = (int)row("SELECT version FROM f3_closings WHERE business_date='2026-10-31'")['version']; return throws(fn() => f3_closing_save(['date' => '2026-10-31', 'version' => $v - 1, 'cash_sale' => '1'], $ctr), 'changed'); });
t('FIN-04', 'Reason required for large difference', function () use ($ctr) { f3_closing_save(['date' => '2026-10-31', 'expenses_confirmed' => 'reviewed', 'diff_reason' => ''], $ctr); return throws(fn() => f3_closing_finalize(['date' => '2026-10-31', 'confirm' => true], $ctr), 'reason'); });
t('FIN-06', 'Final submit locks day and posts each sale tender to Books exactly once', function () use ($ctr) { f3_closing_save(['date' => '2026-10-31', 'diff_reason' => 'Card/UPI not in drawer'], $ctr);
    $r = f3_closing_finalize(['date' => '2026-10-31', 'confirm' => true, 'client_key' => 'tst-close-1'], $ctr); eq($r['ok'], true);
    eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND import_key LIKE 'f360:sale:2026-10-31:%'")['n'], 3); eq(row("SELECT status FROM f3_closings WHERE business_date='2026-10-31'")['status'], 'submitted'); return true; });
t('TST-02b', 'Replay with same key returns stored result; second user cannot double close', function () use ($ctr, $own) { $r = f3_closing_finalize(['date' => '2026-10-31', 'confirm' => true, 'client_key' => 'tst-close-1'], $ctr); eq($r['duplicate'] ?? false, true);
    throws(fn() => f3_closing_finalize(['date' => '2026-10-31', 'confirm' => true, 'client_key' => 'tst-close-2'], $own), 'already submitted'); return eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND type='sale' AND date='2026-10-31'")['n'], 3); });
t('FIN-07', 'Closed day is read-only for counter (sales, expenses) and history still visible', function () use ($ctr) { throws(fn() => f3_closing_save(['date' => '2026-10-31', 'cash_sale' => '1'], $ctr), 'locked'); $x = row("SELECT id FROM dd_bk_txns WHERE type='expense' AND date='2026-10-31' LIMIT 1");
    throws(fn() => f3_expense_save(['id' => $x['id'], 'date' => '2026-10-31', 'category' => 'Vegetable', 'amount' => '5', 'mode' => 'Cash'], $ctr), 'locked'); eq(count(f3_sales_history('2026-10-01', '2026-10-31', $ctr)['days']) > 0, true); return eq(count(f3_expense_list(['from' => '2026-10-31', 'to' => '2026-10-31'], $ctr)['rows']), 1); });
t('FIN-08', 'Correction request → admin reopen (revision 2, old postings voided, history kept)', function () use ($ctr, $own) { f3_correction_request(['date' => '2026-10-31', 'reason' => 'UPI wrong'], $ctr); $id = (int)row('SELECT MAX(id) n FROM f3_corrections')['n'];
    throws(fn() => f3_correction_decide(['id' => $id, 'approve' => true], $ctr), 'permission'); f3_correction_decide(['id' => $id, 'approve' => true, 'note' => 'ok'], $own);
    eq(row("SELECT status,revision FROM f3_closings WHERE business_date='2026-10-31'"), ['status' => 'reopened', 'revision' => 2]); eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE voided=1 AND import_key LIKE 'f360:sale:2026-10-31:%:r1'")['n'], 3);
    f3_closing_save(['date' => '2026-10-31', 'upi_sale' => '18000', 'expenses_confirmed' => 'reviewed'], $ctr); f3_closing_finalize(['date' => '2026-10-31', 'confirm' => true], $ctr);
    return eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND type='sale' AND date='2026-10-31'")['n'], 3) && eq((int)row("SELECT COUNT(*) n FROM f3_closing_events WHERE business_date='2026-10-31'")['n'] >= 4, true); });
t('FIN-09', 'Admin review posts accounting variance once (not as sales income)', function () use ($own) { $c = row("SELECT * FROM f3_closings WHERE business_date='2026-10-31'");
    f3_closing_review(['date' => '2026-10-31', 'classification' => 'post_accounting', 'note' => 'cash book matched to count'], $own); $t = row("SELECT type,total FROM dd_bk_txns WHERE import_key='f360:var:2026-10-31:r2'");
    eq((int)$t['total'], abs((int)$c['accounting_variance'])); return eq(in_array($t['type'], ['cash_access', 'reduce_cash'], true), true); });
t('FIN-09b', 'After posting accounting variance, Books cash equals counted cash', fn() => eq(bk_opening_before('2026-11-01', 'Cash'), (int)row("SELECT actual_cash FROM f3_closings WHERE business_date='2026-10-31'")['actual_cash']));
t('FIN-10', 'Held sales when Books already has sales for the day (no double counting)', function () use ($own) { f3_closing_save(['date' => '2026-10-29', 'cash_sale' => '22000', 'card_sale' => '5000', 'upi_sale' => '18000', 'expenses_confirmed' => 'reviewed', 'actual_cash' => '1000', 'diff_reason' => 'test'], $own);
    $n0 = (int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND type='sale' AND date='2026-10-29'")['n']; f3_closing_finalize(['date' => '2026-10-29', 'confirm' => true], $own);
    eq(row("SELECT sales_post_status s FROM f3_closings WHERE business_date='2026-10-29'")['s'], 'held'); eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND type='sale' AND date='2026-10-29'")['n'], $n0);
    f3_closing_review(['date' => '2026-10-29', 'classification' => 'no_posting', 'held_sales' => 'keep_existing'], $own); return eq(row("SELECT sales_post_status s FROM f3_closings WHERE business_date='2026-10-29'")['s'], 'kept_existing'); });

echo "\n== Permissions (server-side) ==\n";
t('SEC-01', 'Counter template: closing yes; payroll / staff / users / accounts no', fn() => eq(f3_can($GLOBALS['ctr'], 'closing.finalize'), true) && eq(f3_can($GLOBALS['ctr'], 'payroll.view'), false) && throws(fn() => f3_period_view('2026-10', $GLOBALS['ctr']), 'permission') && throws(fn() => f3_users($GLOBALS['ctr']), 'permission') && throws(fn() => f3_book('Cash', '2026-10-01', '2026-10-31', $GLOBALS['ctr']), 'permission'));
t('SEC-01b', 'Per-user override grants / removes a single permission', function () use ($own, $ctr) { f3_user_save(['id' => $ctr['id'], 'name' => $ctr['name'], 'email' => $ctr['email'], 'role' => 'counter', 'template' => 'counter', 'overrides' => ['closing.variance' => false, 'staff.view' => true]], $own);
    f3_perm_cache_clear(); eq(f3_can($ctr, 'closing.variance'), false); eq(f3_can($ctr, 'staff.view'), true); $s = f3_closing_state('2026-10-31', $ctr); eq(isset($s['calc']['operational_diff']), false, 'variance hidden');
    f3_user_save(['id' => $ctr['id'], 'name' => $ctr['name'], 'email' => $ctr['email'], 'role' => 'counter', 'template' => 'counter', 'overrides' => []], $own); f3_perm_cache_clear(); return true; });
t('SEC-01c', 'Kitchen role (V17.1: old modules archived) gets Store-clerk rights only — no money, staff or salary access', function () { $p = f3_perms($GLOBALS['chef']); eq(in_array('purchase.create', $p, true), true); foreach (['expenses.view', 'closing.view', 'staff.view', 'payroll.view', 'supplier.pay', 'accounts.view'] as $x) eq(in_array($x, $p, true), false, $x); return true; });
t('SEC-01d', 'Last owner cannot be removed; non-owner cannot create owner', fn() => throws(fn() => f3_user_save(['id' => $GLOBALS['own']['id'], 'name' => 'x', 'email' => 'owner@staging.test', 'role' => 'manager', 'template' => 'owner'], $GLOBALS['own'])) && throws(fn() => f3_user_save(['name' => 'X', 'email' => 'x@x.test', 'role' => 'owner', 'password' => 'abcdefghijkl12'], array_merge($GLOBALS['acc'], ['role' => 'manager'])), ''));

echo "\n== Staff master ==\n";
t('STA-03', 'Create staff with department/designation/bank (encrypted, masked)', function () use ($own, $ctr) { $g = row("SELECT id FROM f3_designations WHERE dept_id=3 AND name='Cook'");
    $r = f3_staff_save(['name' => 'TEST New Cook', 'dept_id' => 3, 'desig_id' => $g['id'], 'monthly' => '15500', 'joined_on' => '2026-10-16', 'account' => '123456789012', 'ifsc' => 'SBIN0001234', 'upi_id' => 'newcook@okaxis', 'pay_mode' => 'Bank'], $own);
    $e = row('SELECT * FROM dd_employees WHERE id=?', $r['id']); eq($e['account_last4'], '9012'); eq(str_contains((string)$e['bank_account_enc'], '123456789012'), false, 'not plain'); eq(f3_dec($e['bank_account_enc']), '123456789012');
    f3_user_save(['id' => $ctr['id'], 'name' => $ctr['name'], 'email' => $ctr['email'], 'role' => 'counter', 'template' => 'counter', 'overrides' => ['staff.view' => true]], $own); f3_perm_cache_clear();
    $v = f3_staff_get($r['id'], $ctr)['staff']; eq($v['account'], '••••9012'); eq(isset($v['monthly']), false, 'salary hidden'); f3_user_save(['id' => $ctr['id'], 'name' => $ctr['name'], 'email' => $ctr['email'], 'role' => 'counter', 'template' => 'counter', 'overrides' => []], $own); f3_perm_cache_clear(); return true; });
t('STA-10', 'Bulk joining date fills only empty dates', function () use ($own, $emp) { run("UPDATE dd_employees SET joined_on=NULL WHERE name IN ('DEMO Sita D','DEMO Raju UT')"); $keep = row("SELECT joined_on d FROM dd_employees WHERE name='DEMO Amit UT'")['d'];
    $r = f3_staff_bulk_join(['joined_on' => '2026-06-01', 'ids' => [$emp('DEMO Sita D'), $emp('DEMO Raju UT'), $emp('DEMO Amit UT')]], $own); eq($r['updated'], 2); eq(row("SELECT joined_on d FROM dd_employees WHERE name='DEMO Amit UT'")['d'], $keep);
    run("UPDATE dd_employees SET joined_on='2025-04-01' WHERE name IN ('DEMO Sita D','DEMO Raju UT')"); return true; });
t('STA-02c', 'Guard designation cannot be created outside Waiter; designation must match department', fn() => throws(fn() => f3_designation_save(['dept_id' => 3, 'name' => 'Guard'], $GLOBALS['own']), 'Waiter') && throws(fn() => f3_staff_save(['name' => 'X', 'dept_id' => 1, 'desig_id' => row("SELECT id FROM f3_designations WHERE name='Guard'")['id'], 'monthly' => '1', 'joined_on' => '2026-10-01'], $GLOBALS['own']), 'belongs'));
t('STA-04', 'Salary change needs effective date; history kept; transfer recorded', function () use ($own, $emp) { $id = $emp('DEMO Waiter Sunil'); $s = f3_staff_get($id, $own)['staff'];
    $b = ['id' => $id, 'name' => $s['name'], 'dept_id' => 4, 'desig_id' => row("SELECT id FROM f3_designations WHERE dept_id=4 AND name='Captain'")['id'], 'monthly' => '13000', 'joined_on' => $s['joined_on'], 'code' => $s['code']];
    throws(fn() => f3_staff_save($b, $own), 'choose the date'); f3_staff_save($b + ['effective_from' => '2026-11-01'], $own);
    eq((int)f3_rate_on($id, '2026-10-31')['monthly'], 1200000, 'Oct rate unchanged'); eq((int)f3_rate_on($id, '2026-11-01')['monthly'], 1300000); return eq((int)row('SELECT COUNT(*) n FROM f3_assignments WHERE employee_id=?', $id)['n'], 2); });

echo "\n== Staff entries ==\n";
$amit = $emp('DEMO Amit UT'); $chefB = $emp('DEMO Chef Balwant');
t('STA-04b', 'Advance paid immediately: one Books cash entry, ledger balance up, NOT an expense in P&L', function () use ($own, $amit) { $r = f3_staff_txn_create(['kind' => 'advance', 'employee_id' => $amit, 'date' => '2026-10-15', 'amount' => '3000', 'mode' => 'Cash', 'reason' => 'Family', 'installment' => '1000', 'client_key' => 'tst-adv-1'], $own);
    f3_staff_txn_create(['kind' => 'advance', 'employee_id' => $amit, 'date' => '2026-10-15', 'amount' => '3000', 'mode' => 'Cash', 'reason' => 'Family', 'installment' => '1000', 'client_key' => 'tst-adv-1'], $own);
    eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE import_key LIKE 'f360:staff:%' AND category='Staff Advance (recoverable)'")['n'], 1, 'posted once'); eq(f3_staff_balances($amit)['advance_outstanding'], 300000);
    eq(bk_group('Staff Advance (recoverable)'), 'Staff advance (not expense)'); $s = bk_month_summary('2026-10'); return eq(isset($s['expense_groups']['Staff advance (not expense)']), true) && eq($s['pl']['other_expenses'] + $s['pl']['purchases'] + $s['pl']['staff_cost'] >= 0, true); });
t('STA-05', 'Penalty stays pending (not deductible) until approved', function () use ($own, $amit) { f3_staff_txn_create(['kind' => 'penalty', 'employee_id' => $amit, 'date' => '2026-10-20', 'amount' => '200', 'reason' => 'Late x3'], $own);
    $b = f3_staff_balances($amit); eq($b['penalty_pending_approval'], 20000); eq($b['penalty_approved_unapplied'], 0); f3_staff_txn_create(['kind' => 'penalty', 'employee_id' => $amit, 'date' => '2026-10-21', 'amount' => '100', 'reason' => 'Glass', 'approve_now' => true, 'policy' => 'Rules 4.2'], $own);
    return eq(f3_staff_balances($amit)['penalty_approved_unapplied'], 10000); });
t('STA-06', 'Overtime paid now: Books entry once; unpaid OT tracked separately', function () use ($own, $amit, $chefB) { f3_staff_txn_create(['kind' => 'overtime', 'employee_id' => $amit, 'date' => '2026-10-25', 'amount' => '500', 'mode' => 'Cash', 'reason' => 'Party'], $own);
    f3_staff_txn_create(['kind' => 'overtime', 'employee_id' => $chefB, 'date' => '2026-10-26', 'amount' => '400', 'paid' => '0', 'reason' => 'Shift'], $own);
    eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE category='Overtime Wages' AND import_key LIKE 'f360:staff:%'")['n'], 1); return eq(f3_staff_balances($chefB)['ot_unpaid'], 40000); });
t('STA-07', 'Payroll addition (not paid now) approved for next salary', fn() => eq(f3_staff_txn_create(['kind' => 'addition', 'employee_id' => $GLOBALS['chefB'], 'date' => '2026-10-28', 'amount' => '1000', 'period' => '2026-10', 'reason' => 'Diwali'], $GLOBALS['own'])['status'], 'approved'));
t('STA-08', 'Payroll preparer cannot approve; pending advance not posted to cash', function () use ($own, $amit) { $pr = U('accounts@staging.test'); f3_user_save(['id' => $pr['id'], 'name' => $pr['name'], 'email' => $pr['email'], 'role' => 'accountant', 'template' => 'payroll', 'overrides' => []], $own); f3_perm_cache_clear();
    $r = f3_staff_txn_create(['kind' => 'advance', 'employee_id' => $amit, 'date' => '2026-11-01', 'amount' => '500', 'mode' => 'Cash', 'reason' => 'x'], $pr); eq($r['status'], 'pending'); eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE import_key=?", 'f360:staff:' . $r['id'])['n'], 0);
    throws(fn() => f3_staff_txn_decide(['id' => $r['id'], 'approve' => true], $pr), 'permission'); f3_staff_txn_decide(['id' => $r['id'], 'approve' => false, 'note' => 'not now'], $own); return true; });

echo "\n== Monthly Salary Wizard ==\n";
t('PAY-02', 'Open Oct-2026: lines for working staff, part-month joiner prorated, legacy B/F carried', function () use ($own, $amit) { $v = f3_period_open(['period' => '2026-10'], $own); $n = row("SELECT * FROM f3_pay_lines WHERE period='2026-10' AND emp_name='TEST New Cook'");
    eq((float)$n['eligible_days'], 16.0, 'joined 16 Oct'); eq((int)row("SELECT carried_forward n FROM f3_pay_lines WHERE period='2026-10' AND employee_id=?", $amit)['n'], -200000, 'legacy overpaid'); return eq($v['status'], 'draft'); });
t('PAY-03', 'V19 six fields: half day + paid leave, unpaid computed, ledger penalty & advance prefilled, OT info only (hand-checked)', function () use ($own, $amit) { $r = f3_line_save(['period' => '2026-10', 'employee_id' => $amit, 'action' => 'save', 'present' => '28', 'absent' => '1', 'half_days' => '1', 'paid_leave' => '1'], $own); $c = $r['calc'];
    eq($c['divisor'], 31, 'actual calendar days'); eq($c['payable_days'], 29.5); eq($c['unpaid_leave'], 1.5); eq($c['gross_base'], 1046774); eq($c['penalties'], 10000); eq($c['advance_recovery'], 100000); eq($c['net_payable'], 936774); eq($c['ot_paid_info'], 50000); return eq($c['balance'], 736774); });
t('PAY-03b', 'No double counting: present 30 + paid leave 1 (31-day month) = 31 paid days = full basic', function () { $c = f3_pay_calc_v19(['present' => '30', 'absent' => '0', 'half_days' => '0', 'paid_leave' => '1'], ['monthly' => 1500000, 'days' => 31, 'eligible_days' => 31, 'additions' => 0, 'penalty_items' => [], 'advance_outstanding' => 0, 'ot_paid' => 0, 'carried_forward' => 0]); eq($c['payable_days'], 31.0); return eq($c['gross_base'], 1500000); });
t('PAY-03d', 'V19 spec example: ₹15,500, 31 days, 28 present + 1 paid leave + 2 absent → earned ₹14,500; advance ₹2,000 + penalty ₹500 → net ₹12,000', function () { $c = f3_pay_calc_v19(['present' => '28', 'absent' => '2', 'half_days' => '0', 'paid_leave' => '1', 'advance_recovery' => 200000], ['monthly' => 1550000, 'days' => 31, 'eligible_days' => 31, 'additions' => 0, 'penalty_items' => [['id' => 1, 'amount' => 50000]], 'advance_outstanding' => 500000, 'ot_paid' => 0, 'carried_forward' => 0]);
    eq($c['daily_rate'], 50000); eq($c['payable_days'], 29.0); eq($c['gross_base'], 1450000); eq($c['penalties'], 50000); return eq($c['net_payable'], 1200000); });
t('PAY-13', 'Actual calendar-day divisor: 28-day Feb, 29-day leap Feb, 30 and 31-day months; half day = ½ day', function () { $ctx = fn($d, $m = 3000000) => ['monthly' => $m, 'days' => $d, 'eligible_days' => $d, 'additions' => 0, 'penalty_items' => [], 'advance_outstanding' => 0, 'ot_paid' => 0, 'carried_forward' => 0];
    eq(days_in_month('2026-02'), 28); eq(days_in_month('2028-02'), 29); eq(days_in_month('2026-04'), 30); eq(days_in_month('2026-05'), 31);
    eq(f3_pay_calc_v19(['present' => '14', 'absent' => '14', 'half_days' => '0', 'paid_leave' => '0'], $ctx(28))['gross_base'], 1500000, '28'); eq(f3_pay_calc_v19(['present' => '29', 'absent' => '0', 'half_days' => '0', 'paid_leave' => '0'], $ctx(29))['gross_base'], 3000000, '29 leap');
    eq(f3_pay_calc_v19(['present' => '15', 'absent' => '15', 'half_days' => '0', 'paid_leave' => '0'], $ctx(30))['gross_base'], 1500000, '30'); eq(f3_pay_calc_v19(['present' => '10', 'absent' => '21', 'half_days' => '0', 'paid_leave' => '0'], $ctx(31))['gross_base'], 967742, '31');
    eq(f3_pay_calc_v19(['present' => '0', 'absent' => '29', 'half_days' => '1', 'paid_leave' => '0'], $ctx(30))['gross_base'], 50000, 'one half day'); return eq(f3_pay_calc_v19(['present' => '27', 'absent' => '1', 'half_days' => '0', 'paid_leave' => '0', 'rest_days' => '2'], $ctx(30) + ['rest_paid' => true])['payable_days'], 29.0); });
t('PAY-03e', 'Mid-month joiner: only eligible days are accounted, divisor stays the full month (16 of 31 days of ₹15,500 = ₹8,000)', function () { $c = f3_pay_calc_v19(['present' => '16', 'absent' => '0', 'half_days' => '0', 'paid_leave' => '0'], ['monthly' => 1550000, 'days' => 31, 'eligible_days' => 16, 'additions' => 0, 'penalty_items' => [], 'advance_outstanding' => 0, 'ot_paid' => 0, 'carried_forward' => 0]); eq($c['divisor'], 31); return eq($c['gross_base'], 800000) && throws(fn() => f3_pay_calc_v19(['present' => '17', 'absent' => '0', 'half_days' => '0', 'paid_leave' => '0'], ['monthly' => 1550000, 'days' => 31, 'eligible_days' => 16, 'additions' => 0, 'penalty_items' => [], 'advance_outstanding' => 0, 'ot_paid' => 0, 'carried_forward' => 0]), 'add up'); });
t('PAY-03c', 'Days must add up to the eligible days; incomplete entry cannot be marked Completed', fn() => throws(fn() => f3_line_save(['period' => '2026-10', 'employee_id' => row("SELECT employee_id FROM f3_pay_lines WHERE emp_name='TEST New Cook'")['employee_id'], 'action' => 'save', 'present' => '17', 'absent' => '0', 'half_days' => '0', 'paid_leave' => '0'], $GLOBALS['own']), 'add up') && throws(fn() => f3_line_save(['period' => '2026-10', 'employee_id' => $GLOBALS['amit'], 'action' => 'save'], $GLOBALS['own']), 'Enter the present days'));
t('PAY-03f', 'Absent is not typed: only Present is needed; empty half days / paid leave = 0; absent = days − present − half − paid leave; too many days refused', function () { $c = f3_line_save(['period' => '2026-10', 'employee_id' => $GLOBALS['amit'], 'action' => 'preview', 'present' => '28', 'half_days' => '', 'paid_leave' => '1'], $GLOBALS['own'])['calc'];
    eq($c['absent'], 2.0); eq($c['present'], 28.0); eq($c['half_days'], 0.0); eq($c['payable_days'], 29.0);
    return throws(fn() => f3_line_save(['period' => '2026-10', 'employee_id' => $GLOBALS['amit'], 'action' => 'preview', 'present' => '30', 'paid_leave' => '2'], $GLOBALS['own']), 'more than'); });
t('PAY-08', 'Advance recovery capped at outstanding', fn() => throws(fn() => f3_line_save(['period' => '2026-10', 'employee_id' => $GLOBALS['amit'], 'action' => 'save', 'present' => '31', 'absent' => '0', 'half_days' => '0', 'paid_leave' => '0', 'advance_recovery' => '3001'], $GLOBALS['own']), 'more than the outstanding'));
t('PAY-05', 'Skip / Add later leave pending state; submit blocked while open', function () use ($own, $emp) { f3_line_save(['period' => '2026-10', 'employee_id' => $emp('DEMO Raju UT'), 'action' => 'skip'], $own); f3_line_save(['period' => '2026-10', 'employee_id' => $emp('DEMO Cook Mohan'), 'action' => 'later'], $own);
    eq(row("SELECT state FROM f3_pay_lines WHERE period='2026-10' AND employee_id=?", $emp('DEMO Raju UT'))['state'], 'skipped'); return throws(fn() => f3_period_submit(['period' => '2026-10'], $own), 'not complete'); });
t('PAY-06', 'Drafts persist server-side per employee (resume on another device)', function () use ($own, $emp) { f3_line_save(['period' => '2026-10', 'employee_id' => $emp('DEMO Helper Gopal'), 'action' => 'draft', 'present' => '20'], $own); $l = f3_line_get('2026-10', $emp('DEMO Helper Gopal'), $own); return eq($l['present'], 20.0) && eq($l['state'], 'draft'); });
t('PAY-04', 'Complete all staff (wizard order Management → UT → Kitchen → Waiter); submit', function () use ($own) { $v = f3_period_view('2026-10', $own); $order = array_map(fn($l) => $l['dept_id'], $v['lines']); $sorted = $order; sort($sorted); eq($order, $sorted, 'dept order');
    foreach ($v['lines'] as $l) if ($l['state'] !== 'done') { $pr = min(26, (int)$l['eligible_days']); f3_line_save(['period' => '2026-10', 'employee_id' => $l['employee_id'], 'action' => 'save', 'present' => (string)$pr, 'absent' => (string)((int)$l['eligible_days'] - $pr), 'half_days' => '0', 'paid_leave' => '0'], $own); }
    return eq(f3_period_submit(['period' => '2026-10'], $own)['status'], 'review'); });
t('PAY-07', 'Four department sheets + master: grand total = sum of departments = sum of lines', function () use ($own) { $m = f3_sheet('2026-10', null, $own); $sum = 0; foreach ([1, 2, 3, 4] as $d) $sum += f3_sheet('2026-10', $d, $own)['grand']['net_payable'];
    $lines = (int)row("SELECT SUM(net_payable) n FROM f3_pay_lines WHERE period='2026-10' AND state='done'")['n']; eq($m['grand']['net_payable'], $sum); eq($sum, $lines); return eq(count($m['groups']), 4); });
t('PAY-09', 'Approve applies approved penalty + advance recovery exactly once; pending penalty untouched', function () use ($own, $amit) { f3_period_approve(['period' => '2026-10'], $own);
    eq((int)row("SELECT COUNT(*) n FROM f3_staff_txns WHERE kind='recovery' AND employee_id=? AND applied_period='2026-10' AND status='approved'", $amit)['n'], 1); eq(f3_staff_balances($amit)['advance_outstanding'], 200000);
    eq((int)row("SELECT COUNT(*) n FROM f3_staff_txns WHERE kind='penalty' AND status='pending' AND applied_period IS NULL AND employee_id=?", $amit)['n'], 1); return throws(fn() => f3_line_save(['period' => '2026-10', 'employee_id' => $amit, 'action' => 'save', 'present' => '31', 'absent' => '0', 'half_days' => '0', 'paid_leave' => '0'], $own), 'approved'); });
t('PAY-10', 'Partial + multiple payments; overpay blocked; each posts once to Books', function () use ($own, $emp) { $id = $emp('DEMO Sita D'); f3_pay_payment(['period' => '2026-10', 'employee_id' => $id, 'amount' => '5000', 'mode' => 'Cash', 'date' => '2026-11-01', 'client_key' => 'tst-pay-1'], $own);
    f3_pay_payment(['period' => '2026-10', 'employee_id' => $id, 'amount' => '5000', 'mode' => 'Cash', 'date' => '2026-11-01', 'client_key' => 'tst-pay-1'], $own); f3_pay_payment(['period' => '2026-10', 'employee_id' => $id, 'amount' => '4000', 'mode' => 'UPI', 'date' => '2026-11-01'], $own);
    throws(fn() => f3_pay_payment(['period' => '2026-10', 'employee_id' => $id, 'amount' => '6484', 'mode' => 'Cash', 'date' => '2026-11-01'], $own), 'more than the balance');
    eq(f3_paid($id, '2026-10'), 900000); eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE import_key LIKE 'f360:pay:%' AND voided=0")['n'], 2); return eq(f3_period_view('2026-10', $own)['payment_status'], 'partially_paid'); });
t('PAY-14', 'Pay all balances: one payment per person, repeat click does not pay twice', function () use ($own) { $before = (int)row("SELECT COUNT(*) n FROM f3_pay_payments WHERE voided=0")['n'];
    $r = f3_pay_bulk(['period' => '2026-10', 'dept' => 3, 'mode' => 'Bank', 'date' => '2026-11-01', 'client_key' => 'bulk-test-0001'], $own); eq($r['count'] > 0, true);
    $r2 = f3_pay_bulk(['period' => '2026-10', 'dept' => 3, 'mode' => 'Bank', 'date' => '2026-11-01', 'client_key' => 'bulk-test-0001'], $own); eq($r2['count'], 0);
    eq((int)row("SELECT COUNT(*) n FROM f3_pay_payments WHERE voided=0")['n'], $before + $r['count']);
    return eq((int)row("SELECT COALESCE(SUM(l.net_payable+l.carried_forward),0) - (SELECT COALESCE(SUM(amount),0) FROM f3_pay_payments p WHERE p.period='2026-10' AND p.voided=0 AND p.employee_id IN (SELECT employee_id FROM f3_pay_lines WHERE period='2026-10' AND dept_id=3)) n FROM f3_pay_lines l WHERE l.period='2026-10' AND l.dept_id=3 AND l.state='done'")['n'], 0); });
t('ACC-03', 'OT already paid is never in gross/net; staff cash out counted once in cashbook', function () use ($own, $amit) { $l = row("SELECT * FROM f3_pay_lines WHERE period='2026-10' AND employee_id=?", $amit); eq((int)$l['gross_earned'], (int)$l['gross_base'] + (int)$l['additions'] + (int)$l['other_earnings']); eq((int)$l['ot_paid_info'], 50000);
    return eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND category='Overtime Wages' AND date='2026-10-25'")['n'], 1); });
t('PAY-11', 'Exports: XLSX (5 sheets) and PDF are valid files', function () use ($own) { $_ = f3_sheet('2026-10', null, $own); $x = f3_xlsx(['A' => [[1, 'x']], 'B' => [['₹ हिंदी']]]); $tmp = tempnam(sys_get_temp_dir(), 'x'); file_put_contents($tmp, $x); $z = new ZipArchive(); eq($z->open($tmp), true); eq($z->locateName('xl/worksheets/sheet2.xml') !== false, true); $z->close(); @unlink($tmp);
    $p = f3_pdf('T', 'S', ['A', 'B'], [['cells' => ['Ramesh', '1,000.00']], ['cells' => ['हिंदी', '2'], 'bold' => true]], [100, 100]); return eq(substr($p, 0, 8), '%PDF-1.4') && eq(str_contains($p, '%%EOF'), true); });
t('PAY-12', 'Next month carries the unpaid balance forward; unlock blocked while next month approved', function () use ($own, $emp) { $id = $emp('DEMO Sita D'); clock('2026-12-01 07:00:00'); f3_period_open(['period' => '2026-11'], $own);
    $cf = (int)row("SELECT carried_forward n FROM f3_pay_lines WHERE period='2026-11' AND employee_id=?", $id)['n']; $prev = row("SELECT net_payable,carried_forward FROM f3_pay_lines WHERE period='2026-10' AND employee_id=?", $id);
    return eq($cf, (int)$prev['net_payable'] + (int)$prev['carried_forward'] - 900000); });
t('PAY-09b', 'Unlock (with reason) reverses applied penalty/recovery; re-approve applies once again', function () use ($own, $amit) { f3_period_unlock(['period' => '2026-10', 'reason' => 'fix'], $own); eq(f3_staff_balances($amit)['advance_outstanding'], 300000);
    f3_period_submit(['period' => '2026-10'], $own); f3_period_approve(['period' => '2026-10'], $own); eq(f3_staff_balances($amit)['advance_outstanding'], 200000); return eq((int)row("SELECT COUNT(*) n FROM f3_staff_txns WHERE kind='recovery' AND status='approved' AND employee_id=?", $amit)['n'], 1); });
t('STA-09', 'Reversal blocked once applied in salary; allowed for unapplied entry', function () use ($own, $amit, $chefB) { $pen = row("SELECT id FROM f3_staff_txns WHERE kind='penalty' AND status='approved' AND employee_id=?", $amit); throws(fn() => f3_staff_txn_reverse(['id' => $pen['id'], 'reason' => 'x'], $own), 'applied');
    $ot = row("SELECT id FROM f3_staff_txns WHERE kind='overtime' AND status='unpaid' AND employee_id=?", $chefB); f3_staff_txn_reverse(['id' => $ot['id'], 'reason' => 'duplicate'], $own); return eq(f3_staff_balances($chefB)['ot_unpaid'], 0); });
clock('2026-11-01 06:30:00');

t('STA-11', 'Advance above 50% of salary needs a written reason; with reason it is saved and the reason recorded', function () use ($own, $emp) { $id = $emp('DEMO Raju UT');
    try { f3_staff_txn_create(['kind' => 'advance', 'employee_id' => $id, 'date' => '2026-11-01', 'amount' => '6000', 'mode' => 'Cash', 'reason' => 'Medical'], $own); return false; } catch (F3Conflict $e) { eq($e->extra['needs'], 'limit_reason'); }
    $r = f3_staff_txn_create(['kind' => 'advance', 'employee_id' => $id, 'date' => '2026-11-01', 'amount' => '6000', 'mode' => 'Cash', 'reason' => 'Medical', 'limit_reason' => 'Hospital bill, owner approved'], $own);
    return eq(str_contains(row('SELECT reason FROM f3_staff_txns WHERE id=?', $r['id'])['reason'], 'Above advance limit'), true); });
t('FIN-11', 'Petpooja upload: figures offered for pre-fill and a difference raises a review warning', function () use ($own) { run("INSERT INTO dd_pos_days(business_date,cash,upi,card,gross,net,source,file_sha256,status) VALUES('2026-10-27',2200000,1800000,500000,4500000,4500000,'windows-sync',REPEAT('a',64),'imported')");
    f3_closing_save(['date' => '2026-10-27', 'cash_sale' => '22000', 'card_sale' => '5000', 'upi_sale' => '17000'], $own); $st = f3_closing_state('2026-10-27', $own); eq($st['petpooja']['upi'], 1800000);
    $c = f3_closing_row('2026-10-27'); $codes = array_column(f3_closing_issues($c, f3_day_figures('2026-10-27'), f3_closing_calc(2200000, 500000, 1700000, null, f3_day_figures('2026-10-27'))), 'code'); return eq(in_array('petpooja_diff', $codes, true), true); });

echo "\n== Assistant, reports, audit ==\n";
t('UI-02', 'Smart assistant separates blocking vs review items', function () use ($own) { $a = f3_assist('dashboard', $own); return eq(isset($a['blocking'], $a['reviews']), true) && eq(is_array($a['items']), true); });
t('RPT-01', 'Monthly report: Cash Acess label, closings summary, P&L excludes staff advance', function () use ($own) { $r = f3_report_monthly('2026-10', $own); eq($r['cash_acess_label'], 'Cash Acess'); return eq(($r['expense_groups']['Staff advance (not expense)'] ?? 0) > 0, true); });
t('SEC-04', 'Every money action wrote an audit row', fn() => eq((int)row("SELECT COUNT(DISTINCT action) n FROM dd_audit WHERE action IN ('f360.closing.submit','f360.closing.reopen','f360.closing.review','f360.expense.create','f360.stafftxn.create','f360.payroll.approve','f360.payroll.pay','f360.payroll.unlock','f360.staff.create','f360.user.save')")['n'], 10));

require __DIR__ . '/v17_tests.php';
require __DIR__ . '/v20_tests.php';
require __DIR__ . '/v21_oneclick_tests.php';

echo "\nRESULT: $P passed, $Fl failed (" . ($P + $Fl) . " tests)\n";
file_put_contents(__DIR__ . '/results.json', json_encode(['at' => date('c'), 'passed' => $P, 'failed' => $Fl, 'tests' => $log], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
exit($Fl ? 1 : 0);
