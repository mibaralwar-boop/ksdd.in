<?php
declare(strict_types=1);
// Finance 360 V29 — tenant / branch isolation and provisioning.
//
// ISOLATION MODEL (documented in docs/TENANT_ISOLATION.md):
//   Platform DB (control plane) → tenants, branches, users, memberships, billing.  NO financial rows.
//   One ledger database PER BRANCH, created from the same schema as the original restaurant's database.
//   A request can only ever hold a connection to ONE branch ledger, chosen here from the caller's verified
//   membership. The client never supplies database names; tenant/branch ids it sends are re-authorised.
//   Consequence: a missing WHERE clause in any of the ~1,600 existing queries cannot reach another restaurant,
//   because the other restaurant's rows are not in the connected database at all.
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/billing.php';

/* ============================== targets ============================== */
function tenancy_branch_target(array $b, array $extra = []): array {
    $pass = p_dec($b['db_pass_enc'] ?? null);
    if ($pass === null) fail('Branch database credentials cannot be read (platform key changed?).', 503);
    return ['db_host' => $b['db_host'], 'db_port' => (int)$b['db_port'], 'db_name' => $b['db_name'], 'db_user' => $b['db_user'], 'db_password' => $pass,
            'storage' => (string)$b['storage'], 'tenant_id' => (int)$b['tenant_id'], 'branch_id' => (int)$b['id'], 'branch_uid' => $b['uid'], 'branch_name' => $b['name'],
            'entitlement' => billing_entitlement((int)$b['tenant_id'])] + $extra;
}
function tenancy_branch(int $branchId): ?array { return prow('SELECT * FROM p_branches WHERE id=?', $branchId); }
/** Branch target for jobs/consolidation. Caller must already have authorised access. */
function tenancy_target_for(int $tenantId, int $branchId): array {
    $b = prow("SELECT * FROM p_branches WHERE id=? AND tenant_id=? AND status='active'", $branchId, $tenantId);
    if (!$b) fail('Branch not found.', 404);
    return tenancy_branch_target($b);
}

