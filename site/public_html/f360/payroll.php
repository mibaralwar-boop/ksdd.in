<?php
declare(strict_types=1);
// Finance 360 — Monthly Smart Salary Wizard, salary sheets, approval/lock, payments, payslips.
// ONE pay-day model (documented in ACCOUNTING_RULES.md) — leave is never deducted twice:
//   Eligible days = days employed in the month (joining / leaving part-month).
//   Paid leave used = min(Leave taken, Paid leave allowed this month).
//   Leave encashed = Paid leave allowed − Paid leave used (only when setting f3_leave_encash = 1, as in the existing Excel sheet)
//   Payable days = Present + Half days × 0.5 + Paid leave used + Leave encashed + Other paid leave  (≤ divisor + encashed)
//   Gross base   = Monthly basic × Payable days ÷ Divisor (calendar days 28/29/30/31, or fixed 30 — setting)
//                  [daily-rate staff: Daily rate × Payable days]
//   Gross earned = Gross base + approved payroll additions + other earnings
//   Net payable  = Gross earned − approved penalties − advance recovered − other deductions
//   Balance      = Net payable + carried forward − salary paid for this month
//   Overtime already paid is shown for information ONLY; it is never in gross, deductions, net or balance.
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/staff.php';

const F3_LINE_STATES = ['pending', 'draft', 'done', 'skipped', 'later', 'excluded'];

