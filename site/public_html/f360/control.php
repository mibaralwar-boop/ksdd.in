<?php
declare(strict_types=1);
// Finance 360 V17.2 — Admin control centre. Everything the business may want to change lives in Settings, not in code:
// business profile · payment accounts · expense categories · designations (via staff.php) · stock locations / pack units / cost centres ·
// modules on/off · reminder switches · WhatsApp message templates · custom permission roles.
// Rules: masters that already have history are never renamed or deleted (that would rewrite old reports) — they are hidden instead.
// Every change is audited with before/after. Roles and modules: owner only. Everything else: settings.manage.
require_once __DIR__ . '/stock.php';

const F3_ALERTS = ['drafts' => 'Unfinished purchase drafts', 'review' => 'Purchase bills waiting for approval', 'receive' => 'Goods not received', 'overdue' => 'Overdue supplier bills',
    'low' => 'Low / out-of-stock items', 'count' => 'Monthly physical stock count'];
const F3_MSG_DEFAULTS = [
    'closing' => "*{restaurant} — Closing {date}*\nCash Sale: {cash}\nCard Sale: {card}\nUPI Sale: {upi}\n*Total Sale: {total}*\nExpenses: {expenses}\nActual Cash: {actual}\n{diff_label}: {diff}",
    'supplier' => "Namaste {supplier}, as per our books the balance with you is {balance}. — {restaurant}",
    'payslip' => "*{restaurant} — Salary slip {month}*\n{name} ({code})\nPayable days: {days}\nEarned: {earned}\nPenalty: {penalty} · Advance recovered: {advance}\n*Net salary: {net}*\nPaid: {paid} · Balance: {balance}",
];
const F3_MSG_VARS = ['closing' => ['restaurant', 'date', 'cash', 'card', 'upi', 'total', 'expenses', 'actual', 'diff_label', 'diff'], 'supplier' => ['restaurant', 'supplier', 'balance'], 'payslip' => ['restaurant', 'month', 'name', 'code', 'days', 'earned', 'penalty', 'advance', 'net', 'paid', 'balance']];

function f3_alerts_on(): array { $a = f3_json_setting('f3_alerts'); $o = []; foreach (F3_ALERTS as $k => $_) $o[$k] = !isset($a[$k]) || (bool)$a[$k]; return $o; }
function f3_hidden(string $k): array { return array_values(array_filter(f3_json_setting($k), 'is_string')); }
function f3_visible_accounts(): array { $h = f3_hidden('f3_hidden_accounts'); return array_values(array_filter(bk_accounts(), fn($a) => !in_array($a, $h, true))); }
function f3_business(): array { return ['name' => setting('restaurant', 'Khadak Singh Da Dhaba'), 'address' => setting('biz_address', ''), 'phone' => setting('biz_phone', ''), 'gstin' => setting('biz_gstin', ''), 'footer' => setting('biz_footer', '')]; }
function f3_messages(): array { $m = f3_json_setting('f3_messages'); $o = []; foreach (F3_MSG_DEFAULTS as $k => $d) $o[$k] = isset($m[$k]) && is_string($m[$k]) && trim($m[$k]) !== '' ? $m[$k] : $d; return $o; }
function f3_cost_centres(): array { $c = f3_hidden('f3_cost_centres'); return $c ?: ['Kitchen', 'Tandoor', 'Staff meal', 'Bar', 'Housekeeping']; }
function f3_pack_units(): array { return array_values(array_unique(array_merge(F3_PACK_UNITS, f3_hidden('f3_pack_units_extra')))); }

