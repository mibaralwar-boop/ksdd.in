<?php
declare(strict_types=1);
// Finance 360 — Morning Closing (yesterday's Cash / Card / UPI sales → expense review → Actual Cash → Cash Acess / Cash Short → lock).
// TWO separate figures are always computed and labelled:
//  (1) OPERATIONAL DIFFERENCE (owner's requested management metric) = Actual Cash − (Total Sale − Cash expenses & cash payouts).
//      Positive → "Cash Acess", negative → "Cash Short". Card & UPI are part of Total Sale, so this is NOT a physical over/short.
//  (2) ACCOUNTING CASH VARIANCE = Actual Cash − Expected drawer cash, where
//      Expected = Opening cash + Cash sale + other cash received − all cash paid out (Books 'Cash' account movements of that day).
// Neither figure is posted as income/expense automatically. An admin classifies it in Review (posts one Books entry, or none).
require_once __DIR__ . '/core.php';

const F3_SALE_KEY = 'f360:sale:';
const F3_VAR_KEY = 'f360:var:';

/** Figures received from the restaurant PC's Petpooja sync (sync.php → dd_pos_days), if any. Read-only: used to pre-fill and cross-check. */
function f3_petpooja(string $d): ?array {
    try { $r = row('SELECT business_date,cash,card,upi,other,net,status,imported_at,source FROM dd_pos_days WHERE business_date=?', $d); } catch (PDOException $e) { return null; }
    if (!$r) return null; foreach (['cash', 'card', 'upi', 'other', 'net'] as $k) $r[$k] = (int)$r[$k];
    $r['total'] = $r['cash'] + $r['card'] + $r['upi'] + $r['other']; return $r;
}
function f3_closing_row(string $d, bool $lock = false): ?array { return row('SELECT * FROM f3_closings WHERE business_date=?' . ($lock ? ' FOR UPDATE' : ''), $d); }

