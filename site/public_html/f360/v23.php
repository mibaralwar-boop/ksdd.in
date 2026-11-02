<?php
declare(strict_types=1);
// Finance 360 V23 — the V22 operational additions that were still missing (audit §7 / master prompt §I), built ON the existing
// modules (one Books cash book, one stock ledger, one staff ledger, one notification list). Nothing here posts income or expense:
//   • Cash Handover          sender → receiver acknowledgement → (only if the accounts differ) ONE internal Books transfer
//   • Global search          permission-filtered, paginated, deep links
//   • Supplier price history  per item, normalised to the item's stock unit (cost_total ÷ base_qty)
//   • Document inbox         upload → unclassified → linked to an existing expense / purchase bill → approved / rejected / removed (soft)
//   • Reorder assistant      min / reorder level, real trailing consumption, open bills, lead time → suggested quantity (never orders)
//   • Salary acknowledgement / dispute (admin-managed record tied to a salary payment)
//   • Month-end checklist + owner digest (factual, read-only, each line links to the screen that fixes it)
//   • Financial exception lifecycle over the existing reconciliation checks: open → assigned → investigating → corrected → resolved / dismissed (reason)
require_once __DIR__ . '/core.php';

/* ============================== Cash Handover ============================== */
const F3_HO_STATES = ['sent', 'accepted', 'partial', 'disputed', 'cancelled'];
function f3_ho_out(array $h): array {
    foreach (['amount', 'accepted_amount'] as $k) $h[$k] = $h[$k] === null ? null : (int)$h[$k];
    $h['denominations'] = $h['denominations'] ? json_decode((string)$h['denominations'], true) : null; foreach (['id', 'from_user', 'to_user', 'bk_txn_id'] as $k) $h[$k] = $h[$k] === null ? null : (int)$h[$k];
    unset($h['client_key']); return $h;
}
function f3_ho_list(array $q, array $u): array {
    f3_need($u, 'handover.send', 'handover.receive');
    $w = ['1=1']; $a = [];
    if (!f3_can($u, 'handover.receive')) { $w[] = '(h.from_user=? OR h.to_user=?)'; $a[] = $u['id']; $a[] = $u['id']; }
    if (!empty($q['status']) && in_array($q['status'], F3_HO_STATES, true)) { $w[] = 'h.status=?'; $a[] = $q['status']; }
    $r = rows('SELECT h.*,fu.name from_name,tu.name to_name FROM f3_handovers h LEFT JOIN dd_users fu ON fu.id=h.from_user LEFT JOIN dd_users tu ON tu.id=h.to_user WHERE ' . implode(' AND ', $w) . ' ORDER BY h.id DESC LIMIT 200', ...$a);
    $recv = rows("SELECT id,name FROM dd_users WHERE active=1 ORDER BY name");
    $recv = array_values(array_filter($recv, fn($x) => (int)$x['id'] !== (int)$u['id'] && f3_can(row('SELECT * FROM dd_users WHERE id=?', $x['id']) ?: [], 'handover.receive')));
    return ['rows' => array_map('f3_ho_out', $r), 'receivers' => $recv, 'accounts' => f3_visible_accounts(), 'cash_account' => f3_acct('cash'), 'mine_pending' => (int)row("SELECT COUNT(*) n FROM f3_handovers WHERE to_user=? AND status='sent'", $u['id'])['n']];
}
function f3_ho_create(array $b, array $u): array {
    f3_need($u, 'handover.send');
    $ck = txt($b['client_key'] ?? '', 64); if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $ck)) fail('Missing submission key. Reload and try again.');
    if ($ex = row('SELECT * FROM f3_handovers WHERE client_key=?', $ck)) return ['ok' => true, 'duplicate' => true, 'handover' => f3_ho_out($ex)];
    $to = (int)($b['to_user'] ?? 0); if ($to === (int)$u['id']) fail('Choose the person who receives the cash.'); $tu = row('SELECT * FROM dd_users WHERE id=? AND active=1', $to); if (!$tu || !f3_can($tu, 'handover.receive')) fail('This person cannot receive a cash handover.', 422);
    $amt = f3_paise($b['amount'] ?? null, 'Amount'); if ($amt <= 0) fail('Amount must be more than zero.');
    $from = (string)($b['from_account'] ?? f3_acct('cash')); if (!in_array($from, bk_accounts(), true)) fail('Unknown account.');
    $den = null; if (!empty($b['denominations']) && is_array($b['denominations'])) { $den = []; $sum = 0;
        foreach ($b['denominations'] as $note => $cnt) { $n = (int)$note; $c = (int)$cnt; if (!in_array($n, [2000, 500, 200, 100, 50, 20, 10, 5, 2, 1], true) || $c < 0 || $c > 100000) fail('Invalid denomination.'); if ($c) { $den[(string)$n] = $c; $sum += $n * $c * 100; } }
        if ($den && $sum !== $amt) fail('Notes counted (' . f3_rs($sum) . ') do not match the amount (' . f3_rs($amt) . ').', 422); if (!$den) $den = null; }
    $d = f3_business_date(); $no = f3_seq('handover', 'HO');
    run('INSERT INTO f3_handovers(ho_no,date,from_user,to_user,from_account,amount,denominations,note,status,client_key) VALUES(?,?,?,?,?,?,?,?,?,?)', $no, $d, $u['id'], $to, $from, $amt, $den ? json_encode($den) : null, txt($b['note'] ?? '', 250) ?: null, 'sent', $ck);
    $id = (int)db()->lastInsertId();
    f3_notify('handover', 'ho-' . $id, 'Cash handover ' . $no . ' from ' . $u['name'], f3_rs($amt) . ' — confirm when you have counted it.', 'handover.receive', 'handover:' . $id);
    f3_audit($u, 'handover.send', ['id' => $id, 'no' => $no, 'to' => $to, 'amount' => $amt]);
    return ['ok' => true, 'handover' => f3_ho_out(row('SELECT * FROM f3_handovers WHERE id=?', $id))];
}
/** Receiver: accept (full), partial (accepted amount < sent, reason) or dispute (nothing accepted, reason). Only the accepted amount moves —
 *  and only between two DIFFERENT Books accounts (a transfer, never income / expense). Same account = custody record only. */
