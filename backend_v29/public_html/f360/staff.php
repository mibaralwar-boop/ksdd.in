<?php
declare(strict_types=1);
// Finance 360 — Staff master (4 departments: Management, UT, Kitchen, Waiter — Guard is a designation inside Waiter),
// effective-dated salary rates & transfers, and the staff ledger: Advance, Penalty, Instant Overtime, Extra amounts.
// Rules: Advance = recoverable balance (not an expense). Penalty = deducted ONLY in payroll and ONLY after approval.
// Overtime = paid immediately, shown in the salary sheet for information only, never in net salary.
// Every cash/bank/UPI movement is one Books entry (key f360:staff:<id>) so the cashbook is updated exactly once.
require_once __DIR__ . '/core.php';

const F3_TXN_KINDS = [
 'advance' => ['Advance', 'एडवांस'], 'penalty' => ['Penalty', 'जुर्माना'], 'overtime' => ['Overtime (paid now)', 'ओवरटाइम'],
 'extra_now' => ['Extra amount paid now (bonus / tip / incentive)', 'अतिरिक्त भुगतान'], 'addition' => ['Addition in monthly salary', 'वेतन में जोड़'],
 'deduction' => ['Other deduction in monthly salary', 'वेतन से कटौती'], 'opening_advance' => ['Opening advance balance (before Finance 360)', 'पुराना एडवांस'],
 'recovery' => ['Advance recovered in salary', 'एडवांस वसूली'],
];

function f3_departments(): array {
    $d = rows('SELECT id,code,name,name_hi FROM f3_departments ORDER BY sort');
    $g = []; foreach (rows('SELECT id,dept_id,name,name_hi,sort,active FROM f3_designations ORDER BY dept_id,sort,name') as $x) { $x['id'] = (int)$x['id']; $x['active'] = (int)$x['active']; $g[(int)$x['dept_id']][] = $x; }
    $cnt = []; foreach (rows("SELECT f3_desig_id d,COUNT(*) n FROM dd_employees WHERE COALESCE(status,'active')='active' GROUP BY f3_desig_id") as $r) $cnt[(int)$r['d']] = (int)$r['n'];
    foreach ($d as &$x) { $x['id'] = (int)$x['id']; $x['designations'] = array_map(fn($y) => $y + ['staff' => $cnt[$y['id']] ?? 0], $g[$x['id']] ?? []); } unset($x);
    return $d;
}
function f3_designation_save(array $b, array $u): array {
    f3_need($u, 'staff.manage'); $id = (int)($b['id'] ?? 0); $dept = (int)($b['dept_id'] ?? 0);
    if (!isset(F3_DEPTS[$dept])) fail('Choose one of the four departments (Management, UT, Kitchen, Waiter).');
    $name = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($b['name'] ?? ''))), 0, 80); if ($name === '') fail('Designation name is required.');
    if (preg_match('/^gu?a(u)?rd$/i', $name) && $dept !== 4) fail('Guard belongs inside the Waiter department.');
    $dup = row('SELECT id FROM f3_designations WHERE dept_id=? AND name=? AND id<>?', $dept, $name, $id); if ($dup) fail('This designation already exists in that department.', 409);
    if ($id) { $o = row('SELECT * FROM f3_designations WHERE id=?', $id); if (!$o) fail('Not found.', 404); if ((int)$o['dept_id'] !== $dept && row('SELECT 1 FROM dd_employees WHERE f3_desig_id=? LIMIT 1', $id)) fail('Staff use this designation; create a new one in the other department instead.', 409);
        run('UPDATE f3_designations SET dept_id=?,name=?,name_hi=?,sort=?,active=? WHERE id=?', $dept, $name, txt($b['name_hi'] ?? '', 80) ?: null, (int)($b['sort'] ?? 0), empty($b['inactive']) ? 1 : 0, $id); }
    else { run('INSERT INTO f3_designations(dept_id,name,name_hi,sort) VALUES(?,?,?,?)', $dept, $name, txt($b['name_hi'] ?? '', 80) ?: null, (int)($b['sort'] ?? 50)); $id = (int)db()->lastInsertId(); }
    f3_audit($u, 'designation.save', ['id' => $id, 'dept' => $dept, 'name' => $name]);
    return ['ok' => true, 'id' => $id, 'departments' => f3_departments()];
}
function f3_rate_on(int $emp, string $date): ?array { return row('SELECT * FROM f3_salary_rates WHERE employee_id=? AND effective_from<=? ORDER BY effective_from DESC LIMIT 1', $emp, $date); }