function f3_control_get(array $u): array {
    f3_need($u, 'settings.manage', 'users.manage');
    $use = []; foreach (rows('SELECT account a,COUNT(*) n FROM dd_bk_txns GROUP BY account') as $r) $use[$r['a']] = (int)$r['n'];
    foreach (rows('SELECT to_account a,COUNT(*) n FROM dd_bk_txns WHERE to_account IS NOT NULL GROUP BY to_account') as $r) $use[$r['a']] = ($use[$r['a']] ?? 0) + (int)$r['n'];
    $hidA = f3_hidden('f3_hidden_accounts'); $map = []; foreach (['cash', 'card', 'upi', 'bank'] as $k) $map[f3_set('f3_acct_' . $k)][] = strtoupper($k);
    $accts = array_map(fn($a) => ['name' => $a, 'entries' => $use[$a] ?? 0, 'hidden' => in_array($a, $hidA, true), 'mapped' => $map[$a] ?? [], 'balance' => bk_opening_before(next_date(f3_business_date()), $a)], bk_accounts());
    $cu = []; foreach (rows('SELECT category c,COUNT(*) n FROM dd_bk_txns WHERE category IS NOT NULL GROUP BY category') as $r) $cu[$r['c']] = (int)$r['n'];
    $hidC = f3_hidden('f3_hidden_categories');
    $cats = []; foreach (bk_categories() as $c => $g) $cats[] = ['name' => $c, 'group' => $g, 'entries' => $cu[$c] ?? 0, 'hidden' => in_array($c, $hidC, true)];
    $locs = []; foreach (f3_locations() as $l) { $s = row('SELECT COUNT(*) n,COALESCE(SUM(value),0) v FROM f3_stock_balances WHERE location=? AND qty<>0', $l); $locs[] = ['name' => $l, 'items' => (int)$s['n'], 'value' => (int)$s['v'], 'moves' => (int)row('SELECT COUNT(*) n FROM f3_stock_moves WHERE location=?', $l)['n']]; }
    $tplUse = []; foreach (rows('SELECT template t,COUNT(*) n FROM f3_user_access GROUP BY template') as $r) $tplUse[$r['t']] = (int)$r['n'];
    return ['owner' => ($u['role'] ?? '') === 'owner', 'business' => f3_business(), 'accounts' => $accts, 'categories' => $cats, 'groups' => BK_GROUPS, 'departments' => f3_departments(),
        'locations' => $locs, 'packs' => f3_pack_units(), 'packs_base' => F3_PACK_UNITS, 'cost_centres' => f3_cost_centres(),
        'modules' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0], 'on' => f3_modules()[$k]], array_keys(F3_MODULES), F3_MODULES),
        'alerts' => array_map(fn($k, $l) => ['key' => $k, 'label' => $l, 'on' => f3_alerts_on()[$k]], array_keys(F3_ALERTS), F3_ALERTS), 'draft_days' => (int)setting('f3_draft_alert_days', '1'), 'count_days' => (int)setting('f3_count_every_days', '30'),
        'messages' => f3_messages(), 'message_defaults' => F3_MSG_DEFAULTS, 'message_vars' => F3_MSG_VARS,
        'templates' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0], 'perms' => $v[1], 'custom' => str_starts_with($k, 'c_'), 'users' => $tplUse[$k] ?? 0], array_keys(f3_templates()), f3_templates()),
        'perms' => array_map(fn($k, $v) => ['key' => $k, 'group' => $v[0], 'label' => $v[1]], array_keys(F3_PERMS), F3_PERMS)];
}

