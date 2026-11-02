<?php
declare(strict_types=1);
// One-time adoption of the ORIGINAL single-restaurant database (Khadak Singh Da Dhaba) as tenant #1 / branch "Main".
// NON-INVASIVE: the restaurant database is NOT restructured. No financial table is altered, re-keyed, copied or deleted.
// It registers that database as the first branch ledger, creates platform logins for its existing users (same bcrypt
// password hashes, so passwords keep working), maps memberships, and grants a complimentary period until pricing is decided.
//   php tools/platform-adopt-legacy.php                    dry run: report only, writes nothing anywhere
//   php tools/platform-adopt-legacy.php --apply --i-have-a-backup [--complimentary-days=365] [--no-closing-direct]
// Proof: CHECKSUM TABLE of every table before and after; only dd_settings / dd_audit may change (V29 setting + audit line).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'platform/schema.php'; require_once 'platform/tenancy.php'; require_once 'f360/core.php';
$apply = in_array('--apply', $argv, true); $backup = in_array('--i-have-a-backup', $argv, true);
$days = 365; foreach ($argv as $a) if (preg_match('/^--complimentary-days=(\d{1,4})$/', $a, $m)) $days = (int)$m[1];
$direct = !in_array('--no-closing-direct', $argv, true);
$c = cfg(); $legacy = ['db_host' => $c['db_host'] ?? 'localhost', 'db_port' => (int)($c['db_port'] ?? 3306), 'db_name' => $c['db_name'] ?? '', 'db_user' => $c['db_user'] ?? '', 'db_password' => $c['db_password'] ?? '', 'storage' => '', 'tenant_id' => null, 'branch_id' => null];
if ($legacy['db_name'] === '' || str_starts_with($legacy['db_name'], 'YOUR_')) { fwrite(STDERR, "config.php db_* must point at the original restaurant database.\n"); exit(1); }
if ($apply && !$backup) { fwrite(STDERR, "Refusing: take a full database + private/ backup first, then add --i-have-a-backup.\n"); exit(1); }
$checksums = function () { $o = []; foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $r = db()->query('CHECKSUM TABLE `' . $t . '`')->fetch(); $o[$t] = [(string)$r['Checksum'], (int)db()->query('SELECT COUNT(*) FROM `' . $t . '`')->fetchColumn()]; } ksort($o); return $o; };
db_switch($legacy);
if (!installed()) { fwrite(STDERR, "The configured database is not an installed Finance 360 database.\n"); exit(1); }
$before = $checksums();
$users = rows("SELECT id,name,email,role,password,session_rev FROM dd_users ORDER BY id");
$act = []; foreach ($users as $u) { $a = row('SELECT * FROM dd_users WHERE id=?', $u['id']); if ((int)($a['active'] ?? 1) === 1) $act[] = $u; }
$owner = null; foreach ($act as $u) if ($u['role'] === 'owner') { $owner = $u; break; }
$restaurant = setting('restaurant', 'Khadak Singh Da Dhaba');
echo "== Legacy database: {$legacy['db_name']} ({$restaurant})\n";
echo 'Tables: ' . count($before) . ' · users: ' . count($users) . ' (active ' . count($act) . ') · owner: ' . ($owner ? $owner['email'] : 'NONE') . "\n";
foreach (['dd_employees', 'dd_bk_txns', 'f3_closings', 'f3_pay_lines', 'f3_sal_history', 'f3_purchase_bills', 'f3_stock_moves', 'dd_gl_journals'] as $t) if (isset($before[$t])) echo str_pad($t, 20) . " rows {$before[$t][1]}\n";
db_switch(null);
if (!$owner) { fwrite(STDERR, "No active owner user found — cannot adopt.\n"); exit(1); }
$plan = [];
foreach ($act as $u) { $em = strtolower(trim($u['email'])); $ex = prow('SELECT id FROM p_users WHERE email=?', $em);
    $tpl = (function () use ($u, $legacy) { db_switch($legacy); try { return f3_template_of($u); } finally { db_switch(null); } })();
    $plan[] = ['local' => (int)$u['id'], 'email' => $em, 'name' => $u['name'], 'role' => $u['role'], 'template' => $tpl, 'platform_user' => $ex ? 'EXISTS #' . $ex['id'] . ' (will be linked)' : 'new']; }
foreach ($plan as $p) echo sprintf("  user #%d %-32s role=%-11s template=%-17s platform=%s\n", $p['local'], $p['email'], $p['role'], $p['template'], $p['platform_user']);
if (prow('SELECT id FROM p_branches WHERE db_host=? AND db_port=? AND db_name=?', $legacy['db_host'], $legacy['db_port'], $legacy['db_name'])) { echo "Already adopted — nothing to do.\n"; exit(0); }
if (!$apply) { echo "\nDRY RUN — nothing was written. Re-run with --apply --i-have-a-backup.\n"; exit(0); }