/* ------------------------------ balances ------------------------------ */
function f3_staff_balances(int $emp, ?string $exceptPeriod = null): array {
    $b = ['advance_given' => 0, 'advance_recovered' => 0, 'advance_outstanding' => 0, 'penalty_pending_approval' => 0, 'penalty_approved_unapplied' => 0, 'additions_unapplied' => 0, 'deductions_unapplied' => 0, 'ot_unpaid' => 0, 'advance_pending_approval' => 0, 'installment' => 0];
    foreach (rows("SELECT kind,status,amount,applied_period,installment FROM f3_staff_txns WHERE employee_id=?", $emp) as $t) {
        $a = (int)$t['amount']; $ap = $t['applied_period'] && $t['applied_period'] !== $exceptPeriod;
        switch ($t['kind']) {
            case 'advance': case 'opening_advance':
                if (in_array($t['status'], ['approved', 'paid'], true)) { $b['advance_given'] += $a; if ((int)$t['installment'] > 0) $b['installment'] += (int)$t['installment']; }
                elseif ($t['status'] === 'pending') $b['advance_pending_approval'] += $a; break;
            case 'recovery': if ($t['status'] === 'approved' && $ap) $b['advance_recovered'] += $a; break;
            case 'penalty': if ($t['status'] === 'pending') $b['penalty_pending_approval'] += $a; elseif ($t['status'] === 'approved' && !$ap) $b['penalty_approved_unapplied'] += $a; break;
            case 'addition': if ($t['status'] === 'approved' && !$ap) $b['additions_unapplied'] += $a; break;
            case 'deduction': if ($t['status'] === 'approved' && !$ap) $b['deductions_unapplied'] += $a; break;
            case 'overtime': if ($t['status'] === 'unpaid') $b['ot_unpaid'] += $a; break;
        }
    }
    $b['advance_outstanding'] = $b['advance_given'] - $b['advance_recovered'];
    return $b;
}