function f3_divisor(string $p, string $mode): int { return $mode === 'fixed30' ? 30 : days_in_month($p); }
function f3_eligible_days(array $e, string $p): int {
    $a = $p . '-01'; $z = f3_month_last($p);
    $from = ($e['joined_on'] && $e['joined_on'] > $a) ? $e['joined_on'] : $a; $to = ($e['left_on'] && $e['left_on'] < $z) ? $e['left_on'] : $z;
    if ($from > $to) return 0; return (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
}
/** Pure, deterministic calculation in paise and hundredth-days. Throws on invalid input. */
function f3_pay_calc(array $in, array $c): array {
    $h = fn($k) => f3_hund((string)($in[$k] ?? '0'));
    $present = $h('present'); $half = $h('half_days'); $leave = $h('leave_taken'); $allowed = $h('paid_leave_allowed'); $other = $h('other_paid_leave');
    $elig = (int)$c['eligible_days'] * 100;
    if ($present + $half + $leave + $other > $elig) fail('Present + half days + leave + other paid leave = ' . (($present + $half + $leave + $other) / 100) . ' days, but only ' . ($elig / 100) . ' day(s) are available this month for this person.', 422);
    $hv = (int)round((float)($c['half_value'] ?? 0.5) * 100);
    $paidLeave = min($leave, $allowed);
    // Leave encashment (as in the existing KSDD salary sheet): the part of this month's paid-leave allowance that was NOT taken is paid as extra days.
    $encash = !empty($c['encash']) ? max(0, $allowed - $paidLeave) : 0;
    $payable = $present + intdiv($half * $hv, 100) + $paidLeave + $other + $encash;
    $cap = (int)$c['divisor'] * 100 + $encash; $capped = false; if ($payable > $cap) { $payable = $cap; $capped = true; }
    $unaccounted = $elig - ($present + $half + $leave + $other);
    $unpaid = ($leave - $paidLeave) + intdiv($half * (100 - $hv), 100) + $unaccounted;
    $base = ($c['basis'] ?? 'monthly') === 'daily' ? intdiv((int)$c['monthly'] * $payable + 50, 100) : prorate_monthly((int)$c['monthly'], $payable, (int)$c['divisor']);
    $otherE = (int)($in['other_earnings'] ?? 0); $otherD = (int)($in['other_deductions'] ?? 0);
    $gross = $base + (int)$c['additions'] + $otherE;
    $pen = (int)$c['penalties'];
    $maxRec = max(0, min((int)$c['advance_outstanding'], $gross - $pen - $otherD));
    $rec = array_key_exists('advance_recovery', $in) && $in['advance_recovery'] !== null ? (int)$in['advance_recovery'] : min($maxRec, (int)($c['installment'] ?? 0) > 0 ? (int)$c['installment'] : $maxRec);
    if ($rec > (int)$c['advance_outstanding']) fail('Advance recovery ' . f3_rs($rec) . ' is more than the outstanding advance ' . f3_rs((int)$c['advance_outstanding']) . '.', 422);
    if ($rec < 0) fail('Advance recovery cannot be negative.', 422);
    $net = $gross - $pen - $rec - $otherD;
    if ($net < 0) fail('Deductions (' . f3_rs($pen + $rec + $otherD) . ') are more than earnings (' . f3_rs($gross) . '). Reduce the advance recovery or other deductions.', 422);
    $dedPct = $gross > 0 ? round(($pen + $rec + $otherD) * 100 / $gross, 1) : 0.0;
    return ['eligible_days' => $elig / 100.0, 'present' => $present / 100.0, 'half_days' => $half / 100.0, 'leave_taken' => $leave / 100.0, 'paid_leave_allowed' => $allowed / 100.0, 'paid_leave_used' => $paidLeave / 100.0, 'leave_encashed' => $encash / 100.0, 'encash_policy' => !empty($c['encash']), 'other_paid_leave' => $other / 100.0,
            'unpaid_leave' => $unpaid / 100.0, 'unaccounted_days' => $unaccounted / 100.0, 'payable_days' => $payable / 100.0, 'payable_capped' => $capped, 'divisor' => (int)$c['divisor'], 'monthly' => (int)$c['monthly'], 'basis' => $c['basis'] ?? 'monthly',
            'gross_base' => $base, 'additions' => (int)$c['additions'], 'other_earnings' => $otherE, 'gross_earned' => $gross, 'penalties' => $pen, 'advance_recovery' => $rec, 'advance_recovery_max' => $maxRec, 'other_deductions' => $otherD,
            'net_payable' => $net, 'ot_paid_info' => (int)$c['ot_paid'], 'carried_forward' => (int)$c['carried_forward'], 'paid' => (int)($c['paid'] ?? 0), 'balance' => $net + (int)$c['carried_forward'] - (int)($c['paid'] ?? 0), 'deduction_pct' => $dedPct,
            'advance_outstanding_before' => (int)$c['advance_outstanding'], 'advance_outstanding_after' => (int)$c['advance_outstanding'] - $rec];
}
/** V19 rule (controlling): daily salary = monthly basic ÷ ACTUAL calendar days of the month (28/29/30/31 — never 26/30 fixed).
 *  Paid days = present + paid leave + ½ × half days (+ weekly rest / holiday days when the owner's setting says they are paid).
 *  Earned = basic × paid days ÷ calendar days (rounded half-up to the paisa, once). Net = earned + approved ledger additions − chosen approved penalties − advance recovery − approved ledger deductions.
 *  For a full month present + absent + half + paid leave + rest = calendar days; for someone who joined / left mid-month the same total = their eligible days (divisor stays the full month). */
function f3_pay_calc_v19(array $in, array $c, bool $strict = true): array {
    $h = fn($k) => f3_hund((string)($in[$k] ?? '0'));
    $P = $h('present'); $A = $h('absent'); $H = $h('half_days'); $L = $h('paid_leave'); $R = $h('rest_days');
    $elig = (int)$c['eligible_days'] * 100; $days = (int)$c['days']; $sum = $P + $A + $H + $L + $R;
    if ($strict && $sum !== $elig) fail('Days do not add up: present ' . ($P / 100) . ' + absent ' . ($A / 100) . ' + half ' . ($H / 100) . ' + paid leave ' . ($L / 100) . ($R ? ' + rest ' . ($R / 100) : '') . ' = ' . ($sum / 100) . ', but ' . ($elig / 100) . ' day(s) must be accounted for' . ($elig < $days * 100 ? ' (joined / left during the month)' : ' (' . $days . ' calendar days)') . '.', 422);
    if ($sum > $elig) fail('Days entered (' . ($sum / 100) . ') are more than the ' . ($elig / 100) . ' day(s) available this month.', 422);
    $restPaid = !empty($c['rest_paid']);
    $eq = $P + $L + intdiv($H, 2) + ($restPaid ? $R : 0);
    $base = ($c['basis'] ?? 'monthly') === 'daily' ? intdiv((int)$c['monthly'] * $eq + 50, 100) : prorate_monthly((int)$c['monthly'], $eq, $days);
    $sel = $in['penalty_ids'] ?? null; $pen = 0; $chosen = [];
    foreach ((array)($c['penalty_items'] ?? []) as $pi) if ($sel === null || in_array((int)$pi['id'], array_map('intval', (array)$sel), true)) { $pen += (int)$pi['amount']; $chosen[] = (int)$pi['id']; }
    $gross = $base + (int)$c['additions']; $ded = (int)($c['deductions_ledger'] ?? 0);
    $maxRec = max(0, min((int)$c['advance_outstanding'], $gross - $pen - $ded));
    $rec = array_key_exists('advance_recovery', $in) && $in['advance_recovery'] !== null ? (int)$in['advance_recovery'] : min($maxRec, (int)($c['installment'] ?? 0) > 0 ? (int)$c['installment'] : $maxRec);
    if ($rec > (int)$c['advance_outstanding']) fail('Advance recovery ' . f3_rs($rec) . ' is more than the outstanding advance ' . f3_rs((int)$c['advance_outstanding']) . '.', 422);
    if ($rec < 0) fail('Advance recovery cannot be negative.', 422);
    $net = $gross - $pen - $rec - $ded;
    if ($net < 0) fail('Deductions (' . f3_rs($pen + $rec + $ded) . ') are more than earnings (' . f3_rs($gross) . '). Reduce the advance recovery or untick a penalty.', 422);
    $dedPct = $gross > 0 ? round(($pen + $rec + $ded) * 100 / $gross, 1) : 0.0;
    return ['mode' => 'v19', 'eligible_days' => $elig / 100.0, 'calendar_days' => $days, 'present' => $P / 100.0, 'absent' => $A / 100.0, 'half_days' => $H / 100.0, 'paid_leave' => $L / 100.0, 'rest_days' => $R / 100.0, 'rest_paid' => $restPaid,
            'paid_leave_used' => $L / 100.0, 'leave_taken' => $L / 100.0, 'paid_leave_allowed' => $L / 100.0, 'leave_encashed' => 0.0, 'other_paid_leave' => ($restPaid ? $R : 0) / 100.0,
            'unpaid_leave' => ($A + intdiv($H, 2) + ($restPaid ? 0 : $R)) / 100.0, 'unaccounted_days' => ($elig - $sum) / 100.0, 'payable_days' => $eq / 100.0, 'payable_capped' => false, 'divisor' => $days, 'daily_rate' => ($c['basis'] ?? 'monthly') === 'daily' ? (int)$c['monthly'] : intdiv((int)$c['monthly'] * 2 + $days, 2 * $days),
            'monthly' => (int)$c['monthly'], 'basis' => $c['basis'] ?? 'monthly', 'gross_base' => $base, 'additions' => (int)$c['additions'], 'other_earnings' => 0, 'gross_earned' => $gross, 'penalties' => $pen, 'penalty_ids' => $chosen,
            'advance_recovery' => $rec, 'advance_recovery_max' => $maxRec, 'other_deductions' => $ded, 'net_payable' => $net, 'ot_paid_info' => (int)$c['ot_paid'], 'carried_forward' => (int)$c['carried_forward'], 'paid' => (int)($c['paid'] ?? 0),
            'balance' => $net + (int)$c['carried_forward'] - (int)($c['paid'] ?? 0), 'deduction_pct' => $dedPct, 'advance_outstanding_before' => (int)$c['advance_outstanding'], 'advance_outstanding_after' => (int)$c['advance_outstanding'] - $rec];
}
function f3_is_v19(array $per): bool { return ($per['calc_mode'] ?? 'v16') === 'v19'; }
function f3_period(string $p, bool $lock = false): ?array { return row('SELECT * FROM f3_pay_periods WHERE period=?' . ($lock ? ' FOR UPDATE' : ''), $p); }
function f3_carried_forward(int $emp, string $p): array {
    $prev = f3_prev_month($p);
    $pp = f3_period($prev);
    if ($pp && in_array($pp['status'], ['approved', 'locked'], true)) { $l = row('SELECT net_payable,carried_forward FROM f3_pay_lines WHERE period=? AND employee_id=? AND state<>\'excluded\'', $prev, $emp); if ($l) return [(int)$l['net_payable'] + (int)$l['carried_forward'] - f3_paid($emp, $prev), 'finance360:' . $prev]; }
    try { if (row('SELECT 1 FROM dd_sal_months WHERE month=?', $prev)) { require_once dirname(__DIR__) . '/salary360.php'; $b = sal_closing($emp, $prev); if ($b !== null) return [$b, 'register:' . $prev]; } } catch (PDOException $e) {}
    return [0, null];
}
function f3_paid(int $emp, string $p): int { return (int)row('SELECT COALESCE(SUM(amount),0) n FROM f3_pay_payments WHERE employee_id=? AND period=? AND voided=0', $emp, $p)['n']; }
/** Context (ledger figures) for one employee/period — read fresh from the staff ledger, never typed again. */
function f3_line_context(array $line, array $per): array {
    $emp = (int)$line['employee_id']; $p = $line['period']; $end = f3_month_last($p);
    $applied = in_array($per['status'], ['approved', 'locked'], true);
    $bal = f3_staff_balances($emp, $applied ? $p : null);
    $sum = fn(string $kind, string $extra = '', array $a = []) => (int)row("SELECT COALESCE(SUM(amount),0) n FROM f3_staff_txns WHERE employee_id=? AND kind=? $extra", $emp, $kind, ...$a)['n'];
    $penWhere = $applied ? "AND status='approved' AND applied_period=?" : "AND status='approved' AND applied_period IS NULL AND date<=?";
    $penalties = $sum('penalty', $penWhere, [$applied ? $p : $end]);
    $addWhere = $applied ? "AND status='approved' AND applied_period=?" : "AND status='approved' AND applied_period IS NULL AND COALESCE(period,DATE_FORMAT(date,'%Y-%m'))<=?";
    $additions = $sum('addition', $addWhere, [$applied ? $p : $p]); $deductions = $sum('deduction', $addWhere, [$applied ? $p : $p]);
    $ot = $sum('overtime', "AND status='paid' AND DATE_FORMAT(date,'%Y-%m')=?", [$p]);
    $items = rows("SELECT id,txn_no,kind,date,amount,status,reason FROM f3_staff_txns WHERE employee_id=? AND ((kind IN ('penalty','addition','deduction') AND status IN ('approved','pending') AND (applied_period IS NULL OR applied_period=?)) OR (kind='overtime' AND DATE_FORMAT(date,'%Y-%m')=?) OR (kind IN ('advance','opening_advance') AND status IN ('approved','paid','pending'))) ORDER BY date", $emp, $p, $p);
    foreach ($items as &$i) { $i['amount'] = (int)$i['amount']; $i['kind_label'] = F3_TXN_KINDS[$i['kind']][0] ?? $i['kind']; } unset($i);
    return ['monthly' => (int)$line['monthly'], 'basis' => row('SELECT pay_basis FROM dd_employees WHERE id=?', $emp)['pay_basis'] ?? 'monthly', 'divisor' => (int)$per['divisor'], 'eligible_days' => (int)round((float)$line['eligible_days']), 'half_value' => (float)f3_set('f3_half_day_value'), 'default_allowance' => f3_set('f3_default_paid_leave'), 'encash' => $per['leave_encash'] === null ? f3_set('f3_leave_encash') === '1' : (int)$per['leave_encash'] === 1,
            'advance_outstanding' => $bal['advance_outstanding'], 'advance_given' => $bal['advance_given'], 'advance_recovered' => $bal['advance_recovered'], 'installment' => $bal['installment'], 'penalties' => $penalties, 'penalty_pending_approval' => $bal['penalty_pending_approval'], 'additions' => $additions, 'deductions_ledger' => $deductions,
            'ot_paid' => $ot, 'ot_unpaid' => $bal['ot_unpaid'], 'carried_forward' => (int)$line['carried_forward'], 'cf_source' => $line['cf_source'], 'paid' => f3_paid($emp, $p), 'items' => $items,
            'calc_mode' => $per['calc_mode'] ?? 'v16', 'days' => (int)$per['days'], 'rest_paid' => f3_set('f3_rest_days_paid') === '1', 'daily_rate' => intdiv((int)$line['monthly'] * 2 + (int)$per['days'], 2 * max(1, (int)$per['days'])),
            'penalty_items' => array_map(fn($r) => ['id' => (int)$r['id'], 'txn_no' => $r['txn_no'], 'date' => $r['date'], 'amount' => (int)$r['amount'], 'reason' => $r['reason']], rows("SELECT id,txn_no,date,amount,reason FROM f3_staff_txns WHERE employee_id=? AND kind='penalty' AND status='approved' AND " . ($applied ? 'applied_period=?' : 'applied_period IS NULL AND date<=?') . ' ORDER BY date,id', $emp, $applied ? $p : $end)),
            'penalty_ids' => $line['penalty_ids'] !== null ? array_map('intval', json_decode((string)$line['penalty_ids'], true) ?: []) : null];
}
function f3_period_open(array $b, array $u): array {
    f3_need($u, 'payroll.prepare'); $p = valid_month($b['period'] ?? '');
    if ($p >= substr(f3_business_date(), 0, 7) && empty($b['allow_current'])) fail('Salary can be prepared after the month ends (from the 1st of next month).', 409);
    $per = f3_period($p, true);
    if (!$per) { run('INSERT INTO f3_pay_periods(period,status,days,divisor,divisor_mode,leave_encash,calc_mode,created_by) VALUES(?,?,?,?,?,?,?,?)', $p, 'draft', days_in_month($p), days_in_month($p), 'calendar', 0, 'v19', $u['id']); $per = f3_period($p, true); f3_audit($u, 'payroll.open', ['period' => $p]); }
    if (!in_array($per['status'], ['draft', 'review'], true)) return f3_period_view($p, $u);
    $a = $p . '-01'; $z = f3_month_last($p); $added = 0;
    foreach (rows("SELECT e.*,g.name desig_name FROM dd_employees e LEFT JOIN f3_designations g ON g.id=e.f3_desig_id WHERE (e.joined_on IS NULL OR e.joined_on<=?) AND (e.left_on IS NULL OR e.left_on>=?) AND (COALESCE(e.status,'active')='active' OR e.left_on IS NOT NULL)", $z, $a) as $e) {
        $elig = f3_eligible_days($e, $p); if ($elig <= 0) continue;
        $rate = f3_rate_on((int)$e['id'], ($e['left_on'] && $e['left_on'] < $z) ? $e['left_on'] : $z);
        [$cf, $src] = f3_carried_forward((int)$e['id'], $p);
        $ex = row('SELECT id,state FROM f3_pay_lines WHERE period=? AND employee_id=?', $p, $e['id']);
        if ($ex) { run('UPDATE f3_pay_lines SET emp_name=?,emp_code=?,dept_id=?,desig_id=?,desig_name=?,carried_forward=?,cf_source=?,eligible_days=?,monthly=IF(state IN (\'pending\',\'skipped\',\'later\'),?,monthly) WHERE id=?', $e['name'], $e['code'], $e['f3_dept_id'], $e['f3_desig_id'], $e['desig_name'], $cf, $src, $elig, $rate ? (int)$rate['monthly'] : (int)$e['salary'], $ex['id']); continue; }
        run('INSERT INTO f3_pay_lines(period,employee_id,state,emp_name,emp_code,dept_id,desig_id,desig_name,monthly,eligible_days,carried_forward,cf_source) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', $p, $e['id'], 'pending', $e['name'], $e['code'], $e['f3_dept_id'], $e['f3_desig_id'], $e['desig_name'], $rate ? (int)$rate['monthly'] : (int)$e['salary'], $elig, $cf, $src);
        $added++;
    }
    if ($added) f3_audit($u, 'payroll.lines_added', ['period' => $p, 'added' => $added]);
    // Staff who are no longer eligible (marked inactive, or left before this month): their untouched rows are set to
    // Excluded with the reason — never deleted; rows where someone already entered days are left for a person to decide.
    $gone = rows("SELECT l.id,e.name,COALESCE(e.status,'active') st,e.left_on FROM f3_pay_lines l JOIN dd_employees e ON e.id=l.employee_id WHERE l.period=? AND l.state IN ('pending','later','skipped') AND l.present IS NULL
        AND ((COALESCE(e.status,'active')<>'active' AND e.left_on IS NULL) OR e.left_on<? OR e.joined_on>?)", $p, $a, $z);
    foreach ($gone as $g) run("UPDATE f3_pay_lines SET state='excluded',skip_reason=? WHERE id=?", 'Not on this month: ' . ($g['left_on'] ? 'left ' . $g['left_on'] : 'staff status ' . $g['st']), $g['id']);
    if ($gone) f3_audit($u, 'payroll.lines_excluded', ['period' => $p, 'lines' => array_column($gone, 'name')]);
    return f3_period_view($p, $u);
}
function f3_line_out(array $l, array $per, bool $withCtx = false): array {
    $o = ['id' => (int)$l['id'], 'employee_id' => (int)$l['employee_id'], 'name' => $l['emp_name'], 'code' => $l['emp_code'], 'dept_id' => $l['dept_id'] ? (int)$l['dept_id'] : null, 'desig_id' => $l['desig_id'] ? (int)$l['desig_id'] : null, 'designation' => $l['desig_name'], 'state' => $l['state'], 'version' => (int)$l['version'],
          'monthly' => (int)$l['monthly'], 'eligible_days' => (float)$l['eligible_days']];
    foreach (['present', 'half_days', 'leave_taken', 'paid_leave_allowed', 'other_paid_leave'] as $k) $o[$k] = $l[$k] === null ? null : (float)$l[$k];
    foreach (['unpaid_leave', 'payable_days'] as $k) $o[$k] = (float)$l[$k];
    foreach (['gross_base', 'additions', 'other_earnings', 'gross_earned', 'penalties', 'advance_recovery', 'other_deductions', 'net_payable', 'ot_paid_info', 'carried_forward'] as $k) $o[$k] = (int)$l[$k];
    $o['cf_source'] = $l['cf_source']; $o['adjust_note'] = $l['adjust_note']; $o['skip_reason'] = $l['skip_reason'];
    foreach (['absent', 'paid_leave'] as $k) $o[$k] = isset($l[$k]) && $l[$k] !== null ? (float)$l[$k] : null; $o['rest_days'] = (float)($l['rest_days'] ?? 0);
    $o['penalty_ids'] = isset($l['penalty_ids']) && $l['penalty_ids'] !== null ? array_map('intval', json_decode((string)$l['penalty_ids'], true) ?: []) : null;
    $o['saved_at'] = $l['saved_at'] ?? null; $o['version'] = (int)$l['version']; $o['calc_mode'] = $per['calc_mode'] ?? 'v16'; $o['days'] = (int)$per['days'];
    $hasData = $l['present'] !== null || ($l['absent'] ?? null) !== null || ($l['paid_leave'] ?? null) !== null;
    $o['entry_status'] = $l['state'] === 'done' ? 'completed' : ($l['state'] === 'excluded' ? 'excluded' : ($l['state'] === 'draft' || $hasData ? 'draft' : 'pending'));
    $o['paid'] = f3_paid((int)$l['employee_id'], $l['period']); $o['balance'] = $o['state'] === 'excluded' ? 0 : $o['net_payable'] + $o['carried_forward'] - $o['paid'];
    $o['joining_unverified'] = f3_joining_unverified((int)$l['employee_id']); // V23 P1-04: unknown joining date is shown, never invented
    if ($withCtx) $o['context'] = f3_line_context($l, $per);
    return $o;
}
/** V23 P1-04 — true when the staff record has no verified joining date (NULL). Cached per request. */
function f3_joining_unverified(int $emp): bool {
    static $m = null; if ($m === null || !array_key_exists($emp, $m)) { $m ??= []; foreach (rows('SELECT id,joined_on FROM dd_employees') as $r) $m[(int)$r['id']] = $r['joined_on'] === null; }
    return $m[$emp] ?? false;
}
/** V23 P1-07 — ledger status of a salary month's advance-recovery journal: 'posted' | 'pending' | 'not_needed' | 'outside_ledger'. Read-only. */
function f3_payroll_gl_status(string $p): string {
    $per = f3_period($p); if (!$per || !in_array($per['status'], ['approved', 'locked'], true)) return 'not_needed';
    if (!(int)row("SELECT COUNT(*) n FROM f3_staff_txns WHERE kind='recovery' AND status='approved' AND applied_period=?", $p)['n']) return 'not_needed';
    if (!f3_gl_available()) return 'pending';
    require_once dirname(__DIR__) . '/post360.php'; $from = function_exists('fin_unify_from') ? fin_unify_from() : null;
    if (!$from || f3_month_last($p) < $from) return 'outside_ledger';
    return row("SELECT 1 FROM dd_gl_journals WHERE source_key LIKE ? AND status='posted' LIMIT 1", 'f3:payrec:' . $p . ':%') ? 'posted' : 'pending';
}
/** Why active staff are NOT on a month's sheet (root cause of the “blank staff list”): no department, joined after the month, left before it, inactive. */
function f3_not_on_sheet(string $p): array {
    $a = $p . '-01'; $z = f3_month_last($p); $o = [];
    $on = []; foreach (rows('SELECT employee_id,dept_id FROM f3_pay_lines WHERE period=?', $p) as $r) $on[(int)$r['employee_id']] = (int)$r['dept_id'];
    foreach (rows("SELECT e.id,e.name,e.code,e.joined_on,e.left_on,e.status,e.f3_dept_id,e.f3_desig_id,e.salary,d.name dept FROM dd_employees e LEFT JOIN f3_departments d ON d.id=e.f3_dept_id WHERE COALESCE(e.status,'active')='active' OR (e.left_on IS NOT NULL AND e.left_on>=?) ORDER BY e.name", $a) as $e) {
        $id = (int)$e['id']; $why = null; $fix = null;
        if (isset($on[$id])) { if (!$on[$id] || !$e['f3_dept_id'] || !$e['f3_desig_id']) { $why = 'No department / designation set'; $fix = 'department'; } else continue; }
        elseif ($e['joined_on'] && $e['joined_on'] > $z) { $why = 'Joining date ' . $e['joined_on'] . ' is after ' . (new DateTimeImmutable($a))->format('F Y'); $fix = 'joining'; }
        elseif ($e['left_on'] && $e['left_on'] < $a) continue;
        elseif (!$e['f3_dept_id'] || !$e['f3_desig_id']) { $why = 'No department / designation set'; $fix = 'department'; }
        else { $why = 'Not added to this month yet'; $fix = 'refresh'; }
        $o[] = ['id' => $id, 'name' => $e['name'], 'code' => $e['code'], 'dept' => $e['dept'], 'joined_on' => $e['joined_on'], 'reason' => $why, 'fix' => $fix];
    }
    return $o;
}
function f3_period_view(string $p, array $u): array {
    f3_need($u, 'payroll.view', 'payroll.prepare'); valid_month($p);
    $per = f3_period($p);
    if (!$per) return ['period' => $p, 'exists' => false, 'days' => days_in_month($p), 'can_prepare' => f3_can($u, 'payroll.prepare'), 'not_on_sheet' => f3_not_on_sheet($p), 'staff_count' => (int)row("SELECT COUNT(*) n FROM dd_employees WHERE COALESCE(status,'active')='active'")['n']];
    $lines = rows('SELECT l.* FROM f3_pay_lines l LEFT JOIN f3_departments d ON d.id=l.dept_id LEFT JOIN f3_designations g ON g.id=l.desig_id WHERE l.period=? ORDER BY d.sort IS NULL,d.sort,g.sort,l.desig_name,l.emp_name', $p);
    $out = []; $prog = ['total' => 0, 'done' => 0, 'skipped' => 0, 'later' => 0, 'pending' => 0, 'draft' => 0, 'excluded' => 0]; $dept = [];
    foreach ($lines as $l) { $o = f3_line_out($l, $per); $out[] = $o; $prog['total']++; $prog[$o['state']]++; $k = (string)($o['dept_id'] ?? 0); $dept[$k] ??= ['total' => 0, 'done' => 0]; $dept[$k]['total']++; if (in_array($o['state'], ['done', 'excluded'], true)) $dept[$k]['done']++; }
    $paid = array_sum(array_column($out, 'paid')); $net = 0; $cf = 0; foreach ($out as $o) if ($o['state'] !== 'excluded') { $net += $o['net_payable']; $cf += $o['carried_forward']; }
    $pay = in_array($per['status'], ['approved', 'locked'], true) ? ($paid <= 0 ? 'unpaid' : ($paid >= $net + $cf ? 'paid' : 'partially_paid')) : null;
    $ent = []; foreach ($out as $o) { $k = (string)($o['dept_id'] ?? 0); $ent[$k] ??= ['total' => 0, 'pending' => 0, 'draft' => 0, 'completed' => 0, 'excluded' => 0]; $ent[$k]['total']++; $ent[$k][$o['entry_status']]++; }
    $extra = ['gl_status' => f3_payroll_gl_status($p), 'joining_unverified' => count(array_filter($out, fn($o) => $o['joining_unverified'] && $o['state'] !== 'excluded')), 'calc_mode' => $per['calc_mode'] ?? 'v16', 'version' => (int)$per['version'], 'entry_by_dept' => $ent, 'not_on_sheet' => in_array($per['status'], ['draft', 'review'], true) ? f3_not_on_sheet($p) : []];
    return $extra + ['period' => $p, 'exists' => true, 'status' => $per['status'], 'payment_status' => $pay, 'days' => (int)$per['days'], 'divisor' => (int)$per['divisor'], 'divisor_mode' => $per['divisor_mode'], 'leave_encash' => $per['leave_encash'] === null ? null : (int)$per['leave_encash'], 'version' => (int)$per['version'],
            'lines' => $out, 'progress' => $prog, 'by_dept' => $dept, 'departments' => f3_departments(), 'totals' => ['net' => $net, 'carried_forward' => $cf, 'paid' => $paid, 'balance' => $net + $cf - $paid],
            'approved_by' => $per['approved_by'] ? (row('SELECT name FROM dd_users WHERE id=?', $per['approved_by'])['name'] ?? null) : null, 'approved_at' => $per['approved_at'], 'unlock_reason' => $per['unlock_reason'],
            'can' => ['prepare' => f3_can($u, 'payroll.prepare'), 'approve' => f3_can($u, 'payroll.approve'), 'pay' => f3_can($u, 'payroll.pay'), 'unlock' => f3_can($u, 'payroll.unlock')]];
}
function f3_line_get(string $p, int $emp, array $u): array {
    f3_need($u, 'payroll.prepare', 'payroll.view');
    $per = f3_period($p); if (!$per) fail('Open the salary month first.', 404);
    $l = row('SELECT * FROM f3_pay_lines WHERE period=? AND employee_id=?', $p, $emp); if (!$l) fail('This person is not on the ' . $p . ' sheet.', 404);
    $o = f3_line_out($l, $per, true);
    if ($l['calc']) $o['calc'] = json_decode((string)$l['calc'], true);
    return $o;
}
/** Save one employee step of the wizard: action = save (Save & Next) | draft | skip | later | exclude | preview. */
function f3_line_save(array $b, array $u): array {
    f3_need($u, 'payroll.prepare'); $p = valid_month($b['period'] ?? ''); $emp = (int)($b['employee_id'] ?? 0); $action = (string)($b['action'] ?? 'save');
    $per = f3_period($p, true); if (!$per) fail('Open the salary month first.', 404);
    if (!in_array($per['status'], ['draft', 'review'], true)) fail('Salary for ' . $p . ' is ' . $per['status'] . '. Unlock it to make changes.', 409);
    $l = row('SELECT * FROM f3_pay_lines WHERE period=? AND employee_id=? FOR UPDATE', $p, $emp); if (!$l) fail('Not on this sheet.', 404);
    if (isset($b['version']) && (int)$b['version'] !== (int)$l['version'] && $action !== 'preview') fail('This salary row was changed on another device. Reload to continue.', 409);
    if (in_array($action, ['skip', 'later'], true)) {
        run('UPDATE f3_pay_lines SET state=?,skip_reason=?,version=version+1,updated_by=? WHERE id=?', $action === 'skip' ? 'skipped' : 'later', txt($b['reason'] ?? '', 250) ?: null, $u['id'], $l['id']);
        if ($per['status'] === 'review') run("UPDATE f3_pay_periods SET status='draft',version=version+1 WHERE period=?", $p);
        f3_audit($u, 'payroll.line_' . $action, ['period' => $p, 'employee' => $emp]); return ['ok' => true, 'line' => f3_line_out(row('SELECT * FROM f3_pay_lines WHERE id=?', $l['id']), $per)];
    }
    if ($action === 'exclude') { f3_need($u, 'payroll.approve'); run("UPDATE f3_pay_lines SET state='excluded',skip_reason=?,version=version+1,updated_by=? WHERE id=?", f3_text($b['reason'] ?? '', 250, 'Reason for excluding'), $u['id'], $l['id']); f3_audit($u, 'payroll.line_exclude', ['period' => $p, 'employee' => $emp]); return ['ok' => true]; }
    if (!$l['dept_id']) fail('Set this person\'s department and designation in Staff first.', 422);
    if (f3_is_v19($per)) return f3_line_save_v19($b, $u, $per, $l, $action);
    $in = [];
    foreach (['present' => 'Present days', 'half_days' => 'Half days', 'leave_taken' => 'Leave taken', 'paid_leave_allowed' => 'Paid leave allowed', 'other_paid_leave' => 'Other paid leave'] as $k => $lbl) $in[$k] = f3_days($b[$k] ?? '', $lbl, 31);
    $in['other_earnings'] = f3_paise($b['other_earnings'] ?? '0', 'Other earnings') ?? 0; $in['other_deductions'] = f3_paise($b['other_deductions'] ?? '0', 'Other deductions') ?? 0;
    if (($in['other_earnings'] || $in['other_deductions']) && trim((string)($b['adjust_note'] ?? '')) === '') fail('Write a note for other earnings / deductions.');
    $in['advance_recovery'] = isset($b['advance_recovery']) && $b['advance_recovery'] !== '' ? f3_paise($b['advance_recovery'], 'Advance recovery') : null;
    $ctx = f3_line_context($l, $per);
    $calc = f3_pay_calc($in, $ctx);
    $limit = (float)setting('f3_max_deduction_pct', '50');
    if ($calc['deduction_pct'] > $limit && $action !== 'preview') {
        if (trim((string)($b['deduction_override'] ?? '')) === '') fail('Total deductions are ' . $calc['deduction_pct'] . '% of earnings (limit ' . $limit . '%). Reduce the advance recovery, or a payroll approver must write an override reason.', 422);
        f3_need($u, 'payroll.approve');
    }
    if ($action === 'preview') return ['ok' => true, 'calc' => $calc, 'context' => $ctx];
    $complete = (string)($b['present'] ?? '') !== '';
    $state = $action === 'draft' || !$complete ? 'draft' : 'done';
    $calcStore = $calc + ['deduction_override' => txt($b['deduction_override'] ?? '', 250) ?: null, 'saved_by' => $u['name'], 'saved_at' => gmdate('c')];
    run('UPDATE f3_pay_lines SET state=?,version=version+1,present=?,half_days=?,leave_taken=?,paid_leave_allowed=?,other_paid_leave=?,unpaid_leave=?,payable_days=?,gross_base=?,additions=?,other_earnings=?,gross_earned=?,penalties=?,advance_recovery=?,other_deductions=?,net_payable=?,ot_paid_info=?,adjust_note=?,skip_reason=NULL,calc=?,updated_by=? WHERE id=?',
        $state, $in['present'], $in['half_days'], $in['leave_taken'], $in['paid_leave_allowed'], $in['other_paid_leave'], number_format($calc['unpaid_leave'], 2, '.', ''), number_format($calc['payable_days'], 2, '.', ''), $calc['gross_base'], $calc['additions'], $calc['other_earnings'], $calc['gross_earned'],
        $calc['penalties'], $calc['advance_recovery'], $calc['other_deductions'], $calc['net_payable'], $calc['ot_paid_info'], txt($b['adjust_note'] ?? '', 500) ?: null, json_encode($calcStore, JSON_UNESCAPED_UNICODE), $u['id'], $l['id']);
    if ($per['status'] === 'review') run("UPDATE f3_pay_periods SET status='draft',version=version+1 WHERE period=?", $p);
    f3_audit($u, 'payroll.line_save', ['period' => $p, 'employee' => $emp, 'state' => $state, 'net' => $calc['net_payable']]);
    return ['ok' => true, 'line' => f3_line_out(row('SELECT * FROM f3_pay_lines WHERE id=?', $l['id']), $per), 'calc' => $calc];
}
/** V19 save: autosave drafts (partial values allowed, never “Completed”), Save & Next completes after full validation. Version-checked; idempotent per row. */
function f3_line_save_v19(array $b, array $u, array $per, array $l, string $action): array {
    $p = $per['period']; $emp = (int)$l['employee_id']; $ctx = f3_line_context($l, $per);
    $fields = ['present' => 'Present days', 'absent' => 'Absent days', 'half_days' => 'Half days', 'paid_leave' => 'Paid leave', 'rest_days' => 'Weekly rest / holiday days'];
    $in = []; $blank = [];
    // Only Present is required. Half days / paid leave / rest left empty = 0. Absent is not typed: it is the rest of the month
    // (eligible days − present − half − paid leave − rest). An absent value sent by an older screen is still accepted and checked.
    foreach ($fields as $k => $lbl) { $raw = trim((string)($b[$k] ?? '')); if ($raw === '') { $in[$k] = in_array($k, ['rest_days', 'half_days', 'paid_leave'], true) ? '0' : null; if ($k === 'present') $blank[] = $lbl; continue; } $in[$k] = f3_days($raw, $lbl, 31); }
    if ($in['absent'] === null && $in['present'] !== null) { $left = (int)$ctx['eligible_days'] * 100 - f3_hund((string)$in['present']) - f3_hund((string)$in['half_days']) - f3_hund((string)$in['paid_leave']) - f3_hund((string)$in['rest_days']);
        $in['absent'] = number_format(max(0, $left) / 100, 2, '.', ''); if ($left < 0) $in['absent_over'] = true; }
    $avail = array_column($ctx['penalty_items'], 'id');
    if (array_key_exists('penalty_ids', $b) && $b['penalty_ids'] !== null) { $sel = array_values(array_unique(array_map('intval', (array)$b['penalty_ids']))); foreach ($sel as $pid) if (!in_array($pid, $avail, true)) fail('A chosen penalty is not an approved, unapplied penalty of this person (refresh the page).', 409); }
    else $sel = $ctx['penalty_ids'] ?? $avail; // default: every approved penalty up to month end
    $in['penalty_ids'] = $sel;
    $in['advance_recovery'] = isset($b['advance_recovery']) && $b['advance_recovery'] !== '' ? f3_paise($b['advance_recovery'], 'Advance recovery') : null;
    $complete = !$blank;
    if ($action === 'preview') { if (!$complete) return ['ok' => true, 'calc' => null, 'context' => $ctx, 'missing' => $blank]; return ['ok' => true, 'calc' => f3_pay_calc_v19($in, $ctx, false), 'context' => $ctx]; }
    $calc = null;
    if ($action === 'save') { if (!$complete) fail('Enter the present days to complete.', 422); $calc = f3_pay_calc_v19($in, $ctx, true); }
    elseif ($complete) { try { $calc = f3_pay_calc_v19($in, $ctx, false); } catch (RuntimeException $e) { $calc = null; } } // draft: keep a preview when the numbers already make sense
    if ($calc && $action === 'save') { $limit = (float)setting('f3_max_deduction_pct', '50');
        if ($calc['deduction_pct'] > $limit) { if (trim((string)($b['deduction_override'] ?? '')) === '') fail('Total deductions are ' . $calc['deduction_pct'] . '% of earnings (limit ' . $limit . '%). Reduce the advance recovery, or a payroll approver must write an override reason.', 422); f3_need($u, 'payroll.approve'); } }
    $state = $action === 'save' ? 'done' : 'draft';
    $n = fn($v) => $v === null ? null : number_format((float)$v, 2, '.', '');
    $c = $calc ?? ['unpaid_leave' => 0, 'payable_days' => 0, 'gross_base' => 0, 'additions' => 0, 'gross_earned' => 0, 'penalties' => 0, 'advance_recovery' => 0, 'other_deductions' => 0, 'net_payable' => 0, 'ot_paid_info' => (int)$ctx['ot_paid']];
    $store = $calc ? $calc + ['deduction_override' => txt($b['deduction_override'] ?? '', 250) ?: null, 'saved_by' => $u['name'], 'saved_at' => gmdate('c')] : null;
    run('UPDATE f3_pay_lines SET state=?,version=version+1,present=?,absent=?,half_days=?,paid_leave=?,rest_days=?,leave_taken=?,paid_leave_allowed=?,other_paid_leave=?,penalty_ids=?,unpaid_leave=?,payable_days=?,gross_base=?,additions=?,other_earnings=0,gross_earned=?,penalties=?,advance_recovery=?,other_deductions=?,net_payable=?,ot_paid_info=?,skip_reason=NULL,calc=?,saved_at=UTC_TIMESTAMP(),updated_by=? WHERE id=?',
        $state, $n($in['present']), $n($in['absent']), $n($in['half_days']), $n($in['paid_leave']), $n($in['rest_days'] ?? 0), $n($in['paid_leave'] ?? 0), $n($in['paid_leave'] ?? 0), $n((!empty($ctx['rest_paid']) ? ($in['rest_days'] ?? 0) : 0)), json_encode($sel),
        number_format((float)$c['unpaid_leave'], 2, '.', ''), number_format((float)$c['payable_days'], 2, '.', ''), $c['gross_base'], $c['additions'], $c['gross_earned'], $c['penalties'], $c['advance_recovery'], $c['other_deductions'], $c['net_payable'], $c['ot_paid_info'],
        $store ? json_encode($store, JSON_UNESCAPED_UNICODE) : null, $u['id'], $l['id']);
    if ($per['status'] === 'review') run("UPDATE f3_pay_periods SET status='draft',version=version+1 WHERE period=?", $p);
    if ($action === 'save') f3_audit($u, 'payroll.line_save', ['period' => $p, 'employee' => $emp, 'state' => $state, 'net' => $c['net_payable']]);
    $row = row('SELECT * FROM f3_pay_lines WHERE id=?', $l['id']);
    return ['ok' => true, 'line' => f3_line_out($row, $per), 'calc' => $calc, 'saved_at' => $row['saved_at'], 'missing' => $blank];
}
/** Recalculate a done line from its stored inputs (ledger may have changed: new approved penalty, etc.). */
function f3_line_recalc(array $l, array $per, array $u): array {
    if (!in_array($l['state'], ['done', 'draft'], true) || $l['present'] === null) return $l;
    if (f3_is_v19($per)) { if ($l['state'] !== 'done') return $l; $ctx = f3_line_context($l, $per); $sel = array_values(array_intersect($ctx['penalty_ids'] ?? [], array_column($ctx['penalty_items'], 'id')));
        $c = f3_pay_calc_v19(['present' => $l['present'], 'absent' => $l['absent'], 'half_days' => $l['half_days'], 'paid_leave' => $l['paid_leave'], 'rest_days' => $l['rest_days'], 'penalty_ids' => $sel, 'advance_recovery' => min((int)$l['advance_recovery'], (int)$ctx['advance_outstanding'])], $ctx, true);
        run('UPDATE f3_pay_lines SET penalty_ids=?,unpaid_leave=?,payable_days=?,gross_base=?,additions=?,gross_earned=?,penalties=?,advance_recovery=?,other_deductions=?,net_payable=?,ot_paid_info=? WHERE id=?', json_encode($sel), number_format($c['unpaid_leave'], 2, '.', ''), number_format($c['payable_days'], 2, '.', ''), $c['gross_base'], $c['additions'], $c['gross_earned'], $c['penalties'], $c['advance_recovery'], $c['other_deductions'], $c['net_payable'], $c['ot_paid_info'], $l['id']);
        return row('SELECT * FROM f3_pay_lines WHERE id=?', $l['id']); }
    $ctx = f3_line_context($l, $per);
    $in = ['present' => $l['present'], 'half_days' => $l['half_days'], 'leave_taken' => $l['leave_taken'], 'paid_leave_allowed' => $l['paid_leave_allowed'], 'other_paid_leave' => $l['other_paid_leave'], 'other_earnings' => (int)$l['other_earnings'], 'other_deductions' => (int)$l['other_deductions'], 'advance_recovery' => min((int)$l['advance_recovery'], $ctx['advance_outstanding'])];
    $c = f3_pay_calc($in, $ctx);
    run('UPDATE f3_pay_lines SET unpaid_leave=?,payable_days=?,gross_base=?,additions=?,gross_earned=?,penalties=?,advance_recovery=?,net_payable=?,ot_paid_info=?,monthly=monthly WHERE id=?', number_format($c['unpaid_leave'], 2, '.', ''), number_format($c['payable_days'], 2, '.', ''), $c['gross_base'], $c['additions'], $c['gross_earned'], $c['penalties'], $c['advance_recovery'], $c['net_payable'], $c['ot_paid_info'], $l['id']);
    return row('SELECT * FROM f3_pay_lines WHERE id=?', $l['id']);
}
function f3_period_submit(array $b, array $u): array {
    f3_need($u, 'payroll.prepare'); $p = valid_month($b['period'] ?? ''); $per = f3_period($p, true); if (!$per || $per['status'] !== 'draft') fail('Only a draft salary month can be sent for approval.', 409);
    $open = rows("SELECT emp_name,state FROM f3_pay_lines WHERE period=? AND state NOT IN ('done','excluded')", $p);
    if ($open) fail(count($open) . ' staff are not complete (' . implode(', ', array_slice(array_map(fn($x) => $x['emp_name'] . ' — ' . $x['state'], $open), 0, 6)) . (count($open) > 6 ? ' …' : '') . '). Finish them, or an approver can exclude a person with a reason.', 422);
    foreach (rows('SELECT * FROM f3_pay_lines WHERE period=? AND state=\'done\' FOR UPDATE', $p) as $l) f3_line_recalc($l, $per, $u);
    run("UPDATE f3_pay_periods SET status='review',version=version+1,submitted_by=?,submitted_at=UTC_TIMESTAMP() WHERE period=?", $u['id'], $p);
    f3_notify('payroll-review', 'payroll-review-' . $p . '-' . time(), 'Salary ' . $p . ' is ready for approval', 'Prepared by ' . $u['name'] . '.', 'payroll.approve', 'payroll:' . $p);
    f3_audit($u, 'payroll.submit', ['period' => $p]);
    return f3_period_view($p, $u);
}
function f3_period_approve(array $b, array $u): array {
    f3_need($u, 'payroll.approve'); $p = valid_month($b['period'] ?? ''); $per = f3_period($p, true);
    if (!$per || $per['status'] !== 'review') fail('Salary must be “Ready for review” before approval.', 409);
    if (isset($b['version']) && (int)$b['version'] !== (int)$per['version']) fail('The salary sheet changed. Reload before approving.', 409);
    if (f3_set('f3_payroll_separate_approver') === '1' && (int)$per['submitted_by'] === (int)$u['id'] && ($u['role'] ?? '') !== 'owner') fail('The person who prepared this sheet cannot approve it (setting: separate approver).', 403);
    $lines = rows("SELECT * FROM f3_pay_lines WHERE period=? FOR UPDATE", $p);
    foreach ($lines as $l) if (!in_array($l['state'], ['done', 'excluded'], true)) fail('Not all staff are complete.', 422);
    // V23 P1-04: salary for staff whose joining date is unknown is approved only with an explicit, audited confirmation
    $unv = rows("SELECT l.emp_name FROM f3_pay_lines l JOIN dd_employees e ON e.id=l.employee_id WHERE l.period=? AND l.state='done' AND e.joined_on IS NULL", $p);
    if ($unv && empty($b['ack_unverified_joining'])) throw new F3Conflict(count($unv) . ' staff have no verified joining date (' . implode(', ', array_slice(array_column($unv, 'emp_name'), 0, 8)) . (count($unv) > 8 ? ' …' : '') . '). Confirm they worked from the 1st of ' . $p . ' (or correct their joining dates) before approving.', ['code' => 'unverified_joining', 'names' => array_column($unv, 'emp_name')]);
    $end = f3_month_last($p); $snap = [];
    foreach ($lines as $l) {
        if ($l['state'] === 'excluded') continue;
        $l = f3_line_recalc($l, $per, $u); $emp = (int)$l['employee_id'];
        // Recheck outstanding advance under lock, then apply ledger items exactly once.
        $bal = f3_staff_balances($emp); if ((int)$l['advance_recovery'] > $bal['advance_outstanding']) fail($l['emp_name'] . ': advance recovery is more than the outstanding advance. Re-open the row.', 409);
        if (f3_is_v19($per)) { $ids = array_map('intval', json_decode((string)$l['penalty_ids'], true) ?: []); if ($ids) run("UPDATE f3_staff_txns SET applied_period=? WHERE employee_id=? AND kind='penalty' AND status='approved' AND applied_period IS NULL AND id IN (" . implode(',', $ids) . ')', $p, $emp); }
        else run("UPDATE f3_staff_txns SET applied_period=? WHERE employee_id=? AND kind='penalty' AND status='approved' AND applied_period IS NULL AND date<=?", $p, $emp, $end);
        run("UPDATE f3_staff_txns SET applied_period=? WHERE employee_id=? AND kind IN ('addition','deduction') AND status='approved' AND applied_period IS NULL AND COALESCE(period,DATE_FORMAT(date,'%Y-%m'))<=?", $p, $emp, $p);
        if ((int)$l['advance_recovery'] > 0) run("INSERT INTO f3_staff_txns(txn_no,employee_id,kind,date,amount,status,reason,applied_period,created_by,decided_by,decided_at) VALUES(?,?,'recovery',?,?,'approved',?,?,?,?,UTC_TIMESTAMP())", f3_seq('stafftxn', 'ST'), $emp, $end, (int)$l['advance_recovery'], 'Recovered in salary ' . $p, $p, $u['id'], $u['id']);
        $snap[] = f3_line_out($l, $per);
    }
    run("UPDATE f3_pay_periods SET status='approved',version=version+1,approved_by=?,approved_at=UTC_TIMESTAMP(),snapshot=? WHERE period=?", $u['id'], json_encode($snap, JSON_UNESCAPED_UNICODE), $p);
    f3_payroll_gl_recovery($p, $u);
    f3_notify_done('payroll-due-' . $p);
    $gls = f3_payroll_gl_status($p);
    if ($gls === 'pending') f3_notify('gl-pending', 'gl-payrec-' . $p, 'Ledger posting pending: salary ' . $p, 'Advance recovery journal is not in the ledger yet. Accounts → Update ledger.', 'accounts.view', 'payroll:' . $p);
    f3_audit($u, 'payroll.approve', ['period' => $p, 'lines' => count($snap), 'net' => array_sum(array_column($snap, 'net_payable')), 'unverified_joining_ack' => $unv ? array_column($unv, 'emp_name') : [], 'gl' => $gls]);
    return f3_period_view($p, $u);
}
function f3_period_lock(array $b, array $u): array {
    f3_need($u, 'payroll.approve'); $p = valid_month($b['period'] ?? ''); $per = f3_period($p, true); if (!$per || $per['status'] !== 'approved') fail('Only an approved salary month can be locked.', 409);
    if (f3_set('f3_strict_gl') === '1' && f3_payroll_gl_status($p) === 'pending') fail('Ledger posting for ' . $p . ' is pending (advance recovery journal). Run Accounts → Update ledger, then lock.', 409);
    run("UPDATE f3_pay_periods SET status='locked',version=version+1,locked_by=?,locked_at=UTC_TIMESTAMP() WHERE period=?", $u['id'], $p); f3_audit($u, 'payroll.lock', ['period' => $p]); return f3_period_view($p, $u);
}
function f3_period_unlock(array $b, array $u): array {
    f3_need($u, 'payroll.unlock'); $p = valid_month($b['period'] ?? ''); $per = f3_period($p, true);
    if (!$per || !in_array($per['status'], ['approved', 'locked', 'review'], true)) fail('Nothing to unlock.', 409);
    $why = f3_text($b['reason'] ?? '', 500, 'Reason for unlocking');
    $next = f3_period(sal_next_f3($p)); if ($next && in_array($next['status'], ['approved', 'locked'], true)) fail('The next month (' . $next['period'] . ') is already approved and carries this month\'s balance. Unlock it first.', 409);
    run("UPDATE f3_staff_txns SET applied_period=NULL WHERE applied_period=? AND kind IN ('penalty','addition','deduction')", $p);
    run("UPDATE f3_staff_txns SET status='reversed',decision_note=? WHERE applied_period=? AND kind='recovery' AND status='approved'", 'Salary ' . $p . ' unlocked: ' . $why, $p);
    f3_payroll_gl_recovery_reverse($p, $why, $u);
    run("UPDATE f3_pay_periods SET status='draft',version=version+1,unlocked_by=?,unlocked_at=UTC_TIMESTAMP(),unlock_reason=? WHERE period=?", $u['id'], $why, $p);
    f3_audit($u, 'payroll.unlock', ['period' => $p, 'reason' => $why, 'previous_status' => $per['status']]);
    return f3_period_view($p, $u);
}
function sal_next_f3(string $p): string { return (new DateTimeImmutable($p . '-01'))->modify('+1 month')->format('Y-m'); }
function f3_pay_payment(array $b, array $u): array {
    f3_need($u, 'payroll.pay');
    return idem('f3-pay', $b, $u, function () use ($b, $u) {
        $p = valid_month($b['period'] ?? ''); $per = f3_period($p, true);
        if (!$per || !in_array($per['status'], ['approved', 'locked'], true)) fail('Approve the salary sheet before paying.', 409);
        $emp = (int)($b['employee_id'] ?? 0); $l = row("SELECT * FROM f3_pay_lines WHERE period=? AND employee_id=? AND state='done' FOR UPDATE", $p, $emp); if (!$l) fail('This person has no approved salary for ' . $p . '.', 404);
        $amt = f3_paise($b['amount'] ?? null, 'Amount'); if ($amt <= 0) fail('Amount must be more than zero.');
        $bal = (int)$l['net_payable'] + (int)$l['carried_forward'] - f3_paid($emp, $p);
        if ($amt > $bal) fail('Payment ' . f3_rs($amt) . ' is more than the balance ' . f3_rs(max(0, $bal)) . '.', 422);
        $mode = (string)($b['mode'] ?? ''); if (!in_array($mode, ['Cash', 'Bank', 'UPI'], true)) fail('Choose payment mode.');
        $d = valid_date($b['date'] ?? f3_business_date()); f3_day_guard($d, $u, 'salary');
        $no = f3_seq('salpay', 'SP');
        run('INSERT INTO f3_pay_payments(pay_no,period,employee_id,date,amount,mode,reference,client_key,created_by) VALUES(?,?,?,?,?,?,?,?,?)', $no, $p, $emp, $d, $amt, $mode, txt($b['reference'] ?? '', 120) ?: null, txt($b['client_key'] ?? '', 64) ?: null, $u['id']);
        $id = (int)db()->lastInsertId();
        $bk = f3_bk_post('f360:pay:' . $id, ['date' => $d, 'type' => 'expense', 'category' => $d > f3_month_last($p) ? 'Salary - Previous Month' : 'Salary - Current Month', 'party_id' => f3_staff_party($emp), 'total' => $amt, 'account' => f3_mode_acct($mode), 'description' => 'Salary ' . $p . ' · ' . $l['emp_name'] . ' · ' . $no], $u);
        run('UPDATE f3_pay_payments SET bk_txn_id=? WHERE id=?', $bk, $id);
        f3_audit($u, 'payroll.pay', ['id' => $id, 'no' => $no, 'period' => $p, 'employee' => $emp, 'amount' => $amt, 'mode' => $mode]);
        return ['ok' => true, 'id' => $id, 'pay_no' => $no, 'balance' => $bal - $amt];
    });
}
function f3_pay_void(array $b, array $u): array {
    f3_need($u, 'payroll.pay'); $pp = row('SELECT * FROM f3_pay_payments WHERE id=? FOR UPDATE', (int)($b['id'] ?? 0)); if (!$pp || (int)$pp['voided']) fail('Payment not found.', 404);
    $why = f3_text($b['reason'] ?? '', 250, 'Reason'); f3_day_guard($pp['date'], $u, 'salary');
    f3_bk_void((int)$pp['bk_txn_id'], 'Salary payment ' . $pp['pay_no'] . ' cancelled: ' . $why, $u);
    run('UPDATE f3_pay_payments SET voided=1,void_reason=? WHERE id=?', $why, $pp['id']); f3_audit($u, 'payroll.pay_void', ['id' => (int)$pp['id'], 'reason' => $why]); return ['ok' => true];
}
/** Sheet = department → designation → person, with subtotals; dept=null → combined master sheet. */
function f3_sheet(string $p, ?int $dept, array $u): array {
    f3_need($u, 'payroll.view'); $v = f3_period_view($p, $u); if (!$v['exists']) fail('No salary sheet for ' . $p . '.', 404);
    $cols = ['monthly', 'gross_base', 'additions', 'other_earnings', 'gross_earned', 'penalties', 'advance_recovery', 'other_deductions', 'net_payable', 'ot_paid_info', 'carried_forward', 'paid', 'balance'];
    $zero = array_fill_keys($cols, 0) + ['staff' => 0, 'payable_days' => 0.0, 'unpaid_leave' => 0.0];
    $groups = []; $grand = $zero;
    foreach (f3_departments() as $d) {
        if ($dept && $d['id'] !== $dept) continue;
        $dl = array_values(array_filter($v['lines'], fn($l) => $l['dept_id'] === $d['id'])); if (!$dl) { $groups[] = ['dept' => $d['name'], 'dept_id' => $d['id'], 'designations' => [], 'total' => $zero]; continue; }
        $des = []; foreach ($dl as $l) { $k = $l['designation'] ?: '(no designation)'; $des[$k][] = $l; }
        $dt = $zero; $dg = [];
        foreach ($des as $name => $ls) { $st = $zero; foreach ($ls as $l) { if ($l['state'] === 'excluded') continue; foreach ($cols as $c) $st[$c] += $l[$c]; $st['staff']++; $st['payable_days'] += $l['payable_days']; $st['unpaid_leave'] += $l['unpaid_leave']; }
            foreach ($cols as $c) $dt[$c] += $st[$c]; $dt['staff'] += $st['staff']; $dt['payable_days'] += $st['payable_days']; $dt['unpaid_leave'] += $st['unpaid_leave']; $dg[] = ['designation' => $name, 'lines' => $ls, 'total' => $st]; }
        foreach ($cols as $c) $grand[$c] += $dt[$c]; $grand['staff'] += $dt['staff']; $grand['payable_days'] += $dt['payable_days']; $grand['unpaid_leave'] += $dt['unpaid_leave'];
        $groups[] = ['dept' => $d['name'], 'dept_id' => $d['id'], 'designations' => $dg, 'total' => $dt];
    }
    $unmapped = array_values(array_filter($v['lines'], fn($l) => !$l['dept_id']));
    return ['period' => $p, 'status' => $v['status'], 'payment_status' => $v['payment_status'], 'days' => $v['days'], 'divisor' => $v['divisor'], 'divisor_mode' => $v['divisor_mode'], 'title' => $dept ? (F3_DEPTS[$dept][0] . ' salary sheet') : 'Master salary sheet', 'groups' => $groups, 'grand' => $grand, 'unmapped' => $unmapped, 'approved_by' => $v['approved_by'], 'approved_at' => $v['approved_at']];
}
function f3_payslip(string $p, int $emp, array $u): array {
    f3_need($u, 'payroll.view'); $l = f3_line_get($p, $emp, $u); $per = f3_period($p);
    if (!in_array($per['status'], ['approved', 'locked'], true)) fail('Payslips are available after approval.', 409);
    $e = row('SELECT e.*,d.name dept FROM dd_employees e LEFT JOIN f3_departments d ON d.id=e.f3_dept_id WHERE e.id=?', $emp);
    return ['period' => $p, 'line' => $l, 'employee' => ['name' => $e['name'], 'code' => $e['code'], 'dept' => $e['dept'], 'designation' => $l['designation'], 'joined_on' => $e['joined_on'], 'pay_mode' => $e['pay_mode'], 'phone' => $e['phone'], 'account' => $e['account_last4'] ? '••••' . $e['account_last4'] : ''],
            'payments' => rows('SELECT pay_no,date,amount,mode,reference,voided FROM f3_pay_payments WHERE period=? AND employee_id=? ORDER BY id', $p, $emp), 'restaurant' => setting('restaurant', 'Khadak Singh Da Dhaba')];
}
function f3_payroll_months(array $u): array {
    f3_need($u, 'payroll.view', 'payroll.prepare');
    $o = []; foreach (rows('SELECT period,status FROM f3_pay_periods ORDER BY period DESC LIMIT 36') as $r) { $v = f3_period_view($r['period'], $u); $o[] = ['period' => $r['period'], 'status' => $r['status'], 'payment_status' => $v['payment_status'], 'progress' => $v['progress'], 'totals' => $v['totals'], 'source' => 'finance360']; }
    try { foreach (rows('SELECT month,status,days FROM dd_sal_months ORDER BY month DESC LIMIT 36') as $r) $o[] = ['period' => $r['month'], 'status' => $r['status'], 'source' => 'legacy_register', 'days' => (int)$r['days']]; } catch (PDOException $e) {}
    usort($o, fn($a, $b) => strcmp($b['period'], $a['period']));
    $due = f3_payroll_due_period();
    return ['months' => $o, 'due' => $due, 'due_status' => $due ? (f3_period($due)['status'] ?? 'not_started') : null];
}
/** The previous calendar month, once the 1st has reached the reminder hour; null before that, or if before Finance 360 payroll start. */
function f3_payroll_due_period(?DateTimeImmutable $n = null): ?string {
    $n ??= f3_now(); $p = $n->modify('first day of this month')->modify('-1 month')->format('Y-m');
    if ((int)$n->format('j') === 1 && (int)$n->format('G') < f3_set_int('f3_payroll_reminder_hour')) $p = $n->modify('first day of this month')->modify('-2 month')->format('Y-m');
    $start = f3_set('f3_payroll_start'); if ($start && $p < $start) return null;
    $per = f3_period($p); if ($per && in_array($per['status'], ['approved', 'locked'], true)) return null;
    return $p;
}

/** Pay every remaining balance in one go (whole sheet or one department). One payment + one Books entry per person, idempotent per person. */
function f3_pay_bulk(array $b, array $u): array {
    f3_need($u, 'payroll.pay'); $p = valid_month($b['period'] ?? ''); $dept = !empty($b['dept']) ? (int)$b['dept'] : null;
    $mode = (string)($b['mode'] ?? ''); if (!in_array($mode, ['Cash', 'Bank', 'UPI'], true)) fail('Choose payment mode.');
    $ck = txt($b['client_key'] ?? '', 40); if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $ck)) fail('Missing submission key. Reload and try again.');
    $per = f3_period($p); if (!$per || !in_array($per['status'], ['approved', 'locked'], true)) fail('Approve the salary sheet before paying.', 409);
    $only = array_map('intval', (array)($b['employee_ids'] ?? []));
    $n = 0; $total = 0; $skipped = 0;
    foreach (rows("SELECT employee_id,net_payable,carried_forward,dept_id FROM f3_pay_lines WHERE period=? AND state='done'" . ($dept ? ' AND dept_id=' . $dept : '') . ' ORDER BY id', $p) as $l) {
        if ($only && !in_array((int)$l['employee_id'], $only, true)) continue;
        $bal = (int)$l['net_payable'] + (int)$l['carried_forward'] - f3_paid((int)$l['employee_id'], $p);
        if ($bal <= 0) { $skipped++; continue; }
        $r = f3_pay_payment(['period' => $p, 'employee_id' => $l['employee_id'], 'amount' => rupees($bal), 'mode' => $mode, 'date' => $b['date'] ?? f3_business_date(), 'reference' => txt($b['reference'] ?? 'Bulk salary payment', 120), 'client_key' => $ck . '-' . $l['employee_id']], $u);
        if (empty($r['duplicate'])) { $n++; $total += $bal; }
    }
    f3_audit($u, 'payroll.pay_bulk', ['period' => $p, 'dept' => $dept, 'mode' => $mode, 'count' => $n, 'total' => $total]);
    return ['ok' => true, 'count' => $n, 'total' => $total, 'skipped' => $skipped, 'message' => $n ? 'Recorded ' . $n . ' payment(s) totalling ' . f3_rs($total) . '.' : 'Nothing to pay.'];
}

