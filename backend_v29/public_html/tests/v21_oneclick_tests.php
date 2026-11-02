<?php
declare(strict_types=1);
// Finance 360 V21.2 — one-click staff import (staff list matched by HR code + name; old months kept as a READ-ONLY record).
// Included by tests/run_tests.php. DEMO data only. Staging only.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once 'f360/emp_import.php';
global $own, $acc, $mgr, $ctr;
function t1(string $id, string $name, callable $fn): void { global $P, $Fl, $log;
    try { $r = $fn(); $ok = $r !== false; } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $ok = false; $r = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
    $ok ? $P++ : $Fl++; $log[] = [$id, $name, $ok ? 'PASS' : 'FAIL', $ok ? '' : (is_string($r) ? $r : 'returned false')];
    echo ($ok ? "  PASS " : "  FAIL ") . "$id $name" . ($ok ? '' : "  → " . (is_string($r) ? $r : 'false')) . "\n"; }
$ok = fn(bool $c, string $m = '') => $c ?: throw new RuntimeException($m ?: 'assertion failed');
echo "\n== V21.2 · One-click staff import + old salary months (record only) ==\n";

$SEC = [1 => 'Management', 2 => 'UT', 3 => 'Kitchen', 4 => 'Waiter'];
$demo = rows("SELECT id,code,name,f3_dept_id,COALESCE(designation,job,'Staff') desig FROM dd_employees WHERE COALESCE(status,'active')='active' AND f3_dept_id IS NOT NULL AND code IS NOT NULL ORDER BY id");
foreach ($demo as &$e) { $rt = f3_rate_on((int)$e['id'], '2026-11-01'); $e['rs'] = $rt ? intdiv((int)$rt['monthly'], 100) : 15000; } unset($e); // keep their current salary: the import never overwrites a later rate
/** Build a one-click workbook in memory: every active DEMO staff by code + name, plus two new people and two old months. */
$book = function (array $over = [], array $extraHist = []) use ($demo, $SEC): string {
    $staff = [['Section', 'S.No', 'Name', 'Designation', 'Monthly salary', 'HR code', 'HR name', 'Joining date', 'Check']]; $n = 0;
    foreach ($demo as $e) $staff[] = [$SEC[(int)$e['f3_dept_id']], ++$n, $e['name'], $e['desig'], $e['rs'], $e['code'], $e['name'], '', ''];
    $staff[] = ['Kitchen', ++$n, 'Omega DEMO Cook', 'Cook', 21000, 'OC901', 'Omega DEMO NewCook', '2026-06-01', ''];
    $staff[] = ['Waiter', ++$n, 'Zeta DEMO Waiter', 'Waiter', 12000, '', '', '', ''];
    foreach ($over as $i => $row) $staff[$i] = $row;
    $hh = ['Section', 'S.No', 'Name', 'HR code', 'Designation', 'Monthly salary', 'Present days', 'Leave encash', 'Working days', 'Total salary', 'Advance', 'Debt', 'Net salary', 'Payment mode', 'Paid', 'Balance', 'Status'];
    $h8 = [$hh, ['Waiter', 1, $demo[0]['name'], $demo[0]['code'], 'Waiter', 15000, 30, 1, 31, 15000, 2000, 100, 12900, 'Cash', 12900, 0, 'Clear'],
        ['Kitchen', 2, 'Omega DEMO Cook', 'OC901', 'Cook', 21000, 28, 0, 31, 18967.74, 0, 0, 18967.74, 'Bank', 18000, 967.74, 'Due'],
        ['Waiter', 3, 'Somebody Left DEMO', '', 'Waiter', 11000, 10, 0, 31, 3548.39, 0, 0, 3548.39, 'Cash', 3548, 0.39, 'Clear']];
    $h9 = [$hh, ['Waiter', 1, $demo[0]['name'], $demo[0]['code'], 'Waiter', 15000, 30, 0, 30, 15000, 0, 0, 15000, 'Cash', 15000, 0, 'Clear']];
    return f3_xlsx(['READ ME' => [['DEMO one-click file']], 'Staff' => $staff, 'History 2026-08' => $h8, 'History 2026-09' => $h9] + $extraHist);
};
$up = fn(string $x, array $u) => transaction(fn() => ei_upload(['oneclick' => ['name' => 'demo_oneclick.xlsx', 'data' => base64_encode($x)]], $u));
$money = fn() => [(int)row('SELECT COUNT(*) n FROM f3_pay_lines')['n'], (int)row('SELECT COUNT(*) n FROM f3_pay_payments')['n'], (int)row('SELECT COUNT(*) n FROM f3_staff_txns')['n'], (int)row('SELECT COUNT(*) n FROM dd_bk_txns')['n'], (int)row('SELECT COUNT(*) n FROM dd_sal_rows')['n'], (int)row('SELECT COUNT(*) n FROM dd_sal_entries')['n'], (int)row('SELECT COUNT(*) n FROM f3_salary_rates WHERE source<>\'import\'')['n']];

