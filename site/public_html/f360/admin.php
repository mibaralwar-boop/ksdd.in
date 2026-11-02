<?php
declare(strict_types=1);
// Finance 360 — dashboard, smart missing-entry assistant, notifications & popups, users / permissions, settings, audit.
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/closing.php';
require_once __DIR__ . '/money.php';
require_once __DIR__ . '/staff.php';
require_once __DIR__ . '/payroll.php';

/* ------------------------------ notifications & popups ------------------------------ */
function f3_sync_notifications(): void {
    $t = f3_target_date();
    if (f3_popup_due() && $t >= f3_closing_start()) { $c = f3_closing_row($t); if (!$c || !in_array($c['status'], ['submitted', 'approved'], true)) f3_notify('closing-due', 'closing-due-' . $t, 'Morning Closing pending for ' . $t, 'Enter yesterday\'s Cash, Card and UPI sale, review expenses and count Actual Cash.', 'closing.entry', 'closing:' . $t); }
    $p = f3_payroll_due_period(); if ($p) { $per = f3_period($p); f3_notify('payroll-due', 'payroll-due-' . $p, (new DateTimeImmutable($p . '-01'))->format('F Y') . ' की Salary Sheet अभी तैयार करनी है।', 'Make Salary Sheet / Continue Pending Salary / Add Later.', 'payroll.prepare', 'payroll:' . $p); }
    foreach (f3_pending_closings() as $d) if ($d['date'] < $t) f3_notify('closing-overdue', 'closing-overdue-' . $d['date'], 'Closing not done for ' . $d['date'], 'This older day is still open. Admin: complete or review it.', 'closing.approve', 'closing:' . $d['date']);
}
function f3_notifications_for(array $u, bool $all = false): array {
    $perms = f3_perms($u); if (!$perms) return [];
    $in = implode(',', array_map(fn($p) => db()->quote($p), $perms));
    $r = rows("SELECT n.*,nu.snooze_until,nu.seen_at FROM f3_notifications n LEFT JOIN f3_notif_user nu ON nu.notification_id=n.id AND nu.user_id=? WHERE n.perm IN ($in) AND " . ($all ? '1=1' : "n.status='open'") . ' ORDER BY n.status=\'open\' DESC,n.id DESC LIMIT 200', $u['id']);
    foreach ($r as &$x) { $x['id'] = (int)$x['id']; $x['snoozed'] = $x['snooze_until'] && $x['snooze_until'] > gmdate('Y-m-d H:i:s'); } unset($x);
    return $r;
}
function f3_snooze(array $b, array $u): array {
    $key = txt($b['key'] ?? '', 120); $n = row('SELECT id FROM f3_notifications WHERE dedupe_key=?', $key); if (!$n) return ['ok' => true];
    $min = max(5, min(1440, (int)($b['minutes'] ?? f3_set_int('f3_snooze_minutes'))));
    run('INSERT INTO f3_notif_user(notification_id,user_id,snooze_until,seen_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE snooze_until=VALUES(snooze_until),seen_at=VALUES(seen_at)', $n['id'], $u['id'], $min);
    return ['ok' => true, 'snoozed_minutes' => $min];
}
function f3_popups(array $u): array {
    $o = []; $snoozed = fn(string $k) => (bool)row('SELECT 1 FROM f3_notifications n JOIN f3_notif_user nu ON nu.notification_id=n.id AND nu.user_id=? WHERE n.dedupe_key=? AND nu.snooze_until>UTC_TIMESTAMP()', $u['id'], $k);
    $t = f3_target_date();
    if (f3_can($u, 'closing.entry') && f3_popup_due() && $t >= f3_closing_start()) {
        $c = f3_closing_row($t);
        if (!$c || !in_array($c['status'], ['submitted', 'approved'], true)) $o['closing'] = ['date' => $t, 'status' => $c['status'] ?? 'not_started', 'step' => (int)($c['step'] ?? 1), 'snoozed' => $snoozed('closing-due-' . $t), 'key' => 'closing-due-' . $t];
    }
    if (f3_can($u, 'payroll.prepare') && ($p = f3_payroll_due_period())) {
        $per = f3_period($p); $o['payroll'] = ['period' => $p, 'label' => (new DateTimeImmutable($p . '-01'))->format('F Y'), 'status' => $per['status'] ?? 'not_started', 'snoozed' => $snoozed('payroll-due-' . $p), 'key' => 'payroll-due-' . $p,
            'progress' => $per ? array_map('intval', row("SELECT COUNT(*) total,COALESCE(SUM(state IN ('done','excluded')),0) done,COALESCE(SUM(state='draft' OR (state='done' AND present IS NOT NULL)),0) touched FROM f3_pay_lines WHERE period=?", $p)) : null];
    }
    return $o;
}