/* ============================== memberships ============================== */
/** Active tenants + branches the user may enter. Only explicit memberships count (never e-mail / phone inference). */
function tenancy_memberships(int $userId): array {
    $out = [];
    foreach (prows("SELECT t.id,t.uid,t.name,t.status,t.logo_path,tm.role FROM p_tenant_members tm JOIN p_tenants t ON t.id=tm.tenant_id WHERE tm.user_id=? AND tm.status='active' AND t.status IN ('active','suspended') ORDER BY t.name", $userId) as $t) {
        $br = prows("SELECT b.id,b.uid,b.name,b.city,b.is_primary,bm.template FROM p_branch_members bm JOIN p_branches b ON b.id=bm.branch_id AND b.tenant_id=bm.tenant_id WHERE bm.user_id=? AND bm.tenant_id=? AND bm.status='active' AND b.status='active' ORDER BY b.is_primary DESC,b.name", $userId, $t['id']);
        $out[] = ['id' => (int)$t['id'], 'uid' => $t['uid'], 'name' => $t['name'], 'status' => $t['status'], 'role' => $t['role'], 'logo' => (bool)$t['logo_path'],
                  'branches' => array_map(fn($b) => ['id' => (int)$b['id'], 'uid' => $b['uid'], 'name' => $b['name'], 'city' => $b['city'], 'primary' => (bool)$b['is_primary'], 'template' => $b['template']], $br),
                  'subscription' => billing_public_state((int)$t['id'])];
    }
    return $out;
}
function tenancy_member_role(int $userId, int $tenantId): ?string { return pval("SELECT role FROM p_tenant_members WHERE user_id=? AND tenant_id=? AND status='active'", $userId, $tenantId); }
/** Server-side authorisation of a requested tenant + branch. Same error for "does not exist" and "not yours" (no existence leak). */
function tenancy_authorize(int $userId, int $tenantId, int $branchId): array {
    $r = prow("SELECT bm.local_user_id,bm.template,b.*,t.status tstatus FROM p_branch_members bm
               JOIN p_branches b ON b.id=bm.branch_id AND b.tenant_id=bm.tenant_id
               JOIN p_tenant_members tm ON tm.tenant_id=bm.tenant_id AND tm.user_id=bm.user_id AND tm.status='active'
               JOIN p_tenants t ON t.id=b.tenant_id
               JOIN p_users u ON u.id=bm.user_id AND u.status='active'
               WHERE bm.user_id=? AND bm.tenant_id=? AND bm.branch_id=? AND bm.status='active' AND b.status='active' AND t.status IN ('active','suspended')", $userId, $tenantId, $branchId);
    if (!$r) fail('Restaurant or branch not available for this account.', 403);
    return $r;
}

/* ============================== web session ============================== */
function tenancy_session_enter(array $user, int $tenantId, int $branchId): array {
    $m = tenancy_authorize((int)$user['id'], $tenantId, $branchId);
    $target = tenancy_branch_target($m);
    db_switch($target);
    $local = row('SELECT id,session_rev FROM dd_users WHERE id=?', (int)$m['local_user_id']);
    if (!$local) fail('Your account is not linked in this branch. Ask the owner to re-invite you.', 403);
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['p_uid'] = (int)$user['id']; $_SESSION['p_rev'] = (int)$user['session_rev'];
    $_SESSION['p_tenant'] = $tenantId; $_SESSION['p_branch'] = $branchId; unset($_SESSION['p_support']);
    $_SESSION['user_id'] = (int)$local['id']; $_SESSION['rev'] = (int)$local['session_rev'];
    $_SESSION['expires'] = time() + 28800; $_SESSION['csrf'] = bin2hex(random_bytes(24));
    p_audit('user', (int)$user['id'], $tenantId, 'branch.enter', ['branch' => $branchId]);
    return $target;
}
/** Called lazily by db() on every web request in platform mode. Re-verifies everything against the platform DB, so a revoked
 *  membership, disabled user, suspended branch or rotated password takes effect on the very next request (no stale tabs). */
function tenancy_resolve_session(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) fail('Please sign in', 401);
    $uid = (int)($_SESSION['p_uid'] ?? 0);
    if (!empty($_SESSION['p_support'])) return tenancy_resolve_support((int)$_SESSION['p_support']);
    if (!$uid || ($_SESSION['expires'] ?? 0) < time()) fail('Please sign in', 401);
    $u = prow("SELECT id,session_rev,status FROM p_users WHERE id=?", $uid);
    if (!$u || $u['status'] !== 'active' || (int)$u['session_rev'] !== (int)($_SESSION['p_rev'] ?? -1)) fail('Please sign in', 401);
    if (empty($_SESSION['p_tenant']) || empty($_SESSION['p_branch'])) fail('Choose a restaurant and branch first.', 401);
    $m = tenancy_authorize($uid, (int)$_SESSION['p_tenant'], (int)$_SESSION['p_branch']);
    if ((int)$m['local_user_id'] !== (int)($_SESSION['user_id'] ?? 0)) fail('Please sign in', 401);
    return tenancy_branch_target($m, ['platform_user_id' => $uid]);
}
/** Break-glass support session: approved, unexpired grant only; always read-only (write gate) and audited. */
function tenancy_resolve_support(int $grantId): array {
    $g = prow("SELECT * FROM p_support_grants WHERE id=? AND status='approved' AND expires_at>UTC_TIMESTAMP()", $grantId);
    $a = $g ? prow("SELECT id,session_rev,status FROM p_admins WHERE id=?", (int)$g['admin_id']) : null;
    if (!$g || !$a || $a['status'] !== 'active' || (int)($_SESSION['p_admin_support'] ?? 0) !== (int)$a['id']) { unset($_SESSION['p_support']); fail('Support access has ended.', 401); }
    $b = prow("SELECT * FROM p_branches WHERE id=? AND tenant_id=? AND status='active'", (int)$g['branch_id'], (int)$g['tenant_id']); if (!$b) fail('Branch not available.', 404);
    return tenancy_branch_target($b, ['support_grant' => (int)$g['id'], 'support_admin' => (int)$a['id']]);
}

/** Write gate shared by EVERY router (web session, legacy pages and /api/v1). Called from user() for POST requests. */
const P_WRITE_ALLOW_READONLY = ['snooze', 'user-password', 'logout'];
function platform_write_gate(string $route): void {
    if (!platform_mode()) return;
    $t = db_target();
    if (!empty($t['support_grant'])) fail('Support access is read-only. No changes can be made.', 403);
    $ent = $t['entitlement'] ?? null;
    if ($ent && !empty($ent['read_only']) && !in_array($route, P_WRITE_ALLOW_READONLY, true))
        throw new F360PaymentRequired('Your subscription is ' . $ent['state_label'] . '. Your records are safe and can be viewed and exported. Renew the plan to add or change entries.');
}
final class F360PaymentRequired extends RuntimeException { public function __construct(string $m) { parent::__construct($m, 402); } }

