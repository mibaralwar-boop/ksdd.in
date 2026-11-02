<?php
declare(strict_types=1);
// Finance 360 V17 — Item master (canonical: Books 360 dd_bk_items, extended), per-item unit conversions and the ONE stock ledger (f3_stock_moves).
// Quantities are handled as integer milli-units (3 decimals) and conversion factors as integer micro-units (6 decimals) — no binary floats in totals.
// Valuation = weighted average per item+location: balance keeps (qty, value in paise); an outflow takes value = round(balance value × out qty ÷ balance qty),
// so the average never drifts and value is exactly 0 when the quantity reaches 0. Inflows carry their own cost (purchase line cost incl. apportioned charges).
// Accounting model (setting f3_inventory_model = 'periodic', the existing Books 360 practice): purchases are posted once to Books (payable/expense of purchases);
// stock movements are the quantity & value sub-ledger and are NOT posted again as expenses — no double counting. See ACCOUNTING_RULES.md §7.
require_once __DIR__ . '/core.php';

const F3_STD_UNITS = ['kg' => ['mass', 1000000], 'g' => ['mass', 1000], 'gram' => ['mass', 1000], 'mg' => ['mass', 1], 'quintal' => ['mass', 100000000],
    'litre' => ['vol', 1000000], 'ltr' => ['vol', 1000000], 'l' => ['vol', 1000000], 'ml' => ['vol', 1000], 'piece' => ['count', 1000], 'pcs' => ['count', 1000], 'nos' => ['count', 1000], 'dozen' => ['count', 12000]];
const F3_PACK_UNITS = ['packet', 'bag', 'box', 'carton', 'bottle', 'tray', 'can', 'jar', 'pouch', 'bundle', 'sack', 'tin', 'crate', 'case', 'roll'];
const F3_STOCK_KINDS = ['opening' => 'Opening stock', 'receipt' => 'Purchase receipt', 'return_out' => 'Purchase return', 'issue' => 'Issue / consumption', 'wastage' => 'Wastage / spoilage', 'damage' => 'Damaged',
    'expired' => 'Expired', 'adjust_in' => 'Adjustment (+)', 'adjust_out' => 'Adjustment (−)', 'count_adj' => 'Physical count adjustment', 'transfer_in' => 'Transfer in', 'transfer_out' => 'Transfer out', 'reversal' => 'Reversal'];

function f3_unit_norm(string $u): string { $u = strtolower(trim($u)); return ['kgs' => 'kg', 'kilo' => 'kg', 'grams' => 'g', 'gm' => 'g', 'gms' => 'g', 'liter' => 'litre', 'liters' => 'litre', 'litres' => 'litre', 'pc' => 'piece', 'pieces' => 'piece', 'packets' => 'packet', 'pkt' => 'packet', 'bags' => 'bag', 'boxes' => 'box', 'cartons' => 'carton', 'bottles' => 'bottle', 'dz' => 'dozen'][$u] ?? $u; }
function f3_units_all(): array { return array_values(array_unique(array_merge(['kg', 'g', 'litre', 'ml', 'piece', 'dozen'], function_exists('f3_pack_units') ? f3_pack_units() : F3_PACK_UNITS))); }
function f3_milli(mixed $v, string $label, bool $allowZero = false, bool $signed = false): int {
    $s = trim(str_replace(',', '', (string)($v ?? ''))); if ($s === '') fail($label . ' is required.');
    if (!preg_match($signed ? '/^-?\d{1,12}(\.\d{1,3})?$/' : '/^\d{1,12}(\.\d{1,3})?$/', $s)) fail($label . ': enter a number with at most 3 decimals.');
    $neg = str_starts_with($s, '-'); $s = ltrim($s, '-'); [$w, $f] = array_pad(explode('.', $s, 2), 2, ''); $n = (int)$w * 1000 + (int)str_pad($f, 3, '0');
    if ($neg) $n = -$n; if (!$allowZero && $n === 0) fail($label . ' must be more than zero.'); return $n;
}
function f3_m3(int $m): string { return m3($m); }
function f3_qm(mixed $s): int { return qm((string)$s); }
function f3_micro(mixed $f): int { $s = trim((string)$f); if (!preg_match('/^\d{1,9}(\.\d{1,6})?$/', $s)) fail('Conversion factor must be a positive number (up to 6 decimals).'); [$w, $d] = array_pad(explode('.', $s, 2), 2, ''); $n = (int)$w * 1000000 + (int)str_pad($d, 6, '0'); if ($n <= 0) fail('Conversion factor must be more than zero.'); return $n; }
function f3_fmt_micro(int $n): string { return rtrim(rtrim(intdiv($n, 1000000) . '.' . str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT), '0'), '.'); }
/** Base units per 1 entered unit, in micro (×1e6). Item-specific definition wins; same-dimension standard units are derived; pack units MUST be defined per item. */
function f3_unit_factor_micro(array $item, string $unit): int {
    $unit = f3_unit_norm($unit); $base = f3_unit_norm((string)($item['unit'] ?: 'piece'));
    if ($unit === $base) return 1000000;
    $r = row('SELECT factor FROM f3_item_units WHERE item_id=? AND unit=?', $item['id'], $unit); if ($r) return f3_micro($r['factor']);
    if (isset(F3_STD_UNITS[$unit], F3_STD_UNITS[$base]) && F3_STD_UNITS[$unit][0] === F3_STD_UNITS[$base][0]) return intdiv(F3_STD_UNITS[$unit][1] * 1000000, F3_STD_UNITS[$base][1]);
    fail('Define how many ' . $base . ' are in 1 ' . $unit . ' for “' . $item['name'] . '” (Item → Units). Pack sizes are never assumed.', 422);
}
function f3_to_base(int $qtyMilli, int $factorMicro): int { return intdiv($qtyMilli * $factorMicro + 500000, 1000000); }
function f3_item(int $id, bool $lock = false): array { $r = row('SELECT * FROM dd_bk_items WHERE id=?' . ($lock ? ' FOR UPDATE' : ''), $id); if (!$r) fail('Item not found.', 404); return $r; }
function f3_locations(): array { $l = json_decode(setting('f3_stock_locations', '["Main Store"]'), true); return is_array($l) && $l ? $l : ['Main Store']; }

/* ------------------------------ stock posting core ------------------------------ */
/** Post ONE stock movement. $m: item_id, location, date, kind, qty_milli (signed: + in, − out), value (required for inflows; outflows computed), source_type, source_id, source_line_id, reason, idem.
 *  Idempotent on idem key; locks the balance row; rejects negative stock unless $m['allow_negative'] (stock.adjust holder). */