/** V21 §1/§3 — double-entry ledger for advance recovery. The advance cash went out once (Books → Dr 1300 Employee Advances).
 *  Recovering it in payroll converts that asset into salary cost: Dr 6000 Salary / Cr 1300 Employee Advances, dated at month end.
 *  No cash moves. Idempotent per set of recoveries; reversed (never edited) on unlock. Never blocks approval: if the ledger is
 *  not installed or refuses, it is retried by Accounts → "Update ledger". */
function f3_payroll_gl_recovery(string $p, ?array $u): ?int {
    if (!f3_gl_available()) return null; require_once dirname(__DIR__) . '/gl360.php'; require_once dirname(__DIR__) . '/post360.php';
    // Only months inside the unified ledger period: before that date the advances themselves are not in the ledger either.
    $from = function_exists('fin_unify_from') ? fin_unify_from() : null; if (!$from || f3_month_last($p) < $from) return null;
    $rec = rows("SELECT id,employee_id,amount FROM f3_staff_txns WHERE kind='recovery' AND status='approved' AND applied_period=? ORDER BY id", $p); if (!$rec) return null;
    $key = 'f3:payrec:' . $p . ':' . substr(md5(implode(',', array_column($rec, 'id'))), 0, 12);
    $lines = []; foreach ($rec as $r) { $a = (int)$r['amount']; $memo = 'Advance recovered in salary ' . $p;
        $lines[] = [gl_acct('salary'), $a, 0, $memo, 'employee', (int)$r['employee_id']]; $lines[] = [gl_acct('emp_adv'), 0, $a, $memo, 'employee', (int)$r['employee_id']]; }
    try { return gl_post($key, gl_open_date(f3_month_last($p)), 'payroll_recovery', null, 'Salary ' . $p . ': staff advances recovered (no cash)', $lines, $u); }
    catch (RuntimeException $e) { f3_audit($u ?? ['id' => null], 'gl.payrec_pending', ['period' => $p, 'error' => $e->getMessage()]); return null; }
}
function f3_payroll_gl_recovery_reverse(string $p, string $why, ?array $u): void {
    if (!f3_gl_available()) return; require_once dirname(__DIR__) . '/gl360.php';
    foreach (rows("SELECT id FROM dd_gl_journals WHERE source_key LIKE ? AND status='posted'", 'f3:payrec:' . $p . ':%') as $j) gl_reverse((int)$j['id'], gl_open_date(f3_business_date()), 'Salary ' . $p . ' unlocked: ' . $why, $u);
}
