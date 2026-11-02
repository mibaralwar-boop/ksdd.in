<?php
declare(strict_types=1);
// Finance 360 V17 — Suppliers (canonical: Books 360 dd_bk_parties, extended), Purchase Bills, supplier payments with bill allocation, returns / notes, aging.
// Posting rules (ACCOUNTING_RULES.md §7):
//   Purchase bill posted  → ONE Books 360 'purchase' txn (total = grand total, paid = 0) → raises the supplier payable. Not an expense entry, not cash.
//   Goods received        → stock 'receipt' movements (quantity & cost sub-ledger only — no second Books posting).
//   Supplier payment      → ONE Books 360 'payment_out' txn from the chosen account; f3_supplier_allocations links it to bills (advance = unallocated part).
//   Return / credit note  → ONE Books 360 'purchase_return' txn (reduces payable by total − refund; refund comes into the chosen account) + 'return_out' stock moves.
//   Supplier extra charge → ONE Books 360 'purchase' txn with no stock.
require_once __DIR__ . '/stock.php';

function f3_spaise(mixed $v, string $label, int $maxAbs): int { // signed paise (rounding)
    $s = trim(str_replace([',', '₹', ' '], '', (string)($v ?? ''))); if ($s === '' || $s === '0') return 0;
    if (!preg_match('/^-?\d{1,6}(\.\d{1,2})?$/', $s)) fail($label . ': enter an amount like 0.40 or -0.40.');
    $neg = str_starts_with($s, '-'); $p = f3_paise(ltrim($s, '-'), $label); if ($p > $maxAbs) fail($label . ' cannot be more than ' . f3_rs2($maxAbs) . '.'); return $neg ? -$p : $p;
}
function f3_fy(string $d): string { $y = (int)substr($d, 0, 4); return (int)substr($d, 5, 2) >= 4 ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y; }
function f3_inv_key(string $s): string { return substr(preg_replace('/[^a-z0-9]/', '', strtolower($s)), 0, 60); }
function f3_supplier(int $id, bool $lock = false): array { $r = row("SELECT * FROM dd_bk_parties WHERE id=? AND kind IN ('supplier','both')" . ($lock ? ' FOR UPDATE' : ''), $id); if (!$r) fail('Supplier not found.', 404); return $r; }
function f3_mode_account(string $mode): string { return f3_acct(['Cash' => 'cash', 'UPI' => 'upi', 'Bank' => 'bank', 'Card' => 'card'][$mode] ?? fail('Choose payment mode Cash / UPI / Bank / Card.')); }

/* ------------------------------ bill money helpers ------------------------------ */
/** Allocated payments + linked credits for each bill. */
function f3_bill_settled(array $ids): array {
    $o = array_fill_keys($ids, ['paid' => 0, 'credit' => 0]); if (!$ids) return $o; $in = implode(',', array_map('intval', $ids));
    foreach (rows("SELECT a.bill_id,SUM(a.amount) s FROM f3_supplier_allocations a JOIN dd_bk_txns t ON t.id=a.payment_txn_id AND t.voided=0 WHERE a.voided=0 AND a.bill_id IN ($in) GROUP BY a.bill_id") as $r) $o[(int)$r['bill_id']]['paid'] = (int)$r['s'];
    foreach (rows("SELECT r.bill_id,SUM(r.total-r.refund) s FROM f3_purchase_returns r JOIN dd_bk_txns t ON t.id=r.bk_txn_id AND t.voided=0 WHERE r.kind<>'charge' AND r.bill_id IN ($in) GROUP BY r.bill_id") as $r) $o[(int)$r['bill_id']]['credit'] = (int)$r['s'];
    return $o;
}
function f3_bill_money(array $b, array $s): array {
    $g = (int)$b['grand_total']; $out = max(0, $g - $s['paid'] - $s['credit']); $bd = f3_business_date();
    $ps = $b['status'] !== 'posted' ? null : ($out === 0 ? 'paid' : ($s['paid'] + $s['credit'] > 0 ? 'partial' : 'unpaid'));
    return ['paid' => $s['paid'], 'credited' => $s['credit'], 'outstanding' => $b['status'] === 'posted' ? $out : 0, 'payment_status' => $ps,
            'overdue_days' => ($ps && $ps !== 'paid' && $b['due_date'] && $b['due_date'] < $bd) ? (new DateTimeImmutable($b['due_date']))->diff(new DateTimeImmutable($bd))->days : 0];
}
function f3_open_bills(int $sid, bool $lock = false): array {
    $b = rows("SELECT * FROM f3_purchase_bills WHERE supplier_id=? AND status='posted' ORDER BY COALESCE(due_date,post_date),id" . ($lock ? ' FOR UPDATE' : ''), $sid);
    $s = f3_bill_settled(array_map(fn($x) => (int)$x['id'], $b)); $o = [];
    foreach ($b as $x) { $m = f3_bill_money($x, $s[(int)$x['id']]); if ($m['outstanding'] > 0) $o[] = ['id' => (int)$x['id'], 'bill_no' => $x['bill_no'], 'invoice_no' => $x['invoice_no'], 'post_date' => $x['post_date'], 'due_date' => $x['due_date'], 'grand_total' => (int)$x['grand_total']] + $m; }
    return $o;
}
function f3_payment_unallocated(int $txn): int { $t = row('SELECT paid FROM dd_bk_txns WHERE id=? AND voided=0', $txn); if (!$t) return 0; return (int)$t['paid'] - (int)row('SELECT COALESCE(SUM(amount),0) s FROM f3_supplier_allocations WHERE payment_txn_id=? AND voided=0', $txn)['s']; }