/* ------------------------------ smart missing-entry assistant ------------------------------ */
function f3_assist(string $section, array $u): array {
    $i = []; $add = function (string $lvl, string $msg, ?string $route = null, ?string $code = null) use (&$i) { $i[] = ['level' => $lvl, 'msg' => $msg, 'route' => $route, 'code' => $code]; };
    $t = f3_target_date(); $bd = f3_business_date();
    if (in_array($section, ['dashboard', 'closing', 'sales'], true) && f3_can($u, 'closing.view')) {
        $pend = f3_pending_closings();
        foreach (array_slice($pend, 0, 5) as $p) $add($p['date'] === $t ? 'block' : 'review', 'Morning Closing not submitted for ' . $p['date'] . ($p['status'] === 'draft' ? ' (draft saved — resume)' : ''), '#/closing/' . $p['date'], 'closing_pending');
        if (f3_can($u, 'closing.approve')) {
            foreach (rows("SELECT business_date,sales_post_status FROM f3_closings WHERE status='submitted' ORDER BY business_date DESC LIMIT 10") as $c) $add('review', 'Closing ' . $c['business_date'] . ' waits for your review' . ($c['sales_post_status'] === 'held' ? ' — sales are HELD (Books already had sale entries)' : '') . '.', '#/closing/' . $c['business_date'], 'closing_review');
            $sh = row("SELECT COUNT(*) n,COALESCE(SUM(-accounting_variance),0) a FROM f3_closings WHERE status IN ('submitted','approved') AND accounting_variance < ? AND business_date >= ?", -max(1, f3_set_int('f3_diff_reason_above')), next_date($bd, -7));
            if ((int)$sh['n'] >= 2) $add('review', 'Drawer cash was short on ' . $sh['n'] . ' days in the last week (accounting check, ' . f3_rs((int)$sh['a']) . ' in total). Check handovers and unrecorded payouts. If EVERY day shows a big shortage, the opening-cash setting probably does not match how the drawer is run (Settings → Opening cash for accounting reconciliation).', '#/cash-acess', 'repeat_short');
            $n = (int)row("SELECT COUNT(*) n FROM f3_corrections WHERE status='open'")['n']; if ($n) $add('review', $n . ' correction request(s) waiting.', '#/notifications', 'corrections');
        }
    }
    if (in_array($section, ['dashboard', 'expenses', 'closing'], true) && f3_can($u, 'expenses.view')) {
        foreach (rows("SELECT date,category,total,COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND type='expense' AND date BETWEEN ? AND ? GROUP BY date,category,total HAVING n>1", next_date($bd, -7), $bd) as $d) $add('review', 'Possible duplicate: ' . $d['n'] . ' × ' . ($d['category'] ?: 'uncategorised') . ' ' . f3_rs((int)$d['total']) . ' on ' . $d['date'] . '.', '#/expenses?from=' . $d['date'] . '&to=' . $d['date'], 'dup_expense');
        $n = (int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND type='expense' AND (category IS NULL OR category='') AND date>=?", next_date($bd, -31))['n']; if ($n) $add('review', $n . ' expense(s) in the last month have no category.', '#/expenses', 'uncategorised');
        $pa = f3_set_int('f3_expense_proof_above'); if ($pa > 0) { $n = (int)row("SELECT COUNT(*) n FROM dd_bk_txns t LEFT JOIN f3_expense_meta m ON m.txn_id=t.id WHERE t.voided=0 AND t.type='expense' AND t.total>? AND m.file_id IS NULL AND t.date>=?", $pa, next_date($bd, -31))['n']; if ($n) $add('review', $n . ' expense(s) above ' . f3_rs($pa) . ' have no bill photo.', '#/expenses', 'no_proof'); }
        if (f3_can($u, 'expenses.review')) { $n = (int)row("SELECT COUNT(*) n FROM f3_expense_meta WHERE review_status='flagged'")['n']; if ($n) $add('review', $n . ' expense(s) are flagged for follow-up.', '#/expenses?review=flagged', 'flagged'); }
        if ($section === 'expenses' && !row("SELECT 1 FROM dd_bk_txns WHERE voided=0 AND type='expense' AND date=?", $t) && !row("SELECT 1 FROM f3_closings WHERE business_date=? AND expenses_confirmed='none'", $t)) $add('review', 'No expenses recorded for ' . $t . '. Add them, or confirm “No expenses” in Morning Closing.', '#/closing/' . $t, 'no_expense');
    }
    if (in_array($section, ['dashboard', 'staff', 'stafftxn', 'payroll'], true) && (f3_can($u, 'staff.view') || f3_can($u, 'payroll.prepare'))) {
        $n = (int)row("SELECT COUNT(*) n FROM dd_employees WHERE COALESCE(status,'active')='active' AND f3_dept_id IS NULL")['n']; if ($n) $add('block', $n . ' active staff have no department/designation (Management, UT, Kitchen, Waiter). Salary cannot be prepared for them.', '#/staff?unmapped=1', 'unmapped');
        $n = (int)row("SELECT COUNT(*) n FROM dd_employees WHERE COALESCE(status,'active')='active' AND salary<=0")['n']; if ($n) $add('review', $n . ' active staff have ₹0 basic salary.', '#/staff', 'zero_salary');
        $n = (int)row("SELECT COUNT(*) n FROM dd_employees WHERE COALESCE(status,'active')='active' AND joined_on IS NULL")['n']; if ($n) $add('review', $n . ' active staff have no joining date (needed for part-month salary).', '#/staff', 'no_join');
        $n = (int)row("SELECT COUNT(*) n FROM dd_employees WHERE COALESCE(status,'active')='active' AND pay_mode='Bank' AND account_last4 IS NULL")['n']; if ($n) $add('review', $n . ' staff are paid by Bank but have no account number.', '#/staff', 'no_bank');
    }
    if (in_array($section, ['dashboard', 'stafftxn', 'payroll', 'staff'], true) && f3_can($u, 'stafftxn.approve')) {
        foreach (rows("SELECT kind,COUNT(*) n,SUM(amount) a FROM f3_staff_txns WHERE status='pending' GROUP BY kind") as $r) $add($section === 'payroll' && $r['kind'] === 'penalty' ? 'review' : 'review', $r['n'] . ' ' . (F3_TXN_KINDS[$r['kind']][0] ?? $r['kind']) . ' entr' . ($r['n'] > 1 ? 'ies' : 'y') . ' (' . f3_rs((int)$r['a']) . ') waiting for approval. Pending penalties are NOT deducted in salary.', '#/staff-entries?status=pending', 'pending_' . $r['kind']);
    }
    if (in_array($section, ['dashboard', 'stafftxn'], true) && f3_can($u, 'stafftxn.create')) { $r = row("SELECT COUNT(*) n,COALESCE(SUM(amount),0) a FROM f3_staff_txns WHERE kind='overtime' AND status='unpaid'"); if ((int)$r['n']) $add('review', $r['n'] . ' overtime entr' . ($r['n'] > 1 ? 'ies' : 'y') . ' (' . f3_rs((int)$r['a']) . ') recorded but not yet paid.', '#/staff-entries?status=unpaid', 'ot_unpaid'); }
    if (in_array($section, ['dashboard', 'payroll'], true) && f3_can($u, 'payroll.prepare') && ($p = f3_payroll_due_period())) {
        $per = f3_period($p);
        if (!$per) $add('block', 'Salary sheet for ' . $p . ' has not been started.', '#/payroll/' . $p, 'payroll_due');
        else { $r = row("SELECT SUM(state='skipped') s,SUM(state='later') l,SUM(state IN ('pending','draft')) p FROM f3_pay_lines WHERE period=?", $p); if ((int)$r['s'] + (int)$r['l'] + (int)$r['p']) $add('review', 'Salary ' . $p . ': ' . (int)$r['p'] . ' not done, ' . (int)$r['s'] . ' skipped, ' . (int)$r['l'] . ' “add later”.', '#/payroll/' . $p, 'payroll_incomplete'); elseif ($per['status'] === 'draft') $add('review', 'Salary ' . $p . ' is complete — send it for approval.', '#/payroll/' . $p, 'payroll_submit'); elseif ($per['status'] === 'review' && f3_can($u, 'payroll.approve')) $add('review', 'Salary ' . $p . ' is waiting for your approval.', '#/payroll/' . $p, 'payroll_approve'); }
    }
    if (in_array($section, ['dashboard', 'suppliers'], true) && f3_can($u, 'suppliers.view')) {
        $n = 0; $amt = 0; foreach (bk_party_balances() as $p) if ($p['kind'] === 'supplier' && $p['balance'] > 0 && $p['oldest_credit'] && (int)$p['credit_days'] > 0 && next_date($p['oldest_credit'], (int)$p['credit_days']) < $bd) { $n++; $amt += $p['balance']; }
        if ($n) $add('review', $n . ' supplier(s) have bills older than their credit days (' . f3_rs($amt) . ' due).', '#/suppliers', 'supplier_overdue');
    }
    if (in_array($section, ['dashboard', 'accounts', 'cash'], true) && f3_can($u, 'accounts.view')) {
        $n = (int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND type IN ('add_cash','reduce_cash') AND (description IS NULL OR description='') AND date>=?", next_date($bd, -62))['n']; if ($n) $add('review', $n . ' cash adjustment(s) have no reason.', '#/accounts', 'adj_no_reason');
    }
    $blocks = count(array_filter($i, fn($x) => $x['level'] === 'block'));
    if (in_array($section, ['dashboard', 'purchases', 'stock'], true) && function_exists('f3_purchase_alerts')) foreach (f3_purchase_alerts($u) as $a) $add($a['level'] === 'warn' ? 'review' : 'info', $a['text'], $a['go'], 'p-' . $a['kind']);
    return ['section' => $section, 'items' => $i, 'blocking' => $blocks, 'reviews' => count($i) - $blocks];
}

/* ------------------------------ dashboard ------------------------------ */
function f3_dashboard(array $u): array {
    f3_need($u, 'dashboard.view');
    $t = f3_target_date(); $bd = f3_business_date(); $m = substr($bd, 0, 7);
    $o = ['business_date' => $bd, 'target_date' => $t, 'cards' => []];
    if (f3_can($u, 'closing.view')) { $s = f3_closing_state($t, $u); $o['closing'] = ['date' => $t, 'status' => $s['status'], 'sales' => $s['sales'], 'expenses_total' => $s['expenses_total'], 'actual_cash' => $s['actual_cash'], 'calc' => $s['calc'], 'locked' => $s['locked']]; }
    if (f3_can($u, 'sales.view')) {
        $h = f3_sales_history($m . '-01', $bd, $u); $y = null; foreach ($h['days'] as $d) if ($d['date'] === $t) $y = $d;
        $pm = f3_prev_month($m); $o['last_month'] = ['month' => $pm, 'totals' => f3_sales_history($pm . '-01', f3_month_last($pm), $u)['totals']];
        $o['yesterday'] = $y; $o['mtd'] = $h['totals']; $o['recent'] = f3_sales_history(next_date($bd, -6), $bd, $u)['days'];
    }
    if (f3_can($u, 'sales.view')) { $tr = f3_sales_history(next_date($bd, -29), $bd, $u)['days']; usort($tr, fn($a, $b) => strcmp($a['date'], $b['date'])); $o['trend'] = array_map(fn($x) => ['date' => $x['date'], 'cash' => $x['cash'], 'card' => $x['card'], 'upi' => $x['upi'] + $x['other'], 'expenses' => $x['expenses']], $tr); }
    if (f3_can($u, 'expenses.view')) { $e30 = f3_expense_list(['from' => next_date($bd, -29), 'to' => $bd], $u); $o['exp30'] = array_slice($e30['by_category'], 0, 8, true); $o['exp30_total'] = $e30['total']; }
    if (f3_can($u, 'expenses.view')) { $e = f3_expense_list(['from' => $bd, 'to' => $bd], $u); $o['today_expenses'] = ['total' => $e['total'], 'count' => $e['count']]; }
    if (f3_can($u, 'cash.view')) $o['cash_in_hand'] = bk_opening_before(next_date($bd), f3_set('f3_acct_cash'));
    if (f3_can($u, 'accounts.view')) $o['accounts'] = f3_accounts_overview($bd, $u)['accounts'];
    if (f3_can($u, 'suppliers.view')) { $s = f3_sup_list(['limit' => 10], $u); $o['supplier_payable'] = $s['totals']['payable'] ?? 0; $o['supplier_overdue'] = $s['totals']['overdue'] ?? 0; }
    if (f3_can($u, 'staff.ledger') || f3_can($u, 'payroll.view')) { $adv = 0; foreach (rows("SELECT id FROM dd_employees") as $e) $adv += f3_staff_balances((int)$e['id'])['advance_outstanding']; $o['staff_advance'] = $adv; $o['staff_count'] = (int)row("SELECT COUNT(*) n FROM dd_employees WHERE COALESCE(status,'active')='active'")['n']; }
    if (f3_can($u, 'payroll.view') || f3_can($u, 'payroll.prepare')) { $p = f3_payroll_due_period() ?? f3_prev_month($m); $o['payroll'] = ['period' => $p] + array_intersect_key(f3_period_view($p, $u), array_flip(['status', 'payment_status', 'progress', 'totals', 'exists'])); }
    if (f3_can($u, 'purchase.view')) { $pm = row("SELECT COUNT(*) n,COALESCE(SUM(grand_total),0) t FROM f3_purchase_bills WHERE status='posted' AND post_date>=?", $m . '-01'); $o['purchases_mtd'] = ['count' => (int)$pm['n'], 'total' => (int)$pm['t']];
        $o['bills_review'] = (int)row("SELECT COUNT(*) n FROM f3_purchase_bills WHERE status='review'")['n']; $o['goods_pending'] = (int)row("SELECT COUNT(*) n FROM f3_purchase_bills WHERE status='posted' AND receipt_status<>'received'")['n']; }
    if (f3_can($u, 'stock.view')) { $o['low_stock'] = (int)row("SELECT COUNT(*) n FROM dd_bk_items i LEFT JOIN f3_stock_balances b ON b.item_id=i.id AND b.location='Main Store' WHERE i.active=1 AND i.is_stock=1 AND i.merged_into IS NULL AND GREATEST(i.min_level,i.reorder)>0 AND COALESCE(b.qty,0)<=GREATEST(i.min_level,i.reorder)")['n'];
        if (f3_can($u, 'stock.valuation')) $o['stock_value'] = (int)row('SELECT COALESCE(SUM(value),0) v FROM f3_stock_balances')['v']; }
    if (f3_can($u, 'stafftxn.approve')) $o['pending_approvals'] = (int)row("SELECT COUNT(*) n FROM f3_staff_txns WHERE status='pending'")['n'];
    if (f3_can($u, 'closing.approve')) $o['closings_to_review'] = (int)row("SELECT COUNT(*) n FROM f3_closings WHERE status='submitted'")['n'];
    $o['assist'] = f3_assist('dashboard', $u);
    return $o;
}

/* ------------------------------ users & permissions ------------------------------ */
function f3_users(array $u): array {
    f3_need($u, 'users.manage');
    $tpl = f3_templates(); $o = [];
    foreach (rows('SELECT u.id,u.name,u.email,u.role,COALESCE(u.active,1) active,a.template,a.overrides,a.limits,(SELECT MAX(at) FROM dd_audit WHERE user_id=u.id AND action=\'login\') last_login FROM dd_users u LEFT JOIN f3_user_access a ON a.user_id=u.id ORDER BY u.active DESC,u.name') as $r) {
        $r['id'] = (int)$r['id']; $r['active'] = (int)$r['active']; $r['template'] = $r['template'] ?: (F3_ROLE_TEMPLATE[$r['role']] ?? 'none'); $r['overrides'] = json_decode((string)$r['overrides'], true) ?: (object)[]; $r['perms'] = f3_perms($r); $r['legacy_role_label'] = ROLE_LABELS[$r['role']] ?? $r['role']; $l = json_decode((string)$r['limits'], true) ?: []; $r['pay_max'] = (int)($l['pay_max'] ?? 0); unset($r['limits']); $o[] = $r;
    }
    return ['users' => $o, 'templates' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0], 'perms' => $v[1]], array_keys($tpl), $tpl), 'perms' => array_map(fn($k, $v) => ['key' => $k, 'group' => $v[0], 'label' => $v[1]], array_keys(F3_PERMS), F3_PERMS), 'legacy_roles' => ROLE_LABELS];
}
function f3_user_save(array $b, array $u): array {
    f3_need($u, 'users.manage'); $id = (int)($b['id'] ?? 0);
    $name = name_required($b['name'] ?? ''); $email = strtolower(txt($b['email'] ?? '', 190)); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email (used to sign in).');
    $role = (string)($b['role'] ?? 'counter'); if (!isset(ROLES[$role])) fail('Choose a valid base role.');
    if ($role === 'owner' && ($u['role'] ?? '') !== 'owner') fail('Only an owner can create another owner.', 403);
    $tpl = (string)($b['template'] ?? (F3_ROLE_TEMPLATE[$role] ?? 'none')); if (!isset(f3_templates()[$tpl])) fail('Choose a permission template.');
    $ov = []; foreach ((array)($b['overrides'] ?? []) as $k => $v) if (isset(F3_PERMS[$k])) { $inTpl = in_array($k, f3_templates()[$tpl][1], true); if ((bool)$v !== $inTpl) $ov[$k] = (bool)$v; }
    $active = !isset($b['active']) || !empty($b['active']) ? 1 : 0;
    if (row('SELECT id FROM dd_users WHERE email=? AND id<>?', $email, $id)) fail('This email is already used by another user.', 409);
    if ($id) {
        $old = row('SELECT * FROM dd_users WHERE id=? FOR UPDATE', $id); if (!$old) fail('User not found.', 404);
        if ($old['role'] === 'owner' && ($u['role'] ?? '') !== 'owner') fail('Only an owner can change an owner account.', 403);
        if ($id === (int)$u['id'] && (!$active || $role !== $old['role'])) fail('You cannot deactivate yourself or change your own role.', 409);
        if ($old['role'] === 'owner' && ($role !== 'owner' || !$active) && (int)row("SELECT COUNT(*) n FROM dd_users WHERE role='owner' AND COALESCE(active,1)=1")['n'] <= 1) fail('At least one active owner must remain.', 409);
        $bump = $role !== $old['role'] || $active !== (int)($old['active'] ?? 1) || $email !== $old['email'];
        run('UPDATE dd_users SET name=?,email=?,role=?,active=?' . ($bump ? ',session_rev=session_rev+1' : '') . ' WHERE id=?', $name, $email, $role, $active, $id);
    } else {
        $pw = (string)($b['password'] ?? ''); if (strlen($pw) < 12 || strlen($pw) > 200) fail('Password must be 12–200 characters.');
        run('INSERT INTO dd_users(name,email,password,role,active) VALUES(?,?,?,?,?)', $name, $email, password_hash($pw, PASSWORD_DEFAULT), $role, $active); $id = (int)db()->lastInsertId();
    }
    $prev = row('SELECT template,overrides,limits FROM f3_user_access WHERE user_id=?', $id);
    if (($u['role'] ?? '') !== 'owner' && (($prev['template'] ?? (F3_ROLE_TEMPLATE[$role] ?? 'none')) !== $tpl || (string)($prev['overrides'] ?? '{}') !== json_encode((object)$ov))) fail('Only the owner can grant or change permissions.', 403);
    $lim = $prev ? (json_decode((string)$prev['limits'], true) ?: []) : [];
    if (array_key_exists('pay_max', $b)) { if (($u['role'] ?? '') !== 'owner') fail('Only the owner can change payment limits.', 403); $lim['pay_max'] = ($b['pay_max'] ?? '') === '' ? 0 : f3_paise($b['pay_max'], 'Payment limit'); }
    run('INSERT INTO f3_user_access(user_id,template,overrides,limits,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE template=VALUES(template),overrides=VALUES(overrides),limits=VALUES(limits),updated_by=VALUES(updated_by)', $id, $tpl, json_encode((object)$ov), json_encode((object)$lim), $u['id']);
    if ($prev && ($prev['template'] !== $tpl || (string)$prev['overrides'] !== json_encode((object)$ov))) run('UPDATE dd_users SET session_rev=session_rev+1 WHERE id=? AND id<>?', $id, $u['id']);
    f3_perm_cache_clear();
    f3_audit($u, 'user.save', ['id' => $id, 'email' => $email, 'role' => $role, 'template' => $tpl, 'overrides' => $ov, 'active' => $active]);
    return ['ok' => true, 'id' => $id];
}
function f3_user_password(array $b, array $u): array {
    $id = (int)($b['id'] ?? $u['id']); $pw = (string)($b['password'] ?? ''); if (strlen($pw) < 12 || strlen($pw) > 200) fail('Password must be 12–200 characters.');
    if ($id === (int)$u['id']) { $me = row('SELECT password FROM dd_users WHERE id=?', $id); if (!password_verify((string)($b['current'] ?? ''), $me['password'])) fail('Current password is wrong.', 403); }
    else { f3_need($u, 'users.manage'); $t = row('SELECT role FROM dd_users WHERE id=?', $id); if (!$t) fail('User not found.', 404); if ($t['role'] === 'owner' && ($u['role'] ?? '') !== 'owner') fail('Only an owner can reset an owner password.', 403); }
    run('UPDATE dd_users SET password=?,session_rev=session_rev+1 WHERE id=?', password_hash($pw, PASSWORD_DEFAULT), $id);
    if ($id === (int)$u['id']) $_SESSION['rev'] = (int)row('SELECT session_rev FROM dd_users WHERE id=?', $id)['session_rev'];
    f3_audit($u, 'user.password', ['id' => $id, 'self' => $id === (int)$u['id']]);
    return ['ok' => true];
}

