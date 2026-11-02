<?php
declare(strict_types=1);
// KSDD Finance 360 (V16) — core services: permissions, business dates, money, Books bridge, notifications, audit.
// Money = integer paise everywhere (never float). Business timezone = Asia/Kolkata.
// Books 360 (dd_bk_*) is the ONE cash/bank book: every Finance 360 money movement is written there exactly once,
// tagged source='f360' with a unique import_key, so the existing Books reports, party ledgers, month lock,
// audit checks and GL event posting (post360.php) keep working unchanged.
require_once dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/migrations.php';
require_once dirname(__DIR__) . '/lib360.php';
require_once dirname(__DIR__) . '/books360.php';
require_once __DIR__ . '/schema.php';

/** A 409 conflict that carries extra fields for the UI (e.g. the id of a possible duplicate). */
final class F3Conflict extends RuntimeException { public function __construct(string $m, public array $extra = []) { parent::__construct($m, 409); } }

const F3_DEPTS = [1 => ['Management', 'मैनेजमेंट'], 2 => ['UT', 'यूटी'], 3 => ['Kitchen', 'किचन'], 4 => ['Waiter', 'वेटर']];

/* ============================== permissions ============================== */
const F3_PERMS = [
 'dashboard.view' => ['Dashboard', 'View dashboard'],
 'closing.view' => ['Morning Closing', 'View closings'],
 'closing.entry' => ['Morning Closing', 'Enter sales / actual cash (draft)'],
 'closing.finalize' => ['Morning Closing', 'Submit & lock closing'],
 'closing.variance' => ['Morning Closing', 'See Cash Acess / Cash Short and accounting variance'],
 'closing.approve' => ['Morning Closing', 'Review closing & classify variance (any open day)'],
 'closing.reopen' => ['Morning Closing', 'Reopen a locked day (admin correction)'],
 'closing.correction' => ['Morning Closing', 'Request correction of a locked day'],
 'sales.view' => ['Sales', 'View sales history / daybook'],
 'expenses.view' => ['Expenses', 'View expense history'],
 'expenses.create' => ['Expenses', 'Add expenses (open day only)'],
 'expenses.update' => ['Expenses', 'Edit expenses (open day only)'],
 'expenses.void' => ['Expenses', 'Cancel expenses (open day only)'],
 'expenses.today' => ['Expenses', 'Add expenses for the current day'],
 'expenses.review' => ['Expenses', 'Review / flag expenses'],
 'cash.view' => ['Cash Counter', 'View cash counter & movements'],
 'cash.transfer' => ['Cash Counter', 'Record cash deposit / transfer'],
 'accounts.view' => ['Accounts', 'View cashbook, bankbook, ledgers, journals'],
 'accounts.manage' => ['Accounts', 'Manual journals & reconciliation'],
 'suppliers.view' => ['Suppliers', 'View suppliers'],
 'supplier.create' => ['Suppliers', 'Create supplier'],
 'supplier.edit' => ['Suppliers', 'Edit supplier'],
 'supplier.deactivate' => ['Suppliers', 'Deactivate / reactivate supplier'],
 'supplier.outstanding' => ['Suppliers', 'See outstanding, ledger & aging'],
 'supplier.pay' => ['Suppliers', 'Record supplier payments'],
 'supplier.pay_approve' => ['Suppliers', 'Approve payments above own limit'],
 'supplier.adjust' => ['Suppliers', 'Adjust opening balance / credit & debit notes'],
 'purchase.view' => ['Purchases', 'View purchase bills'],
 'purchase.create' => ['Purchases', 'Create draft bills'],
 'purchase.upload' => ['Purchases', 'Upload bill / item photos'],
 'purchase.edit' => ['Purchases', 'Edit draft bills'],
 'purchase.post' => ['Purchases', 'Post (confirm) bills'],
 'purchase.approve' => ['Purchases', 'Approve bills above the approval limit / duplicate invoice override'],
 'purchase.reverse' => ['Purchases', 'Reverse posted bills, purchase returns'],
 'purchase.report' => ['Purchases', 'Purchase reports & exports'],
 'item.view' => ['Items', 'View item master'],
 'item.create' => ['Items', 'Create items'],
 'item.edit' => ['Items', 'Edit items & unit conversions'],
 'item.photo' => ['Items', 'Upload item photos'],
 'item.opening' => ['Items', 'Set / adjust opening stock'],
 'item.deactivate' => ['Items', 'Deactivate items'],
 'stock.view' => ['Stock', 'View stock quantities'],
 'stock.receive' => ['Stock', 'Receive purchased goods into stock'],
 'stock.issue' => ['Stock', 'Issue / consume, wastage'],
 'stock.count' => ['Stock', 'Physical stock count (enter)'],
 'stock.count_approve' => ['Stock', 'Approve stock count'],
 'stock.adjust' => ['Stock', 'Stock adjustment, negative-stock override, reversals'],
 'stock.valuation' => ['Stock', 'See stock value & cost'],
 'stock.export' => ['Stock', 'Stock reports & exports'],
 'pay.cash' => ['Payment sources', 'Pay from Cash'],
 'pay.bank' => ['Payment sources', 'Pay from Bank'],
 'pay.upi' => ['Payment sources', 'Pay from UPI'],
 'pay.card' => ['Payment sources', 'Pay from Card'],
 'staff.view' => ['Staff', 'View staff directory'],
 'staff.manage' => ['Staff', 'Create / edit staff, designations'],
 'staff.bank' => ['Staff', 'See full bank / UPI details'],
 'staff.ledger' => ['Staff', 'View staff ledger'],
 'stafftxn.create' => ['Staff entries', 'Add advance / penalty / overtime / adjustments'],
 'stafftxn.approve' => ['Staff entries', 'Approve / reverse staff entries'],
 'payroll.view' => ['Payroll', 'View salary sheets'],
 'payroll.prepare' => ['Payroll', 'Prepare salary (wizard)'],
 'payroll.approve' => ['Payroll', 'Approve & lock salary'],
 'payroll.pay' => ['Payroll', 'Record salary payments'],
 'payroll.unlock' => ['Payroll', 'Unlock approved salary (with reason)'],
 'reports.view' => ['Reports', 'View reports'],
 'reports.export' => ['Reports', 'Export / print reports'],
 'notifications.view' => ['Notifications', 'See notifications'],
 'users.manage' => ['Admin', 'Users, roles & permissions'],
 'settings.manage' => ['Admin', 'Settings'],
 'audit.view' => ['Admin', 'Audit history'],
 'legacy.access' => ['Admin', 'Old records archive (read-only; owner only while archived)'],
 // V23
 'handover.send' => ['Cash handover', 'Hand over cash to a manager'],
 'handover.receive' => ['Cash handover', 'Receive / confirm cash handovers'],
 'docs.inbox' => ['Document inbox', 'Upload & link bills / receipts'],
 'payroll.ack' => ['Payroll', 'Record salary acknowledgement / dispute'],
 'exceptions.manage' => ['Admin', 'Error resolution centre & month-end checklist'],
];

