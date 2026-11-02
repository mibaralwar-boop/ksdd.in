<?php
declare(strict_types=1);
/* Finance 360 V31 — convert the existing Khadak Singh Da Dhaba database into the ONE shared multi-restaurant database.
   STAGING FIRST. Never run on the live database without the owner's written approval and a verified backup.

   php tools/v31-convert.php                                   preflight / dry run: reports, writes NOTHING
   php tools/v31-convert.php --apply --i-have-a-backup [--complimentary-days=365]
        1. platform tables (p_*) created in THIS database          2. KSDD registered as tenant #1, branch "Main"
        3. existing users → platform logins (same password hashes) 4. every dd_/f3_ table converted (tenant_id/branch_id,
           prefixed keys, composite FKs, scoped views)             5. every table verified: row count + SHA-256 of every row
   php tools/v31-convert.php --verify                          re-check every table against the pre-conversion hashes
   php tools/v31-convert.php --refresh-views                   rebuild the scoped views (after a restore)
   php tools/v31-convert.php --rollback --i-have-a-backup      undo (only while KSDD is the ONLY restaurant)
   php tools/v31-convert.php --no-platform ...                 convert only (tests); tenant/branch from --tenant= --branch=
   After --apply, add to config.php:  'single_db' => true, 'single_db_scope' => ['tenant_id'=>T,'branch_id'=>B,'branch_uid'=>'…'] */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'lib.php'; require_once 'platform/mt_convert.php';
$A = $argv; $has = fn(string $f) => in_array($f, $A, true);
$opt = function (string $k, $d = null) use ($A) { foreach ($A as $a) if (preg_match('/^--' . preg_quote($k, '/') . '=(.+)$/', $a, $m)) return $m[1]; return $d; };
$c = cfg();
if (empty($c['db_name']) || str_starts_with((string)$c['db_name'], 'YOUR_')) { fwrite(STDERR, "config.php db_* must point at the restaurant database.\n"); exit(1); }
$main = ['db_host' => $c['db_host'] ?? 'localhost', 'db_port' => (int)($c['db_port'] ?? 3306), 'db_name' => $c['db_name'], 'db_user' => $c['db_user'] ?? '', 'db_password' => $c['db_password'] ?? '', 'storage' => '', 'tenant_id' => null, 'branch_id' => null];
$p = db_connect_target($main); db_session_collation($p); $p->rawExec("SET time_zone='+00:00'");
$log = function (string $m) { echo '  ' . $m . "\n"; };
$isLive = !preg_match('/(staging|test|appksdd)/i', (string)$c['db_name']) || (($c['environment'] ?? '') === 'production');
echo "== Finance 360 V31 single-database conversion\nDatabase: {$c['db_name']}" . ($isLive ? '  ** LIVE / PRODUCTION **' : '  (staging/test)') . "\n";

if ($has('--refresh-views')) { echo 'Views rebuilt: ' . mt_refresh_views($p) . "\n"; exit(0); }
if ($has('--verify') || $has('--rollback')) {
    $sc = (array)($c['single_db_scope'] ?? []); $T = (int)$opt('tenant', $sc['tenant_id'] ?? 0); $B = (int)$opt('branch', $sc['branch_id'] ?? 0);
    if ($T <= 0 || $B <= 0) { fwrite(STDERR, "Need single_db_scope in config.php or --tenant= --branch=\n"); exit(1); }
    if ($has('--rollback')) {
        if (!$has('--i-have-a-backup')) { fwrite(STDERR, "Refusing: --i-have-a-backup required.\n"); exit(1); }
        $res = mtc_rollback($p, $T, $B, $log);
    } else { mt_set_scope($p, $T, $B); $res = mtc_verify($p); }
    $bad = array_keys(array_filter($res, fn($r) => !$r['ok']));
    echo count($res) . ' tables checked, ' . count($bad) . " mismatch(es)\n"; foreach ($bad as $t) echo "  MISMATCH $t {$res[$t]['before']} → {$res[$t]['after']}\n";
    if ($has('--rollback') && !$bad) echo "Rolled back. Remove 'single_db' and 'single_db_scope' from config.php.\n";
    exit($bad ? 3 : 0);
}

