<?php
declare(strict_types=1);
// Finance 360 — ONE-TIME Active Employee Master import (September 2026 salary sheet = authority for current active staff).
// Sources: (1) salary workbook, sheet "September" — names, section → department, designation, monthly salary;
//          (2) HR employee CSV — code, mobile, department, designation, joining date, bank details (identity data);
//          (3) bank transfer letter (optional) — used ONLY to corroborate / flag bank details, never to post money.
// Two-stage matching:  September row → CSV person (by name + section + designation + salary; anything not unique needs
// the owner's approval) → existing dd_employees row (by stored source link, mobile, bank account, code+name; name-only
// only when exact and unique). Nothing is written until the owner applies a fully-resolved preview. Apply runs in one
// transaction, writes before-snapshots for rollback, and is idempotent (same files = same batch id; a batch applies once;
// any later run finds the same people through the stored source links and shows them as Unchanged).
// Never touches payroll lines, payments, advances, penalties or Books entries.

require_once __DIR__ . '/../xlsx360.php';

const EI_KIND = 'employees';
const EI_SRC = 'hrcsv';              // f3_ext_ids.source for the HR CSV employee code
const EI_SECTIONS = ['WAITER' => 4, 'MANAGER' => 1, 'UT STAFF' => 2, 'KITCHEN' => 3];
const EI_CSV_DEPT = ['service' => 4, 'manager' => 1, 'ut staff' => 2, 'kitchen' => 3];
const EI_PERIOD = '2026-09';        // the authoritative month
const EI_DESIG_FIX = ['service' => 'Waiter', 'gaurd' => 'Guard', 'guard' => 'Guard', 'indain' => 'Indian', 'ut' => 'UT Staff', 'ut staff' => 'UT Staff', 'mocktail' => 'Mocktail', 'wiping' => 'Wiping', 'floor' => 'Floor',
    'cashier' => 'Cashier', 'store' => 'Store', 'head chef' => 'Head Chef', 'halwai' => 'Halwai', 'pickup' => 'Pickup', 'tandoor' => 'Tandoor', 'roti' => 'Roti', 'helper' => 'Helper', 'chinese' => 'Chinese', 'indian' => 'Indian', 'waiter' => 'Waiter'];

function ei_need(array $u): void { if (($u['role'] ?? '') !== 'owner') fail('Only the Owner can run the employee master import.', 403); }
function ei_dir(string $batch): string { $d = storage_dir('imports') . '/' . preg_replace('/[^A-Za-z0-9_-]/', '', $batch); if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }

/* ------------------------------ name helpers ------------------------------ */
function ei_tokens(string $s): array { $s = strtolower(preg_replace('/[^A-Za-z ]+/', ' ', $s)); return array_values(array_filter(explode(' ', $s), fn($t) => $t !== '')); }
/** Phonetic-ish key so that spelling variants (doubled letters, joined/split words, sh/s, v/w) compare equal or very close. */
function ei_key(string $t): string { $t = strtolower(preg_replace('/[^a-z]/i', '', $t)); $t = strtr($t, ['sh' => 's', 'bh' => 'b', 'kh' => 'k', 'th' => 't', 'ph' => 'f', 'dh' => 'd', 'gh' => 'g', 'ch' => 'c', 'ee' => 'i', 'oo' => 'u', 'aa' => 'a', 'w' => 'v', 'y' => 'i', 'z' => 'j']);
    return preg_replace('/(.)\1+/', '$1', $t); }
function ei_names(array $c): array { $o = []; foreach ([$c['name'] ?? '', $c['display'] ?? ''] as $n) if (trim($n) !== '') $o[] = ei_tokens($n); return $o; }
/** Score how well a September name fits a CSV person (0 = no). Explained in the matching report. */
function ei_name_score(string $sept, array $c): array {
    $s = ei_tokens($sept); if (!$s) return [0, 'no name']; $sj = ei_key(implode('', $s)); $sf = ei_key($s[0]); $sl = ei_key(end($s));
    $best = [0, ''];
    foreach (ei_names($c) as $n) { if (!$n) continue; $cj = ei_key(implode('', $n)); $cf = ei_key($n[0]); $cl = ei_key(end($n));
        $cand = match (true) {
            $sj === $cj => [100, 'full name equal'],
            count($s) > 1 && $sf === $cf && $sl === $cl => [92, 'first and last name equal'],
            count($s) > 1 && str_starts_with($cj, $sj) => [90, 'name is the start of the full name'],
            count($s) === 1 && $sf === $cf => [80, 'first name equal'],
            count($s) === 1 && $sj === ei_key(implode('', array_slice($n, 0, 2))) => [85, 'joined name equal'],
            count($s) > 1 && $sf === $cf => [65, 'first name equal, surname differs'],
            strlen($sj) >= 5 && levenshtein($sj, $cj) <= 1 => [75, 'spelling differs by one letter'],
            strlen($sf) >= 4 && levenshtein($sf, $cf) <= 1 => [70, 'first name differs by one letter'],
            strlen($sf) >= 5 && levenshtein($sf, $cf) <= 2 => [60, 'first name differs by two letters'],
            strlen($sf) >= 5 && $sf[0] === ($cf[0] ?? '') && ei_letters_close($sf, $cf) => [60, 'same letters in a different order'],
            default => [0, ''] };
        if ($cand[0] > $best[0]) $best = $cand; }
    return $best;
}
function ei_letters_close(string $a, string $b): bool { $x = count_chars($a, 1); $y = count_chars($b, 1); $d = 0; foreach ($x + $y as $k => $_) $d += abs(($x[$k] ?? 0) - ($y[$k] ?? 0)); return $d <= 1; }
function ei_desig_norm(?string $raw): string { $r = strtolower(trim((string)$raw)); if ($r === '') return ''; return EI_DESIG_FIX[$r] ?? ucwords($r); }
function ei_mobile(string $m): string { $d = preg_replace('/\D/', '', $m); return strlen($d) >= 10 ? substr($d, -10) : ''; }
function ei_mask(string $s, int $keep = 4): string { $s = (string)$s; return $s === '' ? '' : str_repeat('•', max(0, min(6, strlen($s) - $keep))) . substr($s, -$keep); }
function ei_date(string $s): ?string { $s = trim($s); if ($s === '') return null; $d = DateTimeImmutable::createFromFormat('!d M Y', $s) ?: DateTimeImmutable::createFromFormat('!Y-m-d', $s); return $d ? $d->format('Y-m-d') : null; }
function ei_num(string $v): ?float { $v = trim(str_replace(',', '', $v)); return is_numeric($v) ? (float)$v : null; }

/* ------------------------------ parsers ------------------------------ */
function ei_parse_salary(string $data): array {
    $x = xlsx_read($data, 'September'); if (($x['sheet'] ?? '') !== 'September') fail('The salary workbook has no sheet named "September".');
    $rows = $x['rows']; $sec = null; $out = []; $check = ['headline_count' => null, 'headline_salary' => null, 'sections' => []]; $sno = [];
    foreach ($rows as $i => $r) { $r = array_map(fn($v) => trim((string)$v), $r); $a = $r[0] ?? ''; $b = $r[1] ?? '';
        if ($b === 'Total Employee' || ($check['headline_count'] === null && ($rows[$i - 1][1] ?? '') === 'Total Employee')) { if (is_numeric($b)) { $check['headline_count'] = (int)$b; $check['headline_salary'] = (int)round((float)($r[3] ?? 0)); } continue; }
        if (isset(EI_SECTIONS[strtoupper($a)])) { $sec = strtoupper($a); $check['sections'][$sec] = ['rows' => 0, 'salary' => 0, 'sheet_count' => null, 'sheet_salary' => null]; continue; }
        if ($sec === null) continue;
        if ($a === '' && is_numeric($b) && is_numeric($r[3] ?? '')) { $check['sections'][$sec]['sheet_count'] = (int)$b; $check['sections'][$sec]['sheet_salary'] = (int)round((float)$r[3]); continue; } // section subtotal row
        if (!is_numeric($a) || $b === '') continue;
        $sal = ei_num($r[3] ?? ''); $key = 'S' . ($i + 1);
        $out[] = ['key' => $key, 'sheet_row' => $i + 1, 'section' => $sec, 'dept_id' => EI_SECTIONS[$sec], 'sno' => (int)$a, 'name' => preg_replace('/\s+/', ' ', $b), 'desig_raw' => $r[2] ?? '',
            'monthly' => $sal !== null ? (int)round($sal * 100) : null, 'present' => ei_num($r[4] ?? ''), 'status_cell' => $r[15] ?? ''];
        $check['sections'][$sec]['rows']++; $check['sections'][$sec]['salary'] += (int)round(($sal ?? 0)); $sno[$sec][(int)$a][] = $b;
    }
    $dups = []; foreach ($sno as $s => $m) foreach ($m as $n => $names) if (count($names) > 1) $dups[] = $s . ' S.No ' . $n . ': ' . implode(' / ', $names);
    $check['rows'] = count($out); $check['row_salary'] = array_sum(array_map(fn($s) => $s['salary'], $check['sections'])); $check['duplicate_serials'] = $dups;
    return [$out, $check];
}
function ei_parse_csv(string $data): array {
    $data = preg_replace('/^\xEF\xBB\xBF/', '', $data); $lines = preg_split('/\r\n|\n|\r/', $data); $head = str_getcsv(array_shift($lines)); $out = []; $issues = [];
    $col = fn(string $h) => array_search($h, $head, true);
    foreach (['Code*', 'Employee Name*', 'Mobile No*', 'Department Name*', 'Designation Name*', 'Status'] as $h) if ($col($h) === false) fail('The employee CSV has no "' . $h . '" column.');
    foreach ($lines as $n => $ln) { if (trim($ln) === '') continue; $c = str_getcsv($ln); $g = fn(string $h) => trim((string)($c[$col($h)] ?? ''));
        $code = $g('Code*'); if ($code === '') { $issues[] = 'CSV line ' . ($n + 2) . ': no code'; continue; }
        if (isset($out[$code])) { $issues[] = 'CSV code ' . $code . ' appears twice'; continue; }
        $dept = EI_CSV_DEPT[strtolower($g('Department Name*'))] ?? null;
        $out[$code] = ['code' => $code, 'name' => preg_replace('/\s+/', ' ', $g('Employee Name*')), 'display' => $g('Display Name'), 'mobile' => ei_mobile($g('Mobile No*')), 'gender' => $g('Gender*'),
            'dept_raw' => $g('Department Name*'), 'dept_id' => $dept, 'desig' => ei_desig_norm($g('Designation Name*')), 'status' => $g('Status'), 'salary' => ei_num($g('Salary')), 'doj' => ei_date($g('Date of Joining')),
            'bank_name' => $g('Bank Name'), 'ifsc' => strtoupper($g('IFSC Code')), 'account' => ltrim(preg_replace('/\D/', '', $g('Account No')), '0'), 'branch' => $g('Bank Branch'), 'line' => $n + 2];
    }
    $m = []; foreach ($out as $c) if ($c['mobile']) $m[$c['mobile']][] = $c['code']; foreach ($m as $mob => $cs) if (count($cs) > 1) $issues[] = 'Mobile ' . ei_mask($mob) . ' shared by CSV codes ' . implode(', ', $cs);
    $a = []; foreach ($out as $c) if ($c['account']) $a[$c['account']][] = $c['code']; foreach ($a as $ac => $cs) if (count($cs) > 1) $issues[] = 'Account ' . ei_mask($ac) . ' shared by CSV codes ' . implode(', ', $cs);
    return [$out, $issues];
}
function ei_parse_bank(string $data): array {
    $x = xlsx_read($data); $out = []; $hdr = null;
    foreach ($x['rows'] as $i => $r) { $r = array_map(fn($v) => trim((string)$v), $r);
        if ($hdr === null) { if (strtoupper(trim($r[2] ?? '')) === 'NAME' && stripos($r[4] ?? '', 'Account') !== false) $hdr = $i; continue; }
        if (($r[2] ?? '') === '' || strtolower($r[0] ?? '') === 'total') continue;
        $acct = ltrim(preg_replace('/\D/', '', $r[4] ?? ''), '0'); if ($acct === '') continue;
        $out[] = ['holder' => $r[2], 'father' => $r[3] ?? '', 'account' => $acct, 'ifsc' => strtoupper($r[5] ?? ''), 'mobile' => ei_mobile($r[7] ?? ''), 'desig' => $r[8] ?? '', 'bank_name' => $r[1] ?? '', 'branch' => $r[9] ?? '', 'row' => $i + 1];
    }
    return $out;
}