function f3_stock_post(array $m, array $u): int {
    if ($ex = row('SELECT id FROM f3_stock_moves WHERE idem_key=?', $m['idem'])) return (int)$ex['id'];
    $loc = $m['location'] ?? 'Main Store'; if (!in_array($loc, f3_locations(), true)) fail('Unknown stock location.');
    if (in_array($m['kind'], ['opening', 'adjust_in', 'adjust_out'], true) && trim((string)($m['reason'] ?? '')) === '') fail('A reason is required for opening stock and stock corrections.'); // V21 §7
    $d = valid_date($m['date']); if ($d > f3_business_date()) fail('Stock date cannot be in the future.'); bk_check_open($d);
    $item = f3_item((int)$m['item_id']); if (!(int)$item['is_stock']) fail('“' . $item['name'] . '” is not a stock-tracked item.');
    run('INSERT IGNORE INTO f3_stock_balances(item_id,location,qty,value) VALUES(?,?,0,0)', $item['id'], $loc);
    $b = row('SELECT * FROM f3_stock_balances WHERE item_id=? AND location=? FOR UPDATE', $item['id'], $loc);
    $bq = f3_qm($b['qty']); $bv = (int)$b['value']; $q = (int)$m['qty_milli']; if ($q === 0) fail('Quantity must not be zero.');
    if ($q > 0) { $v = (int)($m['value'] ?? 0); if ($v < 0) fail('Value cannot be negative.'); }
    else {
        $out = -$q;
        if ($bq - $out < 0 && empty($m['allow_negative']) && setting('f3_negative_stock', '0') !== '1') fail('Not enough stock of “' . $item['name'] . '”: available ' . f3_m3(max(0, $bq)) . ' ' . $item['unit'] . ', needed ' . f3_m3($out) . '.', 409);
        if (isset($m['value'])) $v = -min((int)abs($m['value']), max(0, $bv)); // return at original cost, never below zero value
        elseif ($bq > 0) $v = -($out >= $bq ? $bv : intdiv($bv * $out * 2 + $bq, 2 * $bq)); // proportional, rounded half up
        else { $last = row("SELECT value,qty FROM f3_stock_moves WHERE item_id=? AND qty>0 AND value>0 ORDER BY id DESC LIMIT 1", $item['id']); $v = $last ? -intdiv((int)$last['value'] * $out, max(1, f3_qm($last['qty']))) : 0; }
    }
    $nq = $bq + $q; if ($nq === 0) $v = -$bv; $nv = $bv + $v; // reaching zero quantity clears the value exactly (ledger Σ value always equals the balance)
    $no = f3_seq('stockmove', 'SM');
    run('INSERT INTO f3_stock_moves(move_no,item_id,location,date,kind,qty,entered_qty,entered_unit,factor,value,qty_after,value_after,source_type,source_id,source_line_id,reason,cost_center,reverses_id,idem_key,created_by,approved_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        $no, $item['id'], $loc, $d, $m['kind'], f3_m3($q), isset($m['entered_milli']) ? f3_m3((int)$m['entered_milli']) : null, $m['entered_unit'] ?? null, isset($m['factor_micro']) ? f3_fmt_micro((int)$m['factor_micro']) : null,
        $v, f3_m3($nq), $nv, $m['source_type'], $m['source_id'] ?? null, $m['source_line_id'] ?? null, isset($m['reason']) ? txt($m['reason'], 250) : null, isset($m['cost_center']) ? txt($m['cost_center'], 60) : null, $m['reverses_id'] ?? null, $m['idem'], $u['id'] ?? null, $m['approved_by'] ?? null);
    $id = (int)db()->lastInsertId();
    run('UPDATE f3_stock_balances SET qty=?,value=? WHERE item_id=? AND location=?', f3_m3($nq), $nv, $item['id'], $loc);
    return $id;
}
function f3_avg_cost(int $item, string $loc = 'Main Store'): ?float { $b = row('SELECT qty,value FROM f3_stock_balances WHERE item_id=? AND location=?', $item, $loc); $q = $b ? f3_qm($b['qty']) : 0; return $q > 0 ? (int)$b['value'] * 1000 / $q : null; }

