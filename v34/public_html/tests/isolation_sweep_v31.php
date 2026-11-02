<?php
declare(strict_types=1);
// V30 adversarial isolation sweep (STAGING ONLY). Run AFTER tests/platform_tests.php in shared mode (it reuses that data):
//   F360_PLATFORM_TEST_CFG=tests/platform_test_config_shared.php php tests/platform_tests.php
//   php tests/isolation_sweep.php
// 1. Marks Khadak Singh Da Dhaba (tenant 1 / branch 1) rows with a secret marker.
// 2. Signs in over HTTP as OTHER restaurants (owner of Bravo Kitchen, cash counter of Alpha Dhaba) and calls EVERY GET route
//    of the API with KSDD's real record ids and the marker as search text (IDOR / search / export attempts).
// 3. Tries write routes against KSDD ids. 4. Verifies KSDD's rows are byte-identical afterwards and the marker never leaked.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
$CFG = realpath('tests/platform_test_config_v31.php'); putenv('F360_CONFIG=' . $CFG);
require 'platform/register.php'; require_once 'f360/dispatch.php';
if (!mt_enabled() || !preg_match('/test/', cfg()['db_name'])) exit("refusing: needs the V31 single-DB TEST config\n");
@file_put_contents(private_root() . '/p_fake_now.txt', ''); @file_put_contents(private_root() . '/f3_fake_now.txt', '');
$c0 = cfg(); $raw = db_connect_target(['db_host' => $c0['db_host'], 'db_port' => $c0['db_port'], 'db_name' => $c0['db_name'], 'db_user' => $c0['db_user'], 'db_password' => $c0['db_password']]); // internal helpers only: base tables mt_*
$MARK = 'SECRET-KSDD-' . bin2hex(random_bytes(4));
$P = 0; $F = 0;
function T(string $id, string $name, callable $fn): void { global $P, $F; try { $r = $fn(); $ok = $r !== false; $m = is_string($r) ? $r : ''; } catch (Throwable $e) { $ok = false; $m = $e->getMessage(); } $ok ? $P++ : $F++; echo ($ok ? '  PASS ' : '  FAIL ') . "$id $name" . ($m !== '' ? "  → $m" : '') . "\n"; }

// ---- 1. mark KSDD data (raw SQL, branch 1 only) ----
$marked = [];
foreach (['dd_employees' => 'name', 'dd_suppliers' => 'name', 'dd_bk_parties' => 'name', 'dd_ledger' => 'description', 'dd_bk_txns' => 'description', 'dd_inventory' => 'name', 'f3_purchase_bills' => 'notes', 'dd_files' => 'name'] as $t => $col) {
    try { $n = $raw->rawExec("UPDATE `mt_$t` SET `$col`=CONCAT('$MARK ', COALESCE(`$col`,'')) WHERE branch_id=1"); if ($n) $marked[$t] = $n; } catch (PDOException $e) {}
}
$raw->rawExec("UPDATE mt_dd_settings SET value=CONCAT('$MARK ', value) WHERE branch_id=1 AND `key`='restaurant'");
$ids = fn(string $sql) => $raw->rawQuery(preg_replace('/FROM (dd_|f3_)/', 'FROM mt_$1', $sql))->fetchAll(PDO::FETCH_COLUMN);
$K = ['employee' => $ids('SELECT id FROM dd_employees WHERE branch_id=1 LIMIT 5'), 'supplier' => $ids('SELECT id FROM dd_suppliers WHERE branch_id=1 LIMIT 3'),
      'party' => $ids('SELECT id FROM dd_bk_parties WHERE branch_id=1 LIMIT 3'), 'txn' => $ids('SELECT id FROM dd_bk_txns WHERE branch_id=1 ORDER BY id DESC LIMIT 3'),
      'bill' => $ids('SELECT id FROM f3_purchase_bills WHERE branch_id=1 LIMIT 3'), 'item' => $ids('SELECT id FROM dd_inventory WHERE branch_id=1 LIMIT 3'),
      'file' => $ids('SELECT id FROM dd_files WHERE branch_id=1 LIMIT 3'), 'user' => $ids('SELECT id FROM dd_users WHERE branch_id=1 LIMIT 3')];