/* ------------------------------ one-click file: staff list + old months (record only) ------------------------------ */
const EI_OC_SECT = ['MANAGER' => 'MANAGER', 'MANAGEMENT' => 'MANAGER', 'UT' => 'UT STAFF', 'UT STAFF' => 'UT STAFF', 'KITCHEN' => 'KITCHEN', 'WAITER' => 'WAITER'];
const EI_OC_HIST = ['section' => 'section', 's.no' => 'sno', 'name' => 'name', 'hr code' => 'code', 'designation' => 'desig', 'monthly salary' => 'monthly', 'present days' => 'present', 'leave encash' => 'leave',
    'working days' => 'working', 'total salary' => 'total', 'advance' => 'advance', 'debt' => 'debt', 'net salary' => 'net', 'payment mode' => 'mode', 'paid' => 'paid', 'balance' => 'balance', 'status' => 'pay_status'];
function ei_oc_head(array $rows, array $must): array { foreach ($rows as $i => $r) { $m = []; foreach ($r as $j => $v) $m[strtolower(trim((string)$v))] = $j; if (!array_diff($must, array_keys($m))) return [$i, $m]; } return [null, []]; }
function ei_oc_date(string $v): ?string { $v = trim($v); if ($v === '') return null; if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int)$v . ' days')->format('Y-m-d');
    $d = ei_date($v) ?? ((DateTimeImmutable::createFromFormat('!d/m/Y', $v) ?: DateTimeImmutable::createFromFormat('!d-m-Y', $v)) ?: null); return $d instanceof DateTimeImmutable ? $d->format('Y-m-d') : $d; }
function ei_oc_paise(string $v): ?int { $n = ei_num($v); return $n === null ? null : (int)round($n * 100); }
/** Returns [sept rows, sheet check, csv-like people keyed by HR code, decisions taken from the file, history months]. Identity = HR code + name; never name alone. */
function ei_parse_oneclick(string $data): array {
    $x = xlsx_read($data, 'Staff'); if (($x['sheet'] ?? '') !== 'Staff') fail('This is not a KSDD one-click import file: it has no sheet named "Staff".');
    [$h, $c] = ei_oc_head($x['rows'], ['section', 'name', 'designation', 'monthly salary', 'hr code']); if ($h === null) fail('The "Staff" sheet needs the columns Section, Name, Designation, Monthly salary and HR code.');
    $g = fn(array $r, string $k) => isset($c[$k]) ? trim((string)($r[$c[$k]] ?? '')) : '';
    $sept = []; $csv = []; $dec = []; $check = ['headline_count' => 0, 'headline_salary' => 0, 'sections' => [], 'rows' => 0, 'row_salary' => 0, 'duplicate_serials' => [], 'one_click' => true]; $codeRow = []; $sno = [];
    foreach (array_slice($x['rows'], $h + 1, null, true) as $i => $r) { $name = preg_replace('/\s+/', ' ', $g($r, 'name')); if ($name === '') continue;
        $secRaw = strtoupper($g($r, 'section')); $sec = EI_OC_SECT[$secRaw] ?? null; if (!$sec) fail('Staff sheet row ' . ($i + 1) . ' (' . $name . '): section "' . $g($r, 'section') . '" is not Management, UT, Kitchen or Waiter.');
        $monthly = ei_oc_paise($g($r, 'monthly salary')); $key = 'S' . ($i + 1); $code = preg_replace('/\s+/', '', $g($r, 'hr code')); $n = (int)$g($r, 's.no');
        $sept[] = ['key' => $key, 'sheet_row' => $i + 1, 'section' => $sec, 'dept_id' => EI_SECTIONS[$sec], 'sno' => $n, 'name' => $name, 'desig_raw' => $g($r, 'designation'), 'monthly' => $monthly, 'present' => null, 'status_cell' => ''];
        $check['sections'][$sec] ??= ['rows' => 0, 'salary' => 0, 'sheet_count' => 0, 'sheet_salary' => 0]; $check['sections'][$sec]['rows']++; $check['sections'][$sec]['sheet_count']++;
        $rs = (int)round(($monthly ?? 0) / 100); $check['sections'][$sec]['salary'] += $rs; $check['sections'][$sec]['sheet_salary'] += $rs; $check['rows']++; $check['row_salary'] += $rs; if ($n) $sno[$sec][$n][] = $name;
        if ($code === '') { $dec['src:' . $key] = 'new'; continue; }
        if (isset($codeRow[$code])) fail('HR code ' . $code . ' is used twice in the Staff sheet (rows ' . $codeRow[$code] . ' and ' . ($i + 1) . '). Each person needs their own code.'); $codeRow[$code] = $i + 1;
        $hrName = preg_replace('/\s+/', ' ', $g($r, 'hr name')); // full name from the HR list (optional); both names are compared with existing staff
        $csv[$code] = ['code' => $code, 'name' => $hrName !== '' ? $hrName : $name, 'display' => '', 'mobile' => '', 'gender' => '', 'dept_raw' => $g($r, 'section'), 'dept_id' => EI_SECTIONS[$sec], 'desig' => ei_desig_norm($g($r, 'designation')), 'status' => 'Active',
            'salary' => $monthly !== null ? $monthly / 100 : null, 'doj' => ei_oc_date($g($r, 'joining date')), 'bank_name' => '', 'ifsc' => '', 'account' => '', 'branch' => '', 'line' => $i + 1];
        $dec['src:' . $key] = $code; }
    if (!$sept) fail('The "Staff" sheet has no staff rows.');
    $check['headline_count'] = $check['rows']; $check['headline_salary'] = $check['row_salary'];
    foreach ($sno as $sc => $m) foreach ($m as $n => $names) if (count($names) > 1) $check['duplicate_serials'][] = $sc . ' S.No ' . $n . ': ' . implode(' / ', $names);
    // old months: kept as a record only
    $hist = [];
    foreach ($x['sheets'] as $sh) { if (!preg_match('/^History (\d{4}-(0[1-9]|1[0-2]))$/', $sh, $mm)) continue; $per = $mm[1]; if ($per > EI_PERIOD) fail('History sheet ' . $sh . ' is after September 2026. Old months only.');
        $y = xlsx_read($data, $sh); [$hh, $hc] = ei_oc_head($y['rows'], ['name', 'hr code', 'monthly salary', 'paid']); if ($hh === null) fail('Sheet "' . $sh . '" needs at least the columns Name, HR code, Monthly salary and Paid.');
        $out = [];
        foreach (array_slice($y['rows'], $hh + 1, null, true) as $i => $r) { $v = []; foreach (EI_OC_HIST as $hd => $k) $v[$k] = isset($hc[$hd]) ? trim((string)($r[$hc[$hd]] ?? '')) : ''; if ($v['name'] === '') continue;
            $sec = EI_OC_SECT[strtoupper($v['section'])] ?? null;
            $out[] = ['sheet_row' => $i + 1, 'section' => $sec ? ['MANAGER' => 'Management', 'UT STAFF' => 'UT', 'KITCHEN' => 'Kitchen', 'WAITER' => 'Waiter'][$sec] : mb_substr($v['section'], 0, 20), 'sno' => (int)$v['sno'] ?: null, 'name' => mb_substr(preg_replace('/\s+/', ' ', $v['name']), 0, 120), 'code' => mb_substr(preg_replace('/\s+/', '', $v['code']), 0, 20),
                'desig' => mb_substr($v['desig'], 0, 60), 'monthly' => ei_oc_paise($v['monthly']), 'present' => ei_num($v['present']), 'leave' => ei_num($v['leave']), 'working' => ei_num($v['working']), 'total' => ei_oc_paise($v['total']),
                'advance' => ei_oc_paise($v['advance']), 'debt' => ei_oc_paise($v['debt']), 'net' => ei_oc_paise($v['net']), 'mode' => mb_substr($v['mode'], 0, 30), 'paid' => ei_oc_paise($v['paid']), 'balance' => ei_oc_paise($v['balance']), 'pay_status' => mb_substr($v['pay_status'], 0, 30)]; }
        $hist[$per] = ['period' => $per, 'sheet' => $sh, 'rows' => $out]; }
    ksort($hist);
    return [$sept, $check, $csv, $dec, $hist];
}
/** Everything a batch was built from, for both upload modes. */
function ei_sources(string $batch): array {
    $oc = ei_read($batch, 'oneclick');
    if ($oc !== null) { [$sept, $check, $csv, $fdec, $hist] = ei_parse_oneclick($oc); return ['sept' => $sept, 'check' => $check, 'csv' => $csv, 'csv_issues' => [], 'bank' => [], 'file_dec' => $fdec, 'history' => $hist, 'mode' => 'oneclick']; }
    [$sept, $check] = ei_parse_salary((string)ei_read($batch, 'salary')); [$csv, $iss] = ei_parse_csv((string)ei_read($batch, 'csv'));
    return ['sept' => $sept, 'check' => $check, 'csv' => $csv, 'csv_issues' => $iss, 'bank' => ($d = ei_read($batch, 'bank')) ? ei_parse_bank($d) : [], 'file_dec' => [], 'history' => [], 'mode' => 'files'];
}
/** Who each history row belongs to: HR code → this import's link / an existing staff code. Never by name. */
function ei_hist_who(string $code, array $planCodes): ?int {
    if ($code === '') return null; if (isset($planCodes[$code])) return $planCodes[$code];
    $l = row('SELECT employee_id FROM f3_ext_ids WHERE source=? AND ext_key=?', EI_SRC, $code); if ($l) return (int)$l['employee_id'];
    $e = rows('SELECT id FROM dd_employees WHERE code=?', $code); return count($e) === 1 ? (int)$e[0]['id'] : null;
}

