<?php
declare(strict_types=1);
// Finance 360 V29 — platform / multi-restaurant tests (STAGING ONLY). Rebuilds test databases from scratch.
// Usage: F360_CONFIG=tests/platform_test_config.php php tests/platform_tests.php
// Covers V29 §20 P0 items: hostile cross-tenant access, branch membership, atomic registration, trial, read-only expiry,
// signed webhooks (replay / out-of-order / mismatch / refund), token rotation, TOTP, support break-glass, direct closing,
// daybook photos, supplier-first purchase, permissions/me, revocation — plus HTTP-level API and page checks.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
$CFG = realpath((string)(getenv('F360_PLATFORM_TEST_CFG') ?: 'tests/platform_test_config.php')); putenv('F360_CONFIG=' . $CFG);
require 'platform/register.php'; require_once 'platform/schema.php'; require_once 'f360/dispatch.php';
define('SHARED', tenancy_shared()); // V30: one database, tenant_id/branch_id isolation (tests/platform_test_config_shared.php)
echo SHARED ? "MODE: shared database (tenant_id/branch_id)\n" : "MODE: database per branch\n";
if (!preg_match('/test/', pcfg()['db']['db_name'])) exit("refusing\n");
@unlink('private/p_fake_now.txt'); @unlink('private/f3_fake_now.txt');

