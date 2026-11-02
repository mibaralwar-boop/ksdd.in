<?php
declare(strict_types=1);
// Finance 360 V17 — Purchases, Suppliers, Item master & Stock ledger tests. Included by tests/run_tests.php (same helpers, same staging DB, fake clock 2026-11-01).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once 'f360/purchase.php';
global $own, $acc, $mgr, $ctr;
$ck = fn() => 'test-' . bin2hex(random_bytes(8));
$hashV16 = []; foreach (['f3_closings', 'f3_pay_lines', 'f3_pay_periods', 'f3_staff_txns', 'f3_pay_payments', 'f3_expenses'] as $tb) { try { $hashV16[$tb] = md5(json_encode(rows("SELECT * FROM `$tb` ORDER BY 1"))); } catch (PDOException $e) {} }
$cashBal = fn() => (int)row("SELECT COALESCE(SUM(CASE WHEN type IN ('payment_in','sale','add_cash','owner_in','cash_access','other_income') THEN paid WHEN type='purchase_return' THEN paid ELSE -paid END),0) b FROM dd_bk_txns WHERE voided=0 AND account='Cash' AND type<>'transfer'")['b'];
$bal = fn(int $sid) => f3_sup_balances()[$sid]['balance'] ?? 0;
$stock = fn(int $iid) => row("SELECT qty,value FROM f3_stock_balances WHERE item_id=? AND location='Main Store'", $iid) ?: ['qty' => '0', 'value' => 0];

echo "\n== V17 · Users & permissions ==\n";
$ex = row('SELECT id,role FROM dd_users WHERE email=?', 'store@staging.test');
$storeId = f3_user_save(['id' => $ex['id'] ?? 0, 'name' => 'Demo Store', 'email' => 'store@staging.test', 'role' => $ex['role'] ?? 'counter', 'template' => 'store', 'password' => 'StorePass#2026x'], $own)['id'];
$store = U('store@staging.test'); f3_perm_cache_clear();
$depId = f3_user_save(['name' => 'Demo Deputy', 'email' => 'deputy@staging.test', 'role' => 'manager', 'template' => 'accountant', 'overrides' => ['users.manage' => true], 'password' => 'DeputyPass#2026'], $own)['id']; $dep = U('deputy@staging.test'); f3_perm_cache_clear();
t('V17-PERM-01', 'Only the owner can grant permissions (a user manager without owner role is blocked)', fn() => throws(fn() => f3_user_save(['id' => $ctr['id'], 'name' => $ctr['name'], 'email' => $ctr['email'], 'role' => 'counter', 'template' => 'accountant'], $dep), 'Only the owner')
    && throws(fn() => f3_user_save(['id' => $ctr['id'], 'name' => $ctr['name'], 'email' => $ctr['email'], 'role' => 'counter', 'template' => 'counter', 'pay_max' => '100'], $dep), 'Only the owner'));
t('V17-PERM-02', 'Counter cannot open purchases, suppliers or stock', fn() => throws(fn() => f3_bill_list([], $ctr), 'permission') && throws(fn() => f3_sup_save(['name' => 'X'], $ctr), 'permission') && throws(fn() => f3_stock_overview($ctr), 'permission'));
t('V17-PERM-03', 'Store clerk: may create items/drafts, may NOT set opening balance, adjust stock or pay', function () use ($store) {
    throws(fn() => f3_stock_manual(['kind' => 'adjust_in', 'item_id' => 1, 'qty' => '1', 'reason' => 'x'], $store), 'permission');
    throws(fn() => f3_sup_pay(['client_key' => 'abcdefgh1', 'supplier_id' => 1, 'amount' => '1'], $store), 'permission');
    return throws(fn() => f3_sup_save(['name' => 'DEMO Opening Test', 'opening' => '500'], $store), 'permission'); });

echo "\n== V17 · Item master & unit conversions ==\n";
$rice = f3_item_save(['name' => 'DEMO Basmati Rice', 'unit' => 'kg', 'category' => 'Grocery', 'reorder' => '5', 'tax_rate' => '5'], $own)['id'];
$sugar = f3_item_save(['name' => 'DEMO Sugar', 'unit' => 'kg', 'category' => 'Grocery'], $own)['id'];
$cola = f3_item_save(['name' => 'DEMO Cola 300ml', 'unit' => 'piece', 'purchase_unit' => 'carton', 'category' => 'Beverages', 'units' => [['unit' => 'carton', 'factor' => '24']], 'min_level' => '24'], $own)['id'];
$atta = f3_item_save(['name' => 'DEMO Atta', 'unit' => 'kg', 'purchase_unit' => 'bag', 'units' => [['unit' => 'bag', 'factor' => '25']]], $store)['id'];
$gas = f3_item_save(['name' => 'DEMO Service charge', 'unit' => 'piece', 'untracked' => 1], $own)['id'];
t('V17-UNIT-01', 'Conversions: 2.5 kg, 250 g→0.25 kg, 3 cartons×24 = 72 pcs, 1 bag = 25 kg', function () use ($rice, $sugar, $cola, $atta) {
    eq(f3_to_base(f3_milli('2.5', 'q'), f3_unit_factor_micro(f3_item($rice), 'kg')), 2500, '2.5 kg'); eq(f3_to_base(f3_milli('250', 'q'), f3_unit_factor_micro(f3_item($sugar), 'g')), 250, '250 g');
    eq(f3_to_base(f3_milli('3', 'q'), f3_unit_factor_micro(f3_item($cola), 'carton')), 72000, '3 cartons'); eq(f3_to_base(f3_milli('1', 'q'), f3_unit_factor_micro(f3_item($atta), 'bag')), 25000, '1 bag');
    return eq(f3_to_base(f3_milli('1', 'q'), f3_unit_factor_micro(f3_item($cola), 'dozen')), 12000, 'dozen'); });
t('V17-UNIT-02', 'Pack size is never assumed (bag on rice is refused until defined)', fn() => throws(fn() => f3_unit_factor_micro(f3_item($rice), 'bag'), 'Define how many'));
t('V17-UNIT-03', 'Decimal quantity: max 3 decimals, no negatives', fn() => throws(fn() => f3_milli('1.2345', 'Q'), '3 decimals') && throws(fn() => f3_milli('-1', 'Q'), 'number'));
t('V17-ITEM-01', 'Duplicate item name (any spacing / case) is refused with the existing item', function () use ($rice) { try { f3_item_save(['name' => '  demo  basmati RICE ', 'unit' => 'kg'], $GLOBALS['own']); return false; } catch (F3Conflict $e) { return eq($e->extra['existing_id'], $rice); } });
t('V17-ITEM-02', 'Base unit cannot change once stock moved; items are deactivated, never deleted', function () use ($own) { $id = f3_item_save(['name' => 'DEMO Oil', 'unit' => 'litre'], $own)['id'];
    f3_stock_post(['item_id' => $id, 'location' => 'Main Store', 'date' => '2026-11-01', 'kind' => 'opening', 'qty_milli' => 1000, 'value' => 15000, 'source_type' => 'opening', 'reason' => 'Test opening', 'idem' => 'test:oil'], $own);
    throws(fn() => f3_item_save(['id' => $id, 'name' => 'DEMO Oil', 'unit' => 'kg'], $own), 'cannot change'); try { f3_item_active(['id' => $id, 'active' => 0], $own); return false; } catch (F3Conflict $e) {} f3_item_active(['id' => $id, 'active' => 0, 'force' => 1], $own);
    return eq((int)row('SELECT active FROM dd_bk_items WHERE id=?', $id)['active'], 0) && eq((int)row('SELECT COUNT(*) n FROM dd_bk_items WHERE id=?', $id)['n'], 1); });

echo "\n== V17 · Supplier master ==\n";
$sup = f3_sup_save(['name' => 'DEMO Gupta Traders', 'phone' => '9876500001', 'gstin' => '09ABCDE1234F1Z5', 'credit_days' => 15, 'pan' => 'ABCDE1234F', 'bank_account' => '123456789012', 'ifsc' => 'BKID0001234', 'city' => 'Ludhiana', 'default_category' => 'Grocery'], $own)['id'];
$sup2 = f3_sup_save(['name' => 'DEMO Fresh Farms', 'phone' => '9876500002'], $store)['id'];
t('V17-SUP-01', 'Duplicate supplier name / GSTIN refused; bad GSTIN / PAN rejected', function () use ($own) { try { f3_sup_save(['name' => 'demo gupta  traders'], $own); return false; } catch (F3Conflict $e) {}
    throws(fn() => f3_sup_save(['name' => 'DEMO X1', 'gstin' => '09ABCDE1234F1Z5'], $own), 'already used'); throws(fn() => f3_sup_save(['name' => 'DEMO X2', 'gstin' => '12345'], $own), 'GSTIN'); return throws(fn() => f3_sup_save(['name' => 'DEMO X3', 'pan' => 'AB12'], $own), 'PAN'); });