/* ------------------------------ stage 1: September row → CSV person ------------------------------ */
function ei_resolve_sources(array $sept, array $csv, array $dec, array $bank = []): array {
    $cand = [];
    foreach ($sept as $s) { $list = [];
        foreach ($csv as $c) { [$sc, $why] = ei_name_score($s['name'], $c); if ($sc < 60) continue;
            $deptOk = $c['dept_id'] === $s['dept_id']; $dsg = ei_desig_norm($s['desig_raw']); $dsgOk = $dsg !== '' && strcasecmp($dsg, $c['desig']) === 0; $salOk = $c['salary'] && $s['monthly'] && (int)round($c['salary'] * 100) === $s['monthly'];
            $list[] = ['code' => $c['code'], 'score' => $sc + ($deptOk ? 10 : -15) + ($dsgOk ? 5 : 0) + ($salOk ? 3 : 0), 'name_score' => $sc, 'why' => $why . ($deptOk ? ', same department' : ', DIFFERENT department (' . $c['dept_raw'] . ')') . ($dsgOk ? ', same designation' : '') . ($salOk ? ', same salary' : '')]; }
        usort($list, fn($a, $b) => $b['score'] <=> $a['score']); $cand[$s['key']] = $list; }
    // how many September rows want each CSV person as their best choice
    $claims = []; foreach ($cand as $k => $l) if ($l) $claims[$l[0]['code']][] = $k;
    $out = [];
    foreach ($sept as $s) { $l = $cand[$s['key']]; $d = $dec['src:' . $s['key']] ?? null; $r = ['sept' => $s, 'candidates' => array_slice($l, 0, 4), 'code' => null, 'tier' => '', 'reason' => ''];
        if ($d !== null) { // owner decision wins: a CSV code, or "new"
            if ($d === 'new') { $r['tier'] = 'approved_new'; $r['reason'] = 'Owner confirmed: not in the CSV, create new'; }
            elseif (isset($csv[$d])) { $r['code'] = $d; $r['tier'] = 'approved'; $r['reason'] = 'Owner approved match to CSV ' . $d; }
            $out[$s['key']] = $r; continue; }
        $top = $l[0] ?? null;
        // a rival = another CSV person in the SAME department whose name fits almost as well
        $rival = null; if ($top) foreach (array_slice($l, 1) as $x) { $xc = $csv[$x['code']]; if ($xc['dept_id'] === $s['dept_id'] && $x['name_score'] >= max(75, $top['name_score'] - 10)) { $rival = $x; break; } }
        $other = null; if ($top) foreach (array_slice($l, 1) as $x) if ($csv[$x['code']]['dept_id'] !== $s['dept_id'] && $x['name_score'] >= 80) { $other = $x; break; }
        // corroboration: a bank-letter row whose holder name fits this September name and that links to a CSV person by mobile / account
        $corr = null; foreach ($bank as $bk) if (ei_name_score($s['name'], ['name' => $bk['holder']])[0] >= 90) foreach ($csv as $c) if (($c['mobile'] && $c['mobile'] === $bk['mobile']) || ($c['account'] && $c['account'] === $bk['account'])) { $corr = $c['code']; break 2; }
        $deptOk = $top && $csv[$top['code']]['dept_id'] === $s['dept_id'];
        if (!$top) { $r['tier'] = 'review_new'; $r['reason'] = 'No CSV person with a similar name — confirm this is a new employee'; }
        elseif ($corr && $corr === $top['code'] && !$rival && count($claims[$top['code']] ?? []) === 1) { $r['code'] = $top['code']; $r['tier'] = 'auto'; $r['reason'] = 'Confirmed: bank letter holder name = September name, same mobile/account as CSV ' . $top['code'] . ' (' . $top['why'] . ')'; }
        elseif ($top['name_score'] >= 80 && $deptOk && !$rival && count($claims[$top['code']] ?? []) === 1) { $r['code'] = $top['code']; $r['tier'] = 'auto'; $r['reason'] = 'Unique in department: ' . $top['why'] . ($other ? ' (note: CSV ' . $other['code'] . ' ' . $csv[$other['code']]['name'] . ' has a similar name in ' . $csv[$other['code']]['dept_raw'] . ')' : ''); }
        else { $r['tier'] = 'review'; $r['code'] = $top['code']; $r['reason'] = ($rival ? 'Two people in this department fit this name (CSV ' . $top['code'] . ' ' . $csv[$top['code']]['name'] . ' / CSV ' . $rival['code'] . ' ' . $csv[$rival['code']]['name'] . ')' : (count($claims[$top['code']] ?? []) > 1 ? 'Another September row also fits CSV ' . $top['code'] : (!$deptOk ? 'Best match CSV ' . $top['code'] . ' ' . $csv[$top['code']]['name'] . ' is in another department (' . $csv[$top['code']]['dept_raw'] . ')' : 'Name only partly matches CSV ' . $top['code'] . ' ' . $csv[$top['code']]['name'] . ' (' . $top['why'] . ')'))) . ' — approve or choose'; }
        $out[$s['key']] = $r; }
    // a CSV person may be used once
    $used = []; foreach ($out as $k => $r) if ($r['code'] && in_array($r['tier'], ['auto', 'approved'], true)) { if (isset($used[$r['code']])) { $out[$k]['tier'] = 'review'; $out[$k]['reason'] = 'CSV ' . $r['code'] . ' is already used by another September row'; } $used[$r['code']] = $k; }
    return $out;
}

/* ------------------------------ schema inspection (read-only) ------------------------------ */
function ei_inspect(): array {
    $need = ['dd_employees' => ['id', 'name', 'code', 'phone', 'status', 'active', 'joined_on', 'left_on', 'salary', 'f3_dept_id', 'f3_desig_id', 'designation', 'section', 'job', 'aliases', 'bank_name', 'ifsc', 'account_last4', 'bank_account_enc'],
        'f3_designations' => ['id', 'dept_id', 'name', 'active'], 'f3_salary_rates' => ['employee_id', 'effective_from', 'monthly'], 'f3_assignments' => ['employee_id', 'dept_id', 'desig_id', 'effective_from'],
        'f3_import_batches' => ['batch_id', 'applied_seq'], 'f3_import_rows' => ['batch_id'], 'f3_ext_ids' => ['source', 'ext_key'], 'f3_sal_history' => ['batch_id', 'period', 'hr_code', 'employee_id', 'superseded_by']];
    $missing = [];
    foreach ($need as $t => $cols) { $have = array_column(rows('SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', $t), 'c'); if (!$have) { $missing[] = $t . ' (table)'; continue; } foreach ($cols as $c) if (!in_array($c, $have, true)) $missing[] = $t . '.' . $c; }
    $by = rows("SELECT COALESCE(status,'active') status,COALESCE(f3_dept_id,0) dept,COUNT(*) n FROM dd_employees GROUP BY 1,2 ORDER BY 1,2");
    $locked = rows("SELECT period,status FROM f3_pay_periods WHERE status IN ('approved','locked','review') ORDER BY period DESC LIMIT 6");
    return ['schema_ok' => !$missing, 'missing' => $missing, 'f3_schema' => (int)setting('f3_schema'), 'employees_total' => (int)row('SELECT COUNT(*) n FROM dd_employees')['n'], 'by_status_dept' => $by,
        'pii_key' => f3_pii_key() !== null, 'periods_closed' => $locked, 'db' => (string)(cfg()['db_name'] ?? ''), 'previous_batches' => rows("SELECT batch_id,status,created_at,applied_at,rolled_back_at FROM f3_import_batches ORDER BY created_at DESC LIMIT 5")];
}
function ei_counts(): array {
    $o = ['total' => (int)row('SELECT COUNT(*) n FROM dd_employees')['n']];
    foreach (rows("SELECT COALESCE(status,'active') s,COUNT(*) n FROM dd_employees GROUP BY 1") as $r) $o['status_' . $r['s']] = (int)$r['n'];
    foreach (rows("SELECT COALESCE(f3_dept_id,0) d,COUNT(*) n FROM dd_employees WHERE COALESCE(status,'active')='active' GROUP BY 1") as $r) $o['active_dept_' . (F3_DEPTS[(int)$r['d']][0] ?? 'none')] = (int)$r['n'];
    $o['salary_rates'] = (int)row('SELECT COUNT(*) n FROM f3_salary_rates')['n']; $o['assignments'] = (int)row('SELECT COUNT(*) n FROM f3_assignments')['n'];
    $o['pay_payments'] = (int)row('SELECT COUNT(*) n FROM f3_pay_payments')['n'];
    foreach (['old_register_rows' => 'dd_sal_rows', 'old_register_entries' => 'dd_sal_entries'] as $k => $t) { try { $o[$k] = (int)row("SELECT COUNT(*) n FROM $t")['n']; } catch (PDOException $e) { $o[$k] = 0; } }
    try { $o['salary_history_rows'] = (int)row('SELECT COUNT(*) n FROM f3_sal_history WHERE superseded_by IS NULL')['n']; } catch (PDOException $e) { $o['salary_history_rows'] = 0; }
    $o['payroll_lines'] = (int)row('SELECT COUNT(*) n FROM f3_pay_lines')['n']; $o['staff_txns'] = (int)row('SELECT COUNT(*) n FROM f3_staff_txns')['n']; $o['books_txns'] = (int)row('SELECT COUNT(*) n FROM dd_bk_txns')['n'];
    return $o;
}

