<?php
declare(strict_types=1);
// Finance 360 REST API v1 for the Android (Flutter) app. Contract: docs/openapi.yaml.
// • Auth: Bearer access token (15 min) + rotating refresh token (30 days) bound to a device id. No cookies, no CSRF.
// • The active restaurant/branch lives on the SERVER token row (set by POST /tenants/select after membership checks);
//   every request re-authorises it, so revoking a membership or disabling a user takes effect immediately.
// • Finance routes call the SAME f3_dispatch() as the website → identical permissions, validation, idempotency, write gate.
// • Money = integer paise (minor units). Dates = YYYY-MM-DD business dates (Asia/Kolkata). Timestamps = UTC.
chdir(dirname(__DIR__, 2));
$GLOBALS['F360_API_GATED'] = true; // this front controller calls platform_write_gate() itself, with the resolved route
require dirname(__DIR__, 2) . '/platform/register.php';
require_once dirname(__DIR__, 2) . '/f360/dispatch.php';
header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
const V1_VERSION = '1.0.0';
const V1_ALIASES = [ // REST-style aliases → existing Finance 360 routes (both forms work: /f360/{route})
    'GET dashboard' => 'dashboard', 'GET closing' => 'closing', 'POST closing/save' => 'closing-save', 'POST closing/finalize' => 'closing-finalize', 'POST closing/correction' => 'correction-request',
    'GET closing/photos' => 'closing-photos', 'POST closing/photos' => 'closing-photo', 'POST closing/photos/remove' => 'closing-photo-remove', 'GET closing/pending' => 'pending-closings',
    'GET sales' => 'sales-history', 'GET expenses' => 'expenses', 'POST expenses' => 'expense-save', 'POST expenses/void' => 'expense-void', 'GET expenses/categories' => 'expense-categories',
    'GET cashbook' => 'book', 'GET accounts' => 'accounts', 'GET handover' => 'handovers', 'POST handover' => 'handover-send', 'POST handover/respond' => 'handover-respond',
    'GET employees' => 'staff', 'GET employees/one' => 'staff-get', 'POST employees' => 'staff-save', 'GET departments' => 'departments',
    'GET salary/periods' => 'payroll-months', 'GET salary/sheet' => 'payroll', 'GET salary/drafts' => 'payroll-line', 'POST salary/drafts' => 'payroll-line', 'POST salary/open' => 'payroll-open',
    'POST salary/submit' => 'payroll-submit', 'POST salary/approve' => 'payroll-approve', 'GET salary/history' => 'salary-history', 'GET salary/payslip' => 'payslip', 'POST salary/payments' => 'payroll-pay',
    'GET staff-entries' => 'staff-txns', 'POST advances' => 'staff-txn', 'POST penalties' => 'staff-txn', 'POST overtime' => 'staff-txn',
    'GET suppliers' => 'suppliers', 'GET suppliers/one' => 'supplier', 'GET suppliers/suggest' => 'supplier-suggest', 'POST suppliers' => 'supplier-save', 'POST suppliers/pay' => 'supplier-pay',
    'GET purchase-bills' => 'bills', 'GET purchase-bills/one' => 'bill', 'POST purchase-bills' => 'bill-save', 'POST purchase-bills/submit' => 'bill-submit', 'POST purchase-bills/attach' => 'bill-attach',
    'GET items' => 'items', 'GET items/one' => 'item', 'POST items' => 'item-save', 'GET items/price-history' => 'price-history', 'GET stock' => 'stock', 'GET stock/moves' => 'stock-moves', 'POST stock/move' => 'stock-move', 'POST stock/consume' => 'stock-consume',
    'GET stock/counts' => 'stock-counts', 'GET stock/count' => 'stock-count', 'POST stock/count/start' => 'count-start', 'POST stock/count/save' => 'count-save', 'POST stock/count/approve' => 'count-approve', 'GET stock/reorder' => 'reorder', 'GET stock/valuation' => 'stock-valuation',
    'GET documents' => 'docs', 'POST documents' => 'doc-upload', 'GET reports/daily' => 'report-daily', 'GET reports/monthly' => 'report-monthly', 'GET reports/staff' => 'report-staff', 'GET reports/gl' => 'gl', 'GET reports/aging' => 'aging', 'GET reports/export' => 'export',
    'GET notifications' => 'notifications', 'GET files' => 'file', 'GET files/thumb' => 'thumb', 'GET search' => 'search',
];
const V1_CODES = [400 => 'VALIDATION_ERROR', 401 => 'UNAUTHENTICATED', 402 => 'SUBSCRIPTION_READ_ONLY', 403 => 'FORBIDDEN', 404 => 'NOT_FOUND', 405 => 'METHOD_NOT_ALLOWED', 409 => 'CONFLICT', 410 => 'GONE', 413 => 'TOO_LARGE', 422 => 'UNPROCESSABLE', 429 => 'RATE_LIMITED', 502 => 'UPSTREAM_ERROR', 503 => 'UNAVAILABLE'];
function v1_out(array $d, int $s = 200): never { http_response_code($s); header('Content-Type: application/json; charset=utf-8'); header('X-API-Version: ' . V1_VERSION); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); exit; }
function v1_body(): array { if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8000000) fail('Request too large (max 8 MB).', 413); $raw = (string)file_get_contents('php://input', false, null, 0, 8000001); if ($raw === '') return []; $b = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); if (!is_array($b) || (array_is_list($b) && $b !== [])) fail('Body must be a JSON object.'); return $b; }
function v1_bearer(): string { $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''); if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{20,200})$/', $h, $m)) fail('Sign in required.', 401); return $m[1]; }
/** Resolve the token's selected restaurant/branch, connect to that branch ledger ONLY, and map to the local user. */
function v1_enter(array $tok): array {
    if (!$tok['tenant_id'] || !$tok['branch_id']) fail('Choose a restaurant and branch first (POST /tenants/select).', 409);
    $m = tenancy_authorize((int)$tok['user_id'], (int)$tok['tenant_id'], (int)$tok['branch_id']);
    db_switch(tenancy_branch_target($m, ['platform_user_id' => (int)$tok['user_id']]));
    $lu = row('SELECT id,session_rev FROM dd_users WHERE id=?', (int)$m['local_user_id']); if (!$lu) fail('Your account is not linked in this branch.', 403);
    $_SESSION = ['user_id' => (int)$lu['id'], 'rev' => (int)$lu['session_rev'], 'expires' => time() + 300, 'csrf' => ''];
    f3_ready(); return user();
}
const V1_MENUS = [ // module → any of these permissions shows it. The app must render ONLY what this returns.
    'closing' => ['closing.view', 'closing.entry'], 'sales' => ['sales.view'], 'expenses' => ['expenses.view', 'expenses.create', 'expenses.today'], 'cash' => ['cash.view'], 'handover' => ['handover.send', 'handover.receive'],
    'staff' => ['staff.view'], 'payroll' => ['payroll.view', 'payroll.prepare'], 'purchases' => ['purchase.view', 'purchase.create'], 'suppliers' => ['suppliers.view'], 'stock' => ['stock.view', 'item.view', 'stock.count'],
    'documents' => ['docs.inbox'], 'reports' => ['reports.view'], 'accounts' => ['accounts.view'], 'settings' => ['users.manage', 'settings.manage']];