/* ------------------------------ suppliers ------------------------------ */
function f3_sup_balances(): array { $o = []; foreach (bk_party_balances() as $p) if (in_array($p['kind'], ['supplier', 'both'], true) && !$p['merged_into']) $o[$p['id']] = $p; return $o; }
function f3_sup_list(array $q, array $u): array {
    f3_need($u, 'suppliers.view', 'purchase.create'); $money = f3_can($u, 'supplier.outstanding') || f3_can($u, 'suppliers.view');
    $bal = f3_sup_balances(); $bd = f3_business_date();
    $od = []; foreach (rows("SELECT b.id,b.supplier_id,b.grand_total,b.due_date,b.status FROM f3_purchase_bills b WHERE b.status='posted' AND b.due_date IS NOT NULL AND b.due_date<?", $bd) as $b) $od[] = $b;
    $st = f3_bill_settled(array_map(fn($x) => (int)$x['id'], $od)); $over = [];
    foreach ($od as $b) { $m = f3_bill_money($b, $st[(int)$b['id']]); if ($m['outstanding'] > 0) $over[(int)$b['supplier_id']] = ($over[(int)$b['supplier_id']] ?? 0) + $m['outstanding']; }
    $bills = []; foreach (rows("SELECT supplier_id,COUNT(*) n,SUM(grand_total) t,MAX(post_date) d FROM f3_purchase_bills WHERE status='posted' GROUP BY supplier_id") as $r) $bills[(int)$r['supplier_id']] = $r;
    $s = mb_strtolower(trim((string)($q['q'] ?? ''))); $status = $q['status'] ?? 'active'; $o = []; $t = ['payable' => 0, 'advance' => 0, 'overdue' => 0, 'count' => 0];
    foreach ($bal as $p) {
        if ($status === 'active' && !(int)$p['active']) continue; if ($status === 'inactive' && (int)$p['active']) continue;
        if ($s !== '' && !str_contains(mb_strtolower($p['name'] . ' ' . ($p['code'] ?? '') . ' ' . ($p['phone'] ?? '') . ' ' . ($p['gstin'] ?? '') . ' ' . ($p['aliases'] ?? '') . ' ' . ($p['city'] ?? '')), $s)) continue;
        $x = ['id' => $p['id'], 'code' => $p['code'] ?? null, 'name' => $p['name'], 'phone' => $p['phone'], 'whatsapp' => $p['whatsapp'] ?? null, 'city' => $p['city'] ?? null, 'gstin' => $p['gstin'], 'active' => (int)$p['active'], 'credit_days' => (int)$p['credit_days'],
              'photo_file_id' => $p['photo_file_id'] ? (int)$p['photo_file_id'] : null, 'last_paid' => $p['last_paid'], 'last_purchase' => max((string)($bills[$p['id']]['d'] ?? ''), (string)($p['last_date'] ?? '')) ?: null, 'bills' => (int)($bills[$p['id']]['n'] ?? 0)];
        if ($money) { $x['balance'] = $p['balance']; $x['purchases'] = $p['purchases']; $x['overdue'] = $over[$p['id']] ?? 0; $x['credit_limit'] = $p['credit_limit'] !== null ? (int)$p['credit_limit'] : null;
            if ($p['balance'] > 0) $t['payable'] += $p['balance']; else $t['advance'] -= $p['balance']; $t['overdue'] += $x['overdue']; }
        $o[] = $x; $t['count']++;
    }
    $sort = $q['sort'] ?? 'name'; if ($money && $sort === 'balance') usort($o, fn($a, $b) => $b['balance'] <=> $a['balance']); elseif ($money && $sort === 'overdue') usort($o, fn($a, $b) => $b['overdue'] <=> $a['overdue']); elseif ($sort === 'recent') usort($o, fn($a, $b) => strcmp((string)$b['last_purchase'], (string)$a['last_purchase']));
    $lim = min(500, max(10, (int)($q['limit'] ?? 100))); $page = max(0, (int)($q['page'] ?? 0));
    return ['suppliers' => array_slice($o, $page * $lim, $lim), 'total' => count($o), 'page' => $page, 'limit' => $lim, 'totals' => $money ? $t : ['count' => $t['count']], 'categories' => array_keys(bk_categories())];
}
function f3_sup_out(array $p, array $u): array {
    $o = array_intersect_key($p, array_flip(['id', 'code', 'name', 'legal_name', 'contact_person', 'phone', 'whatsapp', 'phone2', 'email', 'address', 'city', 'state', 'pin', 'gstin', 'bank_name', 'bank_last4', 'ifsc', 'upi_id', 'credit_days', 'credit_limit', 'default_terms', 'default_category', 'note', 'aliases', 'active', 'opening', 'opening_as_of', 'photo_file_id', 'created_at']));
    $o['id'] = (int)$p['id']; $o['active'] = (int)$p['active']; $o['opening'] = (int)$p['opening']; $o['credit_days'] = (int)$p['credit_days'];
    $pan = f3_dec($p['pan_enc'] ?? null); $o['pan_masked'] = $pan ? f3_mask($pan) : null; $o['bank_account_masked'] = $p['bank_last4'] ? '••••' . $p['bank_last4'] : null;
    if (f3_can($u, 'supplier.edit') && !empty($GLOBALS['F3_REVEAL'])) { $o['pan'] = $pan; $o['bank_account'] = f3_dec($p['bank_account_enc'] ?? null); }
    return $o;
}
function f3_sup_get(int $id, array $u, bool $reveal = false): array {
    f3_need($u, 'suppliers.view'); $p = f3_supplier($id); $GLOBALS['F3_REVEAL'] = $reveal; if ($reveal) { f3_need($u, 'supplier.edit'); f3_audit($u, 'supplier.reveal', ['id' => $id]); }
    $bal = f3_sup_balances()[$id] ?? null; $bills = rows("SELECT * FROM f3_purchase_bills WHERE supplier_id=? AND status IN ('posted','review','reversed') ORDER BY COALESCE(post_date,invoice_date) DESC,id DESC LIMIT 300", $id);
    $st = f3_bill_settled(array_map(fn($x) => (int)$x['id'], $bills));
    $bl = array_map(fn($b) => ['id' => (int)$b['id'], 'bill_no' => $b['bill_no'], 'invoice_no' => $b['invoice_no'], 'invoice_date' => $b['invoice_date'], 'post_date' => $b['post_date'], 'due_date' => $b['due_date'], 'status' => $b['status'], 'receipt_status' => $b['receipt_status'], 'grand_total' => (int)$b['grand_total']] + f3_bill_money($b, $st[(int)$b['id']]), $bills);
    $pays = rows("SELECT t.id txn_id,t.date,t.paid amount,t.account,t.voided,t.void_reason,t.description,sp.pay_no,sp.kind,sp.reference,sp.file_id,(SELECT COALESCE(SUM(a.amount),0) FROM f3_supplier_allocations a WHERE a.payment_txn_id=t.id AND a.voided=0) allocated
        FROM dd_bk_txns t LEFT JOIN f3_supplier_payments sp ON sp.txn_id=t.id WHERE t.party_id=? AND t.type IN ('payment_out','payment_in') ORDER BY t.date DESC,t.id DESC LIMIT 300", $id);
    foreach ($pays as &$x) { foreach (['txn_id', 'amount', 'voided', 'allocated'] as $k) $x[$k] = (int)$x[$k]; $x['unallocated'] = $x['kind'] === 'refund' || !$x['pay_no'] ? 0 : $x['amount'] - $x['allocated']; $x['allocations'] = rows('SELECT a.bill_id,a.amount,b.bill_no,b.invoice_no FROM f3_supplier_allocations a JOIN f3_purchase_bills b ON b.id=a.bill_id WHERE a.payment_txn_id=? AND a.voided=0', $x['txn_id']); } unset($x);
    $rets = rows('SELECT r.*,b.bill_no,t.voided FROM f3_purchase_returns r LEFT JOIN f3_purchase_bills b ON b.id=r.bill_id LEFT JOIN dd_bk_txns t ON t.id=r.bk_txn_id WHERE r.supplier_id=? ORDER BY r.date DESC,r.id DESC', $id);
    $files = rows("SELECT f.file_id,f.kind,b.bill_no,b.id bill_id,d.mime,d.original_name,d.created_at FROM f3_bill_files f JOIN f3_purchase_bills b ON b.id=f.bill_id JOIN dd_files d ON d.id=f.file_id WHERE b.supplier_id=? AND f.removed=0 ORDER BY f.id DESC LIMIT 200", $id);
    foreach (rows("SELECT sp.file_id,'receipt' kind,sp.pay_no bill_no,NULL bill_id,d.mime,d.original_name,d.created_at FROM f3_supplier_payments sp JOIN dd_files d ON d.id=sp.file_id WHERE sp.supplier_id=?", $id) as $f) $files[] = $f;
    $aud = rows("SELECT a.at created_at,a.action,u.name by_name,a.detail details FROM dd_audit a LEFT JOIN dd_users u ON u.id=a.user_id WHERE a.action LIKE 'f360.supplier%' AND JSON_EXTRACT(a.detail,'$.id')=? ORDER BY a.id DESC LIMIT 100", $id);
    $stmt = bk_party_statement($id);
    $money = f3_can($u, 'supplier.outstanding') || f3_can($u, 'suppliers.view');
    return ['supplier' => f3_sup_out($p, $u), 'balance' => $bal ? $bal['balance'] : 0, 'purchases_total' => $bal['purchases'] ?? 0, 'returns_total' => $bal['returns'] ?? 0, 'bills' => $bl, 'payments' => $pays, 'returns' => $rets, 'files' => $files, 'audit' => $aud,
            'ledger' => $money ? $stmt['txns'] : [], 'open_bills' => f3_open_bills($id), 'aging' => f3_aging_one($id), 'advance_available' => array_sum(array_map(fn($x) => $x['voided'] ? 0 : $x['unallocated'], $pays)),
            'can' => ['edit' => f3_can($u, 'supplier.edit'), 'pay' => f3_can($u, 'supplier.pay'), 'adjust' => f3_can($u, 'supplier.adjust'), 'deactivate' => f3_can($u, 'supplier.deactivate'), 'purchase' => f3_can($u, 'purchase.create')]];
}
function f3_sup_save(array $b, array $u): array {
    $id = (int)($b['id'] ?? 0); f3_need($u, $id ? 'supplier.edit' : 'supplier.create');
    $old = $id ? f3_supplier($id, true) : null;
    $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($b['name'] ?? ''))), 0, 160); if ($name === '') fail('Supplier display name is required.');
    $k = bk_key($name); $dup = row('SELECT id,name,kind,active FROM dd_bk_parties WHERE name_key=? AND id<>?', $k, $id);
    if ($dup) throw new F3Conflict('“' . $dup['name'] . '” already exists' . ((int)$dup['active'] ? '' : ' (inactive)') . '. Open it instead of creating a duplicate.', ['existing_id' => (int)$dup['id'], 'existing_name' => $dup['name']]);
    $gst = strtoupper(preg_replace('/\s+/', '', (string)($b['gstin'] ?? '')));
    if ($gst !== '') { if (!preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $gst)) fail('GSTIN looks wrong (15 characters, e.g. 09ABCDE1234F1Z5).'); if ($d = row('SELECT name FROM dd_bk_parties WHERE gstin=? AND id<>?', $gst, $id)) fail('GSTIN ' . $gst . ' is already used by “' . $d['name'] . '”.', 409); }
    $phone = preg_replace('/\D/', '', (string)($b['phone'] ?? '')); if ($phone !== '' && !preg_match('/^(91)?[6-9]\d{9}$/', $phone)) fail('Mobile number should be 10 digits.');
    $phone = $phone !== '' ? substr($phone, -10) : '';
    if ($phone !== '' && empty($b['confirm_phone']) && ($d = row('SELECT name FROM dd_bk_parties WHERE phone=? AND id<>? AND kind IN (\'supplier\',\'both\')', $phone, $id))) throw new F3Conflict('“' . $d['name'] . '” has the same mobile number. Save anyway?', ['needs' => 'confirm_phone']);
    $email = trim((string)($b['email'] ?? '')); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Email address looks wrong.');
    $pin = trim((string)($b['pin'] ?? '')); if ($pin !== '' && !preg_match('/^\d{6}$/', $pin)) fail('PIN code must be 6 digits.');
    $ifsc = strtoupper(trim((string)($b['ifsc'] ?? ''))); if ($ifsc !== '' && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) fail('IFSC looks wrong (e.g. BKID0001234).');
    $openingIn = $b['opening'] ?? null; $opening = $old ? (int)$old['opening'] : 0;
    if ($openingIn !== null && $openingIn !== '') { $neg = str_starts_with(trim((string)$openingIn), '-'); $np = f3_paise(ltrim(trim((string)$openingIn), '-'), 'Opening balance'); $np = $neg ? -$np : $np;
        if ($np !== $opening) { f3_need($u, 'supplier.adjust'); $opening = $np; } }
    $r = bk_party_save(['id' => $id ?: null, 'name' => $name, 'kind' => $old['kind'] ?? 'supplier', 'phone' => $phone, 'gstin' => $gst, 'opening' => rupees($opening), 'credit_days' => max(0, min(365, (int)($b['credit_days'] ?? ($old['credit_days'] ?? 0)))),
        'aliases' => $b['aliases'] ?? ($old['aliases'] ?? ''), 'note' => $b['note'] ?? ($old['note'] ?? ''), 'inactive' => $old && !(int)$old['active'] ? 1 : 0], $u);
    $id = (int)$r['id'];
    $x = ['legal_name' => txt($b['legal_name'] ?? '', 160) ?: null, 'contact_person' => txt($b['contact_person'] ?? '', 120) ?: null, 'whatsapp' => substr(preg_replace('/\D/', '', (string)($b['whatsapp'] ?? '')), -10) ?: null, 'phone2' => txt($b['phone2'] ?? '', 20) ?: null,
          'email' => $email ?: null, 'address' => txt($b['address'] ?? '', 400) ?: null, 'city' => txt($b['city'] ?? '', 80) ?: null, 'state' => txt($b['state'] ?? '', 80) ?: null, 'pin' => $pin ?: null, 'bank_name' => txt($b['bank_name'] ?? '', 80) ?: null, 'ifsc' => $ifsc ?: null,
          'upi_id' => txt($b['upi_id'] ?? '', 80) ?: null, 'credit_limit' => ($b['credit_limit'] ?? '') === '' ? null : f3_paise($b['credit_limit'], 'Credit limit'), 'default_terms' => txt($b['default_terms'] ?? '', 120) ?: null,
          'default_category' => txt($b['default_category'] ?? '', 80) ?: null, 'opening_as_of' => !empty($b['opening_as_of']) ? valid_date($b['opening_as_of']) : ($old['opening_as_of'] ?? null), 'updated_at' => gmdate('Y-m-d H:i:s')];
    if (!$old || !$old['code']) $x['code'] = txt($b['code'] ?? '', 30) ?: 'SUP' . str_pad((string)$id, 4, '0', STR_PAD_LEFT); elseif (!empty($b['code'])) $x['code'] = txt($b['code'], 30);
    if (isset($x['code']) && ($d = row('SELECT name FROM dd_bk_parties WHERE code=? AND id<>?', $x['code'], $id))) fail('Supplier code ' . $x['code'] . ' is used by “' . $d['name'] . '”.', 409);
    if (array_key_exists('pan', $b)) { $pan = strtoupper(trim((string)$b['pan'])); if ($pan !== '' && !preg_match('/^[A-Z]{5}\d{4}[A-Z]$/', $pan)) fail('PAN looks wrong (e.g. ABCDE1234F).');
        if ($pan !== '') { $e = f3_enc($pan); if (!$e) fail('PAN cannot be stored: the encryption key (pii_key) is not set in config.php.', 503); $x['pan_enc'] = $e; } elseif (!empty($b['clear_pan'])) $x['pan_enc'] = null; }
    if (!empty($b['bank_account'])) { $ac = preg_replace('/\s/', '', (string)$b['bank_account']); if (!preg_match('/^\d{6,20}$/', $ac)) fail('Bank account number should be 6–20 digits.'); $e = f3_enc($ac); if (!$e) fail('Bank account cannot be stored: pii_key missing in config.php.', 503); $x['bank_account_enc'] = $e; $x['bank_last4'] = substr($ac, -4); }
    if (!empty($b['photo']['data'])) $x['photo_file_id'] = f3_image_upload($b['photo'], $u);
    run('UPDATE dd_bk_parties SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($x))) . ' WHERE id=?', ...[...array_values($x), $id]);
    f3_audit($u, $old ? 'supplier.update' : 'supplier.create', ['id' => $id, 'name' => $name, 'opening_changed' => $old && (int)$old['opening'] !== $opening]);
    return ['ok' => true, 'id' => $id, 'supplier' => f3_sup_out(f3_supplier($id), $u)];
}
function f3_sup_active(array $b, array $u): array { f3_need($u, 'supplier.deactivate'); $p = f3_supplier((int)($b['id'] ?? 0), true); $on = !empty($b['active']) ? 1 : 0;
    if (!$on) { $bal = f3_sup_balances()[(int)$p['id']]['balance'] ?? 0; if ($bal !== 0 && empty($b['force'])) throw new F3Conflict('This supplier still has a balance of ' . f3_rs($bal) . '. Deactivate anyway? (All history stays.)', ['needs' => 'force']);
        if (row("SELECT 1 FROM f3_purchase_bills WHERE supplier_id=? AND status IN ('draft','review') LIMIT 1", $p['id']) && empty($b['force'])) throw new F3Conflict('This supplier has draft/pending bills. Deactivate anyway?', ['needs' => 'force']); }
    run('UPDATE dd_bk_parties SET active=? WHERE id=?', $on, $p['id']); f3_audit($u, $on ? 'supplier.activate' : 'supplier.deactivate', ['id' => (int)$p['id']]); return ['ok' => true]; }