/* ------------------------------ directory & profile ------------------------------ */
function f3_staff_row(array $e, array $u): array {
    $bank = f3_can($u, 'staff.bank');
    $full = $bank ? f3_dec($e['bank_account_enc'] ?? null) : null;
    $o = ['id' => (int)$e['id'], 'code' => $e['code'], 'name' => $e['name'], 'legal_name' => $e['legal_name'], 'phone' => $e['phone'], 'address' => $e['address'], 'status' => $e['status'] ?: ((int)$e['active'] ? 'active' : 'inactive'),
          'dept_id' => $e['f3_dept_id'] ? (int)$e['f3_dept_id'] : null, 'desig_id' => $e['f3_desig_id'] ? (int)$e['f3_desig_id'] : null, 'dept' => $e['dept_name'] ?? null, 'designation' => $e['desig_name'] ?? null,
          'joined_on' => $e['joined_on'], 'left_on' => $e['left_on'], 'exit_reason' => $e['exit_reason'], 'pay_mode' => $e['pay_mode'], 'salary_basis' => $e['pay_basis'] ?: 'monthly', 'monthly' => (int)$e['salary'], 'notes' => $e['notes'], 'documents' => $e['documents'],
          'bank_name' => $e['bank_name'], 'ifsc' => $bank ? $e['ifsc'] : f3_mask($e['ifsc'], 4), 'account' => $bank ? ($full ?? ($e['account_last4'] ? '••••' . $e['account_last4'] : '')) : ($e['account_last4'] ? '••••' . $e['account_last4'] : ''),
          'upi_id' => $bank ? $e['upi_id'] : ($e['upi_id'] ? f3_mask(explode('@', (string)$e['upi_id'])[0], 2) . '@' . (explode('@', (string)$e['upi_id'])[1] ?? '') : ''), 'bank_visible' => $bank,
          'legacy_section' => $e['section'], 'legacy_designation' => $e['designation'], 'photo_file_id' => $e['photo_file_id'] ? (int)$e['photo_file_id'] : null];
    if (!f3_can($u, 'payroll.view') && !f3_can($u, 'staff.manage')) unset($o['monthly']);
    return $o;
}
function f3_staff_list(array $q, array $u): array {
    f3_need($u, 'staff.view', 'stafftxn.create', 'payroll.prepare');
    $w = ['1=1']; $a = [];
    $st = (string)($q['status'] ?? 'active'); if ($st === 'active') $w[] = "COALESCE(e.status,'active')='active'"; elseif ($st === 'left') $w[] = "COALESCE(e.status,'active')<>'active'";
    if (!empty($q['dept'])) { $w[] = 'e.f3_dept_id=?'; $a[] = (int)$q['dept']; }
    if (!empty($q['desig'])) { $w[] = 'e.f3_desig_id=?'; $a[] = (int)$q['desig']; }
    if (!empty($q['unmapped'])) $w[] = 'e.f3_dept_id IS NULL';
    if (!empty($q['q'])) { $w[] = '(e.name LIKE ? OR e.code LIKE ? OR e.phone LIKE ?)'; $s = '%' . txt($q['q'], 60) . '%'; array_push($a, $s, $s, $s); }
    $rows = rows('SELECT e.*,d.name dept_name,g.name desig_name FROM dd_employees e LEFT JOIN f3_departments d ON d.id=e.f3_dept_id LEFT JOIN f3_designations g ON g.id=e.f3_desig_id WHERE ' . implode(' AND ', $w) . ' ORDER BY d.sort IS NULL,d.sort,g.sort,g.name,e.name', ...$a);
    $ledger = f3_can($u, 'staff.ledger') || f3_can($u, 'payroll.view');
    $out = array_map(function ($e) use ($u, $ledger) { $o = f3_staff_row($e, $u); if ($ledger) { $bal = f3_staff_balances($o['id']); $o['advance_outstanding'] = $bal['advance_outstanding']; $o['pending_approvals'] = $bal['penalty_pending_approval'] + $bal['advance_pending_approval']; $o['ot_unpaid'] = $bal['ot_unpaid']; } return $o; }, $rows);
    $counts = []; foreach (rows("SELECT f3_dept_id d,COUNT(*) n FROM dd_employees WHERE COALESCE(status,'active')='active' GROUP BY f3_dept_id") as $r) $counts[(string)($r['d'] ?? 'none')] = (int)$r['n'];
    return ['staff' => $out, 'counts' => $counts, 'departments' => f3_departments()];
}
function f3_staff_get(int $id, array $u): array {
    f3_need($u, 'staff.view', 'staff.ledger', 'payroll.view');
    $e = row('SELECT e.*,d.name dept_name,g.name desig_name FROM dd_employees e LEFT JOIN f3_departments d ON d.id=e.f3_dept_id LEFT JOIN f3_designations g ON g.id=e.f3_desig_id WHERE e.id=?', $id); if (!$e) fail('Staff not found.', 404);
    $o = ['staff' => f3_staff_row($e, $u)];
    if (f3_can($u, 'payroll.view') || f3_can($u, 'staff.manage')) $o['rates'] = rows('SELECT r.effective_from,r.monthly,r.basis,r.note,r.source,r.created_at,u.name by_name FROM f3_salary_rates r LEFT JOIN dd_users u ON u.id=r.created_by WHERE r.employee_id=? ORDER BY r.effective_from DESC', $id);
    $o['assignments'] = rows('SELECT a.effective_from,d.name dept,g.name designation,a.note,a.created_at FROM f3_assignments a JOIN f3_departments d ON d.id=a.dept_id LEFT JOIN f3_designations g ON g.id=a.desig_id WHERE a.employee_id=? ORDER BY a.effective_from DESC,a.id DESC', $id);
    if (f3_can($u, 'staff.ledger') || f3_can($u, 'payroll.view')) { $o['balances'] = f3_staff_balances($id); $o['ledger'] = f3_staff_ledger($id); }
    return $o;
}
function f3_staff_save(array $b, array $u): array {
    f3_need($u, 'staff.manage'); $id = (int)($b['id'] ?? 0);
    $old = $id ? row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE', $id) : null; if ($id && !$old) fail('Staff not found.', 404);
    $name = name_required($b['name'] ?? '');
    $dept = (int)($b['dept_id'] ?? 0); if (!isset(F3_DEPTS[$dept])) fail('Choose department: Management, UT, Kitchen or Waiter.');
    $desig = row('SELECT * FROM f3_designations WHERE id=?', (int)($b['desig_id'] ?? 0)); if (!$desig || (int)$desig['dept_id'] !== $dept) fail('Choose a designation that belongs to the selected department.');
    if (!(int)$desig['active'] && (!$old || (int)$old['f3_desig_id'] !== (int)$desig['id'])) fail('This designation is inactive.');
    $code = strtoupper(txt($b['code'] ?? '', 40)); if ($code === '') $code = sal_next_code_f3();
    if (row('SELECT id FROM dd_employees WHERE code=? AND id<>?', $code, $id)) fail('Staff code ' . $code . ' is already used.', 409);
    $monthly = f3_paise($b['monthly'] ?? null, 'Basic monthly salary');
    $basis = (string)($b['salary_basis'] ?? 'monthly'); if (!in_array($basis, ['monthly', 'daily'], true)) $basis = 'monthly';
    $join = !empty($b['joined_on']) ? valid_date($b['joined_on']) : null; if (!$join) fail('Joining date is required.');
    $phone = preg_replace('/[^0-9+]/', '', (string)($b['phone'] ?? '')); if ($phone !== '' && !preg_match('/^\+?\d{10,13}$/', $phone)) fail('Phone number looks wrong.');
    $ifsc = strtoupper(txt($b['ifsc'] ?? '', 20)); if ($ifsc !== '' && !str_contains($ifsc, '•') && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) fail('IFSC must look like ABCD0123456.');
    $acct = preg_replace('/\s+/', '', (string)($b['account'] ?? '')); $acctChanged = $acct !== '' && !str_contains($acct, '•');
    if ($acctChanged && !preg_match('/^\d{6,20}$/', $acct)) fail('Bank account number must be 6–20 digits.');
    if (($acctChanged || ($ifsc !== '' && !str_contains($ifsc, '•'))) && $id && !f3_can($u, 'staff.bank')) fail('You cannot change bank details.', 403);
    $upi = txt($b['upi_id'] ?? '', 80); $upiChanged = $upi !== '' && !str_contains($upi, '•'); if ($upiChanged && !preg_match('/^[\w.\-]{2,}@[\w]{2,}$/', $upi)) fail('UPI ID looks wrong (name@bank).');
    $eff = !empty($b['effective_from']) ? valid_date($b['effective_from']) : null;
    $vals = ['name' => $name, 'job' => $desig['name'], 'code' => $code, 'phone' => $phone ?: null, 'legal_name' => txt($b['legal_name'] ?? '', 160) ?: null, 'address' => txt($b['address'] ?? '', 400) ?: null,
             'pay_basis' => $basis, 'pay_mode' => in_array($b['pay_mode'] ?? '', ['Cash', 'Bank', 'UPI'], true) ? $b['pay_mode'] : 'Cash', 'bank_name' => txt($b['bank_name'] ?? '', 80) ?: null,
             'notes' => txt($b['notes'] ?? '', 2000) ?: null, 'documents' => txt($b['documents'] ?? '', 2000) ?: null, 'joined_on' => $join, 'f3_dept_id' => $dept, 'f3_desig_id' => (int)$desig['id'],
             'designation' => $desig['name'], 'section' => ['1' => 'Manager', '2' => 'UT Staff', '3' => 'Kitchen', '4' => 'Waiter'][(string)$dept], 'updated_at' => gmdate('Y-m-d H:i:s')];
    if (!$id || f3_can($u, 'staff.bank')) { if ($ifsc === '' || !str_contains($ifsc, '•')) $vals['ifsc'] = $ifsc ?: null; }
    if (!empty($b['photo']['data'])) { $pf = f3_upload($b['photo'], $u); if ($pf && !str_starts_with((string)row('SELECT mime FROM dd_files WHERE id=?', $pf)['mime'], 'image/')) fail('Photo must be an image.'); $vals['photo_file_id'] = $pf; }
    if ($acctChanged) { $vals['bank_account_enc'] = f3_enc($acct); $vals['account_last4'] = substr($acct, -4); }
    if ($upiChanged) $vals['upi_id'] = $upi; elseif ($upi === '' && (!$id || f3_can($u, 'staff.bank'))) $vals['upi_id'] = null;
    if (!$id) {
        $vals += ['salary' => $monthly, 'status' => 'active', 'active' => 1, 'created_by' => $u['id']];
        run('INSERT INTO dd_employees(' . implode(',', array_keys($vals)) . ') VALUES(' . implode(',', array_fill(0, count($vals), '?')) . ')', ...array_values($vals));
        $id = (int)db()->lastInsertId();
        run("INSERT INTO f3_salary_rates(employee_id,effective_from,monthly,basis,note,created_by) VALUES(?,?,?,?,?,?)", $id, $eff ?? $join, $monthly, $basis, 'Joining salary', $u['id']);
        run('INSERT INTO f3_assignments(employee_id,dept_id,desig_id,effective_from,note,created_by) VALUES(?,?,?,?,?,?)', $id, $dept, $desig['id'], $join, 'Joined', $u['id']);
        f3_audit($u, 'staff.create', ['id' => $id, 'code' => $code, 'dept' => $dept, 'desig' => $desig['name'], 'monthly' => $monthly]);
    } else {
        if ($monthly !== (int)$old['salary'] || $basis !== ($old['pay_basis'] ?: 'monthly')) {
            if (!$eff) fail('Salary changed: choose the date from which the new salary applies.');
            if (!f3_can($u, 'payroll.approve') && !f3_can($u, 'staff.manage')) fail('You cannot change salary.', 403);
            $locked = row("SELECT period FROM f3_pay_periods WHERE period>=? AND status IN ('approved','locked','paid','partially_paid') ORDER BY period LIMIT 1", substr($eff, 0, 7));
            if ($locked) fail('Salary for ' . $locked['period'] . ' is already approved. Choose an effective date after it, or unlock that month first.', 409);
            run('INSERT INTO f3_salary_rates(employee_id,effective_from,monthly,basis,note,created_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE monthly=VALUES(monthly),basis=VALUES(basis),note=VALUES(note),created_by=VALUES(created_by)', $id, $eff, $monthly, $basis, txt($b['rate_note'] ?? 'Salary revision', 250), $u['id']);
            $cur = f3_rate_on($id, f3_business_date()); $vals['salary'] = $cur ? (int)$cur['monthly'] : $monthly;
        }
        if ($dept !== (int)$old['f3_dept_id'] || (int)$desig['id'] !== (int)$old['f3_desig_id']) run('INSERT INTO f3_assignments(employee_id,dept_id,desig_id,effective_from,note,created_by) VALUES(?,?,?,?,?,?)', $id, $dept, $desig['id'], $eff ?? f3_business_date(), txt($b['transfer_note'] ?? 'Transfer / designation change', 250), $u['id']);
        $set = implode(',', array_map(fn($k) => "$k=?", array_keys($vals)));
        run("UPDATE dd_employees SET $set WHERE id=?", ...[...array_values($vals), $id]);
        $chg = []; foreach ($vals as $k => $v) if (array_key_exists($k, $old) && (string)$old[$k] !== (string)$v && !in_array($k, ['updated_at', 'bank_account_enc'], true)) $chg[$k] = in_array($k, ['ifsc', 'upi_id'], true) ? 'changed' : ['from' => $old[$k], 'to' => $v];
        if ($acctChanged) $chg['bank_account'] = 'changed (last4 ' . substr($acct, -4) . ')';
        f3_audit($u, 'staff.update', ['id' => $id, 'changes' => $chg]);
    }
    return ['ok' => true, 'id' => $id] + f3_staff_get($id, $u);
}
function sal_next_code_f3(): string { $n = 0; foreach (rows("SELECT code FROM dd_employees WHERE code LIKE 'KS%'") as $r) if (preg_match('/(\d+)$/', (string)$r['code'], $m)) $n = max($n, (int)$m[1]); return 'KS' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT); }
function f3_staff_exit(array $b, array $u): array {
    f3_need($u, 'staff.manage'); $id = (int)($b['id'] ?? 0); $e = row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE', $id); if (!$e) fail('Staff not found.', 404);
    if (!empty($b['rejoin'])) { run("UPDATE dd_employees SET status='active',active=1,left_on=NULL,exit_reason=NULL WHERE id=?", $id); f3_audit($u, 'staff.rejoin', ['id' => $id]); return ['ok' => true]; }
    $d = valid_date($b['left_on'] ?? ''); if ($e['joined_on'] && $d < $e['joined_on']) fail('Exit date is before joining date.');
    run("UPDATE dd_employees SET status='left',active=0,left_on=?,exit_reason=? WHERE id=?", $d, f3_text($b['reason'] ?? '', 250, 'Reason'), $id);
    f3_audit($u, 'staff.exit', ['id' => $id, 'left_on' => $d]);
    $bal = f3_staff_balances($id);
    return ['ok' => true, 'warning' => $bal['advance_outstanding'] > 0 ? 'This person still has ' . f3_rs($bal['advance_outstanding']) . ' advance outstanding. Recover it in the final salary.' : null];
}