/* ------------------------------ item master ------------------------------ */
function f3_item_out(array $i, array $u, ?array $bal = null): array {
    $o = ['id' => (int)$i['id'], 'name' => $i['name'], 'code' => $i['code'], 'sku' => $i['sku'] ?? null, 'barcode' => $i['barcode'] ?? null, 'category' => $i['category'], 'subcategory' => $i['subcategory'] ?? null, 'brand' => $i['brand'] ?? null,
          'description' => $i['description'] ?? null, 'unit' => $i['unit'] ?: 'piece', 'purchase_unit' => $i['purchase_unit'] ?? null, 'is_stock' => (int)$i['is_stock'], 'active' => (int)$i['active'], 'photo_file_id' => $i['photo_file_id'] ? (int)$i['photo_file_id'] : null,
          'min_level' => (float)($i['min_level'] ?? 0), 'reorder' => (float)$i['reorder'], 'max_level' => isset($i['max_level']) && $i['max_level'] !== null ? (float)$i['max_level'] : null, 'location' => $i['location'] ?? 'Main Store',
          'default_supplier_id' => isset($i['default_supplier_id']) && $i['default_supplier_id'] ? (int)$i['default_supplier_id'] : null, 'tax_rate' => ((int)($i['tax_rate_bp'] ?? 0)) / 100, 'merged_into' => $i['merged_into'] ?? null];
    if ($bal !== null) { $o['qty'] = (float)$bal['qty']; if (f3_can($u, 'stock.valuation')) { $o['value'] = (int)$bal['value']; $o['avg_cost'] = (float)$bal['qty'] > 0 ? round((int)$bal['value'] / (float)$bal['qty']) : null; } $o['low'] = $o['qty'] <= max($o['min_level'], $o['reorder']) && max($o['min_level'], $o['reorder']) > 0; $o['out'] = $o['qty'] <= 0; }
    return $o;
}
function f3_items(array $q, array $u): array {
    f3_need($u, 'item.view', 'stock.view', 'purchase.create');
    $w = ['i.merged_into IS NULL']; $a = [];
    if (($q['status'] ?? 'active') === 'active') $w[] = 'i.active=1'; elseif (($q['status'] ?? '') === 'inactive') $w[] = 'i.active=0';
    if (!empty($q['category'])) { $w[] = 'i.category=?'; $a[] = txt($q['category'], 80); }
    if (!empty($q['q'])) { $s = txt($q['q'], 60); $w[] = '(i.name LIKE ? OR i.code=? OR i.sku=? OR i.barcode=? OR i.name_key LIKE ?)'; array_push($a, '%' . $s . '%', $s, $s, $s, '%' . bk_key($s) . '%'); }
    if (!empty($q['low'])) $w[] = 'COALESCE(b.qty,0) <= GREATEST(i.min_level,i.reorder) AND GREATEST(i.min_level,i.reorder)>0';
    $lim = min(500, max(10, (int)($q['limit'] ?? 200))); $off = max(0, (int)($q['page'] ?? 0)) * $lim;
    $loc = txt($q['location'] ?? 'Main Store', 60);
    $sql = 'FROM dd_bk_items i LEFT JOIN f3_stock_balances b ON b.item_id=i.id AND b.location=' . db()->quote($loc) . ' WHERE ' . implode(' AND ', $w);
    $rows = rows("SELECT i.*,COALESCE(b.qty,0) qty,COALESCE(b.value,0) value,(SELECT MAX(m.date) FROM f3_stock_moves m WHERE m.item_id=i.id AND m.kind='receipt') last_receipt $sql ORDER BY i.category IS NULL,i.category,i.name LIMIT $lim OFFSET $off", ...$a);
    $tot = row("SELECT COUNT(*) n,COALESCE(SUM(COALESCE(b.value,0)),0) v,SUM(COALESCE(b.qty,0) <= GREATEST(i.min_level,i.reorder) AND GREATEST(i.min_level,i.reorder)>0) low $sql", ...$a);
    $last = [];
    if ($rows) foreach (rows('SELECT l.item_id,l.rate,l.unit,l.factor,b.supplier_id,p.name supplier FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id AND b.status=\'posted\' LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id WHERE l.item_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ') ORDER BY b.post_date,l.id') as $l) $last[(int)$l['item_id']] = ['rate' => (int)$l['rate'], 'unit' => $l['unit'], 'supplier' => $l['supplier']];
    $un = []; if ($rows) foreach (rows('SELECT item_id,unit,factor FROM f3_item_units WHERE item_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')') as $x) $un[(int)$x['item_id']][] = ['unit' => $x['unit'], 'factor' => (float)$x['factor']];
    return ['items' => array_map(function ($r) use ($u, $last, $un) { $o = f3_item_out($r, $u, $r); $o['last_receipt'] = $r['last_receipt']; $lp = $last[$o['id']] ?? null; if ($lp && f3_can($u, 'stock.valuation')) { $o['last_rate'] = $lp['rate']; $o['last_rate_unit'] = $lp['unit']; } $o['last_supplier'] = $lp['supplier'] ?? null; $o['units'] = $un[$o['id']] ?? []; return $o; }, $rows),
            'total' => (int)$tot['n'], 'value' => f3_can($u, 'stock.valuation') ? (int)$tot['v'] : null, 'low_count' => (int)$tot['low'], 'page' => (int)($q['page'] ?? 0), 'limit' => $lim,
            'categories' => array_column(rows("SELECT DISTINCT category FROM dd_bk_items WHERE category IS NOT NULL AND category<>'' AND merged_into IS NULL ORDER BY category"), 'category'), 'units' => f3_units_all(), 'locations' => f3_locations()];
}
function f3_item_get(int $id, array $u): array {
    f3_need($u, 'item.view', 'stock.view', 'purchase.create');
    $i = f3_item($id); $bal = row("SELECT COALESCE(SUM(qty),0) qty,COALESCE(SUM(value),0) value FROM f3_stock_balances WHERE item_id=?", $id);
    $o = ['item' => f3_item_out($i, $u, $bal), 'units' => rows('SELECT unit,factor,note FROM f3_item_units WHERE item_id=? ORDER BY unit', $id), 'base_unit' => $i['unit'] ?: 'piece',
          'by_location' => rows('SELECT location,qty' . (f3_can($u, 'stock.valuation') ? ',value' : '') . ' FROM f3_stock_balances WHERE item_id=?', $id),
          'has_moves' => (bool)row("SELECT 1 FROM f3_stock_moves WHERE item_id=? AND source_type<>'legacy' LIMIT 1", $id)];
    $o['ledger'] = f3_item_ledger($id, ['limit' => 200], $u)['rows'];
    $o['purchases'] = rows("SELECT b.id,b.bill_no,b.invoice_no,b.post_date,p.name supplier,l.qty,l.unit,l.rate,l.line_total FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id WHERE l.item_id=? AND b.status='posted' ORDER BY b.post_date DESC LIMIT 20", $id);
    if (!f3_can($u, 'stock.valuation')) foreach ($o['purchases'] as &$p) unset($p['rate'], $p['line_total']); unset($p);
    return $o;
}
function f3_item_save(array $b, array $u): array {
    $id = (int)($b['id'] ?? 0); f3_need($u, $id ? 'item.edit' : 'item.create');
    $old = $id ? f3_item($id, true) : null;
    $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($b['name'] ?? ''))), 0, 160); if ($name === '') fail('Item name is required.');
    $k = bk_key($name); if ($k === '') fail('Item name needs letters or numbers.');
    $dup = row('SELECT id,name,active FROM dd_bk_items WHERE name_key=? AND id<>? AND merged_into IS NULL', $k, $id);
    if ($dup) throw new F3Conflict('An item called “' . $dup['name'] . '” already exists' . ((int)$dup['active'] ? '' : ' (inactive)') . '. Use it instead of creating a duplicate.', ['existing_id' => (int)$dup['id'], 'existing_name' => $dup['name']]);
    foreach (['code' => 'code', 'barcode' => 'barcode'] as $f => $col) { $v = txt($b[$f] ?? '', 60); if ($v !== '' && ($d = row("SELECT name FROM dd_bk_items WHERE $col=? AND id<>?", $v, $id))) fail(strtoupper($f) . ' ' . $v . ' is already used by “' . $d['name'] . '”.', 409); }
    $unit = f3_unit_norm((string)($b['unit'] ?? 'kg')); if ($unit === '' || strlen($unit) > 20) fail('Choose the base stock unit.');
    if ($old && $unit !== f3_unit_norm((string)($old['unit'] ?: $unit)) && row('SELECT 1 FROM f3_stock_moves WHERE item_id=? LIMIT 1', $id)) fail('The base stock unit cannot change after stock movements exist (it would change history). Create a new item instead.', 409);
    $dec = fn($v) => ($v === null || $v === '') ? '0.000' : f3_m3(f3_milli($v, 'Level', true));
    $vals = ['name' => $name, 'name_key' => $k, 'code' => txt($b['code'] ?? '', 40) ?: null, 'barcode' => txt($b['barcode'] ?? '', 60) ?: null, 'sku' => txt($b['sku'] ?? '', 40) ?: null, 'category' => txt($b['category'] ?? '', 80) ?: null,
             'subcategory' => txt($b['subcategory'] ?? '', 80) ?: null, 'brand' => txt($b['brand'] ?? '', 80) ?: null, 'description' => txt($b['description'] ?? '', 500) ?: null, 'unit' => $unit,
             'purchase_unit' => f3_unit_norm((string)($b['purchase_unit'] ?? '')) ?: null, 'is_stock' => empty($b['untracked']) ? 1 : 0, 'reorder' => $dec($b['reorder'] ?? 0), 'min_level' => $dec($b['min_level'] ?? 0),
             'max_level' => ($b['max_level'] ?? '') === '' ? null : $dec($b['max_level']), 'location' => in_array($b['location'] ?? 'Main Store', f3_locations(), true) ? ($b['location'] ?? 'Main Store') : 'Main Store',
             'default_supplier_id' => !empty($b['default_supplier_id']) ? (int)$b['default_supplier_id'] : null, 'tax_rate_bp' => (int)round((float)($b['tax_rate'] ?? 0) * 100), 'updated_at' => gmdate('Y-m-d H:i:s')];
    if ($vals['tax_rate_bp'] < 0 || $vals['tax_rate_bp'] > 5000) fail('Tax rate must be 0–50 %.');
    if (!empty($b['photo']['data'])) { f3_need($u, 'item.photo', 'purchase.upload'); $vals['photo_file_id'] = f3_image_upload($b['photo'], $u); }
    if ($id) { run('UPDATE dd_bk_items SET ' . implode(',', array_map(fn($c) => "$c=?", array_keys($vals))) . ' WHERE id=?', ...[...array_values($vals), $id]); f3_audit($u, 'item.update', ['id' => $id, 'before' => array_intersect_key($old, $vals), 'after' => $vals]); }
    else { run('INSERT INTO dd_bk_items(' . implode(',', array_keys($vals)) . ') VALUES(' . implode(',', array_fill(0, count($vals), '?')) . ')', ...array_values($vals)); $id = (int)db()->lastInsertId(); f3_audit($u, 'item.create', ['id' => $id, 'name' => $name]); }
    foreach ((array)($b['units'] ?? []) as $cu) { $un = f3_unit_norm((string)($cu['unit'] ?? '')); if ($un === '' || $un === $unit) continue; if (empty($cu['factor'])) { run('DELETE FROM f3_item_units WHERE item_id=? AND unit=? AND NOT EXISTS (SELECT 1 FROM f3_purchase_lines l WHERE l.item_id=? AND l.unit=?)', $id, $un, $id, $un); continue; }
        $f = f3_micro($cu['factor']); run('INSERT INTO f3_item_units(item_id,unit,factor,note,created_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE factor=VALUES(factor),note=VALUES(note)', $id, $un, f3_fmt_micro($f), txt($cu['note'] ?? '', 120) ?: null, $u['id']); }
    return ['ok' => true, 'id' => $id] + f3_item_get($id, $u);
}
function f3_item_active(array $b, array $u): array { f3_need($u, 'item.deactivate'); $i = f3_item((int)($b['id'] ?? 0)); $on = !empty($b['active']) ? 1 : 0;
    if (!$on) { $bal = row('SELECT COALESCE(SUM(qty),0) q FROM f3_stock_balances WHERE item_id=?', $i['id']); if ((float)$bal['q'] > 0 && empty($b['force'])) throw new F3Conflict('This item still has ' . (float)$bal['q'] . ' ' . $i['unit'] . ' in stock. Deactivate anyway? (History is kept.)', ['needs' => 'force']); }
    run('UPDATE dd_bk_items SET active=? WHERE id=?', $on, $i['id']); f3_audit($u, $on ? 'item.activate' : 'item.deactivate', ['id' => (int)$i['id']]); return ['ok' => true]; }