/* ---------- V17.2: modules on/off and custom roles (managed in Settings → Control centre) ---------- */
const F3_MODULES = ['purchases' => ['Purchases, suppliers & payments', ['purchase.', 'supplier.', 'suppliers.']], 'stock' => ['Items & stock', ['item.', 'stock.']],
    'staff' => ['Staff & advance / penalty / overtime', ['staff.', 'stafftxn.']], 'payroll' => ['Salary & payroll', ['payroll.']]];
function f3_json_setting(string $k, array $def = []): array { try { $v = json_decode(setting($k, ''), true); } catch (Throwable $e) { $v = null; } return is_array($v) ? $v : $def; }
function f3_modules(): array { $m = f3_json_setting('f3_modules'); $o = []; foreach (F3_MODULES as $k => $_) $o[$k] = !isset($m[$k]) || (bool)$m[$k]; return $o; }
/** A switched-off module removes its permissions from every user (owner included) so menus, quick actions and APIs agree. */
function f3_module_blocked(string $perm): bool { $c = f3_modules(); foreach (F3_MODULES as $k => [, $pre]) if (!$c[$k]) foreach ($pre as $p) if (str_starts_with($perm, $p)) return true; return false; }
/* V29: subscription plan modules. A module the restaurant's plan does not include removes its permissions for EVERY user
   (owner included), exactly like a switched-off module — so web menus, Flutter menus (/permissions/me) and APIs agree. */