/* ------------------------------ stage 2: person → existing dd_employees ------------------------------ */
function ei_existing(): array {
    $o = []; foreach (rows('SELECT * FROM dd_employees') as $e) { $e['_names'] = array_values(array_filter(array_merge([$e['name'], $e['legal_name'] ?? ''], preg_split('/[,;|]/', (string)($e['aliases'] ?? ''))), fn($n) => trim((string)$n) !== ''));
        $e['_mobile'] = ei_mobile((string)($e['phone'] ?? '')); $e['_acct'] = ($e['bank_account_enc'] ?? null) ? (string)f3_dec($e['bank_account_enc']) : ''; $o[(int)$e['id']] = $e; }
    return $o;
}
function ei_full_key(string $n): string { return ei_key(implode('', ei_tokens($n))); }
function ei_target(array $p, array $emps, array $links, int $sheetTwins = 1): array {
    $c = $p['csv']; $hits = [];
    if ($c && isset($links[$c['code']])) $hits['source link'] = $links[$c['code']];
    if ($c && $c['mobile']) foreach ($emps as $id => $e) if ($e['_mobile'] === $c['mobile']) $hits['mobile'][] = $id;
    if ($c && $c['account']) foreach ($emps as $id => $e) if ($e['_acct'] !== '' && $e['_acct'] === $c['account']) $hits['bank account'][] = $id;
    $names = array_filter([$p['sept']['name'], $c['name'] ?? '', $c['display'] ?? '']);
    if ($c) foreach ($emps as $id => $e) if ((string)$e['code'] !== '' && strcasecmp((string)$e['code'], $c['code']) === 0) { $ok = false; foreach ($e['_names'] as $en) foreach ($names as $nm) if (ei_name_score($nm, ['name' => $en])[0] >= 65) $ok = true; $hits[$ok ? 'code + name' : 'code only (name differs)'][] = $id; }
    $nameHits = []; foreach ($emps as $id => $e) foreach ($e['_names'] as $en) foreach ($names as $nm) if (ei_full_key($en) === ei_full_key($nm)) $nameHits[$id] = true;
    $strong = []; foreach (['source link', 'mobile', 'bank account', 'code + name'] as $k) foreach ((array)($hits[$k] ?? []) as $id) $strong[(int)$id][] = $k;
    if (count($strong) === 1) { $id = array_key_first($strong); return ['id' => $id, 'how' => implode(' + ', $strong[$id]), 'review' => null]; }
    if (count($strong) > 1) return ['id' => null, 'how' => '', 'cands' => array_keys($strong), 'review' => 'Identity data points to ' . count($strong) . ' different existing staff (' . implode('; ', array_map(fn($id) => '#' . $id . ' ' . $emps[$id]['name'] . ' by ' . implode('+', $strong[$id]), array_keys($strong))) . ')'];
    if (!empty($hits['code only (name differs)'])) return ['id' => null, 'how' => '', 'cands' => $hits['code only (name differs)'], 'review' => 'Existing staff #' . $hits['code only (name differs)'][0] . ' (' . $emps[$hits['code only (name differs)'][0]]['name'] . ') has code ' . $c['code'] . ' but a different name'];
    $nameIds = array_keys($nameHits);
    if (count($nameIds) === 1) { $e = $emps[$nameIds[0]]; $sameDept = (int)($e['f3_dept_id'] ?? 0) === (int)$p['dept_id'];
        $twins = 0; foreach ($emps as $e2) foreach ($e2['_names'] as $en) if (ei_key(ei_tokens($en)[0] ?? '') === ei_key(ei_tokens($p['sept']['name'])[0] ?? '-')) { $twins++; break; } // count people, not their names/aliases
        if ($sameDept && $twins <= 1 && $sheetTwins <= 1) return ['id' => $nameIds[0], 'how' => 'exact name, same department, no other staff with that first name', 'review' => null];
        return ['id' => null, 'how' => '', 'cands' => [$nameIds[0]], 'review' => 'Only the name matches existing staff #' . $nameIds[0] . ' (' . $e['name'] . ')' . ($sameDept ? '' : ' in another department') . ($twins > 1 ? '; other staff share this first name' : '') . ($sheetTwins > 1 ? '; the September sheet has ' . $sheetTwins . ' people with this name' : '') . ' — approve or create new']; }
    if (count($nameIds) > 1) return ['id' => null, 'how' => '', 'cands' => $nameIds, 'review' => 'Several existing staff have this name: #' . implode(', #', $nameIds)];
    return ['id' => null, 'how' => 'no existing record', 'review' => null];
}

