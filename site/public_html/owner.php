<?php
declare(strict_types=1);
// Finance 360 V29 — Owner portal (restaurant owner / tenant admin). Every action re-checks the caller's role for THIS
// tenant on the server; tenant ids in the URL are only a selection among the caller's own memberships.
require __DIR__ . '/platform/ui.php';
require_once __DIR__ . '/f360/core.php';
ui_boot();
$me = p_web_user(); if (!$me) { header('Location: signin.php'); exit; }
$owned = array_values(array_filter(tenancy_memberships((int)$me['id']), fn($t) => in_array($t['role'], ['owner', 'admin'], true)));
if (!$owned) { ui_flash('err', 'The owner portal is for restaurant owners.'); header('Location: choose.php'); exit; }
$tid = (int)($_GET['t'] ?? $_SESSION['p_tenant'] ?? $owned[0]['id']);
$T = null; foreach ($owned as $o) if ($o['id'] === $tid) $T = $o; if (!$T) { $T = $owned[0]; $tid = $T['id']; }
$role = tenancy_member_role((int)$me['id'], $tid); if (!in_array($role, ['owner', 'admin'], true)) { http_response_code(403); exit('Forbidden'); }
$tenant = prow('SELECT * FROM p_tenants WHERE id=?', $tid);
$pg = preg_replace('/[^a-z]/', '', (string)($_GET['p'] ?? 'home')) ?: 'home';
$self = fn(string $p, array $x = []) => 'owner.php?' . http_build_query(['t' => $tid, 'p' => $p] + $x);
const OWNER_TEMPLATES = ['cash_counter' => 'Cash Counter (daily closing only)', 'counter' => 'Counter (closing + expenses)', 'store' => 'Store keeper (purchases & stock)', 'payroll' => 'Salary manager (prepare)', 'payroll_approver' => 'Salary approver', 'accountant' => 'Accountant / Manager', 'viewer' => 'Reports viewer', 'owner' => 'Full access (owner-level)'];