function f3_ho_respond(array $b, array $u): array {
    f3_need($u, 'handover.receive');
    $h = row('SELECT * FROM f3_handovers WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0)); if (!$h) fail('Handover not found.', 404);
    if ($h['status'] !== 'sent') fail('This handover was already ' . $h['status'] . '.', 409);
    if ((int)$h['to_user'] !== (int)$u['id'] && ($u['role'] ?? '') !== 'owner') fail('Only the person it was handed to (or the owner) can confirm it.', 403);
    $act = (string)($b['act'] ?? ''); if (!in_array($act, ['accept', 'partial', 'dispute'], true)) fail('Choose accept, partial or dispute.');
    $amt = (int)$h['amount']; $acc = $act === 'accept' ? $amt : ($act === 'dispute' ? 0 : f3_paise($b['accepted_amount'] ?? null, 'Amount received'));
    if ($act === 'partial' && ($acc <= 0 || $acc >= $amt)) fail('Partial amount must be more than ₹0 and less than ' . f3_rs($amt) . '.', 422);
    $why = $act === 'accept' ? txt($b['note'] ?? '', 250) : f3_text($b['note'] ?? '', 250, 'Reason'); if ($act !== 'accept' && mb_strlen($why) < 5) fail('Write a reason (at least 5 characters).');
    $to = (string)($b['to_account'] ?? $h['from_account']); if (!in_array($to, bk_accounts(), true)) fail('Unknown account.');
    $bk = null;
    if ($acc > 0 && $to !== $h['from_account']) {
        $d = f3_business_date(); f3_day_guard($d, $u, 'transfer');
        $r = bk_txn_save(['type' => 'transfer', 'date' => $d, 'account' => $h['from_account'], 'to_account' => $to, 'total' => rupees($acc), 'description' => 'Cash handover ' . $h['ho_no'] . ' received by ' . $u['name']], $u);
        run("UPDATE dd_bk_txns SET source='f360' WHERE id=?", $r['id']); $bk = (int)$r['id'];
    }
    run('UPDATE f3_handovers SET status=?,accepted_amount=?,to_account=?,response_note=?,bk_txn_id=?,responded_by=?,responded_at=UTC_TIMESTAMP() WHERE id=?', $act === 'accept' ? 'accepted' : ($act === 'partial' ? 'partial' : 'disputed'), $acc, $to, $why ?: null, $bk, $u['id'], $h['id']);
    f3_notify_done('ho-' . $h['id']);
    if ($act !== 'accept') f3_notify('handover-dispute', 'ho-d-' . $h['id'], 'Cash handover ' . $h['ho_no'] . ': ' . ($act === 'partial' ? 'only ' . f3_rs($acc) . ' of ' . f3_rs($amt) . ' received' : 'disputed'), $why, 'closing.approve', 'handover:' . $h['id']);
    f3_audit($u, 'handover.' . $act, ['id' => (int)$h['id'], 'no' => $h['ho_no'], 'sent' => $amt, 'accepted' => $acc, 'to_account' => $to, 'books_transfer' => $bk, 'note' => $why]);
    return ['ok' => true, 'handover' => f3_ho_out(row('SELECT * FROM f3_handovers WHERE id=?', $h['id']))];
}
function f3_ho_cancel(array $b, array $u): array {
    f3_need($u, 'handover.send'); $h = row('SELECT * FROM f3_handovers WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0)); if (!$h) fail('Handover not found.', 404);
    if ($h['status'] !== 'sent') fail('Only a handover not yet confirmed can be cancelled.', 409); if ((int)$h['from_user'] !== (int)$u['id'] && ($u['role'] ?? '') !== 'owner') fail('Only the sender can cancel.', 403);
    $why = f3_text($b['reason'] ?? '', 250, 'Reason'); run("UPDATE f3_handovers SET status='cancelled',response_note=?,responded_by=?,responded_at=UTC_TIMESTAMP() WHERE id=?", $why, $u['id'], $h['id']); f3_notify_done('ho-' . $h['id']);
    f3_audit($u, 'handover.cancel', ['id' => (int)$h['id'], 'reason' => $why]); return ['ok' => true];
}

/* ============================== Global search ============================== */
function f3_search(array $q, array $u): array {
    $s = trim((string)($q['q'] ?? '')); if (mb_strlen($s) < 2) return ['q' => $s, 'groups' => []];
    $s = mb_substr($s, 0, 60); $like = '%' . addcslashes($s, '%_\\') . '%'; $lim = 8; $off = max(0, min(400, (int)($q['offset'] ?? 0))); $only = (string)($q['only'] ?? '');
    $g = []; $add = function (string $key, string $label, array $rows, callable $map) use (&$g, $lim) { if (!$rows) return; $more = count($rows) > $lim; $g[] = ['key' => $key, 'label' => $label, 'items' => array_map($map, array_slice($rows, 0, $lim)), 'more' => $more]; };
    $want = fn(string $k) => $only === '' || $only === $k; $L = 'LIMIT ' . ($lim + 1) . ' OFFSET ' . $off;
    if ($want('staff') && f3_can($u, 'staff.view')) $add('staff', 'Staff', rows("SELECT e.id,e.name,e.code,e.status,d.name dept FROM dd_employees e LEFT JOIN f3_departments d ON d.id=e.f3_dept_id WHERE e.name LIKE ? OR e.code LIKE ? OR e.aliases LIKE ? ORDER BY e.status='active' DESC,e.name $L", $like, $like, $like),
        fn($r) => ['title' => $r['name'], 'sub' => trim(($r['code'] ?? '') . ' · ' . ($r['dept'] ?? '—') . ' · ' . $r['status']), 'route' => '#/staff/' . $r['id']]);
    if ($want('suppliers') && f3_can($u, 'suppliers.view')) $add('suppliers', 'Suppliers', rows("SELECT id,name,phone,gstin FROM dd_bk_parties WHERE kind='supplier' AND (name LIKE ? OR phone LIKE ? OR gstin LIKE ? OR aliases LIKE ?) ORDER BY active DESC,name $L", $like, $like, $like, $like),
        fn($r) => ['title' => $r['name'], 'sub' => trim(($r['phone'] ? '••' . substr((string)$r['phone'], -4) : '') . ($r['gstin'] ? ' · GST ' . $r['gstin'] : '')), 'route' => '#/supplier/' . $r['id']]);
    if ($want('items') && (f3_can($u, 'item.view') || f3_can($u, 'stock.view'))) $add('items', 'Items / SKU', rows("SELECT id,name,code,unit,category FROM dd_bk_items WHERE merged_into IS NULL AND (name LIKE ? OR code LIKE ? OR category LIKE ?) ORDER BY active DESC,name $L", $like, $like, $like),
        fn($r) => ['title' => $r['name'], 'sub' => trim(($r['code'] ? 'SKU ' . $r['code'] . ' · ' : '') . ($r['category'] ?? '') . ' · ' . ($r['unit'] ?? '')), 'route' => '#/item/' . $r['id']]);
    if ($want('bills') && f3_can($u, 'purchase.view')) $add('bills', 'Purchase bills', rows("SELECT b.id,b.bill_no,b.invoice_no,b.invoice_date,b.grand_total,b.status,p.name sup FROM f3_purchase_bills b LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id WHERE b.status<>'discarded' AND (b.bill_no LIKE ? OR b.invoice_no LIKE ? OR p.name LIKE ? OR b.reference LIKE ?) ORDER BY b.id DESC $L", $like, $like, $like, $like),
        fn($r) => ['title' => $r['bill_no'] . ($r['invoice_no'] ? ' · inv ' . $r['invoice_no'] : ''), 'sub' => ($r['sup'] ?? '—') . ' · ' . ($r['invoice_date'] ?? '') . ' · ' . f3_rs((int)$r['grand_total']) . ' · ' . $r['status'], 'route' => '#/bill/' . $r['id']]);
    if ($want('expenses') && f3_can($u, 'expenses.view')) { $amt = preg_match('/^\d{1,9}(\.\d{1,2})?$/', $s) ? (int)round((float)$s * 100) : -1;
        $add('expenses', 'Expenses', rows("SELECT id,date,category,total,description,bill_no FROM dd_bk_txns WHERE type='expense' AND voided=0 AND (description LIKE ? OR category LIKE ? OR bill_no LIKE ? OR total=?) ORDER BY date DESC,id DESC $L", $like, $like, $like, $amt),
            fn($r) => ['title' => ($r['category'] ?: 'Expense') . ' · ' . f3_rs((int)$r['total']), 'sub' => $r['date'] . ($r['bill_no'] ? ' · bill ' . $r['bill_no'] : '') . ' · ' . mb_substr((string)$r['description'], 0, 60), 'route' => '#/expenses?from=' . $r['date'] . '&to=' . $r['date']]); }
    if ($want('staff_entries') && f3_can($u, 'staff.ledger')) $add('staff_entries', 'Advances / penalties / overtime', rows("SELECT t.txn_no,t.kind,t.date,t.amount,t.status,t.employee_id,e.name FROM f3_staff_txns t JOIN dd_employees e ON e.id=t.employee_id WHERE t.txn_no LIKE ? OR t.reference LIKE ? OR (e.name LIKE ? AND t.kind IN ('advance','penalty','overtime')) ORDER BY t.id DESC $L", $like, $like, $like),
        fn($r) => ['title' => $r['txn_no'] . ' · ' . $r['kind'] . ' · ' . f3_rs((int)$r['amount']), 'sub' => $r['name'] . ' · ' . $r['date'] . ' · ' . $r['status'], 'route' => '#/staff/' . $r['employee_id']]);
    if ($want('salary') && f3_can($u, 'payroll.view')) {
        $per = []; if (preg_match('/^(\d{4})-(\d{2})$/', $s)) $per[] = $s; foreach (['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'] as $i => $m) if (str_starts_with(strtolower($s), $m)) { $y = preg_match('/(20\d{2})/', $s, $mm) ? $mm[1] : substr(f3_business_date(), 0, 4); $per[] = $y . '-' . sprintf('%02d', $i + 1); }
        $r = $per ? rows('SELECT period,status FROM f3_pay_periods WHERE period IN (' . implode(',', array_fill(0, count($per), '?')) . ')', ...$per) : [];
        $r = array_merge($r, rows("SELECT p.period,p.pay_no,p.amount,p.date,e.name FROM f3_pay_payments p JOIN dd_employees e ON e.id=p.employee_id WHERE p.pay_no LIKE ? OR p.reference LIKE ? ORDER BY p.id DESC $L", $like, $like));
        $add('salary', 'Salary months & payments', $r, fn($x) => isset($x['pay_no']) ? ['title' => $x['pay_no'] . ' · ' . f3_rs((int)$x['amount']), 'sub' => $x['name'] . ' · salary ' . $x['period'] . ' · paid ' . $x['date'], 'route' => '#/payroll/' . $x['period']] : ['title' => 'Salary ' . $x['period'], 'sub' => $x['status'], 'route' => '#/payroll/' . $x['period']]); }
    if ($want('handovers') && (f3_can($u, 'handover.receive') || f3_can($u, 'handover.send'))) { $mine = f3_can($u, 'handover.receive') ? '' : ' AND (from_user=' . (int)$u['id'] . ' OR to_user=' . (int)$u['id'] . ')';
        $add('handovers', 'Cash handovers', rows("SELECT id,ho_no,date,amount,status FROM f3_handovers WHERE (ho_no LIKE ? OR note LIKE ?)$mine ORDER BY id DESC $L", $like, $like), fn($r) => ['title' => $r['ho_no'] . ' · ' . f3_rs((int)$r['amount']), 'sub' => $r['date'] . ' · ' . $r['status'], 'route' => '#/handover']); }
    if ($want('supplier_payments') && f3_can($u, 'supplier.outstanding')) $add('supplier_payments', 'Supplier payments', rows("SELECT sp.pay_no,sp.reference,sp.supplier_id,t.total,t.date,p.name FROM f3_supplier_payments sp JOIN dd_bk_txns t ON t.id=sp.txn_id LEFT JOIN dd_bk_parties p ON p.id=sp.supplier_id WHERE sp.pay_no LIKE ? OR sp.reference LIKE ? ORDER BY sp.txn_id DESC $L", $like, $like),
        fn($r) => ['title' => $r['pay_no'] . ' · ' . f3_rs((int)$r['total']), 'sub' => ($r['name'] ?? '') . ' · ' . $r['date'] . ($r['reference'] ? ' · ref ' . $r['reference'] : ''), 'route' => '#/supplier/' . $r['supplier_id']]);
    return ['q' => $s, 'offset' => $off, 'groups' => $g];
}

/* ============================== Supplier price comparison ============================== */
/** Every posted purchase line of an item, priced per STOCK unit (landed cost_total ÷ base quantity) so 1 kg and a 5 kg bag compare correctly.
 *  Lines without a known base quantity are listed but never averaged. Alert when the latest rate is > threshold % above the median. */
function f3_price_history(int $item, array $u): array {
    f3_need($u, 'purchase.view', 'stock.valuation'); $it = row('SELECT id,name,unit FROM dd_bk_items WHERE id=?', $item); if (!$it) fail('Item not found.', 404);
    $r = rows("SELECT l.bill_id,b.bill_no,b.invoice_date,b.supplier_id,p.name sup,l.qty,l.unit,l.factor,l.base_qty,l.rate,l.discount,l.tax,l.line_total,l.cost_total FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id
        WHERE l.item_id=? AND b.status='posted' ORDER BY b.invoice_date DESC,l.id DESC LIMIT 200", $item);
    $pts = []; $out = [];
    foreach ($r as $x) { $bq = (float)$x['base_qty']; $cost = (int)($x['cost_total'] ?: $x['line_total']); $pu = $bq > 0 ? (int)round($cost / $bq) : null; if ($pu !== null) $pts[] = $pu;
        $out[] = ['bill_id' => (int)$x['bill_id'], 'bill_no' => $x['bill_no'], 'date' => $x['invoice_date'], 'supplier_id' => $x['supplier_id'] ? (int)$x['supplier_id'] : null, 'supplier' => $x['sup'], 'qty' => (float)$x['qty'], 'unit' => $x['unit'], 'base_qty' => $bq, 'rate' => (int)$x['rate'], 'discount' => (int)$x['discount'], 'tax' => (int)$x['tax'], 'cost_total' => $cost, 'per_stock_unit' => $pu]; }
    $stat = fn(array $a) => $a ? ['min' => min($a), 'max' => max($a), 'avg' => (int)round(array_sum($a) / count($a)), 'median' => (function ($a) { sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : (int)round(($a[$n / 2 - 1] + $a[$n / 2]) / 2); })($a), 'n' => count($a)] : null;
    $bySup = []; foreach ($out as $o) if ($o['per_stock_unit'] !== null) { $k = (string)($o['supplier_id'] ?? 0); $bySup[$k] ??= ['supplier' => $o['supplier'] ?? '—', 'supplier_id' => $o['supplier_id'], 'points' => [], 'latest' => $o['per_stock_unit'], 'latest_date' => $o['date']]; $bySup[$k]['points'][] = $o['per_stock_unit']; }
    $sup = array_values(array_map(fn($s) => ['supplier' => $s['supplier'], 'supplier_id' => $s['supplier_id'], 'latest' => $s['latest'], 'latest_date' => $s['latest_date']] + $stat($s['points']), $bySup)); usort($sup, fn($a, $b) => $a['latest'] <=> $b['latest']);
    $all = $stat($pts); $alert = null; $th = max(5, (int)setting('f3_price_alert_pct', '15'));
    if ($all && $all['n'] >= 3 && $out[0]['per_stock_unit'] !== null && $out[0]['per_stock_unit'] > $all['median'] * (100 + $th) / 100) $alert = 'Latest rate ' . f3_rs($out[0]['per_stock_unit']) . '/' . $it['unit'] . ' is more than ' . $th . '% above the usual ' . f3_rs($all['median']) . '/' . $it['unit'] . '.';
    return ['item' => $it, 'unit' => $it['unit'], 'lines' => $out, 'overall' => $all, 'suppliers' => $sup, 'alert' => $alert, 'basis' => 'Landed cost per ' . $it['unit'] . ' (line cost incl. allocated discount / tax / transport ÷ quantity in ' . $it['unit'] . '). Posted bills only.'];
}
/** Items whose latest purchase price jumped above their usual price (for the purchases dashboard / exceptions). */
function f3_price_alerts(array $u, int $limit = 20): array {
    if (!f3_can($u, 'purchase.view') && !f3_can($u, 'stock.valuation')) return [];
    $ids = array_column(rows("SELECT DISTINCT l.item_id FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id WHERE b.status='posted' AND l.item_id IS NOT NULL AND b.invoice_date>=? LIMIT 300", next_date(f3_business_date(), -45)), 'item_id');
    $o = []; foreach ($ids as $id) { $h = f3_price_history((int)$id, $u); if ($h['alert']) $o[] = ['item_id' => (int)$id, 'name' => $h['item']['name'], 'alert' => $h['alert']]; if (count($o) >= $limit) break; } return $o;
}

/* ============================== Document inbox ============================== */
function f3_doc_list(array $q, array $u): array {
    f3_need($u, 'docs.inbox'); $st = (string)($q['status'] ?? 'open');
    $w = $st === 'open' ? "d.status IN ('unclassified','linked')" : (in_array($st, ['unclassified', 'linked', 'approved', 'rejected', 'removed'], true) ? 'd.status=' . db()->quote($st) : '1=1');
    $r = rows("SELECT d.*,f.mime,f.size,f.original_name,u.name by_name FROM f3_doc_inbox d JOIN dd_files f ON f.id=d.file_id LEFT JOIN dd_users u ON u.id=d.uploaded_by WHERE $w ORDER BY d.id DESC LIMIT 200");
    foreach ($r as &$x) { foreach (['id', 'file_id', 'linked_id', 'duplicate_of', 'uploaded_by'] as $k) $x[$k] = $x[$k] === null ? null : (int)$x[$k]; } unset($x);
    return ['rows' => $r, 'counts' => array_column(rows('SELECT status,COUNT(*) n FROM f3_doc_inbox GROUP BY status'), 'n', 'status')];
}
function f3_doc_upload(array $b, array $u): array {
    f3_need($u, 'docs.inbox'); $files = (array)($b['files'] ?? []); if (!$files) fail('Choose a photo or PDF.'); if (count($files) > 10) fail('Up to 10 files at a time.');
    $kind = in_array($b['kind'] ?? '', ['purchase', 'expense', 'other'], true) ? $b['kind'] : 'other'; $o = [];
    foreach ($files as $f) { $fid = f3_upload($f, $u); $sha = row('SELECT sha256 FROM dd_files WHERE id=?', $fid)['sha256'];
        // duplicate warning: the same bytes already in the inbox, or already attached to a bill / expense (prevents double posting from re-uploads)
        $dupInbox = row("SELECT d.id FROM f3_doc_inbox d JOIN dd_files f ON f.id=d.file_id WHERE f.sha256=? AND d.status<>'removed' ORDER BY d.id LIMIT 1", $sha);
        $dupUsed = row("SELECT 'bill' t,bf.bill_id id FROM f3_bill_files bf JOIN dd_files f ON f.id=bf.file_id WHERE f.sha256=? AND bf.removed=0 UNION ALL SELECT 'expense',ef.txn_id FROM f3_expense_files ef JOIN dd_files f ON f.id=ef.file_id WHERE f.sha256=? AND ef.removed=0 LIMIT 1", $sha, $sha);
        run('INSERT INTO f3_doc_inbox(file_id,kind_guess,status,note,uploaded_by,duplicate_of,duplicate_note) VALUES(?,?,?,?,?,?,?)', $fid, $kind, 'unclassified', txt($b['note'] ?? '', 250) ?: null, $u['id'], $dupInbox ? (int)$dupInbox['id'] : null, $dupUsed ? 'Same file already attached to ' . $dupUsed['t'] . ' #' . $dupUsed['id'] : ($dupInbox ? 'Same file already in the inbox (#' . $dupInbox['id'] . ')' : null));
        $o[] = (int)db()->lastInsertId(); }
    f3_notify('doc-inbox', 'docs-' . f3_business_date(), 'Documents waiting in the inbox', count($o) . ' new document(s) to link.', 'docs.inbox', 'docs');
    f3_audit($u, 'docs.upload', ['ids' => $o, 'kind' => $kind]); return ['ok' => true, 'ids' => $o];
}
/** Link = attach the inbox file to an EXISTING expense or purchase bill (no new money entry is ever created here). */
function f3_doc_act(array $b, array $u): array {
    f3_need($u, 'docs.inbox'); $d = row('SELECT * FROM f3_doc_inbox WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0)); if (!$d) fail('Document not found.', 404);
    $act = (string)($b['act'] ?? ''); $note = txt($b['note'] ?? '', 250);
    switch ($act) {
        case 'link':
            if (!in_array($d['status'], ['unclassified', 'linked'], true)) fail('This document is ' . $d['status'] . '.', 409);
            $type = (string)($b['type'] ?? ''); $tid = (int)($b['target_id'] ?? 0);
            if ($type === 'bill') { f3_need($u, 'purchase.edit', 'purchase.upload'); if (!row("SELECT 1 FROM f3_purchase_bills WHERE id=? AND status<>'discarded'", $tid)) fail('Purchase bill not found.', 404); run('INSERT IGNORE INTO f3_bill_files(bill_id,file_id,kind,created_by) VALUES(?,?,?,?)', $tid, $d['file_id'], 'bill', $u['id']); }
            elseif ($type === 'expense') { f3_need($u, 'expenses.update', 'expenses.review'); if (!row("SELECT 1 FROM dd_bk_txns WHERE id=? AND type='expense' AND voided=0", $tid)) fail('Expense not found.', 404); run('INSERT IGNORE INTO f3_expense_files(txn_id,file_id,created_by) VALUES(?,?,?)', $tid, $d['file_id'], $u['id']); }
            else fail('Link to a purchase bill or an expense.');
            run("UPDATE f3_doc_inbox SET status='linked',linked_type=?,linked_id=?,decided_by=?,decided_at=UTC_TIMESTAMP() WHERE id=?", $type, $tid, $u['id'], $d['id']); break;
        case 'approve': if ($d['status'] !== 'linked') fail('Link the document first.', 409); f3_need($u, 'expenses.review', 'purchase.approve', 'accounts.view'); run("UPDATE f3_doc_inbox SET status='approved',decided_by=?,decided_at=UTC_TIMESTAMP(),note=COALESCE(?,note) WHERE id=?", $u['id'], $note ?: null, $d['id']); break;
        case 'reject': case 'remove': if (mb_strlen($note) < 3) fail('Write a reason.');
            if ($d['status'] === 'linked') { if ($d['linked_type'] === 'bill') run('UPDATE f3_bill_files SET removed=1 WHERE bill_id=? AND file_id=?', $d['linked_id'], $d['file_id']); elseif ($d['linked_type'] === 'expense') run('UPDATE f3_expense_files SET removed=1 WHERE txn_id=? AND file_id=?', $d['linked_id'], $d['file_id']); }
            run('UPDATE f3_doc_inbox SET status=?,note=?,decided_by=?,decided_at=UTC_TIMESTAMP() WHERE id=?', $act === 'reject' ? 'rejected' : 'removed', $note, $u['id'], $d['id']); break; // soft: the file itself is kept
        default: fail('Unknown action.');
    }
    f3_audit($u, 'docs.' . $act, ['id' => (int)$d['id'], 'type' => $b['type'] ?? null, 'target' => $b['target_id'] ?? null, 'note' => $note]); return ['ok' => true];
}

/* ============================== Reorder assistant ============================== */
/** Suggested order = max(0, target − available − on order), target = (daily use × (lead time + cover days)) and at least the reorder level.
 *  Daily use comes ONLY from recorded consumption/issue/wastage in the window; items with no recorded use are flagged "no data", never treated as zero. */
function f3_reorder(array $q, array $u): array {
    f3_need($u, 'stock.view'); $days = max(7, min(120, (int)($q['days'] ?? 30))); $cover = max(1, min(60, (int)($q['cover'] ?? setting('f3_reorder_cover_days', '7'))));
    $from = next_date(f3_business_date(), -$days); $val = f3_can($u, 'stock.valuation');
    $items = rows("SELECT i.id,i.name,i.unit,i.category,i.reorder,i.min_level,i.max_level,i.f3_lead_days,COALESCE(b.qty,0) qty FROM dd_bk_items i LEFT JOIN f3_stock_balances b ON b.item_id=i.id AND b.location='Main Store' WHERE i.active=1 AND i.is_stock=1 AND i.merged_into IS NULL ORDER BY i.category,i.name");
    $use = []; foreach (rows("SELECT item_id,-SUM(qty) q,COUNT(*) n FROM f3_stock_moves WHERE date>=? AND kind IN ('consume','issue','wastage','damage') AND qty<0 GROUP BY item_id", $from) as $r) $use[(int)$r['item_id']] = [(float)$r['q'], (int)$r['n']];
    $onOrder = []; foreach (rows("SELECT l.item_id,SUM(GREATEST(l.base_qty-l.received_base+l.returned_base*0,0)) q FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id WHERE b.status IN ('posted','approved','review') AND b.receipt_status<>'received' AND l.item_id IS NOT NULL GROUP BY l.item_id") as $r) $onOrder[(int)$r['item_id']] = (float)$r['q'];
    $o = [];
    foreach ($items as $i) { $id = (int)$i['id']; [$q, $n] = $use[$id] ?? [0.0, 0]; $daily = $n ? $q / $days : null; $lead = $i['f3_lead_days'] !== null ? (int)$i['f3_lead_days'] : (int)setting('f3_default_lead_days', '2');
        $lvl = max((float)$i['reorder'], (float)$i['min_level']); $avail = (float)$i['qty']; $inc = $onOrder[$id] ?? 0.0;
        $target = $daily !== null ? max($lvl, $daily * ($lead + $cover)) : $lvl; if ($i['max_level'] !== null && (float)$i['max_level'] > 0) $target = min($target, (float)$i['max_level']);
        $sug = max(0, round($target - $avail - $inc, 3)); $low = $lvl > 0 && $avail <= $lvl; if (!$low && $sug <= 0) continue;
        $last = row("SELECT b.supplier_id,p.name sup,l.cost_total,l.base_qty,b.invoice_date FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id WHERE l.item_id=? AND b.status='posted' AND l.base_qty>0 ORDER BY b.invoice_date DESC,l.id DESC LIMIT 1", $id);
        $o[] = ['item_id' => $id, 'name' => $i['name'], 'unit' => $i['unit'], 'category' => $i['category'], 'available' => $avail, 'on_order' => $inc, 'reorder_level' => $lvl, 'daily_use' => $daily === null ? null : round($daily, 3), 'use_entries' => $n, 'lead_days' => $lead, 'cover_days' => $cover,
            'suggested' => $sug, 'low' => $low, 'data' => $daily === null ? 'no recorded consumption in ' . $days . ' days — suggestion uses the reorder level only' : 'based on ' . $n . ' recorded issue(s)', 'last_supplier' => $last['sup'] ?? null, 'last_supplier_id' => isset($last['supplier_id']) ? (int)$last['supplier_id'] : null,
            'last_rate' => $val && $last ? (int)round((int)$last['cost_total'] / max(0.001, (float)$last['base_qty'])) : null, 'last_date' => $last['invoice_date'] ?? null]; }
    return ['rows' => $o, 'days' => $days, 'cover' => $cover, 'assumptions' => 'Daily use = recorded consume/issue/wastage ÷ ' . $days . ' days. Target = daily use × (lead time + ' . $cover . ' cover days), at least the reorder level, at most the max level. On order = posted bills not yet received. Nothing is ordered automatically.'];
}

/* ============================== Salary acknowledgement / dispute ============================== */
function f3_ack_list(array $q, array $u): array {
    f3_need($u, 'payroll.ack', 'payroll.approve'); $p = isset($q['period']) ? valid_month($q['period']) : null;
    $r = rows('SELECT a.*,e.name,e.code,pp.pay_no,pp.amount pay_amount,pp.date pay_date,pp.mode pay_mode,u.name by_name FROM f3_pay_acks a JOIN dd_employees e ON e.id=a.employee_id LEFT JOIN f3_pay_payments pp ON pp.id=a.payment_id LEFT JOIN dd_users u ON u.id=a.recorded_by' . ($p ? ' WHERE a.period=' . db()->quote($p) : '') . ' ORDER BY a.status=\'disputed\' DESC,a.id DESC LIMIT 300');
    foreach ($r as &$x) foreach (['amount_claimed', 'pay_amount'] as $k) $x[$k] = $x[$k] === null ? null : (int)$x[$k]; unset($x);
    $pay = $p ? rows('SELECT p.id,p.pay_no,p.employee_id,p.amount,p.date,p.mode,e.name FROM f3_pay_payments p JOIN dd_employees e ON e.id=p.employee_id WHERE p.period=? AND p.voided=0 AND NOT EXISTS (SELECT 1 FROM f3_pay_acks a WHERE a.payment_id=p.id) ORDER BY e.name', $p) : [];
    return ['rows' => $r, 'unacknowledged' => $pay];
}
/** Records that a staff member confirmed receipt of a salary payment, or raised a dispute. Admin-managed (no employee logins exist). Never changes money. */
function f3_ack_save(array $b, array $u): array {
    f3_need($u, 'payroll.ack'); $pay = row('SELECT * FROM f3_pay_payments WHERE id=? AND voided=0', (int)($b['payment_id'] ?? 0)); if (!$pay) fail('Salary payment not found.', 404);
    if (row('SELECT 1 FROM f3_pay_acks WHERE payment_id=?', $pay['id'])) fail('This payment already has an acknowledgement record.', 409);
    $st = (string)($b['status'] ?? ''); if (!in_array($st, ['acknowledged', 'disputed'], true)) fail('Choose acknowledged or disputed.');
    $how = in_array($b['method'] ?? '', ['signature', 'thumb', 'verbal', 'whatsapp', 'other'], true) ? $b['method'] : 'other'; $note = txt($b['note'] ?? '', 500);
    if ($st === 'disputed' && mb_strlen($note) < 5) fail('Write what the staff member disputes.');
    $claim = $st === 'disputed' && isset($b['amount_claimed']) && $b['amount_claimed'] !== '' ? f3_paise($b['amount_claimed'], 'Amount claimed') : null;
    run('INSERT INTO f3_pay_acks(period,employee_id,payment_id,status,method,note,amount_claimed,recorded_by) VALUES(?,?,?,?,?,?,?,?)', $pay['period'], $pay['employee_id'], $pay['id'], $st, $how, $note ?: null, $claim, $u['id']);
    $id = (int)db()->lastInsertId();
    if ($st === 'disputed') f3_notify('pay-dispute', 'payack-' . $id, 'Salary dispute ' . $pay['period'] . ' (' . $pay['pay_no'] . ')', mb_substr($note, 0, 120), 'payroll.approve', 'payroll:' . $pay['period']);
    f3_audit($u, 'payroll.ack_' . $st, ['id' => $id, 'payment' => (int)$pay['id'], 'period' => $pay['period']]); return ['ok' => true, 'id' => $id];
}
function f3_ack_resolve(array $b, array $u): array {
    f3_need($u, 'payroll.approve'); $a = row('SELECT * FROM f3_pay_acks WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0)); if (!$a || $a['status'] !== 'disputed') fail('Only an open dispute can be resolved.', 409);
    $res = f3_text($b['resolution'] ?? '', 500, 'Resolution'); if (mb_strlen($res) < 5) fail('Write how it was resolved (e.g. balance paid in SP-…, or explained).');
    run("UPDATE f3_pay_acks SET status='resolved',resolution=?,resolved_by=?,resolved_at=UTC_TIMESTAMP() WHERE id=?", $res, $u['id'], $a['id']); f3_notify_done('payack-' . $a['id']);
    f3_audit($u, 'payroll.ack_resolve', ['id' => (int)$a['id'], 'resolution' => $res]); return ['ok' => true];
}

/* ============================== Month-end checklist & owner digest ============================== */
/** Every line is computed from records (never from a dismissed popup): done = true only when the underlying data says so. */
function f3_month_end(string $m, array $u): array {
    f3_need($u, 'reports.view', 'accounts.view'); valid_month($m); $a = $m . '-01'; $z = f3_month_last($m); $bd = f3_business_date(); $endDay = min($z, next_date($bd, -1));
    $c = []; $add = function (string $key, string $label, bool $done, string $detail, string $route, string $perm = '') use (&$c, $u) { if ($perm && !f3_can($u, $perm)) return; $c[] = ['key' => $key, 'label' => $label, 'done' => $done, 'detail' => $detail, 'route' => $route]; };
    $start = max($a, f3_closing_start()); $miss = [];
    if ($start <= $endDay) { $have = array_flip(array_column(rows("SELECT business_date FROM f3_closings WHERE business_date BETWEEN ? AND ? AND status IN ('submitted','approved')", $start, $endDay), 'business_date'));
        for ($d = $start; $d <= $endDay; $d = next_date($d, 1)) if (!isset($have[$d])) $miss[] = $d; }
    $add('closings', 'Every day closed (Morning Closing)', !$miss, $miss ? count($miss) . ' day(s) not closed: ' . implode(', ', array_slice($miss, 0, 6)) . (count($miss) > 6 ? ' …' : '') : 'All days ' . $start . ' → ' . $endDay . ' closed', '#/closing', 'closing.view');
    $rev = (int)row("SELECT COUNT(*) n FROM f3_closings WHERE business_date BETWEEN ? AND ? AND status='submitted'", $a, $z)['n'];
    $add('closing_review', 'Cash Acess / Cash Short reviewed', $rev === 0, $rev ? $rev . ' closing(s) waiting for review' : 'All reviewed', '#/cash-acess', 'closing.approve');
    $exp = (int)row("SELECT COUNT(*) n FROM f3_expense_meta WHERE business_date BETWEEN ? AND ? AND review_status='pending'", $a, $z)['n'];
    $add('expenses', 'Expenses reviewed', $exp === 0, $exp ? $exp . ' expense(s) not reviewed' : 'All reviewed', '#/expenses?from=' . $a . '&to=' . $z, 'expenses.review');
    $bills = (int)row("SELECT COUNT(*) n FROM f3_purchase_bills WHERE status IN ('draft','review') AND COALESCE(invoice_date,DATE(created_at))<=?", $z)['n'];
    $add('bills', 'Purchase bills posted (no drafts / pending approval)', $bills === 0, $bills ? $bills . ' bill(s) still draft / waiting' : 'None open', '#/purchases?status=drafts', 'purchase.view');
    $recv = (int)row("SELECT COUNT(*) n FROM f3_purchase_bills WHERE status='posted' AND receipt_status<>'received' AND COALESCE(invoice_date,DATE(created_at))<=?", $z)['n'];
    $add('receipts', 'Goods of posted bills received into stock', $recv === 0, $recv ? $recv . ' bill(s) not fully received' : 'All received', '#/purchases', 'stock.receive');
    $cnt = row("SELECT COUNT(*) n,MAX(status) s FROM f3_stock_counts WHERE date BETWEEN ? AND ? AND status='approved'", $a, next_date($z, 5)); $open = (int)row("SELECT COUNT(*) n FROM f3_stock_counts WHERE status IN ('open','submitted')")['n'];
    $add('stock_count', 'Physical stock count approved for month end', (int)$cnt['n'] > 0 && $open === 0, (int)$cnt['n'] ? ((int)$cnt['n'] . ' approved' . ($open ? ' · ' . $open . ' still open' : '')) : 'No approved count dated ' . $a . ' → ' . next_date($z, 5), '#/count', 'stock.count');
    $per = f3_period($m); $ps = $per['status'] ?? 'not started';
    $add('salary', 'Salary for ' . $m . ' approved', in_array($ps, ['approved', 'locked'], true), 'Status: ' . $ps . ($per ? ' · ' . (int)row("SELECT COUNT(*) n FROM f3_pay_lines WHERE period=? AND state NOT IN ('done','excluded')", $m)['n'] . ' line(s) not complete' : ''), '#/payroll/' . $m, 'payroll.view');
    if ($per && in_array($ps, ['approved', 'locked'], true)) { $due = (int)row("SELECT COALESCE(SUM(l.net_payable+l.carried_forward),0)-(SELECT COALESCE(SUM(amount),0) FROM f3_pay_payments WHERE period=? AND voided=0) n FROM f3_pay_lines l WHERE l.period=? AND l.state='done'", $m, $m)['n'];
        $add('salary_paid', 'Salary paid', $due <= 0, $due > 0 ? f3_rs($due) . ' still due' : 'Fully paid', '#/payroll/' . $m, 'payroll.view');
        $gs = f3_payroll_gl_status($m); $add('salary_gl', 'Salary advance-recovery journal in the ledger', $gs !== 'pending', 'Ledger: ' . $gs, '#/accounts', 'accounts.view'); }
    $disp = (int)row("SELECT COUNT(*) n FROM f3_pay_acks WHERE status='disputed'")['n']; $add('pay_disputes', 'No open salary disputes', $disp === 0, $disp ? $disp . ' open' : 'None', '#/pay-acks', 'payroll.ack');
    $ho = (int)row("SELECT COUNT(*) n FROM f3_handovers WHERE status IN ('sent','disputed','partial') AND date<=?", $z)['n']; $add('handover', 'Cash handovers confirmed', $ho === 0, $ho ? $ho . ' sent / disputed / partial' : 'All confirmed', '#/handover', 'handover.receive');
    $docs = (int)row("SELECT COUNT(*) n FROM f3_doc_inbox WHERE status='unclassified' AND created_at<=?", $z . ' 23:59:59')['n']; $add('docs', 'Document inbox empty', $docs === 0, $docs ? $docs . ' document(s) not linked' : 'Empty', '#/docs', 'docs.inbox');
    if (f3_gl_available() && f3_can($u, 'accounts.view')) { $held = 0; try { require_once dirname(__DIR__) . '/post360.php'; $held = fin_tables() ? (int)row("SELECT COUNT(*) n FROM dd_fin_events WHERE status IN ('pending','held','review') AND (business_date IS NULL OR business_date<=?)", $z)['n'] : 0; } catch (Throwable $e) {}
        $add('gl', 'Ledger updated (no pending events)', $held === 0, $held ? $held . ' event(s) waiting' : 'Up to date', '#/accounts', 'accounts.view'); }
    $exc = (int)row("SELECT COUNT(*) n FROM f3_exceptions WHERE status NOT IN ('resolved','dismissed')")['n']; $add('exceptions', 'No open financial exceptions', $exc === 0, $exc ? $exc . ' open — see Error resolution' : 'None open', '#/exceptions', 'exceptions.manage');
    $bk = null; try { $bk = row("SELECT MAX(finished_at) t FROM f3_job_runs WHERE job='backup' AND status='ok' AND message LIKE 'Backup %'")['t'] ?? null; } catch (Throwable $e) {}
    $add('backup', 'Verified backup after month end', $bk !== null && $bk >= $z, $bk ? 'Last verified ' . $bk . ' (UTC)' : 'No verified backup recorded', '#/settings', 'settings.manage');
    $done = count(array_filter($c, fn($x) => $x['done']));
    return ['month' => $m, 'items' => $c, 'done' => $done, 'total' => count($c), 'complete' => $done === count($c)];
}
/** Plain-text owner digest (for in-app display or an approved AiSensy template parameter). Facts only. */
function f3_owner_digest(array $u, ?string $m = null): array {
    $m ??= f3_prev_month(substr(f3_business_date(), 0, 7)); $me = f3_month_end($m, $u);
    $open = array_values(array_filter($me['items'], fn($x) => !$x['done']));
    $lines = [$me['month'] . ' month-end: ' . $me['done'] . '/' . $me['total'] . ' done.']; foreach (array_slice($open, 0, 8) as $x) $lines[] = '• ' . $x['label'] . ' — ' . $x['detail'];
    return ['month' => $m, 'text' => implode("\n", $lines), 'open' => $open];
}

/* ============================== Financial exception lifecycle ============================== */
const F3_EXC_FLOW = ['open' => ['assigned', 'investigating', 'dismissed'], 'assigned' => ['investigating', 'corrected', 'dismissed'], 'investigating' => ['corrected', 'dismissed'], 'corrected' => ['resolved', 'investigating'], 'resolved' => [], 'dismissed' => ['open']];
/** Turn the existing reconciliation checks (+ a few V23 detectors) into durable records. Detection only — never edits history.
 *  A check that is OK again auto-marks its open record "corrected" (a person still confirms "resolved"). */
function f3_exc_scan(array $u): array {
    f3_need($u, 'exceptions.manage'); $found = [];
    try { $rc = f3_reconcile($u); foreach ($rc['checks'] ?? $rc as $c) { if (!is_array($c) || !isset($c['id'])) continue; if ($c['ok']) continue;
        foreach (array_slice($c['examples'] ?: [['_' => 'check failed']], 0, 25) as $ex) { $txt = is_array($ex) ? json_encode($ex, JSON_UNESCAPED_UNICODE) : (string)$ex;
            $found[] = ['fp' => hash('sha256', $c['id'] . '|' . $txt), 'kind' => $c['id'], 'area' => $c['area'], 'title' => $c['check'], 'detail' => mb_substr($txt, 0, 900), 'route' => $c['link'] ?? '#/reconcile', 'how' => $c['how'] ?? '']; } } } catch (Throwable $e) { $found[] = ['fp' => hash('sha256', 'RC-ERR|' . $e->getMessage()), 'kind' => 'RC-ERR', 'area' => 'System', 'title' => 'Reconciliation could not run', 'detail' => mb_substr($e->getMessage(), 0, 500), 'route' => '#/reconcile', 'how' => '']; }
    // V23 detectors
    foreach (rows("SELECT invoice_key,supplier_id,COUNT(*) n,GROUP_CONCAT(bill_no) b FROM f3_purchase_bills WHERE status='posted' AND invoice_key IS NOT NULL AND invoice_key<>'' GROUP BY supplier_id,invoice_key HAVING n>1 LIMIT 50") as $r)
        $found[] = ['fp' => hash('sha256', 'DUP-INV|' . $r['supplier_id'] . '|' . $r['invoice_key']), 'kind' => 'DUP-INV', 'area' => 'Purchases', 'title' => 'Same supplier invoice number posted more than once', 'detail' => 'Bills ' . $r['b'], 'route' => '#/purchases', 'how' => 'Reverse the duplicate bill or record the approval reason.'];
    foreach (rows("SELECT p.employee_id,p.period,p.amount,p.date,COUNT(*) n,GROUP_CONCAT(p.pay_no) nos FROM f3_pay_payments p WHERE p.voided=0 GROUP BY p.employee_id,p.period,p.amount,p.date HAVING n>1 LIMIT 50") as $r)
        $found[] = ['fp' => hash('sha256', 'DUP-SAL|' . $r['nos']), 'kind' => 'DUP-SAL', 'area' => 'Payroll', 'title' => 'Two identical salary payments on the same day', 'detail' => 'Payments ' . $r['nos'] . ' (' . f3_rs((int)$r['amount']) . ', ' . $r['date'] . ')', 'route' => '#/payroll/' . $r['period'], 'how' => 'Void the duplicate payment with a reason.'];
    foreach (rows("SELECT period FROM f3_pay_periods WHERE status IN ('approved','locked')") as $p) if (f3_payroll_gl_status($p['period']) === 'pending')
        $found[] = ['fp' => hash('sha256', 'GL-PAYREC|' . $p['period']), 'kind' => 'GL-PAYREC', 'area' => 'General ledger', 'title' => 'Salary month approved but its advance-recovery journal is not in the ledger', 'detail' => 'Salary ' . $p['period'], 'route' => '#/accounts', 'how' => 'Accounts → Update ledger.'];
    foreach (rows("SELECT id,ho_no,amount,accepted_amount,status FROM f3_handovers WHERE status IN ('disputed','partial')") as $h)
        $found[] = ['fp' => hash('sha256', 'HO|' . $h['id']), 'kind' => 'HO-DISPUTE', 'area' => 'Cash', 'title' => 'Cash handover not fully accepted', 'detail' => $h['ho_no'] . ': sent ' . f3_rs((int)$h['amount']) . ', accepted ' . f3_rs((int)$h['accepted_amount']), 'route' => '#/handover', 'how' => 'Investigate the difference; classify through Morning Closing review if it is a real shortage.'];
    try { $bk = row("SELECT MAX(finished_at) t FROM f3_job_runs WHERE job='backup' AND status='ok' AND message LIKE 'Backup %'")['t'] ?? null; if (!$bk || $bk < gmdate('Y-m-d H:i:s', time() - 8 * 86400)) $found[] = ['fp' => hash('sha256', 'BACKUP|' . gmdate('Y-W')), 'kind' => 'BACKUP', 'area' => 'System', 'title' => 'No verified backup in the last 8 days', 'detail' => $bk ? 'Last ' . $bk : 'Never', 'route' => '#/settings', 'how' => 'Settings → Backup now, then Verify.']; } catch (Throwable $e) {}
    $new = 0; $seen = [];
    foreach ($found as $f) { $seen[] = $f['fp']; $ex = row('SELECT id,status FROM f3_exceptions WHERE fingerprint=?', $f['fp']);
        if (!$ex) { run('INSERT INTO f3_exceptions(fingerprint,kind,area,title,detail,how,route,status,first_seen,last_seen) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', $f['fp'], $f['kind'], $f['area'], mb_substr($f['title'], 0, 250), $f['detail'], mb_substr($f['how'], 0, 500), mb_substr($f['route'], 0, 120), 'open'); f3_exc_log((int)db()->lastInsertId(), null, 'detected', $f['detail']); $new++; }
        else { run('UPDATE f3_exceptions SET last_seen=UTC_TIMESTAMP(),detail=? WHERE id=?', $f['detail'], $ex['id']); if ($ex['status'] === 'corrected') { run("UPDATE f3_exceptions SET status='investigating' WHERE id=?", $ex['id']); f3_exc_log((int)$ex['id'], null, 'reappeared', 'Check still fails'); } } }
    $fixed = 0; foreach (rows("SELECT id,fingerprint FROM f3_exceptions WHERE status IN ('open','assigned','investigating')") as $r) if (!in_array($r['fingerprint'], $seen, true)) { run("UPDATE f3_exceptions SET status='corrected' WHERE id=?", $r['id']); f3_exc_log((int)$r['id'], null, 'auto_corrected', 'The check no longer finds this problem. Confirm and resolve.'); $fixed++; }
    run("INSERT INTO dd_settings(`key`,value) VALUES('f3_exc_last_scan',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", gmdate('Y-m-d H:i:s'));
    return ['found' => count($found), 'new' => $new, 'auto_corrected' => $fixed];
}
function f3_exc_log(int $id, ?array $u, string $act, string $note = ''): void { run('INSERT INTO f3_exception_log(exception_id,user_id,action,note) VALUES(?,?,?,?)', $id, $u['id'] ?? null, $act, mb_substr($note, 0, 900) ?: null); }
function f3_exc_list(array $q, array $u): array {
    f3_need($u, 'exceptions.manage'); $st = (string)($q['status'] ?? 'active');
    $w = $st === 'active' ? "e.status NOT IN ('resolved','dismissed')" : (isset(F3_EXC_FLOW[$st]) ? 'e.status=' . db()->quote($st) : '1=1');
    $r = rows("SELECT e.*,u.name assigned_name FROM f3_exceptions e LEFT JOIN dd_users u ON u.id=e.assigned_to WHERE $w ORDER BY FIELD(e.status,'open','assigned','investigating','corrected','resolved','dismissed'),e.last_seen DESC LIMIT 300");
    $log = []; if (!empty($q['id'])) $log = rows('SELECT l.*,u.name by_name FROM f3_exception_log l LEFT JOIN dd_users u ON u.id=l.user_id WHERE l.exception_id=? ORDER BY l.id', (int)$q['id']);
    return ['rows' => $r, 'log' => $log, 'flow' => F3_EXC_FLOW, 'counts' => array_column(rows('SELECT status,COUNT(*) n FROM f3_exceptions GROUP BY status'), 'n', 'status'), 'last_scan' => setting('f3_exc_last_scan', ''), 'users' => rows('SELECT id,name FROM dd_users WHERE active=1 ORDER BY name')];
}
function f3_exc_act(array $b, array $u): array {
    f3_need($u, 'exceptions.manage'); $e = row('SELECT * FROM f3_exceptions WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0)); if (!$e) fail('Exception not found.', 404);
    $to = (string)($b['to'] ?? ''); $note = txt($b['note'] ?? '', 900);
    if ($to === 'comment') { if (mb_strlen($note) < 2) fail('Write a note.'); f3_exc_log((int)$e['id'], $u, 'comment', $note); return ['ok' => true]; }
    if (!in_array($to, F3_EXC_FLOW[$e['status']] ?? [], true)) fail('Cannot move from ' . $e['status'] . ' to ' . $to . '.', 409);
    if (in_array($to, ['dismissed', 'resolved', 'corrected'], true) && mb_strlen($note) < 5) fail('Write a reason / what was done (at least 5 characters).');
    if ($to === 'dismissed' && !f3_can($u, 'closing.reopen') && ($u['role'] ?? '') !== 'owner') fail('Only the owner / admin can dismiss a detected problem.', 403);
    $as = $e['assigned_to']; if ($to === 'assigned') { $as = (int)($b['assigned_to'] ?? 0); if (!row('SELECT 1 FROM dd_users WHERE id=? AND active=1', $as)) fail('Choose a person.'); }
    run('UPDATE f3_exceptions SET status=?,assigned_to=?,resolved_by=?,resolved_at=?,resolution_note=? WHERE id=?', $to, $as, in_array($to, ['resolved', 'dismissed'], true) ? $u['id'] : null, in_array($to, ['resolved', 'dismissed'], true) ? gmdate('Y-m-d H:i:s') : null, in_array($to, ['resolved', 'dismissed', 'corrected'], true) ? $note : $e['resolution_note'], $e['id']);
    f3_exc_log((int)$e['id'], $u, $to, $note); f3_audit($u, 'exception.' . $to, ['id' => (int)$e['id'], 'kind' => $e['kind'], 'note' => $note]);
    return ['ok' => true];
}