t1('OC-01', 'One-click file is validated: no "Staff" sheet, a duplicate HR code, a future history month and a wrong section are refused; nothing is stored', function () use ($book, $up, $own, $ok) {
    $before = (int)row('SELECT COUNT(*) n FROM f3_import_batches')['n'];
    throws(fn() => $up(f3_xlsx(['Sheet1' => [['Name'], ['X']]]), $own), 'no sheet named "Staff"');
    throws(fn() => $up($book([1 => ['Waiter', 1, 'A', 'Waiter', 1000, 'DUP1', '', '', ''], 2 => ['Waiter', 2, 'B', 'Waiter', 1000, 'DUP1', '', '', '']]), $own), 'used twice');
    throws(fn() => $up($book([], ['History 2026-10' => [['Name', 'HR code', 'Monthly salary', 'Paid'], ['A', '', 1, 1]]]), $own), 'after September');
    throws(fn() => $up($book([1 => ['Bar', 1, 'A', 'Waiter', 1000, '', '', '', '']]), $own), 'is not Management, UT, Kitchen or Waiter');
    throws(fn() => transaction(fn() => ei_upload(['oneclick' => ['name' => 'x.xlsx', 'data' => base64_encode('not a zip')]], $own)), 'must be an .xlsx');
    return $ok((int)row('SELECT COUNT(*) n FROM f3_import_batches')['n'] === $before, 'a refused file left a batch behind'); });

t1('OC-02', 'Preview: existing staff matched by HR code + name, new people created, old months summarised (rows, linked by code, totals); reading changes nothing', function () use ($book, $up, $own, $ok, $demo, $money) {
    $m0 = $money(); $e0 = (int)row('SELECT COUNT(*) n FROM dd_employees')['n'];
    $GLOBALS['OC_X'] = $book(); $r = $up($GLOBALS['OC_X'], $own); $GLOBALS['OC_B'] = $r['batch']; $p = ei_preview($r['batch'], $own);
    $ok($p['mode'] === 'oneclick', 'mode'); $ok($p['counts']['review'] === 0, 'review ' . json_encode(array_values(array_filter(array_map(fn($x) => $x['review'] ? $x['sept_name'] . ': ' . $x['review'][0]['text'] : null, $p['rows'])))));
    $ok($p['counts']['create'] === 2, 'create ' . $p['counts']['create']); $ok($p['counts']['update'] + $p['counts']['unchanged'] === count($demo), 'matched existing');
    foreach ($p['rows'] as $x) if ($x['employee_id']) $ok(str_contains($x['match_how'], 'code + name') || str_contains($x['match_how'], 'source link'), 'matched by ' . $x['match_how']);
    $h = array_column($p['history'], null, 'period'); $ok(count($h) === 2 && $h['2026-08']['rows'] === 3 && $h['2026-08']['linked'] === 2 && $h['2026-09']['linked'] === 1, 'history summary ' . json_encode($p['history']));
    $ok($h['2026-08']['monthly'] === 4700000 && $h['2026-08']['paid'] === 3444800, 'history totals ' . $h['2026-08']['monthly'] . ' ' . $h['2026-08']['paid']);
    $ok(str_contains(implode(',', $h['2026-08']['not_linked']), 'Somebody Left DEMO (no code)'), 'unlinked row listed');
    return $ok($money() === $m0 && (int)row('SELECT COUNT(*) n FROM dd_employees')['n'] === $e0 && (int)row('SELECT COUNT(*) n FROM f3_sal_history')['n'] === 0, 'preview wrote something'); });

t1('OC-03', 'Same HR code but a different name is never matched silently (needs review); a stored answer resolves it', function () use ($book, $up, $own, $ok, $demo) {
    $r = $up($book([1 => ['Waiter', 1, 'Totally Different DEMO', 'Waiter', 15000, $demo[0]['code'], 'Totally Different DEMO', '', '']]), $own); $p = ei_preview($r['batch'], $own);
    $rv = array_values(array_filter($p['rows'], fn($x) => $x['action'] === 'review')); $ok(count($rv) >= 1, 'no review at all: ' . json_encode(array_map(fn($x) => [$x['sept_name'], $x['action'], $x['match_how']], array_slice($p['rows'], 0, 2)))); $ok(str_contains($rv[0]['review'][0]['text'], 'different name'), 'no review for code-only match');
    transaction(fn() => ei_decide(['batch' => $r['batch'], 'decisions' => ['emp:' . $rv[0]['key'] => 'new']], $own)); $p = ei_preview($r['batch'], $own);
    run("DELETE FROM f3_import_batches WHERE batch_id=?", $r['batch']); // test-only: discard this unapplied preview
    return $ok(!array_filter($p['rows'], fn($x) => $x['key'] === $rv[0]['key'] && $x['action'] !== 'create'), 'decision not applied'); });