$pre = mtc_preflight($p);
echo "Server: {$pre['server']} · legacy tables: " . count($pre['tables']) . ' · already converted: ' . count($pre['converted']) . "\n";
foreach ($pre['issues'] as $i) echo "  ISSUE: $i\n";
$installed = (bool)$p->rawQuery("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('dd_settings','mt_dd_settings')")->fetchColumn();
if (!$installed) { fwrite(STDERR, "This is not an installed Finance 360 database.\n"); exit(1); }
$show = ['dd_employees', 'dd_bk_txns', 'f3_closings', 'f3_pay_lines', 'f3_sal_history', 'f3_purchase_bills', 'f3_stock_moves', 'dd_gl_journals', 'dd_gl_lines'];
foreach ($show as $t) if (in_array($t, $pre['tables'], true)) echo str_pad($t, 20) . ' rows ' . $p->rawQuery("SELECT COUNT(*) FROM `$t`")->fetchColumn() . "\n";
// Rows that cannot be attributed to the restaurant automatically (quarantine report, nothing moved)
$amb = [];
if (in_array('dd_bk_txns', $pre['tables'], true)) $amb['dd_bk_txns without date'] = (int)$p->rawQuery('SELECT COUNT(*) FROM dd_bk_txns WHERE date IS NULL')->fetchColumn();
if (in_array('dd_employees', $pre['tables'], true)) { $amb['employees sharing a name (review, NOT merged)'] = (int)$p->rawQuery('SELECT COALESCE(SUM(n),0) FROM (SELECT COUNT(*) n FROM dd_employees GROUP BY LOWER(TRIM(name)) HAVING COUNT(*)>1) x')->fetchColumn(); }
foreach ($amb as $k => $v) echo "  review: $k = $v\n";
if ($pre['issues']) { fwrite(STDERR, "Fix the issues above first.\n"); exit(1); }
if (!$has('--apply')) { echo "\nDRY RUN — nothing was written. Re-run with --apply --i-have-a-backup after a full DB + private/ backup.\n"; exit(0); }
if (!$has('--i-have-a-backup')) { fwrite(STDERR, "Refusing: take a full database + private/ backup and test-restore it, then add --i-have-a-backup.\n"); exit(1); }
if ($isLive && !$has('--owner-approved-live')) { fwrite(STDERR, "Refusing: this looks like the LIVE database. The owner must approve; then add --owner-approved-live.\n"); exit(1); }