/* ------------------------------ build the plan (dry run; read-only) ------------------------------ */
function ei_files(string $batch): array { $b = row('SELECT files FROM f3_import_batches WHERE batch_id=?', $batch); if (!$b) fail('Import batch not found.', 404); return json_decode($b['files'], true); }
function ei_read(string $batch, string $kind): ?string { foreach (ei_files($batch) as $f) if ($f['kind'] === $kind) { $p = ei_dir($batch) . '/' . $f['stored']; $d = (string)file_get_contents($p); if (hash('sha256', $d) !== $f['sha256']) fail('Stored source file ' . $f['name'] . ' was changed after upload.', 409); return $d; } return null; }
function ei_plan(string $batch): array {
    $b = row('SELECT * FROM f3_import_batches WHERE batch_id=?', $batch); if (!$b) fail('Import batch not found.', 404);
    $S = ei_sources($batch); $sept = $S['sept']; $check = $S['check']; $csv = $S['csv']; $csvIssues = $S['csv_issues']; $bank = $S['bank'];
    $dec = array_merge($S['file_dec'], json_decode((string)$b['decisions'], true) ?: []); // the owner's later answers win over the file
    $src = ei_resolve_sources($sept, $csv, $dec, $bank);
    if ($S['mode'] === 'oneclick') foreach ($src as $k => $r) if (($S['file_dec']['src:' . $k] ?? null) === ($dec['src:' . $k] ?? null)) $src[$k]['reason'] = $r['code'] ? 'HR code ' . $r['code'] . ' given in the one-click file' : 'No HR code in the one-click file';
    $emps = ei_existing(); $links = []; foreach (rows('SELECT ext_key,employee_id FROM f3_ext_ids WHERE source=?', EI_SRC) as $l) $links[$l['ext_key']] = (int)$l['employee_id'];
    $desigs = rows('SELECT id,dept_id,name,active FROM f3_designations');
    $lastClosed = row("SELECT MAX(period) p FROM f3_pay_periods WHERE status IN ('approved','locked')")['p'] ?? null;
    $rateFrom = $lastClosed && $lastClosed >= EI_PERIOD ? (new DateTimeImmutable($lastClosed . '-01'))->modify('+1 month')->format('Y-m-d') : EI_PERIOD . '-01';
    $rows = []; $claimedEmp = []; $usedCodes = []; $pending = []; $sheetNames = []; foreach ($sept as $x) $sheetNames[ei_full_key($x['name'])] = ($sheetNames[ei_full_key($x['name'])] ?? 0) + 1;
    foreach ($src as $k => $r) { $s = $r['sept']; $c = $r['code'] && in_array($r['tier'], ['auto', 'approved', 'review'], true) ? ($csv[$r['code']] ?? null) : null; $srcOk = in_array($r['tier'], ['auto', 'approved', 'approved_new'], true);
        $desig = ei_desig_norm($s['desig_raw']); if ($desig === '' || $desig === 'Kitchen') $desig = $c['desig'] ?? ($s['dept_id'] === 2 ? 'UT Staff' : ($s['dept_id'] === 4 ? 'Waiter' : ($s['dept_id'] === 3 ? 'Cook' : 'Staff')));
        if ($s['dept_id'] === 4 && $desig === 'Waiter' && $c && $c['desig'] === 'Guard') $desig = 'Guard';
        $p = ['key' => $k, 'sept' => $s, 'csv' => $c, 'source_tier' => $r['tier'], 'source_reason' => $r['reason'], 'candidates' => $r['candidates'], 'dept_id' => $s['dept_id'], 'desig' => $desig, 'monthly' => $s['monthly'], 'issues' => [], 'review' => []];
        if (!$srcOk) $p['review'][] = ['type' => 'source', 'text' => $r['reason']];
        if ($s['monthly'] === null || $s['monthly'] <= 0) $p['review'][] = ['type' => 'salary', 'text' => 'No monthly salary on the September sheet'];
        if ($c && $c['dept_id'] !== null && $c['dept_id'] !== $s['dept_id']) $p['issues'][] = 'CSV department ' . $c['dept_raw'] . ' → September section ' . $s['section'] . ' (September wins)';
        if ($c && $c['status'] !== '' && strcasecmp($c['status'], 'Active') !== 0) $p['review'][] = ['type' => 'status', 'text' => 'CSV status is "' . $c['status'] . '" but this person is on the September sheet (' . ($s['present'] ?? 0) . ' days present). Make Active?'];
        if ($c && $c['salary'] && (int)round($c['salary'] * 100) !== $s['monthly']) $p['issues'][] = 'Salary in CSV ₹' . number_format($c['salary']) . ' → September ₹' . number_format(($s['monthly'] ?? 0) / 100) . ' (September wins)';
        // joining date: never invented. CSV date if it is not after September; otherwise ask.
        $p['joined_on'] = null; $p['joined_note'] = 'unknown (left blank — enter later)';
        if ($c && $c['doj']) { if ($c['doj'] <= EI_PERIOD . '-30') { $p['joined_on'] = $c['doj']; $p['joined_note'] = 'from CSV'; } else $p['review'][] = ['type' => 'joining', 'text' => 'CSV joining date ' . $c['doj'] . ' is after September, but the person is on the September sheet']; }
        // bank: only the person's own CSV record; bank letter only corroborates or flags.
        $p['bank'] = null; $bl = $c ? array_values(array_filter($bank, fn($x) => ($c['mobile'] && $x['mobile'] === $c['mobile']) || ($c['account'] && $x['account'] === $c['account']))) : [];
        if ($c && $c['account']) { $p['bank'] = ['bank_name' => $c['bank_name'], 'ifsc' => $c['ifsc'], 'account' => $c['account'], 'source' => 'CSV']; if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $c['ifsc'])) $p['issues'][] = 'IFSC "' . $c['ifsc'] . '" is not in the standard format — bank details not imported';
            foreach ($bl as $x) { if ($x['account'] !== $c['account']) $p['review'][] = ['type' => 'bank', 'text' => 'Bank letter has a DIFFERENT account ' . ei_mask($x['account']) . ' for this mobile (CSV ' . ei_mask($c['account']) . ')']; if (ei_name_score($x['holder'], $c)[0] < 60 && ei_name_score($x['holder'], ['name' => $s['name']])[0] < 60) $p['review'][] = ['type' => 'bank', 'text' => 'The bank letter shows this account in the name of "' . $x['holder'] . '" — not this employee (third-party account). Import it as the salary account, or skip?']; } }
        elseif ($bl) { $x = $bl[0]; $p['bank_offer'] = ['bank_name' => $x['bank_name'], 'ifsc' => $x['ifsc'], 'account' => $x['account'], 'holder' => $x['holder'], 'source' => 'bank letter row ' . $x['row']];
            $p['issues'][] = 'Bank details only in the June bank letter (holder "' . $x['holder'] . '") — not imported automatically; owner can approve'; }
        if ($p['bank'] && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $p['bank']['ifsc'])) $p['bank'] = null;
        if (($dec['bank:' . $k] ?? '') === 'use_letter' && !empty($p['bank_offer'])) $p['bank'] = $p['bank_offer'] + ['approved' => true];
        // existing record
        $t = ['id' => null, 'how' => '', 'review' => null];
        if ($srcOk || $r['tier'] === 'review') { $t = ei_target($p, $emps, $links, $sheetNames[ei_full_key($s['name'])] ?? 1); $de = $dec['emp:' . $k] ?? null;
            if ($de === 'new' && !str_contains((string)$t['how'], 'source link')) $t = ['id' => null, 'how' => 'owner: create new', 'review' => null]; /* a stored link (person already imported) always wins */ elseif ($de && isset($emps[(int)$de])) $t = ['id' => (int)$de, 'how' => 'owner approved', 'review' => null]; }
        if ($t['review']) { $p['review'][] = ['type' => 'existing', 'text' => $t['review']]; foreach ($t['cands'] ?? [] as $cid) $pending[(int)$cid][] = $s['name'] . ' (' . $s['section'] . ' S.No ' . $s['sno'] . ')'; }
        if ($t['id'] && isset($claimedEmp[$t['id']])) { $p['review'][] = ['type' => 'existing', 'text' => 'Existing staff #' . $t['id'] . ' is also matched to ' . $claimedEmp[$t['id']]]; $t['id'] = null; }
        if ($t['id']) $claimedEmp[$t['id']] = $s['name'] . ' (' . $s['section'] . ')';
        $p['employee_id'] = $t['id']; $p['match_how'] = $t['how'];
        // resolve review items the owner already answered
        $p['review'] = array_values(array_filter($p['review'], function ($rv) use ($dec, $k) { $a = $dec[$rv['type'] . ':' . $k] ?? null;
            return match ($rv['type']) { 'status' => $a !== 'active', 'joining' => !in_array($a, ['blank'], true) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$a), 'bank' => !in_array($a, ['keep_csv', 'skip'], true), 'salary' => true, default => true }; }));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($dec['joining:' . $k] ?? ''))) { $p['joined_on'] = $dec['joining:' . $k]; $p['joined_note'] = 'entered by owner'; }
        if (($dec['bank:' . $k] ?? '') === 'skip') $p['bank'] = null;
        $p['code'] = $t['id'] ? (string)$emps[$t['id']]['code'] : ($c ? $c['code'] : '');
        if (!$t['id'] && $p['code'] !== '') { $taken = row('SELECT id FROM dd_employees WHERE code=?', $p['code']); if ($taken || isset($usedCodes[$p['code']])) { $p['issues'][] = 'CSV code ' . $p['code'] . ' is already used in the app by #' . ($taken['id'] ?? '?') . ' — a new code will be given'; $p['code'] = ''; } }
        if ($p['code'] !== '') $usedCodes[$p['code']] = true;
        // designation id (existing, or created on apply)
        $dg = null; foreach ($desigs as $d) if ((int)$d['dept_id'] === $p['dept_id'] && strcasecmp($d['name'], $p['desig']) === 0) $dg = $d; $p['desig_id'] = $dg ? (int)$dg['id'] : null; $p['desig_new'] = !$dg;
        // action
        if ($p['review']) $p['action'] = 'review';
        elseif (!$t['id']) $p['action'] = 'create';
        else { $e = $emps[$t['id']]; $chg = [];
            if (COALESCE_status($e) !== 'active') $chg[] = 'status ' . COALESCE_status($e) . ' → active';
            if ((int)($e['f3_dept_id'] ?? 0) !== $p['dept_id']) $chg[] = 'department → ' . F3_DEPTS[$p['dept_id']][0];
            if ($p['desig_new'] || (int)($e['f3_desig_id'] ?? 0) !== (int)$p['desig_id']) $chg[] = 'designation → ' . $p['desig'];
            $rate = f3_rate_on((int)$e['id'], $rateFrom); if (!$rate || (int)$rate['monthly'] !== (int)$p['monthly']) $chg[] = 'salary ' . ($rate ? '₹' . number_format((int)$rate['monthly'] / 100) : 'none') . ' → ₹' . number_format($p['monthly'] / 100) . ' from ' . $rateFrom;
            if ($c && $c['mobile'] && $e['_mobile'] === '') $chg[] = 'mobile added';
            if ($c && $e['_mobile'] !== '' && $c['mobile'] && $e['_mobile'] !== $c['mobile']) $p['issues'][] = 'App mobile ' . ei_mask($e['_mobile']) . ' differs from CSV ' . ei_mask($c['mobile']) . ' — app value kept';
            if ($p['bank'] && $e['_acct'] === '' && f3_pii_key()) $chg[] = 'bank details added';
            if ($p['bank'] && $e['_acct'] !== '' && $e['_acct'] !== $p['bank']['account']) $p['issues'][] = 'App bank account ' . ei_mask($e['_acct']) . ' differs from the import ' . ei_mask($p['bank']['account']) . ' — app value kept';
            if ($p['joined_on'] && empty($e['joined_on'])) $chg[] = 'joining date ' . $p['joined_on'];
            if (!empty($e['joined_on']) && $e['joined_on'] > EI_PERIOD . '-30') { if (isset($dec['joining:' . $k])) $chg[] = 'joining date ' . $e['joined_on'] . ' → ' . ($p['joined_on'] ?? 'blank'); else { $p['review'][] = ['type' => 'joining', 'text' => 'In the app this person joined on ' . $e['joined_on'] . ' (after September) but is on the September sheet — this is why staff can go missing from salary. Enter the real joining date or leave it blank']; } }
            if ($c && !isset($links[$c['code']])) $chg[] = 'link CSV code ' . $c['code'];
            $p['changes'] = $chg; $p['action'] = $p['review'] ? 'review' : ($chg ? 'update' : 'unchanged'); }
        if (!f3_pii_key() && $p['bank']) { $p['issues'][] = 'Bank details not imported: pii_key missing in config.php'; $p['bank'] = null; }
        $rows[$k] = $p; }
    // existing staff absent from September
    $inactive = [];
    foreach ($emps as $id => $e) { if (isset($claimedEmp[$id])) continue; $st = COALESCE_status($e); if ($st !== 'active') continue;
        $key = 'E' . $id; $why = 'Not on the September 2026 sheet'; $rv = null;
        if (!empty($e['joined_on']) && $e['joined_on'] > EI_PERIOD . '-30' && ($dec['inactive:' . $key] ?? '') !== 'inactive') $rv = 'Joined ' . $e['joined_on'] . ', after September — so they could not be on the September sheet. Keep active?';
        if (isset($pending[$id]) && !isset($dec['inactive:' . $key])) $rv = 'Possible match for September row ' . implode(' / ', $pending[$id]) . ' — decide that row first (or choose here)';
        $d = $dec['inactive:' . $key] ?? null;
        $inactive[$key] = ['key' => $key, 'employee_id' => $id, 'name' => $e['name'], 'code' => $e['code'], 'dept' => F3_DEPTS[(int)($e['f3_dept_id'] ?? 0)][0] ?? '—', 'joined_on' => $e['joined_on'],
            'action' => $d === 'keep' ? 'unchanged' : ($rv && $d !== 'inactive' ? 'review' : 'inactive'), 'reason' => $d === 'keep' ? 'Owner: keep active' : ($rv && $d !== 'inactive' ? $rv : $why)]; }
    // CSV people not on September (information; they are not created)
    $usedCsv = []; foreach ($rows as $p) if ($p['csv']) $usedCsv[$p['csv']['code']] = true;
    $csvOnly = array_values(array_map(fn($c) => ['code' => $c['code'], 'name' => $c['name'], 'dept' => $c['dept_raw'], 'status' => $c['status'], 'doj' => $c['doj']], array_filter($csv, fn($c) => !isset($usedCsv[$c['code']]))));
    $bankOnly = array_values(array_filter($bank, function ($x) use ($csv) { foreach ($csv as $c) if (($c['mobile'] && $c['mobile'] === $x['mobile']) || ($c['account'] && $c['account'] === $x['account'])) return false; return true; }));
    $cnt = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'review' => 0, 'inactive' => 0];
    foreach ($rows as $p) $cnt[$p['action']]++; foreach ($inactive as $x) $cnt[$x['action'] === 'unchanged' ? 'unchanged' : $x['action']]++;
    $planCodes = []; foreach ($rows as $p) if ($p['csv'] && $p['employee_id']) $planCodes[$p['csv']['code']] = (int)$p['employee_id'];
    $fileCodes = []; foreach ($rows as $p) if ($p['csv']) $fileCodes[$p['csv']['code']] = true;
    $history = [];
    foreach ($S['history'] as $per => $hm) { $lk = 0; $nl = []; foreach ($hm['rows'] as $hr) { if ($hr['code'] !== '' && (isset($fileCodes[$hr['code']]) || ei_hist_who($hr['code'], $planCodes))) $lk++; else $nl[] = $hr['name'] . ($hr['code'] !== '' ? ' (code ' . $hr['code'] . ' not found)' : ' (no code)'); }
        $history[] = ['period' => $per, 'sheet' => $hm['sheet'], 'rows' => count($hm['rows']), 'linked' => $lk, 'not_linked' => $nl, 'monthly' => array_sum(array_map(fn($r) => (int)$r['monthly'], $hm['rows'])),
            'total' => array_sum(array_map(fn($r) => (int)$r['total'], $hm['rows'])), 'advance' => array_sum(array_map(fn($r) => (int)$r['advance'], $hm['rows'])), 'paid' => array_sum(array_map(fn($r) => (int)$r['paid'], $hm['rows'])),
            'exists' => (int)(row('SELECT COUNT(*) n FROM f3_sal_history WHERE period=? AND superseded_by IS NULL', $per)['n'] ?? 0)]; }
    return ['batch' => $b, 'mode' => $S['mode'], 'history' => $history, 'history_rows' => $S['history'], 'check' => $check, 'csv_issues' => $csvIssues, 'rows' => $rows, 'inactive' => $inactive, 'csv_only' => $csvOnly, 'bank_only' => array_map(fn($x) => ['holder' => $x['holder'], 'account' => ei_mask($x['account']), 'mobile' => ei_mask($x['mobile']), 'desig' => $x['desig']], $bankOnly),
        'counts' => $cnt, 'rate_from' => $rateFrom, 'inspect' => ei_inspect(), 'ready' => $cnt['review'] === 0 && ei_inspect()['schema_ok']];
}
function COALESCE_status(array $e): string { $s = strtolower((string)($e['status'] ?? '')); return $s === '' ? ((int)($e['active'] ?? 1) ? 'active' : 'inactive') : $s; }

