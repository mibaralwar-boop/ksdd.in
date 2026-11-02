<?php
declare(strict_types=1);
// Finance 360 V29 — finance additions on top of V23 (single code path for web + Flutter):
//   • Daily closing is FINAL on submit (owner decision V29 §8): no pending admin approval. The accounting-correct
//     drawer variance (actual − expected cash) is posted automatically as Cash Acess / Cash Short; the owner's
//     operational difference stays a separately labelled figure. Locked days change only via reopen with reason.
//   • Optional daybook (hard-copy register) photos on a closing — never required, never blocks finalisation.
//   • Supplier-first purchase: supplier balance, last/frequent items with last rates, other suppliers' unit-normalised prices.
//   • Rate-change confirmation on purchase bills (previous vs new, per stock unit).
//   • Platform context for the signed-in user (restaurant, branch, subscription, branches to switch to).
require_once __DIR__ . '/core.php';

const F29_GET = ['closing-photos', 'supplier-suggest', 'platform-context'];
const F29_POST = ['closing-photo', 'closing-photo-remove'];
const F29_MAX_PHOTOS = 6;

function f29_get(string $route, array $q, array $u): array {
    return match ($route) {
        'closing-photos' => f29_closing_photos(valid_date($q['date'] ?? ''), $u),
        'supplier-suggest' => f29_supplier_suggest((int)($q['supplier_id'] ?? 0), $u),
        'platform-context' => f29_platform_context($u),
    };
}
function f29_post(string $route, array $b, array $u): array {
    return match ($route) {
        'closing-photo' => f29_closing_photo_add($b, $u),
        'closing-photo-remove' => f29_closing_photo_remove($b, $u),
    };
}

/* ------------------------------ platform context ------------------------------ */
function f29_platform_context(array $u): array {
    if (!platform_mode()) return ['platform' => false];
    $t = db_target(); require_once dirname(__DIR__) . '/platform/tenancy.php';
    $tenant = prow('SELECT id,uid,name,logo_path FROM p_tenants WHERE id=?', (int)$t['tenant_id']);
    $mem = !empty($t['platform_user_id']) ? tenancy_memberships((int)$t['platform_user_id']) : [];
    return ['platform' => true, 'tenant' => ['id' => (int)$tenant['id'], 'name' => $tenant['name'], 'logo' => $tenant['logo_path'] ? 'logo.php?t=' . $tenant['uid'] : null],
            'branch' => ['id' => (int)$t['branch_id'], 'name' => $t['branch_name']], 'subscription' => billing_public_state((int)$t['tenant_id']),
            'support_session' => !empty($t['support_grant']), 'memberships' => $mem];
}

/* ------------------------------ daily closing: direct finalisation ------------------------------ */
function f29_closing_direct_on(): bool { return setting('f3_closing_direct', platform_mode() ? '1' : '0') === '1'; }
/** Called by f3_closing_finalize() right after the closing row is locked as 'submitted' (same DB transaction). */
function f29_closing_autofinal(array $c, string $d, array $u, array $calc, bool $hold): ?array {
    if (!f29_closing_direct_on()) return null;
    $basis = setting('f3_closing_auto_basis', 'accounting'); // accounting (drawer vs books) | operational | none
    $amt = $basis === 'accounting' ? (int)$calc['accounting_variance'] : ($basis === 'operational' ? (int)$calc['operational_diff'] : 0);
    $txn = null; $label = $amt > 0 ? 'Cash Acess' : ($amt < 0 ? 'Cash Short' : 'Balanced');
    if ($amt !== 0) $txn = $amt > 0
        ? f3_bk_post(F3_VAR_KEY . $d . ':r' . $c['revision'], ['date' => $d, 'type' => 'cash_access', 'total' => $amt, 'account' => f3_acct('cash'), 'description' => 'Cash Acess (closing ' . $d . ', ' . $basis . ' variance, auto-posted at final closing)'], $u)
        : f3_bk_post(F3_VAR_KEY . $d . ':r' . $c['revision'], ['date' => $d, 'type' => 'reduce_cash', 'total' => -$amt, 'account' => f3_acct('cash'), 'description' => 'Cash Short (closing ' . $d . ', ' . $basis . ' variance, auto-posted at final closing)'], $u);
    run("UPDATE f3_closings SET status='approved',version=version+1,variance_class=?,variance_basis=?,variance_amount=?,variance_txn_id=?,approved_by=?,approved_at=UTC_TIMESTAMP() WHERE id=?",
        $amt === 0 ? 'no_posting' : 'auto_' . substr($basis, 0, 15), $amt === 0 ? null : substr($basis, 0, 12), $amt, $txn, $u['id'], $c['id']);
    f3_closing_event($c, 'finalized', $u, 'Final on submit · ' . $label . ($amt ? ' ' . f3_rs(abs($amt)) . ' posted (' . $basis . ')' : ''));
    if ($hold) f3_notify('closing-held', 'closing-held-' . $d . '-r' . $c['revision'], 'Closing ' . $d . ': sales held (Books already had sales)', 'The closing is final. Choose “Post closing sales” or “Keep existing Books sales” so sales are not counted twice.', 'closing.approve', 'closing:' . $d);
    f3_audit($u, 'closing.finalize_direct', ['date' => $d, 'revision' => (int)$c['revision'], 'variance' => $amt, 'basis' => $basis, 'txn' => $txn, 'held' => $hold]);
    return ['variance_posted' => $amt, 'variance_label' => $label, 'basis' => $basis];
}

