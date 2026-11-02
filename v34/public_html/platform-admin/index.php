<?php
declare(strict_types=1);
// Finance 360 V29 — Super Admin portal. Separate identity table (p_admins), separate session cookie (F360_PADMIN,
// path /platform-admin/), password + TOTP. Platform admins manage restaurants' SUBSCRIPTIONS and platform health;
// they do NOT see restaurant financial records. Viewing a restaurant's books needs an owner-approved, time-limited,
// read-only, audited support grant.
chdir(dirname(__DIR__));
require dirname(__DIR__) . '/platform/ui.php';
security_headers();
if (!https_request() && !local_request()) { http_response_code(403); exit('HTTPS required'); }
ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); session_name('F360_PADMIN');
session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/platform-admin/index.php'), '/') . '/', 'secure' => https_request(), 'httponly' => true, 'samesite' => 'Strict']);
session_start(); if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
$A = pa_current(); $pg = preg_replace('/[^a-z]/', '', (string)($_GET['p'] ?? 'home')) ?: 'home';
$self = fn(string $p, array $x = []) => 'index.php?' . http_build_query(['p' => $p] + $x);
$o = ['root' => '../', 'home' => 'index.php', 'body' => 'admin'];

if (!$A) { // ---------------- login ----------------
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        try { check_csrf((string)($_POST['csrf'] ?? '')); $a = pa_login((string)$_POST['email'], (string)$_POST['password'], (string)($_POST['code'] ?? ''));
            session_regenerate_id(true); $_SESSION = ['pa_id' => (int)$a['id'], 'pa_rev' => (int)$a['session_rev'], 'pa_exp' => time() + 1800, 'pa_hard' => time() + 28800, 'csrf' => bin2hex(random_bytes(24)), 'pa_mfa_setup' => !empty($a['_mfa_setup_needed'])];
            header('Location: ' . (!empty($a['_mfa_setup_needed']) ? 'index.php?p=mfa' : 'index.php'), true, 303); exit;
        } catch (Throwable $e) { $_SESSION['flash'][] = ['err', $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Login failed.']; header('Location: index.php', true, 303); exit; }
    }
    ui_head('Platform admin', $o + ['narrow' => true]);
    echo '<h1>Platform administration</h1>' . ui_card('', '<form method="post">' . ui_csrf() . ui_field('email', 'Email', ['type' => 'email', 'required' => true, 'autocomplete' => 'username']) . ui_field('password', 'Password', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']) . ui_field('code', 'Authenticator code (6 digits)', ['inputmode' => 'numeric', 'pattern' => '[0-9]{6}', 'maxlength' => 6, 'autocomplete' => 'one-time-code']) . ui_btn('Sign in', 'dark full') . '</form>');
    ui_foot(); exit;
}
if (!empty($_SESSION['pa_mfa_setup']) && $pg !== 'mfa' && $pg !== 'logout') { header('Location: index.php?p=mfa'); exit; }

ui_post(function () use ($A, $self, $pg) {
    $a = (string)($_POST['a'] ?? ''); $tid = (int)($_POST['tenant'] ?? 0);
    switch ($a) {
    case 'logout': $_SESSION = []; session_destroy(); return 'index.php';
    case 'mfa_confirm':
        $sec = (string)($_SESSION['pa_new_secret'] ?? ''); $step = $sec ? p_totp_check($sec, (string)$_POST['code'], 0) : null; if ($step === null) fail('Code did not match. Check the phone clock and try again.');
        prun('UPDATE p_admins SET totp_secret_enc=?,totp_enabled=1,totp_last_step=?,session_rev=session_rev+1 WHERE id=?', p_enc($sec), $step, $A['id']); p_audit('admin', (int)$A['id'], null, 'admin.mfa_enabled');
        $_SESSION = []; session_destroy(); session_start(); $_SESSION['csrf'] = bin2hex(random_bytes(24)); ui_flash('ok', 'Two-step sign-in is on. Sign in again with your code.'); return 'index.php';
    case 'extend': pa_need($A, 'trial'); billing_admin_extend($tid, (int)$_POST['days'], (int)$A['id'], (string)$_POST['reason']); ui_flash('ok', 'Access extended.'); return $self('tenant', ['id' => $tid]);
    case 'suspend': pa_need($A, 'billing'); billing_set_suspended($tid, ($_POST['to'] ?? '') === 'suspend', (int)$A['id'], (string)$_POST['reason']);
        prun('UPDATE p_tenants SET status=?,status_reason=? WHERE id=? AND status IN (\'active\',\'suspended\')', ($_POST['to'] ?? '') === 'suspend' ? 'suspended' : 'active', txt($_POST['reason'] ?? '', 300), $tid); billing_cache_clear(); ui_flash('ok', 'Saved.'); return $self('tenant', ['id' => $tid]);
    case 'offline_pay': pa_need($A, 'billing'); $r = billing_admin_offline_payment($tid, (int)$_POST['plan_version_id'], (string)$_POST['reference'], (int)$A['id']); ui_flash('ok', $r['credited'] ? 'Payment recorded; access until ' . p_ist($r['ends_at']) : 'Already recorded (no double credit).'); return $self('tenant', ['id' => $tid]);
    case 'support_request': pa_need($A, 'support');
        $b = prow('SELECT * FROM p_branches WHERE id=? AND tenant_id=?', (int)$_POST['branch'], $tid) ?? fail('Branch not found.', 404);
        $why = txt($_POST['reason'] ?? '', 500); if (mb_strlen($why) < 15) fail('Explain the reason (at least 15 characters). The owner will see it.');
        $min = max(15, min((int)p_setting('support_max_minutes'), (int)$_POST['minutes']));
        prun('INSERT INTO p_support_grants(tenant_id,branch_id,admin_id,reason,minutes) VALUES(?,?,?,?,?)', $tid, $b['id'], $A['id'], $why, $min);
        $owner = prow('SELECT u.email FROM p_tenants t JOIN p_users u ON u.id=t.owner_user_id WHERE t.id=?', $tid);
        p_queue('support_req', 'supreq:' . pid(), 'email', $owner['email'], 'Finance 360 support asks for read-only access', '<p>Reason: ' . pe($why) . '</p><p>Approve or deny in Owner portal → Support access. Nothing is visible to support unless you approve.</p>', $tid);
        p_audit('admin', (int)$A['id'], $tid, 'support.request', ['branch' => (int)$b['id'], 'minutes' => $min]); ui_flash('ok', 'Request sent to the owner.'); return $self('tenant', ['id' => $tid]);
    case 'support_enter': pa_need($A, 'support');
        $g = prow("SELECT * FROM p_support_grants WHERE id=? AND admin_id=? AND status='approved' AND expires_at>UTC_TIMESTAMP()", (int)$_POST['grant'], $A['id']) ?? fail('No active approved access.', 403);
        $tok = p_token_issue(null, 'support_entry', 120, ['grant' => (int)$g['id'], 'admin' => (int)$A['id']]); p_audit('admin', (int)$A['id'], (int)$g['tenant_id'], 'support.enter', ['grant' => (int)$g['id']]);
        return '../support.php?token=' . urlencode($tok);
    case 'plan_version': pa_need($A, 'billing'); $mods = []; foreach (P_ALL_MODULES as $k => $_) $mods[$k] = !empty($_POST['mod_' . $k]);
        billing_plan_version_save((int)$_POST['plan'], (string)$_POST['term'], ['price_paise' => (int)round((float)$_POST['price'] * 100), 'max_branches' => $_POST['max_branches'], 'max_users' => $_POST['max_users'], 'grace_days' => $_POST['grace_days'], 'modules' => $mods], (int)$A['id']);
        ui_flash('ok', 'New plan version saved. Existing subscriptions keep their version.'); return $self('plans');
    case 'settings': pa_need($A, '*');
        foreach (['trial_days' => [1, 90], 'grace_days' => [0, 30], 'support_max_minutes' => [15, 480]] as $k => [$lo, $hi]) p_setting_set($k, (string)max($lo, min($hi, (int)$_POST[$k])));
        p_setting_set('signup_enabled', !empty($_POST['signup_enabled']) ? '1' : '0'); p_setting_set('readonly_on_expiry', !empty($_POST['readonly_on_expiry']) ? '1' : '0');
        foreach (['billing_legal_name', 'billing_gstin', 'billing_address'] as $k) p_setting_set($k, txt($_POST[$k] ?? '', 300));
        p_audit('admin', (int)$A['id'], null, 'settings.save', array_intersect_key($_POST, P_SETTING_DEFAULTS)); ui_flash('ok', 'Settings saved.'); return $self('settings');
    case 'data_decide': pa_need($A, '*'); // V31: export / deletion requests (no instant erasure; deletion = suspend + retention date)
        $r = prow("SELECT * FROM p_data_requests WHERE id=? AND status='requested'", (int)$_POST['id']) ?? fail('Request not found or already decided.', 404);
        $to = ($_POST['to'] ?? '') === 'approve' ? 'approved' : 'rejected'; $note = txt($_POST['note'] ?? '', 500); if ($note === '') fail('A note is required.');
        $retain = null; if ($r['kind'] === 'deletion' && $to === 'approved') { $retain = valid_date($_POST['retain_until'] ?? ''); prun("UPDATE p_tenants SET status='suspended' WHERE id=?", $r['tenant_id']); }
        prun('UPDATE p_data_requests SET status=?,decided_by_admin=?,decided_at=UTC_TIMESTAMP(),retain_until=?,note=? WHERE id=?', $to, (int)$A['id'], $retain, $note, $r['id']);
        p_audit('admin', (int)$A['id'], (int)$r['tenant_id'], 'data.' . $r['kind'] . '_' . $to, ['request' => (int)$r['id'], 'retain_until' => $retain]); ui_flash('ok', 'Decision saved.'); return $self('privacy');
    case 'notice': pa_need($A, '*'); $t = txt($_POST['title'] ?? '', 160); if ($t === '') fail('Title required.'); prun('INSERT INTO p_notices(title,body,admin_id) VALUES(?,?,?)', $t, txt($_POST['body'] ?? '', 1000), $A['id']); ui_flash('ok', 'Notice published to owner portals.'); return $self('notices');
    case 'notice_off': pa_need($A, '*'); prun('UPDATE p_notices SET active=0 WHERE id=?', (int)$_POST['id']); return $self('notices');
    case 'admin_add': pa_need($A, '*'); pa_create((string)$_POST['email'], (string)$_POST['name'], (string)$_POST['password'], (string)$_POST['role']); p_audit('admin', (int)$A['id'], null, 'admin.create', ['email' => $_POST['email'], 'role' => $_POST['role']]); ui_flash('ok', 'Admin created. They must set up two-step sign-in at first login.'); return $self('admins');
    case 'admin_status': pa_need($A, '*'); if ((int)$_POST['id'] === (int)$A['id']) fail('You cannot disable yourself.'); prun("UPDATE p_admins SET status=IF(status='active','disabled','active'),session_rev=session_rev+1 WHERE id=?", (int)$_POST['id']); p_audit('admin', (int)$A['id'], null, 'admin.toggle', ['id' => (int)$_POST['id']]); return $self('admins');
    }
    fail('Unknown action.');
}, $self($pg, isset($_GET['id']) ? ['id' => (int)$_GET['id']] : []));

$nav = ''; foreach (['home' => 'Overview', 'tenants' => 'Restaurants', 'plans' => 'Plans', 'payments' => 'Payments', 'support' => 'Support', 'privacy' => 'Data requests', 'system' => 'System', 'notices' => 'Notices', 'admins' => 'Admins', 'settings' => 'Settings', 'audit' => 'Audit'] as $k => $l) if (pa_can($A, 'view') || in_array($k, ['home'], true)) $nav .= '<a class="' . ($pg === $k ? 'on' : '') . '" href="' . pe($self($k)) . '">' . $l . '</a>';
$nav .= '<form method="post" style="display:inline">' . ui_csrf() . '<input type="hidden" name="a" value="logout"><button class="btn sm ghost">Sign out</button></form>';
ui_head('Platform admin', $o + ['nav' => $nav]);
echo '<p class="muted">Signed in as ' . pe($A['name']) . ' · ' . pe(str_replace('_', ' ', $A['role'])) . ($A['totp_enabled'] ? '' : ' · <b>two-step sign-in not set up</b>') . '</p>';
$terms = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Six-monthly', 'yearly' => 'Yearly'];

switch ($pg) {
case 'mfa':
    if (empty($_SESSION['pa_new_secret'])) $_SESSION['pa_new_secret'] = p_b32_encode(random_bytes(20));
    $sec = $_SESSION['pa_new_secret']; $uri = 'otpauth://totp/' . rawurlencode('Finance360:' . $A['email']) . '?secret=' . $sec . '&issuer=Finance360&digits=6&period=30';
    echo '<h1>Set up two-step sign-in</h1>' . ui_card('', '<p>Add this key to an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password …): choose “Enter a setup key”.</p><p><code style="font-size:18px;letter-spacing:2px">' . pe(trim(chunk_split($sec, 4, ' '))) . '</code></p><p class="muted">Or paste this link into an app that accepts otpauth links: <code>' . pe($uri) . '</code></p><form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="mfa_confirm">' . ui_field('code', 'Enter the 6-digit code shown by the app', ['inputmode' => 'numeric', 'pattern' => '[0-9]{6}', 'required' => true]) . ui_btn('Turn on', 'dark') . '</form>');
    break;
case 'home':
    $c = fn(string $sql, ...$a) => (int)pval($sql, ...$a);
    $states = ['trialing' => 0, 'active' => 0, 'grace' => 0, 'expired' => 0, 'cancelled' => 0, 'suspended' => 0, 'none' => 0]; $mrr = 0; $soon = 0;
    foreach (prows("SELECT id FROM p_tenants WHERE status IN ('active','suspended')") as $t) { $e = billing_entitlement((int)$t['id']); $states[$e['state']]++; if ($e['state'] === 'trialing' && $e['days_left'] <= 7) $soon++;
        if ($e['state'] === 'active' && $e['kind'] === 'paid' && $e['plan_version_id']) { $pv = prow('SELECT price_paise,months FROM p_plan_versions WHERE id=?', $e['plan_version_id']); $mrr += intdiv((int)$pv['price_paise'], max(1, (int)$pv['months'])); } }
    echo '<h1>Overview</h1><div class="grid g4">' . ui_card('', '<div class="kpi">' . $c("SELECT COUNT(*) FROM p_tenants WHERE status='active'") . '<small>Active restaurants</small></div>') . ui_card('', '<div class="kpi">' . $states['trialing'] . '<small>In trial (' . $soon . ' end ≤7 days)</small></div>')
       . ui_card('', '<div class="kpi">' . $states['active'] . '<small>Active (paid / complimentary)</small></div>') . ui_card('', '<div class="kpi">' . ($states['expired'] + $states['cancelled']) . '<small>Expired / cancelled (read-only)</small></div>')
       . ui_card('', '<div class="kpi">' . $states['grace'] . '<small>Payment due (grace)</small></div>') . ui_card('', '<div class="kpi">' . $states['suspended'] . '<small>Suspended</small></div>')
       . ui_card('', '<div class="kpi">' . pe(p_rs($mrr)) . '<small>MRR estimate (price ÷ term months, active paid). Not recognised revenue.</small></div>') . ui_card('', '<div class="kpi">' . pe(p_rs((int)pval("SELECT COALESCE(SUM(amount_paise),0) FROM p_orders WHERE status='paid' AND paid_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)"))) . '<small>Payments received, last 30 days</small></div>') . '</div>';
    $al = []; $we = $c("SELECT COUNT(*) FROM p_webhook_events WHERE status IN ('error','rejected') AND received_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)"); if ($we) $al[] = $we . ' webhook event(s) rejected/errored in 7 days';
    $rf = $c("SELECT COUNT(*) FROM p_registrations WHERE status='failed'"); if ($rf) $al[] = $rf . ' registration(s) failed to provision';
    $of = $c("SELECT COUNT(*) FROM p_outbox WHERE status='failed'"); if ($of) $al[] = $of . ' email(s) failed';
    
    $rv = $c("SELECT COUNT(*) FROM p_orders WHERE status='review'"); if ($rv) $al[] = $rv . ' order(s) need billing review (mismatch / dispute)';
    $lj = pval("SELECT MAX(started_at) FROM p_job_runs WHERE job='platform' AND status='ok'"); if (!$lj || p_ts($lj) < time() - 3600) $al[] = 'Platform cron has not run successfully in the last hour';
    echo ui_card('Alerts', $al ? '<ul><li>' . implode('</li><li>', array_map('pe', $al)) . '</li></ul>' : '<p class="muted">No alerts.</p>');
    break;
case 'tenants':
    $q = txt($_GET['q'] ?? '', 80); $rows = [];
    foreach (prows("SELECT t.*,u.email owner_email,(SELECT COUNT(*) FROM p_branches b WHERE b.tenant_id=t.id AND b.status='active') nb,(SELECT COUNT(*) FROM p_tenant_members m WHERE m.tenant_id=t.id AND m.status='active') nu FROM p_tenants t JOIN p_users u ON u.id=t.owner_user_id WHERE (?='' OR t.name LIKE CONCAT('%',?,'%') OR u.email LIKE CONCAT('%',?,'%') OR t.city LIKE CONCAT('%',?,'%')) ORDER BY t.id DESC LIMIT 200", $q, $q, $q, $q) as $t) {
        $e = billing_entitlement((int)$t['id']);
        $rows[] = ['<a href="' . pe($self('tenant', ['id' => $t['id']])) . '">' . pe($t['name']) . '</a><br><span class="muted">' . pe($t['city'] ?? '') . '</span>', pe($t['owner_email']), ui_badge($t['status']), ui_badge($e['state']) . '<br><span class="muted">' . pe(p_ist($e['period_end'], 'j M Y')) . '</span>', (int)$t['nb'] . ' / ' . (int)$t['nu'], pe(p_ist($t['created_at'], 'j M Y'))];
    }
    echo '<h1>Restaurants</h1><form class="row" method="get"><input type="hidden" name="p" value="tenants"><input name="q" value="' . pe($q) . '" placeholder="Name, owner email or city" style="max-width:340px">' . ui_btn('Search', 'ghost sm') . '</form><br>' . ui_card('', ui_table(['Restaurant', 'Owner', 'Account', 'Subscription', 'Branches / users', 'Registered'], $rows) . '<p class="muted">Only account and subscription metadata is shown. Restaurant financial records are not visible here.</p>');
    break;
case 'tenant':
    $t = prow('SELECT t.*,u.email owner_email,u.name owner_name,u.mobile,u.email_verified_at FROM p_tenants t JOIN p_users u ON u.id=t.owner_user_id WHERE t.id=?', (int)($_GET['id'] ?? 0)); if (!$t) { echo '<p>Not found.</p>'; break; }
    $e = billing_entitlement((int)$t['id']); $br = prows('SELECT id,name,city,status,created_at FROM p_branches WHERE tenant_id=?', $t['id']);
    echo '<h1>' . pe($t['name']) . '</h1><div class="grid g2">' . ui_card('Account', '<p>Owner: ' . pe($t['owner_name']) . ' · ' . pe($t['owner_email']) . ($t['email_verified_at'] ? ' ' . ui_badge('ok', 'verified') : '') . '<br>Mobile: ' . pe($t['mobile'] ?? '—') . ' (not OTP-verified)<br>Status: ' . ui_badge($t['status']) . ($t['status_reason'] ? ' — ' . pe($t['status_reason']) : '') . '<br>Registered: ' . pe(p_ist($t['created_at'])) . '</p>'
        . ui_table(['Branch', 'City', 'Status'], array_map(fn($b) => [pe($b['name']), pe($b['city'] ?? ''), ui_badge($b['status'])], $br)))
       . ui_card('Subscription', '<p>' . ui_badge($e['state']) . ' ' . pe($e['plan'] ?? '') . ' · ' . pe($e['kind'] ?? '') . '<br>Until: ' . pe(p_ist($e['period_end'])) . '</p>' . ui_table(['Source', 'From', 'To', 'Reason'], array_map(fn($p) => [pe($p['source']), pe(p_ist($p['starts_at'], 'j M Y')), pe(p_ist($p['ends_at'], 'j M Y')) . ($p['revoked_at'] ? ' (revoked)' : ''), pe($p['reason'] ?? '')], prows('SELECT * FROM p_sub_periods WHERE tenant_id=? ORDER BY starts_at DESC LIMIT 20', $t['id'])))) . '</div>';
    if (pa_can($A, 'trial')) echo ui_card('Extend trial / access', '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="extend"><input type="hidden" name="tenant" value="' . $t['id'] . '"><div class="grid g2">' . ui_field('days', 'Days', ['type' => 'number', 'min' => 1, 'max' => 400, 'value' => '7', 'required' => true]) . ui_field('reason', 'Reason (logged)', ['required' => true, 'minlength' => 5]) . '</div>' . ui_btn('Extend', 'dark sm') . '</form>');
    if (pa_can($A, 'billing')) {
        $pvs = []; foreach (prows("SELECT v.id,p.name,v.term,v.price_paise FROM p_plan_versions v JOIN p_plans p ON p.id=v.plan_id WHERE v.active=1 ORDER BY p.sort,v.months") as $v) $pvs[$v['id']] = $v['name'] . ' · ' . ($terms[$v['term']] ?? $v['term']) . ' · ' . p_rs((int)$v['price_paise']);
        echo ui_card('Record offline payment (bank transfer / cash)', '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="offline_pay"><input type="hidden" name="tenant" value="' . $t['id'] . '"><div class="grid g2">' . ui_field('plan_version_id', 'Plan', ['type' => 'select', 'options' => $pvs]) . ui_field('reference', 'Bank / UTR reference (unique)', ['required' => true, 'minlength' => 4]) . '</div>' . ui_btn('Record payment', 'dark sm') . '</form><p class="muted">The same reference can never be credited twice.</p>');
        echo ui_card($t['status'] === 'suspended' ? 'Reactivate' : 'Suspend', '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="suspend"><input type="hidden" name="tenant" value="' . $t['id'] . '"><input type="hidden" name="to" value="' . ($t['status'] === 'suspended' ? 'reactivate' : 'suspend') . '">' . ui_field('reason', 'Reason (shown to the owner, logged)', ['required' => true, 'minlength' => 5]) . ui_btn($t['status'] === 'suspended' ? 'Reactivate' : 'Suspend (owner keeps read-only access)', $t['status'] === 'suspended' ? 'dark sm' : 'danger sm') . '</form>');
    }
    if (pa_can($A, 'support')) {
        $bo = []; foreach ($br as $b) if ($b['status'] === 'active') $bo[$b['id']] = $b['name'];
        $gr = prows('SELECT * FROM p_support_grants WHERE tenant_id=? AND admin_id=? ORDER BY id DESC LIMIT 10', $t['id'], $A['id']);
        echo ui_card('Support access (needs the owner’s approval)', ui_table(['Requested', 'Status', 'Until', ''], array_map(fn($g) => [pe(p_ist($g['requested_at'])), ui_badge($g['status']), pe(p_ist($g['expires_at'])), $g['status'] === 'approved' && p_ts((string)$g['expires_at']) > time() ? '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="support_enter"><input type="hidden" name="grant" value="' . $g['id'] . '">' . ui_btn('Open read-only view', 'dark sm') . '</form>' : ''], $gr), 'No requests.')
            . '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="support_request"><input type="hidden" name="tenant" value="' . $t['id'] . '"><div class="grid g2">' . ui_field('branch', 'Branch', ['type' => 'select', 'options' => $bo]) . ui_field('minutes', 'Minutes', ['type' => 'number', 'min' => 15, 'max' => (int)p_setting('support_max_minutes'), 'value' => '60']) . '</div>' . ui_field('reason', 'Reason (the owner sees this)', ['type' => 'textarea', 'required' => true, 'minlength' => 15]) . ui_btn('Request access', 'ghost sm') . '</form>');
    }
    break;
case 'plans':
    $rows = []; foreach (prows('SELECT v.*,p.name,p.code FROM p_plan_versions v JOIN p_plans p ON p.id=v.plan_id ORDER BY p.sort,v.months,v.version DESC') as $v) $rows[] = [pe($v['name']), pe($terms[$v['term']] ?? $v['term']), 'v' . (int)$v['version'], pe(p_rs((int)$v['price_paise'])), (int)$v['max_branches'] . ' / ' . (int)$v['max_users'], pe(implode(', ', array_keys(array_filter(json_decode((string)$v['modules_json'], true) ?: []))) ), $v['active'] ? ui_badge('ok', 'current') : ui_badge('bad', 'old')];
    echo '<h1>Plans</h1>' . ui_card('', ui_table(['Plan', 'Term', 'Version', 'Price', 'Branches / users', 'Modules', ''], $rows) . '<p class="muted">Seeded versions have price ₹0 and are inactive: nobody can buy until you publish a price. A price change creates a new version.</p>');
    if (pa_can($A, 'billing')) { $mods = ''; foreach (P_ALL_MODULES as $k => $_) $mods .= '<label class="chk"><input type="checkbox" name="mod_' . $k . '" value="1" checked> ' . pe($k) . '</label>';
        echo ui_card('Publish a price (new version)', '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="plan_version"><div class="grid g3">' . ui_field('plan', 'Plan', ['type' => 'select', 'options' => array_column(prows('SELECT id,name FROM p_plans WHERE active=1 ORDER BY sort'), 'name', 'id')]) . ui_field('term', 'Term', ['type' => 'select', 'options' => $terms]) . ui_field('price', 'Price in ₹ (for the whole term)', ['type' => 'number', 'min' => 1, 'step' => '0.01', 'required' => true])
            . ui_field('max_branches', 'Max branches', ['type' => 'number', 'min' => 1, 'value' => '3']) . ui_field('max_users', 'Max users', ['type' => 'number', 'min' => 1, 'value' => '15']) . ui_field('grace_days', 'Grace days after expiry', ['type' => 'number', 'min' => 0, 'max' => 30, 'value' => '3']) . '</div><fieldset><legend>Modules</legend>' . $mods . '</fieldset>' . ui_btn('Publish version', 'dark') . '</form>'); }
    break;
case 'payments':
    pa_need($A, 'view');
    echo '<h1>Payments</h1><p class="muted">Gateway: <b>' . pe(billing_gw()['mode']) . '</b>' . (($w = billing_gw_ready()) ? ' — ' . pe($w) : ' — ready') . '</p>';
    echo ui_card('Orders', ui_table(['Created', 'Restaurant', 'Order', 'Amount', 'Status', 'Receipt', 'Note'], array_map(fn($o) => [pe(p_ist($o['created_at'], 'j M, g:i A')), pe($o['tname']), pe($o['uid']) . '<br><span class="muted">' . pe($o['provider'] . ' ' . ($o['provider_order_id'] ?? '')) . '</span>', pe(p_rs((int)$o['amount_paise'])), ui_badge($o['status']), pe($o['receipt_no'] ?? ''), pe($o['note'] ?? '')], prows('SELECT o.*,t.name tname FROM p_orders o JOIN p_tenants t ON t.id=o.tenant_id ORDER BY o.id DESC LIMIT 100'))));
    echo ui_card('Webhook events', ui_table(['Received', 'Event', 'Type', 'Signature', 'Status', 'Message'], array_map(fn($w) => [pe(p_ist($w['received_at'], 'j M, g:i A')), pe(mb_substr($w['event_id'], 0, 24)), pe($w['event_type'] ?? ''), $w['signature_ok'] ? ui_badge('ok', 'valid') : ui_badge('bad', 'invalid'), ui_badge($w['status']), pe($w['message'] ?? '')], prows('SELECT * FROM p_webhook_events ORDER BY id DESC LIMIT 100'))));
    break;
case 'support':
    echo '<h1>Support access</h1>' . ui_card('', ui_table(['Requested', 'Restaurant', 'Admin', 'Status', 'Until', 'Reason'], array_map(fn($g) => [pe(p_ist($g['requested_at'])), pe($g['tname']), pe($g['aname']), ui_badge($g['status']), pe(p_ist($g['expires_at'])), pe($g['reason'])], prows('SELECT g.*,t.name tname,a.name aname FROM p_support_grants g JOIN p_tenants t ON t.id=g.tenant_id JOIN p_admins a ON a.id=g.admin_id ORDER BY g.id DESC LIMIT 100'))));
    break;
case 'system':
    echo '<h1>System</h1><div class="grid g2">' . ui_card('Platform', '<p>Schema v' . p_schema_version() . ' · PHP ' . PHP_VERSION . ' · DB ' . pe((string)pval('SELECT VERSION()')) . '<br>Provisioning: <b>single database</b> · Mail: <b>' . pe((string)(pcfg()['mail']['mode'] ?? 'log')) . '</b> · Sign-up: <b>' . (p_signup_open() ? 'open' : 'closed') . '</b></p>')
       . ui_card('Database', '<p>One shared database for every restaurant (V31). Isolation: tenant_id + branch_id on every finance row, enforced by scoped views. Scoped tables: <b>' . (int)pval("SELECT COUNT(*) FROM mt_tables") . '</b> · open connections with a scope: <b>' . (int)pval('SELECT COUNT(*) FROM mt_ctx WHERE set_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR') . '</b></p>') . '</div>';
    echo ui_card('Jobs (last 30)', ui_table(['Job', 'Started', 'Status', 'Message'], array_map(fn($j) => [pe($j['job']), pe(p_ist($j['started_at'], 'j M, g:i A')), ui_badge($j['status'] === 'ok' ? 'ok' : 'bad', $j['status']), pe($j['message'] ?? '')], prows('SELECT * FROM p_job_runs ORDER BY id DESC LIMIT 30'))));
    echo ui_card('Email outbox', ui_table(['Status', 'Count'], array_map(fn($r) => [ui_badge($r['status']), (int)$r['n']], prows('SELECT status,COUNT(*) n FROM p_outbox GROUP BY status'))));
    break;
case 'privacy':
    $rows = array_map(fn($r) => [pe(p_ist($r['created_at'])), pe($r['tname']), pe($r['kind']), ui_badge($r['status']), pe((string)$r['reason']), pe((string)($r['retain_until'] ?? '')),
        $r['status'] === 'requested' && pa_can($A, '*') ? '<form method="post" class="row">' . ui_csrf() . '<input type="hidden" name="a" value="data_decide"><input type="hidden" name="id" value="' . (int)$r['id'] . '">' . ui_field('note', 'Note', ['required' => true]) . ($r['kind'] === 'deletion' ? ui_field('retain_until', 'Keep records until (YYYY-MM-DD)', ['required' => true]) : '') . ui_btn('Approve', 'primary sm', 'to', 'approve') . ui_btn('Reject', 'ghost sm', 'to', 'reject') . '</form>' : ''],
        prows('SELECT r.*,t.name tname FROM p_data_requests r JOIN p_tenants t ON t.id=r.tenant_id ORDER BY r.id DESC LIMIT 100'));
    echo '<h1>Data requests</h1>' . ui_card('', '<p class="muted">Approving a deletion suspends the restaurant immediately (no one can sign in to it) and records the date until which posted accounting / salary records must be kept. Physical erasure after that date is a manual, audited operation — never automatic. Exports are prepared per branch (each branch backup contains only that branch).</p>' . ui_table(['Requested', 'Restaurant', 'Type', 'Status', 'Reason', 'Keep until', ''], $rows, 'No requests.'));
    break;
case 'notices':
    echo '<h1>Notices</h1>' . ui_card('Publish to all owner portals', '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="notice">' . ui_field('title', 'Title', ['required' => true, 'maxlength' => 160]) . ui_field('body', 'Message', ['type' => 'textarea', 'maxlength' => 1000]) . ui_btn('Publish', 'dark sm') . '</form><p class="muted">Shown inside the owner portal only. No email or WhatsApp is sent.</p>');
    echo ui_card('Published', ui_table(['When', 'Title', ''], array_map(fn($n) => [pe(p_ist($n['created_at'])), pe($n['title']), $n['active'] ? '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="notice_off"><input type="hidden" name="id" value="' . $n['id'] . '">' . ui_btn('Hide', 'ghost sm') . '</form>' : 'hidden'], prows('SELECT * FROM p_notices ORDER BY id DESC LIMIT 50'))));
    break;
case 'admins':
    pa_need($A, '*');
    echo '<h1>Platform admins</h1>' . ui_card('', ui_table(['Name', 'Email', 'Role', '2-step', 'Status', ''], array_map(fn($a) => [pe($a['name']), pe($a['email']), pe($a['role']), $a['totp_enabled'] ? ui_badge('ok', 'on') : ui_badge('bad', 'off'), ui_badge($a['status']), '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="admin_status"><input type="hidden" name="id" value="' . $a['id'] . '">' . ui_btn($a['status'] === 'active' ? 'Disable' : 'Enable', 'ghost sm') . '</form>'], prows('SELECT * FROM p_admins ORDER BY id'))));
    echo ui_card('Add admin', '<form method="post" autocomplete="off">' . ui_csrf() . '<input type="hidden" name="a" value="admin_add"><div class="grid g2">' . ui_field('name', 'Name', ['required' => true]) . ui_field('email', 'Email', ['type' => 'email', 'required' => true]) . ui_field('password', 'Temporary password (10+)', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password']) . ui_field('role', 'Role', ['type' => 'select', 'options' => ['support' => 'Support', 'billing_admin' => 'Billing admin', 'super_admin' => 'Super admin']]) . '</div>' . ui_btn('Create', 'dark sm') . '</form>');
    break;
case 'settings':
    pa_need($A, '*');
    echo '<h1>Settings</h1>' . ui_card('', '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="settings"><div class="grid g3">' . ui_field('trial_days', 'Free trial days (new restaurants)', ['type' => 'number', 'min' => 1, 'max' => 90, 'value' => p_setting('trial_days')]) . ui_field('grace_days', 'Default grace days', ['type' => 'number', 'min' => 0, 'max' => 30, 'value' => p_setting('grace_days')]) . ui_field('support_max_minutes', 'Max support access minutes', ['type' => 'number', 'min' => 15, 'max' => 480, 'value' => p_setting('support_max_minutes')]) . '</div>'
       . ui_field('readonly_on_expiry', '', ['type' => 'checkbox', 'value' => p_setting('readonly_on_expiry') === '1', 'html' => 'Expired restaurants become read-only (recommended; nothing is deleted)']) . ui_field('signup_enabled', '', ['type' => 'checkbox', 'value' => p_setting('signup_enabled') === '1', 'html' => 'Public restaurant registration is OPEN (also needs <code>platform.signup_enabled</code> in config.php; enable only after the isolation tests pass on staging)'])
       . '<fieldset><legend>Seller details printed on subscription receipts</legend>' . ui_field('billing_legal_name', 'Legal name', ['value' => p_setting('billing_legal_name')]) . ui_field('billing_gstin', 'GSTIN (if registered)', ['value' => p_setting('billing_gstin')]) . ui_field('billing_address', 'Address', ['value' => p_setting('billing_address')]) . '<p class="muted">Tax treatment of subscriptions must be confirmed with your accountant before live billing.</p></fieldset>' . ui_btn('Save', 'dark') . '</form>');
    break;
case 'audit':
    echo '<h1>Audit</h1>' . ui_card('', ui_table(['When', 'Actor', 'Restaurant', 'Action', 'Detail'], array_map(fn($a) => [pe(p_ist($a['at'], 'j M, g:i A')), pe($a['actor_type'] . ($a['actor_id'] ? ' #' . $a['actor_id'] : '')), pe((string)($a['tenant_id'] ?? '')), pe($a['action']), '<code>' . pe(mb_substr((string)$a['detail'], 0, 140)) . '</code>'], prows('SELECT * FROM p_audit ORDER BY id DESC LIMIT 300'))));
    break;
default: echo '<p>Not found.</p>';
}
ui_foot();