function f3_control_save(array $b, array $u): array {
    $sec = (string)($b['section'] ?? ''); $owner = ($u['role'] ?? '') === 'owner';
    if (in_array($sec, ['modules', 'roles'], true)) { if (!$owner) fail('Only the owner can change modules and roles.', 403); } else f3_need($u, 'settings.manage');
    $before = null; $after = null;
    switch ($sec) {
        case 'business':
            $before = f3_business(); $name = mb_substr(trim((string)($b['name'] ?? '')), 0, 80); if (mb_strlen($name) < 3) fail('Business name is required.');
            $g = strtoupper(preg_replace('/\s+/', '', (string)($b['gstin'] ?? ''))); if ($g !== '' && !preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $g)) fail('GSTIN looks wrong (15 characters).');
            $ph = preg_replace('/[^\d+ ]/', '', (string)($b['phone'] ?? ''));
            foreach (['restaurant' => $name, 'biz_address' => txt($b['address'] ?? '', 300), 'biz_phone' => mb_substr($ph, 0, 20), 'biz_gstin' => $g, 'biz_footer' => txt($b['footer'] ?? '', 200)] as $k => $v) set_setting($k, $v);
            $after = f3_business(); break;
        case 'account_add':
            $n = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($b['name'] ?? ''))), 0, 40); if (mb_strlen($n) < 2) fail('Account name is required.');
            $all = bk_accounts(); foreach ($all as $a) if (mb_strtolower($a) === mb_strtolower($n)) fail('“' . $a . '” already exists.', 409);
            $before = $all; $all[] = $n; set_setting('bk_accounts', json_encode($all, JSON_UNESCAPED_UNICODE)); $after = $all; break;
        case 'account_rename':
            $old = (string)($b['old'] ?? ''); $n = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($b['name'] ?? ''))), 0, 40); $all = bk_accounts(); $i = array_search($old, $all, true); if ($i === false) fail('Account not found.', 404);
            if (mb_strlen($n) < 2) fail('New name is required.'); foreach ($all as $a) if ($a !== $old && mb_strtolower($a) === mb_strtolower($n)) fail('“' . $a . '” already exists.', 409);
            if (row('SELECT 1 FROM dd_bk_txns WHERE account=? OR to_account=? LIMIT 1', $old, $old)) fail('“' . $old . '” already has entries — renaming would change old books. Hide it and add a new account instead.', 409);
            foreach (['cash', 'card', 'upi', 'bank'] as $k) if (f3_set('f3_acct_' . $k) === $old) set_setting('f3_acct_' . $k, $n);
            $before = $all; $all[$i] = $n; set_setting('bk_accounts', json_encode(array_values($all), JSON_UNESCAPED_UNICODE)); $after = $all; break;
        case 'account_hide':
            $a = (string)($b['name'] ?? ''); if (!in_array($a, bk_accounts(), true)) fail('Account not found.', 404); $h = f3_hidden('f3_hidden_accounts'); $before = $h;
            if (!empty($b['hidden'])) { foreach (['cash', 'card', 'upi', 'bank'] as $k) if (f3_set('f3_acct_' . $k) === $a) fail('“' . $a . '” is used for ' . strtoupper($k) . ' in Accounts mapping. Map another account first.', 409);
                if (count(array_diff(bk_accounts(), $h)) <= 1) fail('At least one account must stay visible.', 409); $h[] = $a; } else $h = array_values(array_diff($h, [$a]));
            set_setting('f3_hidden_accounts', json_encode(array_values(array_unique($h)), JSON_UNESCAPED_UNICODE)); $after = f3_hidden('f3_hidden_accounts'); break;
        case 'category_save':
            $n = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($b['name'] ?? ''))), 0, 80); $g = (string)($b['group'] ?? ''); if (mb_strlen($n) < 2) fail('Category name is required.');
            if (!in_array($g, BK_GROUPS, true)) fail('Choose a report group.'); $c = bk_categories(); $before = $c; $old = (string)($b['old'] ?? '');
            if ($old !== '' && $old !== $n) { if (!isset($c[$old])) fail('Category not found.', 404); if (row('SELECT 1 FROM dd_bk_txns WHERE category=? LIMIT 1', $old)) fail('“' . $old . '” already has entries — renaming would change old reports. Hide it and add a new one.', 409); unset($c[$old]); }
            foreach ($c as $k => $_) if ($k !== $n && mb_strtolower($k) === mb_strtolower($n)) fail('“' . $k . '” already exists.', 409);
            if ($old === '' && isset($c[$n])) fail('“' . $n . '” already exists.', 409);
            $c[$n] = $g; set_setting('bk_categories', json_encode($c, JSON_UNESCAPED_UNICODE)); $after = $c; break;
        case 'category_hide':
            $n = (string)($b['name'] ?? ''); if (!isset(bk_categories()[$n])) fail('Category not found.', 404); $h = f3_hidden('f3_hidden_categories'); $before = $h;
            $h = !empty($b['hidden']) ? array_values(array_unique(array_merge($h, [$n]))) : array_values(array_diff($h, [$n])); set_setting('f3_hidden_categories', json_encode($h, JSON_UNESCAPED_UNICODE)); $after = $h; break;
        case 'location_save':
            $n = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($b['name'] ?? ''))), 0, 60); if (mb_strlen($n) < 2) fail('Location name is required.'); $l = f3_locations(); $before = $l; $old = (string)($b['old'] ?? '');
            foreach ($l as $x) if ($x !== $old && mb_strtolower($x) === mb_strtolower($n)) fail('“' . $x . '” already exists.', 409);
            if ($old !== '') { $i = array_search($old, $l, true); if ($i === false) fail('Location not found.', 404); if (row('SELECT 1 FROM f3_stock_moves WHERE location=? LIMIT 1', $old) || row('SELECT 1 FROM f3_purchase_bills WHERE location=? LIMIT 1', $old)) fail('“' . $old . '” has stock history — it cannot be renamed. Add a new location instead.', 409); $l[$i] = $n; }
            else $l[] = $n; set_setting('f3_stock_locations', json_encode(array_values($l), JSON_UNESCAPED_UNICODE)); $after = $l; break;
        case 'location_remove':
            $n = (string)($b['name'] ?? ''); $l = f3_locations(); $before = $l; if (!in_array($n, $l, true)) fail('Location not found.', 404); if ($n === $l[0]) fail('The main store (first location) cannot be removed.', 409);
            if (row('SELECT 1 FROM f3_stock_balances WHERE location=? AND qty<>0 LIMIT 1', $n)) fail('“' . $n . '” still has stock. Transfer it first.', 409);
            if (row("SELECT 1 FROM f3_stock_counts WHERE location=? AND status IN ('open','submitted') LIMIT 1", $n)) fail('A stock count is open for “' . $n . '”.', 409);
            $l = array_values(array_diff($l, [$n])); set_setting('f3_stock_locations', json_encode($l, JSON_UNESCAPED_UNICODE)); $after = $l; break;
        case 'packs':
            $x = []; foreach ((array)($b['packs'] ?? []) as $p) { $p = strtolower(trim((string)$p)); if ($p === '') continue; if (!preg_match('/^[a-z][a-z \-]{1,19}$/', $p)) fail('Pack name “' . $p . '” — use letters only, up to 20.'); if (isset(F3_STD_UNITS[$p])) fail('“' . $p . '” is a standard unit already.'); $x[] = $p; }
            $before = f3_hidden('f3_pack_units_extra'); foreach ($before as $p) if (!in_array($p, $x, true) && !in_array($p, F3_PACK_UNITS, true) && row('SELECT 1 FROM f3_item_units WHERE unit=? LIMIT 1', $p)) fail('“' . $p . '” is used by items — it cannot be removed.', 409);
            $x = array_values(array_unique(array_diff($x, F3_PACK_UNITS))); set_setting('f3_pack_units_extra', json_encode($x)); $after = $x; break;
        case 'cost_centres':
            $x = array_values(array_unique(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 60), (array)($b['items'] ?? [])), 'strlen'))); if (!$x) fail('Keep at least one cost centre.');
            $before = f3_cost_centres(); set_setting('f3_cost_centres', json_encode($x, JSON_UNESCAPED_UNICODE)); $after = $x; break;
        case 'modules':
            $before = f3_modules(); $m = []; foreach (F3_MODULES as $k => $_) $m[$k] = !empty($b['modules'][$k]) ? 1 : 0;
            if (!$m['purchases'] && row("SELECT 1 FROM f3_purchase_bills WHERE status IN ('draft','review') LIMIT 1") && empty($b['force'])) throw new F3Conflict('There are purchase drafts or bills waiting for approval. Switch purchases off anyway?', ['needs' => 'force']);
            if (!$m['payroll'] && row("SELECT 1 FROM f3_pay_periods WHERE status IN ('draft','review') LIMIT 1") && empty($b['force'])) throw new F3Conflict('A salary month is still in progress. Switch payroll off anyway?', ['needs' => 'force']);
            set_setting('f3_modules', json_encode($m)); f3_perm_cache_clear(); $after = $m; break;
        case 'alerts':
            $before = ['alerts' => f3_alerts_on(), 'draft_days' => setting('f3_draft_alert_days', '1'), 'count_days' => setting('f3_count_every_days', '30')]; $a = []; foreach (F3_ALERTS as $k => $_) $a[$k] = !empty($b['alerts'][$k]) ? 1 : 0;
            $dd = (int)($b['draft_days'] ?? 1); $cd = (int)($b['count_days'] ?? 30); if ($dd < 1 || $dd > 30) fail('Draft reminder: 1–30 days.'); if ($cd < 7 || $cd > 180) fail('Stock count reminder: every 7–180 days.');
            set_setting('f3_alerts', json_encode($a)); set_setting('f3_draft_alert_days', (string)$dd); set_setting('f3_count_every_days', (string)$cd); $after = ['alerts' => $a, 'draft_days' => $dd, 'count_days' => $cd]; break;
        case 'messages':
            $before = f3_messages(); $m = [];
            foreach (F3_MSG_DEFAULTS as $k => $d) { $t = str_replace("\r", '', (string)($b['messages'][$k] ?? '')); if (mb_strlen($t) > 1000) fail('Message is too long (1,000 characters).');
                if (preg_match_all('/\{([a-z_]+)\}/', $t, $mm)) foreach ($mm[1] as $v) if (!in_array($v, F3_MSG_VARS[$k], true)) fail('Unknown placeholder {' . $v . '} in the ' . $k . ' message. Allowed: {' . implode('}, {', F3_MSG_VARS[$k]) . '}.');
                $m[$k] = trim($t) === '' || $t === $d ? null : $t; }
            set_setting('f3_messages', json_encode(array_filter($m), JSON_UNESCAPED_UNICODE)); $after = f3_messages(); break;
        case 'role_save':
            $t = f3_json_setting('f3_custom_templates'); $before = $t; $key = (string)($b['key'] ?? ''); $label = mb_substr(trim((string)($b['label'] ?? '')), 0, 60); if (mb_strlen($label) < 3) fail('Role name is required.');
            if ($key === '') { $key = 'c_' . substr(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), 0, 24); $key = rtrim($key, '_'); $base = $key; $i = 2; while (isset($t[$key]) || isset(f3_templates()[$key])) $key = $base . $i++; if (strlen($key) < 4) $key = 'c_role' . time(); }
            elseif (!isset($t[$key])) fail('Only custom roles can be edited. Copy a built-in role instead.', 409);
            foreach (f3_templates() as $k => $v) if ($k !== $key && mb_strtolower($v[0]) === mb_strtolower($label)) fail('A role called “' . $v[0] . '” already exists.', 409);
            $perms = array_values(array_filter((array)($b['perms'] ?? []), fn($p) => isset(F3_PERMS[$p]))); if (!$perms) fail('Tick at least one permission.');
            $t[$key] = ['label' => $label, 'perms' => $perms]; set_setting('f3_custom_templates', json_encode($t, JSON_UNESCAPED_UNICODE));
            run('UPDATE dd_users u JOIN f3_user_access a ON a.user_id=u.id SET u.session_rev=u.session_rev+1 WHERE a.template=? AND u.id<>?', $key, $u['id']); f3_perm_cache_clear(); $after = $t[$key] + ['key' => $key]; break;
        case 'role_delete':
            $t = f3_json_setting('f3_custom_templates'); $key = (string)($b['key'] ?? ''); if (!isset($t[$key])) fail('Only custom roles can be deleted.', 409);
            $n = (int)row('SELECT COUNT(*) n FROM f3_user_access WHERE template=?', $key)['n']; if ($n) fail($n . ' user(s) still have this role. Move them to another role first.', 409);
            $before = $t[$key]; unset($t[$key]); set_setting('f3_custom_templates', json_encode((object)$t, JSON_UNESCAPED_UNICODE)); $after = null; break;
        default: fail('Unknown settings section.', 404);
    }
    f3_audit($u, 'control.' . $sec, ['before' => $before, 'after' => $after]);
    return ['ok' => true] + f3_control_get($u);
}