const F3_PLAN_MODULE_PERMS = ['closing' => ['closing.', 'sales.', 'cash.', 'handover.'], 'expenses' => ['expenses.'], 'staff' => ['staff.', 'stafftxn.'], 'payroll' => ['payroll.'],
    'purchases' => ['purchase.', 'supplier.', 'suppliers.', 'docs.'], 'stock' => ['item.', 'stock.'], 'reports' => ['reports.', 'accounts.']];
function f3_plan_blocked(string $perm): bool {
    if (!function_exists('platform_mode') || !platform_mode()) return false;
    $m = db_target()['entitlement']['modules'] ?? null; if (!is_array($m)) return false;
    foreach (F3_PLAN_MODULE_PERMS as $k => $pre) if (array_key_exists($k, $m) && !$m[$k]) foreach ($pre as $p) if (str_starts_with($perm, $p)) return true;
    return false;
}
function f3_custom_templates(): array { $t = f3_json_setting('f3_custom_templates'); $o = []; foreach ($t as $k => $v) if (preg_match('/^c_[a-z0-9_]{2,30}$/', (string)$k) && is_array($v) && isset($v['label'], $v['perms'])) $o[$k] = [(string)$v['label'], array_values(array_filter((array)$v['perms'], fn($p) => isset(F3_PERMS[$p])))]; return $o; }
function f3_templates(): array {
    $all = array_keys(F3_PERMS);
    $counter = ['dashboard.view','closing.view','closing.entry','closing.finalize','closing.variance','closing.correction','sales.view','expenses.view','expenses.create','expenses.update','expenses.today','notifications.view','handover.send','docs.inbox'];
    $acct = array_values(array_diff($all, ['users.manage','settings.manage','payroll.unlock','closing.reopen','legacy.access']));
    $store = ['dashboard.view','suppliers.view','supplier.create','purchase.view','purchase.create','purchase.upload','purchase.edit','item.view','item.create','item.photo','stock.view','stock.receive','stock.issue','stock.count','notifications.view','docs.inbox'];
    $payroll = ['dashboard.view','staff.view','staff.manage','staff.ledger','stafftxn.create','payroll.view','payroll.prepare','reports.view','notifications.view'];
    $approver = array_merge($payroll, ['staff.bank','stafftxn.approve','payroll.approve','payroll.pay','reports.export','payroll.ack']);
    return [
     'owner' => ['Owner / Admin', $all],
     'accountant' => ['Accountant / Manager', $acct],
     'counter' => ['Counter (closing + expenses)', $counter],
     'cash_counter' => ['Cash Counter (daily closing only)', ['closing.view', 'closing.entry', 'closing.finalize', 'closing.variance', 'notifications.view']], // V29 §7
     'payroll' => ['Payroll / Staff Manager (prepare)', $payroll],
     'payroll_approver' => ['Payroll Approver', $approver],
     'store' => ['Store clerk (purchases & stock entry)', $store],
     'viewer' => ['Reports viewer', ['dashboard.view','sales.view','expenses.view','reports.view','notifications.view']],
     'none' => ['No Finance 360 access (legacy module user)', []],
    ] + f3_custom_templates();
}
const F3_ROLE_TEMPLATE = ['owner' => 'owner', 'manager' => 'accountant', 'accountant' => 'accountant', 'cashier' => 'counter', 'counter' => 'counter', 'storekeeper' => 'store', 'purchase' => 'store', 'chef' => 'store']; // V17.1: old modules archived → store / kitchen staff use Finance 360
function f3_template_of(array $u): string {
    $r = row('SELECT template FROM f3_user_access WHERE user_id=?', (int)$u['id']);
    if ($r && isset(f3_templates()[$r['template']])) return $r['template'];
    return F3_ROLE_TEMPLATE[$u['role'] ?? ''] ?? 'none';
}
function f3_perms(array $u): array {
    $cache = &$GLOBALS['F3_PERM_CACHE']; $cache ??= [];
    $id = (int)($u['id'] ?? 0);
    if (isset($cache[$id])) return $cache[$id];
    if (($u['role'] ?? '') === 'owner') return $cache[$id] = array_values(array_filter(array_keys(F3_PERMS), fn($p) => !f3_module_blocked($p) && !f3_plan_blocked($p))); // the owner can never be locked out (only switched-off modules are hidden)
    $tpl = f3_template_of($u); $p = array_fill_keys(f3_templates()[$tpl][1], true);
    $r = row('SELECT overrides FROM f3_user_access WHERE user_id=?', $id);
    $o = $r ? (json_decode((string)$r['overrides'], true) ?: []) : [];
    foreach ($o as $k => $v) if (isset(F3_PERMS[$k])) { if ($v) $p[$k] = true; else unset($p[$k]); }
    return $cache[$id] = array_values(array_filter(array_keys($p), fn($x) => !f3_module_blocked($x) && !f3_plan_blocked($x)));
}
function f3_perm_cache_clear(): void { $GLOBALS['F3_PERM_CACHE'] = []; }
function f3_can(array $u, string $p): bool { return in_array($p, f3_perms($u), true); }
function f3_need(array $u, string ...$any): void { foreach ($any as $p) if (f3_can($u, $p)) return; fail('You do not have permission for this action (' . implode(' / ', $any) . ').', 403); }