/* ------------------------------ settings & audit ------------------------------ */
const F3_SETTING_RULES = [
 'business_day_cutoff_hour' => ['int', 0, 8, 'Business day changes at (hour, 0–8)'], 'f3_popup_hour' => ['int', 0, 23, 'Morning Closing popup from (hour)'],
 'f3_closing_start' => ['date', 0, 0, 'Closings required from (date)'], 'f3_counter_backlog_days' => ['int', 1, 7, 'Counter may close up to N past days'], 'f3_snooze_minutes' => ['int', 5, 1440, '“Add later” hides popup for (minutes)'],
 'f3_diff_reason_above' => ['money_or_flag', 0, 0, 'Reason required when Cash Acess / Short is above (₹; −1 = never)'], 'f3_opening_mode' => ['enum', ['books', 'zero'], 0, 'Opening cash for accounting reconciliation'],
 'unify_from' => ['date_or_blank', 0, 0, 'Double-entry ledger: post the cash book from (date; blank = off). Then Accounts → Update ledger'], 'f3_acct_cash' => ['account', 0, 0, 'Books account for Cash'], 'f3_acct_card' => ['account', 0, 0, 'Books account for Card'], 'f3_acct_upi' => ['account', 0, 0, 'Books account for UPI'], 'f3_acct_bank' => ['account', 0, 0, 'Books account for Bank'],
 'f3_expense_proof_above' => ['money', 0, 0, 'Bill photo required above (₹, 0 = optional)'], 'f3_payroll_divisor' => ['enum', ['calendar'], 0, 'Salary day divisor (always actual calendar days)'], 'f3_payroll_separate_approver' => ['enum', ['0', '1'], 0, 'Preparer cannot approve'], 'f3_strict_gl' => ['enum', ['0', '1'], 0, 'Strict ledger: block salary lock while ledger posting is pending'],
 'f3_payroll_reminder_hour' => ['int', 0, 23, 'Salary reminder on the 1st from (hour)'], 'f3_payroll_start' => ['month', 0, 0, 'Finance 360 salary from (month)'], 'f3_half_day_value' => ['enum', ['0.5'], 0, 'Half day counts as'],
 'f3_max_deduction_pct' => ['int', 10, 100, 'Max total deductions as % of earnings (wage-law check)'],
 'f3_advance_limit_pct' => ['int', 0, 300, 'Advance balance limit as % of monthly salary (0 = none)'],
 'f3_default_paid_leave' => ['enum', ['0', '1', '2', '3', '4'], 0, 'Default paid leave allowed per month (pre-filled)'],
 'f3_rest_days_paid' => ['enum', ['1', '0'], 0, 'Weekly rest / holiday days entered in the salary popup are paid (1 = yes, 0 = unpaid)'],
 'legacy_archived' => ['enum', ['1', '0'], 0, 'Old modules archived (1 = closed, owner read-only; 0 = reopen old screens — emergency only)'],
 'f3_purchase_approval_above' => ['money', 0, 0, 'Purchase bills above this need approval (₹, 0 = no approval step)'],
 'f3_negative_stock' => ['enum', ['0', '1'], 0, 'Allow stock to go below zero (1 = yes; not recommended)'],
 'f3_tax_in_cost' => ['enum', ['1', '0'], 0, 'GST is part of item cost (1 = no input-tax credit claimed)'],
 'f3_require_utr' => ['enum', ['0', '1'], 0, 'UTR / reference required for UPI & bank supplier payments'],
];
function f3_settings(array $u): array {
    f3_need($u, 'settings.manage', 'users.manage'); $o = [];
    foreach (F3_SETTING_RULES as $k => $r) { $v = $k === 'f3_max_deduction_pct' ? setting($k, '50') : f3_set($k); $o[] = ['key' => $k, 'label' => $r[3], 'type' => $r[0], 'options' => is_array($r[1]) ? $r[1] : null, 'min' => is_int($r[1]) ? $r[1] : null, 'max' => $r[2] ?: null, 'value' => $r[0] === 'list' ? implode(', ', json_decode($v, true) ?: []) : (in_array($r[0], ['money', 'money_or_flag'], true) && (int)$v > 0 ? rupees((int)$v) : $v)]; }
    return ['settings' => $o, 'accounts' => bk_accounts(), 'restaurant' => setting('restaurant', 'Khadak Singh Da Dhaba'), 'pii_key_configured' => f3_pii_key() !== null, 'timezone' => 'Asia/Kolkata', 'now' => f3_now()->format('Y-m-d H:i')];
}
function f3_list_setting(string $v, string $l): string { $a = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 60), explode(',', $v)), 'strlen'))); if (!$a) fail($l . ': enter at least one.');
    $old = f3_locations(); foreach ($old as $o) if (!in_array($o, $a, true) && row('SELECT 1 FROM f3_stock_balances WHERE location=? AND qty<>0 LIMIT 1', $o)) fail('Location “' . $o . '” still has stock; transfer it before removing.'); return json_encode($a, JSON_UNESCAPED_UNICODE); }