$T = (int)$opt('tenant', 0); $B = (int)$opt('branch', 0); $buid = '';
if (!$has('--no-platform')) {
    require_once 'platform/schema.php'; require_once 'platform/tenancy.php'; require_once 'f360/core.php';
    $done = p_migrate(); echo 'Platform tables: ' . ($done ? 'created/updated v' . implode(',', $done) : 'up to date') . "\n";
    $ex = prow("SELECT b.id,b.tenant_id,b.uid FROM p_branches b WHERE b.is_primary=1 AND b.storage='' ORDER BY b.id LIMIT 1");
    if ($ex) { $T = (int)$ex['tenant_id']; $B = (int)$ex['id']; $buid = $ex['uid']; echo "Founding restaurant already registered: tenant #$T branch #$B\n"; }
    else {
        db_switch($main);
        $users = []; foreach (rows('SELECT * FROM dd_users ORDER BY id') as $u) if ((int)($u['active'] ?? 1) === 1) $users[] = $u;
        $owner = null; foreach ($users as $u) if ($u['role'] === 'owner') { $owner = $u; break; }
        if (!$owner) { fwrite(STDERR, "No active owner in dd_users — cannot register the restaurant.\n"); exit(1); }
        $restaurant = (string)setting('restaurant', 'Khadak Singh Da Dhaba');
        $plan = []; foreach ($users as $u) $plan[] = ['local' => (int)$u['id'], 'email' => strtolower(trim($u['email'])), 'name' => $u['name'], 'role' => $u['role'], 'hash' => $u['password'], 'template' => f3_template_of($u)];
        db_switch(null);
        $days = (int)$opt('complimentary-days', 365);
        [$T, $B, $buid] = ptx(function () use ($plan, $owner, $restaurant, $days) {
            $ids = [];
            foreach ($plan as $x) { $e = prow('SELECT id FROM p_users WHERE email=?', $x['email']);
                if ($e) $ids[$x['local']] = (int)$e['id'];
                else { prun("INSERT INTO p_users(uid,email,name,password_hash,email_verified_at,legacy_source) VALUES(?,?,?,?,UTC_TIMESTAMP(),'ksdd')", p_uid(), $x['email'], $x['name'], $x['hash']); $ids[$x['local']] = pid(); } }
            $tuid = p_uid(); $ou = $ids[(int)$owner['id']];
            prun("INSERT INTO p_tenants(uid,name,status,owner_user_id,activated_at) VALUES(?,?,'active',?,UTC_TIMESTAMP())", $tuid, $restaurant, $ou); $tid = pid();
            prun("INSERT INTO p_branches(uid,tenant_id,name,status,is_primary,storage,cutoff_hour) VALUES(?,?,'Main','active',1,'',4)", $buid = p_uid(), $tid); $bid = pid();
            foreach ($plan as $x) { $role = $x['local'] === (int)$owner['id'] ? 'owner' : ($x['role'] === 'owner' ? 'admin' : 'member');
                prun('INSERT INTO p_tenant_members(tenant_id,user_id,role) VALUES(?,?,?)', $tid, $ids[$x['local']], $role);
                prun('INSERT INTO p_branch_members(tenant_id,branch_id,user_id,local_user_id,template) VALUES(?,?,?,?,?)', $tid, $bid, $ids[$x['local']], $x['local'], $x['template']); }
            $pv = (int)pval("SELECT v.id FROM p_plan_versions v JOIN p_plans p ON p.id=v.plan_id WHERE p.code='legacy_complimentary' ORDER BY v.version DESC LIMIT 1");
            prun("INSERT INTO p_subscriptions(tenant_id,plan_version_id,kind,status) VALUES(?,?,'complimentary','active')", $tid, $pv);
            prun("INSERT INTO p_sub_periods(tenant_id,source,plan_version_id,starts_at,ends_at,reason) VALUES(?,'legacy',?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? DAY),'Founding restaurant migrated from single-restaurant system; complimentary until the owner approves pricing')", $tid, $pv, $days);
            prun("INSERT INTO p_trial_grants(tenant_id,owner_user_id,auto_key,days,starts_at,ends_at,reason) VALUES(?,?,?,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'Founding restaurant (complimentary, no trial)')", $tid, $ou, 'owner:' . $ou);
            if (is_file('assets/brand/logo-mark-256.png') && @copy('assets/brand/logo-mark-256.png', p_private('logos') . '/' . $tuid . '.png')) prun('UPDATE p_tenants SET logo_path=? WHERE id=?', 'logos/' . $tuid . '.png', $tid);
            p_audit('system', null, $tid, 'v31.founding_tenant', ['users' => count($plan), 'branch_uid' => $buid]);
            return [$tid, $bid, $buid];
        });
        echo "Registered founding restaurant: tenant #$T, branch #$B (uid $buid), users " . count($plan) . "\n";
    }
}
if ($T <= 0 || $B <= 0) { fwrite(STDERR, "Need --tenant= and --branch= with --no-platform.\n"); exit(1); }
if (!$pre['tables'] && $pre['converted']) { echo "Already converted (" . count($pre['converted']) . " scoped tables) — nothing to do. Use --verify right after a conversion, or --refresh-views after a restore.\n"; exit(0); }
$t0 = microtime(true);
$res = mtc_convert($p, $T, $B, $log);
// V29 owner decision (closing final on submit) recorded for the founding branch
if (!$has('--no-closing-direct')) { $p->exec("INSERT INTO dd_settings(`key`,value) VALUES('f3_closing_direct','1') ON DUPLICATE KEY UPDATE value='1'"); }
$bad = array_keys(array_filter($res, fn($r) => !$r['ok']));
printf("\nConverted %d tables in %.1fs. Verification: %d OK, %d mismatch.\n", count($res), microtime(true) - $t0, count($res) - count($bad), count($bad));
foreach ($bad as $t) echo "  MISMATCH $t {$res[$t]['before']} → {$res[$t]['after']}\n";
@mkdir('private/logs', 0750, true);
file_put_contents('private/logs/v31-convert-' . date('Ymd-His') . '.json', json_encode(['tenant' => $T, 'branch' => $B, 'branch_uid' => $buid, 'tables' => $res], JSON_PRETTY_PRINT));
if ($bad) { fwrite(STDERR, "STOP: mismatches found. Run --rollback or restore the backup, and report.\n"); exit(3); }
echo "\nNow set in config.php:\n  'single_db' => true,\n  'single_db_scope' => ['tenant_id' => $T, 'branch_id' => $B, 'branch_uid' => '$buid'],\n";
exit(0);
