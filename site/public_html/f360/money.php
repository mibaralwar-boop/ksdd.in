<?php
declare(strict_types=1);
// Finance 360 — Expenses, Cash counter, Accounts (cashbook / bankbook / GL), Suppliers, Stock financial summary.
// All entries are Books 360 transactions (dd_bk_txns); Finance 360 adds review/proof metadata in f3_expense_meta.
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/closing.php';

function f3_expense_categories(): array {
    $hid = function_exists('f3_hidden') ? f3_hidden('f3_hidden_categories') : []; $o = []; foreach (bk_categories() as $c => $g) if ((!str_contains($g, '(not expense)') || $c === 'Staff Advance (recoverable)') && !in_array($c, $hid, true)) $o[] = ['name' => $c, 'group' => $g];
    usort($o, fn($a, $b) => [$a['group'], $a['name']] <=> [$b['group'], $b['name']]);
    // Staff advances / salary / OT are entered from Staff entries (to keep the staff ledger exact), not as plain expenses.
    return array_values(array_filter($o, fn($x) => !in_array($x['name'], ['Staff Advance (recoverable)'], true)));
}
function f3_expense_frequent(): array { return array_column(rows("SELECT category,COUNT(*) n FROM dd_bk_txns WHERE voided=0 AND type='expense' AND category IS NOT NULL AND date>=? AND category NOT IN ('Staff Advance (recoverable)','Overtime Wages','Salary - Current Month','Salary - Previous Month','Other Staff Sallery') GROUP BY category ORDER BY n DESC LIMIT 8", next_date(f3_business_date(), -60)), 'category'); }
function f3_expense_save(array $b, array $u): array {
    $id = (int)($b['id'] ?? 0);
    f3_need($u, $id ? 'expenses.update' : 'expenses.create');
    $d = valid_date($b['date'] ?? '');
    $old = null;
    if ($id) {
        $old = row('SELECT * FROM dd_bk_txns WHERE id=? FOR UPDATE', $id);
        if (!$old || (int)$old['voided'] || $old['type'] !== 'expense') fail('Expense not found.', 404);
        if (str_starts_with((string)$old['import_key'], 'f360:staff') || str_starts_with((string)$old['import_key'], 'f360:pay')) fail('This entry belongs to a staff/salary record. Change it from Staff entries.', 409);
        f3_day_guard($old['date'], $u);
    }
    f3_day_guard($d, $u);
    $cat = f3_text($b['category'] ?? '', 80, 'Category');
    if (!isset(bk_categories()[$cat])) fail('Choose a category from the list.');
    if (in_array($cat, ['Staff Advance (recoverable)'], true)) fail('Staff advances are entered from Staff → Advance so that recovery works.');
    $amt = f3_paise($b['amount'] ?? null, 'Amount'); if ($amt <= 0) fail('Amount must be more than zero.');
    $mode = (string)($b['mode'] ?? 'Cash'); $acct = in_array($mode, ['Cash', 'Bank', 'UPI', 'Card'], true) ? f3_mode_acct($mode) : (in_array($mode, bk_accounts(), true) ? $mode : fail('Choose payment mode.'));
    $desc = f3_text($b['description'] ?? '', 500);
    $proofAbove = f3_set_int('f3_expense_proof_above');
    // V21 §5: several photos / PDFs per expense. A receipt already attached to ANOTHER live expense is flagged (no double posting by re-upload).
    $newFiles = []; foreach (array_slice(array_merge(isset($b['file']) && $b['file'] ? [$b['file']] : [], array_values((array)($b['files'] ?? []))), 0, 8) as $f) { $fid = f3_upload($f, $u); if ($fid) $newFiles[] = $fid; }
    $fileId = $newFiles[0] ?? null;
    if ($newFiles && empty($b['confirm_duplicate'])) { $in = implode(',', array_map('intval', $newFiles));
        $dupF = row("SELECT x.txn_id FROM dd_files n JOIN dd_files o ON o.sha256=n.sha256 AND o.id<>n.id JOIN (SELECT txn_id,file_id FROM f3_expense_files WHERE removed=0 UNION SELECT txn_id,file_id FROM f3_expense_meta WHERE file_id IS NOT NULL) x ON x.file_id=o.id JOIN dd_bk_txns t ON t.id=x.txn_id AND t.voided=0 WHERE n.id IN ($in) AND x.txn_id<>? LIMIT 1", (int)$id);
        if ($dupF) throw new F3Conflict('This bill photo is already attached to expense #' . $dupF['txn_id'] . '. Is this really a different expense?', ['duplicate' => (int)$dupF['txn_id'], 'reason' => 'same_receipt']); }
    $hasProof = $fileId || ($id && (row('SELECT file_id FROM f3_expense_meta WHERE txn_id=? AND file_id IS NOT NULL', $id) || row('SELECT 1 FROM f3_expense_files WHERE txn_id=? AND removed=0', $id)));
    if ($proofAbove > 0 && $amt > $proofAbove && !$hasProof) fail('Attach a bill photo for expenses above ' . f3_rs($proofAbove) . '.', 422);
    if (empty($b['confirm_duplicate'])) {
        $dup = row("SELECT id,total,description FROM dd_bk_txns WHERE voided=0 AND type='expense' AND date=? AND category=? AND total=? AND id<>?", $d, $cat, $amt, $id);
        if ($dup) throw new F3Conflict('Possible duplicate: the same category and amount already exists on ' . $d . ' (#' . $dup['id'] . ' ' . ($dup['description'] ?? '') . '). Save anyway?', ['duplicate' => (int)$dup['id']]);
    }
    $r = bk_txn_save(['id' => $id ?: null, 'type' => 'expense', 'date' => $d, 'category' => $cat, 'total' => rupees($amt), 'paid' => rupees($amt), 'account' => $acct, 'party' => f3_text($b['party'] ?? '', 160), 'bill_no' => f3_text($b['bill_no'] ?? '', 60), 'description' => $desc], $u);
    $tid = (int)$r['id'];
    if (!$id) run("UPDATE dd_bk_txns SET source='f360' WHERE id=?", $tid);
    run('INSERT INTO f3_expense_meta(txn_id,business_date,file_id,created_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE business_date=VALUES(business_date),file_id=COALESCE(VALUES(file_id),file_id),review_status=IF(?,"pending",review_status)', $tid, $d, $fileId, $u['id'], $id ? 1 : 0);
    foreach ($newFiles as $k => $fid) run('INSERT IGNORE INTO f3_expense_files(txn_id,file_id,sort,created_by) VALUES(?,?,?,?)', $tid, $fid, (int)row('SELECT COUNT(*) n FROM f3_expense_files WHERE txn_id=?', $tid)['n'] + $k, $u['id']);
    foreach ((array)($b['files_remove'] ?? []) as $fid) { run('UPDATE f3_expense_files SET removed=1 WHERE txn_id=? AND file_id=?', $tid, (int)$fid); run('UPDATE f3_expense_meta SET file_id=(SELECT file_id FROM f3_expense_files WHERE txn_id=? AND removed=0 ORDER BY sort,id LIMIT 1) WHERE txn_id=? AND file_id=?', $tid, $tid, (int)$fid); }
    // A change to a day's expenses invalidates a previously reviewed "No expenses" confirmation for that day.
    run("UPDATE f3_closings SET expenses_confirmed=NULL,version=version+1 WHERE business_date IN (?,?) AND status IN ('draft','reopened')", $d, $old['date'] ?? $d);
    f3_audit($u, $id ? 'expense.update' : 'expense.create', ['txn' => $tid, 'date' => $d, 'amount' => $amt, 'category' => $cat, 'account' => $acct]);
    return ['ok' => true, 'id' => $tid, 'expense' => f3_expense_list(['id' => $tid], $u)['rows'][0] ?? null];
}
function f3_expense_void(array $b, array $u): array {
    f3_need($u, 'expenses.void'); $t = row('SELECT * FROM dd_bk_txns WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0));
    if (!$t || (int)$t['voided'] || $t['type'] !== 'expense') fail('Expense not found.', 404);
    if (str_starts_with((string)$t['import_key'], 'f360:staff') || str_starts_with((string)$t['import_key'], 'f360:pay')) fail('Reverse this from the staff ledger.', 409);
    f3_day_guard($t['date'], $u);
    bk_txn_void(['id' => $t['id'], 'reason' => f3_text($b['reason'] ?? '', 250, 'Reason')], $u);
    run("UPDATE f3_closings SET expenses_confirmed=NULL,version=version+1 WHERE business_date=? AND status IN ('draft','reopened')", $t['date']);
    f3_audit($u, 'expense.void', ['txn' => (int)$t['id']]);
    return ['ok' => true];
}
function f3_expense_review(array $b, array $u): array {
    f3_need($u, 'expenses.review'); $id = (int)($b['id'] ?? 0); $st = (string)($b['status'] ?? '');
    if (!in_array($st, ['reviewed', 'flagged', 'pending'], true)) fail('Invalid review status.');
    $t = row("SELECT id,date FROM dd_bk_txns WHERE id=? AND type='expense'", $id); if (!$t) fail('Expense not found.', 404);
    run('INSERT INTO f3_expense_meta(txn_id,business_date,review_status,review_note,reviewed_by,reviewed_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE review_status=VALUES(review_status),review_note=VALUES(review_note),reviewed_by=VALUES(reviewed_by),reviewed_at=VALUES(reviewed_at)', $id, $t['date'], $st, f3_text($b['note'] ?? '', 250) ?: null, $u['id']);
    f3_audit($u, 'expense.review', ['txn' => $id, 'status' => $st]);
    return ['ok' => true];
}
function f3_expense_list(array $q, array $u): array {
    f3_need($u, 'expenses.view');
    $w = ["t.type='expense'"]; $a = [];
    if (!empty($q['id'])) { $w[] = 't.id=?'; $a[] = (int)$q['id']; }
    else {
        $from = valid_date($q['from'] ?? f3_business_date()); $to = valid_date($q['to'] ?? $from); if ($from > $to) [$from, $to] = [$to, $from];
        $w[] = 't.date BETWEEN ? AND ?'; array_push($a, $from, $to);
        if (empty($q['include_void'])) $w[] = 't.voided=0';
        if (!empty($q['category'])) { $w[] = 't.category=?'; $a[] = txt($q['category'], 80); }
        if (!empty($q['account'])) { $w[] = 't.account=?'; $a[] = txt($q['account'], 40); }
        if (!empty($q['review'])) { $w[] = 'COALESCE(m.review_status,\'pending\')=?'; $a[] = txt($q['review'], 12); }
        if (!empty($q['q'])) { $w[] = '(t.description LIKE ? OR p.name LIKE ? OR t.bill_no LIKE ?)'; $s = '%' . txt($q['q'], 60) . '%'; array_push($a, $s, $s, $s); }
    }
    $rows = rows('SELECT t.id,t.date,t.category,t.total,t.paid,t.account,t.bill_no,t.description,t.voided,t.void_reason,t.source,t.import_key,t.created_at,p.name party,u.name created_by_name,m.file_id,COALESCE(m.review_status,\'pending\') review_status,m.review_note
        FROM dd_bk_txns t LEFT JOIN dd_bk_parties p ON p.id=t.party_id LEFT JOIN dd_users u ON u.id=t.created_by LEFT JOIN f3_expense_meta m ON m.txn_id=t.id WHERE ' . implode(' AND ', $w) . ' ORDER BY t.date DESC,t.id DESC LIMIT 2000', ...$a);
    $tot = 0; $byCat = []; $byAcct = []; $locks = [];
    foreach ($rows as &$r) {
        foreach (['id', 'total', 'paid', 'voided'] as $k) $r[$k] = (int)$r[$k]; $r['file_id'] = $r['file_id'] ? (int)$r['file_id'] : null; $r['files'] = array_map(fn($f) => ['id' => (int)$f['file_id'], 'mime' => $f['mime']], rows('SELECT ef.file_id,d.mime FROM f3_expense_files ef JOIN dd_files d ON d.id=ef.file_id WHERE ef.txn_id=? AND ef.removed=0 ORDER BY ef.sort,ef.id', $r['id']));
        if (!$r['files'] && $r['file_id']) $r['files'] = [['id' => $r['file_id'], 'mime' => null]];
        $r['group'] = bk_group($r['category']); $r['staff_linked'] = str_starts_with((string)$r['import_key'], 'f360:staff') || str_starts_with((string)$r['import_key'], 'f360:pay'); unset($r['import_key']);
        $locks[$r['date']] ??= f3_day_block_reason($r['date'], $u); $r['editable'] = !$r['voided'] && !$r['staff_linked'] && $locks[$r['date']] === null; $r['lock_reason'] = $locks[$r['date']];
        if (!$r['voided']) { $tot += $r['total']; $byCat[$r['category'] ?: '(none)'] = ($byCat[$r['category'] ?: '(none)'] ?? 0) + $r['total']; $byAcct[$r['account']] = ($byAcct[$r['account']] ?? 0) + $r['total']; }
    } unset($r);
    arsort($byCat);
    return ['rows' => $rows, 'total' => $tot, 'by_category' => $byCat, 'by_account' => $byAcct, 'count' => count($rows)];
}

/* ------------------------------ Cash counter & accounts ------------------------------ */
function f3_accounts_overview(string $asOf, array $u): array {
    f3_need($u, 'cash.view', 'accounts.view');
    $next = next_date($asOf); $o = [];
    foreach (bk_accounts() as $a) $o[] = ['account' => $a, 'balance' => bk_opening_before($next, $a), 'today_in' => 0, 'today_out' => 0];
    foreach (bk_txn_rows('t.date=? AND t.voided=0', [$asOf]) as $t) foreach ($o as &$x) { $e = bk_effect($t, $x['account']); if ($e > 0) $x['today_in'] += $e; else $x['today_out'] -= $e; } unset($x);
    return ['as_of' => $asOf, 'accounts' => $o];
}
function f3_book(string $acct, string $from, string $to, array $u): array {
    f3_need($u, $acct === f3_set('f3_acct_cash') ? 'cash.view' : 'accounts.view', 'accounts.view');
    if (!in_array($acct, bk_accounts(), true)) fail('Unknown account.');
    valid_date($from); valid_date($to); if ($from > $to) [$from, $to] = [$to, $from];
    if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 400) fail('Choose up to 400 days.');
    $b = bk_daybook($from, $to, $acct);
    $b['txns'] = array_values(array_filter($b['txns'], fn($t) => $t['cash'] !== 0 || $t['voided']));
    foreach ($b['txns'] as &$t) { $t['label'] = BK_TYPES[$t['type']][0] ?? $t['type']; if ($t['type'] === 'cash_access') $t['label'] = 'Cash Acess (not a sale)'; unset($t['lines']); } unset($t);
    return $b;
}
function f3_transfer_save(array $b, array $u): array {
    f3_need($u, 'cash.transfer', 'accounts.manage'); $d = valid_date($b['date'] ?? ''); f3_day_guard($d, $u, 'transfer');
    $from = (string)($b['from'] ?? ''); $to = (string)($b['to'] ?? '');
    $amt = f3_paise($b['amount'] ?? null, 'Amount'); if ($amt <= 0) fail('Amount must be more than zero.');
    $r = bk_txn_save(['type' => 'transfer', 'date' => $d, 'account' => $from, 'to_account' => $to, 'total' => rupees($amt), 'description' => f3_text($b['description'] ?? '', 500, 'Note (e.g. deposited in bank by …)')], $u);
    run("UPDATE dd_bk_txns SET source='f360' WHERE id=?", $r['id']);
    f3_audit($u, 'transfer.create', ['txn' => $r['id'], 'from' => $from, 'to' => $to, 'amount' => $amt]);
    return ['ok' => true, 'id' => $r['id']];
}
function f3_gl_available(): bool { try { row('SELECT 1 FROM dd_gl_accounts LIMIT 1'); return true; } catch (PDOException $e) { return false; } }
function f3_gl_view(string $what, array $q, array $u): array {
    f3_need($u, 'accounts.view'); if (!f3_gl_available()) fail('The double-entry ledger is not installed.', 503);
    require_once dirname(__DIR__) . '/gl360.php';
    $to = valid_date($q['to'] ?? f3_business_date()); $from = valid_date($q['from'] ?? substr($to, 0, 8) . '01');
    return match ($what) {
        'trial' => gl_trial_balance($to) + ['to' => $to, 'journals' => (int)row('SELECT COUNT(*) n FROM dd_gl_journals')['n']],
        'ledger' => gl_ledger_view(txt($q['code'] ?? '1000', 20), $from, $to),
        'journals' => ['rows' => rows('SELECT j.id,j.journal_no,j.business_date,j.source_type,j.memo,j.total,j.status FROM dd_gl_journals j WHERE j.business_date BETWEEN ? AND ? ORDER BY j.business_date DESC,j.id DESC LIMIT 500', $from, $to), 'from' => $from, 'to' => $to],
        'journal' => gl_journal_view((int)($q['id'] ?? 0)),
        'statements' => gl_statements($from, $to),
        default => fail('Unknown ledger view.', 404),
    };
}

/* Suppliers, purchases and stock moved to f360/purchase.php + f360/stock.php in V17 (one supplier master, one purchase pipeline, one stock ledger). */

/** "Update ledger" (moved from the archived CRM tool): registers new Books / salary events, posts the clear ones, then builds GL journals. Idempotent. */
function f3_gl_sync(array $u): array {
    f3_need($u, 'accounts.view'); if (!f3_can($u, 'closing.approve')) fail('Only the owner / accountant can update the ledger.', 403);
    if (!f3_gl_available()) fail('The double-entry ledger is not installed.', 503);
    require_once dirname(__DIR__) . '/gl360.php'; require_once dirname(__DIR__) . '/post360.php';
    $ev = function_exists('fin_sync') ? fin_sync($u) : []; $posted = [];
    if (function_exists('fin_post_clear') && fin_tables()) { try { $posted = fin_post_clear(['limit' => 2000], $u); } catch (RuntimeException $e) { $posted = ['note' => $e->getMessage()]; } }
    $gl = gl_sync_all($u);
    $rec = 0; foreach (rows("SELECT period FROM f3_pay_periods WHERE status IN ('approved','locked')") as $per) if (f3_payroll_gl_recovery($per['period'], $u)) $rec++; // backfill months approved before V21
    $gl['payroll_recovery_journals'] = $rec; f3_audit($u, 'gl.sync', ['events' => $ev, 'gl' => $gl]);
    $held = fin_tables() ? (int)row("SELECT COUNT(*) n FROM dd_fin_events WHERE status IN ('pending','held','review')")['n'] : 0;
    return ['ok' => true, 'events' => $ev, 'posted' => $posted, 'journals' => $gl, 'held' => $held];
}
/** Ledger items held for a person (possible duplicate with an old daybook entry, or no date). Decided here now that the old CRM screens are archived. */
function f3_gl_pending(array $u): array {
    f3_need($u, 'accounts.view'); require_once dirname(__DIR__) . '/post360.php'; if (!fin_tables()) return ['rows' => []];
    $r = rows("SELECT id,source_system,source_table,source_id,kind,business_date,amount,account,party,memo FROM dd_fin_events WHERE status='pending' ORDER BY business_date,id LIMIT 200");
    foreach ($r as &$x) { $x['amount'] = (int)$x['amount']; $x['candidates'] = $x['business_date'] ? fin_candidates(row('SELECT * FROM dd_fin_events WHERE id=?', $x['id'])) : []; } unset($x);
    return ['rows' => $r];
}
function f3_gl_decide(array $b, array $u): array {
    f3_need($u, 'accounts.view'); if (!f3_can($u, 'closing.approve')) fail('Only the owner / accountant can decide ledger items.', 403);
    require_once dirname(__DIR__) . '/gl360.php'; require_once dirname(__DIR__) . '/post360.php';
    $act = (string)($b['act'] ?? ''); if (!in_array($act, ['post', 'exclude'], true)) fail('Choose post or exclude.');
    $note = f3_text($b['note'] ?? '', 250, 'Reason'); if (mb_strlen($note) < 5) fail('Write a reason of at least 5 characters.');
    $r = fin_decide(['id' => (int)($b['id'] ?? 0), 'act' => $act, 'note' => $note, 'force' => 1, 'date' => $b['date'] ?? null], $u);
    f3_audit($u, 'gl.decide', ['event' => (int)($b['id'] ?? 0), 'act' => $act, 'note' => $note]); return $r;
}