t1('OC-04', 'Apply: staff created/updated; old months stored as record only, linked ONLY by HR code; salary, payments, advances, cash book and old register untouched', function () use ($own, $ok, $money, $demo) {
    $m0 = $money(); $r = ei_apply(['batch' => $GLOBALS['OC_B'], 'confirm' => 'IMPORT', 'backup_ok' => 1], $own);
    $ok($r['done']['create'] === 2 && $r['done']['history_rows'] === 4 && $r['done']['history_linked'] === 3, 'done ' . json_encode($r['done']));
    $new = row("SELECT id,code FROM dd_employees WHERE name='Omega DEMO NewCook'"); $ok($new && $new['code'] === 'OC901', 'HR name + code used for the new record');
    $h = rows('SELECT name,hr_code,employee_id,paid,balance FROM f3_sal_history WHERE period=? ORDER BY sheet_row', '2026-08');
    $ok((int)$h[0]['employee_id'] === (int)$demo[0]['id'] && (int)$h[1]['employee_id'] === (int)$new['id'] && $h[2]['employee_id'] === null, 'links ' . json_encode($h));
    $ok((int)$h[1]['paid'] === 1800000 && (int)$h[1]['balance'] === 96774, 'amounts in paise');
    return $ok($money() === $m0, 'money tables changed: ' . json_encode([$m0, $money()])); });

t1('OC-05', 'Salary history view: needs payroll permission; month tabs, totals, per-staff history; nothing editable', function () use ($own, $ctr, $ok, $demo) {
    throws(fn() => ei_history_view([], $ctr), 'permission');
    $v = ei_history_view([], $own); $ok($v['period'] === '2026-09' && count($v['months']) === 2 && str_contains($v['note'], 'Not used for salary'), 'view');
    $e = ei_history_view(['employee' => (string)$demo[0]['id']], $own); $ok(count($e['rows']) === 2 && $e['employee']['id'] == $demo[0]['id'], 'staff view');
    $m8 = array_column($v['months'], null, 'period')['2026-08']; return $ok($m8['n'] === 3 && $m8['linked'] === 2 && $m8['paid'] === 3444800, 'month totals'); });

t1('OC-06', 'Same file again → same batch, nothing changes; answers from a 3-file import are never carried into a one-click batch', function () use ($book, $up, $own, $ok) {
    $n = (int)row('SELECT COUNT(*) n FROM f3_sal_history')['n']; $r = $up($GLOBALS['OC_X'], $own); $ok($r['existing'] && $r['status'] === 'applied', 'same batch');
    $a = ei_apply(['batch' => $r['batch'], 'confirm' => 'IMPORT'], $own); $ok(!empty($a['already']), 'applied twice');
    run("UPDATE f3_import_batches SET files=REPLACE(files,'\"oneclick\"','\"oneclickX\"'), decisions='{\"src:S2\":\"new\"}' WHERE batch_id=?", $GLOBALS['OC_B']); // pretend the last applied batch was a 3-file one
    $r2 = $up($book([], ['History 2026-07' => [['Name', 'HR code', 'Monthly salary', 'Paid'], ['July Row DEMO', '', 1000, 1000]]]), $own); $d = row('SELECT decisions FROM f3_import_batches WHERE batch_id=?', $r2['batch'])['decisions'];
    run("UPDATE f3_import_batches SET files=REPLACE(files,'\"oneclickX\"','\"oneclick\"') WHERE batch_id=?", $GLOBALS['OC_B']); run("UPDATE f3_import_batches SET decisions=NULL WHERE batch_id=?", $GLOBALS['OC_B']);
    run('DELETE FROM f3_import_batches WHERE batch_id=?', $r2['batch']);
    return $ok($d === null, 'decisions were inherited across formats: ' . $d) && $ok((int)row('SELECT COUNT(*) n FROM f3_sal_history')['n'] === $n, 'history changed'); });