/* ============================== settings ============================== */
const F3_SETTING_DEFAULTS = [
 'business_day_cutoff_hour' => '4',      // shared with legacy CRM: before this hour, the previous calendar date is still the business day
 'f3_popup_hour' => '6',                 // Morning Closing popup from 06:00 Asia/Kolkata
 'f3_closing_start' => '',               // first business date that requires a Finance 360 closing (set at migration)
 'f3_counter_backlog_days' => '1',       // how many past days a counter may close (1 = yesterday only)
 'f3_snooze_minutes' => '120',           // "Add later" hides the popup for this long (banner stays)
 'f3_diff_reason_above' => '50000',      // paise; reason required when |Cash Acess/Short| is above this (0 = always, -1 = never)
 'f3_opening_mode' => 'books',           // books = Books cash balance before the day; zero = drawer starts empty each day
 'legacy_archived' => '1', 'f3_rest_days_paid' => '1', 'f3_purchase_approval_above' => '0', 'f3_negative_stock' => '0', 'f3_tax_in_cost' => '1', 'f3_require_utr' => '0', 'f3_stock_locations' => '["Main Store"]',
 'f3_acct_cash' => 'Cash', 'f3_acct_card' => 'Card', 'f3_acct_upi' => 'UPI / Paytm', 'f3_acct_bank' => 'Bank of India',
 'f3_expense_proof_above' => '0',        // paise; 0 = proof optional
 'f3_payroll_divisor' => 'calendar',     // calendar = days in month (28/29/30/31) · fixed30 = always 30
 'f3_payroll_separate_approver' => '0',  // 1 = the person who prepared a sheet cannot approve it
 'f3_strict_gl' => '0',                 // V23: 1 = a salary month cannot be locked while its ledger journal is pending
 'f3_payroll_reminder_hour' => '6',
 'f3_payroll_start' => '',               // first payroll period handled by Finance 360 (YYYY-MM)
 'f3_half_day_value' => '0.5',
 'f3_advance_limit_pct' => '50',      // advance balance above this % of monthly salary needs a written reason (0 = no limit)
 'f3_default_paid_leave' => '2',      // pre-filled “paid leave allowed” in the salary wizard / quick grid (most rows in the Excel sheet use 2)
 'f3_leave_encash' => '1',             // 1 = unused paid-leave allowance is paid as extra days ("Leave Encash" in KSDD Sallery 2026.xlsx)
];
function f3_set(string $k): string { return setting($k, F3_SETTING_DEFAULTS[$k] ?? ''); }
function f3_set_int(string $k): int { return (int)f3_set($k); }