$fingerprint = function () use ($raw): string { $h = '';
    foreach ($raw->rawQuery("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND (TABLE_NAME LIKE 'mt\\_dd\\_%' OR TABLE_NAME LIKE 'mt\\_f3\\_%') AND TABLE_NAME NOT IN ('mt_dd_login_attempts','mt_dd_request_keys','mt_dd_audit') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $cols = $raw->rawQuery("SELECT GROUP_CONCAT(CONCAT('IFNULL(`',COLUMN_NAME,'`,0x00)') ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
        $h .= $t . ':' . $raw->rawQuery("SELECT COUNT(*), COALESCE(BIT_XOR(CRC32(CONCAT_WS(0x1f,$cols))),0) FROM `$t` WHERE branch_id=1")->fetch(PDO::FETCH_NUM)[1] . ';'; }
    return md5($h); };
$before = $fingerprint();
echo "Marked KSDD rows: " . json_encode($marked) . "\n";

// ---- HTTP server ----
$srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:8095', '-t', getcwd()], [0 => ['pipe', 'r'], 1 => ['file', '/tmp/f360_sweep.log', 'a'], 2 => ['file', '/tmp/f360_sweep.log', 'a']], $pipes, getcwd(), ['F360_CONFIG' => $CFG, 'PATH' => getenv('PATH')]);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', 8095) === false; $i++) usleep(100000);
function api(string $m, string $path, array $q = [], ?array $body = null, ?string $tok = null): array {
    $ch = curl_init('http://127.0.0.1:8095/api/v1/index.php?' . http_build_query(['__path' => $path] + $q));
    $h = ['Content-Type: application/json']; if ($tok) $h[] = 'Authorization: Bearer ' . $tok;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60] + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
    $b = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return [$c, $b];
}
function enterApi(string $email, string $pw, string $tenantName): array {
    [$c, $b] = api('POST', 'auth/login', [], ['email' => $email, 'password' => $pw, 'device_id' => 'sweep-' . md5($email)]); $j = json_decode($b, true); if ($c !== 200) throw new RuntimeException("login $email: $b");
    foreach ($j['memberships'] as $m) if ($m['name'] === $tenantName) { [$c2, $b2] = api('POST', 'tenants/select', [], ['tenant_id' => $m['id'], 'branch_id' => $m['branches'][0]['id']], $j['access_token']); $s = json_decode($b2, true); if ($c2 !== 200) throw new RuntimeException("select: $b2"); return [$s['access_token'], $s['permissions']]; }
    throw new RuntimeException("no membership $tenantName");
}