/** Opening stock for an item that has no Finance 360 movements yet (otherwise use an adjustment). */
function f3_item_opening(array $b, array $u): array {
    f3_need($u, 'item.opening'); $i = f3_item((int)($b['id'] ?? 0)); $loc = (string)($b['location'] ?? 'Main Store');
    if (row("SELECT 1 FROM f3_stock_moves WHERE item_id=? AND location=? AND source_type<>'legacy' LIMIT 1", $i['id'], $loc)) fail('This item already has stock movements. Use a stock adjustment instead.', 409);
    $d = valid_date($b['date'] ?? ''); $unit = f3_unit_norm((string)($b['unit'] ?? $i['unit'])); $fm = f3_unit_factor_micro($i, $unit); $qm = f3_milli($b['qty'] ?? '', 'Opening quantity');
    $val = f3_paise($b['value'] ?? null, 'Opening value (total ₹)');
    $leg = row("SELECT id,qty,value FROM f3_stock_moves WHERE item_id=? AND location=? AND source_type='legacy' AND reversed_by IS NULL", $i['id'], $loc);
    if ($leg) f3_stock_reverse_move((int)$leg['id'], 'Replaced by opening stock entered in Finance 360', $u, true);
    $id = f3_stock_post(['item_id' => $i['id'], 'location' => $loc, 'date' => $d, 'kind' => 'opening', 'qty_milli' => f3_to_base($qm, $fm), 'entered_milli' => $qm, 'entered_unit' => $unit, 'factor_micro' => $fm, 'value' => $val, 'source_type' => 'opening', 'source_id' => $i['id'], 'reason' => f3_text($b['reason'] ?? '', 250, 'Reason / source of the opening quantity'), 'idem' => 'open:' . $i['id'] . ':' . $loc . ':' . ($leg ? 'r' . $leg['id'] : '0')], $u);
    run('UPDATE dd_bk_items SET opening_date=? WHERE id=?', $d, $i['id']); f3_audit($u, 'stock.opening', ['item' => (int)$i['id'], 'move' => $id]); return ['ok' => true, 'move_id' => $id];
}
/** Image-only upload (item/supplier photos): reuses the hardened store_upload (MIME sniff, decode check, EXIF strip, random name, private folder). */
function f3_image_upload(array $f, array $u): int { $id = f3_upload($f, $u); $m = row('SELECT mime FROM dd_files WHERE id=?', $id)['mime']; if (!str_starts_with($m, 'image/')) fail('Photo must be a JPG, PNG or WEBP image.'); return $id; }

/* ------------------------------ manual movements ------------------------------ */
function f3_stock_manual(array $b, array $u): array {
    f3_need($u, 'stock.issue', 'stock.adjust'); $kind = (string)($b['kind'] ?? '');
    $perm = ['issue' => 'stock.issue', 'wastage' => 'stock.issue', 'damage' => 'stock.issue', 'expired' => 'stock.issue', 'adjust_in' => 'stock.adjust', 'adjust_out' => 'stock.adjust', 'transfer' => 'stock.issue'][$kind] ?? fail('Choose a movement type.');
    f3_need($u, $perm);
    return idem('f3-stock', $b, $u, function () use ($b, $u, $kind) {
        $i = f3_item((int)($b['item_id'] ?? 0), true); $d = valid_date($b['date'] ?? f3_business_date()); $loc = (string)($b['location'] ?? 'Main Store');
        $unit = f3_unit_norm((string)($b['unit'] ?? $i['unit'])); $fm = f3_unit_factor_micro($i, $unit); $qm = f3_milli($b['qty'] ?? '', 'Quantity'); $base = f3_to_base($qm, $fm); if ($base <= 0) fail('Quantity is too small for the base unit.');
        $reason = f3_text($b['reason'] ?? '', 250, 'Reason'); $neg = !empty($b['allow_negative']) && f3_can($u, 'stock.adjust');
        $key = 'man:' . txt($b['client_key'] ?? bin2hex(random_bytes(8)), 64);
        if ($kind === 'transfer') { $to = (string)($b['to_location'] ?? ''); if ($to === $loc || !in_array($to, f3_locations(), true)) fail('Choose a different destination location.');
            $o = f3_stock_post(['item_id' => $i['id'], 'location' => $loc, 'date' => $d, 'kind' => 'transfer_out', 'qty_milli' => -$base, 'entered_milli' => $qm, 'entered_unit' => $unit, 'factor_micro' => $fm, 'source_type' => 'transfer', 'reason' => $reason, 'allow_negative' => $neg, 'idem' => $key . ':out'], $u);
            $ov = -(int)row('SELECT value FROM f3_stock_moves WHERE id=?', $o)['value'];
            $in = f3_stock_post(['item_id' => $i['id'], 'location' => $to, 'date' => $d, 'kind' => 'transfer_in', 'qty_milli' => $base, 'value' => $ov, 'entered_milli' => $qm, 'entered_unit' => $unit, 'factor_micro' => $fm, 'source_type' => 'transfer', 'source_id' => $o, 'reason' => $reason, 'idem' => $key . ':in'], $u);
            f3_audit($u, 'stock.transfer', ['item' => (int)$i['id'], 'from' => $loc, 'to' => $to, 'qty' => f3_m3($base)]); return ['ok' => true, 'moves' => [$o, $in]]; }
        $in = $kind === 'adjust_in';
        $val = $in ? (isset($b['value']) && $b['value'] !== '' ? f3_paise($b['value'], 'Value') : (int)round(($a = f3_avg_cost((int)$i['id'], $loc)) !== null ? $a * $base / 1000 : 0)) : null;
        $id = f3_stock_post(['item_id' => $i['id'], 'location' => $loc, 'date' => $d, 'kind' => $kind, 'qty_milli' => $in ? $base : -$base, 'value' => $val, 'entered_milli' => $qm, 'entered_unit' => $unit, 'factor_micro' => $fm, 'source_type' => 'manual', 'reason' => $reason, 'cost_center' => txt($b['cost_center'] ?? '', 60) ?: null, 'allow_negative' => $neg, 'idem' => $key, 'approved_by' => in_array($kind, ['adjust_in', 'adjust_out'], true) ? $u['id'] : null], $u);
        f3_audit($u, 'stock.' . $kind, ['item' => (int)$i['id'], 'qty' => f3_m3($base), 'move' => $id, 'reason' => $reason]);
        return ['ok' => true, 'move_id' => $id];
    });
}
/** Correction = an opposite movement (history is never edited). */
function f3_stock_reverse_move(int $id, string $why, array $u, bool $internal = false): int {
    $m = row('SELECT * FROM f3_stock_moves WHERE id=? FOR UPDATE', $id); if (!$m) fail('Movement not found.', 404);
    if ($m['reversed_by']) fail('Already reversed.', 409);
    if (!$internal && !in_array($m['source_type'], ['manual', 'opening', 'transfer'], true)) fail('Purchase receipts / returns / counts are corrected from their own document (return or new count).', 409);
    $rid = f3_stock_post(['item_id' => $m['item_id'], 'location' => $m['location'], 'date' => max($m['date'], f3_business_date() > $m['date'] ? f3_business_date() : $m['date']), 'kind' => 'reversal', 'qty_milli' => -f3_qm($m['qty']), 'value' => abs((int)$m['value']),
        'source_type' => 'reversal', 'source_id' => $m['id'], 'reason' => 'Reversal of ' . $m['move_no'] . ': ' . $why, 'reverses_id' => $m['id'], 'allow_negative' => true, 'idem' => 'rev:' . $m['id']], $u);
    run('UPDATE f3_stock_moves SET reversed_by=? WHERE id=?', $rid, $id); return $rid;
}
function f3_stock_reverse(array $b, array $u): array { f3_need($u, 'stock.adjust'); $rid = f3_stock_reverse_move((int)($b['id'] ?? 0), f3_text($b['reason'] ?? '', 200, 'Reason'), $u); f3_audit($u, 'stock.reverse', ['move' => (int)$b['id'], 'reversal' => $rid]); return ['ok' => true, 'id' => $rid]; }