ui_post(function () use ($me, $tid, $tenant, $pg, $self, $role) {
    $a = (string)($_POST['a'] ?? '');
    switch ($a) {
    case 'branch_create': $b = tenancy_branch_create($tid, $_POST, (int)$me['id']); ui_flash('ok', 'Branch "' . $b['name'] . '" is ready.'); return $self('branches');
    case 'branch_finish': $b = prow("SELECT * FROM p_branches WHERE id=? AND tenant_id=? AND status='provisioning'", (int)$_POST['id'], $tid) ?? fail('Branch not found.', 404); tenancy_branch_finish((int)$b['id'], (int)$me['id']); ui_flash('ok', 'Branch is ready.'); return $self('branches');
    case 'branch_edit':
        $b = prow('SELECT * FROM p_branches WHERE id=? AND tenant_id=?', (int)$_POST['id'], $tid) ?? fail('Branch not found.', 404);
        $name = txt($_POST['name'] ?? '', 120); if ($name === '') fail('Name is required.'); $cut = max(0, min(12, (int)($_POST['cutoff_hour'] ?? 4)));
        prun('UPDATE p_branches SET name=?,address=?,city=?,cutoff_hour=? WHERE id=?', $name, txt($_POST['address'] ?? '', 300) ?: null, txt($_POST['city'] ?? '', 80) ?: null, $cut, $b['id']);
        $prev = $GLOBALS['F360_TARGET'] ?? null; db_switch(tenancy_branch_target($b)); try { set_setting('business_day_cutoff_hour', (string)$cut); } finally { db_switch($prev); }
        p_audit('user', (int)$me['id'], $tid, 'branch.edit', ['branch' => (int)$b['id']]); ui_flash('ok', 'Branch saved.'); return $self('branches');
    case 'branch_status':
        $b = prow('SELECT * FROM p_branches WHERE id=? AND tenant_id=?', (int)$_POST['id'], $tid) ?? fail('Branch not found.', 404);
        if ($b['is_primary']) fail('The first branch cannot be disabled.');
        $to = $_POST['to'] === 'active' ? 'active' : 'disabled';
        if ($to === 'active') { $n = (int)pval("SELECT COUNT(*) FROM p_branches WHERE tenant_id=? AND status='active'", $tid); if ($n >= billing_entitlement($tid)['max_branches']) fail('Your plan allows ' . billing_entitlement($tid)['max_branches'] . ' active branch(es).', 402); }
        prun("UPDATE p_branches SET status=? WHERE id=? AND status IN ('active','disabled')", $to, $b['id']); p_audit('user', (int)$me['id'], $tid, 'branch.' . $to, ['branch' => (int)$b['id']]);
        ui_flash('ok', $to === 'active' ? 'Branch enabled.' : 'Branch disabled. Its records are kept; nobody can open it until you enable it again.'); return $self('branches');
    case 'invite':
        $email = p_email($_POST['email'] ?? ''); $name = txt($_POST['name'] ?? '', 160) ?: $email; $tpl = (string)($_POST['template'] ?? '');
        if (!isset(OWNER_TEMPLATES[$tpl])) fail('Choose a role.');
        $bids = array_map('intval', (array)($_POST['branches'] ?? [])); if (!$bids) fail('Choose at least one branch.');
        $ent = billing_entitlement($tid); if ($ent['read_only']) fail('Renew the subscription to invite people.', 402);
        if ((int)pval("SELECT COUNT(*) FROM p_tenant_members WHERE tenant_id=? AND status='active'", $tid) >= $ent['max_users'] && !prow("SELECT 1 FROM p_tenant_members tm JOIN p_users u ON u.id=tm.user_id WHERE tm.tenant_id=? AND u.email=? AND tm.status='active'", $tid, $email)) fail('Your plan allows ' . $ent['max_users'] . ' users.', 402);
        ptx(function () use ($email, $name, $tpl, $bids, $tid, $me, $tenant) {
            $u = prow('SELECT * FROM p_users WHERE email=? FOR UPDATE', $email); $new = false;
            if (!$u) { prun('INSERT INTO p_users(uid,email,name,password_hash) VALUES(?,?,?,?)', p_uid(), $email, $name, password_hash(p_token(), PASSWORD_DEFAULT)); $u = prow('SELECT * FROM p_users WHERE id=?', pid()); $new = true; }
            if ($u['status'] !== 'active') fail('This account is disabled.');
            $tmRole = $tpl === 'owner' ? 'admin' : 'member';
            prun("INSERT INTO p_tenant_members(tenant_id,user_id,role,invited_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE status='active',role=IF(role='owner','owner',VALUES(role))", $tid, $u['id'], $tmRole, $me['id']);
            foreach ($bids as $bid) {
                $b = prow("SELECT * FROM p_branches WHERE id=? AND tenant_id=? AND status='active'", $bid, $tid) ?? fail('Branch not found.', 404);
                $local = prov_local_user($b, $u, $tpl);
                prun("INSERT INTO p_branch_members(tenant_id,branch_id,user_id,local_user_id,template) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE local_user_id=VALUES(local_user_id),template=VALUES(template),status='active'", $tid, $bid, $u['id'], $local, $tpl);
            }
            if ($new || !$u['email_verified_at']) { $tok = p_token_issue((int)$u['id'], 'password_reset', 7 * 86400);
                p_queue('invite', 'invite:' . $tid . ':' . $u['id'] . ':' . substr(p_sha($tok), 0, 10), 'email', $email, 'You are invited to ' . $tenant['name'] . ' on Finance 360', '<p>' . pe($me['name']) . ' invited you to ' . pe($tenant['name']) . '.</p><p>Set your password (link valid 7 days): <a href="' . pe(p_base_url() . '/reset.php?token=' . urlencode($tok)) . '">' . pe(p_base_url() . '/reset.php?token=' . $tok) . '</a></p>', $tid); }
            else p_queue('invite', 'invite:' . $tid . ':' . $u['id'] . ':' . time(), 'email', $email, 'You now have access to ' . $tenant['name'], '<p>Sign in at ' . pe(p_base_url() . '/signin.php') . '.</p>', $tid);
            p_audit('user', (int)$me['id'], $tid, 'member.invite', ['user' => (int)$u['id'], 'template' => $tpl, 'branches' => $bids]);
        });
        ui_flash('ok', 'Invitation sent to ' . $email . '.'); return $self('team');
    case 'member_branch':
        $m = prow("SELECT bm.*,u.email,u.name,u.id uid2 FROM p_branch_members bm JOIN p_users u ON u.id=bm.user_id WHERE bm.id=? AND bm.tenant_id=?", (int)$_POST['id'], $tid) ?? fail('Not found.', 404);
        $tm = prow('SELECT role FROM p_tenant_members WHERE tenant_id=? AND user_id=?', $tid, $m['user_id']); if ($tm['role'] === 'owner') fail('The restaurant owner always has full access.');
        $b = prow('SELECT * FROM p_branches WHERE id=? AND tenant_id=?', (int)$m['branch_id'], $tid);
        if (($_POST['to'] ?? '') === 'revoke') { prun("UPDATE p_branch_members SET status='revoked' WHERE id=?", $m['id']); prov_local_user_disable($b, (int)$m['local_user_id']); p_audit('user', (int)$me['id'], $tid, 'member.branch_revoke', ['user' => (int)$m['user_id'], 'branch' => (int)$b['id']]); ui_flash('ok', 'Access removed for this branch.'); }
        else { $tpl = (string)($_POST['template'] ?? ''); if (!isset(OWNER_TEMPLATES[$tpl])) fail('Choose a role.'); $pu = prow('SELECT * FROM p_users WHERE id=?', $m['user_id']); $local = prov_local_user($b, $pu, $tpl);
            prun("UPDATE p_branch_members SET template=?,local_user_id=?,status='active' WHERE id=?", $tpl, $local, $m['id']); p_audit('user', (int)$me['id'], $tid, 'member.template', ['user' => (int)$m['user_id'], 'branch' => (int)$b['id'], 'template' => $tpl]); ui_flash('ok', 'Role updated. It applies on their next screen or app refresh.'); }
        return $self('team');
    case 'member_remove':
        $uid = (int)$_POST['user']; $tm = prow('SELECT * FROM p_tenant_members WHERE tenant_id=? AND user_id=?', $tid, $uid) ?? fail('Not found.', 404);
        if ($tm['role'] === 'owner') fail('The owner cannot be removed.'); if ($uid === (int)$me['id']) fail('You cannot remove yourself.');
        foreach (prows("SELECT bm.*,b.* ,bm.id bmid FROM p_branch_members bm JOIN p_branches b ON b.id=bm.branch_id WHERE bm.tenant_id=? AND bm.user_id=? AND bm.status='active'", $tid, $uid) as $r) { prov_local_user_disable($r, (int)$r['local_user_id']); }
        prun("UPDATE p_branch_members SET status='revoked' WHERE tenant_id=? AND user_id=?", $tid, $uid); prun("UPDATE p_tenant_members SET status='revoked' WHERE id=?", $tm['id']);
        p_audit('user', (int)$me['id'], $tid, 'member.remove', ['user' => $uid]); ui_flash('ok', 'Removed from the restaurant. Their open screens and app sessions stop working on the next request.'); return $self('team');
    case 'branding':
        $name = txt($_POST['name'] ?? '', 160); if (mb_strlen($name) < 2) fail('Restaurant name is required.');
        $ph = p_mobile($_POST['contact_phone'] ?? '', false); $logo = reg_logo_png(ui_file_b64('logo'));
        prun('UPDATE p_tenants SET name=?,contact_phone=?,address=?,city=?,state=?,pincode=? WHERE id=?', $name, $ph, txt($_POST['address'] ?? '', 300) ?: null, txt($_POST['city'] ?? '', 80) ?: null, txt($_POST['state'] ?? '', 80) ?: null, preg_match('/^[1-9]\d{5}$/', (string)($_POST['pincode'] ?? '')) ? $_POST['pincode'] : null, $tid);
        if ($logo !== null) { file_put_contents(p_private('logos') . '/' . $tenant['uid'] . '.png', $logo); prun('UPDATE p_tenants SET logo_path=? WHERE id=?', 'logos/' . $tenant['uid'] . '.png', $tid); }
        p_audit('user', (int)$me['id'], $tid, 'tenant.branding', ['name' => $name, 'logo' => $logo !== null]); ui_flash('ok', 'Saved.'); return $self('branding');
    case 'checkout':
        $r = billing_create_order($tid, (int)$_POST['plan_version_id'], (int)$me['id']); $_SESSION['checkout'] = $r; return $self('pay');
    case 'cancel':
        if ($role !== 'owner') fail('Only the owner can cancel.', 403);
        prun('UPDATE p_subscriptions SET cancel_at_period_end=? WHERE tenant_id=?', ($_POST['to'] ?? '') === 'resume' ? 0 : 1, $tid); billing_cache_clear();
        p_audit('user', (int)$me['id'], $tid, ($_POST['to'] ?? '') === 'resume' ? 'subscription.resume' : 'subscription.cancel_at_end'); ui_flash('ok', 'Saved.'); return $self('billing');
    case 'support':
        $g = prow('SELECT * FROM p_support_grants WHERE id=? AND tenant_id=?', (int)$_POST['id'], $tid) ?? fail('Not found.', 404);
        $to = (string)($_POST['to'] ?? '');
        if ($to === 'approve' && $g['status'] === 'requested') prun("UPDATE p_support_grants SET status='approved',decided_by=?,decided_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL minutes MINUTE) WHERE id=?", $me['id'], $g['id']);
        elseif ($to === 'deny' && $g['status'] === 'requested') prun("UPDATE p_support_grants SET status='denied',decided_by=?,decided_at=UTC_TIMESTAMP() WHERE id=?", $me['id'], $g['id']);
        elseif ($to === 'revoke' && $g['status'] === 'approved') prun("UPDATE p_support_grants SET status='revoked',decided_by=?,expires_at=UTC_TIMESTAMP() WHERE id=?", $me['id'], $g['id']);
        else fail('This request cannot be changed now.', 409);
        p_audit('user', (int)$me['id'], $tid, 'support.' . $to, ['grant' => (int)$g['id']]); ui_flash('ok', 'Saved.'); return $self('support');
    }
    fail('Unknown action.');
}, $self($pg));