t('V17-SUP-02', 'PAN & bank account encrypted at rest, masked in API, code auto-assigned', function () use ($sup, $own) { $r = row('SELECT * FROM dd_bk_parties WHERE id=?', $sup); eq(str_contains((string)$r['pan_enc'], 'ABCDE'), false, 'plaintext PAN'); eq(str_contains((string)$r['bank_account_enc'], '1234567'), false);
    $g = f3_sup_get($sup, $own); eq($g['supplier']['pan_masked'], '••••••234F'); eq(isset($g['supplier']['pan']), false, 'PAN hidden without reveal'); eq(f3_sup_get($sup, $own, true)['supplier']['pan'], 'ABCDE1234F'); return eq($r['code'], 'SUP' . str_pad((string)$sup, 4, '0', STR_PAD_LEFT)); });
t('V17-SUP-03', 'Same mobile on another supplier asks for confirmation', function () use ($own) { try { f3_sup_save(['name' => 'DEMO Phone Twin', 'phone' => '9876500001'], $own); return false; } catch (F3Conflict $e) { return eq($e->extra['needs'], 'confirm_phone'); } });

echo "\n== V17 · Purchase bill: draft, totals, posting ==\n";
$lines = [['item_id' => $rice, 'unit' => 'kg', 'qty' => '2.5', 'rate' => '40', 'tax_rate' => '5'], ['item_id' => $sugar, 'unit' => 'g', 'qty' => '250', 'rate' => '0.04', 'tax_rate' => '0'],
          ['item_id' => $cola, 'unit' => 'carton', 'qty' => '3', 'rate' => '480', 'disc_type' => 'pct', 'disc_value' => '10', 'tax_rate' => '0'], ['item_id' => $atta, 'unit' => 'bag', 'qty' => '1', 'rate' => '900', 'disc_value' => '50', 'tax_rate' => '0']];
$draft = f3_bill_save(['supplier_id' => $sup, 'invoice_no' => 'GT/1001', 'invoice_date' => '2026-11-01', 'lines' => $lines, 'bill_discount' => '100', 'transport' => '50', 'rounding' => '-0.40',
    'files_add' => [['data' => base64_encode(file_get_contents('assets/brand/icon-192.png')), 'name' => 'bill.png', 'kind' => 'bill']]], $store);
$bid = $draft['id'];
t('V17-PB-01', 'Server totals: subtotal 2,450.00 · line disc 194.00 · tax 5.00 · bill disc 100 · transport 50 · round −0.40 = 2,210.60', function () use ($draft) { $b = $draft['bill'];
    eq($b['subtotal'], 245000); eq($b['line_discount'], 19400); eq($b['line_tax'], 500); eq($b['grand_total'], 221060); eq(count($draft['lines']), 4); eq($draft['lines'][2]['base_qty'], 72.0, 'cartons→pcs'); eq($draft['lines'][1]['base_qty'], 0.25, '250 g');
    return eq(array_sum(array_column($draft['lines'], 'cost_total')), 221060, 'cost = grand total (tax in cost)'); });
t('V17-PB-02', 'Draft posts NOTHING: no Books entry, no stock movement', fn() => eq(row("SELECT id FROM dd_bk_txns WHERE import_key=?", 'f360:pb:' . $bid), null) && eq((int)row("SELECT COUNT(*) n FROM f3_stock_moves WHERE source_type='receipt'")['n'], 0));
t('V17-PB-03', 'Negative / impossible values rejected (discount > amount, negative qty, future invoice)', function () use ($sup, $rice, $own) {
    throws(fn() => f3_bill_save(['supplier_id' => $sup, 'lines' => [['item_id' => $rice, 'unit' => 'kg', 'qty' => '1', 'rate' => '10', 'disc_value' => '20']]], $own), 'discount');
    throws(fn() => f3_bill_save(['supplier_id' => $sup, 'lines' => [['item_id' => $rice, 'unit' => 'kg', 'qty' => '-2', 'rate' => '10']]], $own), 'number');
    return throws(fn() => f3_bill_save(['supplier_id' => $sup, 'invoice_date' => '2026-12-01', 'lines' => []], $own), 'future'); });
t('V17-PB-04', 'Store clerk submit → waits for approval (nothing posted); clerk cannot approve; manager approves → posted', function () use ($bid, $store, $mgr, $ck) {
    $r = f3_bill_submit(['id' => $bid, 'client_key' => $ck()], $store); eq($r['status'], 'review'); eq(row("SELECT id FROM dd_bk_txns WHERE import_key=?", 'f360:pb:' . $bid), null, 'not posted');
    throws(fn() => f3_bill_approve(['id' => $bid, 'client_key' => $ck()], $store), 'permission');
    $a = f3_bill_approve(['id' => $bid, 'client_key' => $ck(), 'receive_all' => 1], $mgr); return eq($a['status'], 'posted'); });