/* ------------------------------ purchase bills ------------------------------ */
/** Server-authoritative line & bill arithmetic (integer paise / milli-units). Returns computed lines + header totals. */
function f3_bill_compute(array $h, array $lines, bool $strict): array {
    $out = []; $sub = 0; $ld = 0; $lt = 0; $taxable = 0; $n = 0;
    foreach ($lines as $i => $l) {
        $lab = 'Row ' . ($i + 1); $iid = (int)($l['item_id'] ?? 0);
        if (!$iid) { if ($strict) fail($lab . ': choose an item (or add a new item).'); continue; }
        $item = f3_item($iid); if ($item['merged_into']) fail($lab . ': “' . $item['name'] . '” was merged into another item.'); if (!(int)$item['active']) fail($lab . ': “' . $item['name'] . '” is inactive.');
        $unit = f3_unit_norm((string)($l['unit'] ?? '') ?: (string)($item['purchase_unit'] ?: $item['unit']));
        if (!empty($l['factor']) && !row('SELECT 1 FROM f3_item_units WHERE item_id=? AND unit=?', $iid, $unit) && $unit !== f3_unit_norm((string)$item['unit']) && !isset(F3_STD_UNITS[$unit]))
            run('INSERT INTO f3_item_units(item_id,unit,factor,note) VALUES(?,?,?,?)', $iid, $unit, f3_fmt_micro(f3_micro($l['factor'])), 'Defined on purchase bill');
        $fm = f3_unit_factor_micro($item, $unit);
        $qs = trim((string)($l['qty'] ?? '')); if ($qs === '' && !$strict) $qs = '0'; $qm = f3_milli($qs, $lab . ' quantity', !$strict);
        $rate = f3_paise($l['rate'] ?? ($strict ? null : '0'), $lab . ' rate') ?? 0;
        $gross = intdiv($qm * $rate * 2 + 1000, 2000); // round half up
        $dt = ($l['disc_type'] ?? 'amt') === 'pct' ? 'pct' : 'amt';
        if ($dt === 'pct') { $pct = (float)($l['disc_value'] ?? 0); if ($pct < 0 || $pct > 100) fail($lab . ': discount % must be 0–100.'); $dv = (int)round($pct * 100); $disc = intdiv($gross * $dv * 2 + 10000, 20000); }
        else { $dv = f3_paise(($l['disc_value'] ?? '') === '' ? '0' : $l['disc_value'], $lab . ' discount'); $disc = $dv; }
        if ($disc > $gross) fail($lab . ': discount is more than the amount.');
        $tbp = (int)round((float)($l['tax_rate'] ?? ($item['tax_rate_bp'] / 100)) * 100); if ($tbp < 0 || $tbp > 5000) fail($lab . ': tax % must be 0–50.');
        $tx = intdiv(($gross - $disc) * $tbp * 2 + 10000, 20000);
        $lineTotal = $gross - $disc + $tx;
        $exp = !empty($l['expiry']) ? valid_date($l['expiry']) : null; $mrp = ($l['mrp'] ?? '') === '' ? null : f3_paise($l['mrp'], $lab . ' MRP');
        $out[] = ['sort' => $n++, 'item_id' => $iid, 'item_name' => $item['name'], 'is_stock' => (int)$item['is_stock'], 'unit' => $unit, 'factor_micro' => $fm, 'base_unit' => $item['unit'] ?: 'piece', 'qty_milli' => $qm, 'base_milli' => f3_to_base($qm, $fm),
                  'rate' => $rate, 'gross' => $gross, 'disc_type' => $dt, 'disc_value' => $dv, 'discount' => $disc, 'tax_rate_bp' => $tbp, 'tax' => $tx, 'line_total' => $lineTotal, 'batch' => txt($l['batch'] ?? '', 60) ?: null, 'expiry' => $exp, 'mrp' => $mrp,
                  'notes' => txt($l['notes'] ?? '', 250) ?: null, 'photo_file_id' => !empty($l['photo_file_id']) ? (int)$l['photo_file_id'] : null, 'id' => (int)($l['id'] ?? 0), 'photo' => $l['photo'] ?? null];
        $sub += $gross; $ld += $disc; $lt += $tx; $taxable += $gross - $disc;
    }
    $bdisc = f3_paise(($h['bill_discount'] ?? '') === '' ? '0' : $h['bill_discount'], 'Bill discount'); $tr = f3_paise(($h['transport'] ?? '') === '' ? '0' : $h['transport'], 'Transport / loading');
    $ot = f3_paise(($h['other_tax'] ?? '') === '' ? '0' : $h['other_tax'], 'Other tax / cess'); $rnd = f3_spaise($h['rounding'] ?? '0', 'Rounding', 500);
    $grand = $sub - $ld + $lt - $bdisc + $tr + $ot + $rnd;
    if ($bdisc > $taxable + $lt) fail('Bill discount is more than the bill amount.');
    if ($strict) { if (!$out) fail('Add at least one item.'); if ($grand <= 0) fail('Bill total must be more than zero.'); foreach ($out as $i => $l) if ($l['base_milli'] <= 0) fail('Row ' . ($i + 1) . ': quantity is too small.'); }
    // Cost to stock: taxable (+ tax if input tax is not claimed) ± bill-level charges apportioned by taxable value; the last line takes the remainder (no paise lost).
    $taxIn = setting('f3_tax_in_cost', '1') === '1'; $pool = $tr - $bdisc + $rnd + ($taxIn ? $ot : 0); $base = max(1, $taxable); $left = $pool; $cnt = count($out);
    foreach ($out as $i => &$l) { $own = $l['gross'] - $l['discount'] + ($taxIn ? $l['tax'] : 0); $share = $i === $cnt - 1 ? $left : intdiv(($l['gross'] - $l['discount']) * $pool, $base); $left -= $share; $l['cost_total'] = max(0, $own + $share); } unset($l);
    return ['lines' => $out, 'subtotal' => $sub, 'line_discount' => $ld, 'line_tax' => $lt, 'bill_discount' => $bdisc, 'transport' => $tr, 'other_tax' => $ot, 'rounding' => $rnd, 'grand_total' => $grand];
}
function f3_bill(int $id, bool $lock = false): array { $r = row('SELECT * FROM f3_purchase_bills WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), $id); if (!$r) fail('Purchase bill not found.', 404); return $r; }
/** Save a DRAFT (header, lines, photos). Nothing is posted to Books or stock while a bill is a draft. */
function f3_bill_save(array $b, array $u): array {
    f3_need($u, 'purchase.create'); $id = (int)($b['id'] ?? 0); $old = $id ? f3_bill($id, true) : null;
    if ($old && $old['status'] !== 'draft') fail('Only a draft can be edited. Posted bills are changed by a return / credit note or a reversal.', 409);
    if ($old && (int)$old['created_by'] !== (int)$u['id'] && !f3_can($u, 'purchase.edit')) fail('This draft belongs to another user.', 403);
    if ($old && isset($b['version']) && (int)$b['version'] !== (int)$old['version']) fail('This draft was changed on another device. Reload it before saving.', 409);
    $sid = !empty($b['supplier_id']) ? (int)$b['supplier_id'] : null; if ($sid) { $s = f3_supplier($sid); if (!(int)$s['active']) fail('This supplier is inactive.'); }
    $dt = fn($k) => !empty($b[$k]) ? valid_date($b[$k]) : null;
    $loc = (string)($b['location'] ?? 'Main Store'); if (!in_array($loc, f3_locations(), true)) fail('Unknown location.');
    $inv = txt($b['invoice_no'] ?? '', 60); $c = f3_bill_compute($b, (array)($b['lines'] ?? []), false);
    $h = ['supplier_id' => $sid, 'invoice_no' => $inv ?: null, 'invoice_key' => $inv !== '' ? f3_inv_key($inv) : null, 'invoice_no_missing' => $inv === '' ? 1 : 0, 'invoice_date' => $dt('invoice_date'), 'receipt_date' => $dt('receipt_date'), 'due_date' => $dt('due_date'),
          'terms' => txt($b['terms'] ?? '', 120) ?: null, 'location' => $loc, 'challan_no' => txt($b['challan_no'] ?? '', 60) ?: null, 'reference' => txt($b['reference'] ?? '', 120) ?: null, 'gstin' => strtoupper(txt($b['gstin'] ?? '', 20)) ?: null,
          'tax_invoice' => !empty($b['tax_invoice']) ? 1 : 0, 'notes' => txt($b['notes'] ?? '', 1000) ?: null, 'category' => txt($b['category'] ?? '', 80) ?: null, 'step' => max(1, min(7, (int)($b['step'] ?? 1)))]
        + array_intersect_key($c, array_flip(['subtotal', 'line_discount', 'line_tax', 'bill_discount', 'transport', 'other_tax', 'rounding', 'grand_total']));
    if ($h['due_date'] && $h['invoice_date'] && $h['due_date'] < $h['invoice_date']) fail('Due date cannot be before the invoice date.');
    if ($h['invoice_date'] && $h['invoice_date'] > f3_business_date()) fail('Invoice date cannot be in the future.');
    if ($old) { run('UPDATE f3_purchase_bills SET ' . implode(',', array_map(fn($k) => "$k=?", array_keys($h))) . ',version=version+1 WHERE id=?', ...[...array_values($h), $id]); }
    else { $h['bill_no'] = f3_seq('purchasebill', 'PB'); $h['created_by'] = $u['id']; run('INSERT INTO f3_purchase_bills(' . implode(',', array_keys($h)) . ') VALUES(' . implode(',', array_fill(0, count($h), '?')) . ')', ...array_values($h)); $id = (int)db()->lastInsertId(); f3_audit($u, 'purchase.draft', ['id' => $id]); }
    run('DELETE FROM f3_purchase_lines WHERE bill_id=?', $id); // draft lines only (never for posted bills)
    foreach ($c['lines'] as $l) {
        $ph = $l['photo_file_id']; if (!empty($l['photo']['data'])) { f3_need($u, 'purchase.upload'); $ph = f3_image_upload($l['photo'], $u); }
        run('INSERT INTO f3_purchase_lines(bill_id,sort,item_id,item_name,unit,factor,base_unit,qty,base_qty,rate,gross,disc_type,disc_value,discount,tax_rate_bp,tax,line_total,batch,expiry,mrp,notes,photo_file_id,cost_total) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            $id, $l['sort'], $l['item_id'], $l['item_name'], $l['unit'], f3_fmt_micro($l['factor_micro']), $l['base_unit'], f3_m3($l['qty_milli']), f3_m3($l['base_milli']), $l['rate'], $l['gross'], $l['disc_type'], $l['disc_value'], $l['discount'], $l['tax_rate_bp'], $l['tax'], $l['line_total'], $l['batch'], $l['expiry'], $l['mrp'], $l['notes'], $ph, $l['cost_total']);
    }
    foreach ((array)($b['files_add'] ?? []) as $f) { f3_need($u, 'purchase.upload'); $fid = f3_upload($f, $u); $k = in_array($f['kind'] ?? 'bill', ['bill', 'item', 'receipt'], true) ? ($f['kind'] ?? 'bill') : 'bill';
        run('INSERT IGNORE INTO f3_bill_files(bill_id,file_id,kind,sort,created_by) VALUES(?,?,?,?,?)', $id, $fid, $k, (int)row('SELECT COUNT(*) n FROM f3_bill_files WHERE bill_id=?', $id)['n'], $u['id']); }
    foreach ((array)($b['files_remove'] ?? []) as $fid) run('UPDATE f3_bill_files SET removed=1 WHERE bill_id=? AND file_id=?', $id, (int)$fid);
    foreach ((array)($b['files_order'] ?? []) as $i => $fid) run('UPDATE f3_bill_files SET sort=? WHERE bill_id=? AND file_id=?', $i, $id, (int)$fid);
    return ['ok' => true, 'id' => $id] + f3_bill_get($id, $u);
}
/** Add photos / PDFs to a posted bill (attachments only — amounts never change). */
function f3_bill_attach(array $b, array $u): array { f3_need($u, 'purchase.upload'); $bill = f3_bill((int)($b['id'] ?? 0)); $n = 0;
    foreach ((array)($b['files_add'] ?? []) as $f) { $fid = f3_upload($f, $u); $k = in_array($f['kind'] ?? 'bill', ['bill', 'item', 'receipt'], true) ? ($f['kind'] ?? 'bill') : 'bill'; run('INSERT IGNORE INTO f3_bill_files(bill_id,file_id,kind,sort,created_by) VALUES(?,?,?,99,?)', $bill['id'], $fid, $k, $u['id']); $n++; }
    if (!empty($b['files_remove'])) { if ($bill['status'] === 'posted' && !f3_can($u, 'purchase.edit')) fail('Only a manager can remove a photo from a posted bill.', 403); foreach ((array)$b['files_remove'] as $fid) run('UPDATE f3_bill_files SET removed=1 WHERE bill_id=? AND file_id=?', $bill['id'], (int)$fid); }
    f3_audit($u, 'purchase.attach', ['id' => (int)$bill['id'], 'added' => $n, 'removed' => count((array)($b['files_remove'] ?? []))]); return f3_bill_get((int)$bill['id'], $u); }
function f3_bill_discard(array $b, array $u): array { $bill = f3_bill((int)($b['id'] ?? 0), true); if ($bill['status'] !== 'draft') fail('Only a draft can be discarded.', 409);
    if ((int)$bill['created_by'] !== (int)$u['id']) f3_need($u, 'purchase.edit'); else f3_need($u, 'purchase.create');
    run("UPDATE f3_purchase_bills SET status='discarded' WHERE id=?", $bill['id']); f3_audit($u, 'purchase.discard', ['id' => (int)$bill['id']]); return ['ok' => true]; }

function f3_bill_get(int $id, array $u): array {
    f3_need($u, 'purchase.view', 'purchase.create'); $b = f3_bill($id);
    if ($b['status'] === 'draft' && (int)$b['created_by'] !== (int)$u['id'] && !f3_can($u, 'purchase.edit') && !f3_can($u, 'purchase.view')) fail('Not your draft.', 403);
    $s = $b['supplier_id'] ? row('SELECT id,name,code,phone,whatsapp,gstin,credit_days,default_terms,default_category FROM dd_bk_parties WHERE id=?', $b['supplier_id']) : null;
    $l = rows('SELECT l.*,i.unit item_unit,i.photo_file_id item_photo,i.is_stock FROM f3_purchase_lines l LEFT JOIN dd_bk_items i ON i.id=l.item_id WHERE l.bill_id=? ORDER BY l.sort,l.id', $id);
    foreach ($l as &$x) { foreach (['qty', 'base_qty', 'received_base', 'returned_base', 'factor'] as $k) $x[$k] = (float)$x[$k]; foreach (['rate', 'gross', 'discount', 'tax', 'line_total', 'cost_total', 'disc_value', 'tax_rate_bp', 'mrp', 'id', 'item_id', 'is_stock'] as $k) $x[$k] = $x[$k] === null ? null : (int)$x[$k];
        $x['tax_rate'] = $x['tax_rate_bp'] / 100; $x['pending_base'] = round(max(0, $x['base_qty'] - $x['received_base']), 3); $x['pending_qty'] = $x['factor'] > 0 ? round($x['pending_base'] / $x['factor'], 3) : 0; } unset($x);
    $files = rows('SELECT f.file_id,f.kind,f.sort,d.mime,d.original_name,d.size FROM f3_bill_files f JOIN dd_files d ON d.id=f.file_id WHERE f.bill_id=? AND f.removed=0 ORDER BY f.kind,f.sort,f.id', $id);
    $rc = rows("SELECT r.*,u.name by_name FROM f3_receipts r LEFT JOIN dd_users u ON u.id=r.created_by WHERE r.bill_id=? ORDER BY r.id", $id);
    foreach ($rc as &$r) $r['moves'] = rows("SELECT m.move_no,m.qty,m.entered_qty,m.entered_unit,i.name,i.unit,m.reversed_by FROM f3_stock_moves m JOIN dd_bk_items i ON i.id=m.item_id WHERE m.source_type='receipt' AND m.source_id=?", $r['id']); unset($r);
    $al = rows('SELECT a.amount,a.created_at,t.date,t.account,sp.pay_no,sp.reference,t.id txn_id FROM f3_supplier_allocations a JOIN dd_bk_txns t ON t.id=a.payment_txn_id AND t.voided=0 LEFT JOIN f3_supplier_payments sp ON sp.txn_id=t.id WHERE a.bill_id=? AND a.voided=0 ORDER BY t.date,a.id', $id);
    $rt = rows('SELECT r.*,t.voided FROM f3_purchase_returns r LEFT JOIN dd_bk_txns t ON t.id=r.bk_txn_id WHERE r.bill_id=? ORDER BY r.id', $id);
    $aud = rows("SELECT a.at created_at,a.action,u.name by_name FROM dd_audit a LEFT JOIN dd_users u ON u.id=a.user_id WHERE a.action LIKE 'f360.purchase%' AND JSON_EXTRACT(a.detail,'$.id')=? ORDER BY a.id", $id);
    $m = f3_bill_money($b, f3_bill_settled([$id])[$id]);
    foreach (['subtotal', 'line_discount', 'line_tax', 'bill_discount', 'transport', 'other_tax', 'rounding', 'grand_total', 'version', 'id', 'supplier_id', 'bk_txn_id', 'invoice_no_missing', 'tax_invoice', 'step'] as $k) $b[$k] = $b[$k] === null ? null : (int)$b[$k];
    $dup = $b['status'] === 'draft' ? f3_bill_duplicates($b) : [];
    return ['bill' => $b + $m, 'supplier' => $s, 'lines' => $l, 'files' => $files, 'receipts' => $rc, 'allocations' => $al, 'returns' => $rt, 'audit' => $aud, 'duplicates' => $dup,
            'can' => ['edit' => $b['status'] === 'draft', 'post' => f3_can($u, 'purchase.post'), 'approve' => f3_can($u, 'purchase.approve'), 'receive' => f3_can($u, 'stock.receive'), 'pay' => f3_can($u, 'supplier.pay'), 'reverse' => f3_can($u, 'purchase.reverse'), 'upload' => f3_can($u, 'purchase.upload'), 'return' => f3_can($u, 'purchase.reverse') || f3_can($u, 'supplier.adjust')],
            'approval_above' => f3_set_int('f3_purchase_approval_above')];
}
function f3_bill_duplicates(array $b): array {
    if (!$b['supplier_id'] || !$b['invoice_key']) return [];
    $fy = f3_fy($b['invoice_date'] ?: f3_business_date()); [$y1, $y2] = explode('-', $fy);
    $o = rows("SELECT id,bill_no,invoice_no,invoice_date,grand_total,status FROM f3_purchase_bills WHERE supplier_id=? AND invoice_key=? AND id<>? AND status IN ('posted','review') AND COALESCE(invoice_date,post_date) BETWEEN ? AND ?", $b['supplier_id'], $b['invoice_key'], $b['id'], $y1 . '-04-01', $y2 . '-03-31');
    foreach (rows("SELECT id,bill_no,date,total FROM dd_bk_txns WHERE party_id=? AND type='purchase' AND voided=0 AND source<>'f360' AND bill_no IS NOT NULL AND date BETWEEN ? AND ?", $b['supplier_id'], $y1 . '-04-01', $y2 . '-03-31') as $t)
        if (f3_inv_key((string)$t['bill_no']) === $b['invoice_key']) $o[] = ['id' => null, 'bill_no' => 'Books #' . $t['id'], 'invoice_no' => $t['bill_no'], 'invoice_date' => $t['date'], 'grand_total' => (int)$t['total'], 'status' => 'books'];
    return $o;
}
function f3_bill_list(array $q, array $u): array {
    f3_need($u, 'purchase.view', 'purchase.create'); $w = []; $a = [];
    $st = $q['status'] ?? 'all';
    if ($st === 'drafts') { $w[] = "b.status='draft'"; if (!f3_can($u, 'purchase.edit')) { $w[] = 'b.created_by=?'; $a[] = $u['id']; } }
    elseif ($st === 'review') $w[] = "b.status='review'"; elseif ($st === 'pending_receipt') $w[] = "b.status='posted' AND b.receipt_status<>'received'"; elseif ($st === 'reversed') $w[] = "b.status='reversed'";
    else $w[] = "b.status IN ('posted','review')";
    if (!f3_can($u, 'purchase.view')) { $w[] = 'b.created_by=?'; $a[] = $u['id']; }
    if (!empty($q['supplier_id'])) { $w[] = 'b.supplier_id=?'; $a[] = (int)$q['supplier_id']; }
    if (!empty($q['from'])) { $w[] = 'COALESCE(b.post_date,b.invoice_date,DATE(b.created_at))>=?'; $a[] = valid_date($q['from']); } if (!empty($q['to'])) { $w[] = 'COALESCE(b.post_date,b.invoice_date,DATE(b.created_at))<=?'; $a[] = valid_date($q['to']); }
    if (!empty($q['q'])) { $s = txt($q['q'], 60); $w[] = '(b.bill_no LIKE ? OR b.invoice_no LIKE ? OR p.name LIKE ? OR EXISTS(SELECT 1 FROM f3_purchase_lines l WHERE l.bill_id=b.id AND l.item_name LIKE ?))'; array_push($a, "%$s%", "%$s%", "%$s%", "%$s%"); }
    $lim = min(500, max(10, (int)($q['limit'] ?? 100))); $off = max(0, (int)($q['page'] ?? 0)) * $lim;
    $r = rows('SELECT b.*,p.name supplier,(SELECT COUNT(*) FROM f3_purchase_lines l WHERE l.bill_id=b.id) n_lines,(SELECT COUNT(*) FROM f3_bill_files f WHERE f.bill_id=b.id AND f.removed=0) n_files,(SELECT f.file_id FROM f3_bill_files f JOIN dd_files d ON d.id=f.file_id WHERE f.bill_id=b.id AND f.removed=0 AND d.mime LIKE \'image/%\' ORDER BY f.sort LIMIT 1) thumb FROM f3_purchase_bills b LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id WHERE ' . implode(' AND ', $w) . " ORDER BY COALESCE(b.post_date,DATE(b.updated_at)) DESC,b.id DESC LIMIT $lim OFFSET $off", ...$a);
    $s = f3_bill_settled(array_map(fn($x) => (int)$x['id'], $r)); $f = $q['pay'] ?? '';
    $o = []; foreach ($r as $b) { $m = f3_bill_money($b, $s[(int)$b['id']]); if ($f && $m['payment_status'] !== $f && !($f === 'overdue' && $m['overdue_days'] > 0)) continue;
        $o[] = ['id' => (int)$b['id'], 'bill_no' => $b['bill_no'], 'supplier' => $b['supplier'], 'supplier_id' => $b['supplier_id'] ? (int)$b['supplier_id'] : null, 'invoice_no' => $b['invoice_no'], 'invoice_date' => $b['invoice_date'], 'post_date' => $b['post_date'], 'due_date' => $b['due_date'], 'status' => $b['status'], 'receipt_status' => $b['receipt_status'],
                'grand_total' => (int)$b['grand_total'], 'n_lines' => (int)$b['n_lines'], 'n_files' => (int)$b['n_files'], 'thumb' => $b['thumb'] ? (int)$b['thumb'] : null, 'step' => (int)$b['step'], 'updated_at' => $b['updated_at']] + $m; }
    $sum = row("SELECT COUNT(*) n,COALESCE(SUM(grand_total),0) t FROM f3_purchase_bills WHERE status='posted' AND post_date>=?", substr(f3_business_date(), 0, 8) . '01');
    $cnt = row("SELECT SUM(status='draft' AND created_by=?) drafts,SUM(status='review') review,SUM(status='posted' AND receipt_status<>'received') pending FROM f3_purchase_bills", $u['id']);
    return ['bills' => $o, 'month' => ['count' => (int)$sum['n'], 'total' => (int)$sum['t']], 'counts' => array_map('intval', $cnt), 'page' => (int)($q['page'] ?? 0), 'limit' => $lim];
}
/** Submit a draft: validates everything, checks duplicates, then posts (or sends for approval above the limit). */
function f3_bill_submit(array $b, array $u): array {
    f3_need($u, 'purchase.create');
    return idem('f3-bill-submit', $b, $u, function () use ($b, $u) {
        $bill = f3_bill((int)($b['id'] ?? 0), true); if ($bill['status'] !== 'draft') fail('This bill was already submitted (' . $bill['status'] . ').', 409);
        if (isset($b['version']) && (int)$b['version'] !== (int)$bill['version']) fail('This draft was changed on another device. Reload it.', 409);
        if (!$bill['supplier_id']) fail('Choose the supplier.'); $sup = f3_supplier((int)$bill['supplier_id']); if (!(int)$sup['active']) fail('This supplier is inactive.');
        if (!$bill['invoice_date']) fail('Enter the invoice / bill date.');
        $lines = rows('SELECT * FROM f3_purchase_lines WHERE bill_id=? ORDER BY sort,id', $bill['id']); if (!$lines) fail('Add at least one item.');
        $h = $bill; foreach (['bill_discount', 'transport', 'other_tax'] as $k) $h[$k] = rupees((int)$bill[$k]); $h['rounding'] = ((int)$bill['rounding'] < 0 ? '-' : '') . rupees(abs((int)$bill['rounding']));
        $c = f3_bill_compute($h, array_map(fn($l) => ['item_id' => $l['item_id'], 'unit' => $l['unit'], 'qty' => $l['qty'], 'rate' => rupees((int)$l['rate']), 'disc_type' => $l['disc_type'], 'disc_value' => $l['disc_type'] === 'pct' ? $l['disc_value'] / 100 : rupees((int)$l['disc_value']), 'tax_rate' => $l['tax_rate_bp'] / 100], $lines), true);
        if ($c['grand_total'] !== (int)$bill['grand_total']) fail('Totals changed (item or setting updated). Open the draft and save again.', 409);
        $dups = f3_bill_duplicates($bill); $why = txt($b['dup_reason'] ?? '', 250);
        if ($dups && $why === '') throw new F3Conflict('Invoice ' . $bill['invoice_no'] . ' from ' . $sup['name'] . ' is already entered (' . implode(', ', array_map(fn($d) => $d['bill_no'], $dups)) . '). If this really is a different bill, write the reason.', ['duplicate' => $dups, 'needs' => 'dup_reason']);
        if (!empty($sup['credit_limit']) && empty($b['over_limit_ok'])) { $bal = f3_sup_balances()[(int)$sup['id']]['balance'] ?? 0; if ($bal + (int)$bill['grand_total'] > (int)$sup['credit_limit'] && !f3_can($u, 'supplier.adjust')) throw new F3Conflict('This bill takes ' . $sup['name'] . ' above the credit limit of ' . f3_rs((int)$sup['credit_limit']) . '. Continue?', ['needs' => 'over_limit_ok']); }
        if (function_exists('f29_rate_changes') && empty($b['rates_ok']) && ($rc = f29_rate_changes($bill))) throw new F3Conflict('Rates changed since the last bill from ' . $sup['name'] . ': ' . implode('; ', array_map(fn($x) => $x['item'] . ' ' . f3_rs2($x['previous_per_stock_unit']) . ' → ' . f3_rs2($x['new_per_stock_unit']) . ' per ' . ($x['stock_unit'] ?: 'unit') . ($x['change_pct'] !== null ? ' (' . ($x['change_pct'] > 0 ? '+' : '') . $x['change_pct'] . '%)' : ''), $rc)) . '. Confirm the new rates to save them as the latest supplier price.', ['needs' => 'rates_ok', 'rate_changes' => $rc]);
        if (!empty($b['rates_ok']) && isset($rc)) f3_audit($u, 'purchase.rates_confirmed', ['bill' => (int)$bill['id']]);
        $post = valid_date($b['post_date'] ?? ($bill['receipt_date'] ?: $bill['invoice_date'])); if ($post > f3_business_date()) fail('Posting date cannot be in the future.');
        if ($bill['due_date'] === null) { $cd = (int)$sup['credit_days']; run('UPDATE f3_purchase_bills SET due_date=? WHERE id=?', next_date($bill['invoice_date'], $cd), $bill['id']); }
        run('UPDATE f3_purchase_bills SET dup_reason=?,post_date=?,submitted_by=? WHERE id=?', $why ?: null, $post, $u['id'], $bill['id']);
        $limit = f3_set_int('f3_purchase_approval_above');
        if (!f3_can($u, 'purchase.post') || ($limit > 0 && (int)$bill['grand_total'] > $limit && !f3_can($u, 'purchase.approve'))) {
            f3_day_guard($post, $u, 'purchase');
            run("UPDATE f3_purchase_bills SET status='review',step=6 WHERE id=?", $bill['id']); f3_audit($u, 'purchase.submit', ['id' => (int)$bill['id'], 'for' => 'approval']);
            f3_notify('purchase', 'pb-review-' . $bill['id'], 'Purchase bill ' . $bill['bill_no'] . ' waits for approval', $sup['name'] . ' · ' . f3_rs((int)$bill['grand_total']), 'purchase.approve', 'purchase:' . $bill['id']);
            return ['ok' => true, 'status' => 'review', 'id' => (int)$bill['id'], 'message' => 'Sent for approval. Nothing is posted until it is approved.'];
        }
        return f3_bill_post_now((int)$bill['id'], $b, $u);
    });
}
function f3_bill_approve(array $b, array $u): array {
    f3_need($u, 'purchase.approve');
    return idem('f3-bill-approve', $b, $u, function () use ($b, $u) {
        $bill = f3_bill((int)($b['id'] ?? 0), true); if ($bill['status'] !== 'review') fail('Only a bill waiting for approval can be approved.', 409);
        if (!empty($b['reject'])) { run("UPDATE f3_purchase_bills SET status='draft',version=version+1 WHERE id=?", $bill['id']); f3_notify_done('pb-review-' . $bill['id']); f3_audit($u, 'purchase.return_to_draft', ['id' => (int)$bill['id'], 'note' => txt($b['note'] ?? '', 250)]); return ['ok' => true, 'status' => 'draft']; }
        f3_notify_done('pb-review-' . $bill['id']); run('UPDATE f3_purchase_bills SET approved_by=?,approved_at=UTC_TIMESTAMP() WHERE id=?', $u['id'], $bill['id']);
        return f3_bill_post_now((int)$bill['id'], $b, $u);
    });
}
/** The ONE place a purchase bill reaches Books: purchase txn (paid 0) keyed f360:pb:<id>. Optional same-time receipt of all goods and payment. */
function f3_bill_post_now(int $id, array $b, array $u): array {
    $bill = f3_bill($id, true); $sup = f3_supplier((int)$bill['supplier_id']); $post = $bill['post_date'];
    f3_day_guard($post, $u, 'purchase');
    $cat = $bill['category'] ?: ($sup['default_category'] ?: 'Grocery');
    $txn = f3_bk_post('f360:pb:' . $id, ['date' => $post, 'type' => 'purchase', 'party_id' => (int)$sup['id'], 'category' => $cat, 'total' => (int)$bill['grand_total'], 'paid' => 0, 'account' => f3_acct('cash'),
        'bill_no' => $bill['invoice_no'] ?: $bill['bill_no'], 'description' => 'Purchase bill ' . $bill['bill_no'] . ($bill['invoice_no'] ? ' · Inv ' . $bill['invoice_no'] : ' · no invoice no.')], $u);
    run("UPDATE f3_purchase_bills SET status='posted',bk_txn_id=?,posted_by=?,posted_at=UTC_TIMESTAMP(),step=7,approved_by=COALESCE(approved_by,?),approved_at=COALESCE(approved_at,UTC_TIMESTAMP()) WHERE id=?", $txn, $u['id'], $u['id'], $id);
    // Non-stock lines need no receiving.
    run("UPDATE f3_purchase_lines l JOIN dd_bk_items i ON i.id=l.item_id SET l.received_base=l.base_qty WHERE l.bill_id=? AND i.is_stock=0", $id);
    f3_audit($u, 'purchase.post', ['id' => $id, 'bill_no' => $bill['bill_no'], 'total' => (int)$bill['grand_total'], 'books_txn' => $txn, 'dup_reason' => $bill['dup_reason']]);
    $res = ['ok' => true, 'status' => 'posted', 'id' => $id, 'books_txn' => $txn];
    if (!empty($b['receive_all']) && f3_can($u, 'stock.receive')) $res['receipt'] = f3_bill_receive_do($id, ['date' => $bill['receipt_date'] ?: $post, 'all' => true, 'note' => 'Received with bill'], $u);
    else f3_bill_receipt_status($id);
    if (!empty($b['pay']['amount'])) { $p = $b['pay']; $amt = f3_paise($p['amount'], 'Paid now'); if ($amt > (int)$bill['grand_total']) fail('Paid now cannot be more than the bill total.');
        $res['payment'] = f3_sup_pay_do(['supplier_id' => (int)$sup['id'], 'date' => $p['date'] ?? $post, 'amount' => $p['amount'], 'mode' => $p['mode'] ?? 'Cash', 'reference' => $p['reference'] ?? '', 'file' => $p['file'] ?? null, 'allocations' => [['bill_id' => $id, 'amount' => $p['amount']]], 'key' => 'pb' . $id], $u); }
    return $res + ['bill' => f3_bill_get($id, $u)];
}
function f3_bill_receipt_status(int $id): string {
    $r = row('SELECT SUM(base_qty) q,SUM(LEAST(received_base,base_qty)) rcv FROM f3_purchase_lines WHERE bill_id=?', $id);
    $s = (float)$r['rcv'] <= 0 ? 'pending' : ((float)$r['rcv'] + 0.0005 >= (float)$r['q'] ? 'received' : 'partial'); run('UPDATE f3_purchase_bills SET receipt_status=? WHERE id=?', $s, $id); return $s;
}
/** Receive goods (all, or per line in the line's own unit). Each received line → exactly ONE 'receipt' stock move at its share of the line cost. */
function f3_bill_receive(array $b, array $u): array { f3_need($u, 'stock.receive'); return idem('f3-receive', $b, $u, fn() => f3_bill_receive_do((int)($b['id'] ?? 0), $b, $u)); }
function f3_bill_receive_do(int $id, array $b, array $u): array {
    $bill = f3_bill($id, true); if ($bill['status'] !== 'posted') fail('Goods can be received only on a posted bill.', 409);
    $d = valid_date($b['date'] ?? f3_business_date()); if ($d > f3_business_date()) fail('Receipt date cannot be in the future.');
    $want = []; foreach ((array)($b['lines'] ?? []) as $x) if (($x['qty'] ?? '') !== '' && (float)$x['qty'] != 0) $want[(int)$x['line_id']] = $x['qty'];
    $lines = rows('SELECT l.*,i.is_stock FROM f3_purchase_lines l JOIN dd_bk_items i ON i.id=l.item_id WHERE l.bill_id=? ORDER BY l.sort,l.id FOR UPDATE', $id);
    $plan = [];
    foreach ($lines as $l) { if (!(int)$l['is_stock']) continue; $pend = f3_qm($l['base_qty']) - f3_qm($l['received_base']); if ($pend <= 0) { if (isset($want[(int)$l['id']])) fail('“' . $l['item_name'] . '” is already fully received.', 409); continue; }
        if (!empty($b['all'])) $base = $pend; elseif (isset($want[(int)$l['id']])) { $qm = f3_milli($want[(int)$l['id']], '“' . $l['item_name'] . '” received quantity'); $base = f3_to_base($qm, f3_micro($l['factor']));
            if ($base > $pend) fail('“' . $l['item_name'] . '”: receiving ' . f3_m3($base) . ' ' . $l['base_unit'] . ' but only ' . f3_m3($pend) . ' is pending on this bill.', 409); } else continue;
        $plan[] = [$l, $base, $pend]; }
    if (!$plan) fail('Nothing to receive.', 409);
    $no = f3_seq('receipt', 'GR'); run('INSERT INTO f3_receipts(receipt_no,bill_id,date,note,created_by) VALUES(?,?,?,?,?)', $no, $id, $d, txt($b['note'] ?? '', 250) ?: null, $u['id']); $rid = (int)db()->lastInsertId();
    foreach ($plan as [$l, $base, $pend]) {
        $lineBase = f3_qm($l['base_qty']); $cost = (int)$l['cost_total']; $doneVal = (int)row("SELECT COALESCE(SUM(value),0) v FROM f3_stock_moves WHERE source_type='receipt' AND source_line_id=? AND reversed_by IS NULL", $l['id'])['v'];
        $val = $base === $pend ? $cost - $doneVal : intdiv($cost * $base * 2 + $lineBase, 2 * $lineBase); // last part takes the remainder
        f3_stock_post(['item_id' => (int)$l['item_id'], 'location' => $bill['location'], 'date' => $d, 'kind' => 'receipt', 'qty_milli' => $base, 'value' => max(0, $val), 'entered_milli' => intdiv($base * 1000000, max(1, f3_micro($l['factor']))), 'entered_unit' => $l['unit'], 'factor_micro' => f3_micro($l['factor']),
            'source_type' => 'receipt', 'source_id' => $rid, 'source_line_id' => (int)$l['id'], 'reason' => 'Bill ' . $bill['bill_no'] . ($bill['invoice_no'] ? ' / ' . $bill['invoice_no'] : ''), 'idem' => 'rcpt:' . $rid . ':' . $l['id']], $u);
        run('UPDATE f3_purchase_lines SET received_base=received_base+? WHERE id=?', f3_m3($base), $l['id']);
    }
    $st = f3_bill_receipt_status($id); f3_audit($u, 'purchase.receive', ['id' => $id, 'receipt' => $no, 'lines' => count($plan), 'status' => $st]);
    return ['ok' => true, 'receipt_no' => $no, 'receipt_status' => $st];
}
/** Reverse a posted bill (wrong supplier / entered twice). Never edits history: Books txn is voided (or, if its day/month is closed, offset by a dated credit entry today),
 *  stock receipts are reversed by opposite moves (refused if the goods were already used — then use a return), payments stay and become advance on account. */
function f3_bill_reverse(array $b, array $u): array {
    f3_need($u, 'purchase.reverse'); $why = f3_text($b['reason'] ?? '', 500, 'Reason');
    return idem('f3-bill-reverse', $b, $u, function () use ($b, $u, $why) {
        $bill = f3_bill((int)($b['id'] ?? 0), true); if ($bill['status'] === 'review') { run("UPDATE f3_purchase_bills SET status='draft' WHERE id=?", $bill['id']); f3_notify_done('pb-review-' . $bill['id']); return ['ok' => true, 'status' => 'draft']; }
        if ($bill['status'] !== 'posted') fail('Only a posted bill can be reversed.', 409);
        if (row("SELECT 1 FROM f3_purchase_returns r JOIN dd_bk_txns t ON t.id=r.bk_txn_id AND t.voided=0 WHERE r.bill_id=? LIMIT 1", $bill['id'])) fail('This bill has returns / credit notes. Reverse those first or record another return.', 409);
        foreach (rows("SELECT m.id FROM f3_stock_moves m JOIN f3_receipts r ON r.id=m.source_id WHERE m.source_type='receipt' AND r.bill_id=? AND m.reversed_by IS NULL", $bill['id']) as $m) {
            $mv = row('SELECT * FROM f3_stock_moves WHERE id=?', $m['id']);
            $rid = f3_stock_post(['item_id' => (int)$mv['item_id'], 'location' => $mv['location'], 'date' => max($mv['date'], f3_business_date()), 'kind' => 'reversal', 'qty_milli' => -f3_qm($mv['qty']), 'value' => (int)$mv['value'], 'source_type' => 'reversal', 'source_id' => (int)$mv['id'], 'reverses_id' => (int)$mv['id'], 'reason' => 'Bill ' . $bill['bill_no'] . ' reversed: ' . $why, 'idem' => 'rev:' . $mv['id']], $u);
            run('UPDATE f3_stock_moves SET reversed_by=? WHERE id=?', $rid, $mv['id']);
        }
        run('UPDATE f3_purchase_lines SET received_base=0 WHERE bill_id=?', $bill['id']);
        $freed = (int)row('SELECT COALESCE(SUM(amount),0) s FROM f3_supplier_allocations WHERE bill_id=? AND voided=0', $bill['id'])['s']; run('UPDATE f3_supplier_allocations SET voided=1 WHERE bill_id=?', $bill['id']);
        $t = row('SELECT * FROM dd_bk_txns WHERE id=?', $bill['bk_txn_id']); $how = 'voided';
        if (f3_day_block_reason($t['date'], $u, 'purchase') !== null) { // closed period stays untouched: offsetting credit entry dated today
            $how = 'offset'; $d = f3_business_date(); f3_day_guard($d, $u, 'purchase');
            f3_bk_post('f360:pbrev:' . $bill['id'], ['date' => $d, 'type' => 'purchase_return', 'party_id' => (int)$bill['supplier_id'], 'category' => $t['category'], 'total' => (int)$bill['grand_total'], 'paid' => 0, 'account' => f3_acct('cash'), 'bill_no' => $t['bill_no'], 'description' => 'Reversal of purchase bill ' . $bill['bill_no'] . ': ' . $why], $u);
        } else f3_bk_void((int)$bill['bk_txn_id'], 'Purchase bill ' . $bill['bill_no'] . ' reversed: ' . $why, $u);
        run("UPDATE f3_purchase_bills SET status='reversed',receipt_status='pending',reversed_by=?,reversed_at=UTC_TIMESTAMP(),reverse_reason=? WHERE id=?", $u['id'], $why, $bill['id']);
        f3_audit($u, 'purchase.reverse', ['id' => (int)$bill['id'], 'reason' => $why, 'books' => $how, 'payments_now_advance' => $freed]);
        return ['ok' => true, 'status' => 'reversed', 'books' => $how, 'advance_freed' => $freed];
    });
}
/** Copy a posted / reversed bill into a new draft (for corrections: reverse + copy + fix). */
function f3_bill_copy(array $b, array $u): array { f3_need($u, 'purchase.create'); $s = f3_bill((int)($b['id'] ?? 0)); $no = f3_seq('purchasebill', 'PB');
    run("INSERT INTO f3_purchase_bills(bill_no,supplier_id,invoice_no,invoice_key,invoice_no_missing,invoice_date,receipt_date,due_date,terms,location,challan_no,reference,gstin,tax_invoice,notes,category,subtotal,line_discount,line_tax,bill_discount,transport,other_tax,rounding,grand_total,created_by)
         SELECT ?,supplier_id,invoice_no,invoice_key,invoice_no_missing,invoice_date,receipt_date,due_date,terms,location,challan_no,reference,gstin,tax_invoice,notes,category,subtotal,line_discount,line_tax,bill_discount,transport,other_tax,rounding,grand_total,? FROM f3_purchase_bills WHERE id=?", $no, $u['id'], $s['id']);
    $id = (int)db()->lastInsertId();
    run('INSERT INTO f3_purchase_lines(bill_id,sort,item_id,item_name,unit,factor,base_unit,qty,base_qty,rate,gross,disc_type,disc_value,discount,tax_rate_bp,tax,line_total,batch,expiry,mrp,notes,photo_file_id,cost_total) SELECT ?,sort,item_id,item_name,unit,factor,base_unit,qty,base_qty,rate,gross,disc_type,disc_value,discount,tax_rate_bp,tax,line_total,batch,expiry,mrp,notes,photo_file_id,cost_total FROM f3_purchase_lines WHERE bill_id=?', $id, $s['id']);
    run('INSERT INTO f3_bill_files(bill_id,file_id,kind,sort,created_by) SELECT ?,file_id,kind,sort,? FROM f3_bill_files WHERE bill_id=? AND removed=0', $id, $u['id'], $s['id']);
    f3_audit($u, 'purchase.copy', ['id' => $id, 'from' => (int)$s['id']]); return ['ok' => true, 'id' => $id]; }

/* ------------------------------ supplier payments ------------------------------ */
function f3_sup_pay(array $b, array $u): array { f3_need($u, 'supplier.pay'); if (txt($b['client_key'] ?? '', 64) === '') fail('Missing submission key. Refresh the page.'); return idem('f3-sup-pay', $b, $u, fn() => f3_sup_pay_do($b + ['key' => $b['client_key']], $u)); }
function f3_sup_pay_do(array $b, array $u): array {
    f3_need($u, 'supplier.pay'); $sup = f3_supplier((int)($b['supplier_id'] ?? 0), true); $d = valid_date($b['date'] ?? f3_business_date()); f3_day_guard($d, $u, 'supplier');
    $amt = f3_paise($b['amount'] ?? null, 'Payment amount'); if ($amt <= 0) fail('Payment amount must be more than zero.');
    $mode = (string)($b['mode'] ?? 'Cash'); f3_pay_guard($u, $mode, $amt); $acct = f3_mode_account($mode);
    $ref = txt($b['reference'] ?? '', 120); if (in_array($mode, ['UPI', 'Bank'], true) && $ref === '' && setting('f3_require_utr', '0') === '1') fail('Enter the UTR / reference number.');
    if ($ref !== '' && $mode !== 'Cash' && ($dup = row('SELECT pay_no FROM f3_supplier_payments sp JOIN dd_bk_txns t ON t.id=sp.txn_id AND t.voided=0 WHERE sp.reference=? AND sp.supplier_id=?', $ref, $sup['id'])) && empty($b['ref_ok'])) throw new F3Conflict('Reference ' . $ref . ' is already used on payment ' . $dup['pay_no'] . '. Save anyway?', ['needs' => 'ref_ok']);
    $open = []; foreach (f3_open_bills((int)$sup['id'], true) as $o) $open[$o['id']] = $o;
    $alloc = []; $mode_a = $b['allocate'] ?? (isset($b['allocations']) ? 'manual' : 'fifo');
    if ($mode_a === 'manual') { foreach ((array)($b['allocations'] ?? []) as $a) { if (($a['amount'] ?? '') === '' || (float)$a['amount'] == 0) continue; $bid = (int)$a['bill_id']; $x = f3_paise($a['amount'], 'Allocation');
            if (!isset($open[$bid])) fail('Bill is not open for this supplier (already paid or reversed).', 409); if ($x > $open[$bid]['outstanding']) fail('Allocation to ' . $open[$bid]['bill_no'] . ' (' . f3_rs2($x) . ') is more than its outstanding ' . f3_rs2($open[$bid]['outstanding']) . '.', 409);
            $alloc[$bid] = ($alloc[$bid] ?? 0) + $x; } }
    elseif ($mode_a === 'fifo') { $left = $amt; foreach ($open as $o) { if ($left <= 0) break; $x = min($left, $o['outstanding']); $alloc[$o['id']] = $x; $left -= $x; } }
    if (array_sum($alloc) > $amt) fail('Allocations (' . f3_rs2(array_sum($alloc)) . ') are more than the payment (' . f3_rs2($amt) . ').');
    $no = f3_seq('supplierpay', 'SP'); $fid = !empty($b['file']['data']) ? f3_upload($b['file'], $u) : null;
    $txn = f3_bk_post('f360:sp:' . txt((string)($b['key'] ?? $no), 64), ['date' => $d, 'type' => 'payment_out', 'party_id' => (int)$sup['id'], 'total' => $amt, 'paid' => $amt, 'account' => $acct, 'bill_no' => $ref ?: null,
        'description' => 'Supplier payment ' . $no . ($alloc ? ' · bills ' . implode(', ', array_map(fn($k) => $open[$k]['bill_no'], array_keys($alloc))) : ' · advance / on account') . (txt($b['note'] ?? '', 200) ? ' · ' . txt($b['note'], 200) : '')], $u);
    if ($ex = row('SELECT pay_no FROM f3_supplier_payments WHERE txn_id=?', $txn)) return ['ok' => true, 'duplicate' => true, 'pay_no' => $ex['pay_no'], 'txn_id' => $txn];
    run('INSERT INTO f3_supplier_payments(txn_id,supplier_id,pay_no,kind,reference,file_id,client_key,created_by) VALUES(?,?,?,?,?,?,?,?)', $txn, $sup['id'], $no, $alloc ? 'payment' : 'advance', $ref ?: null, $fid, txt((string)($b['key'] ?? ''), 64) ?: null, $u['id']);
    foreach ($alloc as $bid => $x) if ($x > 0) run('INSERT INTO f3_supplier_allocations(payment_txn_id,bill_id,amount,created_by) VALUES(?,?,?,?)', $txn, $bid, $x, $u['id']);
    f3_audit($u, 'supplier.pay', ['id' => (int)$sup['id'], 'pay_no' => $no, 'amount' => $amt, 'mode' => $mode, 'allocated' => array_sum($alloc), 'txn' => $txn]);
    return ['ok' => true, 'pay_no' => $no, 'txn_id' => $txn, 'allocated' => array_sum($alloc), 'advance' => $amt - array_sum($alloc)];
}
/** Use an unallocated payment (advance) against bills. */
function f3_sup_allocate(array $b, array $u): array {
    f3_need($u, 'supplier.pay'); $txn = (int)($b['txn_id'] ?? 0); $p = row('SELECT * FROM f3_supplier_payments WHERE txn_id=?', $txn); if (!$p) fail('Payment not found.', 404);
    row('SELECT id FROM dd_bk_txns WHERE id=? FOR UPDATE', $txn); $free = f3_payment_unallocated($txn); $open = []; foreach (f3_open_bills((int)$p['supplier_id'], true) as $o) $open[$o['id']] = $o; $n = 0;
    foreach ((array)($b['allocations'] ?? []) as $a) { $bid = (int)$a['bill_id']; $x = f3_paise($a['amount'] ?? '0', 'Allocation'); if ($x <= 0) continue;
        if (!isset($open[$bid])) fail('Bill is not open.', 409); if ($x > $open[$bid]['outstanding']) fail('Allocation is more than the bill outstanding.', 409); if ($x > $free) fail('Only ' . f3_rs2($free) . ' of this payment is unallocated.', 409);
        $ex = row('SELECT id,voided FROM f3_supplier_allocations WHERE payment_txn_id=? AND bill_id=?', $txn, $bid);
        if ($ex && !(int)$ex['voided']) run('UPDATE f3_supplier_allocations SET amount=amount+? WHERE id=?', $x, $ex['id']); elseif ($ex) run('UPDATE f3_supplier_allocations SET amount=?,voided=0 WHERE id=?', $x, $ex['id']); else run('INSERT INTO f3_supplier_allocations(payment_txn_id,bill_id,amount,created_by) VALUES(?,?,?,?)', $txn, $bid, $x, $u['id']);
        $free -= $x; $open[$bid]['outstanding'] -= $x; $n++; }
    if (!$n) fail('Enter an amount for at least one bill.');
    run("UPDATE f3_supplier_payments SET kind='payment' WHERE txn_id=?", $txn); f3_audit($u, 'supplier.allocate', ['id' => (int)$p['supplier_id'], 'txn' => $txn, 'bills' => $n]); return ['ok' => true, 'unallocated' => $free];
}
function f3_sup_pay_void(array $b, array $u): array {
    f3_need($u, 'supplier.adjust'); $why = f3_text($b['reason'] ?? '', 250, 'Reason'); $txn = (int)($b['txn_id'] ?? 0); $p = row('SELECT * FROM f3_supplier_payments WHERE txn_id=?', $txn); if (!$p) fail('Payment not found.', 404);
    $t = row('SELECT * FROM dd_bk_txns WHERE id=? FOR UPDATE', $txn); if ((int)$t['voided']) fail('Already cancelled.', 409);
    $r = f3_day_block_reason($t['date'], $u, 'supplier'); if ($r) fail('Cannot cancel: ' . $r . ' Record a refund / correcting entry today instead.', 409);
    f3_bk_void($txn, 'Supplier payment ' . $p['pay_no'] . ' cancelled: ' . $why, $u); run('UPDATE f3_supplier_allocations SET voided=1 WHERE payment_txn_id=?', $txn);
    f3_audit($u, 'supplier.pay_void', ['id' => (int)$p['supplier_id'], 'pay_no' => $p['pay_no'], 'reason' => $why]); return ['ok' => true];
}
/** Supplier refunds an advance / excess payment to us. */
function f3_sup_refund(array $b, array $u): array {
    f3_need($u, 'supplier.adjust');
    return idem('f3-sup-refund', $b, $u, function () use ($b, $u) {
        $sup = f3_supplier((int)($b['supplier_id'] ?? 0), true); $d = valid_date($b['date'] ?? f3_business_date()); f3_day_guard($d, $u, 'supplier');
        $amt = f3_paise($b['amount'] ?? null, 'Refund amount'); if ($amt <= 0) fail('Refund must be more than zero.');
        $bal = f3_sup_balances()[(int)$sup['id']]['balance'] ?? 0; if (-$bal < $amt && empty($b['force'])) throw new F3Conflict('The supplier owes you only ' . f3_rs(max(0, -$bal)) . ' (advance). Record this refund anyway?', ['needs' => 'force']);
        $mode = (string)($b['mode'] ?? 'Cash'); $no = f3_seq('supplierpay', 'SP');
        $txn = f3_bk_post('f360:sr:' . $no, ['date' => $d, 'type' => 'payment_in', 'party_id' => (int)$sup['id'], 'total' => $amt, 'paid' => $amt, 'account' => f3_mode_account($mode), 'description' => 'Refund from supplier ' . $no . ' · ' . txt($b['note'] ?? '', 200)], $u);
        run("INSERT INTO f3_supplier_payments(txn_id,supplier_id,pay_no,kind,reference,created_by) VALUES(?,?,?,'refund',?,?)", $txn, $sup['id'], $no, txt($b['reference'] ?? '', 120) ?: null, $u['id']);
        // consume unallocated advances (oldest first) so they are not offered again
        $left = $amt; foreach (rows("SELECT sp.txn_id FROM f3_supplier_payments sp JOIN dd_bk_txns t ON t.id=sp.txn_id AND t.voided=0 WHERE sp.supplier_id=? AND sp.kind IN ('advance','payment') ORDER BY t.date,t.id", $sup['id']) as $p) { if ($left <= 0) break; $f = f3_payment_unallocated((int)$p['txn_id']); if ($f <= 0) continue; $x = min($f, $left); $left -= $x;
            run('INSERT INTO f3_purchase_returns(return_no,kind,supplier_id,date,reason,total,refund,refund_mode,bk_txn_id,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)', f3_seq('purchasereturn', 'PR'), 'adv_refund', $sup['id'], $d, 'Advance refunded (' . $no . ')', $x, $x, $mode, $txn, $u['id']); }
        f3_audit($u, 'supplier.refund', ['id' => (int)$sup['id'], 'amount' => $amt, 'txn' => $txn]); return ['ok' => true, 'pay_no' => $no, 'txn_id' => $txn];
    });
}

/* ------------------------------ returns, credit / debit notes ------------------------------ */
/** kind: return (goods back, from a bill) | credit_note (amount only — reduces what we owe) | charge (supplier extra charge — increases what we owe). */
function f3_return_save(array $b, array $u): array {
    if (!f3_can($u, 'purchase.reverse') && !f3_can($u, 'supplier.adjust')) fail('You do not have permission for returns / notes.', 403);
    return idem('f3-return', $b, $u, function () use ($b, $u) {
        $kind = in_array($b['kind'] ?? '', ['return', 'credit_note', 'charge'], true) ? $b['kind'] : fail('Choose return, credit note or extra charge.');
        $d = valid_date($b['date'] ?? f3_business_date()); f3_day_guard($d, $u, 'purchase'); $why = f3_text($b['reason'] ?? '', 500, 'Reason');
        $bill = !empty($b['bill_id']) ? f3_bill((int)$b['bill_id'], true) : null; if ($bill && $bill['status'] !== 'posted') fail('The bill is not posted.', 409);
        $sid = $bill ? (int)$bill['supplier_id'] : (int)($b['supplier_id'] ?? 0); $sup = f3_supplier($sid, true);
        $no = f3_seq('purchasereturn', 'PR'); $total = 0; $lines = [];
        if ($kind === 'return') {
            if (!$bill) fail('Choose the bill the goods came on.');
            foreach ((array)($b['lines'] ?? []) as $x) { if (($x['qty'] ?? '') === '' || (float)$x['qty'] == 0) continue;
                $l = row('SELECT * FROM f3_purchase_lines WHERE id=? AND bill_id=? FOR UPDATE', (int)$x['line_id'], $bill['id']); if (!$l) fail('Bill line not found.');
                $fm = f3_micro($l['factor']); $base = f3_to_base(f3_milli($x['qty'], '“' . $l['item_name'] . '” return quantity'), $fm); $can = f3_qm($l['received_base']) - f3_qm($l['returned_base']);
                if ($base > $can) fail('“' . $l['item_name'] . '”: only ' . f3_m3($can) . ' ' . $l['base_unit'] . ' was received and not yet returned.', 409);
                $lb = f3_qm($l['base_qty']); $amt = intdiv((int)$l['line_total'] * $base * 2 + $lb, 2 * $lb); $cost = intdiv((int)$l['cost_total'] * $base * 2 + $lb, 2 * $lb);
                $lines[] = [$l, $base, $amt, $cost]; $total += $amt; }
            if (!$lines) fail('Enter the quantity returned for at least one item.');
            if (($b['amount'] ?? '') !== '') { $ov = f3_paise($b['amount'], 'Return amount'); if ($ov > $total + 100 * count($lines)) fail('Return amount is more than the value of the items returned.'); $total = $ov; }
        } else { $total = f3_paise($b['amount'] ?? null, 'Amount'); }
        if ($total <= 0) fail('Amount must be more than zero.');
        $refund = ($b['refund'] ?? '') === '' ? 0 : f3_paise($b['refund'], 'Refund received'); if ($kind === 'charge') $refund = 0; if ($refund > $total) fail('Refund cannot be more than the return amount.');
        $mode = $refund > 0 ? (string)($b['refund_mode'] ?? 'Cash') : null;
        if ($kind === 'charge') $txn = f3_bk_post('f360:pc:' . $no, ['date' => $d, 'type' => 'purchase', 'party_id' => $sid, 'category' => $sup['default_category'] ?: 'Grocery', 'total' => $total, 'paid' => 0, 'account' => f3_acct('cash'), 'bill_no' => txt($b['note_no'] ?? '', 60) ?: $no, 'description' => 'Supplier extra charge / debit note ' . $no . ': ' . $why], $u);
        else $txn = f3_bk_post('f360:pr:' . $no, ['date' => $d, 'type' => 'purchase_return', 'party_id' => $sid, 'category' => $sup['default_category'] ?: 'Grocery', 'total' => $total, 'paid' => $refund, 'account' => $mode ? f3_mode_account($mode) : f3_acct('cash'), 'bill_no' => txt($b['note_no'] ?? '', 60) ?: $no,
            'description' => ($kind === 'return' ? 'Purchase return ' : 'Credit note ') . $no . ($bill ? ' · bill ' . $bill['bill_no'] : '') . ': ' . $why], $u);
        if ($bill && $kind !== 'charge') { $o = f3_bill_money($bill, f3_bill_settled([(int)$bill['id']])[(int)$bill['id']]); if ($total - $refund > $o['outstanding'] && empty($b['excess_ok'])) throw new F3Conflict('Credit of ' . f3_rs2($total - $refund) . ' is more than the bill outstanding ' . f3_rs2($o['outstanding']) . '. The extra becomes an advance with the supplier. Continue?', ['needs' => 'excess_ok']); }
        run('INSERT INTO f3_purchase_returns(return_no,kind,bill_id,supplier_id,date,reason,total,refund,refund_mode,bk_txn_id,client_key,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', $no, $kind, $bill['id'] ?? null, $sid, $d, $why, $total, $refund, $mode, $txn, txt($b['client_key'] ?? '', 64) ?: null, $u['id']); $rid = (int)db()->lastInsertId();
        foreach ($lines as [$l, $base, $amt, $cost]) {
            $mid = f3_stock_post(['item_id' => (int)$l['item_id'], 'location' => $bill['location'], 'date' => $d, 'kind' => 'return_out', 'qty_milli' => -$base, 'value' => $cost, 'source_type' => 'return', 'source_id' => $rid, 'source_line_id' => (int)$l['id'], 'reason' => 'Return ' . $no . ': ' . $why, 'allow_negative' => false, 'idem' => 'ret:' . $rid . ':' . $l['id']], $u);
            run('INSERT INTO f3_purchase_return_lines(return_id,bill_line_id,item_id,base_qty,amount,move_id) VALUES(?,?,?,?,?,?)', $rid, $l['id'], $l['item_id'], f3_m3($base), $amt, $mid);
            run('UPDATE f3_purchase_lines SET returned_base=returned_base+? WHERE id=?', f3_m3($base), $l['id']);
        }
        f3_audit($u, 'supplier.' . $kind, ['id' => $sid, 'no' => $no, 'total' => $total, 'refund' => $refund, 'bill' => $bill['id'] ?? null]);
        return ['ok' => true, 'return_no' => $no, 'id' => $rid, 'txn_id' => $txn];
    });
}

/* ------------------------------ aging & reports ------------------------------ */
const F3_AGING = [['not_due', 'Not due'], ['d1_7', '1–7 days'], ['d8_15', '8–15 days'], ['d16_30', '16–30 days'], ['d31_60', '31–60 days'], ['d60', '60+ days']];
function f3_age_bucket(int $days): string { return $days <= 0 ? 'not_due' : ($days <= 7 ? 'd1_7' : ($days <= 15 ? 'd8_15' : ($days <= 30 ? 'd16_30' : ($days <= 60 ? 'd31_60' : 'd60')))); }
function f3_open_bills_all(): array { // supplier_id => open bills (one pass)
    $b = rows("SELECT * FROM f3_purchase_bills WHERE status='posted' ORDER BY COALESCE(due_date,post_date),id"); $s = f3_bill_settled(array_map(fn($x) => (int)$x['id'], $b)); $o = [];
    foreach ($b as $x) { $m = f3_bill_money($x, $s[(int)$x['id']]); if ($m['outstanding'] > 0) $o[(int)$x['supplier_id']][] = ['id' => (int)$x['id'], 'bill_no' => $x['bill_no'], 'due_date' => $x['due_date']] + $m; }
    return $o;
}
function f3_aging_row(array $open, int $bal): array { $o = array_fill_keys(array_column(F3_AGING, 0), 0); foreach ($open as $b) $o[f3_age_bucket($b['overdue_days'])] += $b['outstanding'];
    $o['bills_total'] = array_sum($o); $o['other'] = $bal - $o['bills_total']; $o['balance'] = $bal; return $o; } // other = opening / older Books entries / unallocated advances
function f3_aging_one(int $sid): array { return f3_aging_row(f3_open_bills($sid), f3_sup_balances()[$sid]['balance'] ?? 0); }
function f3_aging(array $q, array $u): array {
    f3_need($u, 'supplier.outstanding', 'suppliers.view'); $rows = []; $tot = array_fill_keys(array_merge(array_column(F3_AGING, 0), ['other', 'balance', 'bills_total']), 0); $open = f3_open_bills_all();
    foreach (f3_sup_balances() as $p) { $a = f3_aging_row($open[$p['id']] ?? [], $p['balance']); if ($a['balance'] === 0 && $a['bills_total'] === 0) continue;
        $rows[] = ['id' => $p['id'], 'name' => $p['name'], 'phone' => $p['phone'], 'whatsapp' => $p['whatsapp'] ?? null] + $a; foreach ($tot as $k => $v) $tot[$k] += $a[$k]; }
    usort($rows, fn($a, $b) => $b['balance'] <=> $a['balance']); return ['buckets' => F3_AGING, 'rows' => $rows, 'totals' => $tot, 'as_of' => f3_business_date()];
}
function f3_purchase_register(array $q, array $u): array {
    f3_need($u, 'purchase.report'); $from = valid_date($q['from'] ?? substr(f3_business_date(), 0, 8) . '01'); $to = valid_date($q['to'] ?? f3_business_date());
    $w = ["b.status='posted'", 'b.post_date BETWEEN ? AND ?']; $a = [$from, $to]; if (!empty($q['supplier_id'])) { $w[] = 'b.supplier_id=?'; $a[] = (int)$q['supplier_id']; }
    $r = rows('SELECT b.id,b.bill_no,b.invoice_no,b.invoice_date,b.post_date,b.due_date,b.receipt_status,b.subtotal,b.line_discount+b.bill_discount discount,b.line_tax+b.other_tax tax,b.transport,b.rounding,b.grand_total,p.name supplier,p.gstin,b.supplier_id FROM f3_purchase_bills b JOIN dd_bk_parties p ON p.id=b.supplier_id WHERE ' . implode(' AND ', $w) . ' ORDER BY b.post_date,b.id', ...$a);
    $s = f3_bill_settled(array_map(fn($x) => (int)$x['id'], $r)); $t = ['subtotal' => 0, 'discount' => 0, 'tax' => 0, 'transport' => 0, 'grand_total' => 0, 'paid' => 0, 'outstanding' => 0]; $bySup = []; $byItem = [];
    foreach ($r as &$x) { $x += f3_bill_money($x + ['status' => 'posted'], $s[(int)$x['id']]); foreach (['subtotal', 'discount', 'tax', 'transport', 'grand_total', 'rounding'] as $k) $x[$k] = (int)$x[$k]; foreach ($t as $k => $v) $t[$k] += $x[$k] ?? 0; $bySup[$x['supplier']] = ($bySup[$x['supplier']] ?? 0) + $x['grand_total']; } unset($x);
    foreach (rows('SELECT l.item_name,i.category,l.base_unit,SUM(l.base_qty) q,SUM(l.line_total) v,COUNT(*) n FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id LEFT JOIN dd_bk_items i ON i.id=l.item_id WHERE ' . implode(' AND ', $w) . ' GROUP BY l.item_id ORDER BY v DESC', ...$a) as $i) $byItem[] = ['item' => $i['item_name'], 'category' => $i['category'], 'unit' => $i['base_unit'], 'qty' => (float)$i['q'], 'value' => (int)$i['v'], 'bills' => (int)$i['n']];
    arsort($bySup); $returns = (int)row("SELECT COALESCE(SUM(r.total),0) s FROM f3_purchase_returns r JOIN dd_bk_txns t ON t.id=r.bk_txn_id AND t.voided=0 WHERE r.kind IN ('return','credit_note') AND r.date BETWEEN ? AND ?", $from, $to)['s'];
    return ['from' => $from, 'to' => $to, 'rows' => $r, 'totals' => $t, 'by_supplier' => $bySup, 'by_item' => $byItem, 'returns' => $returns];
}
/** Alerts used by the assistant / notifications sync. */
function f3_purchase_alerts(array $u): array {
    $o = []; $bd = f3_business_date(); $on = function_exists('f3_alerts_on') ? f3_alerts_on() : []; $dd = max(1, (int)setting('f3_draft_alert_days', '1')); $cd = max(7, (int)setting('f3_count_every_days', '30'));
    if (f3_can($u, 'purchase.create')) { $n = (int)row("SELECT COUNT(*) n FROM f3_purchase_bills WHERE status='draft' AND created_by=? AND updated_at<?", $u['id'], gmdate('Y-m-d H:i:s', time() - 86400 * $dd))['n']; if ($n) $o[] = ['kind' => 'drafts', 'level' => 'info', 'text' => $n . ' purchase draft(s) not finished for more than ' . ($dd === 1 ? 'a day' : $dd . ' days') . '.', 'go' => '#/purchases?status=drafts']; }
    if (f3_can($u, 'purchase.approve')) { $n = (int)row("SELECT COUNT(*) n FROM f3_purchase_bills WHERE status='review'")['n']; if ($n) $o[] = ['kind' => 'review', 'level' => 'warn', 'text' => $n . ' purchase bill(s) wait for your approval.', 'go' => '#/purchases?status=review']; }
    if (f3_can($u, 'stock.receive')) { $n = (int)row("SELECT COUNT(*) n FROM f3_purchase_bills WHERE status='posted' AND receipt_status<>'received' AND post_date<?", next_date($bd, -1))['n']; if ($n) $o[] = ['kind' => 'receive', 'level' => 'warn', 'text' => $n . ' posted bill(s) have goods not yet received.', 'go' => '#/purchases?status=pending_receipt']; }
    if (f3_can($u, 'supplier.outstanding') || f3_can($u, 'supplier.pay')) { $od = 0; $n = 0; foreach (rows("SELECT * FROM f3_purchase_bills WHERE status='posted' AND due_date<?", $bd) as $b) { $m = f3_bill_money($b, f3_bill_settled([(int)$b['id']])[(int)$b['id']]); if ($m['outstanding'] > 0) { $od += $m['outstanding']; $n++; } }
        if ($n) $o[] = ['kind' => 'overdue', 'level' => 'warn', 'text' => $n . ' supplier bill(s) overdue · ' . f3_rs($od) . '.', 'go' => '#/aging']; }
    if (f3_can($u, 'stock.view')) { $n = (int)row("SELECT COUNT(*) n FROM dd_bk_items i LEFT JOIN f3_stock_balances b ON b.item_id=i.id AND b.location='Main Store' WHERE i.active=1 AND i.is_stock=1 AND GREATEST(i.min_level,i.reorder)>0 AND COALESCE(b.qty,0)<=GREATEST(i.min_level,i.reorder)")['n']; if ($n) $o[] = ['kind' => 'low', 'level' => 'info', 'text' => $n . ' item(s) at or below reorder level.', 'go' => '#/stock?low=1']; }
    if (f3_can($u, 'stock.count')) { $last = row("SELECT MAX(approved_at) d FROM f3_stock_counts WHERE status='approved'")['d']; if ((!$last || $last < gmdate('Y-m-d', strtotime($bd . ' -' . $cd . ' days'))) && row('SELECT 1 FROM f3_stock_balances WHERE qty<>0 LIMIT 1')) $o[] = ['kind' => 'count', 'level' => 'info', 'text' => 'No physical stock count in the last ' . $cd . ' days.', 'go' => '#/count']; }
    return array_values(array_filter($o, fn($x) => $on[$x['kind']] ?? true));
}