$nav = ''; foreach (['home' => tr('Overview', 'सारांश'), 'branches' => tr('Branches', 'ब्रांच'), 'team' => tr('Team', 'टीम'), 'consolidated' => tr('All branches report', 'सभी ब्रांच रिपोर्ट'), 'billing' => tr('Billing', 'बिलिंग'), 'branding' => tr('Restaurant', 'रेस्टोरेंट'), 'support' => tr('Support access', 'सपोर्ट एक्सेस'), 'audit' => tr('Activity', 'गतिविधि')] as $k => $l) $nav .= '<a class="' . ($pg === $k ? 'on' : '') . '" href="' . pe($self($k)) . '">' . pe($l) . '</a>';
$nav .= '<a href="choose.php?stay=1">' . pe(tr('Open app', 'ऐप खोलें')) . '</a>';
ui_head($tenant['name'], ['nav' => $nav, 'home' => $self('home'), 'brand' => ['name' => $tenant['name'], 'logo' => $tenant['logo_path'] ? 'logo.php?t=' . $tenant['uid'] : 'assets/f360-mark.svg']]);
$ent = billing_entitlement($tid);
if (count($owned) > 1) { echo '<form class="row" method="get"><input type="hidden" name="p" value="' . pe($pg) . '"><select name="t" aria-label="Restaurant">'; foreach ($owned as $o) echo '<option value="' . $o['id'] . '"' . ($o['id'] === $tid ? ' selected' : '') . '>' . pe($o['name']) . '</option>'; echo '</select>' . ui_btn(tr('Switch', 'बदलें'), 'ghost sm') . '</form><br>'; }
if ($ent['read_only']) echo '<div class="banner bad"><b>' . pe($ent['state_label']) . '.</b> ' . pe(tr('Your records are safe. You can view and export everything. Renew to add or change entries.', 'आपका रिकॉर्ड सुरक्षित है। नई एंट्री के लिए रिन्यू करें।')) . ' <a href="' . pe($self('billing')) . '">' . pe(tr('Renew', 'रिन्यू')) . '</a></div>';
elseif ($ent['state'] === 'trialing') echo '<div class="banner">' . pe(tr('Free trial', 'मुफ़्त ट्रायल')) . ': <b>' . $ent['days_left'] . '</b> ' . pe(tr('day(s) left — ends', 'दिन बाक़ी — ख़त्म')) . ' ' . pe(p_ist($ent['period_end'])) . '.</div>';
elseif ($ent['state'] === 'grace') echo '<div class="banner bad">' . pe(tr('Payment due. Full access continues until', 'भुगतान बाक़ी। पूरा एक्सेस तब तक')) . ' ' . pe(p_ist($ent['grace_until'])) . '.</div>';
$nAct = (int)pval("SELECT COUNT(*) FROM p_branches WHERE tenant_id=? AND status='active'", $tid);
if ($nAct > $ent['max_branches']) echo '<div class="banner bad">' . pe(tr("You have $nAct active branches but your plan allows {$ent['max_branches']}. Existing branches keep working and nothing is deleted; new branches cannot be added until you upgrade or disable one.", "आपकी $nAct सक्रिय ब्रांच हैं, प्लान {$ent['max_branches']} की अनुमति देता है।")) . '</div>';
foreach (prows('SELECT title,body FROM p_notices WHERE active=1 ORDER BY id DESC LIMIT 3') as $n) echo '<div class="banner"><b>' . pe($n['title']) . '</b> — ' . pe($n['body']) . '</div>';

