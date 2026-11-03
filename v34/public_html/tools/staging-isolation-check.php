<?php
declare(strict_types=1);
/*
 * Finance 360 V34 — NON-DESTRUCTIVE isolation check for the STAGING copy (u245900320_staging) with REAL data.  CLI only.
 *
 *   F360_PRIVATE_DIR=… php tools/staging-isolation-check.php --base-url=https://staging.ksdd.in
 *
 * 1. Refuses to run on the production database (u245900320_ksdd) or when the database is not converted (single_db).
 * 2. Registers two throw-away test restaurants through the real registration code (status remains visible to Super Admin).
 * 3. Signs in as them over HTTPS and calls EVERY GET route with KSDD's real record ids, and KSDD's real staff / supplier /
 *    party names as search text; tries write routes against KSDD ids.
 * 4. Passes only if no KSDD name ever appears in a reply, no 5xx occurs, and every KSDD row is byte-identical afterwards.
 * The test restaurants are SUSPENDED at the end (Super Admin can delete them). KSDD data is only read.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'platform/register.php'; require_once 'f360/dispatch.php';
$o = getopt('', ['base-url:', 'ksdd-branch:']); $BASE = rtrim((string)($o['base-url'] ?? ''), '/');
$PROD = 'u245900320_ksdd';
if (($c = cfg())['db_name'] === $PROD) exit("Refusing: this is the PRODUCTION database ($PROD). Run on the staging copy only.\n");
if (!mt_enabled() || !platform_mode()) exit("Refusing: single_db must be true and the platform enabled.\n");
if (!preg_match('#^https?://#', $BASE)) exit("Pass --base-url=https://your-staging-address (the site that uses THIS database).\n");
$raw = db_connect_target(['db_host' => $c['db_host'], 'db_port' => $c['db_port'] ?? 3306, 'db_name' => $c['db_name'], 'db_user' => $c['db_user'], 'db_password' => $c['db_password']]); // internal helpers only (base tables mt_*)
$KB = (int)($o['ksdd-branch'] ?? ($c['single_db_scope']['branch_id'] ?? 0) ?: $raw->rawQuery("SELECT id FROM p_branches WHERE is_primary=1 AND storage='' ORDER BY id LIMIT 1")->fetchColumn());
if (!$KB) exit("Cannot find the adopted KSDD branch.\n");
$P = 0; $F = 0;
function T(string $id, string $name, callable $fn): void { global $P, $F; try { $r = $fn(); $ok = $r === true; $m = is_string($r) ? $r : ''; } catch (Throwable $e) { $ok = false; $m = $e->getMessage(); } $ok ? $P++ : $F++; echo ($ok ? '  PASS ' : '  FAIL ') . "$id $name" . ($m !== '' ? "  → $m" : '') . "\n"; }

// ---- KSDD secrets (real names) and ids, read-only ----
$col = fn(string $sql) => $raw->rawQuery(preg_replace('/FROM `?(dd_|f3_)/', 'FROM mt_$1', $sql))->fetchAll(PDO::FETCH_COLUMN);
$secrets = array_values(array_unique(array_filter(array_merge(
    $col("SELECT name FROM dd_employees WHERE branch_id=$KB AND CHAR_LENGTH(name)>=6 ORDER BY id LIMIT 25"),
    $col("SELECT name FROM dd_suppliers WHERE branch_id=$KB AND CHAR_LENGTH(name)>=6 ORDER BY id LIMIT 15"),
    $col("SELECT name FROM dd_bk_parties WHERE branch_id=$KB AND CHAR_LENGTH(name)>=6 ORDER BY id LIMIT 15")), fn($s) => !preg_match('/^(cash|bank|owner|staff|main|test)/i', (string)$s))));
$K = []; foreach (['employee' => 'dd_employees', 'supplier' => 'dd_suppliers', 'party' => 'dd_bk_parties', 'txn' => 'dd_bk_txns', 'bill' => 'f3_purchase_bills', 'item' => 'dd_inventory', 'file' => 'dd_files', 'user' => 'dd_users'] as $k => $t)
    $K[$k] = $col("SELECT id FROM $t WHERE branch_id=$KB ORDER BY id DESC LIMIT 4");
$fingerprint = function () use ($raw, $KB): string { $h = '';
    foreach ($raw->rawQuery("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND (TABLE_NAME LIKE 'mt\\_dd\\_%' OR TABLE_NAME LIKE 'mt\\_f3\\_%') AND TABLE_NAME NOT IN ('mt_dd_login_attempts','mt_dd_request_keys','mt_dd_audit') ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $cols = $raw->rawQuery("SELECT GROUP_CONCAT(CONCAT('IFNULL(`',COLUMN_NAME,'`,0x00)') ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t'")->fetchColumn();
        $h .= $t . ':' . implode('/', $raw->rawQuery("SELECT COUNT(*), COALESCE(BIT_XOR(CRC32(CONCAT_WS(0x1f,$cols))),0) FROM `$t` WHERE branch_id=$KB")->fetch(PDO::FETCH_NUM)) . ';'; }
    return md5($h); };
$before = $fingerprint();
echo "Staging database " . cfg()['db_name'] . " · KSDD branch $KB · " . count($secrets) . " real KSDD names used as leak detectors\n";

// ---- two throw-away restaurants through the real registration code ----
$wasOpen = p_setting('signup_enabled'); p_setting_set('signup_enabled', '1');
$att = [];
try {
    foreach (['A', 'B'] as $x) {
        $email = 'isolation-check-' . strtolower($x) . '-' . bin2hex(random_bytes(3)) . '@example.invalid'; $pw = 'Iso#' . bin2hex(random_bytes(8));
        $r = reg_start(['owner_name' => "Isolation Check $x", 'mobile' => '9876543210', 'email' => $email, 'password' => $pw, 'restaurant' => "ISOLATION CHECK $x (delete me)", 'city' => 'Test', 'state' => 'Test', 'pincode' => '110001', 'branch_name' => 'Main', 'cutoff_hour' => 4, 'accept_terms' => 1]);
        $b = (string)pval('SELECT body FROM p_outbox WHERE recipient=? AND kind=? ORDER BY id DESC LIMIT 1', $email, 'verify');
        if (!preg_match('/token=([A-Za-z0-9_-]{20,})/', $b, $m)) throw new RuntimeException('No verification e-mail in p_outbox (set platform.mail.mode = log on staging while testing).');
        p_verify_email($m[1]); prun("UPDATE p_registrations SET status='verified' WHERE uid=? AND status='draft'", $r['registration']); $t = reg_provision($r['registration']);
        $att[] = [$email, $pw, (int)$t['id']];
    }
} finally { p_setting_set('signup_enabled', (string)($wasOpen ?? '0')); }

function api(string $m, string $path, array $q = [], ?array $body = null, ?string $tok = null): array { global $BASE;
    $ch = curl_init($BASE . '/api/v1/index.php?' . http_build_query(['__path' => $path] + $q)); $h = ['Content-Type: application/json']; if ($tok) $h[] = 'Authorization: Bearer ' . $tok;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60] + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
    $b = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch); return [$c, $b]; }
['GET' => $GET, 'POST' => $POST] = f3_routes();
foreach ($att as $i => [$email, $pw, $tid]) {
    [$c, $b] = api('POST', 'auth/login', [], ['email' => $email, 'password' => $pw, 'device_id' => 'isolation-check-' . $i]); $j = json_decode($b, true);
    if ($c !== 200) { T("LOGIN-$i", $email, fn() => "login failed: $b"); continue; }
    $m = $j['memberships'][0]; [$c, $b] = api('POST', 'tenants/select', [], ['tenant_id' => $m['id'], 'branch_id' => $m['branches'][0]['id']], $j['access_token']); $tok = json_decode($b, true)['access_token'] ?? '';
    $leaks = []; $calls = 0; $codes = [];
    foreach ($GET as $route) {
        $params = [[], ['period' => date('Y-m', strtotime('-1 month'))], ['from' => '2020-01-01', 'to' => '2030-12-31']];
        foreach (array_slice($secrets, 0, 6) as $s) $params[] = ['q' => (string)$s];
        foreach ($K as $kind => $list) foreach ($list as $id) { $params[] = ['id' => $id]; $params[] = [$kind . '_id' => $id]; }
        foreach ($params as $q) { [$c, $b] = api('GET', 'f360/' . $route, $q, null, $tok); $calls++; $codes[$c] = ($codes[$c] ?? 0) + 1;
            $jj = json_decode($b, true); if (is_array($jj) && isset($q['q']) && ($jj['q'] ?? null) === $q['q']) { unset($jj['q']); $b = json_encode($jj, JSON_UNESCAPED_UNICODE); }
            foreach ($secrets as $s) if ($s !== '' && mb_stripos($b, (string)$s) !== false) { $leaks[] = "$route " . json_encode($q) . " → «$s»"; break; }
            if ($c >= 500) $leaks[] = "HTTP $c on $route " . json_encode($q); }
    }
    T("READ-$i", "test restaurant #$tid: $calls GET calls on " . count($GET) . " routes with KSDD ids/names (codes " . json_encode($codes) . ')', fn() => $leaks ? implode(' | ', array_slice($leaks, 0, 6)) : true);
    $wc = [];
    foreach (array_merge(array_map(fn($id) => ['staff-save', ['id' => $id, 'name' => 'X', 'dept_id' => 1, 'monthly' => '1']], $K['employee']), array_map(fn($id) => ['supplier-save', ['id' => $id, 'name' => 'X']], $K['supplier']),
                         array_map(fn($id) => ['expense-void', ['id' => $id, 'reason' => 'isolation check']], $K['txn']), array_map(fn($id) => ['bill-discard', ['id' => $id]], $K['bill']), array_map(fn($id) => ['item-save', ['id' => $id, 'name' => 'X', 'unit' => 'kg']], $K['item'])) as [$r, $body])
        if (in_array($r, $POST, true)) { [$c] = api('POST', 'f360/' . $r, [], $body + ['client_key' => 'iso' . bin2hex(random_bytes(6))], $tok); $wc[$c] = ($wc[$c] ?? 0) + 1; }
    T("WRITE-$i", "test restaurant #$tid: write attempts on KSDD ids (codes " . json_encode($wc) . ') leave KSDD unchanged', fn() => $fingerprint() === $before ? true : 'KSDD DATA CHANGED — stop and investigate');
}
foreach ($att as [, , $tid]) prun("UPDATE p_tenants SET status='suspended' WHERE id=?", $tid);
echo "\nTest restaurants suspended (delete them in Super Admin → Restaurants when done).\nRESULT: $P passed, $F failed\n"; exit($F ? 1 : 0);