/* ------------------------------ upload, decide, apply, rollback ------------------------------ */
function ei_upload(array $b, array $u): array {
    ei_need($u); $files = []; $hashes = []; $one = !empty($b['oneclick']['data']);
    $want = $one ? ['oneclick' => ['xlsx']] : ['salary' => ['xlsx'], 'csv' => ['csv'], 'bank' => ['xlsx']];
    foreach ($want as $kind => $ext) { $f = $b[$kind] ?? null; if (!$f || empty($f['data'])) { if ($kind === 'bank') continue; fail('Upload the ' . ($kind === 'salary' ? 'salary workbook (.xlsx)' : 'employee CSV') . '.'); }
        $data = base64_decode((string)$f['data'], true); if ($data === false || $data === '') fail('Empty file: ' . $kind); if (strlen($data) > 5 * 1024 * 1024) fail('File too large: ' . $kind, 413);
        if ($ext[0] === 'xlsx' && substr($data, 0, 2) !== 'PK') fail(($kind === 'salary' ? 'Salary workbook' : ($kind === 'oneclick' ? 'The one-click import file' : 'Bank file')) . ' must be an .xlsx file.');
        if ($ext[0] === 'csv' && preg_match('/[\x00-\x08]/', substr($data, 0, 2000))) fail('The employee file must be a CSV (text) file.');
        $h = hash('sha256', $data); $hashes[] = $kind . ':' . $h; $files[] = ['kind' => $kind, 'name' => mb_substr(basename((string)($f['name'] ?? $kind)), 0, 120), 'sha256' => $h, 'size' => strlen($data), 'stored' => $kind . '-' . substr($h, 0, 16) . '.' . $ext[0], '_data' => $data]; }
    $base = 'EMP-' . strtoupper(substr(hash('sha256', implode('|', $hashes)), 0, 12)); $batch = $base;
    // same files again: reuse the open/applied batch; after an undo, start a fresh attempt (-2, -3 …) and keep the old one as history
    for ($n = 2; ($x = row('SELECT status FROM f3_import_batches WHERE batch_id=?', $batch)) && $x['status'] === 'rolled_back'; $n++) $batch = $base . '-' . $n;
    $ex = row('SELECT batch_id,status FROM f3_import_batches WHERE batch_id=?', $batch);
    if (!$ex) { $dir = ei_dir($batch); foreach ($files as &$f) { if (file_put_contents($dir . '/' . $f['stored'], $f['_data']) === false) fail('private/imports is not writable.', 503); @chmod($dir . '/' . $f['stored'], 0640); unset($f['_data']); } unset($f);
        // parse once now so a wrong file is refused before anything is stored as a batch
        // a re-run keeps the owner's earlier answers — only from a batch of the same kind of upload (row keys mean different rows in the two formats)
        $prevB = row("SELECT batch_id,decisions,decision_fps,files FROM f3_import_batches WHERE kind=? AND status='applied' ORDER BY applied_at DESC LIMIT 1", EI_KIND);
        $prevB = $prevB && (str_contains((string)$prevB['files'], '"oneclick"') === $one) ? $prevB : null;
        run('INSERT INTO f3_import_batches(batch_id,kind,status,files,decisions,created_by) VALUES(?,?,?,?,?,?)', $batch, EI_KIND, 'preview', json_encode($files), null, $u['id']);
        try { $carry = ei_carry_decisions($prevB, $batch); run('UPDATE f3_import_batches SET decisions=?,decision_fps=? WHERE batch_id=?', $carry['decisions'] ? json_encode($carry['decisions']) : null, $carry['fps'] ? json_encode($carry['fps']) : null, $batch); ei_plan($batch); }
        catch (Throwable $e) { run('DELETE FROM f3_import_batches WHERE batch_id=?', $batch); throw $e; }
        if ($prevB) f3_audit($u, 'employee_import.decisions_carry', ['batch' => $batch, 'from' => $prevB['batch_id'], 'kept' => count($carry['decisions']), 'dropped' => $carry['dropped']]);
        f3_audit($u, 'employee_import.upload', ['batch' => $batch, 'files' => array_map(fn($f) => ['name' => $f['name'], 'sha256' => $f['sha256'], 'size' => $f['size']], $files)]); }
    return ['batch' => $batch, 'existing' => (bool)$ex, 'status' => $ex['status'] ?? 'preview'];
}
function ei_decide(array $b, array $u): array {
    ei_need($u); $batch = (string)($b['batch'] ?? ''); $r = row('SELECT * FROM f3_import_batches WHERE batch_id=? FOR UPDATE', $batch); if (!$r) fail('Import batch not found.', 404);
    if ($r['status'] !== 'preview') fail('This batch was already ' . $r['status'] . '. Upload the files again to start a new preview.', 409);
    $dec = json_decode((string)$r['decisions'], true) ?: [];
    foreach ((array)($b['decisions'] ?? []) as $k => $v) { if (!preg_match('/^(src|emp|status|joining|bank|inactive):[SE]\d{1,6}$/', (string)$k)) fail('Invalid decision key ' . $k); $v = trim((string)$v);
        if ($v === '') { unset($dec[$k]); continue; } if (!preg_match('/^([A-Za-z0-9_-]{1,40}|\d{4}-\d{2}-\d{2})$/', $v)) fail('Invalid decision value for ' . $k); $dec[$k] = $v; }
    $fps = ei_row_fps($batch); $keep = []; foreach (array_keys($dec) as $k) { $rk = substr($k, strpos($k, ':') + 1); if (isset($fps[$rk])) $keep[$rk] = $fps[$rk]; }
    run('UPDATE f3_import_batches SET decisions=?,decision_fps=? WHERE batch_id=?', json_encode($dec), json_encode($keep), $batch);
    f3_audit($u, 'employee_import.decide', ['batch' => $batch, 'decisions' => $b['decisions'] ?? []]);
    return ['ok' => true];
}
/** V23 P1-02 — identity fingerprint of every September row of a batch (row key → hash of section, S.No, full name, salary, HR code).
 *  A decision such as "src:S7" means "the person on row 7"; it is only valid for a file where row 7 is still the same person. */
function ei_row_fps(string $batch): array {
    $S = ei_sources($batch); $o = [];
    foreach ($S['sept'] as $x) $o[$x['key']] = hash('sha256', json_encode([$x['section'], (int)$x['sno'], ei_full_key((string)$x['name']), $x['monthly'], (string)($S['file_dec']['src:' . $x['key']] ?? '')]));
    return $o;
}
/** Answers from the last applied batch are carried to a NEW file only when they point at the same person:
 *  S-row answers need an identical row fingerprint (else dropped → the owner reviews again); E-answers (existing staff id) need that staff to exist. */