/** All Books movements of the day, split the way the closing needs them. Pure read. */
function f3_day_figures(string $d): array {
    $cash = f3_set('f3_acct_cash');
    $tx = bk_txn_rows('t.date=? AND t.voided=0', [$d]);
    $f = ['expenses' => [], 'payouts' => [], 'receipts' => [], 'legacy_sales' => [], 'expenses_total' => 0, 'expenses_cash' => 0, 'other_cash_in' => 0, 'cash_out' => 0, 'legacy_sales_total' => 0];
    foreach ($tx as $t) {
        $key = (string)($t['import_key'] ?? '');
        if (str_starts_with($key, F3_SALE_KEY) || str_starts_with($key, F3_VAR_KEY)) continue; // the closing's own postings
        $eff = bk_effect($t, $cash);
        $item = ['id' => $t['id'], 'type' => $t['type'], 'label' => BK_TYPES[$t['type']][0] ?? $t['type'], 'category' => $t['category'], 'party' => $t['party'], 'account' => $t['account'], 'to_account' => $t['to_account'],
                 'total' => $t['total'], 'paid' => $t['paid'], 'cash_effect' => $eff, 'description' => $t['description'], 'source' => $t['source'], 'created_by' => $t['created_by']];
        if ($t['type'] === 'sale') { $f['legacy_sales'][] = $item; $f['legacy_sales_total'] += $t['total']; continue; } // counted by the closing's own Cash/Card/UPI instead
        if ($t['type'] === 'expense') { $f['expenses'][] = $item; $f['expenses_total'] += $t['total']; if ($t['account'] === $cash) $f['expenses_cash'] += $t['paid']; }
        elseif ($eff < 0) $f['payouts'][] = $item;
        elseif ($eff > 0) $f['receipts'][] = $item;
        if ($eff > 0) $f['other_cash_in'] += $eff; else $f['cash_out'] -= $eff;
    }
    $f['opening'] = f3_set('f3_opening_mode') === 'zero' ? 0 : bk_opening_before($d, $cash);
    // Opening must not include this day's or later postings; bk_opening_before is strictly "before $d".
    return $f;
}
/** Pure calculation, shared by draft preview, finalize and tests. */
function f3_closing_calc(?int $cashSale, ?int $cardSale, ?int $upiSale, ?int $actual, array $f): array {
    $total = ($cashSale ?? 0) + ($cardSale ?? 0) + ($upiSale ?? 0);
    $expected = $f['opening'] + ($cashSale ?? 0) + $f['other_cash_in'] - $f['cash_out'];
    $op = $actual === null ? null : $actual - ($total - $f['cash_out']);
    $acc = $actual === null ? null : $actual - $expected;
    $label = fn(?int $v) => $v === null ? null : ($v > 0 ? 'Cash Acess' : ($v < 0 ? 'Cash Short' : 'Balanced'));
    return ['total_sale' => $total, 'expected_cash' => $expected, 'operational_diff' => $op, 'operational_label' => $label($op), 'operational_abs' => $op === null ? null : abs($op),
            'accounting_variance' => $acc, 'accounting_label' => $label($acc), 'accounting_abs' => $acc === null ? null : abs($acc),
            'formula_operational' => 'Actual Cash − (Total Sale − Cash expenses & payouts)', 'formula_accounting' => 'Actual Cash − (Opening + Cash Sale + Other cash in − Cash paid out)'];
}
function f3_closing_state(string $d, array $u): array {
    f3_need($u, 'closing.view');
    valid_date($d);
    $c = f3_closing_row($d); $f = f3_day_figures($d);
    $calc = f3_closing_calc($c ? ($c['cash_sale'] === null ? null : (int)$c['cash_sale']) : null, $c ? ($c['card_sale'] === null ? null : (int)$c['card_sale']) : null, $c ? ($c['upi_sale'] === null ? null : (int)$c['upi_sale']) : null, $c && $c['actual_cash'] !== null ? (int)$c['actual_cash'] : null, $f);
    $locked = $c && in_array($c['status'], ['submitted', 'approved'], true);
    if ($locked && $c['snapshot']) { $snap = json_decode((string)$c['snapshot'], true) ?: []; $calc = $snap['calc'] ?? $calc; $f = array_replace($f, $snap['figures'] ?? []); }
    $block = f3_day_block_reason($d, $u, 'closing');
    $seeVar = f3_can($u, 'closing.variance');
    $out = ['date' => $d, 'target_date' => f3_target_date(), 'business_date' => f3_business_date(), 'status' => $c['status'] ?? 'not_started', 'locked' => $locked, 'revision' => (int)($c['revision'] ?? 1), 'version' => (int)($c['version'] ?? 0), 'step' => (int)($c['step'] ?? 1),
        'can_edit' => !$locked && $block === null && f3_can($u, 'closing.entry'), 'block_reason' => $locked ? 'This day is closed and locked.' : $block,
        'can_finalize' => f3_can($u, 'closing.finalize'), 'can_review' => f3_can($u, 'closing.approve'), 'can_reopen' => f3_can($u, 'closing.reopen'), 'can_correction' => f3_can($u, 'closing.correction'),
        'sales' => ['cash' => $c && $c['cash_sale'] !== null ? (int)$c['cash_sale'] : null, 'card' => $c && $c['card_sale'] !== null ? (int)$c['card_sale'] : null, 'upi' => $c && $c['upi_sale'] !== null ? (int)$c['upi_sale'] : null, 'total' => $calc['total_sale']],
        'expenses_confirmed' => $c['expenses_confirmed'] ?? null, 'actual_cash' => $c && $c['actual_cash'] !== null ? (int)$c['actual_cash'] : null, 'diff_reason' => $c['diff_reason'] ?? null,
        'expenses' => $f['expenses'], 'expenses_total' => $f['expenses_total'], 'expenses_cash' => $f['expenses_cash'], 'payouts' => $f['payouts'], 'receipts' => $f['receipts'], 'cash_out' => $f['cash_out'], 'other_cash_in' => $f['other_cash_in'],
        'legacy_sales' => $f['legacy_sales'], 'legacy_sales_total' => $f['legacy_sales_total'], 'sales_post_status' => $c['sales_post_status'] ?? 'none',
        'reason_required_above' => f3_set_int('f3_diff_reason_above'),
        'petpooja' => f3_petpooja($d),
        'submitted_at' => $c['submitted_at'] ?? null, 'submitted_by' => $c && $c['submitted_by'] ? (row('SELECT name FROM dd_users WHERE id=?', $c['submitted_by'])['name'] ?? null) : null,
        'variance_class' => $c['variance_class'] ?? 'pending', 'review_note' => $c['review_note'] ?? null, 'reopen_reason' => $c['reopen_reason'] ?? null,
        'events' => $c ? rows('SELECT e.action,e.revision,e.note,e.at,u.name user FROM f3_closing_events e LEFT JOIN dd_users u ON u.id=e.user_id WHERE e.closing_id=? ORDER BY e.id', $c['id']) : [],
        'corrections' => rows("SELECT id,reason,status,requested_at FROM f3_corrections WHERE kind='closing' AND business_date=? ORDER BY id DESC", $d)];
    // Cash Acess / Short is shown to the counter while entering (owner's requested UX) only when the user may see variance; always on the server-side figures for admins.
    if ($seeVar) $out['calc'] = $calc + ['opening' => $f['opening']];
    else $out['calc'] = ['total_sale' => $calc['total_sale']];
    return $out;
}
function f3_closing_event(array $c, string $action, array $u, ?string $note = null, ?array $snap = null): void {
    run('INSERT INTO f3_closing_events(closing_id,business_date,action,revision,note,snapshot,user_id) VALUES(?,?,?,?,?,?,?)', $c['id'], $c['business_date'], $action, $c['revision'], $note ? txt($note, 500) : null, $snap ? json_encode($snap, JSON_UNESCAPED_UNICODE) : null, $u['id']);
}
/** Save a draft step (any subset of fields). Optimistic concurrency on `version`. */
function f3_closing_save(array $b, array $u): array {
    f3_need($u, 'closing.entry'); $d = valid_date($b['date'] ?? '');
    run('INSERT IGNORE INTO f3_closings(business_date,status,created_by) VALUES(?,?,?)', $d, 'draft', $u['id']);
    $c = f3_closing_row($d, true);
    if (in_array($c['status'], ['submitted', 'approved'], true)) fail('This day is closed and locked. Use “Request correction”.', 409);
    f3_day_guard($d, $u, 'closing');
    if (isset($b['version']) && (int)$b['version'] > 0 && (int)$b['version'] !== (int)$c['version']) fail('Someone else changed this closing a moment ago. Reload to see the latest figures.', 409);
    $set = []; $args = [];
    foreach (['cash_sale' => 'Cash Sale', 'card_sale' => 'Card Sale', 'upi_sale' => 'UPI Sale', 'actual_cash' => 'Actual Cash'] as $k => $lbl) if (array_key_exists($k, $b)) { $set[] = "$k=?"; $args[] = f3_paise($b[$k], $lbl, false); }
    if (array_key_exists('expenses_confirmed', $b)) { $v = $b['expenses_confirmed']; if (!in_array($v, [null, '', 'reviewed', 'none'], true)) fail('Invalid expense confirmation.'); $set[] = 'expenses_confirmed=?'; $args[] = $v ?: null; }
    if (array_key_exists('diff_reason', $b)) { $set[] = 'diff_reason=?'; $args[] = txt($b['diff_reason'], 500) ?: null; }
    if (isset($b['step'])) { $set[] = 'step=?'; $args[] = max(1, min(6, (int)$b['step'])); }
    if ($set) { $set[] = 'version=version+1'; $set[] = 'updated_by=?'; $args[] = $u['id']; run('UPDATE f3_closings SET ' . implode(',', $set) . ' WHERE id=?', ...[...$args, $c['id']]); }
    if (($c['version'] ?? 1) == 1 && $c['status'] === 'draft' && !row('SELECT 1 FROM f3_closing_events WHERE closing_id=?', $c['id'])) f3_closing_event($c, 'draft_started', $u);
    return f3_closing_state($d, $u);
}
/** Validation used by finalize AND by the smart assistant (so the popup shows exactly what the server will refuse). */
function f3_closing_issues(array $c, array $f, array $calc, bool $seeVar = true): array {
    $i = [];
    foreach (['cash_sale' => 'Cash Sale', 'card_sale' => 'Card Sale', 'upi_sale' => 'UPI Sale'] as $k => $l) if ($c[$k] === null) $i[] = ['level' => 'block', 'code' => $k, 'msg' => $l . ' is not entered (enter 0 if there was none).', 'step' => 2];
    if (!$c['expenses_confirmed']) $i[] = ['level' => 'block', 'code' => 'expenses', 'msg' => 'Review the day\'s expenses (or confirm “No expenses”).', 'step' => 4];
    if ($c['expenses_confirmed'] === 'none' && count($f['expenses']) > 0) $i[] = ['level' => 'block', 'code' => 'expenses_none', 'msg' => '“No expenses” was confirmed but ' . count($f['expenses']) . ' expense(s) exist for this day. Review them instead.', 'step' => 4];
    if ($c['actual_cash'] === null) $i[] = ['level' => 'block', 'code' => 'actual_cash', 'msg' => 'Actual Cash (physically counted) is not entered.', 'step' => 5];
    $th = f3_set_int('f3_diff_reason_above');
    if ($seeVar && $calc['operational_diff'] !== null && $th >= 0 && abs($calc['operational_diff']) > $th && trim((string)$c['diff_reason']) === '') $i[] = ['level' => 'block', 'code' => 'diff_reason', 'msg' => 'Write a reason for the ' . $calc['operational_label'] . ' of ' . f3_rs(abs($calc['operational_diff'])) . '.', 'step' => 5];
    if ($f['legacy_sales']) $i[] = ['level' => 'review', 'code' => 'legacy_sales', 'msg' => 'Books already has ' . count($f['legacy_sales']) . ' sale entr' . (count($f['legacy_sales']) > 1 ? 'ies' : 'y') . ' (' . f3_rs($f['legacy_sales_total']) . ') for this date. The closing sales will be held for admin review to avoid double counting.', 'step' => 2];
    $total = (int)($c['cash_sale'] ?? 0) + (int)($c['card_sale'] ?? 0) + (int)($c['upi_sale'] ?? 0);
    if ($c['cash_sale'] !== null && $c['card_sale'] !== null && $c['upi_sale'] !== null && $total === 0) $i[] = ['level' => 'review', 'code' => 'zero_sales', 'msg' => 'Total Sale is ₹0. Confirm the restaurant was closed this day.', 'step' => 2];
    $pp = f3_petpooja($c['business_date']);
    if ($pp && $c['cash_sale'] !== null && $c['card_sale'] !== null && $c['upi_sale'] !== null) { $dd = ['Cash' => (int)$c['cash_sale'] - $pp['cash'], 'Card' => (int)$c['card_sale'] - $pp['card'], 'UPI' => (int)$c['upi_sale'] - $pp['upi']]; $bad = array_filter($dd, fn($v) => abs($v) >= 100);
        if ($bad) $i[] = ['level' => 'review', 'code' => 'petpooja_diff', 'msg' => 'Entered sales differ from the Petpooja upload: ' . implode(', ', array_map(fn($k, $v) => $k . ' ' . ($v > 0 ? '+' : '−') . f3_rs(abs($v)), array_keys($bad), $bad)) . '. Check the day report.', 'step' => 2]; }
    foreach ($f['expenses'] as $e) if (!$e['category']) $i[] = ['level' => 'review', 'code' => 'uncategorised', 'msg' => 'Expense #' . $e['id'] . ' has no category.', 'step' => 4];
    return $i;
}
function f3_closing_finalize(array $b, array $u): array {
    f3_need($u, 'closing.finalize'); $d = valid_date($b['date'] ?? '');
    return idem('f3-close-' . $d, $b, $u, function () use ($b, $u, $d) {
        $c = f3_closing_row($d, true); if (!$c) fail('Nothing entered for this day yet.', 409);
        if (in_array($c['status'], ['submitted', 'approved'], true)) fail('This day was already submitted by ' . (row('SELECT name FROM dd_users WHERE id=?', $c['submitted_by'])['name'] ?? 'another user') . '. No duplicate closing was created.', 409);
        if (isset($b['version']) && (int)$b['version'] !== (int)$c['version']) fail('The closing changed since you opened it. Reload and check the figures again.', 409);
        f3_day_guard($d, $u, 'closing');
        if (empty($b['confirm'])) fail('Tick the confirmation before submitting.', 400);
        $f = f3_day_figures($d);
        $calc = f3_closing_calc((int)$c['cash_sale'], (int)$c['card_sale'], (int)$c['upi_sale'], $c['actual_cash'] === null ? null : (int)$c['actual_cash'], $f);
        $blocks = array_values(array_filter(f3_closing_issues($c, $f, $calc), fn($x) => $x['level'] === 'block'));
        if ($blocks) fail('Cannot submit yet: ' . implode(' ', array_column($blocks, 'msg')), 422);
        // Post the three tender sales into Books exactly once (revision-keyed). Held when Books already has sales for the day.
        $hold = !empty($f['legacy_sales']);
        $posted = [];
        if (!$hold) foreach (['cash' => 'cash_sale', 'card' => 'card_sale', 'upi' => 'upi_sale'] as $k => $col) {
            $amt = (int)$c[$col]; if ($amt <= 0) continue;
            $posted[$k] = f3_bk_post(F3_SALE_KEY . $d . ':' . $k . ':r' . $c['revision'], ['date' => $d, 'type' => 'sale', 'total' => $amt, 'account' => f3_acct($k), 'description' => 'Daily ' . strtoupper($k) . ' sale · Finance 360 closing (Petpooja figure entered manually)'], $u);
        }
        $snap = ['figures' => ['opening' => $f['opening'], 'other_cash_in' => $f['other_cash_in'], 'cash_out' => $f['cash_out'], 'expenses_total' => $f['expenses_total'], 'expenses_cash' => $f['expenses_cash'], 'expenses' => $f['expenses'], 'payouts' => $f['payouts'], 'receipts' => $f['receipts'], 'legacy_sales' => $f['legacy_sales'], 'legacy_sales_total' => $f['legacy_sales_total']],
                 'calc' => $calc, 'sales' => ['cash' => (int)$c['cash_sale'], 'card' => (int)$c['card_sale'], 'upi' => (int)$c['upi_sale']], 'actual_cash' => (int)$c['actual_cash'], 'posted' => $posted, 'revision' => (int)$c['revision']];
        run("UPDATE f3_closings SET status='submitted',version=version+1,total_sale=?,opening_cash=?,other_cash_in=?,cash_out=?,expenses_total=?,expenses_cash=?,expected_cash=?,accounting_variance=?,operational_diff=?,sales_post_status=?,variance_class='pending',snapshot=?,submitted_by=?,submitted_at=UTC_TIMESTAMP(),step=6 WHERE id=?",
            $calc['total_sale'], $f['opening'], $f['other_cash_in'], $f['cash_out'], $f['expenses_total'], $f['expenses_cash'], $calc['expected_cash'], $calc['accounting_variance'], $calc['operational_diff'], $hold ? 'held' : ($posted ? 'posted' : 'nothing'), json_encode($snap, JSON_UNESCAPED_UNICODE), $u['id'], $c['id']);
        $c['revision'] = (int)$c['revision']; f3_closing_event($c, 'submitted', $u, null, $snap);
        f3_notify_done('closing-due-' . $d);
        $direct = function_exists('f29_closing_autofinal') ? f29_closing_autofinal($c, $d, $u, $calc, $hold) : null; // V29: final on submit, no admin approval
        if (!$direct) f3_notify('closing-review', 'closing-review-' . $d . '-r' . $c['revision'], 'Review closing ' . $d . ($calc['operational_diff'] ? ' · ' . $calc['operational_label'] . ' ' . f3_rs(abs($calc['operational_diff'])) : ''), 'Submitted by ' . $u['name'] . '. Total sale ' . f3_rs($calc['total_sale']) . '. Classify Cash Acess / Cash Short (nothing is posted until you decide).' . ($hold ? ' Sales are HELD: Books already has sale entries for this date.' : ''), 'closing.approve', 'closing:' . $d);
        f3_audit($u, 'closing.submit', ['date' => $d, 'revision' => $c['revision'], 'total_sale' => $calc['total_sale'], 'actual' => (int)$c['actual_cash'], 'op_diff' => $calc['operational_diff'], 'acc_var' => $calc['accounting_variance'], 'sales_post' => $hold ? 'held' : 'posted']);
        return ['ok' => true, 'date' => $d, 'final' => (bool)$direct, 'variance' => $direct, 'message' => $direct ? 'Closing for ' . $d . ' is final and locked (' . $direct['variance_label'] . ($direct['variance_posted'] ? ' ' . f3_rs(abs($direct['variance_posted'])) . ' posted' : '') . '). Thank you!' : 'Closing for ' . $d . ' submitted and locked. Thank you!', 'summary' => ['total_sale' => $calc['total_sale'], 'cash' => (int)$c['cash_sale'], 'card' => (int)$c['card_sale'], 'upi' => (int)$c['upi_sale'], 'expenses' => $f['expenses_total'], 'actual_cash' => (int)$c['actual_cash']]];
    });
}
/** Admin review: decide what (if anything) to post for the difference, and resolve held sales. */
function f3_closing_review(array $b, array $u): array {
    f3_need($u, 'closing.approve'); $d = valid_date($b['date'] ?? '');
    $c = f3_closing_row($d, true);
    if ($c && $c['status'] === 'approved' && $c['sales_post_status'] === 'held') { // V29: final closing whose sales were held — resolve sales only
        bk_check_open($d); $sales = (string)($b['held_sales'] ?? '');
        if (!in_array($sales, ['post', 'keep_existing'], true)) fail('Choose “Post closing sales” or “Keep existing Books sales”.');
        if ($sales === 'post') foreach (['cash' => 'cash_sale', 'card' => 'card_sale', 'upi' => 'upi_sale'] as $k => $col) if ((int)$c[$col] > 0) f3_bk_post(F3_SALE_KEY . $d . ':' . $k . ':r' . $c['revision'], ['date' => $d, 'type' => 'sale', 'total' => (int)$c[$col], 'account' => f3_acct($k), 'description' => 'Daily ' . strtoupper($k) . ' sale · Finance 360 closing (held sales posted)'], $u);
        run('UPDATE f3_closings SET sales_post_status=?,version=version+1 WHERE id=?', $sales === 'post' ? 'posted' : 'kept_existing', $c['id']);
        f3_closing_event($c, 'held_sales', $u, $sales); f3_notify_done('closing-held-' . $d . '-r' . $c['revision']); f3_audit($u, 'closing.held_sales', ['date' => $d, 'decision' => $sales]);
        return f3_closing_state($d, $u);
    }
    if (!$c || $c['status'] !== 'submitted') fail('Only a submitted closing can be reviewed.', 409);
    bk_check_open($d);
    $class = (string)($b['classification'] ?? '');
    if (!in_array($class, ['no_posting', 'post_accounting', 'post_operational'], true)) fail('Choose how to treat the difference.');
    $note = f3_text($b['note'] ?? '', 500, $class === 'no_posting' ? null : 'Review note');
    $amt = $class === 'post_accounting' ? (int)$c['accounting_variance'] : ($class === 'post_operational' ? (int)$c['operational_diff'] : 0);
    $txn = null;
    if ($class !== 'no_posting' && $amt !== 0) {
        $txn = $amt > 0
            ? f3_bk_post(F3_VAR_KEY . $d . ':r' . $c['revision'], ['date' => $d, 'type' => 'cash_access', 'total' => $amt, 'account' => f3_acct('cash'), 'description' => 'Cash Acess (closing ' . $d . ', ' . ($class === 'post_accounting' ? 'accounting variance' : 'operational difference') . ') · ' . $note], $u)
            : f3_bk_post(F3_VAR_KEY . $d . ':r' . $c['revision'], ['date' => $d, 'type' => 'reduce_cash', 'total' => -$amt, 'account' => f3_acct('cash'), 'description' => 'Cash Short (closing ' . $d . ', ' . ($class === 'post_accounting' ? 'accounting variance' : 'operational difference') . ') · ' . $note], $u);
    }
    $sales = (string)($b['held_sales'] ?? '');
    $postStatus = $c['sales_post_status'];
    if ($c['sales_post_status'] === 'held') {
        if (!in_array($sales, ['post', 'keep_existing'], true)) fail('Sales are held because Books already had sale entries. Choose “Post closing sales” or “Keep existing Books sales”.');
        if ($sales === 'post') { foreach (['cash' => 'cash_sale', 'card' => 'card_sale', 'upi' => 'upi_sale'] as $k => $col) if ((int)$c[$col] > 0) f3_bk_post(F3_SALE_KEY . $d . ':' . $k . ':r' . $c['revision'], ['date' => $d, 'type' => 'sale', 'total' => (int)$c[$col], 'account' => f3_acct($k), 'description' => 'Daily ' . strtoupper($k) . ' sale · Finance 360 closing (posted after admin review)'], $u); $postStatus = 'posted'; }
        else $postStatus = 'kept_existing';
    }
    run("UPDATE f3_closings SET status='approved',version=version+1,variance_class=?,variance_basis=?,variance_amount=?,variance_txn_id=?,review_note=?,sales_post_status=?,approved_by=?,approved_at=UTC_TIMESTAMP() WHERE id=?",
        $class, $class === 'no_posting' ? null : substr($class, 5), $amt, $txn, $note ?: null, $postStatus, $u['id'], $c['id']);
    f3_closing_event($c, 'approved', $u, $class . ($amt ? ' ' . f3_rs($amt) : '') . ($note ? ' · ' . $note : ''));
    f3_notify_done('closing-review-' . $d . '-r' . $c['revision']);
    f3_audit($u, 'closing.review', ['date' => $d, 'class' => $class, 'amount' => $amt, 'txn' => $txn, 'sales' => $postStatus]);
    return f3_closing_state($d, $u);
}
/** Admin-only reopen: keeps history, cancels this revision's postings (Books entries are voided, never deleted) and opens a new revision. */
function f3_closing_reopen(array $b, array $u): array {
    f3_need($u, 'closing.reopen'); $d = valid_date($b['date'] ?? '');
    $why = f3_text($b['reason'] ?? '', 500, 'Reason for reopening');
    $c = f3_closing_row($d, true); if (!$c || !in_array($c['status'], ['submitted', 'approved'], true)) fail('Only a locked closing can be reopened.', 409);
    bk_check_open($d);
    foreach (rows("SELECT id FROM dd_bk_txns WHERE voided=0 AND (import_key LIKE ? OR import_key=?)", F3_SALE_KEY . $d . ':%:r' . $c['revision'], F3_VAR_KEY . $d . ':r' . $c['revision']) as $t) f3_bk_void((int)$t['id'], 'Closing ' . $d . ' reopened: ' . $why, $u);
    f3_closing_event($c, 'reopened', $u, $why);
    run("UPDATE f3_closings SET status='reopened',revision=revision+1,version=version+1,sales_post_status='none',variance_class='pending',variance_txn_id=NULL,reopened_by=?,reopened_at=UTC_TIMESTAMP(),reopen_reason=?,step=2 WHERE id=?", $u['id'], $why, $c['id']);
    run("UPDATE f3_corrections SET status='done',decided_by=?,decided_at=UTC_TIMESTAMP(),decision_note=? WHERE kind='closing' AND business_date=? AND status='open'", $u['id'], 'Day reopened: ' . $why, $d);
    f3_notify_done('closing-review-' . $d . '-r' . $c['revision']);
    f3_notify('closing-due', 'closing-reopened-' . $d . '-r' . ((int)$c['revision'] + 1), 'Closing ' . $d . ' was reopened', 'Reason: ' . $why . '. Re-check figures and submit again.', 'closing.entry', 'closing:' . $d);
    f3_audit($u, 'closing.reopen', ['date' => $d, 'reason' => $why, 'old_revision' => (int)$c['revision']]);
    return f3_closing_state($d, $u);
}
function f3_correction_request(array $b, array $u): array {
    f3_need($u, 'closing.correction', 'closing.approve'); $d = valid_date($b['date'] ?? '');
    $why = f3_text($b['reason'] ?? '', 1000, 'What needs correcting');
    $c = f3_closing_row($d); if (!$c || !in_array($c['status'], ['submitted', 'approved'], true)) fail('This day is not locked; you can still edit it directly.', 409);
    if (row("SELECT id FROM f3_corrections WHERE kind='closing' AND business_date=? AND status='open' AND requested_by=?", $d, $u['id'])) fail('You already have an open correction request for this day.', 409);
    run("INSERT INTO f3_corrections(kind,business_date,ref,reason,requested_by) VALUES('closing',?,?,?,?)", $d, 'closing:' . $d, $why, $u['id']);
    $id = (int)db()->lastInsertId();
    f3_notify('correction', 'correction-' . $id, 'Correction requested for ' . $d, $u['name'] . ': ' . $why, 'closing.reopen', 'closing:' . $d);
    f3_audit($u, 'correction.request', ['id' => $id, 'date' => $d]);
    return ['ok' => true, 'id' => $id, 'message' => 'Correction request sent to the admin.'];
}
function f3_correction_decide(array $b, array $u): array {
    f3_need($u, 'closing.reopen'); $id = (int)($b['id'] ?? 0); $r = row('SELECT * FROM f3_corrections WHERE id=? FOR UPDATE', $id);
    if (!$r || $r['status'] !== 'open') fail('Request not found or already decided.', 404);
    $ok = !empty($b['approve']); $note = f3_text($b['note'] ?? '', 500, $ok ? null : 'Reason for rejecting');
    if ($ok && $r['kind'] === 'closing') { f3_closing_reopen(['date' => $r['business_date'], 'reason' => 'Correction #' . $id . ': ' . $r['reason'] . ($note ? ' · ' . $note : '')], $u); }
    else run("UPDATE f3_corrections SET status='rejected',decided_by=?,decided_at=UTC_TIMESTAMP(),decision_note=? WHERE id=?", $u['id'], $note, $id);
    f3_notify_done('correction-' . $id);
    f3_audit($u, 'correction.decide', ['id' => $id, 'approve' => $ok]);
    return ['ok' => true];
}
/** Day-wise sales & expense history (counter can view; no edit). Days without a Finance 360 closing show Books figures. */
function f3_sales_history(string $from, string $to, array $u): array {
    f3_need($u, 'sales.view', 'closing.view'); valid_date($from); valid_date($to); if ($from > $to) [$from, $to] = [$to, $from];
    if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 400) fail('Choose up to 400 days.');
    $acc = ['cash' => f3_set('f3_acct_cash'), 'card' => f3_set('f3_acct_card'), 'upi' => f3_set('f3_acct_upi')];
    $days = [];
    foreach (rows("SELECT date,account,SUM(total) n FROM dd_bk_txns WHERE voided=0 AND type='sale' AND date BETWEEN ? AND ? GROUP BY date,account", $from, $to) as $r) {
        $k = array_search($r['account'], $acc, true) ?: 'other'; $x = &$days[$r['date']]; $x ??= ['date' => $r['date'], 'cash' => 0, 'card' => 0, 'upi' => 0, 'other' => 0, 'expenses' => 0, 'source' => 'books']; $x[$k] += (int)$r['n']; unset($x);
    }
    foreach (rows("SELECT date,SUM(total) n,COUNT(*) c FROM dd_bk_txns WHERE voided=0 AND type='expense' AND date BETWEEN ? AND ? GROUP BY date", $from, $to) as $r) { $x = &$days[$r['date']]; $x ??= ['date' => $r['date'], 'cash' => 0, 'card' => 0, 'upi' => 0, 'other' => 0, 'expenses' => 0, 'source' => 'books']; $x['expenses'] = (int)$r['n']; $x['expense_count'] = (int)$r['c']; unset($x); }
    $seeVar = f3_can($u, 'closing.variance');
    foreach (rows('SELECT * FROM f3_closings WHERE business_date BETWEEN ? AND ?', $from, $to) as $c) {
        $x = &$days[$c['business_date']]; $x ??= ['date' => $c['business_date'], 'cash' => 0, 'card' => 0, 'upi' => 0, 'other' => 0, 'expenses' => 0];
        $x['status'] = $c['status']; $x['source'] = 'closing';
        if (in_array($c['status'], ['submitted', 'approved'], true) && $c['sales_post_status'] !== 'posted') { $x['cash'] = (int)$c['cash_sale']; $x['card'] = (int)$c['card_sale']; $x['upi'] = (int)$c['upi_sale']; $x['note'] = $c['sales_post_status'] === 'held' ? 'Sales held for admin review' : ($c['sales_post_status'] === 'kept_existing' ? 'Books sales kept' : null); }
        if ($seeVar && $c['operational_diff'] !== null) { $x['operational_diff'] = (int)$c['operational_diff']; $x['accounting_variance'] = (int)$c['accounting_variance']; $x['actual_cash'] = (int)$c['actual_cash']; }
        unset($x);
    }
    krsort($days); $t = ['cash' => 0, 'card' => 0, 'upi' => 0, 'other' => 0, 'total' => 0, 'expenses' => 0, 'cash_acess' => 0, 'cash_short' => 0];
    foreach ($days as &$x) { $x['status'] ??= ($x['date'] >= f3_closing_start() ? 'not_started' : 'legacy'); $x['total'] = $x['cash'] + $x['card'] + $x['upi'] + $x['other']; foreach (['cash', 'card', 'upi', 'other', 'total', 'expenses'] as $k) $t[$k] += $x[$k];
        if (isset($x['operational_diff'])) { if ($x['operational_diff'] > 0) $t['cash_acess'] += $x['operational_diff']; else $t['cash_short'] -= $x['operational_diff']; } } unset($x);
    if (!$seeVar) unset($t['cash_acess'], $t['cash_short']);
    return ['from' => $from, 'to' => $to, 'days' => array_values($days), 'totals' => $t];
}
/** Unfinished closing days (start date → target), for banners and the admin list. */
function f3_pending_closings(): array {
    $start = f3_closing_start(); $target = f3_target_date(); if ($start > $target) return [];
    $done = []; foreach (rows("SELECT business_date,status FROM f3_closings WHERE business_date BETWEEN ? AND ?", $start, $target) as $r) $done[$r['business_date']] = $r['status'];
    $o = []; for ($d = $target, $i = 0; $d >= $start && $i < 62; $d = next_date($d, -1), $i++) { $s = $done[$d] ?? 'not_started'; if (!in_array($s, ['submitted', 'approved'], true)) $o[] = ['date' => $d, 'status' => $s]; }
    return $o;
}