$branches = prows('SELECT * FROM p_branches WHERE tenant_id=? ORDER BY is_primary DESC,name', $tid);
switch ($pg) {
case 'home':
    $members = (int)pval("SELECT COUNT(*) FROM p_tenant_members WHERE tenant_id=? AND status='active'", $tid);
    echo '<h1>' . pe(tr('Overview', 'सारांश')) . '</h1><div class="grid g4">' . ui_card('', '<div class="kpi">' . ui_badge($ent['state']) . '<small>' . pe(tr('Subscription', 'सदस्यता')) . '</small></div><p class="muted">' . pe($ent['plan'] ?? '—') . ($ent['period_end'] ? ' · ' . pe(p_ist($ent['period_end'], 'j M Y')) : '') . '</p>')
       . ui_card('', '<div class="kpi">' . count(array_filter($branches, fn($b) => $b['status'] === 'active')) . ' / ' . $ent['max_branches'] . '<small>' . pe(tr('Active branches / plan limit', 'सक्रिय ब्रांच / सीमा')) . '</small></div>')
       . ui_card('', '<div class="kpi">' . $members . ' / ' . $ent['max_users'] . '<small>' . pe(tr('Team members / plan limit', 'टीम / सीमा')) . '</small></div>')
       . ui_card('', '<div class="kpi">' . (int)pval("SELECT COUNT(*) FROM p_support_grants WHERE tenant_id=? AND status='requested'", $tid) . '<small>' . pe(tr('Support requests waiting', 'सपोर्ट अनुरोध')) . '</small></div>') . '</div>';
    $h = '<div class="grid g3">'; foreach ($branches as $b) $h .= '<div class="card"><h2>' . pe($b['name']) . '</h2><p>' . ui_badge($b['status']) . ' <span class="muted">' . pe($b['city'] ?? '') . '</span></p>' . ($b['status'] === 'active' ? '<form method="post" action="choose.php">' . ui_csrf() . '<input type="hidden" name="tenant" value="' . $tid . '"><input type="hidden" name="branch" value="' . $b['id'] . '">' . ui_btn(tr('Open daily books', 'रोज़ का हिसाब खोलें'), 'primary full') . '</form>' : '') . '</div>';
    echo ui_card(tr('Branches', 'ब्रांच'), $h . '</div><p class="muted">' . pe(tr('Sales, closing, expenses, salary, purchases, stock and reports open inside each branch. Use "All branches report" for combined figures.', 'बिक्री, क्लोज़िंग, सैलरी आदि हर ब्रांच के अंदर खुलते हैं।')) . '</p>');
    break;
case 'branches':
    $rows = []; foreach ($branches as $b) $rows[] = [pe($b['name']) . ($b['is_primary'] ? ' <span class="muted">(first)</span>' : ''), ui_badge($b['status']), pe($b['city'] ?? ''), sprintf('%02d:00', $b['cutoff_hour']),
        '<details><summary>' . pe(tr('Edit', 'बदलें')) . '</summary><form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="branch_edit"><input type="hidden" name="id" value="' . $b['id'] . '">' . ui_field('name', 'Name', ['value' => $b['name'], 'required' => true]) . ui_field('address', 'Address', ['value' => $b['address'] ?? '']) . ui_field('city', 'City', ['value' => $b['city'] ?? '']) . ui_field('cutoff_hour', 'Business day ends at', ['type' => 'select', 'value' => $b['cutoff_hour'], 'options' => array_combine(range(0, 12), array_map(fn($h) => sprintf('%02d:00', $h), range(0, 12)))]) . ui_btn('Save', 'primary sm') . '</form>'
        . ($b['status'] === 'provisioning' ? '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="branch_finish"><input type="hidden" name="id" value="' . $b['id'] . '">' . ui_btn('Finish setup', 'primary sm') . '</form>' : (!$b['is_primary'] ? '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="branch_status"><input type="hidden" name="id" value="' . $b['id'] . '"><input type="hidden" name="to" value="' . ($b['status'] === 'active' ? 'disabled' : 'active') . '">' . ui_btn($b['status'] === 'active' ? 'Disable branch' : 'Enable branch', 'ghost sm') . '</form>' : '')) . '</details>'];
    echo '<h1>' . pe(tr('Branches', 'ब्रांच')) . '</h1>' . ui_card('', ui_table(['Branch', 'Status', 'City', 'Day ends', ''], $rows));
    $can = count(array_filter($branches, fn($b) => $b['status'] !== 'disabled')) < $ent['max_branches'] && !$ent['read_only'];
    echo ui_card(tr('Add a branch', 'ब्रांच जोड़ें'), $can ? '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="branch_create">' . ui_field('name', tr('Branch name', 'ब्रांच का नाम'), ['required' => true, 'maxlength' => 120]) . ui_field('address', tr('Address', 'पता'), ['maxlength' => 300]) . ui_field('city', tr('City', 'शहर'), ['maxlength' => 80]) . ui_field('cutoff_hour', tr('Business day ends at', 'बिज़नेस डे ख़त्म'), ['type' => 'select', 'value' => '4', 'options' => array_combine(range(0, 12), array_map(fn($h) => sprintf('%02d:00', $h), range(0, 12)))]) . '<p class="muted">' . pe(tr('The new branch gets its own closing, staff, stock, suppliers and books. You and restaurant admins get full access automatically.', 'नई ब्रांच का अलग हिसाब होगा।')) . '</p>' . ui_btn(tr('Create branch', 'ब्रांच बनाएँ')) . '</form>'
        : '<p class="muted">' . pe($ent['read_only'] ? tr('Renew the subscription to add branches.', 'ब्रांच जोड़ने के लिए रिन्यू करें।') : tr('Your plan allows ', 'आपका प्लान ') . $ent['max_branches'] . tr(' branch(es). Upgrade in Billing to add more.', ' ब्रांच की अनुमति देता है।')) . '</p>');
    break;
case 'team':
    $mem = prows("SELECT u.id,u.name,u.email,u.email_verified_at,tm.role FROM p_tenant_members tm JOIN p_users u ON u.id=tm.user_id WHERE tm.tenant_id=? AND tm.status='active' ORDER BY tm.role='owner' DESC,u.name", $tid);
    $rows = [];
    foreach ($mem as $m) {
        $acc = prows("SELECT bm.id,bm.template,b.name FROM p_branch_members bm JOIN p_branches b ON b.id=bm.branch_id WHERE bm.tenant_id=? AND bm.user_id=? AND bm.status='active' ORDER BY b.name", $tid, $m['id']);
        $a = ''; foreach ($acc as $x) $a .= '<div>' . pe($x['name']) . ': <b>' . pe(OWNER_TEMPLATES[$x['template']] ?? $x['template']) . '</b>' . ($m['role'] !== 'owner' ? '<details><summary class="muted">change</summary><form method="post" class="row">' . ui_csrf() . '<input type="hidden" name="a" value="member_branch"><input type="hidden" name="id" value="' . $x['id'] . '">' . ui_field('template', 'Role', ['type' => 'select', 'value' => $x['template'], 'options' => OWNER_TEMPLATES]) . ui_btn('Save', 'primary sm', 'to', 'set') . ui_btn('Remove from branch', 'ghost sm', 'to', 'revoke') . '</form></details>' : '') . '</div>';
        $rows[] = [pe($m['name']) . '<br><span class="muted">' . pe($m['email']) . '</span>', pe(ucfirst($m['role'])) . ($m['email_verified_at'] ? '' : ' ' . ui_badge('requested', 'Invite pending')), $a ?: '<span class="muted">—</span>',
            $m['role'] !== 'owner' && (int)$m['id'] !== (int)$me['id'] ? '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="member_remove"><input type="hidden" name="user" value="' . $m['id'] . '">' . ui_btn('Remove', 'danger sm') . '</form>' : ''];
    }
    echo '<h1>' . pe(tr('Team', 'टीम')) . '</h1>' . ui_card('', ui_table(['Person', 'Role', 'Branch access', ''], $rows) . '<p class="muted">' . pe(tr('Fine-tune individual permissions (allow / deny a single action) inside each branch: Settings → Users & Permissions.', 'व्यक्तिगत अनुमति हर ब्रांच में Settings → Users में बदलें।')) . '</p>');
    $bx = ''; foreach ($branches as $b) if ($b['status'] === 'active') $bx .= '<label class="chk"><input type="checkbox" name="branches[]" value="' . $b['id'] . '"' . (count($branches) === 1 ? ' checked' : '') . '> ' . pe($b['name']) . '</label>';
    echo ui_card(tr('Invite a person', 'व्यक्ति को आमंत्रित करें'), '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="invite">' . ui_field('name', tr('Name', 'नाम'), ['maxlength' => 160]) . ui_field('email', tr('Email', 'ईमेल'), ['type' => 'email', 'required' => true]) . ui_field('template', tr('Role', 'भूमिका'), ['type' => 'select', 'options' => OWNER_TEMPLATES, 'value' => 'cash_counter', 'hint' => tr('A Cash Counter sees only the closing screen. Each role sees only its own menus — on the website and in the app.', 'कैश काउंटर को सिर्फ़ क्लोज़िंग दिखेगा।')]) . '<fieldset><legend>' . pe(tr('Branches', 'ब्रांच')) . '</legend>' . $bx . '</fieldset>' . ui_btn(tr('Send invitation', 'आमंत्रण भेजें')) . '</form>');
    break;
case 'consolidated':
    $m = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : (new DateTimeImmutable('now', new DateTimeZone(P_TZ)))->format('Y-m');
    $sum = ['sales' => 0, 'other_income' => 0, 'purchases' => 0, 'staff_cost' => 0, 'profit' => 0]; $rows = []; $skipped = [];
    foreach ($branches as $b) { if ($b['status'] !== 'active') continue;
        $bm = prow("SELECT local_user_id FROM p_branch_members WHERE branch_id=? AND user_id=? AND status='active'", $b['id'], $me['id']); if (!$bm) { $skipped[] = $b['name'] . ' (no access)'; continue; }
        $prev = $GLOBALS['F360_TARGET'] ?? null; db_switch(tenancy_branch_target($b));
        try { require_once __DIR__ . '/f360/reports.php'; $lu = row('SELECT * FROM dd_users WHERE id=?', (int)$bm['local_user_id']);
            if (!$lu || !f3_can($lu, 'reports.view')) { $skipped[] = $b['name'] . ' (no report permission)'; continue; }
            $pl = bk_month_summary($m)['pl']; foreach ($sum as $k => $_) $sum[$k] += (int)$pl[$k];
            $rows[] = [pe($b['name']), pe(p_rs((int)$pl['sales'])), pe(p_rs((int)$pl['purchases'])), pe(p_rs((int)$pl['staff_cost'])), '<b>' . pe(p_rs((int)$pl['profit'])) . '</b>'];
        } catch (Throwable $e) { $skipped[] = $b['name'] . ' (could not load: ' . ($e instanceof PDOException ? 'database error' : $e->getMessage()) . ')'; }
        finally { db_switch($prev); } }
    $rows[] = ['<b>' . pe(tr('All branches', 'सभी ब्रांच')) . '</b>', '<b>' . pe(p_rs($sum['sales'])) . '</b>', '<b>' . pe(p_rs($sum['purchases'])) . '</b>', '<b>' . pe(p_rs($sum['staff_cost'])) . '</b>', '<b>' . pe(p_rs($sum['profit'])) . '</b>'];
    echo '<h1>' . pe(tr('All branches report', 'सभी ब्रांच रिपोर्ट')) . '</h1><form class="row" method="get"><input type="hidden" name="t" value="' . $tid . '"><input type="hidden" name="p" value="consolidated"><input type="month" name="m" value="' . pe($m) . '" style="max-width:220px">' . ui_btn('Show', 'ghost sm') . '</form><br>'
       . ui_card('', ui_table(['Branch', 'Sales', 'Purchases', 'Staff cost', 'Profit'], $rows) . '<p class="muted">Basis: each branch’s Books month view (purchases counted when bought, not when consumed). Each branch keeps separate books, so no inter-branch amount is counted twice; record cash moved between branches as owner withdrawal / owner deposit, not as sale or expense. Detailed P&amp;L, trial balance and GL are inside each branch (Accounts).</p>'
       . ($skipped ? '<p class="muted">Not included: ' . pe(implode(', ', $skipped)) . '</p>' : ''));
    break;
case 'billing':
    echo '<h1>' . pe(tr('Billing', 'बिलिंग')) . '</h1>' . ui_card(tr('Current plan', 'वर्तमान प्लान'), '<p>' . ui_badge($ent['state']) . ' ' . pe($ent['plan'] ?? '') . ($ent['term'] ? ' · ' . pe($ent['term']) : '') . '</p><p>' . pe($ent['state'] === 'trialing' ? tr('Trial ends', 'ट्रायल ख़त्म') : tr('Paid until', 'भुगतान तक')) . ': <b>' . pe(p_ist($ent['period_end'])) . '</b></p>'
        . ($ent['kind'] === 'paid' ? '<form method="post">' . ui_csrf() . '<input type="hidden" name="a" value="cancel">' . ($ent['cancel_at_period_end'] ? '<p class="muted">Will not renew after the current period.</p>' . ui_btn('Keep renewing', 'ghost sm', 'to', 'resume') : ui_btn('Do not renew after this period', 'ghost sm', 'to', 'cancel')) . '</form>' : ''));
    $why = billing_gw_ready(); $h = '';
    foreach (billing_plans() as $pl) foreach ($pl['terms'] as $t) if ($t['price_paise'] >= 100)
        $h .= '<form method="post" class="card"><h2>' . pe($pl['name']) . ' · ' . pe(['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Six-monthly', 'yearly' => 'Yearly'][$t['term']]) . '</h2><div class="kpi">' . pe(p_rs($t['price_paise'])) . '</div><p class="muted">' . $t['max_branches'] . ' branch(es) · ' . $t['max_users'] . ' users</p>' . ui_csrf() . '<input type="hidden" name="a" value="checkout"><input type="hidden" name="plan_version_id" value="' . $t['plan_version_id'] . '">' . ($why ? '<button class="btn ghost full" disabled>' . pe(tr('Online payment not active yet', 'ऑनलाइन भुगतान अभी शुरू नहीं')) . '</button>' : ui_btn(tr('Pay & renew', 'भुगतान करें'), 'primary full')) . '</form>';
    echo ui_card(tr('Plans', 'प्लान'), $h ? '<div class="grid g3">' . $h . '</div>' . ($why ? '<p class="muted">' . pe($why) . '</p>' : '<p class="muted">Access is extended only after the payment provider confirms the payment. Renewing early adds time to the end of your current period.</p>') : '<p class="muted">' . pe(tr('Plan prices have not been published yet. Contact support to renew.', 'प्लान की कीमतें अभी प्रकाशित नहीं हुई हैं।')) . '</p>');
    $rows = array_map(fn($o) => [pe(p_ist($o['created_at'], 'j M Y')), pe($o['uid']), pe(p_rs((int)$o['amount_paise'])), ui_badge($o['status']), pe($o['receipt_no'] ?? '—')], prows('SELECT * FROM p_orders WHERE tenant_id=? ORDER BY id DESC LIMIT 50', $tid));
    echo ui_card(tr('Payment history & receipts', 'भुगतान इतिहास'), ui_table(['Date', 'Order', 'Amount', 'Status', 'Receipt'], $rows, 'No payments yet.'));
    $rows = array_map(fn($p) => [pe(ucfirst($p['source'])), pe(p_ist($p['starts_at'], 'j M Y')), pe(p_ist($p['ends_at'], 'j M Y')), $p['revoked_at'] ? ui_badge('bad', 'Revoked') : ui_badge('ok', 'Valid')], prows('SELECT * FROM p_sub_periods WHERE tenant_id=? ORDER BY starts_at DESC LIMIT 50', $tid));
    echo ui_card(tr('Access periods', 'एक्सेस अवधि'), ui_table(['Source', 'From', 'To', ''], $rows));
    break;
case 'pay':
    $c = $_SESSION['checkout'] ?? null; if (!$c) { header('Location: ' . $self('billing')); exit; }
    echo '<h1>Payment</h1>' . ui_card('', '<p>Order <b>' . pe($c['order_uid']) . '</b> · ' . pe(p_rs($c['amount_paise'])) . '</p><p class="muted">' . pe($c['note']) . '</p><p class="muted">The hosted Razorpay checkout opens here once live keys are approved and configured (assets/platform-checkout.js, see docs/BILLING_RUNBOOK.md). Gateway order id: <code>' . pe($c['provider_order_id']) . '</code></p><a class="btn ghost" href="' . pe($self('billing')) . '">Back to billing</a>');
    break;
case 'branding':
    echo '<h1>' . pe(tr('Restaurant details', 'रेस्टोरेंट विवरण')) . '</h1>' . ui_card('', '<form method="post" enctype="multipart/form-data">' . ui_csrf() . '<input type="hidden" name="a" value="branding">' . ui_field('name', tr('Restaurant name (shown in the app)', 'रेस्टोरेंट का नाम'), ['value' => $tenant['name'], 'required' => true, 'maxlength' => 160]) . ui_field('contact_phone', tr('Contact number', 'संपर्क नंबर'), ['value' => $tenant['contact_phone'] ?? '', 'type' => 'tel'])
        . ui_field('address', tr('Address', 'पता'), ['value' => $tenant['address'] ?? '']) . '<div class="grid g3">' . ui_field('city', tr('City', 'शहर'), ['value' => $tenant['city'] ?? '']) . ui_field('state', tr('State', 'राज्य'), ['value' => $tenant['state'] ?? '']) . ui_field('pincode', tr('PIN code', 'पिन'), ['value' => $tenant['pincode'] ?? '']) . '</div>'
        . ($tenant['logo_path'] ? '<p><img src="logo.php?t=' . pe($tenant['uid']) . '" alt="Current logo" width="96" style="border-radius:10px;background:#fff;border:1px solid #ead9c2"></p>' : '') . ui_field('logo', tr('New logo (PNG/JPG/WEBP, 2 MB)', 'नया लोगो'), ['type' => 'file', 'accept' => 'image/png,image/jpeg,image/webp'])
        . '<p class="muted">Colours stay Finance 360 orange/black/cream for readability; your logo and name are shown on every screen.</p>' . ui_btn(tr('Save', 'सेव करें')) . '</form>');
    break;
case 'support':
    $rows = array_map(fn($g) => [pe(p_ist($g['requested_at'])), pe($g['admin_name']), pe($g['branch_name']), pe($g['reason']), pe($g['minutes'] . ' min'), ui_badge($g['status']) . ($g['status'] === 'approved' ? '<br><span class="muted">until ' . pe(p_ist($g['expires_at'])) . '</span>' : ''),
        in_array($g['status'], ['requested', 'approved'], true) ? '<form method="post" class="row">' . ui_csrf() . '<input type="hidden" name="a" value="support"><input type="hidden" name="id" value="' . $g['id'] . '">' . ($g['status'] === 'requested' ? ui_btn('Approve', 'primary sm', 'to', 'approve') . ui_btn('Deny', 'ghost sm', 'to', 'deny') : ui_btn('End now', 'danger sm', 'to', 'revoke')) . '</form>' : ''],
        prows('SELECT g.*,a.name admin_name,b.name branch_name FROM p_support_grants g JOIN p_admins a ON a.id=g.admin_id JOIN p_branches b ON b.id=g.branch_id WHERE g.tenant_id=? ORDER BY g.id DESC LIMIT 50', $tid));
    echo '<h1>' . pe(tr('Support access', 'सपोर्ट एक्सेस')) . '</h1>' . ui_card('', '<p class="muted">Finance 360 staff cannot see your records unless you approve a request here. Approved access is read-only, ends automatically, and every view is logged.</p>' . ui_table(['Requested', 'By', 'Branch', 'Reason', 'Length', 'Status', ''], $rows, 'No requests.'));
    break;
case 'audit':
    $rows = array_map(fn($a) => [pe(p_ist($a['at'])), pe($a['actor_type'] . ($a['actor_id'] ? ' #' . $a['actor_id'] : '')), pe($a['action']), '<code>' . pe(mb_substr((string)$a['detail'], 0, 160)) . '</code>'], prows('SELECT * FROM p_audit WHERE tenant_id=? ORDER BY id DESC LIMIT 200', $tid));
    echo '<h1>' . pe(tr('Account activity', 'खाता गतिविधि')) . '</h1>' . ui_card('', ui_table(['When', 'Who', 'What', 'Detail'], $rows) . '<p class="muted">Money entries have their own audit trail inside each branch (Settings → Audit history).</p>');
    break;
default: echo '<p>Not found.</p>';
}
ui_foot();