$res = ptx(function () use ($plan, $owner, $restaurant, $legacy, $days) {
    $ids = [];
    foreach ($plan as $p) { $ex = prow('SELECT id FROM p_users WHERE email=?', $p['email']);
        if ($ex) $ids[$p['local']] = (int)$ex['id'];
        else { db_switch($legacy); $hash = row('SELECT password FROM dd_users WHERE id=?', $p['local'])['password']; db_switch(null);
            prun("INSERT INTO p_users(uid,email,name,password_hash,email_verified_at,legacy_source) VALUES(?,?,?,?,UTC_TIMESTAMP(),'ksdd')", p_uid(), $p['email'], $p['name'], $hash); $ids[$p['local']] = pid(); } }
    $tuid = p_uid(); $ou = $ids[(int)$owner['id']];
    prun("INSERT INTO p_tenants(uid,name,status,owner_user_id,activated_at) VALUES(?,?,'active',?,UTC_TIMESTAMP())", $tuid, $restaurant, $ou); $tid = pid();
    prun("INSERT INTO p_branches(uid,tenant_id,name,status,is_primary,db_host,db_port,db_name,db_user,db_pass_enc,storage,cutoff_hour) VALUES(?,?,'Main','active',1,?,?,?,?,?,'',4)", $buid = p_uid(), $tid, $legacy['db_host'], $legacy['db_port'], $legacy['db_name'], $legacy['db_user'], p_enc((string)$legacy['db_password'])); $bid = pid();
    foreach ($plan as $p) { $role = $p['local'] === (int)$owner['id'] ? 'owner' : ($p['role'] === 'owner' ? 'admin' : 'member');
        prun('INSERT INTO p_tenant_members(tenant_id,user_id,role) VALUES(?,?,?)', $tid, $ids[$p['local']], $role);
        prun('INSERT INTO p_branch_members(tenant_id,branch_id,user_id,local_user_id,template) VALUES(?,?,?,?,?)', $tid, $bid, $ids[$p['local']], $p['local'], $p['template']); }
    $pv = (int)pval("SELECT v.id FROM p_plan_versions v JOIN p_plans p ON p.id=v.plan_id WHERE p.code='legacy_complimentary' ORDER BY v.version DESC LIMIT 1");
    prun("INSERT INTO p_subscriptions(tenant_id,plan_version_id,kind,status) VALUES(?,?,'complimentary','active')", $tid, $pv);
    prun("INSERT INTO p_sub_periods(tenant_id,source,plan_version_id,starts_at,ends_at,reason) VALUES(?,'legacy',?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? DAY),'Founding restaurant migrated from single-restaurant system; complimentary until the owner approves pricing')", $tid, $pv, $days);
    // The founding owner has used no sign-up trial; record that so a SECOND restaurant by this owner does not get an automatic one silently.
    prun("INSERT INTO p_trial_grants(tenant_id,owner_user_id,auto_key,days,starts_at,ends_at,reason) VALUES(?,?,?,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),'Founding restaurant (complimentary, no trial)')", $tid, $ou, 'owner:' . $ou);
    if (is_file('assets/brand/logo-mark-256.png') && @copy('assets/brand/logo-mark-256.png', p_private('logos') . '/' . $tuid . '.png')) prun('UPDATE p_tenants SET logo_path=? WHERE id=?', 'logos/' . $tuid . '.png', $tid); // keep the restaurant's own logo
    p_audit('system', null, $tid, 'legacy.adopt', ['db' => $legacy['db_name'], 'users' => count($plan), 'branch_uid' => $buid]);
    return ['tenant' => $tid, 'branch' => $bid, 'branch_uid' => $buid];
});
db_switch($legacy);
if ($direct) { set_setting('f3_closing_direct', '1'); audit(null, 'f360.v29_closing_direct', ['by' => 'platform-adopt-legacy', 'note' => 'V29 §8 owner decision: closing is final on submit']); }
$after = $checksums(); $changed = [];
foreach ($before as $t => $v) if (($after[$t] ?? null) !== $v) $changed[] = $t;
$unexpected = array_values(array_diff($changed, ['dd_settings', 'dd_audit']));
echo "\nAdopted: tenant #{$res['tenant']}, branch #{$res['branch']} (uid {$res['branch_uid']}).\n";
echo 'Tables changed: ' . ($changed ? implode(', ', $changed) : 'none') . "\n";
if ($unexpected) { fwrite(STDERR, 'UNEXPECTED CHANGES: ' . implode(', ', $unexpected) . " — restore from backup and report.\n"); exit(3); }
echo "Financial, payroll, stock, supplier and GL tables: byte-identical (CHECKSUM TABLE before = after).\n";
echo "Next: set 'sync_branch_uid' => '{$res['branch_uid']}' in config.php if the Petpooja PC upload is used. Users sign in at signin.php with their existing passwords.\n";
file_put_contents('private/logs/adopt-' . date('Ymd-His') . '.json', json_encode(['result' => $res, 'before' => $before, 'after' => $after, 'changed' => $changed], JSON_PRETTY_PRINT));