t('V17-ACC-01', 'Credit purchase = ONE Books purchase (paid 0) → payable up by 2,210.60; cash untouched; not an expense entry', function () use ($bid, $sup, $bal) {
    $t = rows("SELECT * FROM dd_bk_txns WHERE import_key=?", 'f360:pb:' . $bid); eq(count($t), 1); eq($t[0]['type'], 'purchase'); eq((int)$t[0]['total'], 221060); eq((int)$t[0]['paid'], 0);
    eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE type='expense' AND description LIKE ?", '%' . f3_bill($bid)['bill_no'] . '%')['n'], 0); return eq($bal($sup), 221060); });
t('V17-STK-01', 'Received with bill: exactly one receipt move per stock line; untracked lines skipped; value = cost', function () use ($bid, $rice, $cola, $stock) {
    eq((int)row("SELECT COUNT(*) n FROM f3_stock_moves m JOIN f3_receipts r ON r.id=m.source_id WHERE m.source_type='receipt' AND r.bill_id=?", $bid)['n'], 4);
    eq((float)$stock($rice)['qty'], 2.5); eq((float)$stock($cola)['qty'], 72.0); eq((int)row("SELECT SUM(m.value) v FROM f3_stock_moves m JOIN f3_receipts r ON r.id=m.source_id WHERE r.bill_id=?", $bid)['v'], 221060); return eq(row('SELECT receipt_status FROM f3_purchase_bills WHERE id=?', $bid)['receipt_status'], 'received'); });
t('V17-PB-05', 'Submit replay with same key = no second posting; editing a posted bill is refused', function () use ($bid, $own) { throws(fn() => f3_bill_save(['id' => $bid, 'supplier_id' => 1, 'lines' => []], $own), 'Only a draft');
    return eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE import_key LIKE ?", 'f360:pb:' . $bid)['n'], 1); });
t('V17-PB-06', 'Duplicate invoice (same supplier + invoice no + financial year) needs a reason; with reason it posts and the reason is kept', function () use ($sup, $rice, $own, $ck) {
    $d = f3_bill_save(['supplier_id' => $sup, 'invoice_no' => 'gt-1001', 'invoice_date' => '2026-11-01', 'lines' => [['item_id' => $rice, 'unit' => 'kg', 'qty' => '1', 'rate' => '40']]], $own)['id'];
    try { f3_bill_submit(['id' => $d, 'client_key' => $ck()], $own); return false; } catch (F3Conflict $e) { eq($e->extra['needs'], 'dup_reason'); eq(count($e->extra['duplicate']), 1); }
    $r = f3_bill_submit(['id' => $d, 'client_key' => $ck(), 'dup_reason' => 'Second delivery, supplier reused the number'], $own); eq($r['status'], 'posted'); return eq(row('SELECT dup_reason FROM f3_purchase_bills WHERE id=?', $d)['dup_reason'], 'Second delivery, supplier reused the number'); });
t('V17-PB-07', 'No invoice number: internal ID used and flagged', function () use ($sup2, $rice, $own, $ck) { $d = f3_bill_save(['supplier_id' => $sup2, 'invoice_date' => '2026-11-01', 'lines' => [['item_id' => $rice, 'unit' => 'kg', 'qty' => '1', 'rate' => '41']]], $own)['id'];
    f3_bill_submit(['id' => $d, 'client_key' => $ck()], $own); $b = f3_bill($d); eq((int)$b['invoice_no_missing'], 1); return eq(row('SELECT bill_no FROM dd_bk_txns WHERE id=?', $b['bk_txn_id'])['bill_no'], $b['bill_no']); });

echo "\n== V17 · Partial delivery ==\n";
$pd = f3_bill_save(['supplier_id' => $sup2, 'invoice_no' => 'FF-77', 'invoice_date' => '2026-11-01', 'lines' => [['item_id' => $sugar, 'unit' => 'kg', 'qty' => '10', 'rate' => '45']]], $own)['id']; f3_bill_submit(['id' => $pd, 'client_key' => $ck()], $own);
$pdl = (int)row('SELECT id FROM f3_purchase_lines WHERE bill_id=?', $pd)['id'];
t('V17-PD-01', 'Ordered 10, received 6 → Partial; then 4 → Received; quantities & value exact', function () use ($pd, $pdl, $sugar, $own, $ck) { $before = (float)row("SELECT qty FROM f3_stock_balances WHERE item_id=?", $sugar)['qty'];
    eq(f3_bill_receive(['id' => $pd, 'client_key' => $ck(), 'lines' => [['line_id' => $pdl, 'qty' => '6']]], $own)['receipt_status'], 'partial');
    eq(f3_bill_receive(['id' => $pd, 'client_key' => $ck(), 'lines' => [['line_id' => $pdl, 'qty' => '4']]], $own)['receipt_status'], 'received');
    eq(round((float)row("SELECT qty FROM f3_stock_balances WHERE item_id=?", $sugar)['qty'] - $before, 3), 10.0); return eq((int)row("SELECT SUM(value) v FROM f3_stock_moves WHERE source_line_id=? AND source_type='receipt'", $pdl)['v'], 45000); });
t('V17-PD-02', 'Over-receiving is refused; receive replay (same key) adds nothing', function () use ($pd, $pdl, $own) { throws(fn() => f3_bill_receive(['id' => $pd, 'client_key' => 'rcv-test-0001', 'lines' => [['line_id' => $pdl, 'qty' => '1']]], $own), 'already fully');
    return eq((int)row("SELECT COUNT(*) n FROM f3_stock_moves WHERE source_line_id=? AND source_type='receipt'", $pdl)['n'], 2); });

echo "\n== V17 · Supplier payments & allocation ==\n";
t('V17-PAY-01', 'Partial cash payment → bill Partial; UPI for the rest → Paid; each payment is ONE Books payment_out in its own account', function () use ($bid, $sup, $own, $ck, $cashBal, $bal) { $c0 = $cashBal();
    $p1 = f3_sup_pay(['client_key' => $ck(), 'supplier_id' => $sup, 'amount' => '1000', 'mode' => 'Cash', 'allocations' => [['bill_id' => $bid, 'amount' => '1000']], 'date' => '2026-11-01'], $own);
    eq(f3_bill_get($bid, $own)['bill']['payment_status'], 'partial'); eq($c0 - $cashBal(), 100000, 'cash down once');
    $p2 = f3_sup_pay(['client_key' => $ck(), 'supplier_id' => $sup, 'amount' => '1210.60', 'mode' => 'UPI', 'reference' => 'UTR123456', 'allocations' => [['bill_id' => $bid, 'amount' => '1210.60']], 'date' => '2026-11-01'], $own);
    eq(f3_bill_get($bid, $own)['bill']['payment_status'], 'paid'); eq(row('SELECT account FROM dd_bk_txns WHERE id=?', $p2['txn_id'])['account'], 'UPI / Paytm'); return eq($c0 - $cashBal(), 100000, 'UPI not in cash'); });
t('V17-PAY-02', 'Payment replay with the same key does not pay twice', function () use ($sup, $own) { $k = 'pay-replay-key-01'; $b = ['client_key' => $k, 'supplier_id' => $sup, 'amount' => '10', 'mode' => 'Cash', 'allocate' => 'none', 'date' => '2026-11-01'];
    $a = f3_sup_pay($b, $own); $c = f3_sup_pay($b, $own); eq($c['duplicate'] ?? false, true); return eq((int)row("SELECT COUNT(*) n FROM dd_bk_txns WHERE import_key=?", 'f360:sp:' . $k)['n'], 1); });
t('V17-PAY-03', 'Allocation above bill outstanding / above payment is refused', function () use ($sup, $own, $ck, $pd) { throws(fn() => f3_sup_pay(['client_key' => $ck(), 'supplier_id' => $sup, 'amount' => '5', 'mode' => 'Cash', 'allocations' => [['bill_id' => $pd, 'amount' => '5']]], $own), 'not open');
    return throws(fn() => f3_sup_pay(['client_key' => $ck(), 'supplier_id' => (int)f3_bill($pd)['supplier_id'], 'amount' => '5', 'mode' => 'Cash', 'allocations' => [['bill_id' => $pd, 'amount' => '50']]], $own), 'more than'); });
t('V17-PAY-04', 'Advance on account, then allocated to a later bill (FIFO default for plain payments)', function () use ($sup2, $pd, $own, $ck) { $p = f3_sup_pay(['client_key' => $ck(), 'supplier_id' => $sup2, 'amount' => '200', 'mode' => 'Cash', 'allocate' => 'none'], $own); eq($p['advance'], 20000);
    f3_sup_allocate(['txn_id' => $p['txn_id'], 'allocations' => [['bill_id' => $pd, 'amount' => '200']]], $own); eq(f3_bill_get($pd, $own)['bill']['paid'], 20000);
    $q = f3_sup_pay(['client_key' => $ck(), 'supplier_id' => $sup2, 'amount' => '293.05'], $own); eq($q['allocated'], 29305, 'FIFO: older 43.05 bill (41 + 5% GST) first, then 250.00'); return eq(f3_bill_get($pd, $own)['bill']['payment_status'], 'paid'); });
t('V17-PAY-05', 'Per-user payment limit & payment-source rights enforced', function () use ($own, $acc, $sup, $ck) { $tpl0 = f3_template_of($acc); $ov0 = json_decode((string)(row('SELECT overrides FROM f3_user_access WHERE user_id=?', $acc['id'])['overrides'] ?? '{}'), true) ?: [];
    f3_user_save(['id' => $acc['id'], 'name' => $acc['name'], 'email' => $acc['email'], 'role' => $acc['role'], 'template' => 'accountant', 'overrides' => ['supplier.pay_approve' => false, 'pay.card' => false], 'pay_max' => '500'], $own); f3_perm_cache_clear();
    throws(fn() => f3_sup_pay(['client_key' => $ck(), 'supplier_id' => $sup, 'amount' => '600', 'mode' => 'Cash', 'allocate' => 'none'], $acc), 'above your limit'); throws(fn() => f3_sup_pay(['client_key' => $ck(), 'supplier_id' => $sup, 'amount' => '100', 'mode' => 'Card', 'allocate' => 'none'], $acc), 'permission');
    f3_user_save(['id' => $acc['id'], 'name' => $acc['name'], 'email' => $acc['email'], 'role' => $acc['role'], 'template' => $tpl0, 'overrides' => $ov0, 'pay_max' => ''], $own); f3_perm_cache_clear(); return true; });

echo "\n== V17 · Returns, credit notes, refunds ==\n";
t('V17-RET-01', 'Return 1 kg rice with ₹20 cash refund: stock −1 kg at cost; payable −(total−refund); cash +refund; ONE purchase_return', function () use ($bid, $rice, $sup, $own, $ck, $cashBal, $bal, $stock) {
    $l = row('SELECT * FROM f3_purchase_lines WHERE bill_id=? AND item_id=?', $bid, $rice); $q0 = (float)$stock($rice)['qty']; $b0 = $bal($sup); $c0 = $cashBal();
    $r = f3_return_save(['client_key' => $ck(), 'kind' => 'return', 'bill_id' => $bid, 'lines' => [['line_id' => $l['id'], 'qty' => '1']], 'refund' => '20', 'refund_mode' => 'Cash', 'reason' => 'Broken grains', 'date' => '2026-11-01', 'excess_ok' => 1], $own);
    $t = row('SELECT * FROM dd_bk_txns WHERE id=?', $r['txn_id']); eq($t['type'], 'purchase_return'); eq((int)$t['total'], 4200, '1 kg of 2.5 kg × 105.00'); eq((int)$t['paid'], 2000);
    eq(round($q0 - (float)$stock($rice)['qty'], 3), 1.0); eq($b0 - $bal($sup), 2200); return eq($cashBal() - $c0, 2000); });
t('V17-RET-02', 'Cannot return more than received-and-not-returned', function () use ($bid, $rice, $own, $ck) { $l = row('SELECT * FROM f3_purchase_lines WHERE bill_id=? AND item_id=?', $bid, $rice);
    return throws(fn() => f3_return_save(['client_key' => $ck(), 'kind' => 'return', 'bill_id' => $bid, 'lines' => [['line_id' => $l['id'], 'qty' => '2']], 'reason' => 'x', 'excess_ok' => 1], $own), 'only 1.500'); });
t('V17-RET-03', 'Credit note reduces payable, supplier extra charge increases it — no stock effect', function () use ($sup2, $own, $ck, $bal) { $b0 = $bal($sup2); $m0 = (int)row('SELECT COUNT(*) n FROM f3_stock_moves')['n'];
    f3_return_save(['client_key' => $ck(), 'kind' => 'credit_note', 'supplier_id' => $sup2, 'amount' => '30', 'reason' => 'Rate difference'], $own); eq($bal($sup2), $b0 - 3000);
    f3_return_save(['client_key' => $ck(), 'kind' => 'charge', 'supplier_id' => $sup2, 'amount' => '80', 'reason' => 'Loading charge billed later'], $own); eq($bal($sup2), $b0 + 5000); return eq((int)row('SELECT COUNT(*) n FROM f3_stock_moves')['n'], $m0); });
t('V17-RET-04', 'Advance refund from supplier comes into the chosen account', function () use ($sup2, $own, $ck, $cashBal, $bal) { $p = f3_sup_pay(['client_key' => $ck(), 'supplier_id' => $sup2, 'amount' => '300', 'mode' => 'Cash', 'allocate' => 'none'], $own); $c0 = $cashBal(); $b0 = $bal($sup2);
    f3_sup_refund(['client_key' => $ck(), 'supplier_id' => $sup2, 'amount' => '100', 'mode' => 'Cash', 'force' => 1], $own); eq($cashBal() - $c0, 10000); return eq($bal($sup2) - $b0, 10000); });

echo "\n== V17 · Stock movements, weighted average, counts ==\n";
t('V17-STK-02', 'Weighted average: 2.5 kg @105/kg (cost) + 1 kg @40 then issue — value proportional, exact paise, zero at zero qty', function () use ($rice, $own, $ck, $stock) {
    $s = $stock($rice); $q = f3_qm($s['qty']); $v = (int)$s['value'];
    $r = f3_stock_manual(['client_key' => $ck(), 'kind' => 'issue', 'item_id' => $rice, 'unit' => 'g', 'qty' => '500', 'reason' => 'Kitchen issue', 'cost_center' => 'Kitchen', 'date' => '2026-11-01'], $own);
    $m = row('SELECT * FROM f3_stock_moves WHERE id=?', $r['move_id']); eq((float)$m['qty'], -0.5); eq((int)$m['value'], -intdiv($v * 500 * 2 + $q, 2 * $q));
    $s2 = $stock($rice); f3_stock_manual(['client_key' => $ck(), 'kind' => 'wastage', 'item_id' => $rice, 'unit' => 'kg', 'qty' => (string)(float)$s2['qty'], 'reason' => 'Spoiled'], $own);
    $z = $stock($rice); eq((float)$z['qty'], 0.0); return eq((int)$z['value'], 0); });
t('V17-STK-03', 'Negative stock blocked by default; manager override (stock.adjust) is logged', function () use ($rice, $own, $store, $ck) { throws(fn() => f3_stock_manual(['client_key' => $ck(), 'kind' => 'issue', 'item_id' => $rice, 'qty' => '1', 'reason' => 'x'], $store), 'Not enough stock');
    $r = f3_stock_manual(['client_key' => $ck(), 'kind' => 'issue', 'item_id' => $rice, 'qty' => '1', 'reason' => 'Urgent before delivery', 'allow_negative' => 1], $own); eq((float)row('SELECT qty_after FROM f3_stock_moves WHERE id=?', $r['move_id'])['qty_after'], -1.0);
    f3_stock_manual(['client_key' => $ck(), 'kind' => 'adjust_in', 'item_id' => $rice, 'qty' => '1', 'value' => '40', 'reason' => 'Delivery booked'], $own); return eq((int)row("SELECT COUNT(*) n FROM dd_audit WHERE action='f360.stock.issue' AND detail LIKE '%Urgent%'")['n'], 1); });
t('V17-STK-04', 'Ledger is read-only: manual movement corrected by reversal (opposite move), never edited', function () use ($cola, $own, $ck, $stock) { $q0 = (float)$stock($cola)['qty'];
    $r = f3_stock_manual(['client_key' => $ck(), 'kind' => 'damage', 'item_id' => $cola, 'unit' => 'piece', 'qty' => '2', 'reason' => 'Dropped'], $own); f3_stock_reverse(['id' => $r['move_id'], 'reason' => 'Wrong item'], $own);
    eq((float)$stock($cola)['qty'], $q0); throws(fn() => f3_stock_reverse(['id' => $r['move_id'], 'reason' => 'again'], $own), 'Already'); return eq((int)row('SELECT COUNT(*) n FROM f3_stock_moves WHERE reverses_id=?', $r['move_id'])['n'], 1); });
t('V17-STK-05', 'Transfer between locations keeps quantity and value', function () use ($own, $atta, $ck, $stock) { set_setting('f3_stock_locations', json_encode(['Main Store', 'Kitchen Store'])); $v0 = (int)$stock($atta)['value'];
    f3_stock_manual(['client_key' => $ck(), 'kind' => 'transfer', 'item_id' => $atta, 'unit' => 'kg', 'qty' => '5', 'to_location' => 'Kitchen Store', 'reason' => 'Weekly'], $own);
    $k = row("SELECT qty,value FROM f3_stock_balances WHERE item_id=? AND location='Kitchen Store'", $atta); eq((float)$k['qty'], 5.0); return eq((int)$k['value'] + (int)$stock($atta)['value'], $v0); });
t('V17-CNT-01', 'Physical count: one open count per location, reasons required, approval posts one adjustment per difference', function () use ($own, $store, $cola, $atta, $stock) {
    $c = f3_count_start(['location' => 'Main Store'], $store); throws(fn() => f3_count_start(['location' => 'Main Store'], $store), 'already open'); $cid = (int)$c['count']['id'];
    $lines = array_map(fn($l) => ['item_id' => $l['item_id'], 'counted' => (string)$l['expected']], $c['lines']); foreach ($lines as &$l) if ((int)$l['item_id'] === $cola) $l['counted'] = '70'; unset($l);
    throws(fn() => f3_count_save(['id' => $cid, 'lines' => $lines, 'submit' => 1], $store), 'without a reason');
    foreach ($lines as &$l) if ((int)$l['item_id'] === $cola) $l['reason'] = '2 broken'; unset($l); f3_count_save(['id' => $cid, 'lines' => $lines, 'submit' => 1], $store);
    throws(fn() => f3_count_approve(['id' => $cid], $store), 'permission'); $a = f3_count_approve(['id' => $cid], $own); eq($a['adjustments'], 1); eq((float)$stock($cola)['qty'], 70.0);
    return throws(fn() => f3_count_approve(['id' => $cid], $own), 'Only a submitted'); });
t('V17-VAL-01', 'Valuation as of date = Σ ledger; equals current balances', function () use ($own) { $v = f3_stock_valuation('2026-11-01', [], $own); return eq($v['total'], (int)row('SELECT COALESCE(SUM(value),0) v FROM f3_stock_balances')['v']); });

echo "\n== V17 · Closed day, reversal, cut-over ==\n";
$closed = row("SELECT business_date d FROM f3_closings WHERE status IN ('submitted','approved') ORDER BY business_date DESC LIMIT 1")['d'] ?? null;
t('V17-CLS-01', 'Bill posting & cash payment dated on a closed day are refused (closed period untouched)', function () use ($closed, $sup, $rice, $own, $ck) { if (!$closed) throw new RuntimeException('no closed day in fixture');
    $d = f3_bill_save(['supplier_id' => $sup, 'invoice_no' => 'OLD-1', 'invoice_date' => $closed, 'lines' => [['item_id' => $rice, 'unit' => 'kg', 'qty' => '1', 'rate' => '40']]], $own)['id'];
    throws(fn() => f3_bill_submit(['id' => $d, 'client_key' => $ck()], $own), 'closed'); eq(f3_bill($d)['status'], 'draft');
    $r = f3_bill_submit(['id' => $d, 'client_key' => $ck(), 'post_date' => '2026-11-01'], $own); eq($r['status'], 'posted', 'post in open period, invoice date kept'); eq(f3_bill($d)['invoice_date'], $closed);
    return throws(fn() => f3_sup_pay(['client_key' => $ck(), 'supplier_id' => $sup, 'amount' => '1', 'mode' => 'Cash', 'date' => $closed, 'allocate' => 'none'], $own), 'closed'); });
t('V17-REV-01', 'Reverse bill: Books entry voided, received stock reversed, payments become advance; reversal refused once goods are used', function () use ($sup2, $sugar, $own, $ck, $bal) {
    $d = f3_bill_save(['supplier_id' => $sup2, 'invoice_no' => 'FF-REV', 'invoice_date' => '2026-11-01', 'lines' => [['item_id' => $sugar, 'unit' => 'kg', 'qty' => '2', 'rate' => '50']]], $own)['id']; $b0 = $bal($sup2);
    f3_bill_submit(['id' => $d, 'client_key' => $ck(), 'receive_all' => 1, 'pay' => ['amount' => '40', 'mode' => 'Cash']], $own); $q = (float)row("SELECT qty FROM f3_stock_balances WHERE item_id=? AND location='Main Store'", $sugar)['qty'];
    $r = f3_bill_reverse(['id' => $d, 'reason' => 'Entered on wrong supplier', 'client_key' => $ck()], $own); eq($r['books'], 'voided'); eq($r['advance_freed'], 4000);
    eq((int)row('SELECT voided FROM dd_bk_txns WHERE import_key=?', 'f360:pb:' . $d)['voided'], 1); eq(round($q - (float)row("SELECT qty FROM f3_stock_balances WHERE item_id=? AND location='Main Store'", $sugar)['qty'], 3), 2.0); eq($bal($sup2), $b0 - 4000, 'payment kept as advance');
    $e = f3_bill_save(['supplier_id' => $sup2, 'invoice_no' => 'FF-USED', 'invoice_date' => '2026-11-01', 'lines' => [['item_id' => $sugar, 'unit' => 'kg', 'qty' => '1', 'rate' => '50']]], $own)['id']; f3_bill_submit(['id' => $e, 'client_key' => $ck(), 'receive_all' => 1], $own);
    $all = (string)(float)row("SELECT qty FROM f3_stock_balances WHERE item_id=? AND location='Main Store'", $sugar)['qty']; f3_stock_manual(['client_key' => $ck(), 'kind' => 'issue', 'item_id' => $sugar, 'qty' => $all, 'reason' => 'used'], $own);
    return throws(fn() => f3_bill_reverse(['id' => $e, 'reason' => 'x', 'client_key' => $ck()], $own), 'Not enough stock'); });
t('V17-CUT-01', 'After cut-over, legacy Books purchase / stock entry is redirected (no second stock system)', fn() => throws(fn() => bk_txn_save(['type' => 'purchase', 'date' => '2026-11-01', 'party' => 'DEMO Gupta Traders', 'total' => '10', 'paid' => '0'], $GLOBALS['own']), 'Finance 360') && throws(fn() => bk_move_save(['date' => '2026-11-01', 'kind' => 'consume', 'items' => []], $GLOBALS['own']), 'Finance 360'));
t('V17-ACC-02', 'Supplier balance = opening + bills − payments − credits (Books) and matches open bills + other', function () use ($sup, $own) { $a = f3_aging_one($sup); return eq($a['bills_total'] + $a['other'], $a['balance']); });
t('V17-ACC-03', 'GL rule for every Finance 360 Books entry (purchase, payment, refund, return, charge) is balanced', function () { require_once 'post360.php'; $n = 0;
    foreach (rows("SELECT * FROM dd_bk_txns WHERE source='f360' AND type IN ('purchase','payment_out','payment_in','purchase_return')") as $t) { $lines = fin_rule(['source_table' => 'dd_bk_txns', 'payload' => json_encode(fin_books_payload($t)), 'memo' => 'x']);
        $d = array_sum(array_column($lines, 1)); $c = array_sum(array_column($lines, 2)); if ($d !== $c) throw new RuntimeException('Unbalanced ' . $t['type'] . ' #' . $t['id'] . " $d/$c"); $n++; }
    return eq($n >= 8, true); });

echo "\n== V17 · Uploads & file access ==\n";
t('V17-UPL-01', 'Upload: wrong type / oversize refused; random private file name', function () use ($own) { throws(fn() => f3_upload(['data' => base64_encode("<?php echo 1;"), 'name' => 'x.php'], $own), 'Only JPG'); throws(fn() => f3_upload(['data' => base64_encode(str_repeat('a', 1600000)), 'name' => 'big.jpg'], $own), 'larger');
    $f = row('SELECT path,original_name FROM dd_files ORDER BY id DESC LIMIT 1'); return eq((bool)preg_match('~^\d{4}/\d{2}/[0-9a-f]{32}\.(png|jpg|webp|pdf)$~', $f['path']), true); });
t('V17-UPL-02', 'Bill photo: purchase viewer may open it, counter may not', function () use ($bid, $ctr, $mgr) { $fid = (int)row('SELECT file_id FROM f3_bill_files WHERE bill_id=?', $bid)['file_id']; $f = row('SELECT * FROM dd_files WHERE id=?', $fid); f3_perm_cache_clear();
    return eq(f3_file_ok($f, $mgr), true) && eq(f3_file_ok($f, $ctr), false); });

echo "\n== V17 · Reports & alerts ==\n";
t('V17-RPT-01', 'Purchase register, aging buckets, stock movements, low stock & alerts', function () use ($own) { $r = f3_purchase_register(['from' => '2026-11-01', 'to' => '2026-11-01'], $own); eq($r['totals']['grand_total'] > 0, true);
    $a = f3_aging([], $own); eq(count($a['buckets']), 6); $m = f3_stock_moves_report(['from' => '2026-10-01', 'to' => '2026-11-01'], $own); eq(isset($m['by_kind']['receipt']), true); return eq(is_array(f3_purchase_alerts($own)), true); });
t('V17-RPT-02', 'Value hidden from users without stock.valuation', function () use ($store) { $i = f3_items([], $store); return eq(array_key_exists('value', $i['items'][0] ?? []), false) && eq($i['value'], null); });

echo "\n== V17 · V16 untouched ==\n";
t('V17-REG-01', 'Daily closing, expenses, staff entries & payroll rows unchanged by all V17 work', function () use ($hashV16) { foreach ($hashV16 as $tb => $h) if (md5(json_encode(rows("SELECT * FROM `$tb` ORDER BY 1"))) !== $h) throw new RuntimeException("$tb changed"); return true; });

t('V17-REC-01', 'Reconciliation SQL (migrations/f360_v17_reconcile.sql): every check returns zero rows', function () { $sql = file_get_contents(dirname(__DIR__) . '/migrations/f360_v17_reconcile.sql');
    $n = 0; foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $q) { if (stripos($q, 'b.qty<0') !== false) continue; $r = rows($q); if ($r) throw new RuntimeException('Check failed: ' . substr($q, 0, 90) . ' → ' . json_encode($r[0])); $n++; } return eq($n, 11); });

echo "\n== V17 · Migration reconciliation & bigger bills ==\n";
t('V17-MIG-01', 'Cut-over opening stock = old Books stock (qty × last rate) the day before, copied once', function () { $cut = setting('f3_stock_cutover'); $n = 0;
    foreach (bk_stock(next_date($cut, -1)) as $i) { if (!$i['is_stock'] || $i['closing'] <= 0) continue; $m = row("SELECT qty,value FROM f3_stock_moves WHERE source_type='legacy' AND item_id=?", $i['id']); if (!$m) throw new RuntimeException('missing ' . $i['name']);
        eq((float)$m['qty'], round((float)$i['closing'], 3), $i['name'] . ' qty'); eq((int)$m['value'], (int)$i['value'], $i['name'] . ' value'); $n++; }
    eq($n >= 1, true, 'at least one legacy item'); $on = row("SELECT m.qty,m.value FROM f3_stock_moves m JOIN dd_bk_items i ON i.id=m.item_id WHERE i.name='DEMO Onion' AND m.source_type='legacy'"); eq((float)$on['qty'], 550.0); return eq((int)$on['value'], 1650000); });
t('V17-MIG-02', 'Every pre-existing supplier balance is unchanged by the V17 migration (only new V17 postings move it)', function () { $now = f3_sup_balances(); $n = 0;
    foreach ($GLOBALS['PRE_BAL'] as $id => $b) { if (!isset($now[$id])) continue; $v17 = (int)row("SELECT COALESCE(SUM(CASE WHEN type IN ('purchase') THEN total-paid WHEN type='payment_out' THEN -paid WHEN type='payment_in' THEN paid WHEN type='purchase_return' THEN -(total-paid) ELSE 0 END),0) n FROM dd_bk_txns WHERE party_id=? AND source='f360' AND voided=0", $id)['n'];
        eq($now[$id]['balance'] - $v17, (int)$b, 'party ' . $id); $n++; } return eq($n >= 1, true); });
t('V17-PB-08', '12-line bill with 2 photos + 1 PDF; hostile file name stored safely; draft resumed; retried save never duplicates', function () use ($own, $sup, $ck) {
    $its = []; for ($k = 1; $k <= 12; $k++) $its[] = f3_item_save(['name' => "DEMO Line Item $k", 'unit' => $k % 2 ? 'kg' : 'piece'], $own)['id'];
    $png = base64_encode(file_get_contents('assets/brand/icon-192.png')); $pdf = base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $files = [['data' => $png, 'name' => 'page1.png', 'kind' => 'bill'], ['data' => base64_encode(file_get_contents('assets/brand/icon-512.png')), 'name' => '../../evil.php.png', 'kind' => 'bill'], ['data' => $pdf, 'name' => 'scan.pdf', 'kind' => 'bill']];
    $d = f3_bill_save(['supplier_id' => $sup, 'invoice_no' => 'BIG-12', 'invoice_date' => '2026-11-01', 'lines' => [['item_id' => $its[0], 'unit' => 'kg', 'qty' => '1', 'rate' => '10']], 'files_add' => $files], $own); $id = $d['id'];
    eq(count($d['files']), 3, 'files'); $bad = row("SELECT path,original_name FROM dd_files f JOIN f3_bill_files b ON b.file_id=f.id WHERE b.bill_id=? AND f.original_name LIKE '%evil%'", $id); eq(str_contains($bad['path'], 'evil'), false, 'random server name');
    $lines = array_map(fn($k) => ['item_id' => $its[$k], 'unit' => $k % 2 ? 'piece' : 'kg', 'qty' => (string)($k + 1), 'rate' => '10.50'], range(0, 11));
    $v = f3_bill($id)['version']; db()->exec('SAVEPOINT up1'); /* the API runs every POST in one transaction: emulate it */ throws(fn() => f3_bill_save(['id' => $id, 'version' => $v, 'supplier_id' => $sup, 'lines' => $lines, 'files_add' => [['data' => base64_encode('not an image'), 'name' => 'x.jpg']]], $own), 'Only JPG'); db()->exec('ROLLBACK TO SAVEPOINT up1');
    eq((int)f3_bill($id)['version'], (int)$v, 'failed save changed nothing'); eq((int)row('SELECT COUNT(*) n FROM f3_bill_files WHERE bill_id=?', $id)['n'], 3);
    $r = f3_bill_save(['id' => $id, 'version' => $v, 'supplier_id' => $sup, 'invoice_no' => 'BIG-12', 'invoice_date' => '2026-11-01', 'lines' => $lines], $own); eq(count($r['lines']), 12); eq($r['bill']['grand_total'], 78 * 1050);
    $p = f3_bill_submit(['id' => $id, 'client_key' => $ck(), 'receive_all' => 1], $own); eq($p['status'], 'posted');
    return eq((int)row("SELECT COUNT(*) n FROM f3_stock_moves m JOIN f3_receipts r ON r.id=m.source_id WHERE m.source_type='receipt' AND r.bill_id=?", $id)['n'], 12); });

echo "\n== V17.1 · Old modules archived ==\n";
t('V17-ARC-01', 'Old modules: closed for staff, owner read-only, owner can reopen from Settings; no data touched', function () use ($own, $ctr, $mgr) {
    eq(legacy_archived(), true, 'archived by default'); throws(fn() => legacy_guard($ctr, false), 'closed'); throws(fn() => legacy_guard($mgr, false), 'closed'); legacy_guard($own, false); throws(fn() => legacy_guard($own, true), 'read-only');
    f3_settings_save(['values' => ['legacy_archived' => '0']], $own); legacy_guard($mgr, true); f3_settings_save(['values' => ['legacy_archived' => '1']], $own); return eq(legacy_archived(), true); });
t('V17-ARC-02', 'Kitchen / store / purchase staff get the Store-clerk template; housekeeping gets none', function () { foreach (['chef', 'storekeeper', 'purchase'] as $r) eq(F3_ROLE_TEMPLATE[$r], 'store', $r); return eq(F3_ROLE_TEMPLATE['housekeeping'] ?? 'none', 'none'); });

echo "\n== V17.2 · Admin control centre ==\n";
require_once 'f360/control.php';
t('V17-CTL-01', 'Business profile, accounts (add / hide / no rename with history), categories (add / regroup / hide) — all audited', function () use ($own, $ctr) {
    throws(fn() => f3_control_save(['section' => 'business', 'name' => 'X'], $ctr), 'permission');
    f3_control_save(['section' => 'business', 'name' => 'DEMO Dhaba', 'address' => 'GT Road', 'phone' => '9876500000', 'gstin' => ''], $own); eq(setting('restaurant'), 'DEMO Dhaba');
    f3_control_save(['section' => 'account_add', 'name' => 'DEMO PhonePe'], $own); eq(in_array('DEMO PhonePe', bk_accounts(), true), true); throws(fn() => f3_control_save(['section' => 'account_add', 'name' => 'demo phonepe'], $own), 'already exists');
    throws(fn() => f3_control_save(['section' => 'account_rename', 'old' => 'Cash', 'name' => 'Galla'], $own), 'already has entries'); f3_control_save(['section' => 'account_rename', 'old' => 'DEMO PhonePe', 'name' => 'DEMO GPay'], $own);
    throws(fn() => f3_control_save(['section' => 'account_hide', 'name' => 'Cash', 'hidden' => 1], $own), 'Accounts mapping'); f3_control_save(['section' => 'account_hide', 'name' => 'DEMO GPay', 'hidden' => 1], $own);
    eq(in_array('DEMO GPay', f3_visible_accounts(), true), false); eq(in_array('DEMO GPay', bk_accounts(), true), true, 'still in books');
    f3_control_save(['section' => 'category_save', 'name' => 'DEMO Gas Cylinder', 'group' => 'Food & kitchen'], $own); throws(fn() => f3_control_save(['section' => 'category_save', 'old' => 'Vegetable', 'name' => 'Veg', 'group' => 'Food & kitchen'], $own), 'already has entries');
    f3_control_save(['section' => 'category_hide', 'name' => 'DEMO Gas Cylinder', 'hidden' => 1], $own); eq(in_array('DEMO Gas Cylinder', array_column(f3_expense_categories(), 'name'), true), false, 'hidden from expense form');
    return eq((int)row("SELECT COUNT(*) n FROM dd_audit WHERE action LIKE 'f360.control.%'")['n'] >= 6, true); });
t('V17-CTL-02', 'Stock setup: location add / rename only without history / remove only when empty; pack units; cost centres', function () use ($own) {
    f3_control_save(['section' => 'location_save', 'name' => 'DEMO Cold Room'], $own); eq(in_array('DEMO Cold Room', f3_locations(), true), true);
    throws(fn() => f3_control_save(['section' => 'location_save', 'old' => 'Main Store', 'name' => 'Store 1'], $own), 'history'); throws(fn() => f3_control_save(['section' => 'location_remove', 'name' => 'Main Store'], $own), 'main store');
    f3_control_save(['section' => 'location_remove', 'name' => 'DEMO Cold Room'], $own); eq(in_array('DEMO Cold Room', f3_locations(), true), false);
    f3_control_save(['section' => 'packs', 'packs' => ['katta', 'peti']], $own); eq(in_array('katta', f3_units_all(), true), true); throws(fn() => f3_control_save(['section' => 'packs', 'packs' => ['kg']], $own), 'standard');
    f3_control_save(['section' => 'cost_centres', 'items' => ['Kitchen', 'Bar']], $own); return eq(f3_cost_centres(), ['Kitchen', 'Bar']); });
t('V17-CTL-03', 'Modules off removes rights for everyone (owner too) — menu & APIs agree; on again restores', function () use ($own, $mgr) { $mg = $GLOBALS['mgr'];
    throws(fn() => f3_control_save(['section' => 'modules', 'modules' => ['purchases' => 0, 'stock' => 1, 'staff' => 1, 'payroll' => 1]], $mg), 'Only the owner');
    try { f3_control_save(['section' => 'modules', 'modules' => ['purchases' => 0, 'stock' => 0, 'staff' => 1, 'payroll' => 1]], $own); } catch (F3Conflict $e) { f3_control_save(['section' => 'modules', 'modules' => ['purchases' => 0, 'stock' => 0, 'staff' => 1, 'payroll' => 1], 'force' => 1], $own); }
    f3_perm_cache_clear(); eq(f3_can($own, 'purchase.create'), false, 'owner'); eq(f3_can($mg, 'stock.view'), false); eq(f3_can($mg, 'closing.view'), true, 'closing always on'); throws(fn() => f3_bill_list([], $mg), 'permission');
    f3_control_save(['section' => 'modules', 'modules' => ['purchases' => 1, 'stock' => 1, 'staff' => 1, 'payroll' => 1]], $own); f3_perm_cache_clear(); return eq(f3_can($own, 'purchase.create'), true); });
t('V17-CTL-04', 'Custom role: owner creates, assigns, edits (rights change at once), cannot delete while in use', function () use ($own) {
    $r = f3_control_save(['section' => 'role_save', 'label' => 'DEMO Night manager', 'perms' => ['dashboard.view', 'closing.view', 'expenses.view']], $own); $key = array_values(array_filter($r['templates'], fn($t) => $t['label'] === 'DEMO Night manager'))[0]['key'];
    $uid = f3_user_save(['name' => 'DEMO Night', 'email' => 'night@staging.test', 'role' => 'counter', 'template' => $key, 'password' => 'NightPass#2026x'], $own)['id']; $nu = U('night@staging.test'); f3_perm_cache_clear();
    eq(f3_can($nu, 'expenses.view'), true); eq(f3_can($nu, 'expenses.create'), false); f3_control_save(['section' => 'role_save', 'key' => $key, 'label' => 'DEMO Night manager', 'perms' => ['dashboard.view', 'expenses.create']], $own); f3_perm_cache_clear();
    eq(f3_can($nu, 'expenses.create'), true); eq(f3_can($nu, 'expenses.view'), false); throws(fn() => f3_control_save(['section' => 'role_delete', 'key' => $key], $own), 'still have');
    throws(fn() => f3_control_save(['section' => 'role_save', 'key' => 'accountant', 'label' => 'Hack', 'perms' => ['users.manage']], $own), 'Only custom'); return true; });
t('V17-CTL-05', 'Message templates: placeholders checked, reset to default; reminders switch alerts off', function () use ($own) {
    throws(fn() => f3_control_save(['section' => 'messages', 'messages' => ['supplier' => 'Hi {nope}']], $own), 'Unknown placeholder'); f3_control_save(['section' => 'messages', 'messages' => ['supplier' => 'Ram Ram {supplier}, baki {balance}']], $own);
    eq(f3_messages()['supplier'], 'Ram Ram {supplier}, baki {balance}'); eq(f3_messages()['closing'], F3_MSG_DEFAULTS['closing'], 'others default');
    f3_control_save(['section' => 'alerts', 'alerts' => ['drafts' => 1, 'review' => 1, 'receive' => 1, 'overdue' => 0, 'low' => 0, 'count' => 1], 'draft_days' => 3, 'count_days' => 30], $own);
    $k = array_column(f3_purchase_alerts($own), 'kind'); eq(in_array('low', $k, true), false); eq(in_array('overdue', $k, true), false);
    f3_control_save(['section' => 'alerts', 'alerts' => array_fill_keys(array_keys(F3_ALERTS), 1), 'draft_days' => 1, 'count_days' => 30], $own); return true; });

echo "\n== V19 · Category-wise salary (six fields, calendar days, autosave) ==\n";
$P9 = '2026-09'; $ec = fn(string $n) => (int)row('SELECT id FROM dd_employees WHERE name=?', $n)['id'];
t('V19-BUG-01', 'Root cause of blank staff list: joining date after the month hides staff — reported with reason; correcting it brings them onto the sheet; re-open never duplicates', function () use ($own, $P9, $ec) {
    $nc = $ec('TEST New Cook'); $v = f3_period_open(['period' => $P9], $own); eq(in_array($nc, array_column($v['lines'], 'employee_id'), true), false, 'not eligible yet');
    $why = array_values(array_filter($v['not_on_sheet'], fn($x) => $x['id'] === $nc)); eq(count($why), 1); eq(str_contains($why[0]['reason'], 'Joining date'), true); eq($why[0]['fix'], 'joining');
    f3_staff_joining_fix(['id' => $nc, 'joined_on' => '2026-09-10'], $own); $n0 = count(f3_period_open(['period' => $P9], $own)['lines']); $v2 = f3_period_open(['period' => $P9], $own);
    eq(count($v2['lines']), $n0, 'no duplicates'); $l = array_values(array_filter($v2['lines'], fn($x) => $x['employee_id'] === $nc))[0]; eq($l['eligible_days'], 21.0); return eq($l['entry_status'], 'pending'); });
t('V19-BUG-02', 'Staff with no department are listed with the reason (never silently missing)', function () use ($own, $P9) { run("INSERT INTO dd_employees(name,code,salary,status,joined_on) VALUES('DEMO No Dept','ND1',1000000,'active','2026-01-01')");
    $v = f3_period_open(['period' => $P9], $own); $x = array_values(array_filter($v['not_on_sheet'], fn($e) => $e['name'] === 'DEMO No Dept')); eq(count($x), 1); return eq($x[0]['fix'], 'department'); });
t('V19-PAY-01', 'Autosave: partial values saved as Draft on the server (never Completed); skip keeps Pending / Draft; resume restores values', function () use ($own, $P9, $ec) { $a = $ec('DEMO Raju UT'); $b = $ec('DEMO Cook Mohan');
    $r = f3_line_save(['period' => $P9, 'employee_id' => $a, 'action' => 'draft', 'present' => '12', 'absent' => '', 'half_days' => '1'], $own); eq($r['line']['entry_status'], 'draft'); eq($r['line']['state'], 'draft'); eq($r['saved_at'] !== null, true);
    $g = f3_line_get($P9, $a, $own); eq($g['present'], 12.0); eq($g['half_days'], 1.0); eq($g['absent'], 17.0); // absent is calculated (30 − 12 − 1 half day)
    f3_line_save(['period' => $P9, 'employee_id' => $b, 'action' => 'skip'], $own); eq(f3_line_get($P9, $b, $own)['entry_status'], 'pending');
    f3_line_save(['period' => $P9, 'employee_id' => $a, 'action' => 'skip'], $own); return eq(f3_line_get($P9, $a, $own)['entry_status'], 'draft'); });
t('V19-PAY-02', 'Two users editing the same staff: the stale save is refused (no silent overwrite)', function () use ($own, $P9, $ec) { $a = $ec('DEMO Raju UT'); $v = f3_line_get($P9, $a, $own)['version'];
    f3_line_save(['period' => $P9, 'employee_id' => $a, 'action' => 'draft', 'version' => $v, 'present' => '13'], $own); return throws(fn() => f3_line_save(['period' => $P9, 'employee_id' => $a, 'action' => 'draft', 'version' => $v, 'present' => '14'], $own), 'another device'); });
t('V19-PAY-03', 'Penalty: only approved penalties are offered; unticked one stays for a later month; unknown id refused; approval applies only the chosen one', function () use ($own, $P9, $ec) { $id = $ec('DEMO Helper Gopal');
    $p1 = f3_staff_txn_create(['kind' => 'penalty', 'employee_id' => $id, 'date' => '2026-09-05', 'amount' => '150', 'reason' => 'Late'], $own)['id']; $p2 = f3_staff_txn_create(['kind' => 'penalty', 'employee_id' => $id, 'date' => '2026-09-06', 'amount' => '250', 'reason' => 'Broken plate'], $own)['id'];
    $p3 = f3_staff_txn_create(['kind' => 'penalty', 'employee_id' => $id, 'date' => '2026-09-07', 'amount' => '999', 'reason' => 'Not approved'], $own)['id'];
    foreach ([$p1, $p2] as $x) f3_staff_txn_decide(['id' => $x, 'approve' => 1, 'policy' => 'House rule 4'], $own);
    $ctx = f3_line_get($P9, $id, $own)['context']; eq(array_column($ctx['penalty_items'], 'id'), [$p1, $p2]);
    throws(fn() => f3_line_save(['period' => $P9, 'employee_id' => $id, 'action' => 'save', 'present' => '30', 'absent' => '0', 'half_days' => '0', 'paid_leave' => '0', 'penalty_ids' => [$p3]], $own), 'not an approved');
    $c = f3_line_save(['period' => $P9, 'employee_id' => $id, 'action' => 'save', 'present' => '30', 'absent' => '0', 'half_days' => '0', 'paid_leave' => '0', 'penalty_ids' => [$p2]], $own)['calc']; eq($c['penalties'], 25000);
    $nd = (int)row("SELECT id FROM dd_employees WHERE name='DEMO No Dept'")['id']; f3_line_save(['period' => $P9, 'employee_id' => $nd, 'action' => 'exclude', 'reason' => 'No department yet — paid next month'], $own);
    foreach (f3_period_view($P9, $own)['lines'] as $l) if (!in_array($l['state'], ['done', 'excluded'], true)) { $e = (int)$l['eligible_days']; f3_line_save(['period' => $P9, 'employee_id' => $l['employee_id'], 'action' => 'save', 'present' => (string)$e, 'absent' => '0', 'half_days' => '0', 'paid_leave' => '0', 'advance_recovery' => '0'], $own); }
    f3_period_submit(['period' => $P9], $own); f3_period_approve(['period' => $P9], $own);
    eq(row('SELECT applied_period FROM f3_staff_txns WHERE id=?', $p2)['applied_period'], $P9); eq(row('SELECT applied_period FROM f3_staff_txns WHERE id=?', $p1)['applied_period'], null, 'unticked stays');
    return eq(row('SELECT applied_period FROM f3_staff_txns WHERE id=?', $p3)['applied_period'], null); });
t('V19-PAY-04', 'Department sheets + master use the same server numbers; absent / paid-leave columns present', function () use ($own, $P9) { $m = f3_sheet($P9, null, $own); $sum = 0; foreach ([1, 2, 3, 4] as $d) $sum += f3_sheet($P9, $d, $own)['grand']['net_payable'];
    eq($m['grand']['net_payable'], $sum); $l = $m['groups'][0]['designations'][0]['lines'][0]; eq(array_key_exists('absent', $l) && array_key_exists('paid_leave', $l) && array_key_exists('entry_status', $l), true); return eq($m['divisor'], 30); });
t('V19-MIG-01', 'Migration moves a month still in draft to the V19 rule (calendar divisor), maps old leave fields and asks for review; approved months untouched', function () use ($own) {
    run("INSERT INTO f3_pay_periods(period,status,days,divisor,divisor_mode,leave_encash,calc_mode,created_by) VALUES('2026-07','draft',31,30,'fixed30',1,'v16',1)");
    $e = (int)row("SELECT id FROM dd_employees WHERE name='DEMO Sita D'")['id']; run("INSERT INTO f3_pay_lines(period,employee_id,state,emp_name,dept_id,monthly,eligible_days,present,half_days,leave_taken,paid_leave_allowed,other_paid_leave) VALUES('2026-07',?,'done','DEMO Sita D',1,1500000,31,27,1,2,1,0)", $e);
    $before = row("SELECT * FROM f3_pay_periods WHERE period='2026-09'"); f3_migrations()[6]();
    $pp = row("SELECT * FROM f3_pay_periods WHERE period='2026-07'"); eq($pp['calc_mode'], 'v19'); eq((int)$pp['divisor'], 31); $l = row("SELECT * FROM f3_pay_lines WHERE period='2026-07'"); eq($l['state'], 'draft'); eq((float)$l['paid_leave'], 1.0); eq((float)$l['absent'], 2.0);
    return eq(row("SELECT status,calc_mode FROM f3_pay_periods WHERE period='2026-09'")['calc_mode'], $before['calc_mode']); });

echo "\n== V17 · Volume ==\n";
t('V17-PERF-01', '1,000 suppliers · 1,000 items · 10,000 bill lines: lists & reports under 2 s each', function () use ($own) {
    $p = []; for ($i = 1; $i <= 1000; $i++) $p[] = "('DEMO Vol Supplier $i','demovolsupplier$i','supplier','VS$i')"; db()->exec('INSERT INTO dd_bk_parties(name,name_key,kind,code) VALUES' . implode(',', $p));
    $p = []; for ($i = 1; $i <= 1000; $i++) $p[] = "('DEMO Vol Item $i','demovolitem$i','kg','Vol')"; db()->exec('INSERT INTO dd_bk_items(name,name_key,unit,category) VALUES' . implode(',', $p));
    $s0 = (int)row("SELECT MIN(id) m FROM dd_bk_parties WHERE code LIKE 'VS%'")['m']; $i0 = (int)row("SELECT MIN(id) m FROM dd_bk_items WHERE category='Vol'")['m'];
    for ($b = 0; $b < 500; $b++) { run("INSERT INTO f3_purchase_bills(bill_no,supplier_id,invoice_no,invoice_date,post_date,status,receipt_status,grand_total) VALUES(?,?,?,?,?,'posted','received',?)", 'PBV' . $b, $s0 + ($b % 1000), 'V' . $b, '2026-10-15', '2026-10-15', 200000); $id = (int)db()->lastInsertId(); run("INSERT INTO dd_bk_txns(date,type,party_id,category,total,paid,account,import_key,source) VALUES('2026-10-15','purchase',?,'Grocery',200000,0,'Cash',?,'f360')", $s0 + ($b % 1000), 'f360:pb:' . $id);
        $l = []; for ($k = 0; $k < 20; $k++) $l[] = "($id,$k," . ($i0 + (($b * 20 + $k) % 1000)) . ",'Vol','kg',1,'kg',1,1,10000,10000,10000)"; db()->exec('INSERT INTO f3_purchase_lines(bill_id,sort,item_id,item_name,unit,factor,base_unit,qty,base_qty,rate,gross,line_total) VALUES' . implode(',', $l)); }
    $tm = []; foreach (['suppliers' => fn() => f3_sup_list([], $own), 'items' => fn() => f3_items([], $own), 'items_search' => fn() => f3_items(['q' => 'Vol Item 99'], $own), 'bills' => fn() => f3_bill_list([], $own), 'register' => fn() => f3_purchase_register(['from' => '2026-10-01', 'to' => '2026-10-31'], $own), 'aging' => fn() => f3_aging([], $own)] as $k => $fn) { $t = microtime(true); $fn(); $tm[$k] = round(microtime(true) - $t, 3); }
    echo '      timings: ' . json_encode($tm) . "\n"; $GLOBALS['V17_PERF'] = $tm; foreach ($tm as $k => $v) if ($v > 2.0) throw new RuntimeException("$k took {$v}s"); return eq((int)row('SELECT COUNT(*) n FROM f3_purchase_lines')['n'] >= 10000, true); });