/* ============================== clock & business dates ============================== */
function f3_now(): DateTimeImmutable {
    $tz = new DateTimeZone('Asia/Kolkata');
    $c = cfg();
    // Staging/test hook only: honoured solely when config.php explicitly enables it. Production config never has it.
    if (!empty($c['f3_allow_fake_clock'])) {
        $f = getenv('F3_FAKE_NOW') ?: ($c['f3_fake_now'] ?? '');
        if ($f === '' && is_file(dirname(__DIR__) . '/private/f3_fake_now.txt')) $f = trim((string)file_get_contents(dirname(__DIR__) . '/private/f3_fake_now.txt'));
        if ($f !== '') return new DateTimeImmutable($f, $tz);
    }
    return new DateTimeImmutable('now', $tz);
}
function f3_business_date(?DateTimeImmutable $n = null): string { $n ??= f3_now(); return $n->modify('-' . max(0, min(12, f3_set_int('business_day_cutoff_hour'))) . ' hours')->format('Y-m-d'); }
function f3_target_date(?DateTimeImmutable $n = null): string { return next_date(f3_business_date($n), -1); }
function f3_popup_due(?DateTimeImmutable $n = null): bool {
    $n ??= f3_now(); $bd = f3_business_date($n);
    $t = new DateTimeImmutable($bd . ' ' . sprintf('%02d:00', max(0, min(23, f3_set_int('f3_popup_hour')))), new DateTimeZone('Asia/Kolkata'));
    if ($t->format('G') < f3_set_int('business_day_cutoff_hour')) $t = $t->modify('+1 day');
    return $n >= $t;
}
function f3_closing_start(): string { $s = f3_set('f3_closing_start'); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : f3_target_date(); }
function f3_prev_month(string $m): string { return (new DateTimeImmutable($m . '-01'))->modify('-1 month')->format('Y-m'); }
function f3_month_last(string $m): string { return (new DateTimeImmutable($m . '-01'))->format('Y-m-t'); }

/* ============================== money / input ============================== */
function f3_paise(mixed $v, string $label, bool $required = true): ?int {
    if ($v === null || $v === '') { if ($required) fail($label . ' is required.'); return null; }
    if (is_int($v)) $v = (string)$v;
    if (!is_string($v) && !is_float($v)) fail($label . ': enter a valid amount.');
    $s = trim(str_replace([',', '₹', ' '], '', (string)$v));
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $s)) fail($label . ': enter a non-negative amount with at most 2 decimals.');
    [$w, $f] = array_pad(explode('.', $s, 2), 2, '');
    $n = (int)$w * 100 + (int)str_pad($f, 2, '0');
    if ($n > 100000000000) fail($label . ' is too large.');
    return $n;
}
function f3_days(mixed $v, string $label, float $max = 31): string {
    $s = trim((string)($v ?? '')); if ($s === '') $s = '0';
    if (!preg_match('/^\d{1,2}(?:\.(?:0|00|5|50))?$/', $s)) fail($label . ': use whole or half days (e.g. 2 or 2.5).');
    if ((float)$s > $max) fail($label . ' cannot be more than ' . $max . '.');
    return number_format((float)$s, 2, '.', '');
}
function f3_hund(string $d): int { return (int)round((float)$d * 100); } // days are stored as DECIMAL(5,2) with only .0/.5 allowed → exact
function f3_rs(int $p): string { return bk_r($p); }
function f3_rs2(int $p): string { $neg = $p < 0; $p = abs($p); $r = intdiv($p, 100); $s = (string)$r; $l = strlen($s); if ($l > 3) { $h = substr($s, 0, $l - 3); $s = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $h) . ',' . substr($s, -3); } return ($neg ? '-' : '') . '₹' . $s . '.' . str_pad((string)($p % 100), 2, '0', STR_PAD_LEFT); }
function f3_text(mixed $v, int $max = 250, ?string $requiredLabel = null): string { $s = txt($v, $max); if ($requiredLabel && $s === '') fail($requiredLabel . ' is required.'); return $s; }
function f3_audit(array $u, string $action, array $d = []): void { audit($u, 'f360.' . $action, $d); }