t1('OC-07', 'A corrected file replaces the old-month copy; undo restores the earlier copy; undoing the first import removes its staff and history', function () use ($book, $up, $own, $ok, $money) {
    $m0 = $money(); $e0 = rows('SELECT id,code,name,status,f3_dept_id FROM dd_employees ORDER BY id');
    $r = $up($book([], ['History 2026-07' => [['Name', 'HR code', 'Monthly salary', 'Paid'], ['July Row DEMO', '', 1000, 1000]]]), $own);
    $pv = ei_preview($r['batch'], $own); $ok($pv['counts']['review'] === 0, 'review: ' . json_encode(array_merge(array_map(fn($x) => $x['sept_name'] . ': ' . ($x['review'][0]['text'] ?? ''), array_filter($pv['rows'], fn($x) => $x['action'] === 'review')), array_map(fn($x) => $x['name'] . ': ' . $x['reason'], array_filter($pv['inactive'], fn($x) => $x['action'] === 'review')))));
    ei_apply(['batch' => $r['batch'], 'confirm' => 'IMPORT', 'backup_ok' => 1], $own);
    $ok((int)row("SELECT COUNT(*) n FROM f3_sal_history WHERE superseded_by IS NULL")['n'] === 5 && (int)row('SELECT COUNT(*) n FROM f3_sal_history WHERE superseded_by=?', $r['batch'])['n'] === 4, 'supersede');
    throws(fn() => ei_rollback(['batch' => $GLOBALS['OC_B'], 'reason' => 'test'], $own), 'later import');
    ei_rollback(['batch' => $r['batch'], 'reason' => 'test second'], $own);
    $ok((int)row("SELECT COUNT(*) n FROM f3_sal_history WHERE superseded_by IS NULL")['n'] === 4, 'earlier copy back');
    ei_rollback(['batch' => $GLOBALS['OC_B'], 'reason' => 'test first'], $own);
    $ok((int)row('SELECT COUNT(*) n FROM f3_sal_history')['n'] === 0 && !row("SELECT 1 FROM dd_employees WHERE code='OC901'"), 'first undo');
    return $ok($money() === $m0, 'money changed'); });

t1('COL-01', 'Mixed text collations (old vs new tables) are found and aligned to the database default; text is kept; the 1267 error disappears', function () use ($ok, $own) {
    $t = f3_db_collation_target(); $alt = $t === 'utf8mb4_unicode_ci' ? 'utf8mb4_general_ci' : 'utf8mb4_unicode_ci';
    $name = (string)row('SELECT name FROM dd_employees ORDER BY id LIMIT 1')['name'];
    db()->exec("ALTER TABLE dd_employees CONVERT TO CHARACTER SET utf8mb4 COLLATE $alt");
    $ok(in_array('dd_employees', f3_collation_report()['tables'], true), 'not reported');
    throws(fn() => row('SELECT COUNT(*) n FROM dd_employees e JOIN f3_designations d ON d.name=e.designation'), 'Illegal mix of collations');
    $r = f3_fix_collations(); $ok(in_array('dd_employees', $r['converted'], true) && !$r['failed'], 'not converted ' . json_encode($r));
    $ok(f3_collation_report()['tables'] === [], 'still mixed'); row('SELECT COUNT(*) n FROM dd_employees e JOIN f3_designations d ON d.name=e.designation');
    return $ok((string)row('SELECT name FROM dd_employees ORDER BY id LIMIT 1')['name'] === $name, 'text changed'); });

t1('COL-02', 'Connection collation differs from the database (as on the live MariaDB 11.8 host): passed-in values vs typed-in text fail with 1267; the app\'s connection setup fixes it', function () use ($ok) {
    $c = cfg(); $pdo = new PDO('mysql:host=' . ($c['db_host'] ?? 'localhost') . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $dbc = (string)$pdo->query('SELECT @@collation_database')->fetchColumn(); $alt = $dbc === 'utf8mb4_unicode_ci' ? 'utf8mb4_general_ci' : 'utf8mb4_unicode_ci';
    $pdo->exec("SET collation_connection=$alt"); $q = fn() => ($s = $pdo->prepare("SELECT DATE_FORMAT('2026-10-05','%Y-%m')=?")) && $s->execute(['2026-10']) ? (int)$s->fetchColumn() : -1;
    $err = null; try { $q(); } catch (PDOException $e) { $err = $e->errorInfo[1] ?? 0; }
    // (if this server version does not show the problem, the fix must still leave the comparison working)
    db_session_collation($pdo); $ok((string)$pdo->query('SELECT @@collation_connection')->fetchColumn() === $dbc, 'connection collation not set');
    return $ok($q() === 1, 'comparison still fails') && $ok($err === null || (int)$err === 1267, 'unexpected error ' . $err); });
