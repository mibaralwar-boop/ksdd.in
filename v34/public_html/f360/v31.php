<?php
declare(strict_types=1);
// Finance 360 V31 — features that need the single shared database:
//   • Inter-branch STOCK transfer: send (transfer_out at the source, valued at the source's average cost) → in transit →
//     receive (transfer_in at the destination with the SAME value). Quantity and value are conserved; nothing is revenue or expense.
//   • Inter-branch CASH transfer: source "Cash → Inter-branch transit" and destination "Inter-branch transit → Cash" are Books
//     transfers (never sales, never expenses), so tenant-wide consolidation never double counts them.
//   • Each transfer is one row in p_branch_transfers (platform table, carries tenant_id + both branch ids). Because every
//     restaurant lives in ONE database, the stock/cash posting and the transfer record commit in the SAME transaction.
//   • Rules: same restaurant (tenant) only; source ≠ destination; destination must be active; receive only by a member of the
//     destination branch with the receive permission; a transfer is received or cancelled exactly once.
require_once __DIR__ . '/core.php';

const F31_GET = ['transfers', 'transfer-branches'];
const F31_POST = ['transfer-stock-send', 'transfer-cash-send', 'transfer-receive', 'transfer-cancel'];
const F31_TRANSIT_ACCOUNT = 'Inter-branch transit';