function f3_settings_save(array $b, array $u): array {
    f3_need($u, 'settings.manage'); $ch = [];
    foreach ((array)($b['values'] ?? []) as $k => $v) {
        if (!isset(F3_SETTING_RULES[$k])) continue; [$t, $a, $z, $l] = F3_SETTING_RULES[$k]; $v = trim((string)$v);
        $val = match ($t) { 'int' => (preg_match('/^\d+$/', $v) && (int)$v >= $a && (int)$v <= $z) ? $v : fail($l . ': enter a number ' . $a . '–' . $z . '.'),
            'date' => valid_date($v), 'date_or_blank' => $v === '' ? '' : valid_date($v), 'month' => valid_month($v), 'enum' => in_array($v, $a, true) ? $v : fail($l . ': invalid choice.'),
            'account' => in_array($v, bk_accounts(), true) ? $v : fail($l . ': choose a Books account.'), 'money' => (string)f3_paise($v === '' ? '0' : $v, $l),
            'money_or_flag' => $v === '-1' ? '-1' : (string)f3_paise($v === '' ? '0' : $v, $l),
            'list' => f3_list_setting($v, $l) };
        $old = setting($k, ''); if ($old !== $val) { set_setting($k, $val); $ch[$k] = ['from' => $old, 'to' => $val]; }
    }
    if ($ch) f3_audit($u, 'settings.save', $ch);
    return ['ok' => true, 'changed' => array_keys($ch)];
}
function f3_audit_list(array $q, array $u): array {
    f3_need($u, 'audit.view'); $w = ['1=1']; $a = [];
    if (!empty($q['action'])) { $w[] = 'a.action LIKE ?'; $a[] = '%' . txt($q['action'], 60) . '%'; }
    if (!empty($q['user'])) { $w[] = 'a.user_id=?'; $a[] = (int)$q['user']; }
    if (!empty($q['from'])) { $w[] = 'a.at>=?'; $a[] = valid_date($q['from']) . ' 00:00:00'; }
    if (!empty($q['to'])) { $w[] = 'a.at<=?'; $a[] = valid_date($q['to']) . ' 23:59:59'; }
    $page = max(0, (int)($q['page'] ?? 0));
    $r = rows('SELECT a.id,a.at,a.action,a.detail,u.name user FROM dd_audit a LEFT JOIN dd_users u ON u.id=a.user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY a.id DESC LIMIT 100 OFFSET ' . ($page * 100), ...$a);
    foreach ($r as &$x) { $d = json_decode((string)$x['detail'], true); $x['detail'] = is_array($d) ? $d : $x['detail']; } unset($x);
    return ['rows' => $r, 'page' => $page, 'users' => rows('SELECT id,name FROM dd_users ORDER BY name')];
}
/** Who may open an uploaded file: the uploader, or a user allowed to see the record it belongs to. */
function f3_file_ok(array $f, array $u): bool { $id = (int)$f['id'];
    if ((int)$f['uploaded_by'] === (int)$u['id'] || f3_can($u, 'accounts.view')) return true;
    if (f3_can($u, 'expenses.view') && (row('SELECT 1 FROM f3_expense_meta WHERE file_id=? UNION SELECT 1 FROM f3_expense_files WHERE file_id=?', $id, $id) || !row('SELECT 1 FROM f3_bill_files WHERE file_id=? UNION SELECT 1 FROM f3_supplier_payments WHERE file_id=? UNION SELECT 1 FROM dd_employees WHERE photo_file_id=? UNION SELECT 1 FROM f3_staff_txns WHERE file_id=?', $id, $id, $id, $id))) return true; // expense proofs + legacy files
    if (f3_can($u, 'staff.ledger') && row('SELECT 1 FROM f3_staff_txns WHERE file_id=?', $id)) return true;
    if (f3_can($u, 'docs.inbox') && row("SELECT 1 FROM f3_doc_inbox WHERE file_id=? AND status<>'removed'", $id)) return true; // V23 document inbox
    if ((f3_can($u, 'closing.view') || f3_can($u, 'closing.entry')) && row('SELECT 1 FROM f3_closing_files WHERE file_id=? AND removed_at IS NULL', $id)) return true; // V29 daybook photo
    if (f3_can($u, 'staff.view') && row('SELECT 1 FROM dd_employees WHERE photo_file_id=?', $id)) return true;
    if ((f3_can($u, 'purchase.view') || f3_can($u, 'stock.receive')) && (row('SELECT 1 FROM f3_bill_files WHERE file_id=?', $id) || row('SELECT 1 FROM f3_purchase_lines WHERE photo_file_id=?', $id) || row('SELECT 1 FROM f3_supplier_payments WHERE file_id=?', $id))) return true;
    if ((f3_can($u, 'item.view') || f3_can($u, 'stock.view') || f3_can($u, 'purchase.create')) && (row('SELECT 1 FROM dd_bk_items WHERE photo_file_id=?', $id) || row('SELECT 1 FROM f3_purchase_lines WHERE photo_file_id=?', $id))) return true;
    if (f3_can($u, 'suppliers.view') && (row('SELECT 1 FROM dd_bk_parties WHERE photo_file_id=?', $id) || row('SELECT 1 FROM f3_supplier_payments WHERE file_id=?', $id))) return true;
    return false; }
function f3_file(int $id, array $u): never {
    $f = row('SELECT * FROM dd_files WHERE id=?', $id); if (!$f) fail('File not found.', 404);
    if (!f3_file_ok($f, $u)) fail('You cannot open this file.', 403);
    $base = realpath(storage_dir('uploads')); $path = realpath($base . '/' . $f['path']);
    if (!$path || !$base || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) fail('File is missing on the server.', 404);
    header('Content-Type: ' . $f['mime']); header('Content-Disposition: inline; filename="proof-' . $id . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"'); header('Content-Length: ' . filesize($path)); header('X-Content-Type-Options: nosniff');
    readfile($path); exit;
}