/* ------------------------------ staff transactions ------------------------------ */
function f3_staff_txn_create(array $b, array $u): array {
    f3_need($u, 'stafftxn.create');
    $kind = (string)($b['kind'] ?? ''); if (!isset(F3_TXN_KINDS[$kind]) || $kind === 'recovery') fail('Choose Advance, Penalty, Overtime or Extra amount.');
    if ($kind === 'opening_advance') f3_need($u, 'stafftxn.approve');
    return idem('f3-stxn', $b, $u, function () use ($b, $u, $kind) {
        $emp = row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE', (int)($b['employee_id'] ?? 0)); if (!$emp) fail('Choose the staff member.');
        $d = valid_date($b['date'] ?? f3_business_date()); if ($d > f3_business_date()) fail('Future dates are not allowed.');
        $amt = f3_paise($b['amount'] ?? null, 'Amount'); if ($amt <= 0) fail('Amount must be more than zero.');
        $reason = f3_text($b['reason'] ?? '', 500, 'Reason');
        $mode = null; $paidNow = in_array($kind, ['advance', 'extra_now'], true) || ($kind === 'overtime' && ($b['paid'] ?? '1') !== '0' && ($b['paid'] ?? true) !== false);
        if ($paidNow) { $mode = (string)($b['mode'] ?? ''); if (!in_array($mode, ['Cash', 'Bank', 'UPI'], true)) fail('Choose payment mode Cash / Bank / UPI.'); }
        $approver = f3_can($u, 'stafftxn.approve');
        $status = match ($kind) {
            'advance' => $approver ? 'paid' : 'pending', 'penalty', 'deduction' => ($approver && !empty($b['approve_now'])) ? 'approved' : 'pending', 'addition' => $approver ? 'approved' : 'pending',
            'overtime' => $paidNow ? 'paid' : 'unpaid', 'extra_now' => $approver ? 'paid' : 'pending', 'opening_advance' => 'approved', default => 'pending' };
        if ($kind === 'penalty' && $status === 'approved' && trim((string)($b['policy'] ?? '')) === '') fail('Penalty approval needs the rule / policy reference.');
        if ($paidNow && in_array($status, ['paid'], true)) f3_day_guard($d, $u, 'staff');
        if ($kind === 'advance') { // Control: advance balance above a % of monthly salary needs a written reason (approver) — protects against unrecoverable advances.
            $lim = f3_set_int('f3_advance_limit_pct'); $sal = (int)$emp['salary'];
            if ($lim > 0 && $sal > 0) { $after = f3_staff_balances((int)$emp['id'])['advance_outstanding'] + $amt; $cap = intdiv($sal * $lim, 100);
                if ($after > $cap && trim((string)($b['limit_reason'] ?? '')) === '') throw new F3Conflict('Advance balance would become ' . f3_rs($after) . ', above the limit of ' . $lim . '% of salary (' . f3_rs($cap) . '). Write the reason for allowing it.', ['needs' => 'limit_reason', 'after' => $after, 'cap' => $cap]);
                if ($after > $cap) $reason .= ' · Above advance limit: ' . txt($b['limit_reason'], 200); } }
        $hours = isset($b['hours']) && $b['hours'] !== '' ? number_format(min(99, max(0, (float)$b['hours'])), 2, '.', '') : null;
        $inst = $kind === 'advance' || $kind === 'opening_advance' ? (f3_paise($b['installment'] ?? '', 'Monthly recovery', false) ?: null) : null;
        if ($inst && $inst > $amt) fail('Monthly recovery cannot exceed the advance.');
        $fileId = isset($b['file']) ? f3_upload($b['file'], $u) : null;
        $no = f3_seq('stafftxn', 'ST');
        run('INSERT INTO f3_staff_txns(txn_no,employee_id,kind,date,amount,mode,status,reason,reference,hours,installment,file_id,period,client_key,created_by,decided_by,decided_at,decision_note) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            $no, $emp['id'], $kind, $d, $amt, $mode, $status, $reason, txt(($b['reference'] ?? '') . (($b['policy'] ?? '') !== '' ? ' · Policy: ' . $b['policy'] : ''), 120) ?: null, $hours, $inst, $fileId,
            in_array($kind, ['addition', 'deduction'], true) && !empty($b['period']) ? valid_month($b['period']) : null, txt($b['client_key'] ?? '', 64) ?: null, $u['id'],
            in_array($status, ['approved', 'paid'], true) && $kind !== 'overtime' ? $u['id'] : null, in_array($status, ['approved', 'paid'], true) && $kind !== 'overtime' ? gmdate('Y-m-d H:i:s') : null, null);
        $id = (int)db()->lastInsertId();
        if ($status === 'paid') f3_staff_txn_post($id, $u);
        if ($status === 'pending') f3_notify('staff-approval', 'stxn-' . $id, F3_TXN_KINDS[$kind][0] . ' for ' . $emp['name'] . ' needs approval', f3_rs($amt) . ' · ' . $reason, 'stafftxn.approve', 'stafftxn:' . $id);
        if ($status === 'unpaid') f3_notify('ot-unpaid', 'stxn-' . $id, 'Overtime not yet paid: ' . $emp['name'], f3_rs($amt) . ' · settle it from the staff ledger', 'stafftxn.create', 'stafftxn:' . $id);
        f3_audit($u, 'stafftxn.create', ['id' => $id, 'no' => $no, 'kind' => $kind, 'employee' => (int)$emp['id'], 'amount' => $amt, 'status' => $status, 'mode' => $mode]);
        return ['ok' => true, 'id' => $id, 'txn_no' => $no, 'status' => $status, 'message' => F3_TXN_KINDS[$kind][0] . ' saved (' . $status . ').'];
    });
}
/** Cash/bank posting of a paid staff transaction — exactly once (unique Books key). */
function f3_staff_txn_post(int $id, array $u): void {
    $t = row('SELECT * FROM f3_staff_txns WHERE id=? FOR UPDATE', $id); if (!$t || !$t['mode']) return;
    if ($t['bk_txn_id']) return;
    $cat = ['advance' => 'Staff Advance (recoverable)', 'overtime' => 'Overtime Wages', 'extra_now' => 'Other Staff Sallery'][$t['kind']] ?? null; if (!$cat) return;
    $emp = row('SELECT name FROM dd_employees WHERE id=?', $t['employee_id']);
    $bk = f3_bk_post('f360:staff:' . $id, ['date' => $t['date'], 'type' => 'expense', 'category' => $cat, 'party_id' => f3_staff_party((int)$t['employee_id']), 'total' => (int)$t['amount'], 'account' => f3_mode_acct($t['mode']), 'description' => F3_TXN_KINDS[$t['kind']][0] . ' · ' . $emp['name'] . ' · ' . $t['txn_no'] . ' · ' . $t['reason']], $u);
    run('UPDATE f3_staff_txns SET bk_txn_id=? WHERE id=?', $bk, $id);
}
function f3_staff_txn_decide(array $b, array $u): array {
    f3_need($u, 'stafftxn.approve'); $t = row('SELECT * FROM f3_staff_txns WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0));
    if (!$t || $t['status'] !== 'pending') fail('Entry not found or already decided.', 404);
    $ok = !empty($b['approve']); $note = f3_text($b['note'] ?? '', 250, $ok ? null : 'Reason for rejecting');
    if ($ok && $t['kind'] === 'penalty' && trim((string)($b['policy'] ?? $note)) === '') fail('Write the rule / policy this penalty is based on.');
    $new = !$ok ? 'rejected' : (in_array($t['kind'], ['advance', 'extra_now'], true) ? 'paid' : 'approved');
    if ($new === 'paid') f3_day_guard($t['date'], $u, 'staff');
    run('UPDATE f3_staff_txns SET status=?,decided_by=?,decided_at=UTC_TIMESTAMP(),decision_note=? WHERE id=?', $new, $u['id'], $note ?: (string)($b['policy'] ?? '') ?: null, $t['id']);
    if ($new === 'paid') f3_staff_txn_post((int)$t['id'], $u);
    f3_notify_done('stxn-' . $t['id']);
    f3_audit($u, 'stafftxn.decide', ['id' => (int)$t['id'], 'status' => $new]);
    return ['ok' => true, 'status' => $new];
}
function f3_staff_txn_settle(array $b, array $u): array { // pay an overtime that was recorded as unpaid
    f3_need($u, 'stafftxn.create'); $t = row('SELECT * FROM f3_staff_txns WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0));
    if (!$t || $t['kind'] !== 'overtime' || $t['status'] !== 'unpaid') fail('Only unpaid overtime can be settled.', 409);
    $mode = (string)($b['mode'] ?? ''); if (!in_array($mode, ['Cash', 'Bank', 'UPI'], true)) fail('Choose payment mode.');
    $d = valid_date($b['date'] ?? f3_business_date()); f3_day_guard($d, $u, 'staff');
    run("UPDATE f3_staff_txns SET status='paid',mode=?,date=?,decision_note=CONCAT(COALESCE(decision_note,''),?) WHERE id=?", $mode, $d, ' Settled ' . $d . ' (recorded as unpaid on ' . $t['date'] . ')', $t['id']);
    f3_staff_txn_post((int)$t['id'], $u); f3_notify_done('stxn-' . $t['id']);
    f3_audit($u, 'stafftxn.settle', ['id' => (int)$t['id'], 'mode' => $mode]);
    return ['ok' => true];
}
function f3_staff_txn_reverse(array $b, array $u): array {
    f3_need($u, 'stafftxn.approve'); $t = row('SELECT * FROM f3_staff_txns WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0));
    if (!$t || in_array($t['status'], ['reversed', 'rejected'], true) || $t['kind'] === 'recovery') fail('Entry cannot be reversed.', 409);
    if ($t['applied_period']) fail('This entry was applied in salary ' . $t['applied_period'] . '. Unlock that salary month first.', 409);
    if (in_array($t['kind'], ['advance', 'opening_advance'], true)) { $bal = f3_staff_balances((int)$t['employee_id']); if ($bal['advance_outstanding'] - (int)$t['amount'] < 0) fail('Part of this advance has already been recovered in salary; reversing it would make the balance negative.', 409); }
    $why = f3_text($b['reason'] ?? '', 250, 'Reason');
    if ($t['bk_txn_id']) { f3_day_guard(row('SELECT date FROM dd_bk_txns WHERE id=?', $t['bk_txn_id'])['date'], $u, 'staff'); f3_bk_void((int)$t['bk_txn_id'], 'Staff entry ' . $t['txn_no'] . ' reversed: ' . $why, $u); }
    run("UPDATE f3_staff_txns SET status='reversed',decision_note=? WHERE id=?", 'Reversed by ' . $u['name'] . ': ' . $why, $t['id']);
    f3_notify_done('stxn-' . $t['id']);
    f3_audit($u, 'stafftxn.reverse', ['id' => (int)$t['id'], 'reason' => $why]);
    return ['ok' => true];
}
function f3_staff_txn_list(array $q, array $u): array {
    f3_need($u, 'staff.ledger', 'stafftxn.create', 'stafftxn.approve');
    $w = ['1=1']; $a = [];
    if (!empty($q['employee_id'])) { $w[] = 't.employee_id=?'; $a[] = (int)$q['employee_id']; }
    if (!empty($q['kind'])) { $w[] = 't.kind=?'; $a[] = txt($q['kind'], 24); }
    if (!empty($q['status'])) { $w[] = 't.status=?'; $a[] = txt($q['status'], 20); }
    if (!empty($q['from'])) { $w[] = 't.date>=?'; $a[] = valid_date($q['from']); }
    if (!empty($q['to'])) { $w[] = 't.date<=?'; $a[] = valid_date($q['to']); }
    $r = rows("SELECT t.*,e.name emp_name,e.code emp_code,c.name created_by_name,d.name decided_by_name FROM f3_staff_txns t JOIN dd_employees e ON e.id=t.employee_id LEFT JOIN dd_users c ON c.id=t.created_by LEFT JOIN dd_users d ON d.id=t.decided_by WHERE " . implode(' AND ', $w) . ' ORDER BY t.date DESC,t.id DESC LIMIT 1000', ...$a);
    foreach ($r as &$x) { $x['amount'] = (int)$x['amount']; $x['id'] = (int)$x['id']; $x['kind_label'] = F3_TXN_KINDS[$x['kind']][0] ?? $x['kind']; unset($x['client_key']); } unset($x);
    return ['rows' => $r];
}
/** Chronological staff ledger with running "staff owes / restaurant owes" balance (advances are receivable; salary due is payable). */
function f3_staff_ledger(int $emp): array {
    $L = [];
    foreach (rows("SELECT * FROM f3_staff_txns WHERE employee_id=? ORDER BY date,id", $emp) as $t) {
        $a = (int)$t['amount']; $eff = 0; $info = false;
        if (in_array($t['status'], ['approved', 'paid'], true)) switch ($t['kind']) {
            case 'advance': case 'opening_advance': $eff = $a; break;           // staff owes more
            case 'recovery': $eff = -$a; break;                                  // recovered from salary
            default: $info = true;                                               // penalties/additions affect payroll lines; OT/extra are settled immediately
        } else $info = true;
        $L[] = ['date' => $t['date'], 'type' => F3_TXN_KINDS[$t['kind']][0] ?? $t['kind'], 'kind' => $t['kind'], 'ref' => $t['txn_no'], 'amount' => $a, 'mode' => $t['mode'], 'status' => $t['status'], 'reason' => $t['reason'], 'advance_effect' => $eff, 'info_only' => $info, 'period' => $t['applied_period'] ?: $t['period'], 'id' => (int)$t['id']];
    }
    foreach (rows("SELECT l.period,l.gross_earned,l.net_payable,l.carried_forward,l.state,p.status FROM f3_pay_lines l JOIN f3_pay_periods p ON p.period=l.period WHERE l.employee_id=? AND p.status IN ('approved','locked','partially_paid','paid')", $emp) as $p)
        $L[] = ['date' => f3_month_last($p['period']), 'type' => 'Salary ' . $p['period'] . ' (net payable)', 'kind' => 'salary', 'ref' => $p['period'], 'amount' => (int)$p['net_payable'], 'status' => $p['status'], 'advance_effect' => 0, 'info_only' => false, 'period' => $p['period']];
    foreach (rows('SELECT * FROM f3_pay_payments WHERE employee_id=? ORDER BY date,id', $emp) as $p)
        $L[] = ['date' => $p['date'], 'type' => 'Salary payment ' . $p['period'] . ((int)$p['voided'] ? ' (cancelled)' : ''), 'kind' => 'salary_payment', 'ref' => $p['pay_no'], 'amount' => (int)$p['amount'], 'mode' => $p['mode'], 'status' => (int)$p['voided'] ? 'voided' : 'paid', 'advance_effect' => 0, 'info_only' => (bool)(int)$p['voided'], 'period' => $p['period']];
    usort($L, fn($a, $b) => [$a['date'], $a['kind'] === 'salary' ? 1 : 0] <=> [$b['date'], $b['kind'] === 'salary' ? 1 : 0]);
    $run = 0; foreach ($L as &$x) { $run += $x['advance_effect']; $x['advance_balance'] = $run; } unset($x);
    // Legacy Salary Register history (read-only reference, never re-applied).
    $legacy = []; try { foreach (rows('SELECT month,kind,SUM(amount) n FROM dd_sal_entries WHERE employee_id=? AND voided=0 GROUP BY month,kind ORDER BY month', $emp) as $r) $legacy[] = ['month' => $r['month'], 'kind' => $r['kind'], 'amount' => (int)$r['n']]; } catch (PDOException $e) {}
    return ['entries' => array_reverse($L), 'legacy_salary_register' => $legacy];
}

/** Bulk-fill missing joining dates (e.g. staff imported from the Excel register). Only fills NULL dates; audited. */
function f3_staff_bulk_join(array $b, array $u): array {
    f3_need($u, 'staff.manage'); $d = valid_date($b['joined_on'] ?? ''); $ids = array_values(array_filter(array_map('intval', (array)($b['ids'] ?? []))));
    if (!$ids || count($ids) > 500) fail('Choose 1–500 staff.');
    $n = 0; foreach ($ids as $id) { $n += run('UPDATE dd_employees SET joined_on=? WHERE id=? AND joined_on IS NULL', $d, $id)->rowCount();
        run("UPDATE f3_salary_rates SET effective_from=? WHERE employee_id=? AND source='migration' AND effective_from='2000-01-01'", $d, $id); }
    f3_audit($u, 'staff.bulk_join', ['date' => $d, 'ids' => $ids, 'updated' => $n]);
    return ['ok' => true, 'updated' => $n];
}

/** Correct one person's joining date (the usual reason someone is missing from a salary sheet). Audited; salary history is untouched. */
function f3_staff_joining_fix(array $b, array $u): array {
    f3_need($u, 'staff.manage'); $e = row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0)); if (!$e) fail('Staff not found.', 404);
    $d = valid_date($b['joined_on'] ?? ''); if ($d > f3_business_date()) fail('Joining date cannot be in the future.');
    if ($e['left_on'] && $d > $e['left_on']) fail('Joining date is after the exit date.');
    if (row("SELECT 1 FROM f3_pay_lines l JOIN f3_pay_periods p ON p.period=l.period WHERE l.employee_id=? AND p.status IN ('approved','locked') AND l.period < ? LIMIT 1", $e['id'], substr($d, 0, 7))) fail('An approved salary month exists before this date — unlock it first or choose an earlier date.', 409);
    run('UPDATE dd_employees SET joined_on=?,updated_at=UTC_TIMESTAMP() WHERE id=?', $d, $e['id']);
    run('UPDATE f3_salary_rates SET effective_from=? WHERE employee_id=? AND effective_from=? AND note=?', $d, $e['id'], $e['joined_on'], 'Joining salary');
    f3_audit($u, 'staff.joining_fix', ['id' => (int)$e['id'], 'from' => $e['joined_on'], 'to' => $d]);
    return ['ok' => true];
}