function v1_is_read_perm(string $p): bool { return (bool)preg_match('/\.(view|outstanding|ledger|valuation|export|report|bank|variance)$/', $p) || in_array($p, ['legacy.access', 'audit.view'], true); }
function v1_permissions(array $u): array {
    $t = db_target(); $ent = $t['entitlement']; $perms = f3_perms($u);
    $acts = $ent['read_only'] || !empty($t['support_grant']) ? array_values(array_filter($perms, 'v1_is_read_perm')) : $perms;
    $menus = []; foreach (V1_MENUS as $k => $any) if (array_intersect($any, $perms)) $menus[] = $k;
    $state = ['perms' => $acts, 'menus' => $menus, 'state' => $ent['state'], 'modules' => $ent['modules']];
    return ['tenant_id' => (int)$t['tenant_id'], 'branch_id' => (int)$t['branch_id'], 'branch_name' => $t['branch_name'], 'template' => f3_template_of($u),
        'menus' => $menus, 'home' => count($menus) === 1 ? $menus[0] : 'dashboard', 'actions' => $acts, 'read_only' => (bool)$ent['read_only'] || !empty($t['support_grant']),
        'subscription' => billing_public_state((int)$t['tenant_id']), 'plan_modules' => $ent['modules'], 'business_date' => f3_business_date(), 'closing_target_date' => f3_target_date(),
        'perm_version' => substr(hash('sha256', json_encode($state)), 0, 16), 'refresh_after_seconds' => 300];
}