/* ============================== Books bridge ============================== */
function f3_acct(string $kind): string { // cash|card|upi|bank → Books account name
    $a = f3_set('f3_acct_' . $kind); if (!in_array($a, bk_accounts(), true)) fail('Account mapping for ' . $kind . ' ("' . $a . '") is not a Books account. Owner: Settings → Accounts mapping.', 503);
    return $a;
}
function f3_mode_acct(string $mode): string { return match ($mode) { 'Cash' => f3_acct('cash'), 'Bank' => f3_acct('bank'), 'UPI' => f3_acct('upi'), 'Card' => f3_acct('card'), default => fail('Choose payment mode Cash / Bank / UPI.') }; }
/** Insert one Books transaction for a Finance 360 source. Idempotent on $key (unique import_key). */
function f3_bk_post(string $key, array $t, array $u): int {
    $ex = row('SELECT id,voided FROM dd_bk_txns WHERE import_key=?', $key);
    if ($ex) { if ((int)$ex['voided']) fail('Posting key ' . $key . ' was cancelled earlier; a new revision is required.', 409); return (int)$ex['id']; }
    bk_check_open($t['date']);
    if (!isset(BK_TYPES[$t['type']])) fail('Invalid Books type.');
    if (!in_array($t['account'], bk_accounts(), true)) fail('Invalid account.');
    run('INSERT INTO dd_bk_txns(date,type,party_id,category,total,paid,account,to_account,bill_no,description,source,import_key,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
        $t['date'], $t['type'], $t['party_id'] ?? null, $t['category'] ?? null, $t['total'], $t['paid'] ?? $t['total'], $t['account'], $t['to_account'] ?? null, $t['bill_no'] ?? null, txt($t['description'] ?? '', 500) ?: null, 'f360', $key, $u['id'] ?? null);
    $id = (int)db()->lastInsertId();
    audit($u, 'books.txn.create', ['id' => $id, 'type' => $t['type'], 'total' => $t['total'], 'via' => 'f360', 'key' => $key]);
    return $id;
}
function f3_bk_void(?int $id, string $why, array $u): void {
    if (!$id) return; $t = row('SELECT * FROM dd_bk_txns WHERE id=? FOR UPDATE', $id); if (!$t || (int)$t['voided']) return;
    bk_check_open($t['date']);
    run('UPDATE dd_bk_txns SET voided=1,void_reason=? WHERE id=?', txt($why, 250), $id);
    audit($u, 'books.txn.void', ['id' => $id, 'reason' => $why, 'via' => 'f360']);
}
function f3_staff_party(int $emp): int {
    $r = row("SELECT id FROM dd_bk_parties WHERE employee_id=? AND merged_into IS NULL ORDER BY id LIMIT 1", $emp); if ($r) return (int)$r['id'];
    $e = row('SELECT name,code FROM dd_employees WHERE id=?', $emp); if (!$e) fail('Employee not found.', 404);
    $name = 'Staff ' . $e['name'] . ($e['code'] ? ' (' . $e['code'] . ')' : ''); $k = bk_key($name);
    $p = row('SELECT id,employee_id FROM dd_bk_parties WHERE name_key=?', $k);
    if ($p) { if (!$p['employee_id']) run('UPDATE dd_bk_parties SET employee_id=?,kind=? WHERE id=?', $emp, 'staff', $p['id']); return (int)$p['id']; }
    run("INSERT INTO dd_bk_parties(name,name_key,kind,employee_id) VALUES(?,?,'staff',?)", txt($name, 160), $k, $emp);
    return (int)db()->lastInsertId();
}

/* ============================== day locking ============================== */
/** Can this user add/change money entries dated $d? Returns null if yes, else the reason. */
function f3_day_block_reason(string $d, array $u, string $purpose = 'expense'): ?string {
    $bd = f3_business_date();
    if ($d > $bd) return 'Future dates are not allowed.';
    if (bk_month_status($d) === 'locked') return 'Books for ' . substr($d, 0, 7) . ' are locked.';
    $c = row('SELECT status FROM f3_closings WHERE business_date=?', $d);
    if ($c && in_array($c['status'], ['submitted', 'approved'], true)) return 'Day ' . $d . ' is closed and locked. Ask an admin to reopen it (Request correction).';
    if (f3_can($u, 'closing.approve')) return null; // admins/accountants: any open (not finalized) day
    if ($d === $bd) return ($purpose === 'expense' && !f3_can($u, 'expenses.today')) ? 'You can add today\'s expenses only with permission.' : null;
    $back = max(1, f3_set_int('f3_counter_backlog_days'));
    if ($d < next_date($bd, -$back)) return 'You can only enter data for the last ' . $back . ' day(s). Ask an admin.';
    if ($d < f3_closing_start()) return 'This date is before Finance 360 closings started.';
    return null;
}
function f3_day_guard(string $d, array $u, string $purpose = 'expense'): void { $r = f3_day_block_reason($d, $u, $purpose); if ($r) fail($r, 409); }