/* ------------------------------ physical count ------------------------------ */
function f3_count_start(array $b, array $u): array {
    f3_need($u, 'stock.count'); $loc = (string)($b['location'] ?? 'Main Store'); if (!in_array($loc, f3_locations(), true)) fail('Unknown location.');
    if ($o = row("SELECT id,count_no FROM f3_stock_counts WHERE location=? AND status IN ('open','submitted')", $loc)) fail('Count ' . $o['count_no'] . ' is already open for ' . $loc . '. Resume it instead of starting another.', 409);
    $cat = txt($b['category'] ?? '', 80); $no = f3_seq('stockcount', 'SC');
    $startMove = (int)row('SELECT COALESCE(MAX(id),0) n FROM f3_stock_moves')['n'];
    run('INSERT INTO f3_stock_counts(count_no,location,date,scope,note,started_by,start_move_id) VALUES(?,?,?,?,?,?,?)', $no, $loc, f3_business_date(), $cat ?: 'All tracked items', txt($b['note'] ?? '', 250) ?: null, $u['id'], $startMove); $cid = (int)db()->lastInsertId();
    foreach (rows('SELECT i.id,COALESCE(b.qty,0) q FROM dd_bk_items i LEFT JOIN f3_stock_balances b ON b.item_id=i.id AND b.location=? WHERE i.active=1 AND i.is_stock=1 AND i.merged_into IS NULL' . ($cat ? ' AND i.category=' . db()->quote($cat) : '') . ' ORDER BY i.category,i.name', $loc) as $r)
        run('INSERT INTO f3_stock_count_lines(count_id,item_id,expected) VALUES(?,?,?)', $cid, $r['id'], $r['q']);
    f3_audit($u, 'stock.count_start', ['count' => $no, 'location' => $loc]); return f3_count_get($cid, $u);
}
function f3_count_get(int $id, array $u): array {
    f3_need($u, 'stock.count', 'stock.count_approve'); $c = row('SELECT * FROM f3_stock_counts WHERE id=?', $id); if (!$c) fail('Count not found.', 404);
    $l = rows('SELECT l.*,i.name,i.unit,i.category,i.photo_file_id FROM f3_stock_count_lines l JOIN dd_bk_items i ON i.id=l.item_id WHERE l.count_id=? ORDER BY i.category,i.name', $id);
    $val = f3_can($u, 'stock.valuation');
    foreach ($l as &$x) { $x['expected'] = (float)$x['expected']; $x['counted'] = $x['counted'] === null ? null : (float)$x['counted']; $x['variance'] = $x['counted'] === null ? null : round($x['counted'] - $x['expected'], 3);
        if ($val && $x['variance']) { $a = f3_avg_cost((int)$x['item_id'], $c['location']); $x['variance_value'] = $a === null ? null : (int)round($a * $x['variance']); } } unset($x);
    if ($c['status'] === 'submitted') foreach ($l as &$x) { if ($x['counted'] === null) continue; $pl = f3_count_line_plan($c, ['item_id' => $x['item_id'], 'counted' => $x['counted'], 'expected' => $x['expected'], 'book_at_count' => $x['book_at_count'], 'move_id_at_count' => $x['move_id_at_count']]);
        $x['approval'] = $pl['ok'] ? ['expected_at_count' => $pl['expected_at_count'] / 1000, 'moved_after' => $pl['post_count'] / 1000, 'variance' => $pl['diff'] / 1000, 'final' => $pl['final'] / 1000, 'backdated' => $pl['backdated']] : ['problem' => $pl['reason']]; } unset($x);
    return ['count' => $c, 'lines' => $l, 'done' => count(array_filter($l, fn($x) => $x['counted'] !== null)), 'total' => count($l)];
}
function f3_count_save(array $b, array $u): array {
    f3_need($u, 'stock.count'); $c = row('SELECT * FROM f3_stock_counts WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0)); if (!$c || $c['status'] !== 'open') fail('This count is not open.', 409);
    foreach ((array)($b['lines'] ?? []) as $ln) { $iid = (int)($ln['item_id'] ?? 0); $v = $ln['counted'] ?? '';
        if ($v === '' || $v === null) { run('UPDATE f3_stock_count_lines SET counted=NULL,counted_by=NULL WHERE count_id=? AND item_id=?', $c['id'], $iid); continue; }
        $newQ = f3_m3(f3_milli($v, 'Counted quantity', true)); $old = row('SELECT counted FROM f3_stock_count_lines WHERE count_id=? AND item_id=? FOR UPDATE', $c['id'], $iid); if (!$old) continue;
        if ($old['counted'] !== null && f3_qm($old['counted']) === f3_qm($newQ)) { run('UPDATE f3_stock_count_lines SET reason=? WHERE count_id=? AND item_id=?', txt($ln['reason'] ?? '', 250) ?: null, $c['id'], $iid); continue; } // same quantity: keep the original count time
        // V23 P0-03: the moment a quantity is (re)counted is the cut-off for THIS item. Record the book quantity and the last movement id at that moment,
        // so movements posted after it (receipts, issues, transfers, reversals) are kept when the count is approved.
        $snap = row('SELECT COALESCE((SELECT qty FROM f3_stock_balances WHERE item_id=? AND location=?),0) q, COALESCE((SELECT MAX(id) FROM f3_stock_moves),0) mid', $iid, $c['location'], );
        run('UPDATE f3_stock_count_lines SET counted=?,reason=?,counted_by=?,counted_at=UTC_TIMESTAMP(),book_at_count=?,move_id_at_count=? WHERE count_id=? AND item_id=?', $newQ, txt($ln['reason'] ?? '', 250) ?: null, $u['id'], $snap['q'], (int)$snap['mid'], $c['id'], $iid); }
    if (!empty($b['submit'])) { $open = (int)row('SELECT COUNT(*) n FROM f3_stock_count_lines WHERE count_id=? AND counted IS NULL', $c['id'])['n']; if ($open) fail($open . ' item(s) are not counted yet. Enter 0 for items that are not there.', 422);
        $noReason = (int)row('SELECT COUNT(*) n FROM f3_stock_count_lines WHERE count_id=? AND counted<>expected AND (reason IS NULL OR reason=\'\')', $c['id'])['n']; if ($noReason) fail($noReason . ' item(s) have a difference without a reason.', 422);
        run("UPDATE f3_stock_counts SET status='submitted',submitted_by=?,submitted_at=UTC_TIMESTAMP() WHERE id=?", $u['id'], $c['id']);
        f3_notify('stock-count', 'count-' . $c['id'], 'Stock count ' . $c['count_no'] . ' waits for approval', 'Submitted by ' . $u['name'], 'stock.count_approve', 'count:' . $c['id']); f3_audit($u, 'stock.count_submit', ['count' => $c['count_no']]); }
    return f3_count_get((int)$c['id'], $u);
}
/** Movements of one item/location posted after a count cut-off (move id), excluding this count's own adjustments. */
function f3_count_after(int $item, string $loc, int $afterId, int $countId): array {
    return rows("SELECT id,move_no,date,kind,qty,source_type,source_id FROM f3_stock_moves WHERE item_id=? AND location=? AND id>? AND NOT (source_type='count' AND source_id=?) ORDER BY id", $item, $loc, $afterId, $countId);
}
/** V23 P0-03 — reconcile one counted line at approval time.
 *  expected_at_count = book quantity when the person counted (snapshot) · post_count = Σ movements recorded after that moment
 *  variance = counted − expected_at_count (posted as ONE adjustment)  →  final stock = counted + post_count (intervening movements preserved).
 *  Backdated movements (recorded after the count but dated before the count date) are ambiguous: were they already on the shelf?
 *  They block approval unless the approver confirms how to treat them, or the item is recounted. */
function f3_count_line_plan(array $c, array $l): array {
    $cur = f3_qm(row('SELECT COALESCE((SELECT qty FROM f3_stock_balances WHERE item_id=? AND location=?),0) q', $l['item_id'], $c['location'])['q']);
    if ($l['move_id_at_count'] === null) { // counted before V23 (no snapshot): fall back to the count start snapshot, else require a recount
        if ($c['start_move_id'] === null) return ['ok' => false, 'reason' => 'counted before the V23 update — enter the count again', 'current' => $cur];
        $snapQ = f3_qm($l['expected']); $after = (int)$c['start_move_id']; $basis = 'count start';
    } else { $snapQ = f3_qm($l['book_at_count']); $after = (int)$l['move_id_at_count']; $basis = 'time of counting'; }
    $mv = f3_count_after((int)$l['item_id'], $c['location'], $after, (int)$c['id']); $post = 0; $back = [];
    foreach ($mv as $m) { $post += f3_qm($m['qty']); if ($m['date'] < $c['date']) $back[] = $m['move_no'] . ' (' . $m['kind'] . ', dated ' . $m['date'] . ')'; }
    // the ledger must explain the current balance exactly; otherwise the history cannot be trusted → recount
    if ($snapQ + $post !== $cur) return ['ok' => false, 'reason' => 'stock ledger does not explain the balance since counting — recount this item', 'current' => $cur];
    $diff = f3_qm($l['counted']) - $snapQ;
    return ['ok' => true, 'basis' => $basis, 'expected_at_count' => $snapQ, 'post_count' => $post, 'current' => $cur, 'diff' => $diff, 'final' => $cur + $diff, 'backdated' => $back, 'moves_after' => count($mv)];
}
/** Approval posts ONE adjustment per item with a variance (idempotent per count+item) — never overriding movements recorded after the count. */
function f3_count_approve(array $b, array $u): array {
    f3_need($u, 'stock.count_approve'); $c = row('SELECT * FROM f3_stock_counts WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0)); if (!$c || $c['status'] !== 'submitted') fail('Only a submitted count can be approved.', 409);
    if (!empty($b['reject'])) { run("UPDATE f3_stock_counts SET status='open' WHERE id=?", $c['id']); f3_notify_done('count-' . $c['id']); f3_audit($u, 'stock.count_return', ['count' => $c['count_no'], 'note' => txt($b['note'] ?? '', 200)]); return f3_count_get((int)$c['id'], $u); }
    $lines = rows('SELECT l.*,i.name FROM f3_stock_count_lines l JOIN dd_bk_items i ON i.id=l.item_id WHERE l.count_id=? ORDER BY l.id', $c['id']);
    foreach ($lines as $l) { run('INSERT IGNORE INTO f3_stock_balances(item_id,location,qty,value) VALUES(?,?,0,0)', $l['item_id'], $c['location']); row('SELECT qty FROM f3_stock_balances WHERE item_id=? AND location=? FOR UPDATE', $l['item_id'], $c['location']); }
    $plans = []; $bad = []; $back = [];
    foreach ($lines as $l) { $pl = f3_count_line_plan($c, $l); $plans[$l['id']] = $pl; if (!$pl['ok']) $bad[] = $l['name'] . ': ' . $pl['reason']; elseif ($pl['backdated']) $back[] = $l['name'] . ': ' . implode(', ', $pl['backdated']); }
    if ($bad) throw new F3Conflict('Cannot approve yet — ' . implode('; ', array_slice($bad, 0, 6)) . (count($bad) > 6 ? ' …' : '') . '. Return the count for a recount of these items.', ['code' => 'count_recount', 'items' => $bad]);
    $ackWhy = trim((string)($b['backdated_reason'] ?? ''));
    if ($back && mb_strlen($ackWhy) < 5) throw new F3Conflict('Movements dated BEFORE the count were recorded AFTER it was counted (' . implode('; ', array_slice($back, 0, 4)) . '). If they were not on the shelf when counted, confirm with a reason; otherwise return the count for a recount.', ['code' => 'count_backdated', 'items' => $back]);
    $n = 0; $sum = [];
    foreach ($lines as $l) { $pl = $plans[$l['id']]; $sum[] = ['item' => (int)$l['item_id'], 'expected_at_count' => f3_m3($pl['expected_at_count']), 'counted' => $l['counted'], 'after' => f3_m3($pl['post_count']), 'variance' => f3_m3($pl['diff']), 'final' => f3_m3($pl['final'])];
        run('UPDATE f3_stock_count_lines SET approved_expected=?,post_count_qty=? WHERE id=?', f3_m3($pl['expected_at_count']), f3_m3($pl['post_count']), $l['id']);
        $diff = $pl['diff']; if ($diff === 0) continue;
        $val = $diff > 0 ? (int)round((f3_avg_cost((int)$l['item_id'], $c['location']) ?? 0) * $diff / 1000) : null;
        $mid = f3_stock_post(['item_id' => $l['item_id'], 'location' => $c['location'], 'date' => f3_business_date(), 'kind' => 'count_adj', 'qty_milli' => $diff, 'value' => $val, 'source_type' => 'count', 'source_id' => $c['id'], 'reason' => 'Count ' . $c['count_no'] . ': ' . ($l['reason'] ?: 'difference') . ($pl['post_count'] ? ' (' . f3_m3($pl['post_count']) . ' moved after counting kept)' : ''), 'allow_negative' => true, 'approved_by' => $u['id'], 'idem' => 'count:' . $c['id'] . ':' . $l['item_id']], $u);
        run('UPDATE f3_stock_count_lines SET move_id=? WHERE id=?', $mid, $l['id']); $n++;
    }
    run("UPDATE f3_stock_counts SET status='approved',approved_by=?,approved_at=UTC_TIMESTAMP() WHERE id=?", $u['id'], $c['id']); f3_notify_done('count-' . $c['id']);
    f3_audit($u, 'stock.count_approve', ['count' => $c['count_no'], 'adjustments' => $n, 'lines' => $sum, 'backdated_ack' => $back ? ['items' => $back, 'reason' => $ackWhy] : null]); return f3_count_get((int)$c['id'], $u) + ['adjustments' => $n];
}

/* ------------------------------ reports ------------------------------ */
function f3_item_ledger(int $item, array $q, array $u): array {
    f3_need($u, 'stock.view', 'item.view'); $val = f3_can($u, 'stock.valuation');
    $w = ['m.item_id=?']; $a = [$item]; if (!empty($q['from'])) { $w[] = 'm.date>=?'; $a[] = valid_date($q['from']); } if (!empty($q['to'])) { $w[] = 'm.date<=?'; $a[] = valid_date($q['to']); }
    $r = rows('SELECT m.*,u.name by_name FROM f3_stock_moves m LEFT JOIN dd_users u ON u.id=m.created_by WHERE ' . implode(' AND ', $w) . ' ORDER BY m.id DESC LIMIT ' . min(2000, max(10, (int)($q['limit'] ?? 500))), ...$a);
    foreach ($r as &$x) { $x['kind_label'] = F3_STOCK_KINDS[$x['kind']] ?? $x['kind']; foreach (['qty', 'qty_after', 'entered_qty'] as $k) $x[$k] = $x[$k] === null ? null : (float)$x[$k]; if (!$val) unset($x['value'], $x['value_after']); else { $x['value'] = (int)$x['value']; $x['value_after'] = (int)$x['value_after']; } unset($x['idem_key']); } unset($x);
    return ['rows' => $r];
}
function f3_stock_moves_report(array $q, array $u): array {
    f3_need($u, 'stock.view'); $from = valid_date($q['from'] ?? substr(f3_business_date(), 0, 8) . '01'); $to = valid_date($q['to'] ?? f3_business_date()); $val = f3_can($u, 'stock.valuation');
    $w = ['m.date BETWEEN ? AND ?']; $a = [$from, $to];
    if (!empty($q['kind'])) { $w[] = 'm.kind=?'; $a[] = txt($q['kind'], 16); } if (!empty($q['item_id'])) { $w[] = 'm.item_id=?'; $a[] = (int)$q['item_id']; } if (!empty($q['category'])) { $w[] = 'i.category=?'; $a[] = txt($q['category'], 80); }
    $r = rows('SELECT m.id,m.item_id,m.move_no,m.date,m.kind,m.qty,m.value,m.qty_after,m.location,m.reason,m.source_type,m.source_id,m.reversed_by,i.name item,i.unit,u.name by_name FROM f3_stock_moves m JOIN dd_bk_items i ON i.id=m.item_id LEFT JOIN dd_users u ON u.id=m.created_by WHERE ' . implode(' AND ', $w) . ' ORDER BY m.date DESC,m.id DESC LIMIT 2000', ...$a);
    $sum = []; foreach ($r as &$x) { $x['qty'] = (float)$x['qty']; $x['qty_after'] = (float)$x['qty_after']; $x['kind_label'] = F3_STOCK_KINDS[$x['kind']] ?? $x['kind']; $s = &$sum[$x['kind']]; $s ??= ['n' => 0, 'value' => 0]; $s['n']++; $s['value'] += (int)$x['value']; unset($s); if (!$val) unset($x['value']); else $x['value'] = (int)$x['value']; } unset($x);
    if (!$val) foreach ($sum as &$s) unset($s['value']); unset($s);
    return ['from' => $from, 'to' => $to, 'rows' => $r, 'by_kind' => $sum, 'kinds' => F3_STOCK_KINDS];
}
/** Quantity & value of every item as on a date = sum of movements up to that date (the ledger is append-only). */
function f3_stock_valuation(string $asOf, array $q, array $u): array {
    f3_need($u, 'stock.valuation'); valid_date($asOf);
    $r = rows("SELECT i.id,i.name,i.unit,i.category,COALESCE(SUM(m.qty),0) qty,COALESCE(SUM(m.value),0) value,MAX(CASE WHEN m.kind IN ('issue','wastage','damage','expired','transfer_out') THEN m.date END) last_out,MAX(CASE WHEN m.kind='receipt' THEN m.date END) last_in
        FROM dd_bk_items i JOIN f3_stock_moves m ON m.item_id=i.id AND m.date<=? " . (!empty($q['category']) ? 'WHERE i.category=' . db()->quote(txt($q['category'], 80)) : '') . " GROUP BY i.id HAVING qty<>0 OR value<>0 ORDER BY i.category,i.name", $asOf);
    $cat = []; $t = 0; foreach ($r as &$x) { $x['qty'] = (float)$x['qty']; $x['value'] = (int)$x['value']; $t += $x['value']; $k = $x['category'] ?: 'Uncategorised'; $cat[$k] = ($cat[$k] ?? 0) + $x['value'];
        $x['days_since_out'] = $x['last_out'] ? (new DateTimeImmutable($x['last_out']))->diff(new DateTimeImmutable($asOf))->days : null; } unset($x);
    arsort($cat); return ['as_of' => $asOf, 'rows' => $r, 'total' => $t, 'by_category' => $cat];
}
function f3_stock_overview(array $u): array {
    f3_need($u, 'stock.view'); $val = f3_can($u, 'stock.valuation');
    $low = rows("SELECT i.id,i.name,i.unit,i.photo_file_id,COALESCE(b.qty,0) qty,GREATEST(i.min_level,i.reorder) lvl FROM dd_bk_items i LEFT JOIN f3_stock_balances b ON b.item_id=i.id AND b.location='Main Store' WHERE i.active=1 AND i.is_stock=1 AND i.merged_into IS NULL AND GREATEST(i.min_level,i.reorder)>0 AND COALESCE(b.qty,0)<=GREATEST(i.min_level,i.reorder) ORDER BY COALESCE(b.qty,0)/GREATEST(i.min_level,i.reorder) LIMIT 100");
    $pend = rows("SELECT b.id,b.bill_no,b.invoice_no,b.receipt_status,p.name supplier,b.post_date,SUM(GREATEST(l.base_qty-l.received_base,0)>0) lines_pending FROM f3_purchase_bills b JOIN f3_purchase_lines l ON l.bill_id=b.id LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id WHERE b.status='posted' AND b.receipt_status<>'received' GROUP BY b.id ORDER BY b.post_date LIMIT 100");
    $exp = rows("SELECT l.item_id,i.name,l.batch,l.expiry,b.bill_no FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id AND b.status='posted' JOIN dd_bk_items i ON i.id=l.item_id WHERE l.expiry IS NOT NULL AND l.expiry<=? AND l.received_base>l.returned_base ORDER BY l.expiry LIMIT 50", next_date(f3_business_date(), 15));
    $tot = row('SELECT COALESCE(SUM(value),0) v,COUNT(DISTINCT item_id) n FROM f3_stock_balances WHERE qty<>0');
    $cnt = row("SELECT id,count_no,status,location FROM f3_stock_counts WHERE status IN ('open','submitted') ORDER BY id DESC LIMIT 1");
    $lastApproved = row("SELECT MAX(approved_at) d FROM f3_stock_counts WHERE status='approved'")['d'];
    return ['value' => $val ? (int)$tot['v'] : null, 'items_in_stock' => (int)$tot['n'], 'low' => $low, 'pending_receipts' => $pend, 'expiring' => $exp, 'open_count' => $cnt, 'last_count' => $lastApproved, 'cutover' => setting('f3_stock_cutover', ''), 'locations' => f3_locations(), 'model' => setting('f3_inventory_model', 'periodic'),
            'legacy_crm_value' => (function () { try { return (int)row('SELECT COALESCE(SUM(GREATEST(b.quantity,0)*i.cost),0) n FROM dd_stock_balances b JOIN dd_inventory i ON i.id=b.item_id')['n']; } catch (PDOException $e) { return null; } })()];
}
/** Lightweight thumbnails (240 px JPEG) for item / bill photos, cached privately. */
function f3_thumb(int $id, array $u): never {
    $f = row('SELECT * FROM dd_files WHERE id=?', $id); if (!$f) fail('File not found.', 404);
    if (!f3_file_ok($f, $u)) fail('You cannot open this file.', 403);
    $base = realpath(storage_dir('uploads')); $src = realpath($base . '/' . $f['path']); if (!$src || !str_starts_with($src, $base . DIRECTORY_SEPARATOR)) fail('File missing.', 404);
    if ($f['mime'] === 'application/pdf' || !function_exists('imagecreatefromstring')) { header('Content-Type: ' . $f['mime']); readfile($src); exit; }
    $dir = $base . '/thumbs'; if (!is_dir($dir)) @mkdir($dir, 0750, true); $t = $dir . '/' . $id . '-' . substr((string)$f['sha256'], 0, 12) . '.jpg';
    if (!is_file($t)) { $im = @imagecreatefromstring((string)file_get_contents($src)); if (!$im) fail('Image unreadable.', 415); $w = imagesx($im); $h = imagesy($im); $s = min(1, 240 / max($w, $h)); $tw = max(1, (int)round($w * $s)); $th = max(1, (int)round($h * $s));
        $o = imagecreatetruecolor($tw, $th); imagefill($o, 0, 0, imagecolorallocate($o, 255, 255, 255)); imagecopyresampled($o, $im, 0, 0, 0, 0, $tw, $th, $w, $h); imagejpeg($o, $t, 78); imagedestroy($o); imagedestroy($im); }
    header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff'); readfile($t); exit;
}
/** Rotate an uploaded image 90° (creates a new file; the original is kept). */
function f3_file_rotate(array $b, array $u): array {
    f3_need($u, 'purchase.upload', 'item.photo'); $f = row('SELECT * FROM dd_files WHERE id=?', (int)($b['id'] ?? 0)); if (!$f || !str_starts_with($f['mime'], 'image/')) fail('Image not found.', 404);
    if (!f3_file_ok($f, $u)) fail('You cannot change this file.', 403);
    $src = storage_dir('uploads') . '/' . $f['path']; $im = @imagecreatefromstring((string)file_get_contents($src)); if (!$im) fail('Image unreadable.');
    $r = imagerotate($im, (int)($b['dir'] ?? 1) < 0 ? 90 : -90, 0); ob_start(); imagejpeg($r, null, 88); $data = (string)ob_get_clean(); imagedestroy($im); imagedestroy($r);
    $nid = store_upload(['data' => base64_encode($data), 'name' => $f['original_name']], $u);
    run('UPDATE f3_bill_files SET file_id=? WHERE file_id=?', $nid, $f['id']); run('UPDATE dd_bk_items SET photo_file_id=? WHERE photo_file_id=?', $nid, $f['id']); run('UPDATE f3_purchase_lines SET photo_file_id=? WHERE photo_file_id=?', $nid, $f['id']);
    return ['ok' => true, 'id' => $nid];
}
/** V21 §7 — Daily Stock Consumption: many items in one save (no POS / KOT needed). Each line is an ordinary "issue"
 *  movement with its own idempotency key, so a double tap or network retry never issues twice. All-or-nothing. */
function f3_stock_consume(array $b, array $u): array {
    f3_need($u, 'stock.issue'); $ck = txt($b['client_key'] ?? '', 48); if (strlen($ck) < 8) fail('Missing submission key. Refresh the page.');
    $lines = array_values(array_filter((array)($b['lines'] ?? []), fn($l) => trim((string)($l['qty'] ?? '')) !== '' && (float)$l['qty'] != 0.0));
    if (!$lines) fail('Enter the quantity used for at least one item.'); if (count($lines) > 200) fail('Too many lines — save in two parts.');
    $reason = f3_text($b['reason'] ?? '', 200) ?: 'Daily kitchen consumption'; $seen = []; $out = [];
    foreach ($lines as $n => $l) { $iid = (int)($l['item_id'] ?? 0); if (isset($seen[$iid])) fail('The same item is listed twice (line ' . ($n + 1) . ').'); $seen[$iid] = 1;
        try { $out[] = f3_stock_manual(['client_key' => $ck . ':' . $iid, 'kind' => 'issue', 'item_id' => $iid, 'qty' => (string)$l['qty'], 'unit' => $l['unit'] ?? null, 'date' => $b['date'] ?? null, 'location' => $b['location'] ?? 'Main Store',
            'reason' => $reason, 'cost_center' => $b['cost_center'] ?? ''], $u)['move_id'] ?? null; }
        catch (RuntimeException $e) { $nm = row('SELECT name FROM dd_bk_items WHERE id=?', $iid)['name'] ?? ('item ' . $iid); throw new RuntimeException($nm . ': ' . $e->getMessage(), $e->getCode() ?: 400); } }
    f3_audit($u, 'stock.consume', ['lines' => count($lines), 'date' => $b['date'] ?? null]);
    return ['ok' => true, 'moves' => count($out)];
}