/* ------------------------------ daybook photos ------------------------------ */
function f29_closing_photos(string $d, array $u): array {
    f3_need($u, 'closing.view', 'closing.entry');
    $c = row('SELECT id,status,revision FROM f3_closings WHERE business_date=?', $d);
    $rows = $c ? rows('SELECT cf.id,cf.file_id,cf.revision,cf.created_at,f.mime,f.size,us.name by_name FROM f3_closing_files cf JOIN dd_files f ON f.id=cf.file_id LEFT JOIN dd_users us ON us.id=cf.created_by WHERE cf.closing_id=? AND cf.removed_at IS NULL ORDER BY cf.id', $c['id']) : [];
    return ['date' => $d, 'status' => $c['status'] ?? null, 'max' => F29_MAX_PHOTOS, 'photos' => array_map(fn($r) => ['id' => (int)$r['id'], 'file_id' => (int)$r['file_id'], 'revision' => (int)$r['revision'], 'mime' => $r['mime'], 'size' => (int)$r['size'], 'by' => $r['by_name'], 'at' => $r['created_at']], $rows)];
}
/** Attach one photo. Allowed before AND after finalisation (attaching evidence never changes money); removal only while open. */
function f29_closing_photo_add(array $b, array $u): array {
    f3_need($u, 'closing.entry', 'closing.finalize'); $d = valid_date($b['date'] ?? '');
    return idem('f29-closing-photo-' . $d, $b, $u, function () use ($b, $u, $d) {
        $c = row('SELECT * FROM f3_closings WHERE business_date=? FOR UPDATE', $d); if (!$c) fail('Start the closing for ' . $d . ' first.', 409);
        if (!f3_can($u, 'closing.approve') && $d < next_date(f3_business_date(), -max(2, f3_set_int('f3_counter_backlog_days') + 1))) fail('Photos for older days can be added by an admin only.', 403);
        $n = (int)row('SELECT COUNT(*) n FROM f3_closing_files WHERE closing_id=? AND removed_at IS NULL', $c['id'])['n']; if ($n >= F29_MAX_PHOTOS) fail('Up to ' . F29_MAX_PHOTOS . ' photos per day.', 409);
        $f = $b['file'] ?? null; if (!is_array($f) || empty($f['data'])) fail('Choose a photo.');
        $raw = base64_decode((string)$f['data'], true); if ($raw === false) fail('Upload is empty.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($raw); if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) fail('Daybook photo must be a JPG, PNG or WEBP image.');
        $fid = store_upload(['data' => $f['data'], 'name' => 'daybook-' . $d], $u);
        run('INSERT INTO f3_closing_files(closing_id,business_date,revision,file_id,client_key,created_by) VALUES(?,?,?,?,?,?)', $c['id'], $d, $c['revision'], $fid, null, $u['id']);
        f3_audit($u, 'closing.photo_add', ['date' => $d, 'file' => $fid, 'status' => $c['status']]);
        return ['ok' => true, 'file_id' => $fid] + f29_closing_photos($d, $u);
    });
}
function f29_closing_photo_remove(array $b, array $u): array {
    f3_need($u, 'closing.entry'); $id = (int)($b['id'] ?? 0);
    $r = row('SELECT cf.*,c.status FROM f3_closing_files cf JOIN f3_closings c ON c.id=cf.closing_id WHERE cf.id=? AND cf.removed_at IS NULL FOR UPDATE', $id) ?? fail('Photo not found.', 404);
    if (in_array($r['status'], ['submitted', 'approved'], true)) fail('The day is closed. Photos stay with the closing record (ask an admin to reopen the day to change it).', 409);
    if ((int)$r['created_by'] !== (int)$u['id'] && !f3_can($u, 'closing.approve')) fail('Only the person who added the photo can remove it.', 403);
    run('UPDATE f3_closing_files SET removed_at=UTC_TIMESTAMP(),removed_by=? WHERE id=?', $u['id'], $id); // file kept (audit); link removed
    f3_audit($u, 'closing.photo_remove', ['date' => $r['business_date'], 'file' => (int)$r['file_id']]);
    return f29_closing_photos($r['business_date'], $u);
}

/* ------------------------------ supplier-first purchase ------------------------------ */
function f29_supplier_suggest(int $sid, array $u): array {
    f3_need($u, 'purchase.create', 'purchase.view'); $sup = f3_supplier($sid);
    $bal = f3_sup_balances()[$sid] ?? null; $money = f3_can($u, 'supplier.outstanding') || f3_can($u, 'suppliers.view');
    $last = row("SELECT id,bill_no,invoice_no,invoice_date,grand_total FROM f3_purchase_bills WHERE supplier_id=? AND status='posted' ORDER BY invoice_date DESC,id DESC LIMIT 1", $sid);
    $items = rows("SELECT l.item_id,MAX(l.item_name) item_name,COUNT(DISTINCT l.bill_id) times,MAX(b.invoice_date) last_date FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id
                   WHERE b.supplier_id=? AND b.status='posted' AND l.item_id IS NOT NULL GROUP BY l.item_id ORDER BY times DESC,last_date DESC LIMIT 12", $sid);
    $out = [];
    foreach ($items as $it) {
        $l = row("SELECT l.unit,l.factor,l.qty,l.rate,l.base_unit FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id WHERE b.supplier_id=? AND l.item_id=? AND b.status='posted' ORDER BY b.invoice_date DESC,l.id DESC LIMIT 1", $sid, $it['item_id']);
        $x = ['item_id' => (int)$it['item_id'], 'item_name' => $it['item_name'], 'times' => (int)$it['times'], 'last_date' => $it['last_date'], 'unit' => $l['unit'], 'factor' => (float)$l['factor'], 'base_unit' => $l['base_unit'], 'last_qty' => (float)$l['qty'], 'last_rate' => (int)$l['rate']];
        if (f3_can($u, 'purchase.view') || f3_can($u, 'stock.valuation')) {
            $ph = f3_price_history((int)$it['item_id'], $u);
            $x['compare_basis'] = $ph['basis']; $x['other_suppliers'] = array_values(array_filter($ph['suppliers'], fn($s) => (int)($s['supplier_id'] ?? 0) !== $sid));
        }
        $out[] = $x;
    }
    return ['supplier' => ['id' => $sid, 'name' => $sup['name'], 'credit_days' => (int)$sup['credit_days']],
            'balance' => $money && $bal ? (int)$bal['balance'] : null, 'balance_note' => $money ? 'Positive = you owe the supplier; negative = advance with the supplier (from the supplier ledger).' : 'Hidden by permission',
            'last_bill' => $last ? ['id' => (int)$last['id'], 'bill_no' => $last['bill_no'], 'invoice_no' => $last['invoice_no'], 'date' => $last['invoice_date'], 'total' => (int)$last['grand_total']] : null, 'suggested_items' => $out];
}
/** Lines whose rate differs from this supplier's last POSTED rate for the same item, compared per stock unit (pack-size safe). */
function f29_rate_changes(array $bill): array {
    if (setting('f3_rate_confirm', '1') !== '1' || !$bill['supplier_id']) return [];
    $o = [];
    foreach (rows('SELECT * FROM f3_purchase_lines WHERE bill_id=? AND item_id IS NOT NULL ORDER BY sort,id', $bill['id']) as $l) {
        $p = row("SELECT l.rate,l.unit,l.factor,b.invoice_date FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id WHERE b.supplier_id=? AND l.item_id=? AND b.status='posted' AND b.id<>? ORDER BY b.invoice_date DESC,l.id DESC LIMIT 1", $bill['supplier_id'], $l['item_id'], $bill['id']);
        if (!$p) continue;
        $newPer = (float)$l['factor'] > 0 ? (int)round((int)$l['rate'] / (float)$l['factor']) : (int)$l['rate'];
        $oldPer = (float)$p['factor'] > 0 ? (int)round((int)$p['rate'] / (float)$p['factor']) : (int)$p['rate'];
        if ($newPer === $oldPer) continue;
        $o[] = ['item' => $l['item_name'], 'item_id' => (int)$l['item_id'], 'previous_rate' => (int)$p['rate'], 'previous_unit' => $p['unit'], 'previous_date' => $p['invoice_date'], 'new_rate' => (int)$l['rate'], 'new_unit' => $l['unit'],
                'previous_per_stock_unit' => $oldPer, 'new_per_stock_unit' => $newPer, 'stock_unit' => $l['base_unit'], 'change_pct' => $oldPer > 0 ? round(($newPer - $oldPer) * 100 / $oldPer, 1) : null,
                'unit_note' => $p['unit'] !== $l['unit'] ? 'Unit changed (' . $p['unit'] . ' → ' . $l['unit'] . '): compared per ' . ($l['base_unit'] ?: 'stock unit') : null];
    }
    return $o;
}