function ei_carry_decisions(?array $prevB, string $newBatch): array {
    $out = ['decisions' => [], 'fps' => [], 'dropped' => 0]; if (!$prevB || !$prevB['decisions']) return $out;
    $dec = json_decode((string)$prevB['decisions'], true) ?: []; $old = json_decode((string)($prevB['decision_fps'] ?? ''), true);
    if (!is_array($old)) { try { $old = ei_row_fps((string)$prevB['batch_id']); } catch (Throwable $e) { $old = []; } } // pre-V23 batch: recompute from its stored files, if still there
    $new = ei_row_fps($newBatch);
    foreach ($dec as $k => $v) { if (!is_string($k) || !str_contains($k, ':')) continue; $rk = substr($k, strpos($k, ':') + 1);
        if ($rk[0] === 'E') { if (row('SELECT 1 FROM dd_employees WHERE id=?', (int)substr($rk, 1))) $out['decisions'][$k] = $v; else $out['dropped']++; continue; }
        if (isset($old[$rk], $new[$rk]) && hash_equals($old[$rk], $new[$rk])) { $out['decisions'][$k] = $v; $out['fps'][$rk] = $new[$rk]; } else $out['dropped']++; }
    return $out;
}
/** V23 P1-08 — every table/column that can reference an employee (found from the live schema, not a fixed list). */
function ei_employee_refs(int $id, string $batch): array {
    $skip = ['f3_import_rows', 'f3_ext_ids', 'f3_salary_rates', 'f3_assignments', 'f3_sal_history', 'dd_audit', 'f3_audit', 'dd_employees'];
    $o = [];
    foreach (rows("SELECT TABLE_NAME t,COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('employee_id','emp_id','staff_id') AND (TABLE_NAME LIKE 'f3\\_%' OR TABLE_NAME LIKE 'dd\\_%')") as $r) {
        if (in_array($r['t'], $skip, true)) continue;
        $n = (int)row('SELECT COUNT(*) n FROM `' . $r['t'] . '` WHERE `' . $r['c'] . '`=?', $id)['n']; if ($n) $o[$r['t'] . '.' . $r['c']] = $n; }
    // salary rates / assignments / history NOT created by this batch also mean the person is in use
    foreach (['f3_salary_rates' => "note NOT LIKE ?", 'f3_assignments' => "COALESCE(note,'') NOT LIKE ?"] as $t => $w) { $n = (int)row("SELECT COUNT(*) n FROM $t WHERE employee_id=? AND $w", $id, '%' . $batch . '%')['n']; if ($n) $o[$t] = $n; }
    $n = (int)row('SELECT COUNT(*) n FROM f3_sal_history WHERE employee_id=? AND batch_id<>?', $id, $batch)['n']; if ($n) $o['f3_sal_history(other batches)'] = $n;
    try { $n = (int)row('SELECT COUNT(*) n FROM dd_files WHERE ref_type=? AND ref_id=?', 'employee', $id)['n']; if ($n) $o['dd_files'] = $n; } catch (Throwable $e) {}
    return $o;
}
function ei_desig_id(int $dept, string $name, array $u): int {
    $d = row('SELECT id,active FROM f3_designations WHERE dept_id=? AND name=?', $dept, $name);
    if ($d) { if (!(int)$d['active']) { run('UPDATE f3_designations SET active=1 WHERE id=?', $d['id']); $GLOBALS['EI_REACT'][] = (int)$d['id']; } return (int)$d['id']; }
    run('INSERT INTO f3_designations(dept_id,name,sort,active) VALUES(?,?,?,1)', $dept, $name, 50); $id = (int)db()->lastInsertId(); $GLOBALS['EI_NEWD'][] = $id; return $id;
}
function ei_apply(array $b, array $u): array {
    ei_need($u); $batch = (string)($b['batch'] ?? '');
    if (strtoupper(trim((string)($b['confirm'] ?? ''))) !== 'IMPORT') fail('Type IMPORT to confirm.');
    $r = row('SELECT * FROM f3_import_batches WHERE batch_id=?', $batch); if (!$r) fail('Import batch not found.', 404);
    if ($r['status'] === 'applied') return ['ok' => true, 'already' => true, 'note' => 'This batch was already applied on ' . $r['applied_at'] . '. Nothing was changed again.'];
    if ($r['status'] !== 'preview') fail('This batch is ' . $r['status'] . '.', 409);
    $plan = ei_plan($batch); if (!$plan['inspect']['schema_ok']) fail('Database schema is not ready: ' . implode(', ', $plan['inspect']['missing']), 409);
    if ($plan['counts']['review']) fail($plan['counts']['review'] . ' row(s) still need your decision.', 409);
    // backup first (outside the import transaction)
    $bk = null; if (row("SELECT 1 FROM f3_int_secrets WHERE name='backup_passphrase'")) { require_once __DIR__ . '/integrations.php'; $bk = f3_backup_run($u, true)['name']; }
    elseif (empty($b['backup_ok'])) fail('Take a backup first (phpMyAdmin → Export), then tick "I have a backup" — or set a backup passphrase so the app takes one automatically.', 409);
    $before = ei_counts();
    $res = transaction(function () use ($plan, $batch, $u, $bk, $before) {
        $lk = row('SELECT status FROM f3_import_batches WHERE batch_id=? FOR UPDATE', $batch); if ($lk['status'] !== 'preview') fail('This batch was applied in the meantime.', 409);
        $done = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'inactive' => 0]; $GLOBALS['EI_NEWD'] = []; $GLOBALS['EI_REACT'] = [];
        foreach ($plan['rows'] as $k => $p) {
            if ($p['action'] === 'unchanged') { $done['unchanged']++; run('INSERT INTO f3_import_rows(batch_id,row_key,action,employee_id,after_json,note) VALUES(?,?,?,?,?,?)', $batch, $k, 'unchanged', $p['employee_id'],
                json_encode(['dept' => $p['dept_id'], 'desig' => $p['desig'], 'monthly' => $p['monthly'], 'csv' => $p['csv']['code'] ?? null, 'sept' => $p['sept']['name']]), 'Already correct'); continue; }
            $desigId = $p['desig_id'] ?: ei_desig_id($p['dept_id'], $p['desig'], $u); $dName = $p['desig']; $section = ['1' => 'Manager', '2' => 'UT Staff', '3' => 'Kitchen', '4' => 'Waiter'][(string)$p['dept_id']];
            $rates = []; $asg = []; $exts = []; $c = $p['csv'];
            $alias = implode(', ', array_unique(array_filter([$p['sept']['name'], $c['name'] ?? null, $c['display'] ?? null])));
            if ($p['action'] === 'create') {
                $code = $p['code'] !== '' ? $p['code'] : sal_next_code_f3();
                $vals = ['name' => $c['name'] ?? $p['sept']['name'], 'aliases' => $alias, 'job' => $dName, 'code' => $code, 'phone' => ($c['mobile'] ?? '') ?: null, 'pay_basis' => 'monthly', 'pay_mode' => 'Cash', 'joined_on' => $p['joined_on'],
                    'f3_dept_id' => $p['dept_id'], 'f3_desig_id' => $desigId, 'designation' => $dName, 'section' => $section, 'salary' => $p['monthly'], 'status' => 'active', 'active' => 1, 'created_by' => $u['id'], 'updated_at' => gmdate('Y-m-d H:i:s'),
                    'notes' => 'Imported ' . $batch . ' from the September 2026 salary sheet'];
                if ($p['bank']) $vals += ['bank_name' => $p['bank']['bank_name'] ?: null, 'ifsc' => $p['bank']['ifsc'], 'bank_account_enc' => f3_enc($p['bank']['account']), 'account_last4' => substr($p['bank']['account'], -4)];
                run('INSERT INTO dd_employees(' . implode(',', array_keys($vals)) . ') VALUES(' . implode(',', array_fill(0, count($vals), '?')) . ')', ...array_values($vals)); $id = (int)db()->lastInsertId();
                run('INSERT INTO f3_salary_rates(employee_id,effective_from,monthly,basis,note,source,created_by) VALUES(?,?,?,?,?,?,?)', $id, $p['joined_on'] ?? $plan['rate_from'], $p['monthly'], 'monthly', 'Import ' . $batch . ' (September 2026 sheet)', 'import', $u['id']); $rates[] = (int)db()->lastInsertId();
                run('INSERT INTO f3_assignments(employee_id,dept_id,desig_id,effective_from,note,created_by) VALUES(?,?,?,?,?,?)', $id, $p['dept_id'], $desigId, $p['joined_on'] ?? $plan['rate_from'], 'Import ' . $batch, $u['id']); $asg[] = (int)db()->lastInsertId();
                $beforeRow = null; $done['create']++;
            } else {
                $id = (int)$p['employee_id']; $e = row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE', $id); $beforeRow = $e; $beforeRow['_had_bank'] = empty($e['bank_account_enc']) ? 0 : 1;
                $vals = ['status' => 'active', 'active' => 1, 'f3_dept_id' => $p['dept_id'], 'f3_desig_id' => $desigId, 'designation' => $dName, 'section' => $section, 'job' => $dName, 'updated_at' => gmdate('Y-m-d H:i:s')];
                $al = array_unique(array_filter(array_map('trim', array_merge(preg_split('/,/', (string)$e['aliases']), explode(', ', $alias))))); $vals['aliases'] = mb_substr(implode(', ', $al), 0, 500);
                if (empty($e['phone']) && !empty($c['mobile'])) $vals['phone'] = $c['mobile'];
                if ($p['bank'] && empty($e['bank_account_enc'])) $vals += ['bank_name' => $p['bank']['bank_name'] ?: null, 'ifsc' => $p['bank']['ifsc'], 'bank_account_enc' => f3_enc($p['bank']['account']), 'account_last4' => substr($p['bank']['account'], -4)];
                if ($p['joined_on'] && (empty($e['joined_on']) || $e['joined_on'] > EI_PERIOD . '-30')) $vals['joined_on'] = $p['joined_on'];
                elseif (!empty($e['joined_on']) && $e['joined_on'] > EI_PERIOD . '-30' && !$p['joined_on']) $vals['joined_on'] = null; // owner chose "unknown"
                $rate = f3_rate_on($id, $plan['rate_from']);
                if (!$rate || (int)$rate['monthly'] !== (int)$p['monthly']) { run('INSERT INTO f3_salary_rates(employee_id,effective_from,monthly,basis,note,source,created_by) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', $id, $plan['rate_from'], $p['monthly'], 'monthly', 'Import ' . $batch . ' (September 2026 sheet)', 'import', $u['id']);
                    $rid = (int)db()->lastInsertId(); $prev = row('SELECT monthly,note FROM f3_salary_rates WHERE id=?', $rid); if ((int)$prev['monthly'] !== (int)$p['monthly']) fail('A salary rate already exists for ' . $e['name'] . ' from ' . $plan['rate_from'] . ' with a different amount. Change it in Staff first.', 409);
                    if (str_contains((string)$prev['note'], $batch)) $rates[] = $rid; $cur = f3_rate_on($id, f3_business_date()); $vals['salary'] = $cur ? (int)$cur['monthly'] : $p['monthly']; }
                if ((int)$e['f3_dept_id'] !== $p['dept_id'] || (int)$e['f3_desig_id'] !== $desigId) { run('INSERT INTO f3_assignments(employee_id,dept_id,desig_id,effective_from,note,created_by) VALUES(?,?,?,?,?,?)', $id, $p['dept_id'], $desigId, $plan['rate_from'], 'Import ' . $batch, $u['id']); $asg[] = (int)db()->lastInsertId(); }
                $set = implode(',', array_map(fn($k) => "`$k`=?", array_keys($vals))); run("UPDATE dd_employees SET $set WHERE id=?", ...array_merge(array_values($vals), [$id])); $done['update']++;
            }
            if ($c && !row('SELECT 1 FROM f3_ext_ids WHERE source=? AND ext_key=?', EI_SRC, $c['code'])) { run('INSERT INTO f3_ext_ids(source,ext_key,employee_id,batch_id) VALUES(?,?,?,?)', EI_SRC, $c['code'], $id, $batch); $exts[] = (int)db()->lastInsertId(); }
            unset($beforeRow['bank_account_enc']);
            run('INSERT INTO f3_import_rows(batch_id,row_key,action,employee_id,before_json,after_json,created_rate_ids,created_assignment_ids,created_ext_ids,note) VALUES(?,?,?,?,?,?,?,?,?,?)', $batch, $k, $p['action'], $id,
                $beforeRow ? json_encode($beforeRow) : null,
                json_encode(['dept' => $p['dept_id'], 'desig' => $dName, 'monthly' => $p['monthly'], 'csv' => $c['code'] ?? null, 'sept' => $p['sept']['name']]), json_encode($rates), json_encode($asg), json_encode($exts), mb_substr(implode('; ', $p['changes'] ?? []), 0, 500));
        }
        foreach ($plan['inactive'] as $k => $x) { if ($x['action'] !== 'inactive') continue; $e = row('SELECT * FROM dd_employees WHERE id=? FOR UPDATE', $x['employee_id']); unset($e['bank_account_enc']);
            run("UPDATE dd_employees SET status='inactive',active=0,updated_at=? WHERE id=?", gmdate('Y-m-d H:i:s'), $x['employee_id']); // no left_on: termination date is NOT inferred
            run('INSERT INTO f3_import_rows(batch_id,row_key,action,employee_id,before_json,note) VALUES(?,?,?,?,?,?)', $batch, $k, 'inactive', $x['employee_id'], json_encode($e), 'Not on the September 2026 sheet'); $done['inactive']++; }
        // old months: record only (no salary, advance, payment, carry-forward or cash-book entry is made from them)
        $done['history_rows'] = 0; $done['history_linked'] = 0; $done['history_periods'] = [];
        if ($plan['history_rows']) { $codes = []; foreach (rows('SELECT ext_key,employee_id FROM f3_ext_ids WHERE source=?', EI_SRC) as $l) $codes[$l['ext_key']] = (int)$l['employee_id'];
            foreach ($plan['history_rows'] as $per => $hm) {
                run('UPDATE f3_sal_history SET superseded_by=? WHERE period=? AND superseded_by IS NULL AND batch_id<>?', $batch, $per, $batch);
                foreach ($hm['rows'] as $hr) { $who = ei_hist_who($hr['code'], $codes); $done['history_rows']++; if ($who) $done['history_linked']++;
                    run('INSERT INTO f3_sal_history(batch_id,period,sheet,sheet_row,section,sno,name,hr_code,employee_id,designation,monthly,present,leave_days,working_days,total_salary,advance,debt,net_salary,pay_mode,paid,balance,pay_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                        $batch, $per, $hm['sheet'], $hr['sheet_row'], $hr['section'], $hr['sno'], $hr['name'], $hr['code'] ?: null, $who, $hr['desig'] ?: null, $hr['monthly'], $hr['present'], $hr['leave'], $hr['working'], $hr['total'], $hr['advance'], $hr['debt'], $hr['net'], $hr['mode'] ?: null, $hr['paid'], $hr['balance'], $hr['pay_status'] ?: null); }
                $done['history_periods'][] = $per; } }
        $after = ei_counts();
        foreach (['payroll_lines', 'staff_txns', 'books_txns', 'pay_payments', 'old_register_rows', 'old_register_entries'] as $z) if ($after[$z] !== $before[$z]) fail('Safety stop: ' . $z . ' changed during the import. Nothing was saved.', 500);
        $done['designations_created'] = $GLOBALS['EI_NEWD']; $done['designations_reactivated'] = $GLOBALS['EI_REACT'];
        $seq = (int)row('SELECT COALESCE(MAX(applied_seq),0)+1 n FROM f3_import_batches')['n'];
        run("UPDATE f3_import_batches SET status='applied',applied_seq=$seq,applied_by=?,applied_at=UTC_TIMESTAMP(),backup_name=?,summary=?,before_counts=?,after_counts=? WHERE batch_id=?", $u['id'], $bk, json_encode($done), json_encode($before), json_encode($after), $batch);
        f3_audit($u, 'employee_import.apply', ['batch' => $batch, 'done' => $done, 'before' => $before, 'after' => $after, 'backup' => $bk]);
        return ['ok' => true, 'batch' => $batch, 'done' => $done, 'before' => $before, 'after' => $after, 'backup' => $bk];
    });
    $res['wizard'] = ei_verify_wizard($batch);
    return $res;
}
/** Which salary month would show these staff, and would each imported active person appear in the right department? */
function ei_verify_wizard(string $batch, ?string $period = null): array {
    $period ??= f3_payroll_due_period() ?? f3_prev_month(substr(f3_business_date(), 0, 7));
    $a = $period . '-01'; $z = f3_month_last($period); $o = ['period' => $period, 'ok' => 0, 'problems' => []];
    foreach (rows("SELECT r.row_key,r.employee_id,r.after_json,e.name,e.f3_dept_id,e.status,e.joined_on,e.left_on FROM f3_import_rows r JOIN dd_employees e ON e.id=r.employee_id WHERE r.batch_id=? AND r.action IN ('create','update','unchanged')", $batch) as $r) {
        $want = (int)(json_decode((string)$r['after_json'], true)['dept'] ?? 0);
        $eligible = ($r['joined_on'] === null || $r['joined_on'] <= $z) && ($r['left_on'] === null || $r['left_on'] >= $a) && $r['status'] === 'active';
        if ($eligible && (int)$r['f3_dept_id'] === $want) $o['ok']++; else $o['problems'][] = $r['name'] . ': ' . (!$eligible ? 'would not be on the ' . $period . ' sheet (joined ' . ($r['joined_on'] ?? '—') . ', status ' . $r['status'] . ')' : 'department differs');
    }
    return $o;
}
function ei_rollback(array $b, array $u): array {
    ei_need($u); $batch = (string)($b['batch'] ?? ''); $why = f3_text($b['reason'] ?? '', 500, 'Reason for rollback');
    return transaction(function () use ($batch, $why, $u) {
        $r = row('SELECT * FROM f3_import_batches WHERE batch_id=? FOR UPDATE', $batch); if (!$r || $r['status'] !== 'applied') fail('Only an applied batch can be rolled back.', 409);
        $later = $r['applied_seq'] !== null ? row("SELECT batch_id FROM f3_import_batches WHERE kind=? AND status='applied' AND (applied_seq>? OR (applied_seq IS NULL AND applied_at>?)) LIMIT 1", EI_KIND, (int)$r['applied_seq'], $r['applied_at'])
            : row("SELECT batch_id FROM f3_import_batches WHERE kind=? AND status='applied' AND (applied_at>? OR applied_seq IS NOT NULL) AND batch_id<>? LIMIT 1", EI_KIND, $r['applied_at'], $batch); if ($later) fail('A later import (' . $later['batch_id'] . ') was applied after this one. Roll that back first.', 409);
        $kept = []; $keptWhy = []; $n = 0;
        foreach (rows('SELECT * FROM f3_import_rows WHERE batch_id=? ORDER BY id DESC', $batch) as $x) { $id = (int)$x['employee_id'];
            foreach (json_decode((string)$x['created_ext_ids'], true) ?: [] as $eid) run('DELETE FROM f3_ext_ids WHERE id=?', $eid);
            foreach (json_decode((string)$x['created_assignment_ids'], true) ?: [] as $aid) run('DELETE FROM f3_assignments WHERE id=?', $aid);
            foreach (json_decode((string)$x['created_rate_ids'], true) ?: [] as $rid) run('DELETE FROM f3_salary_rates WHERE id=?', $rid);
            if ($x['action'] === 'create') {
                $refs = ei_employee_refs($id, $batch); // V23: ALL references (attendance, files, WhatsApp recipients, new tables …), not only payroll
                if ($refs) { run("UPDATE dd_employees SET status='inactive',active=0 WHERE id=?", $id); $kept[] = $id; $keptWhy[$id] = $refs; }
                else { run('DELETE FROM dd_employees WHERE id=?', $id); }
            } else { $bf = json_decode((string)$x['before_json'], true) ?: []; $cols = ['status', 'active', 'f3_dept_id', 'f3_desig_id', 'designation', 'section', 'job', 'aliases', 'phone', 'joined_on', 'salary', 'updated_at'];
                $hadBank = (int)($bf['_had_bank'] ?? 1) === 1; $set = []; $vals = [];
                foreach ($cols as $c) if (array_key_exists($c, $bf)) { $set[] = "`$c`=?"; $vals[] = $bf[$c]; }
                if (!$hadBank) { $set[] = 'bank_account_enc=NULL'; $set[] = 'account_last4=NULL'; $set[] = 'ifsc=?'; $vals[] = $bf['ifsc'] ?? null; $set[] = 'bank_name=?'; $vals[] = $bf['bank_name'] ?? null; }
                if ($set) run('UPDATE dd_employees SET ' . implode(',', $set) . ' WHERE id=?', ...array_merge($vals, [$id])); }
            $n++; }
        $hd = run('DELETE FROM f3_sal_history WHERE batch_id=?', $batch)->rowCount(); run('UPDATE f3_sal_history SET superseded_by=NULL WHERE superseded_by=?', $batch); // earlier copies of those months come back
        $sm = json_decode((string)$r['summary'], true) ?: [];
        foreach ($sm['designations_created'] ?? [] as $did) if (!row('SELECT 1 FROM dd_employees WHERE f3_desig_id=? UNION SELECT 1 FROM f3_assignments WHERE desig_id=? UNION SELECT 1 FROM f3_pay_lines WHERE desig_id=? LIMIT 1', $did, $did, $did)) run('DELETE FROM f3_designations WHERE id=?', $did);
        foreach ($sm['designations_reactivated'] ?? [] as $did) run('UPDATE f3_designations SET active=0 WHERE id=?', $did);
        run("UPDATE f3_import_batches SET status='rolled_back',rolled_back_by=?,rolled_back_at=UTC_TIMESTAMP(),rollback_note=? WHERE batch_id=?", $u['id'], $why, $batch);
        f3_audit($u, 'employee_import.rollback', ['batch' => $batch, 'rows' => $n, 'history_rows_removed' => $hd, 'kept_inactive' => $kept, 'kept_references' => $keptWhy, 'reason' => $why]);
        return ['ok' => true, 'rows' => $n, 'kept_as_inactive' => $kept, 'note' => $kept ? count($kept) . ' new staff already had salary/advance records, so they were set Inactive instead of deleted.' : ''];
    });
}
/** Public view for the browser: masks mobiles and bank accounts. */
function ei_preview(string $batch, array $u): array {
    ei_need($u); $p = ei_plan($batch); $dept = fn(int $d) => F3_DEPTS[$d][0] ?? '—';
    $rows = array_values(array_map(fn($x) => ['key' => $x['key'], 'action' => $x['action'], 'section' => $x['sept']['section'], 'sno' => $x['sept']['sno'], 'sept_name' => $x['sept']['name'], 'sheet_row' => $x['sept']['sheet_row'], 'present' => $x['sept']['present'],
        'dept' => $dept($x['dept_id']), 'desig' => $x['desig'] . ($x['desig_new'] ? ' (new designation)' : ''), 'monthly' => $x['monthly'],
        'csv' => $x['csv'] ? ['code' => $x['csv']['code'], 'name' => $x['csv']['name'], 'display' => $x['csv']['display'], 'mobile' => ei_mask($x['csv']['mobile']), 'dept' => $x['csv']['dept_raw'], 'status' => $x['csv']['status']] : null,
        'source_tier' => $x['source_tier'], 'source_reason' => $x['source_reason'], 'candidates' => $x['candidates'], 'employee_id' => $x['employee_id'], 'match_how' => $x['match_how'], 'code' => $x['code'],
        'joined_on' => $x['joined_on'], 'joined_note' => $x['joined_note'], 'bank' => $x['bank'] ? ['bank' => $x['bank']['bank_name'], 'ifsc' => $x['bank']['ifsc'], 'account' => ei_mask($x['bank']['account']), 'source' => $x['bank']['source']] : null,
        'bank_offer' => isset($x['bank_offer']) ? ['holder' => $x['bank_offer']['holder'], 'account' => ei_mask($x['bank_offer']['account']), 'ifsc' => $x['bank_offer']['ifsc']] : null,
        'changes' => $x['changes'] ?? [], 'issues' => $x['issues'], 'review' => $x['review']], $p['rows']));
    $csvNames = []; foreach (ei_sources($batch)['csv'] as $c) $csvNames[$c['code']] = $c['name'] . ($c['display'] ? ' (' . $c['display'] . ')' : '') . ' · ' . $c['dept_raw'] . ' · ' . $c['status'];
    return ['batch' => ['id' => $batch, 'status' => $p['batch']['status'], 'created_at' => $p['batch']['created_at'], 'applied_at' => $p['batch']['applied_at'], 'files' => array_map(fn($f) => ['kind' => $f['kind'], 'name' => $f['name'], 'sha256' => $f['sha256'], 'size' => $f['size']], json_decode($p['batch']['files'], true)),
        'summary' => json_decode((string)$p['batch']['summary'], true), 'before' => json_decode((string)$p['batch']['before_counts'], true), 'after' => json_decode((string)$p['batch']['after_counts'], true)],
        'mode' => $p['mode'], 'history' => $p['history'], 'check' => $p['check'], 'csv_issues' => $p['csv_issues'], 'rows' => $rows, 'inactive' => array_values($p['inactive']), 'csv_only' => $p['csv_only'], 'bank_only' => $p['bank_only'], 'counts' => $p['counts'], 'rate_from' => $p['rate_from'],
        'inspect' => $p['inspect'], 'ready' => $p['ready'], 'csv_choices' => $csvNames, 'staff_choices' => array_map(fn($e) => ['id' => (int)$e['id'], 'label' => '#' . $e['id'] . ' ' . $e['name'] . ' · ' . ($e['code'] ?? '') . ' · ' . COALESCE_status($e)], rows('SELECT id,name,code,status,active FROM dd_employees ORDER BY name')),
        'wizard' => $p['batch']['status'] === 'applied' ? ei_verify_wizard($batch) : null, 'current_counts' => ei_counts()];
}

/* ------------------------------ old salary months: read-only view ------------------------------ */
function ei_history_view(array $q, array $u): array {
    f3_need($u, 'payroll.view');
    $note = 'Copied from your old salary sheets. Not used for salary, advances, payments, carry-forward or the cash book.';
    try { $months = rows('SELECT period,MIN(sheet) sheet,COUNT(*) n,SUM(employee_id IS NOT NULL) linked,SUM(monthly) monthly,SUM(total_salary) total,SUM(advance) advance,SUM(debt) debt,SUM(net_salary) net,SUM(paid) paid,SUM(balance) balance,MIN(batch_id) batch,MIN(created_at) imported_at FROM f3_sal_history WHERE superseded_by IS NULL GROUP BY period ORDER BY period DESC'); }
    catch (PDOException $e) { return ['months' => [], 'rows' => [], 'note' => $note, 'ready' => false]; }
    $emp = isset($q['employee']) ? (int)$q['employee'] : 0; $per = isset($q['period']) && preg_match('/^\d{4}-\d{2}$/', (string)$q['period']) ? (string)$q['period'] : ($emp ? '' : ($months[0]['period'] ?? ''));
    $w = ['h.superseded_by IS NULL']; $a = []; if ($per !== '') { $w[] = 'h.period=?'; $a[] = $per; } if ($emp) { $w[] = 'h.employee_id=?'; $a[] = $emp; }
    $rows = $per === '' && !$emp ? [] : rows('SELECT h.id,h.period,h.section,h.sno,h.name,h.hr_code,h.employee_id,e.name staff_name,h.designation,h.monthly,h.present,h.leave_days,h.working_days,h.total_salary,h.advance,h.debt,h.net_salary,h.pay_mode,h.paid,h.balance,h.pay_status FROM f3_sal_history h LEFT JOIN dd_employees e ON e.id=h.employee_id WHERE ' . implode(' AND ', $w) . ' ORDER BY h.period DESC,h.sheet_row', ...$a);
    foreach ($rows as &$r) foreach (['id', 'sno', 'employee_id', 'monthly', 'total_salary', 'advance', 'debt', 'net_salary', 'paid', 'balance'] as $k) $r[$k] = $r[$k] === null ? null : (int)$r[$k]; unset($r);
    foreach ($months as &$m) foreach (['n', 'linked', 'monthly', 'total', 'advance', 'debt', 'net', 'paid', 'balance'] as $k) $m[$k] = (int)$m[$k]; unset($m);
    return ['months' => $months, 'period' => $per, 'employee' => $emp ? row('SELECT id,name,code FROM dd_employees WHERE id=?', $emp) : null, 'rows' => $rows, 'note' => $note, 'ready' => true];
}