try {
    ['GET' => $GET, 'POST' => $POST] = f3_routes();
    $attackers = [['ownerb@example.test', 'TestPass#2026', 'Bravo Kitchen'], ['ownera@example.test', 'TestPass#2026', 'Alpha Dhaba'], ['cashier.a@example.test', 'CashPass#2026', 'Alpha Dhaba']];
    foreach ($attackers as [$email, $pw, $tn]) {
        [$tok, $perm] = enterApi($email, $pw, $tn);
        $calls = 0; $leaks = []; $codes = [];
        foreach ($GET as $route) {
            $params = [[], ['q' => $MARK], ['q' => 'SECRET'], ['period' => '2026-10'], ['month' => '2026-10'], ['date' => '2026-10-15'], ['from' => '2020-01-01', 'to' => '2030-12-31']];
            foreach ($K as $kind => $list) foreach ($list as $id) { $params[] = ['id' => $id]; $params[] = [$kind . '_id' => $id]; $params[] = ['employee' => $id, 'employee_id' => $id]; }
            foreach ($params as $q) {
                [$c, $b] = api('GET', 'f360/' . $route, $q, null, $tok); $calls++; $codes[$c] = ($codes[$c] ?? 0) + 1;
                $j = json_decode($b, true); if (is_array($j) && isset($q['q']) && ($j['q'] ?? null) === $q['q']) { unset($j['q']); $b = json_encode($j, JSON_UNESCAPED_UNICODE); } // search echoes the query text itself
                if (str_contains($b, $MARK) || str_contains($b, 'SECRET-KSDD')) $leaks[] = "$route " . json_encode($q);
                if ($c >= 500) $leaks[] = "SERVER ERROR $c on $route " . json_encode($q) . ' ' . substr($b, 0, 160);
            }
        }
        T('SWEEP-' . strtoupper(substr(md5($email), 0, 4)), "$email ($tn, template {$perm['template']}): $calls GET calls over " . count($GET) . " routes with KSDD ids → no KSDD data, no 5xx (codes " . json_encode($codes) . ')',
            fn() => $leaks ? implode(' | ', array_slice($leaks, 0, 8)) . ' …' && false : true);
        if ($leaks) echo "     leaks: " . implode("\n            ", array_slice($leaks, 0, 15)) . "\n";
        // write attempts against KSDD record ids
        $w = [];
        foreach ($K['employee'] as $id) $w[] = ['staff-save', ['id' => $id, 'name' => 'HACKED', 'dept_id' => 1, 'monthly' => '1']];
        foreach ($K['supplier'] as $id) $w[] = ['supplier-save', ['id' => $id, 'name' => 'HACKED']];
        foreach ($K['txn'] as $id) { $w[] = ['expense-void', ['id' => $id, 'reason' => 'hack attempt']]; $w[] = ['expense-save', ['id' => $id, 'date' => '2026-11-01', 'category' => 'Vegetable', 'amount' => '1', 'mode' => 'Cash']]; }
        foreach ($K['bill'] as $id) { $w[] = ['bill-discard', ['id' => $id]]; $w[] = ['bill-reverse', ['id' => $id, 'reason' => 'hack']]; $w[] = ['bill-save', ['id' => $id, 'version' => 1, 'supplier_id' => $K['supplier'][0] ?? 1, 'lines' => []]]; }
        foreach ($K['item'] as $id) $w[] = ['item-save', ['id' => $id, 'name' => 'HACKED', 'unit' => 'kg']];
        foreach ($K['user'] as $id) $w[] = ['user-save', ['id' => $id, 'name' => 'HACKED', 'role' => 'owner']];
        $wc = []; foreach ($w as [$r, $body]) { if (!in_array($r, $POST, true)) continue; [$c, $b] = api('POST', 'f360/' . $r, [], $body + ['client_key' => 'sweep' . bin2hex(random_bytes(6))], $tok); $wc[$c] = ($wc[$c] ?? 0) + 1; if (str_contains($b, $MARK)) $leaks[] = "write reply leaked on $r"; }
        T('WRITE-' . strtoupper(substr(md5($email), 0, 4)), "$email: " . count($w) . ' write attempts on KSDD ids (codes ' . json_encode($wc) . ') leave every KSDD row unchanged', fn() => $fingerprint() === $before ? true : 'KSDD data CHANGED');
    }
    // sanity: KSDD's own owner DOES see the marker (proves the sweep would detect a leak)
    [$tok] = enterApi('owner@staging.test', 'OwnerPass#2026', 'Khadak Singh Da Dhaba');
    [$c, $b] = api('GET', 'f360/staff', [], null, $tok);
    T('CONTROL', 'Control: KSDD owner sees the marker in its own staff list (detector works)', fn() => $c === 200 && str_contains($b, $MARK));
    [$c, $b] = api('GET', 'f360/search', ['q' => 'SECRET-KSDD'], null, $tok); $j = json_decode($b, true); unset($j['q']);
    T('CONTROL-2', 'Control: KSDD owner\'s own search finds the marker (search really searches)', fn() => $c === 200 && str_contains(json_encode($j, JSON_UNESCAPED_UNICODE), 'SECRET-KSDD'));
    // Scheduled/Drive backup of another restaurant: only its own business rows, never platform tables or KSDD rows
    require_once 'f360/integrations.php';
    $bt = prow("SELECT b.* FROM p_branches b JOIN p_tenants t ON t.id=b.tenant_id WHERE t.name='Bravo Kitchen' AND b.is_primary=1");
    db_switch(tenancy_branch_target($bt)); [$dump, $man] = f3_db_dump(); db_switch(null);
    T('BACKUP', 'Bravo Kitchen backup (' . count($man) . ' tables, ' . array_sum($man) . ' rows): no KSDD marker, no platform (p_*) tables, only its own branch_id',
        fn() => !str_contains($dump, 'SECRET-KSDD') && !preg_grep('/^p_/', array_keys($man)) && !str_contains($dump, 'ksdd') ? true : 'leak in backup');
} finally { proc_terminate($srv); foreach (array_keys($marked) as $t) {} }
echo "\nRESULT: $P passed, $F failed\n"; exit($F ? 1 : 0);