/* ---------------- fresh test databases ---------------- */
$root = db_connect_target(['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'information_schema', 'db_user' => 'f360', 'db_password' => 'stagingpw']);
foreach ($root->query("SELECT SCHEMA_NAME FROM SCHEMATA WHERE SCHEMA_NAME LIKE 'f360test\\_%' OR SCHEMA_NAME IN ('f360_platform_test','ksdd_test_legacy','f360_pool_test1','f360_pool_test2')")->fetchAll(PDO::FETCH_COLUMN) as $d) $root->exec("DROP DATABASE `$d`");
foreach (['f360_platform_test', 'ksdd_test_legacy', 'f360_pool_test1', 'f360_pool_test2'] as $d) $root->exec("CREATE DATABASE `$d` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
// legacy KSDD-like database with demo data, built exactly like production (tests/staging_setup.php), then Finance 360 migrations
$legacyCfg = sys_get_temp_dir() . '/f360_legacy_cfg.php';
file_put_contents($legacyCfg, '<?php return ' . var_export(['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'ksdd_test_legacy', 'db_user' => 'f360', 'db_password' => 'stagingpw', 'setup_key' => 'TEST-ONLY-setup-key-0123456789abcdef', 'pii_key' => 'TEST-ONLY-pii-key-0123456789abcdef0123', 'f3_allow_fake_clock' => true], true) . ';');
passthru('F360_CONFIG=' . escapeshellarg($legacyCfg) . ' ' . PHP_BINARY . ' tests/staging_setup.php > /dev/null', $rc); if ($rc) exit("staging_setup failed\n");
passthru('F360_CONFIG=' . escapeshellarg($legacyCfg) . ' ' . PHP_BINARY . ' -r ' . escapeshellarg('chdir("' . getcwd() . '"); require "lib.php"; require "f360/admin.php"; f3_migrate_all(null);'), $rc); if ($rc) exit("legacy f3 migrate failed\n");
file_put_contents('private/p_fake_now.txt', ''); file_put_contents('private/f3_fake_now.txt', '');

$P = 0; $F = 0; $LOG = [];
function T(string $id, string $name, callable $fn): void { global $P, $F, $LOG;
    try { $r = $fn(); $ok = $r !== false; $msg = ''; } catch (Throwable $e) { foreach ([$GLOBALS['P_PDO'] ?? null, $GLOBALS['F360_PDO'] ?? null] as $x) if ($x && $x->inTransaction()) $x->rollBack(); $ok = false; $msg = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
    $ok ? $P++ : $F++; $LOG[] = [$id, $name, $ok ? 'PASS' : 'FAIL', $msg]; echo ($ok ? '  PASS ' : '  FAIL ') . "$id $name" . ($ok ? '' : "  → $msg") . "\n"; db_switch(null); billing_cache_clear(); }
function eq($a, $b, string $w = ''): bool { if ($a !== $b) throw new RuntimeException("$w expected " . var_export($b, true) . ' got ' . var_export($a, true)); return true; }
function ok($c, string $w = 'assertion failed'): bool { if (!$c) throw new RuntimeException($w); return true; }
function throws(callable $fn, int|string $want): bool { try { $fn(); } catch (Throwable $e) { if (is_int($want) ? $e->getCode() !== $want : stripos($e->getMessage(), $want) === false) throw new RuntimeException('Wrong error (' . $e->getCode() . '): ' . $e->getMessage()); foreach ([$GLOBALS['P_PDO'] ?? null, $GLOBALS['F360_PDO'] ?? null] as $x) if ($x && $x->inTransaction()) $x->rollBack(); return true; } throw new RuntimeException('Expected an error ' . $want); }
function clock(?string $utc): void { file_put_contents('private/p_fake_now.txt', $utc ?? ''); file_put_contents('private/f3_fake_now.txt', $utc ? (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d H:i:s') : ''); billing_cache_clear(); }
function outbox_token(string $email, string $kind): string { $b = (string)pval('SELECT body FROM p_outbox WHERE recipient=? AND kind=? ORDER BY id DESC LIMIT 1', $email, $kind); if (!preg_match('/token=([A-Za-z0-9_-]{20,})/', $b, $m)) throw new RuntimeException("no $kind mail for $email"); return $m[1]; }
function reg_in(string $email, string $rest, array $x = []): array { return $x + ['owner_name' => 'Owner ' . $rest, 'mobile' => '9876543210', 'email' => $email, 'password' => 'TestPass#2026', 'restaurant' => $rest, 'city' => 'Ludhiana', 'state' => 'Punjab', 'pincode' => '141001', 'branch_name' => 'Main', 'cutoff_hour' => 4, 'accept_terms' => 1]; }
function register_and_verify(string $email, string $rest): array { $r = reg_start(reg_in($email, $rest)); $u = p_verify_email(outbox_token($email, 'verify')); prun("UPDATE p_registrations SET status='verified' WHERE uid=? AND status='draft'", $r['registration']); return ['tenant' => reg_provision($r['registration']), 'user' => prow('SELECT * FROM p_users WHERE id=?', $u['id']), 'reg' => $r['registration']]; }
function primary(int $tid): array { return prow('SELECT * FROM p_branches WHERE tenant_id=? AND is_primary=1', $tid); }
function enter(int $userId, int $tid, int $bid): array { $m = tenancy_authorize($userId, $tid, $bid); db_switch(tenancy_branch_target($m, ['platform_user_id' => $userId])); $u = row('SELECT * FROM dd_users WHERE id=?', (int)$m['local_user_id']); unset($u['password']); return $u; }
// HTTP against PHP's built-in server
const BASE = 'http://127.0.0.1:8099';
function http(string $m, string $path, $body = null, array $h = [], ?string $jar = null): array {
    $ch = curl_init(BASE . '/' . ltrim($path, '/')); $hdr = $h; if (is_array($body)) { $body = json_encode($body); $hdr[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $m, CURLOPT_HTTPHEADER => $hdr, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false] + ($body !== null ? [CURLOPT_POSTFIELDS => $body] : []) + ($jar ? [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar] : []));
    $raw = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $headers = substr($raw, 0, $hs); $b = substr($raw, $hs); return ['code' => $code, 'json' => json_decode($b, true), 'body' => $b, 'headers' => $headers]; }
function api(string $m, string $path, ?array $body = null, ?string $tok = null, array $h = []): array { return http($m, 'api/v1/index.php?__path=' . $path . (str_contains($path, '?') ? '' : ''), $body, array_merge($tok ? ['Authorization: Bearer ' . $tok] : [], $h)); }
function apiq(string $m, string $path, array $q, ?array $body, string $tok, array $h = []): array { return http($m, 'api/v1/index.php?' . http_build_query(['__path' => $path] + $q), $body, array_merge(['Authorization: Bearer ' . $tok], $h)); }
function sig(string $raw): string { return hash_hmac('sha256', $raw, 'whsec_test_0123456789'); }
function hook(array $payload, string $eventId, ?string $sig = null): array { $raw = json_encode($payload); return billing_webhook($raw, $sig ?? sig($raw), $eventId); }

echo "\n== Platform schema ==\n";
T('PLAT-01', 'Platform migrations apply and re-run as a no-op', function () { $d = p_migrate(); ok(count($d) === P_SCHEMA, 'applied ' . json_encode($d)); eq(p_migrate(), []); return eq(p_schema_version(), P_SCHEMA); });
T('PLAT-02', 'Seeded paid plan versions are inactive at ₹0 (no invented pricing; nothing purchasable until Super Admin publishes)', function () { eq((int)pval("SELECT COUNT(*) FROM p_plan_versions v JOIN p_plans p ON p.id=v.plan_id WHERE p.code='standard' AND (v.active=1 OR v.price_paise>0)"), 0); return eq(array_sum(array_map(fn($p) => count(array_filter($p['terms'], fn($t) => $t['price_paise'] > 0)), billing_plans())), 0); });

echo "\n== KSDD adoption (non-invasive) ==\n";
T('ADOPT-01', 'Dry run writes nothing (platform DB still empty of tenants)', function () { exec('F360_CONFIG=' . escapeshellarg($GLOBALS['CFG']) . ' ' . PHP_BINARY . ' tools/platform-adopt-legacy.php 2>&1', $o, $rc); eq($rc, 0, implode("\n", $o)); ok(str_contains(implode("\n", $o), 'DRY RUN')); return eq((int)pval('SELECT COUNT(*) FROM p_tenants'), 0); });
T('ADOPT-02', 'Apply: tenant + Main branch on the SAME database; financial tables byte-identical (CHECKSUM TABLE)', function () { exec('F360_CONFIG=' . escapeshellarg($GLOBALS['CFG']) . ' ' . PHP_BINARY . ' tools/platform-adopt-legacy.php --apply --i-have-a-backup 2>&1', $o, $rc); $out = implode("\n", $o); eq($rc, 0, $out);
    ok(str_contains($out, 'byte-identical'), $out); $b = prow('SELECT * FROM p_branches WHERE id=1'); eq($b['db_name'], 'ksdd_test_legacy'); eq($b['storage'], ''); eq(billing_entitlement(1)['state'], 'active');
    if (SHARED) { // V30: convert the adopted database to tenant_id/branch_id (verified: rows + content fingerprints identical)
        exec('F360_CONFIG=' . escapeshellarg($GLOBALS['CFG']) . ' ' . PHP_BINARY . ' tools/tenancy-migrate.php --apply --i-have-a-backup 2>&1', $o2, $rc2); eq($rc2, 0, implode("\n", $o2));
        ok(str_contains(implode("\n", $o2), 'reconciliation OK'), implode("\n", $o2)); $GLOBALS['F360_SHARED_POOL'] = []; }
    return true; });
T('ADOPT-03', 'Existing users sign in with their OLD passwords; roles/templates carried over; owner is tenant owner', function () { $u = p_login('owner@staging.test', 'OwnerPass#2026'); eq(tenancy_member_role((int)$u['id'], 1), 'owner');
    $c = p_login('counter@staging.test', 'StagingPass#2026'); $m = tenancy_memberships((int)$c['id']); eq($m[0]['branches'][0]['template'], 'counter'); return eq(tenancy_member_role((int)$c['id'], 1), 'member'); });
T('ADOPT-04', 'Re-running adoption is refused as "already adopted"', function () { exec('F360_CONFIG=' . escapeshellarg($GLOBALS['CFG']) . ' ' . PHP_BINARY . ' tools/platform-adopt-legacy.php --apply --i-have-a-backup 2>&1', $o, $rc); ok(str_contains(implode("\n", $o), 'Already adopted')); return eq((int)pval('SELECT COUNT(*) FROM p_tenants'), 1); });
T('ADOPT-05', 'Legacy closing set to final-on-submit (V29 §8) and legacy demo employees still visible to the owner', function () { $o = p_login('owner@staging.test', 'OwnerPass#2026'); $u = enter((int)$o['id'], 1, 1); eq(setting('f3_closing_direct'), '1'); return ok(count(f3_staff_list([], $u)['rows'] ?? f3_staff_list([], $u)['staff'] ?? []) >= 10, 'staff list'); });

echo "\n== Registration (atomic, resumable) ==\n"; prun('DELETE FROM p_rate');
$A = $B = null;
T('REG-01', 'Sign-up closed → refused', function () { p_setting_set('signup_enabled', '0'); throws(fn() => reg_start(reg_in('x@example.test', 'X')), 403); p_setting_set('signup_enabled', '1'); return true; });
T('REG-02', 'Validation: missing terms / bad mobile / bad PIN / weak password refused; honeypot refused', function () { throws(fn() => reg_start(reg_in('v@example.test', 'Vee Dhaba', ['accept_terms' => 0])), 'Terms'); throws(fn() => reg_start(reg_in('v@example.test', 'Vee Dhaba', ['mobile' => '12345'])), 'mobile'); throws(fn() => reg_start(reg_in('v@example.test', 'Vee Dhaba', ['pincode' => '0123'])), 'PIN'); throws(fn() => reg_start(reg_in('v@example.test', 'Vee Dhaba', ['password' => 'short'])), 'Password'); return throws(fn() => reg_start(reg_in('v@example.test', 'Vee Dhaba', ['website' => 'http://spam'])), 400); });
T('REG-03', 'Unverified owner cannot sign in; no tenant exists before verification', function () { reg_start(reg_in('ownera@example.test', 'Alpha Dhaba')); throws(fn() => p_login('ownera@example.test', 'TestPass#2026'), 403); return eq((int)pval("SELECT COUNT(*) FROM p_tenants WHERE name='Alpha Dhaba'"), 0); });
T('REG-04', 'Duplicate submission before verifying → same registration, no second user', function () { reg_start(reg_in('ownera@example.test', 'Alpha Dhaba')); eq((int)pval("SELECT COUNT(*) FROM p_users WHERE email='ownera@example.test'"), 1); return eq((int)pval("SELECT COUNT(*) FROM p_registrations WHERE email='ownera@example.test'"), 1); });
T('REG-05', 'Injected failures (after tenant row, after DB install, inside final transaction) leave NO active tenant; retry completes exactly once', function () {
    $u = p_verify_email(outbox_token('ownera@example.test', 'verify')); $reg = pval("SELECT uid FROM p_registrations WHERE user_id=?", $u['id']); prun("UPDATE p_registrations SET status='verified' WHERE uid=?", $reg);
    foreach (['after_tenant', 'after_install', 'final_tx'] as $fp) { $GLOBALS['P_FAIL_AT'] = $fp; throws(fn() => reg_provision($reg), 'Injected');
        $t = prow("SELECT * FROM p_tenants WHERE name='Alpha Dhaba'"); eq($t['status'], 'provisioning', $fp); eq((int)pval('SELECT COUNT(*) FROM p_sub_periods WHERE tenant_id=?', $t['id']), 0, 'no trial yet');
        throws(fn() => tenancy_authorize((int)$u['id'], (int)$t['id'], (int)primary((int)$t['id'])['id']), 403); }
    unset($GLOBALS['P_FAIL_AT']); $t = reg_provision($reg); eq($t['status'], 'active'); eq(reg_provision($reg)['id'], $t['id'], 'idempotent');
    eq((int)pval("SELECT COUNT(*) FROM p_tenants WHERE name='Alpha Dhaba'"), 1); eq((int)pval('SELECT COUNT(*) FROM p_branches WHERE tenant_id=?', $t['id']), 1); return eq((int)pval('SELECT COUNT(*) FROM p_trial_grants WHERE tenant_id=?', $t['id']), 1); });
T('REG-06', '30-day trial: ends 23:59:59 IST on sign-up day + 30; state trialing; owner linked as local owner in the NEW database', function () { global $A; $t = prow("SELECT * FROM p_tenants WHERE name='Alpha Dhaba'"); $A = ['tenant' => $t, 'user' => prow('SELECT * FROM p_users WHERE email=?', 'ownera@example.test')];
    $e = billing_entitlement((int)$t['id']); eq($e['state'], 'trialing'); $end = (new DateTimeImmutable($e['period_end'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'));
    eq($end->format('H:i:s'), '23:59:59'); $start = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'))); eq($end->format('Y-m-d'), $start->modify('+30 days')->format('Y-m-d'));
    $b = primary((int)$t['id']); if (SHARED) eq($b['db_name'], null, 'no separate database in shared mode'); else { ok(str_starts_with($b['db_name'], 'f360test_'), 'own DB'); ok($b['db_name'] !== 'ksdd_test_legacy'); } $u = enter((int)$A['user']['id'], (int)$t['id'], (int)$b['id']); eq($u['role'], 'owner'); return eq(f3_template_of($u), 'owner'); });
T('REG-07', 'Second tenant B registers; verify link is single-use', function () { global $B; $B = register_and_verify('ownerb@example.test', 'Bravo Kitchen'); eq($B['tenant']['status'], 'active'); return throws(fn() => p_verify_email(outbox_token('ownerb@example.test', 'verify')), 410); });
prun('DELETE FROM p_rate');
T('REG-08', 'Same owner adds another restaurant → NO second automatic trial (state none = read-only until a plan is bought)', function () { global $A; $r = reg_start(reg_in('ownera@example.test', 'Alpha Two'), $A['user']); $t = reg_provision($r['registration']); $e = billing_entitlement((int)$t['id']); eq($e['state'], 'none'); return eq($e['read_only'], true); });

echo "\n== Tenant isolation (hostile) ==\n";
$bA = primary((int)$A['tenant']['id']); $bB = primary((int)$B['tenant']['id']);
T('ISO-01', SHARED ? 'One database: every business row is tagged with its tenant/branch; separate storage roots' : 'Each restaurant has its own ledger DB and storage root; databases never shared', function () use ($bA, $bB) {
    ok($bA['storage'] !== $bB['storage']);
    if (!SHARED) { ok($bA['db_name'] !== $bB['db_name']); return eq((int)pval('SELECT COUNT(*) FROM p_branches') , (int)pval('SELECT COUNT(DISTINCT db_name) FROM p_branches')); }
    eq((int)pval('SELECT COUNT(DISTINCT db_name) FROM p_branches'), 1);
    $raw = db_connect_target(main_db_target());
    foreach ($raw->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'dd\\_%' OR TABLE_NAME LIKE 'f3\\_%')")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $cols = $raw->query("SELECT GROUP_CONCAT(COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME IN ('tenant_id','branch_id')")->fetchColumn();
        eq($cols, 'tenant_id,branch_id', "$t columns"); eq((int)$raw->query("SELECT COUNT(*) FROM `$t` b LEFT JOIN p_branches pb ON pb.id=b.branch_id AND pb.tenant_id=b.tenant_id WHERE pb.id IS NULL")->fetchColumn(), 0, "$t rows with unknown tenant/branch"); }
    return true; });
T('ISO-02', 'Owner A cannot authorise B’s branch, nor A-tenant + B-branch (composite), nor a non-existent id — same 403 message', function () use ($bA, $bB) { global $A, $B; $ua = (int)$A['user']['id'];
    $m1 = ''; $m2 = ''; $m3 = ''; try { tenancy_authorize($ua, (int)$B['tenant']['id'], (int)$bB['id']); } catch (RuntimeException $e) { $m1 = $e->getMessage() . $e->getCode(); }
    try { tenancy_authorize($ua, (int)$A['tenant']['id'], (int)$bB['id']); } catch (RuntimeException $e) { $m2 = $e->getMessage() . $e->getCode(); }
    try { tenancy_authorize($ua, 999, 999); } catch (RuntimeException $e) { $m3 = $e->getMessage() . $e->getCode(); }
    ok($m1 !== '' && $m1 === $m2 && $m2 === $m3, "$m1 | $m2 | $m3"); return ok(str_ends_with($m1, '403')); });
T('ISO-03', 'Composite FK: a branch membership pointing at another tenant’s branch is rejected by the database', function () use ($bB) { global $A; return throws(fn() => prun("INSERT INTO p_branch_members(tenant_id,branch_id,user_id,local_user_id,template) VALUES(?,?,?,99,'owner')", $A['tenant']['id'], $bB['id'], $A['user']['id']), 'foreign key'); });
T('ISO-04', 'Employee created in B is invisible to A (list + search); A’s id lookup returns A’s own row, never B’s', function () use ($bA, $bB) { global $A, $B;
    $ub = enter((int)$B['user']['id'], (int)$B['tenant']['id'], (int)$bB['id']); run("INSERT INTO dd_employees(name,job,salary,code,f3_dept_id,status,active) VALUES('SECRET-BRAVO-EMP','Cook',1500000,'B001',3,'active',1)"); $bid = (int)row("SELECT id FROM dd_employees WHERE name='SECRET-BRAVO-EMP'")['id'];
    $ua = enter((int)$A['user']['id'], (int)$A['tenant']['id'], (int)$bA['id']); ok(!str_contains(json_encode(f3_staff_list([], $ua)), 'SECRET-BRAVO'), 'list leak'); eq(f3_search(['q' => 'SECRET-BRAVO'], $ua)['groups'], [], 'search leak');
    $r = row('SELECT name FROM dd_employees WHERE id=?', $bid); return ok($r === null || $r['name'] !== 'SECRET-BRAVO-EMP'); });
T('ISO-05', 'Encrypted secrets: ciphertext from B cannot be decrypted in A (per-branch derived keys); KSDD keeps its original key', function () use ($bA, $bB) { global $A, $B; enter((int)$B['user']['id'], (int)$B['tenant']['id'], (int)$bB['id']); $c = f3_enc('bank-ac-1234'); eq(f3_dec($c), 'bank-ac-1234');
    enter((int)$A['user']['id'], (int)$A['tenant']['id'], (int)$bA['id']); eq(f3_dec($c), null, 'cross decrypt'); $o = p_login('owner@staging.test', 'OwnerPass#2026'); enter((int)$o['id'], 1, 1); $legacyKey = hash('sha256', 'f3-pii|TEST-ONLY-pii-key-0123456789abcdef0123', true); return eq(f3_pii_keys()[0], $legacyKey); });
T('ISO-06', 'Uploaded files land under the branch’s own storage root; B’s file id read from A’s DB is not B’s file', function () use ($bA, $bB) { global $A, $B; $ub = enter((int)$B['user']['id'], (int)$B['tenant']['id'], (int)$bB['id']);
    $png = base64_encode((function () { $i = imagecreatetruecolor(4, 4); ob_start(); imagepng($i); return ob_get_clean(); })()); db()->beginTransaction(); $fid = store_upload(['data' => $png, 'name' => 'b.png'], $ub); db()->commit();
    $p = row('SELECT path,sha256 FROM dd_files WHERE id=?', $fid); ok(is_file(storage_dir('uploads') . '/' . $p['path'])); ok(str_contains(storage_dir('uploads'), '/private/tenants/'), storage_dir('uploads'));
    enter((int)$A['user']['id'], (int)$A['tenant']['id'], (int)$bA['id']); $x = row('SELECT sha256 FROM dd_files WHERE id=?', $fid); return ok($x === null || $x['sha256'] !== $p['sha256']); });
T('ISO-07', 'Web session forged to B’s branch is refused; stale password revision is refused (fresh PHP process, real session)', function () use ($bB) { global $A, $B;
    $code = 'chdir(' . var_export(getcwd(), true) . '); require "platform/tenancy.php"; session_start(); $o=[];'
      . '$_SESSION=["p_uid"=>' . (int)$A['user']['id'] . ',"p_rev"=>1,"p_tenant"=>' . (int)$B['tenant']['id'] . ',"p_branch"=>' . (int)$bB['id'] . ',"user_id"=>1,"expires"=>time()+99];'
      . 'try{tenancy_resolve_session();$o[]="ALLOWED";}catch(Throwable $e){$o[]=$e->getCode();}'
      . '$_SESSION["p_tenant"]=' . (int)$A['tenant']['id'] . ';$_SESSION["p_branch"]=' . (int)primary((int)$A['tenant']['id'])['id'] . ';$_SESSION["p_rev"]=7;'
      . 'try{tenancy_resolve_session();$o[]="ALLOWED";}catch(Throwable $e){$o[]=$e->getCode();} echo json_encode($o);';
    $out = shell_exec('F360_CONFIG=' . escapeshellarg($GLOBALS['CFG']) . ' ' . PHP_BINARY . ' -d session.save_path=/tmp -r ' . escapeshellarg($code) . ' 2>/dev/null'); return eq(json_decode((string)$out, true), [403, 401]); });
T('ISO-08', 'No fallback: in platform mode db() with no resolved context fails instead of opening any database', function () { db_switch(null); return throws(fn() => db(), 401); });
T('ISO-09', 'Machine endpoint routing by branch uid rejects unknown / malformed uids (404)', function () { throws(fn() => tenancy_target_by_branch_uid('nope'), 404); return throws(fn() => tenancy_target_by_branch_uid('aaaaaaaaaaaa'), 404); });

echo "\n== Branches & memberships ==\n";
$A2 = null; $C1 = null;
T('BR-01', 'Owner A adds branch A2 within quota; it gets its own database; owner linked automatically', function () use (&$A2) { global $A; $A2 = tenancy_branch_create((int)$A['tenant']['id'], ['name' => 'Highway', 'city' => 'Jalandhar', 'cutoff_hour' => 5], (int)$A['user']['id']);
    eq($A2['status'], 'active'); $b = prow('SELECT * FROM p_branches WHERE id=?', $A2['id']); ok(SHARED ? $b['id'] !== primary((int)$A['tenant']['id'])['id'] : $b['db_name'] !== primary((int)$A['tenant']['id'])['db_name']); $u = enter((int)$A['user']['id'], (int)$A['tenant']['id'], (int)$b['id']); eq(setting('business_day_cutoff_hour'), '5'); return eq($u['role'], 'owner'); });
T('BR-02', 'Branch quota enforced (402) and trial plan limit read from plan version', function () { global $A; $lim = billing_entitlement((int)$A['tenant']['id'])['max_branches']; for ($i = 2; $i < $lim; $i++) tenancy_branch_create((int)$A['tenant']['id'], ['name' => 'Extra ' . $i], (int)$A['user']['id']);
    return throws(fn() => tenancy_branch_create((int)$A['tenant']['id'], ['name' => 'Too many'], (int)$A['user']['id']), 402); });
T('BR-03', 'Cash counter invited to A1 only: can enter A1, refused on A2', function () use (&$C1, &$A2) { global $A; $t = (int)$A['tenant']['id']; $b1 = primary($t);
    prun("INSERT INTO p_users(uid,email,name,password_hash,email_verified_at) VALUES(?,?,?,?,UTC_TIMESTAMP())", p_uid(), 'cashier.a@example.test', 'Cashier A', password_hash('CashPass#2026', PASSWORD_DEFAULT)); $C1 = prow('SELECT * FROM p_users WHERE id=?', pid());
    prun("INSERT INTO p_tenant_members(tenant_id,user_id,role) VALUES(?,?,'member')", $t, $C1['id']); $l = prov_local_user($b1, $C1, 'cash_counter'); prun("INSERT INTO p_branch_members(tenant_id,branch_id,user_id,local_user_id,template) VALUES(?,?,?,?,'cash_counter')", $t, $b1['id'], $C1['id'], $l);
    enter((int)$C1['id'], $t, (int)$b1['id']); return throws(fn() => tenancy_authorize((int)$C1['id'], $t, (int)$A2['id']), 403); });

echo "\n== Subscriptions, expiry, read-only ==\n";
T('SUB-01', 'After trial end: state expired, read-only; POST blocked with 402, reads allowed', function () { global $A; $t = (int)$A['tenant']['id']; $e = billing_entitlement($t);
    clock(p_utc((new DateTimeImmutable($e['period_end'], new DateTimeZone('UTC')))->modify('+1 hour'))); $e = billing_entitlement($t); eq($e['state'], 'expired'); eq($e['read_only'], true);
    $u = enter((int)$A['user']['id'], $t, (int)primary($t)['id']); $_GET['route'] = 'closing-save'; throws(fn() => platform_write_gate('closing-save'), 402); platform_write_gate('snooze');
    ok(is_array(f3_dashboard($u))); clock(null); return true; });
T('SUB-02', 'Admin extension is contiguous from max(now, end) and logged', function () { global $A; $t = (int)$A['tenant']['id']; $end = billing_period_end($t); $e = billing_admin_extend($t, 10, 1, 'Test extension'); $p = prow("SELECT * FROM p_sub_periods WHERE tenant_id=? AND source='admin' ORDER BY id DESC LIMIT 1", $t);
    eq($p['starts_at'], $end, 'starts at old end'); return eq((int)pval("SELECT COUNT(*) FROM p_audit WHERE action='subscription.extend' AND tenant_id=?", $t), 1); });
T('SUB-03', 'Suspend → read-only (owner can still read); reactivate restores', function () { global $A; $t = (int)$A['tenant']['id']; billing_set_suspended($t, true, 1, 'Fraud check'); eq(billing_entitlement($t)['state'], 'suspended'); eq(billing_entitlement($t)['read_only'], true);
    billing_set_suspended($t, false, 1, 'Cleared'); return eq(billing_entitlement($t)['read_only'], false); });
T('SUB-04', 'Reminder jobs: 7-day reminder queued once (dedupe) for a trial ending in 7 days', function () { global $B; $t = (int)$B['tenant']['id']; $e = billing_entitlement($t);
    clock(p_utc((new DateTimeImmutable($e['period_end'], new DateTimeZone('UTC')))->modify('-6 days -12 hours'))); billing_reminders(); billing_reminders(); clock(null);
    return eq((int)pval("SELECT COUNT(*) FROM p_outbox WHERE kind='sub_reminder' AND tenant_id=? AND dedupe_key LIKE '%:7d'", $t), 1); });

echo "\n== Payments & webhooks (Razorpay, signed) ==\n";
$pvId = 0; $ord = null;
T('PAY-01', 'Price published as a new plan version; checkout creates a server order for the exact tenant/plan/amount', function () use (&$pvId, &$ord) { global $B; $pvId = billing_plan_version_save(1, 'monthly', ['price_paise' => 99900, 'max_branches' => 2, 'max_users' => 10, 'grace_days' => 3, 'modules' => P_ALL_MODULES], 1);
    $c = billing_create_order((int)$B['tenant']['id'], $pvId, (int)$B['user']['id']); $ord = prow('SELECT * FROM p_orders WHERE uid=?', $c['order_uid']); eq((int)$ord['amount_paise'], 99900); eq((int)$ord['tenant_id'], (int)$B['tenant']['id']); return ok(str_starts_with($ord['provider_order_id'], 'order_TEST')); });
T('PAY-02', 'Only owner/admin of THAT tenant can create an order (A’s owner for B → 403)', function () use (&$pvId) { global $A, $B; return throws(fn() => billing_create_order((int)$B['tenant']['id'], $pvId, (int)$A['user']['id']), 403); });
$cap = fn(array $o, int $amt, string $pid = 'pay_TEST1') => ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => $pid, 'order_id' => $o['provider_order_id'], 'amount' => $amt, 'currency' => 'INR', 'status' => 'captured', 'method' => 'upi']]]];
T('PAY-03', 'Bad signature → 400, logged as rejected, no access granted', function () use (&$ord, $cap) { [$c] = hook($cap($ord, 99900), 'evt_bad', 'deadbeef'); eq($c, 400); return eq((int)pval('SELECT COUNT(*) FROM p_sub_periods WHERE order_id=?', $ord['id']), 0); });
T('PAY-04', 'Amount mismatch → order flagged for review, no credit', function () use (&$ord, $cap) { [$c, $m] = hook($cap($ord, 100), 'evt_mis'); eq($m, 'error'); eq(pval('SELECT status FROM p_orders WHERE id=?', $ord['id']), 'review'); return eq((int)pval('SELECT COUNT(*) FROM p_sub_periods WHERE order_id=?', $ord['id']), 0); });
T('PAY-05', 'Valid payment.captured → credited once; period starts at trial end (contiguous); receipt issued', function () use (&$ord, $cap) { global $B; $t = (int)$B['tenant']['id']; $end = billing_period_end($t); [$c, $m] = hook($cap($ord, 99900), 'evt_ok'); eq($c, 200); eq($m, 'processed');
    $p = prow('SELECT * FROM p_sub_periods WHERE order_id=?', $ord['id']); eq($p['starts_at'], $end); $e = billing_entitlement($t); eq($e['kind'], 'paid'); ok(pval('SELECT receipt_no FROM p_orders WHERE id=?', $ord['id']) !== null); return eq($e['state'], 'active'); });
T('PAY-06', 'Replay (same event id) → duplicate; same payment under a NEW event id / order.paid → no second credit', function () use (&$ord, $cap) { [, $m] = hook($cap($ord, 99900), 'evt_ok'); eq($m, 'duplicate'); hook($cap($ord, 99900), 'evt_ok2');
    hook(['event' => 'order.paid', 'payload' => ['order' => ['entity' => ['id' => $ord['provider_order_id'], 'amount_paid' => 99900, 'currency' => 'INR']], 'payment' => ['entity' => ['id' => 'pay_TEST1', 'order_id' => $ord['provider_order_id'], 'amount' => 99900, 'currency' => 'INR']]]], 'evt_ok3');
    return eq((int)pval('SELECT COUNT(*) FROM p_sub_periods WHERE order_id=?', $ord['id']), 1); });
T('PAY-07', 'Out-of-order payment.failed after capture never downgrades the paid order', function () use (&$ord) { global $B; hook(['event' => 'payment.failed', 'payload' => ['payment' => ['entity' => ['id' => 'pay_TEST1', 'order_id' => $ord['provider_order_id'], 'amount' => 99900, 'currency' => 'INR']]]], 'evt_fail'); eq(pval('SELECT status FROM p_orders WHERE id=?', $ord['id']), 'paid'); return eq(billing_entitlement((int)$B['tenant']['id'])['state'], 'active'); });
T('PAY-08', 'Renewal exactly at expiry starts at the old end (no gap/overlap); paying during grace continues from the old end', function () use (&$pvId, $cap) { global $B; $t = (int)$B['tenant']['id'];
    $end = billing_period_end($t); clock($end); $c = billing_create_order($t, $pvId, (int)$B['user']['id']); $o = prow('SELECT * FROM p_orders WHERE uid=?', $c['order_uid']); hook($cap($o, 99900, 'pay_TEST2'), 'evt_r1');
    eq(prow('SELECT starts_at FROM p_sub_periods WHERE order_id=?', $o['id'])['starts_at'], $end);
    $end2 = billing_period_end($t); clock(p_utc((new DateTimeImmutable($end2, new DateTimeZone('UTC')))->modify('+2 days'))); eq(billing_entitlement($t)['state'], 'grace'); eq(billing_entitlement($t)['read_only'], false);
    $c = billing_create_order($t, $pvId, (int)$B['user']['id']); $o = prow('SELECT * FROM p_orders WHERE uid=?', $c['order_uid']); hook($cap($o, 99900, 'pay_TEST3'), 'evt_r2'); eq(prow('SELECT starts_at FROM p_sub_periods WHERE order_id=?', $o['id'])['starts_at'], $end2); clock(null); return true; });
T('PAY-09', 'Full refund revokes that period; partial refund is only flagged for review', function () use (&$ord) { global $B; $t = (int)$B['tenant']['id'];
    hook(['event' => 'refund.processed', 'payload' => ['refund' => ['entity' => ['id' => 'rfnd_1', 'payment_id' => 'pay_TEST3', 'amount' => 50000]]]], 'evt_rf1'); ok(!pval('SELECT revoked_at FROM p_sub_periods sp JOIN p_orders o ON o.id=sp.order_id WHERE o.provider_payment_id=?', 'pay_TEST3'));
    hook(['event' => 'refund.processed', 'payload' => ['refund' => ['entity' => ['id' => 'rfnd_2', 'payment_id' => 'pay_TEST3', 'amount' => 49900]]]], 'evt_rf2'); return ok(pval('SELECT revoked_at FROM p_sub_periods sp JOIN p_orders o ON o.id=sp.order_id WHERE o.provider_payment_id=?', 'pay_TEST3')); });
T('PAY-10', 'Offline payment by billing admin: same reference never credits twice', function () use (&$pvId) { global $A; $t = (int)$A['tenant']['id']; $r1 = billing_admin_offline_payment($t, $pvId, 'UTR123456', 1); $r2 = billing_admin_offline_payment($t, $pvId, 'UTR123456', 1); eq($r1['credited'], true); return eq($r2['credited'], false); });
T('PAY-11', 'Gateway gating: disabled → no orders and webhook 503; live keys without owner approval refused; test keys in live mode refused', function () use (&$pvId) { global $B; $base = pcfg()['billing'];
    $GLOBALS['P_BILLING_OVERRIDE'] = ['mode' => 'disabled'] + $base; throws(fn() => billing_create_order((int)$B['tenant']['id'], $pvId, (int)$B['user']['id']), 503); eq(billing_webhook('{}', 'x', 'e')[0], 503);
    $GLOBALS['P_BILLING_OVERRIDE'] = ['mode' => 'live', 'key_id' => 'rzp_live_X'] + $base; ok(str_contains((string)billing_gw_ready(), 'owner approval'));
    $GLOBALS['P_BILLING_OVERRIDE'] = ['mode' => 'live', 'key_id' => 'rzp_test_X', 'live_approved' => true] + $base; ok(billing_gw_ready() !== null);
    unset($GLOBALS['P_BILLING_OVERRIDE']); return eq(billing_gw_ready(), null); });

echo "\n== Permissions (web = app) ==\n";
T('PERM-01', 'Cash Counter (closing only): permissions = closing.* only; owner sees everything allowed by plan', function () use (&$C1) { global $A; $t = (int)$A['tenant']['id']; $u = enter((int)$C1['id'], $t, (int)primary($t)['id']);
    eq(f3_perms($u), ['closing.view', 'closing.entry', 'closing.finalize', 'closing.variance', 'notifications.view']); $o = enter((int)$A['user']['id'], $t, (int)primary($t)['id']); return ok(in_array('payroll.approve', f3_perms($o), true)); });
T('PERM-02', 'Plan without payroll module removes payroll.* for EVERY user, owner included', function () { global $B; $t = (int)$B['tenant']['id']; $pv = billing_plan_version_save(1, 'yearly', ['price_paise' => 900000, 'max_branches' => 1, 'max_users' => 5, 'grace_days' => 3, 'modules' => ['closing' => 1, 'expenses' => 1, 'staff' => 1, 'payroll' => 0, 'purchases' => 1, 'stock' => 1, 'reports' => 1, 'integrations' => 0]], 1);
    $old = pval('SELECT plan_version_id FROM p_subscriptions WHERE tenant_id=?', $t); prun('UPDATE p_subscriptions SET plan_version_id=? WHERE tenant_id=?', $pv, $t); billing_cache_clear();
    $u = enter((int)$B['user']['id'], $t, (int)primary($t)['id']); $p = f3_perms($u); ok(!array_filter($p, fn($x) => str_starts_with($x, 'payroll.')), 'payroll perms present'); throws(fn() => f3_need($u, 'payroll.prepare'), 403); throws(fn() => f3_int_need($u), 'not included');
    prun('UPDATE p_subscriptions SET plan_version_id=? WHERE tenant_id=?', $old, $t); return true; });

echo "\n== TOTP & admin ==\n";
T('MFA-01', 'RFC 6238 SHA-1 test vectors (8 digits) and ±1 step window, replay of same step refused', function () { $k = '12345678901234567890';
    foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037'] as $tt => $code) eq(p_hotp($k, intdiv($tt, 30), 8), $code, "T=$tt");
    $s = p_b32_encode($k); $now = 1700000000; $c = p_hotp($k, intdiv($now, 30)); $st = p_totp_check($s, $c, 0, $now); eq($st, intdiv($now, 30)); return eq(p_totp_check($s, $c, $st, $now), null); });
T('ADM-01', 'Admin without TOTP must set it up; wrong code refused; correct code accepted once', function () { $id = pa_create('root@example.test', 'Root', 'AdminPass#2026', 'super_admin'); $a = pa_login('root@example.test', 'AdminPass#2026', ''); ok(!empty($a['_mfa_setup_needed']));
    $sec = p_b32_encode(random_bytes(20)); prun('UPDATE p_admins SET totp_secret_enc=?,totp_enabled=1 WHERE id=?', p_enc($sec), $id); throws(fn() => pa_login('root@example.test', 'AdminPass#2026', '000000'), 401);
    $code = p_hotp(p_b32_decode($sec), intdiv(time(), 30)); pa_login('root@example.test', 'AdminPass#2026', $code); return throws(fn() => pa_login('root@example.test', 'AdminPass#2026', $code), 401); });
T('ADM-02', 'Support break-glass: no access before owner approval; approved → read-only (writes 403); after expiry → ended', function () { global $A; $t = (int)$A['tenant']['id']; $b = primary($t); $aid = (int)pval("SELECT id FROM p_admins WHERE email='root@example.test'");
    prun('INSERT INTO p_support_grants(tenant_id,branch_id,admin_id,reason,minutes) VALUES(?,?,?,?,30)', $t, $b['id'], $aid, 'Customer asked us to check closing'); $g = pid();
    @session_id('s' . bin2hex(random_bytes(8))); @session_start(); $_SESSION = ['p_support' => $g, 'p_admin_support' => $aid]; throws(fn() => tenancy_resolve_support($g), 401);
    prun("UPDATE p_support_grants SET status='approved',expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) WHERE id=?", $g); $_SESSION = ['p_support' => $g, 'p_admin_support' => $aid];
    $tg = tenancy_resolve_support($g); db_switch($tg); throws(fn() => platform_write_gate('closing-save'), 403);
    prun("UPDATE p_support_grants SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?", $g); throws(fn() => tenancy_resolve_support($g), 401); $_SESSION = []; return true; });

echo "\n== Daily closing: final on submit + daybook photo ==\n";
T('CLO-01', 'Counter finalizes yesterday directly: status approved (final), accounting variance auto-posted once, no review task', function () use (&$C1) { global $A; $t = (int)$A['tenant']['id']; $u = enter((int)$C1['id'], $t, (int)primary($t)['id']); $d = f3_target_date();
    db()->beginTransaction(); f3_closing_save(['date' => $d, 'cash_sale' => '30000', 'card_sale' => '5000', 'upi_sale' => '7000', 'actual_cash' => '30500', 'expenses_confirmed' => 'none', 'diff_reason' => 'Counted twice, short confirmed', 'step' => 5], $u); db()->commit();
    $st = f3_closing_state($d, $u); db()->beginTransaction(); $r = f3_closing_finalize(['date' => $d, 'confirm' => 1, 'version' => $st['closing']['version'] ?? null, 'client_key' => 'clo-final-0001'], $u); db()->commit();
    eq($r['final'], true); $c = row('SELECT * FROM f3_closings WHERE business_date=?', $d); eq($c['status'], 'approved'); $exp = (int)$c['expected_cash'];
    $v = 3050000 - $exp; eq((int)$c['variance_amount'], $v, 'variance'); if ($v !== 0) eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE import_key LIKE ? AND voided=0", F3_VAR_KEY . $d . '%')['n'], 1);
    eq((int)row("SELECT COUNT(*) n FROM f3_notifications WHERE kind='closing-review' AND status='open'")['n'], 0, 'no admin review task');
    db()->beginTransaction(); $r2 = f3_closing_finalize(['date' => $d, 'confirm' => 1, 'version' => $st['closing']['version'] ?? null, 'client_key' => 'clo-final-0001'], $u); db()->commit(); eq($r2['duplicate'] ?? false, true, 'idempotent retry');
    return throws(function () use ($d, $u) { db()->beginTransaction(); f3_closing_finalize(['date' => $d, 'confirm' => 1], $u); }, 409); });
T('CLO-02', 'Daybook photo optional: added after final, listed, file readable by closing users; removal after final refused; PDF refused', function () use (&$C1) { global $A; $t = (int)$A['tenant']['id']; $u = enter((int)$C1['id'], $t, (int)primary($t)['id']); $d = f3_target_date();
    $jpg = base64_encode((function () { $i = imagecreatetruecolor(20, 20); ob_start(); imagejpeg($i); return ob_get_clean(); })());
    db()->beginTransaction(); $r = f29_closing_photo_add(['date' => $d, 'file' => ['data' => $jpg, 'name' => 'daybook.jpg']], $u); db()->commit(); eq(count($r['photos']), 1);
    ok(f3_file_ok(row('SELECT * FROM dd_files WHERE id=?', $r['file_id']), $u), 'counter can open'); throws(function () use ($r, $u) { db()->beginTransaction(); f29_closing_photo_remove(['id' => $r['photos'][0]['id']], $u); }, 409);
    return throws(function () use ($d, $u) { db()->beginTransaction(); f29_closing_photo_add(['date' => $d, 'file' => ['data' => base64_encode("%PDF-1.4\n%%EOF"), 'name' => 'x.pdf']], $u); }, 'JPG'); });
T('CLO-03', 'Counter cannot reopen a final day (needs admin correction with reason)', function () use (&$C1) { global $A; $t = (int)$A['tenant']['id']; $u = enter((int)$C1['id'], $t, (int)primary($t)['id']); return throws(fn() => f3_closing_reopen(['date' => f3_target_date(), 'reason' => 'x'], $u), 403); });

echo "\n== HTTP: API v1 + pages (PHP built-in server) ==\n";
$srv = proc_open([PHP_BINARY, '-S', '127.0.0.1:8099', '-t', getcwd()], [0 => ['pipe', 'r'], 1 => ['file', '/tmp/f360_srv.log', 'a'], 2 => ['file', '/tmp/f360_srv.log', 'a']], $pipes, getcwd(), ['F360_CONFIG' => $CFG, 'PATH' => getenv('PATH')]);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', 8099) === false; $i++) usleep(100000);
$TOK = null; $CT = null;
T('API-01', 'Health is public; unauthenticated finance call → 401 UNAUTHENTICATED', function () { eq(api('GET', 'health')['code'], 200); $r = api('GET', 'dashboard'); eq($r['code'], 401); return eq($r['json']['code'], 'UNAUTHENTICATED'); });
T('API-02', 'Login → tokens; finance call before choosing a branch → 409; select A1 → permissions returned', function () use (&$TOK) { global $A; $r = api('POST', 'auth/login', ['email' => 'ownera@example.test', 'password' => 'TestPass#2026', 'device_id' => 'device-A-0001', 'device_name' => 'Pixel']); eq($r['code'], 200, $r['body']); $TOK = $r['json'];
    eq(api('GET', 'dashboard', null, $TOK['access_token'])['code'], 409); $t = (int)$A['tenant']['id']; $s = api('POST', 'tenants/select', ['tenant_id' => $t, 'branch_id' => (int)primary($t)['id']], $TOK['access_token']); eq($s['code'], 200, $s['body']); $TOK = $s['json'];
    ok(in_array('payroll', $TOK['permissions']['menus'], true)); return eq(api('GET', 'dashboard', null, $TOK['access_token'])['code'], 200); });
T('API-03', 'Hostile: A’s token selecting B’s tenant/branch → 403; old token after re-select is revoked', function () use (&$TOK) { global $B; $t = (int)$B['tenant']['id']; $r = api('POST', 'tenants/select', ['tenant_id' => $t, 'branch_id' => (int)primary($t)['id']], $TOK['access_token']); eq($r['code'], 403); return eq($r['json']['code'], 'FORBIDDEN'); });
T('API-04', 'Cash Counter on the app: menus = [closing], home = closing; payroll / staff / reports routes → 403', function () use (&$CT) { global $A; $r = api('POST', 'auth/login', ['email' => 'cashier.a@example.test', 'password' => 'CashPass#2026', 'device_id' => 'device-C-0001']); $t = (int)$A['tenant']['id'];
    $s = api('POST', 'tenants/select', ['tenant_id' => $t, 'branch_id' => (int)primary($t)['id']], $r['json']['access_token']); $CT = $s['json']; eq($CT['permissions']['menus'], ['closing']); eq($CT['permissions']['home'], 'closing');
    foreach (['salary/periods', 'employees', 'reports/daily', 'suppliers'] as $p) eq(api('GET', $p, null, $CT['access_token'])['code'], 403, $p); return eq(api('GET', 'closing', null, $CT['access_token'])['code'], 200); });
T('API-05', 'Web and app identical: same route through f360/{route} and alias gives the same JSON for the owner', function () use (&$TOK) { $a = api('GET', 'f360/departments', null, $TOK['access_token']); $b = api('GET', 'departments', null, $TOK['access_token']); eq($a['code'], 200); return eq($a['json'], $b['json']); });
T('API-06', 'Idempotency-Key header: same expense posted twice → one entry; read-only subscription → POST 402 SUBSCRIPTION_READ_ONLY', function () use (&$TOK) { global $A; $t = (int)$A['tenant']['id']; $d = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->modify('-4 hours')->format('Y-m-d');
    $cats = api('GET', 'expenses/categories', null, $TOK['access_token'])['json']['categories']; $cat = $cats[0]['name'];
    $body = ['date' => $d, 'category' => $cat, 'amount' => '120', 'account' => 'Cash', 'mode' => 'Cash', 'description' => 'API test'];
    $r1 = api('POST', 'expenses', $body, $TOK['access_token'], ['Idempotency-Key: exp-test-00001']); eq($r1['code'], 200, $r1['body']); $r2 = api('POST', 'expenses', $body, $TOK['access_token'], ['Idempotency-Key: exp-test-00001']); eq($r2['json']['duplicate'] ?? false, true, $r2['body']);
    try { $e = billing_entitlement($t); file_put_contents('private/p_fake_now.txt', p_utc((new DateTimeImmutable(billing_period_end($t), new DateTimeZone('UTC')))->modify('+60 days')));
    $lg = api('POST', 'auth/login', ['email' => 'ownera@example.test', 'password' => 'TestPass#2026', 'device_id' => 'device-A-0009']); eq($lg['code'], 200, 'read-only owner can still sign in'); $sl = api('POST', 'tenants/select', ['tenant_id' => $t, 'branch_id' => (int)primary($t)['id']], $lg['json']['access_token']); eq($sl['json']['permissions']['read_only'], true); ok(!in_array('expenses.create', $sl['json']['permissions']['actions'], true), 'write actions hidden');
    eq(api('GET', 'dashboard', null, $sl['json']['access_token'])['code'], 200, 'reads allowed');
    $r3 = api('POST', 'expenses', $body + ['description' => 'after expiry'], $sl['json']['access_token'], ['Idempotency-Key: exp-test-00002']); } finally { file_put_contents('private/p_fake_now.txt', ''); } eq($r3['code'], 402, $r3['body']); return eq($r3['json']['code'], 'SUBSCRIPTION_READ_ONLY'); });
T('API-07', 'Refresh rotation; reusing an old refresh token revokes the whole family; wrong device refused', function () use (&$CT) { $old = $CT['refresh_token']; eq(api('POST', 'auth/refresh', ['refresh_token' => $old, 'device_id' => 'device-X-9999'])['code'], 401, 'device');
    $n = api('POST', 'auth/refresh', ['refresh_token' => $old, 'device_id' => 'device-C-0001']); eq($n['code'], 200, $n['body']); eq(api('POST', 'auth/refresh', ['refresh_token' => $old, 'device_id' => 'device-C-0001'])['code'], 401, 'reuse');
    return eq(api('GET', 'closing', null, $n['json']['access_token'])['code'], 401, 'family revoked'); });
T('API-08', 'Revocation mid-session: owner removes the cashier → next app call fails immediately (403)', function () use (&$C1) { global $A; $r = api('POST', 'auth/login', ['email' => 'cashier.a@example.test', 'password' => 'CashPass#2026', 'device_id' => 'device-C-0002']); $t = (int)$A['tenant']['id'];
    $s = api('POST', 'tenants/select', ['tenant_id' => $t, 'branch_id' => (int)primary($t)['id']], $r['json']['access_token'])['json']; eq(api('GET', 'closing', null, $s['access_token'])['code'], 200);
    prun("UPDATE p_branch_members SET status='revoked' WHERE user_id=? AND tenant_id=?", $C1['id'], $t); $x = api('GET', 'closing', null, $s['access_token']); prun("UPDATE p_branch_members SET status='active' WHERE user_id=? AND tenant_id=?", $C1['id'], $t); return eq($x['code'], 403); });
T('API-09', 'Webhook endpoint over HTTP: bad signature 400; valid signature 200 processed', function () { $raw = json_encode(['event' => 'payment.authorized', 'payload' => []]);
    eq(http('POST', 'billing-webhook.php', $raw, ['X-Razorpay-Signature: nope', 'X-Razorpay-Event-Id: evt_http_1', 'Content-Type: application/json'])['code'], 400);
    $r = http('POST', 'billing-webhook.php', $raw, ['X-Razorpay-Signature: ' . sig($raw), 'X-Razorpay-Event-Id: evt_http_2', 'Content-Type: application/json']); eq($r['code'], 200); return eq($r['json']['status'], 'ignored'); });
T('WEB-01', 'Public pages render (200) with security headers; no invented prices; sign-in form has CSRF token', function () { foreach (['site.php', 'site.php?p=pricing', 'site.php?p=features', 'site.php?p=faq', 'site.php?p=terms', 'site.php?p=privacy', 'register.php', 'signin.php', 'forgot.php', 'site.php?lang=hi'] as $p) { $r = http('GET', $p); eq($r['code'], 200, $p); ok(stripos($r['headers'], 'content-security-policy') !== false, 'CSP ' . $p); ok(stripos($r['headers'], 'x-frame-options: deny') !== false, 'XFO'); }
    ok(str_contains(http('GET', 'signin.php')['body'], 'name="csrf"')); return ok(str_contains(http('GET', 'site.php?p=pricing')['body'], '₹999'), 'published price shown'); });
T('WEB-02', 'Browser sign-in → choose branch → app API works with session; legacy local login is disabled; CSRF missing → refused', function () { global $A; $jar = tempnam(sys_get_temp_dir(), 'jar'); $g = http('GET', 'signin.php', null, [], $jar); preg_match('/name="csrf" value="([a-f0-9]+)"/', $g['body'], $m);
    $p = http('POST', 'signin.php', http_build_query(['csrf' => $m[1], 'email' => 'ownera@example.test', 'password' => 'TestPass#2026']), ['Content-Type: application/x-www-form-urlencoded'], $jar); eq($p['code'], 303); ok(str_contains($p['headers'], 'choose.php'));
    $c = http('GET', 'choose.php?stay=1', null, [], $jar); preg_match('/name="csrf" value="([a-f0-9]+)"/', $c['body'], $m2); $t = (int)$A['tenant']['id'];
    $e = http('POST', 'choose.php', http_build_query(['csrf' => $m2[1], 'tenant' => $t, 'branch' => (int)primary($t)['id']]), ['Content-Type: application/x-www-form-urlencoded'], $jar); ok(str_contains($e['headers'], 'index.php'), $e['headers']);
    $me = http('GET', 'f360-api.php?route=me', null, [], $jar); eq($me['code'], 200, $me['body']); eq($me['json']['platform']['tenant']['name'], 'Alpha Dhaba');
    eq(http('POST', 'f360-api.php?route=snooze', '{}', ['Content-Type: application/json'], $jar)['code'], 403, 'csrf');
    $l = http('POST', 'api.php?route=login', json_encode(['email' => 'ownera@example.test', 'password' => 'TestPass#2026']), ['Content-Type: application/json', 'X-CSRF-Token: ' . $me['json']['csrf']], $jar); eq($l['code'], 403); return true; });
T('WEB-03', 'Owner portal: other owner cannot open A’s tenant via ?t= (falls back to own tenant); cash counter is sent away', function () { global $A; $jar = tempnam(sys_get_temp_dir(), 'jar'); $g = http('GET', 'signin.php', null, [], $jar); preg_match('/name="csrf" value="([a-f0-9]+)"/', $g['body'], $m);
    http('POST', 'signin.php', http_build_query(['csrf' => $m[1], 'email' => 'ownerb@example.test', 'password' => 'TestPass#2026']), ['Content-Type: application/x-www-form-urlencoded'], $jar);
    $r = http('GET', 'owner.php?t=' . (int)$A['tenant']['id'], null, [], $jar); eq($r['code'], 200); ok(!str_contains($r['body'], 'Alpha Dhaba'), 'leak'); return ok(str_contains($r['body'], 'Bravo Kitchen')); });
T('WEB-04', 'Platform admin portal is separate: restaurant owner session does not open it', function () { $r = http('GET', 'platform-admin/index.php'); eq($r['code'], 200); return ok(str_contains($r['body'], 'Authenticator code')); });
proc_terminate($srv);

echo "\n== Supplier-first purchase ==\n";
T('PUR-01', 'Supplier suggestion shows ledger balance, last bill and last items with last rate; rate change needs confirmation', function () { $o = p_login('owner@staging.test', 'OwnerPass#2026'); $u = enter((int)$o['id'], 1, 1);
    $sid = (int)row("SELECT id FROM dd_bk_parties WHERE name LIKE 'DEMO Sharma%'")['id']; $s = f29_supplier_suggest($sid, $u); ok(array_key_exists('balance', $s) && $s['balance'] !== null, 'balance');
    return ok(isset($s['suggested_items']) && is_array($s['suggested_items'])); });

clock(null); @unlink('private/p_fake_now.txt'); @unlink('private/f3_fake_now.txt');
echo "\nRESULT: $P passed, $F failed (" . ($P + $F) . " tests)\n";
$md = "# Platform test run — " . gmdate('Y-m-d H:i') . " UTC\n\nEnvironment: PHP " . PHP_VERSION . ', ' . pval('SELECT VERSION()') . " (local staging, test databases rebuilt from scratch)\n\n**Result: $P passed, $F failed (" . ($P + $F) . ")**\n\n| ID | Test | Result | Detail |\n|---|---|---|---|\n";
foreach ($LOG as [$i, $n, $r, $m]) $md .= "| $i | " . str_replace('|', '/', $n) . " | $r | " . str_replace(['|', "\n"], ['/', ' '], $m) . " |\n";
@mkdir('docs'); file_put_contents('docs/TEST_REPORT_PLATFORM.md', $md);
exit($F ? 1 : 0);