try {
    if (!platform_mode()) fail('The mobile API needs multi-restaurant mode (platform block in config.php).', 503);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = trim((string)($_GET['__path'] ?? $_SERVER['PATH_INFO'] ?? ''), '/'); unset($_GET['__path']);
    if ($path === '' || !preg_match('#^[a-z0-9/-]{1,80}$#', $path)) fail('Not found', 404);
    $key = $method . ' ' . $path;
    // ---------------- public ----------------
    if ($key === 'GET health') v1_out(['ok' => true, 'version' => V1_VERSION, 'time' => p_utc()]);
    if ($key === 'GET subscriptions/plans') v1_out(['plans' => billing_plans(), 'trial_days' => (int)p_setting('trial_days')]);
    if ($key === 'POST auth/login') { $b = v1_body(); $u = p_login((string)($b['email'] ?? ''), (string)($b['password'] ?? ''));
        $tok = p_api_issue($u, (string)($b['device_id'] ?? ''), isset($b['device_name']) ? (string)$b['device_name'] : null);
        p_audit('user', (int)$u['id'], null, 'api.login', ['device' => substr((string)$b['device_id'], 0, 20)]);
        v1_out($tok + ['user' => ['id' => (int)$u['id'], 'name' => $u['name'], 'email' => $u['email']], 'memberships' => tenancy_memberships((int)$u['id'])]); }
    if ($key === 'POST auth/refresh') { $b = v1_body(); v1_out(p_api_refresh((string)($b['refresh_token'] ?? ''), (string)($b['device_id'] ?? ''))); }
    if ($key === 'POST auth/password-forgot') { $b = v1_body(); p_password_forgot((string)($b['email'] ?? '')); v1_out(['ok' => true, 'message' => 'If an account exists, a reset link was emailed.']); }
    if ($key === 'POST tenants/register') { $b = v1_body(); $r = reg_start($b); v1_out($r + ['message' => 'Check your email to verify and finish setting up your restaurant.'], 201); }
    // ---------------- authenticated ----------------
    $tok = p_api_auth(v1_bearer()); $uid = (int)$tok['user_id'];
    prun('UPDATE p_api_tokens SET used_at=UTC_TIMESTAMP() WHERE id=? AND (used_at IS NULL OR used_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE))', $tok['id']);
    switch ($key) {
        case 'POST auth/logout': p_api_logout($tok['family']); v1_out(['ok' => true]);
        case 'GET auth/profile': $u = prow('SELECT id,uid,name,email,mobile,email_verified_at FROM p_users WHERE id=?', $uid); v1_out(['user' => $u, 'memberships' => tenancy_memberships($uid), 'selected' => ['tenant_id' => $tok['tenant_id'] ? (int)$tok['tenant_id'] : null, 'branch_id' => $tok['branch_id'] ? (int)$tok['branch_id'] : null]]);
        case 'GET tenants': v1_out(['tenants' => tenancy_memberships($uid)]);
        case 'POST tenants/select':
            $b = v1_body(); $tid = (int)($b['tenant_id'] ?? 0); $bid = (int)($b['branch_id'] ?? 0);
            tenancy_authorize($uid, $tid, $bid); // same 403 for "not yours" and "does not exist"
            $u = prow('SELECT * FROM p_users WHERE id=?', $uid);
            prun('UPDATE p_api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE family=? AND revoked_at IS NULL', $tok['family']); // context change = new token pair
            $new = p_api_issue($u, (string)$tok['device_id'], $tok['device_name'], $tok['family'], $tid, $bid);
            p_audit('user', $uid, $tid, 'api.select', ['branch' => $bid]);
            $tk = p_api_auth($new['access_token']); $lu = v1_enter($tk);
            v1_out($new + ['permissions' => v1_permissions($lu)]);
        case 'GET branches': if (!$tok['tenant_id']) fail('Choose a restaurant first.', 409); foreach (tenancy_memberships($uid) as $t) if ($t['id'] === (int)$tok['tenant_id']) v1_out(['branches' => $t['branches']]); fail('Restaurant not available.', 403);
        case 'POST devices/register': $b = v1_body(); $ft = (string)($b['fcm_token'] ?? ''); if (strlen($ft) < 20 || strlen($ft) > 4096) fail('Invalid push token.');
            prun('INSERT INTO p_device_tokens(user_id,token_sha,token_enc,platform,api_family) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),api_family=VALUES(api_family),revoked_at=NULL', $uid, p_sha($ft), p_enc($ft), 'android', $tok['family']); v1_out(['ok' => true]);
        case 'GET subscriptions/current': if (!$tok['tenant_id']) fail('Choose a restaurant first.', 409); tenancy_authorize($uid, (int)$tok['tenant_id'], (int)$tok['branch_id']); v1_out(billing_public_state((int)$tok['tenant_id']) + (!empty(pcfg()['billing']['app_may_link_web']) ? ['manage_on_web' => p_base_url() . '/owner.php?p=billing'] : [])); // no payment steering in the Play app unless approved (docs/SUBSCRIPTIONS_AND_BILLING.md)
        case 'GET subscriptions/payments': if (!$tok['tenant_id'] || !in_array(tenancy_member_role($uid, (int)$tok['tenant_id']), ['owner', 'admin'], true)) fail('Only the owner can see billing.', 403);
            v1_out(['orders' => array_map(fn($o) => ['order' => $o['uid'], 'amount_paise' => (int)$o['amount_paise'], 'currency' => $o['currency'], 'status' => $o['status'], 'receipt_no' => $o['receipt_no'], 'created_at' => $o['created_at'], 'paid_at' => $o['paid_at']], prows('SELECT * FROM p_orders WHERE tenant_id=? ORDER BY id DESC LIMIT 100', (int)$tok['tenant_id']))]);
        case 'POST subscriptions/checkout': $b = v1_body(); if (!$tok['tenant_id']) fail('Choose a restaurant first.', 409);
            // NOTE: in-app purchase of digital subscriptions on Google Play is governed by Play's payments policy; this endpoint stays
            // disabled for the Android app unless config platform.billing.allow_app_checkout = true after policy review (docs/BILLING_RUNBOOK.md).
            if (empty(pcfg()['billing']['allow_app_checkout'])) fail('Plan purchase is not available in the app.', 403);
            v1_out(billing_create_order((int)$tok['tenant_id'], (int)($b['plan_version_id'] ?? 0), $uid));
    }
    // ---------------- finance (same dispatcher as the website) ----------------
    $u = v1_enter($tok);
    if ($key === 'GET permissions/me') v1_out(v1_permissions($u));
    $route = str_starts_with($path, 'f360/') ? substr($path, 5) : (V1_ALIASES[$key] ?? null);
    if ($route === null) fail('Not found', 404);
    ['GET' => $GET, 'POST' => $POST] = f3_routes();
    if (!in_array($route, $method === 'GET' ? $GET : ($method === 'POST' ? $POST : []), true)) fail(in_array($route, array_merge($GET, $POST), true) ? 'Method not allowed' : 'Not found', in_array($route, array_merge($GET, $POST), true) ? 405 : 404);
    $_GET['route'] = $route; // the write gate inside user() reads the route
    if ($method === 'POST') { platform_write_gate($route); $b = v1_body();
        $ik = (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''); if ($ik !== '' && !isset($b['client_key'])) { if (!preg_match('/^[A-Za-z0-9_:.-]{8,64}$/', $ik)) fail('Invalid Idempotency-Key.'); $b['client_key'] = $ik; }
    } else $b = [];
    if ($route === 'me') fail('Use GET /permissions/me and GET /auth/profile.', 404);
    f3_dispatch($method, $route, $_GET, $b, $u, true);
} catch (Throwable $e) {
    try { if (!empty($GLOBALS['F360_PDO']) && db()->inTransaction()) db()->rollBack(); if (!empty($GLOBALS['P_PDO']) && pdb()->inTransaction()) pdb()->rollBack(); } catch (Throwable $ignored) {}
    if ($e instanceof F3Conflict) v1_out(['error' => $e->getMessage(), 'code' => 'CONFLICT'] + $e->extra, 409);
    if ($e instanceof PDOException) { $ref = strtoupper(bin2hex(random_bytes(3))); error_log('api v1 db error ' . $ref . ': ' . ($e->errorInfo[1] ?? '') . ' ' . $e->getMessage());
        $c = (int)($e->errorInfo[1] ?? 0); if ($c === 1062) v1_out(['error' => 'This record already exists. No duplicate was added.', 'code' => 'CONFLICT'], 409); if (in_array($c, [1205, 1213], true)) v1_out(['error' => 'Another entry is being saved. Try again.', 'code' => 'CONFLICT'], 409);
        v1_out(['error' => 'Server database error (ref ' . $ref . ').', 'code' => 'SERVER_ERROR'], 500); }
    if ($e instanceof JsonException) v1_out(['error' => 'Invalid JSON.', 'code' => 'VALIDATION_ERROR'], 400);
    $s = $e instanceof RuntimeException && isset(V1_CODES[$e->getCode()]) ? $e->getCode() : ($e instanceof RuntimeException ? 400 : 500);
    if (!$e instanceof RuntimeException) error_log('api v1: ' . get_class($e) . ' ' . $e->getMessage());
    v1_out(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Server error.', 'code' => V1_CODES[$s] ?? 'SERVER_ERROR'], $s);
}