function f31_get(string $route, array $q, array $u): array {
    return match ($route) { 'transfers' => f31_transfer_list($q, $u), 'transfer-branches' => f31_transfer_branches($u) };
}
function f31_post(string $route, array $b, array $u): array {
    return match ($route) {
        'transfer-stock-send' => f31_stock_send($b, $u), 'transfer-cash-send' => f31_cash_send($b, $u),
        'transfer-receive' => f31_receive($b, $u), 'transfer-cancel' => f31_cancel($b, $u),
    };
}
/** Current scope from the VERIFIED connection context (never from the request). */
function f31_scope(): array {
    $t = db_target(); $tid = (int)($t['tenant_id'] ?? 0); $bid = (int)($t['branch_id'] ?? 0);
    if (!mt_enabled() || $tid <= 0 || $bid <= 0) fail('Inter-branch transfers need the multi-restaurant (single database) mode.', 409);
    return [$tid, $bid];
}
function f31_branch_ok(int $tid, int $bid): array {
    $b = row("SELECT id,name,status FROM p_branches WHERE id=? AND tenant_id=?", $bid, $tid); // global table, read on the scoped connection
    if (!$b || $b['status'] !== 'active') fail('Destination branch not available.', 404);
    return $b;
}
function f31_transfer_branches(array $u): array {
    f3_need($u, 'stock.issue', 'cash.transfer', 'stock.receive'); [$tid, $bid] = f31_scope();
    return ['branches' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], rows("SELECT id,name FROM p_branches WHERE tenant_id=? AND status='active' AND id<>? ORDER BY name", $tid, $bid))];
}
function f31_ensure_transit_account(): void {
    $a = bk_accounts(); if (!in_array(F31_TRANSIT_ACCOUNT, $a, true)) { $a[] = F31_TRANSIT_ACCOUNT; set_setting('bk_accounts', json_encode(array_values($a), JSON_UNESCAPED_UNICODE)); }
    $m = json_decode(setting('post_account_map', ''), true); if (is_array($m) && !isset($m['books:' . F31_TRANSIT_ACCOUNT])) { $m['books:' . F31_TRANSIT_ACCOUNT] = '1150'; set_setting('post_account_map', json_encode($m, JSON_UNESCAPED_UNICODE)); } // GL 1150 Cash in Transit
}
function f31_row(int $id, bool $lock = false): ?array { return row('SELECT * FROM p_branch_transfers WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), $id); }
function f31_pub(array $r): array {
    $p = json_decode((string)$r['payload'], true) ?: [];
    return ['id' => (int)$r['id'], 'no' => $r['uid'], 'kind' => $r['kind'], 'status' => $r['status'], 'from_branch' => (int)$r['from_branch_id'], 'to_branch' => (int)$r['to_branch_id'],
            'from_name' => $r['from_name'] ?? null, 'to_name' => $r['to_name'] ?? null, 'date_sent' => $r['sent_date'], 'date_received' => $r['received_date'], 'note' => $r['note'],
            'lines' => $p['lines'] ?? [], 'amount_paise' => isset($p['amount']) ? (int)$p['amount'] : null, 'value_paise' => (int)$r['value_paise']];
}
function f31_transfer_list(array $q, array $u): array {
    f3_need($u, 'stock.view', 'cash.view', 'stock.receive'); [$tid, $bid] = f31_scope();
    $rs = rows("SELECT x.*,fb.name from_name,tb.name to_name FROM p_branch_transfers x JOIN p_branches fb ON fb.id=x.from_branch_id JOIN p_branches tb ON tb.id=x.to_branch_id
                WHERE x.tenant_id=? AND (x.from_branch_id=? OR x.to_branch_id=?) ORDER BY x.id DESC LIMIT 200", $tid, $bid, $bid);
    return ['transfers' => array_map('f31_pub', $rs), 'incoming_pending' => count(array_filter($rs, fn($r) => (int)$r['to_branch_id'] === $bid && $r['status'] === 'in_transit'))];
}
function f31_new(int $tid, int $from, int $to, string $kind, string $date, array $payload, int $value, string $note, array $u): int {
    $uid = 'BT' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 10));
    run("INSERT INTO p_branch_transfers(uid,tenant_id,from_branch_id,to_branch_id,kind,status,sent_date,payload,value_paise,note,sent_by_local,sent_by_platform) VALUES(?,?,?,?,?,'in_transit',?,?,?,?,?,?)",
        $uid, $tid, $from, $to, $kind, $date, json_encode($payload, JSON_UNESCAPED_UNICODE), $value, $note, (int)$u['id'], (int)(db_target()['platform_user_id'] ?? 0) ?: null);
    return (int)db()->lastInsertId();
}

/** SEND stock: transfer_out at the source; the goods are "in transit" until the destination receives them. */
function f31_stock_send(array $b, array $u): array {
    f3_need($u, 'stock.issue'); [$tid, $bid] = f31_scope();
    $to = (int)($b['to_branch_id'] ?? 0); if ($to === $bid) fail('Choose a different branch.'); $dest = f31_branch_ok($tid, $to);
    return idem('f31-xfer', $b, $u, function () use ($b, $u, $tid, $bid, $to, $dest) {
        $d = valid_date($b['date'] ?? f3_business_date()); $loc = (string)($b['location'] ?? 'Main Store'); $note = txt($b['note'] ?? '', 250);
        $lines = []; $total = 0;
        foreach (array_slice((array)($b['lines'] ?? [['item_id' => $b['item_id'] ?? 0, 'qty' => $b['qty'] ?? '', 'unit' => $b['unit'] ?? null]]), 0, 50) as $ln) {
            $i = f3_item((int)($ln['item_id'] ?? 0), true); $unit = f3_unit_norm((string)($ln['unit'] ?? $i['unit'])); $fm = f3_unit_factor_micro($i, $unit);
            $qm = f3_milli($ln['qty'] ?? '', 'Quantity'); $base = f3_to_base($qm, $fm); if ($base <= 0) fail('Quantity is too small for the base unit.');
            $mv = f3_stock_post(['item_id' => $i['id'], 'location' => $loc, 'date' => $d, 'kind' => 'transfer_out', 'qty_milli' => -$base, 'entered_milli' => $qm, 'entered_unit' => $unit, 'factor_micro' => $fm,
                                 'source_type' => 'branch_transfer', 'reason' => 'To branch: ' . $dest['name'] . ($note !== '' ? ' — ' . $note : ''), 'idem' => 'bt-out:' . bin2hex(random_bytes(8))], $u);
            $val = -(int)row('SELECT value FROM f3_stock_moves WHERE id=?', $mv)['value']; $total += $val;
            $lines[] = ['item' => $i['name'], 'name_key' => $i['name_key'], 'unit' => $i['unit'], 'category' => $i['category'], 'qty_milli' => $base, 'value' => $val, 'out_move' => $mv];
        }
        if (!$lines) fail('Add at least one item.');
        $id = f31_new($tid, $bid, $to, 'stock', $d, ['lines' => $lines, 'from_location' => $loc], $total, $note, $u);
        foreach ($lines as $l) run("UPDATE f3_stock_moves SET source_id=? WHERE id=?", $id, $l['out_move']);
        f3_audit($u, 'transfer.stock_send', ['transfer' => $id, 'to_branch' => $to, 'value' => $total]);
        return ['ok' => true, 'transfer' => f31_pub(f31_row($id))];
    });
}
/** SEND cash: Books transfer from the chosen account to "Inter-branch transit" (not an expense). */
function f31_cash_send(array $b, array $u): array {
    f3_need($u, 'cash.transfer'); [$tid, $bid] = f31_scope();
    $to = (int)($b['to_branch_id'] ?? 0); if ($to === $bid) fail('Choose a different branch.'); $dest = f31_branch_ok($tid, $to);
    return idem('f31-xfer', $b, $u, function () use ($b, $u, $tid, $bid, $to, $dest) {
        $d = valid_date($b['date'] ?? f3_business_date()); f3_day_guard($d, $u, 'transfer'); $amt = f3_paise($b['amount'] ?? null, 'Amount'); if ($amt <= 0) fail('Amount must be more than zero.');
        $from = (string)($b['account'] ?? f3_set('f3_acct_cash')); $note = txt($b['note'] ?? '', 250); f31_ensure_transit_account();
        $r = bk_txn_save(['type' => 'transfer', 'date' => $d, 'account' => $from, 'to_account' => F31_TRANSIT_ACCOUNT, 'total' => rupees($amt), 'description' => 'Cash to branch ' . $dest['name'] . ($note !== '' ? ' — ' . $note : '')], $u);
        run("UPDATE dd_bk_txns SET source='f31' WHERE id=?", $r['id']);
        $id = f31_new($tid, $bid, $to, 'cash', $d, ['amount' => $amt, 'from_account' => $from, 'out_txn' => (int)$r['id']], $amt, $note, $u);
        f3_audit($u, 'transfer.cash_send', ['transfer' => $id, 'to_branch' => $to, 'amount' => $amt]);
        return ['ok' => true, 'transfer' => f31_pub(f31_row($id))];
    });
}
/** RECEIVE at the destination (must be the branch the user is signed in to). Exactly once. */
function f31_receive(array $b, array $u): array {
    [$tid, $bid] = f31_scope();
    return idem('f31-xfer', $b, $u, function () use ($b, $u, $tid, $bid) {
        $x = f31_row((int)($b['id'] ?? 0), true);
        if (!$x || (int)$x['tenant_id'] !== $tid || (int)$x['to_branch_id'] !== $bid) fail('Transfer not found.', 404); // same answer for "not yours"
        if ($x['status'] !== 'in_transit') fail('This transfer was already ' . $x['status'] . '.', 409);
        $d = valid_date($b['date'] ?? f3_business_date()); if ($d < $x['sent_date']) fail('Receipt date cannot be before the sending date.');
        $p = json_decode((string)$x['payload'], true); $src = (string)row('SELECT name FROM p_branches WHERE id=?', (int)$x['from_branch_id'])['name'];
        if ($x['kind'] === 'stock') {
            f3_need($u, 'stock.receive'); $loc = (string)($b['location'] ?? 'Main Store');
            foreach ($p['lines'] as &$l) {
                $item = row('SELECT * FROM dd_bk_items WHERE name_key=? AND (merged_into IS NULL OR merged_into=0) ORDER BY active DESC,id LIMIT 1', $l['name_key']);
                if (!$item) { run('INSERT INTO dd_bk_items(name,name_key,unit,category,is_stock,active) VALUES(?,?,?,?,1,1)', $l['item'], $l['name_key'], $l['unit'], $l['category']); $item = row('SELECT * FROM dd_bk_items WHERE id=?', (int)db()->lastInsertId()); }
                if ((string)$item['unit'] !== (string)$l['unit']) fail('Unit of “' . $l['item'] . '” differs between the branches (' . $l['unit'] . ' vs ' . $item['unit'] . '). Align the item units first.', 409);
                $l['in_move'] = f3_stock_post(['item_id' => (int)$item['id'], 'location' => $loc, 'date' => $d, 'kind' => 'transfer_in', 'qty_milli' => (int)$l['qty_milli'], 'value' => (int)$l['value'], 'entered_milli' => (int)$l['qty_milli'], 'entered_unit' => $l['unit'], 'factor_micro' => 1000000,
                                               'source_type' => 'branch_transfer', 'source_id' => (int)$x['id'], 'reason' => 'From branch: ' . $src, 'idem' => 'bt-in:' . $x['id'] . ':' . $l['out_move']], $u);
            } unset($l);
        } else {
            f3_need($u, 'cash.transfer'); f3_day_guard($d, $u, 'transfer'); f31_ensure_transit_account();
            $acct = (string)($b['account'] ?? f3_set('f3_acct_cash'));
            $r = bk_txn_save(['type' => 'transfer', 'date' => $d, 'account' => F31_TRANSIT_ACCOUNT, 'to_account' => $acct, 'total' => rupees((int)$p['amount']), 'description' => 'Cash from branch ' . $src], $u);
            run("UPDATE dd_bk_txns SET source='f31' WHERE id=?", $r['id']); $p['in_txn'] = (int)$r['id']; $p['to_account'] = $acct;
        }
        run("UPDATE p_branch_transfers SET status='received',received_date=?,payload=?,received_by_local=?,received_at=UTC_TIMESTAMP() WHERE id=? AND status='in_transit'", $d, json_encode($p, JSON_UNESCAPED_UNICODE), (int)$u['id'], (int)$x['id']);
        f3_audit($u, 'transfer.receive', ['transfer' => (int)$x['id']]);
        return ['ok' => true, 'transfer' => f31_pub(f31_row((int)$x['id']))];
    });
}
/** CANCEL (by the SENDING branch, before receipt): exact reversal at the source — stock back / cash back. */
function f31_cancel(array $b, array $u): array {
    [$tid, $bid] = f31_scope();
    return idem('f31-xfer', $b, $u, function () use ($b, $u, $tid, $bid) {
        $x = f31_row((int)($b['id'] ?? 0), true);
        if (!$x || (int)$x['tenant_id'] !== $tid || (int)$x['from_branch_id'] !== $bid) fail('Transfer not found.', 404);
        if ($x['status'] !== 'in_transit') fail('Only a transfer still in transit can be cancelled.', 409);
        $why = f3_text($b['reason'] ?? '', 250, 'Reason'); if ($why === '') fail('A reason is required.');
        $p = json_decode((string)$x['payload'], true);
        if ($x['kind'] === 'stock') { f3_need($u, 'stock.issue'); foreach ($p['lines'] as $l) f3_stock_reverse_move((int)$l['out_move'], 'Transfer cancelled: ' . $why, $u, true); }
        else { f3_need($u, 'cash.transfer'); $r = bk_txn_save(['type' => 'transfer', 'date' => f3_business_date(), 'account' => F31_TRANSIT_ACCOUNT, 'to_account' => (string)$p['from_account'], 'total' => rupees((int)$p['amount']), 'description' => 'Cancelled branch transfer ' . $x['uid']], $u); run("UPDATE dd_bk_txns SET source='f31' WHERE id=?", $r['id']); }
        run("UPDATE p_branch_transfers SET status='cancelled',cancel_reason=?,received_at=UTC_TIMESTAMP() WHERE id=?", $why, (int)$x['id']);
        f3_audit($u, 'transfer.cancel', ['transfer' => (int)$x['id'], 'reason' => $why]);
        return ['ok' => true, 'transfer' => f31_pub(f31_row((int)$x['id']))];
    });
}