/* ============================== provisioning ============================== */
function prov_mode(): string { return (string)(pcfg()['provision']['mode'] ?? 'pool'); }
/** Get an EMPTY database for a new branch: from the pre-created pool (Hostinger shared hosting) or by CREATE DATABASE (VPS). */
function prov_claim_db(int $branchId): array {
    $b = prow('SELECT * FROM p_branches WHERE id=?', $branchId); if (!$b) fail('Branch missing.', 500);
    if ($b['db_name']) return $b; // idempotent resume
    if (prov_mode() === 'create') {
        $c = (array)(pcfg()['provision']['create'] ?? []); $prefix = preg_replace('/[^a-z0-9_]/', '', (string)($c['prefix'] ?? 'f360_'));
        $name = $prefix . 'b' . $b['uid']; if (strlen($name) > 64) fail('Database name too long.', 500);
        $root = db_connect_target(['db_host' => $c['db_host'], 'db_port' => $c['db_port'] ?? 3306, 'db_name' => 'information_schema', 'db_user' => $c['db_user'], 'db_password' => $c['db_password']]);
        $root->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $cred = ['db_host' => $c['db_host'], 'db_port' => (int)($c['db_port'] ?? 3306), 'db_name' => $name, 'db_user' => $c['db_user'], 'db_pass_enc' => p_enc((string)$c['db_password'])];
    } else {
        $cred = ptx(function () use ($branchId) {
            $p = prow("SELECT * FROM p_db_pool WHERE status='available' ORDER BY id LIMIT 1 FOR UPDATE");
            if (!$p) fail('No free restaurant database is available right now. Your registration is saved; our team has been alerted and will finish it shortly.', 503);
            prun("UPDATE p_db_pool SET status='claimed',branch_id=?,claimed_at=UTC_TIMESTAMP() WHERE id=?", $branchId, $p['id']);
            return ['db_host' => $p['db_host'], 'db_port' => (int)$p['db_port'], 'db_name' => $p['db_name'], 'db_user' => $p['db_user'], 'db_pass_enc' => $p['db_pass_enc']];
        });
    }
    // Never reuse a database that holds anything, and never one already mapped to another branch.
    if (prow('SELECT id FROM p_branches WHERE db_host=? AND db_port=? AND db_name=? AND id<>?', $cred['db_host'], $cred['db_port'], $cred['db_name'], $branchId)) fail('Database already belongs to another branch.', 500);
    $pdo = db_connect_target(['db_password' => p_dec($cred['db_pass_enc'])] + $cred);
    $n = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    if ($n > 0) { if (prov_mode() !== 'create') prun("UPDATE p_db_pool SET status='retired' WHERE db_host=? AND db_port=? AND db_name=?", $cred['db_host'], $cred['db_port'], $cred['db_name']); fail('Assigned database is not empty; it was set aside for review. Please retry.', 503); }
    prun('UPDATE p_branches SET db_host=?,db_port=?,db_name=?,db_user=?,db_pass_enc=? WHERE id=? AND db_name IS NULL', $cred['db_host'], $cred['db_port'], $cred['db_name'], $cred['db_user'], $cred['db_pass_enc'], $branchId);
    return prow('SELECT * FROM p_branches WHERE id=?', $branchId);
}
/** Build a branch ledger exactly the way the original restaurant database was built (schema.sql → CRM → Salary → Books → Finance 360). Idempotent. */
function prov_install_branch(array $branch, string $restaurant, int $cutoffHour): void {
    $target = tenancy_branch_target($branch); $prev = $GLOBALS['F360_TARGET'] ?? null;
    db_switch($target);
    try {
        if (!installed()) {
            foreach (explode(';', (string)file_get_contents(dirname(__DIR__) . '/schema.sql')) as $sql) if (trim($sql) !== '') db()->exec($sql);
            run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('restaurant',?),('schema_version','1')", mb_substr($restaurant . ($branch['is_primary'] ? '' : ' · ' . $branch['name']), 0, 160));
            require_once dirname(__DIR__) . '/migrations.php'; migrate_all(null);
            require_once dirname(__DIR__) . '/lib360.php'; require_once dirname(__DIR__) . '/salary360.php'; require_once dirname(__DIR__) . '/books360.php';
            sal_migrate(); bk_migrate();
            run("INSERT IGNORE INTO dd_settings(`key`,value) VALUES('installed','1')");
        }
        require_once dirname(__DIR__) . '/f360/admin.php';
        f3_migrate_all(null);
        set_setting('business_day_cutoff_hour', (string)max(0, min(12, $cutoffHour)));
        if (setting('f3_closing_start', '') === '') set_setting('f3_closing_start', f3_target_date());
        set_setting('f3_closing_direct', '1');
    } finally { db_switch($prev); }
}
/** Create (or re-link) the local ledger user that represents a platform member inside one branch database. */
function prov_local_user(array $branch, array $pUser, string $template): int {
    $prev = $GLOBALS['F360_TARGET'] ?? null; db_switch(tenancy_branch_target($branch));
    try {
        require_once dirname(__DIR__) . '/f360/core.php';
        $role = $template === 'owner' ? 'owner' : 'employee';
        if (!isset(f3_templates()[$template])) fail('Unknown access template.', 400);
        $ex = row('SELECT id FROM dd_users WHERE email=?', strtolower($pUser['email']));
        if ($ex) { $id = (int)$ex['id']; run('UPDATE dd_users SET role=?,name=?,session_rev=session_rev+1 WHERE id=?', $role, $pUser['name'], $id); }
        else { run('INSERT INTO dd_users(name,email,password,role) VALUES(?,?,?,?)', $pUser['name'], strtolower($pUser['email']), password_hash(p_token(), PASSWORD_DEFAULT), $role); $id = (int)db()->lastInsertId(); }
        try { run('UPDATE dd_users SET active=1 WHERE id=?', $id); } catch (PDOException $e) {}
        run('INSERT INTO f3_user_access(user_id,template,overrides) VALUES(?,?,NULL) ON DUPLICATE KEY UPDATE template=VALUES(template)', $id, $template);
        audit(null, 'platform.member_link', ['local_user' => $id, 'template' => $template, 'platform_user' => (int)$pUser['id']]);
        return $id;
    } finally { db_switch($prev); }
}
function prov_local_user_disable(array $branch, int $localId): void {
    $prev = $GLOBALS['F360_TARGET'] ?? null; db_switch(tenancy_branch_target($branch));
    try { run('UPDATE dd_users SET session_rev=session_rev+1 WHERE id=?', $localId); try { run('UPDATE dd_users SET active=0 WHERE id=? AND role<>\'owner\'', $localId); } catch (PDOException $e) {} }
    finally { db_switch($prev); }
}
/** New branch for an existing tenant (owner portal). Enforces the plan's branch quota. */
function tenancy_branch_create(int $tenantId, array $in, int $actorUserId): array {
    $ent = billing_entitlement($tenantId);
    if (!empty($ent['read_only'])) fail('Renew the subscription before adding a branch.', 402);
    $n = (int)pval("SELECT COUNT(*) FROM p_branches WHERE tenant_id=? AND status<>'disabled'", $tenantId);
    if ($n >= (int)$ent['max_branches']) fail('Your plan allows ' . $ent['max_branches'] . ' branch(es). Upgrade the plan to add more.', 402);
    $name = txt($in['name'] ?? '', 120); if ($name === '') fail('Branch name is required.');
    $uid = p_uid(); $t = prow('SELECT * FROM p_tenants WHERE id=?', $tenantId);
    prun("INSERT INTO p_branches(uid,tenant_id,name,address,city,cutoff_hour,status,is_primary,storage) VALUES(?,?,?,?,?,?,'provisioning',0,?)", $uid, $tenantId, $name, txt($in['address'] ?? '', 300) ?: null, txt($in['city'] ?? '', 80) ?: null, max(0, min(12, (int)($in['cutoff_hour'] ?? 4))), 'tenants/' . $t['uid'] . '/' . $uid);
    $bid = pid();
    return tenancy_branch_finish($bid, $actorUserId);
}
/** Resumable: claim DB → install → link owners/admins → activate. Safe to call again after any failure. */
function tenancy_branch_finish(int $branchId, int $actorUserId): array {
    $b = prov_claim_db($branchId); $t = prow('SELECT * FROM p_tenants WHERE id=?', $b['tenant_id']);
    prov_install_branch($b, $t['name'], (int)$b['cutoff_hour']);
    foreach (prows("SELECT u.*,tm.role FROM p_tenant_members tm JOIN p_users u ON u.id=tm.user_id WHERE tm.tenant_id=? AND tm.status='active' AND tm.role IN ('owner','admin')", $b['tenant_id']) as $pu) {
        $local = prov_local_user($b, $pu, 'owner');
        prun("INSERT INTO p_branch_members(tenant_id,branch_id,user_id,local_user_id,template,status) VALUES(?,?,?,?, 'owner','active') ON DUPLICATE KEY UPDATE local_user_id=VALUES(local_user_id),status='active',template='owner'", $b['tenant_id'], $b['id'], $pu['id'], $local);
    }
    prun("UPDATE p_branches SET status='active' WHERE id=? AND status='provisioning'", $branchId);
    p_audit('user', $actorUserId, (int)$b['tenant_id'], 'branch.create', ['branch' => $branchId, 'name' => $b['name']]);
    return prow('SELECT id,uid,name,status FROM p_branches WHERE id=?', $branchId);
}

/** For machine endpoints that carry their OWN per-branch secret (AiSensy webhook token, Petpooja sync token).
 *  The branch uid only routes the request; the endpoint must still verify that branch's secret before doing anything. */
function tenancy_target_by_branch_uid(string $uid): array {
    if (!preg_match('/^[a-z0-9]{12}$/', $uid)) fail('Not found', 404);
    $b = prow("SELECT b.* FROM p_branches b JOIN p_tenants t ON t.id=b.tenant_id WHERE b.uid=? AND b.status='active' AND t.status IN ('active','suspended')", $uid);
    if (!$b) fail('Not found', 404);
    return tenancy_branch_target($b);
}