/* ============================== notifications ============================== */
function f3_notify(string $kind, string $dedupe, string $title, string $body, string $perm, ?string $ref = null): void {
    run('INSERT IGNORE INTO f3_notifications(kind,dedupe_key,title,body,perm,ref) VALUES(?,?,?,?,?,?)', $kind, $dedupe, txt($title, 200), txt($body, 1000), $perm, $ref);
}
function f3_notify_done(string $dedupe): void { run("UPDATE f3_notifications SET status='done',done_at=UTC_TIMESTAMP() WHERE dedupe_key=? AND status='open'", $dedupe); }

/* ============================== uploads ============================== */
function f3_upload(?array $f, array $u): ?int { if (!$f || empty($f['data'])) return null; return store_upload(['data' => $f['data'], 'name' => $f['name'] ?? 'proof'], $u); }

/* ============================== PII ============================== */
/** V23 P0-01 key ring. Primary (used to ENCRYPT and for phone hashes) = pii_key, else setup_key (the historical fallback).
 *  Decryption also tries pii_key_previous and setup_key, so adding a NEW pii_key never makes old bank / Drive / WhatsApp secrets unreadable.
 *  Move old values to the new key with tools/f360-rekey.php (dry run first). Never delete the old key from config.php until it reports 0 legacy values. */
function f3_pii_keys(): array { $c = cfg(); $o = [];
    // V29: every branch other than the original restaurant gets its OWN derived key (scope = its storage id), so a
    // ciphertext copied between restaurant databases cannot be decrypted there. The original restaurant keeps its keys.
    $scope = function_exists('platform_mode') && platform_mode() ? (string)(db_target()['storage'] ?? '') : '';
    if ($scope !== '') { $k = (string)($c['pii_key'] ?? $c['setup_key'] ?? ''); if (strlen($k) >= 24 && !preg_match('/^(REPLACE_|ONLY_WHEN)/', $k)) return [hash('sha256', 'f3-pii|' . $k . '|' . $scope, true)]; return []; } foreach ([$c['pii_key'] ?? null, $c['pii_key_previous'] ?? null, $c['setup_key'] ?? null] as $k) { $k = (string)$k; if (strlen($k) < 24 || preg_match('/^(REPLACE_|ONLY_WHEN)/', $k)) continue; /* placeholders from config.example.php are never used as keys */ $h = hash('sha256', 'f3-pii|' . $k, true); if (!in_array($h, $o, true)) $o[] = $h; } return $o; }
function f3_pii_key(): ?string { return f3_pii_keys()[0] ?? null; }
function f3_enc(string $plain): ?string { $k = f3_pii_key(); if (!$k || $plain === '') return null; $iv = random_bytes(12); $tag = ''; $c = openssl_encrypt($plain, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag); return 'v1:' . base64_encode($iv . $tag . $c); }
function f3_dec_with(string $enc, string $k): ?string { $b = base64_decode(substr($enc, 3), true); if ($b === false || strlen($b) < 29) return null; $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16)); return $p === false ? null : $p; }
function f3_dec(?string $enc): ?string { if (!$enc || !str_starts_with($enc, 'v1:')) return null; foreach (f3_pii_keys() as $k) { $p = f3_dec_with($enc, $k); if ($p !== null) return $p; } return null; }
function f3_mask(?string $s, int $keep = 4): string { $s = (string)$s; if ($s === '') return ''; $l = mb_strlen($s); return $l <= $keep ? str_repeat('•', $l) : str_repeat('•', min(8, $l - $keep)) . mb_substr($s, -$keep); }

/* Per-user payment limits (owner sets in Users): {"pay_max": paise, 0 = no limit}. */
function f3_user_limits(array $u): array { $r = row('SELECT limits FROM f3_user_access WHERE user_id=?', (int)$u['id']); $l = $r ? (json_decode((string)$r['limits'], true) ?: []) : []; return $l + ['pay_max' => 0]; }
function f3_pay_guard(array $u, string $mode, int $amount): void {
    $p = ['Cash' => 'pay.cash', 'Bank' => 'pay.bank', 'UPI' => 'pay.upi', 'Card' => 'pay.card'][$mode] ?? null; if (!$p) fail('Choose payment mode Cash / Bank / UPI / Card.');
    f3_need($u, $p);
    $max = (int)f3_user_limits($u)['pay_max']; if ($max > 0 && $amount > $max && !f3_can($u, 'supplier.pay_approve')) fail('This payment (' . f3_rs($amount) . ') is above your limit of ' . f3_rs($max) . '. Ask an approver.', 403);
}
