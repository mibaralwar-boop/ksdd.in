<?php
declare(strict_types=1);
// Finance 360 — reports and exports (CSV, XLSX, PDF). Pure PHP, no Composer (Hostinger shared hosting).
// PDF uses the built-in Helvetica font: Hindi (Devanagari) text cannot be shaped there, so it is transliterated/removed in PDF only.
// For Hindi names use “Print” → “Save as PDF” in the browser, which renders Hindi correctly (documented limitation).
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/closing.php';
require_once __DIR__ . '/money.php';
require_once __DIR__ . '/payroll.php';

function f3_report_daily(string $from, string $to, array $u): array {
    f3_need($u, 'reports.view'); $h = f3_sales_history($from, $to, $u);
    $exp = f3_can($u, 'expenses.view') ? f3_expense_list(['from' => $from, 'to' => $to], $u) : null;
    return ['history' => $h, 'expenses_by_category' => $exp['by_category'] ?? [], 'expenses_by_account' => $exp['by_account'] ?? [], 'expenses_total' => $exp['total'] ?? null];
}
function f3_report_monthly(string $m, array $u): array {
    f3_need($u, 'reports.view'); valid_month($m);
    $s = bk_month_summary($m);
    $s['cash_acess_label'] = 'Cash Acess';
    $cl = row("SELECT COUNT(*) n,SUM(status IN ('submitted','approved')) done,SUM(CASE WHEN operational_diff>0 THEN operational_diff ELSE 0 END) acess,SUM(CASE WHEN operational_diff<0 THEN -operational_diff ELSE 0 END) short,SUM(CASE WHEN accounting_variance>0 THEN accounting_variance ELSE 0 END) acc_over,SUM(CASE WHEN accounting_variance<0 THEN -accounting_variance ELSE 0 END) acc_short FROM f3_closings WHERE business_date BETWEEN ? AND ?", $m . '-01', f3_month_last($m));
    $s['closings'] = f3_can($u, 'closing.variance') ? array_map('intval', $cl) : ['n' => (int)$cl['n'], 'done' => (int)$cl['done']];
    $per = f3_period($m);
    if ($per && f3_can($u, 'payroll.view')) { $v = f3_period_view($m, $u); $s['payroll'] = ['status' => $v['status'], 'payment_status' => $v['payment_status'], 'totals' => $v['totals'], 'gross' => array_sum(array_map(fn($l) => $l['state'] === 'excluded' ? 0 : $l['gross_earned'], $v['lines'])), 'ot_info' => array_sum(array_column($v['lines'], 'ot_paid_info'))]; }
    if (!f3_can($u, 'payroll.view')) unset($s['staff_recon']);
    return $s;
}
function f3_report_staff_outstanding(array $u): array {
    f3_need($u, 'staff.ledger', 'payroll.view'); $o = []; $t = ['advance' => 0, 'penalty_pending' => 0, 'ot_unpaid' => 0, 'salary_due' => 0];
    foreach (rows("SELECT e.id,e.code,e.name,e.status,d.name dept,g.name desig FROM dd_employees e LEFT JOIN f3_departments d ON d.id=e.f3_dept_id LEFT JOIN f3_designations g ON g.id=e.f3_desig_id ORDER BY d.sort,g.sort,e.name") as $e) {
        $b = f3_staff_balances((int)$e['id']);
        $due = 0; foreach (rows("SELECT l.period,l.net_payable,l.carried_forward FROM f3_pay_lines l JOIN f3_pay_periods p ON p.period=l.period WHERE l.employee_id=? AND l.state='done' AND p.status IN ('approved','locked') ORDER BY l.period DESC LIMIT 1", $e['id']) as $l) $due = (int)$l['net_payable'] + (int)$l['carried_forward'] - f3_paid((int)$e['id'], $l['period']);
        if (!$b['advance_outstanding'] && !$b['penalty_pending_approval'] && !$b['ot_unpaid'] && !$due) continue;
        $o[] = ['id' => (int)$e['id'], 'code' => $e['code'], 'name' => $e['name'], 'dept' => $e['dept'], 'designation' => $e['desig'], 'status' => $e['status'], 'advance' => $b['advance_outstanding'], 'penalty_pending' => $b['penalty_pending_approval'], 'ot_unpaid' => $b['ot_unpaid'], 'salary_due' => $due];
        $t['advance'] += $b['advance_outstanding']; $t['penalty_pending'] += $b['penalty_pending_approval']; $t['ot_unpaid'] += $b['ot_unpaid']; $t['salary_due'] += $due;
    }
    return ['rows' => $o, 'totals' => $t];
}

/* ------------------------------ export writers ------------------------------ */
/** V20: when capture mode is on (Drive backups), exports are returned as bytes instead of being sent to the browser. */
final class F3Captured extends RuntimeException { public function __construct(public string $fname, public string $bytes, public string $mime) { parent::__construct('captured'); } }
function f3_capture_on(): bool { return !empty($GLOBALS['F3_CAPTURE']); }
function f3_csv_out(string $name, array $sheets): never {
    $o = "\xEF\xBB\xBF";
    foreach ($sheets as $title => $rows) { $o .= csv_cell($title) . "\r\n"; foreach ($rows as $r) $o .= implode(',', array_map(fn($c) => csv_cell(is_array($c) ? ($c['v'] ?? '') : $c), $r)) . "\r\n"; $o .= "\r\n"; }
    if (f3_capture_on()) throw new F3Captured($name . '.csv', $o, 'text/csv');
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $name . '.csv"'); echo $o;
    exit;
}
/** Minimal XLSX (Office Open XML) writer. Cells: scalar or ['v'=>..,'b'=>bool bold,'n'=>bool money]. */
function f3_xlsx(array $sheets): string {
    if (!class_exists('ZipArchive')) fail('PHP zip extension is missing on the server; use CSV.', 503);
    $X = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; };
    $tmp = tempnam(sys_get_temp_dir(), 'f3x'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $names = []; $i = 0;
    foreach ($sheets as $title => $rows) {
        $i++; $names[$i] = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', (string)$title), 0, 31) ?: 'Sheet' . $i;
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $r => $cells) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($cells) as $c => $cell) {
                $v = is_array($cell) ? ($cell['v'] ?? '') : $cell; $b = is_array($cell) && !empty($cell['b']); $money = is_array($cell) && !empty($cell['n']);
                if ($v === null || $v === '') continue;
                $ref = $col($c) . ($r + 1); $s = $money ? ($b ? 3 : 2) : ($b ? 1 : 0);
                if (is_int($v) || is_float($v)) $xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . $v . '</v></c>';
                else $xml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . $X($v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $z->addFromString('xl/worksheets/sheet' . $i . '.xml', $xml . '</sheetData><pageSetup orientation="landscape" paperSize="9"/></worksheet>');
    }
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($names as $k => $n) { $ct .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'; $wb .= '<sheet name="' . $X($n) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>'; $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>'; }
    $rels .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $z->addFromString('[Content_Types].xml', $ct . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##,##0.00"/></numFmts><fonts count="2"><font><sz val="10"/><name val="Arial"/></font><font><b/><sz val="10"/><name val="Arial"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="4"><xf/><xf fontId="1" applyFont="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="164" fontId="1" applyNumberFormat="1" applyFont="1"/></cellXfs></styleSheet>');
    $z->close(); $data = (string)file_get_contents($tmp); @unlink($tmp); return $data;
}
function f3_xlsx_out(string $name, array $sheets): never { $d = f3_xlsx($sheets); if (f3_capture_on()) throw new F3Captured($name . '.xlsx', $d, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="' . $name . '.xlsx"'); header('Content-Length: ' . strlen($d)); echo $d; exit; }
function f3_pdf_text(string $s): string {
    $s = strtr($s, ['₹' => 'Rs ', '→' => '->', '·' => '-', '–' => '-', '—' => '-', '•' => '*', '−' => '-', '’' => "'", '“' => '"', '”' => '"']);
    $s = preg_replace('/[\x{0900}-\x{097F}]+/u', '', $s); // Devanagari cannot be drawn with the standard PDF font (see note above)
    $s = (string)iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
}
/** Landscape A4 table PDF. $rows: list of ['cells'=>[..], 'bold'=>bool]. $widths in points (sum ≤ 800). */
function f3_pdf(string $title, string $sub, array $head, array $rows, array $widths): string {
    $W = 842; $H = 595; $m = 22; $pages = []; $y = 0; $cur = '';
    $logo = null; $lp = dirname(__DIR__) . '/assets/brand/logo-mark-96.png';
    $line = function (array $cells, bool $bold, float $size = 7.2) use (&$cur, &$y, $widths, $m) {
        $x = $m; $cur .= 'BT /' . ($bold ? 'F2' : 'F1') . ' ' . $size . ' Tf ';
        foreach ($cells as $i => $c) { $w = $widths[$i] ?? 60; $t = f3_pdf_text((string)$c); $max = max(3, (int)floor($w / ($size * 0.5))); if (strlen($t) > $max) $t = substr($t, 0, $max - 1) . '.';
            $num = preg_match('/^-?(Rs )?[\d,]+(\.\d+)?$/', trim($t)); $tx = $num ? $x + $w - 4 - strlen($t) * $size * 0.5 : $x + 2;
            $cur .= '1 0 0 1 ' . round($tx, 2) . ' ' . round($y, 2) . ' Tm (' . $t . ') Tj '; $x += $w; }
        $cur .= "ET\n"; $y -= $size + 4.2;
    };
    $newPage = function () use (&$cur, &$y, &$pages, $title, $sub, $head, $H, $W, $m, $line) {
        if ($cur !== '') $pages[] = $cur; $cur = ''; $y = $H - $m - 6;
        $cur .= "0.906 0.4 0.102 rg {$m} " . ($H - $m - 30) . ' ' . ($W - 2 * $m) . " 4 re f 0 0 0 rg\n";
        $cur .= 'BT /F2 13 Tf 1 0 0 1 ' . ($m + 2) . ' ' . ($H - $m - 14) . ' Tm (' . f3_pdf_text(setting('restaurant', 'Khadak Singh Da Dhaba') . ' - ' . $title) . ") Tj ET\n";
        $cur .= 'BT /F1 8 Tf 1 0 0 1 ' . ($m + 2) . ' ' . ($H - $m - 25) . ' Tm (' . f3_pdf_text($sub) . ") Tj ET\n";
        $y = $H - $m - 46; $line($head, true, 7.4); $cur .= "0.6 G {$m} " . ($y + 9) . ' m ' . ($W - $m) . ' ' . ($y + 9) . " l S 0 G\n";
    };
    $newPage();
    foreach ($rows as $r) { if ($y < $m + 20) $newPage(); if (!empty($r['bold'])) $cur .= "0.98 0.94 0.88 rg {$m} " . ($y - 2.5) . ' ' . ($W - 2 * $m) . " 11 re f 0 0 0 rg\n"; $line($r['cells'], !empty($r['bold'])); }
    $pages[] = $cur;
    $objs = []; $add = function (string $s) use (&$objs) { $objs[] = $s; return count($objs); };
    $f1 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'); $f2 = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
    $pagesId = count($objs) + 1; $add('PAGES'); $kids = []; $n = count($pages);
    foreach ($pages as $i => $c) { $c .= 'BT /F1 7 Tf 1 0 0 1 ' . ($W - $m - 120) . " 12 Tm (" . f3_pdf_text('Page ' . ($i + 1) . ' of ' . $n . ' - generated ' . f3_now()->format('d M Y H:i')) . ") Tj ET\n";
        $cid = $add("<< /Length " . strlen($c) . " >>\nstream\n" . $c . "\nendstream"); $kids[] = $add("<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 $W $H] /Contents $cid 0 R /Resources << /Font << /F1 $f1 0 R /F2 $f2 0 R >> >> >>"); }
    $objs[$pagesId - 1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($k) => "$k 0 R", $kids)) . '] /Count ' . count($kids) . ' >>';
    $cat = $add("<< /Type /Catalog /Pages $pagesId 0 R >>");
    $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
    foreach ($objs as $i => $o) { $off[] = strlen($out); $out .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n"; }
    $x = strlen($out); $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n"; foreach ($off as $o) $out .= sprintf("%010d 00000 n \n", $o);
    return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root $cat 0 R >>\nstartxref\n$x\n%%EOF";
}
function f3_pdf_out(string $name, string $pdf): never { if (f3_capture_on()) throw new F3Captured($name . '.pdf', $pdf, 'application/pdf'); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . $name . '.pdf"'); header('Content-Length: ' . strlen($pdf)); echo $pdf; exit; }
function f3_r(int $p): string { return number_format($p / 100, 2, '.', ','); }

/** Salary sheets: four department sheets + master, in one workbook / PDF / CSV. */
function f3_export_sheet(string $p, string $fmt, ?int $dept, array $u): never {
    f3_need($u, 'reports.export', 'payroll.approve');
    $head = ['#', 'Emp ID', 'Name', 'Designation', 'Basic', 'Cal. days', 'Present', 'Absent', 'Half', 'Paid leave', 'Paid days', 'Earned', 'Additions', 'Penalty', 'Adv. recovered', 'Other ded.', 'Net salary', 'OT paid (info)', 'B/F', 'Paid', 'Due', 'Entry', 'Approval'];
    $tot = fn(string $label, array $t, string $count) => ['bold' => true, 'cells' => ['', '', $label, $count, f3_r($t['monthly']), '', '', '', '', '', $t['payable_days'], f3_r($t['gross_base']), f3_r($t['additions'] + $t['other_earnings']), f3_r($t['penalties']), f3_r($t['advance_recovery']), f3_r($t['other_deductions']), f3_r($t['net_payable']), f3_r($t['ot_paid_info']), f3_r($t['carried_forward']), f3_r($t['paid']), f3_r($t['balance']), '', '']];
    $toRows = function (array $s) use ($tot) {
        $out = []; $n = 0; $appr = in_array($s['status'], ['approved', 'locked'], true) ? ucfirst(str_replace('_', ' ', (string)($s['payment_status'] ?? 'approved'))) : ['draft' => 'Draft', 'review' => 'Submitted for approval'][$s['status']] ?? $s['status'];
        foreach ($s['groups'] as $g) {
            $out[] = ['bold' => true, 'cells' => [$g['dept'] . ' (' . $g['total']['staff'] . ' staff)']];
            foreach ($g['designations'] as $d) {
                foreach ($d['lines'] as $l) { $n++; $absent = $l['absent'] ?? null; $pl = $l['paid_leave'] ?? null;
                    $out[] = ['cells' => [$n, $l['code'], $l['name'] . ($l['state'] === 'excluded' ? ' (excluded)' : ''), $l['designation'], f3_r($l['monthly']), $s['days'], $l['present'], $absent ?? $l['unpaid_leave'], $l['half_days'], $pl ?? $l['paid_leave_allowed'], $l['payable_days'], f3_r($l['gross_base']), f3_r($l['additions'] + $l['other_earnings']), f3_r($l['penalties']), f3_r($l['advance_recovery']), f3_r($l['other_deductions']), f3_r($l['net_payable']), f3_r($l['ot_paid_info']), f3_r($l['carried_forward']), f3_r($l['paid']), f3_r($l['balance']), ucfirst($l['entry_status'] ?? $l['state']), $appr], 'raw' => $l]; }
                $out[] = $tot('Subtotal ' . $d['designation'], $d['total'], $d['total']['staff'] . ' staff');
            }
            $out[] = $tot('TOTAL ' . strtoupper($g['dept']), $g['total'], $g['total']['staff'] . ' staff');
        }
        $out[] = $tot('RESTAURANT GRAND TOTAL', $s['grand'], $s['grand']['staff'] . ' staff');
        return $out;
    };
    $sheets = [];
    $list = $dept ? [$dept] : [1, 2, 3, 4, 0];
    foreach ($list as $d) { $s = f3_sheet($p, $d ?: null, $u); $sheets[($d ? F3_DEPTS[$d][0] : 'Master') . ' ' . $p] = [$s, $toRows($s)]; }
    $sub = fn($s) => 'Salary ' . (new DateTimeImmutable($p . '-01'))->format('F Y') . ' · ' . $s['days'] . ' days · divisor ' . $s['divisor'] . ' (' . $s['divisor_mode'] . ') · status ' . $s['status'] . ($s['approved_by'] ? ' · approved by ' . $s['approved_by'] : '') . ' · OT paid is information only (not in net)';
    f3_audit($u, 'export.salary', ['period' => $p, 'format' => $fmt, 'dept' => $dept]);
    $name = 'KSDD-salary-' . $p . ($dept ? '-' . strtolower(F3_DEPTS[$dept][0]) : '');
    if ($fmt === 'pdf') {
        [$s, $rows] = $dept ? $sheets[array_key_first($sheets)] : $sheets['Master ' . $p];
        f3_pdf_out($name, f3_pdf(($dept ? F3_DEPTS[$dept][0] . ' salary sheet' : 'Master salary sheet'), $sub($s), $head, $rows, [14, 30, 80, 48, 36, 22, 26, 24, 20, 26, 28, 40, 32, 32, 36, 30, 40, 32, 34, 34, 38, 34, 44]));
    }
    $book = [];
    foreach ($sheets as $t => [$s, $rows]) { $book[$t] = array_merge([[['v' => setting('restaurant', 'Khadak Singh Da Dhaba') . ' — ' . $s['title'], 'b' => true]], [$sub($s)], [], array_map(fn($h) => ['v' => $h, 'b' => true], $head)],
        array_map(fn($r) => array_map(fn($c) => ['v' => is_string($c) && preg_match('/^-?[\d,]+\.\d{2}$/', $c) ? (float)str_replace(',', '', $c) : $c, 'b' => !empty($r['bold']), 'n' => is_string($c) && preg_match('/^-?[\d,]+\.\d{2}$/', $c)], $r['cells']), $rows)); }
    if ($fmt === 'xlsx') f3_xlsx_out($name, $book);
    f3_csv_out($name, array_map(fn($rs) => array_map(fn($r) => array_map(fn($c) => $c['v'] ?? '', $r), $rs), $book));
}
function f3_export(string $kind, array $q, array $u): never {
    $fmt = in_array($q['format'] ?? 'csv', ['csv', 'xlsx', 'pdf'], true) ? $q['format'] : 'csv';
    if ($kind === 'salary') f3_export_sheet(valid_month($q['period'] ?? ''), $fmt, !empty($q['dept']) ? (int)$q['dept'] : null, $u);
    $v17 = ['supplier-statement', 'purchase-register', 'aging', 'stock-valuation', 'stock-moves'];
    if (!in_array($kind, $v17, true)) f3_need($u, 'reports.export');
    $from = valid_date($q['from'] ?? f3_business_date()); $to = valid_date($q['to'] ?? $from);
    switch ($kind) {
        case 'supplier-statement':
            f3_need($u, 'suppliers.view'); require_once __DIR__ . '/purchase.php'; $st = bk_party_statement((int)($q['id'] ?? 0)); $p = $st['party'];
            $head = ['Date', 'Entry', 'Bill / ref', 'Description', 'Debit (bill)', 'Credit (paid / returned)', 'Balance']; $rows = [['', 'Opening balance', '', '', '', '', f3_r((int)$p['opening'])]];
            foreach ($st['txns'] as $t) $rows[] = [$t['date'], ['purchase' => 'Purchase', 'payment_out' => 'Payment', 'payment_in' => 'Refund in', 'purchase_return' => 'Return / credit note', 'expense' => 'Expense'][$t['type']] ?? $t['type'], $t['bill_no'], $t['description'], $t['effect'] > 0 ? f3_r($t['effect']) : '', $t['effect'] < 0 ? f3_r(-$t['effect']) : '', f3_r($t['balance'])];
            $rows[] = ['', 'Closing balance', '', '', '', '', f3_r((int)$p['balance'])]; $title = 'Supplier statement — ' . $p['name']; $w = [56, 90, 70, 250, 80, 90, 80]; $from = $st['txns'][0]['date'] ?? $from; break;
        case 'purchase-register':
            require_once __DIR__ . '/purchase.php'; $r = f3_purchase_register($q, $u); $from = $r['from']; $to = $r['to'];
            $head = ['Date', 'Bill', 'Invoice', 'Supplier', 'GSTIN', 'Items', 'Discount', 'GST', 'Transport', 'Total', 'Paid', 'Outstanding'];
            $rows = array_map(fn($x) => [$x['post_date'], $x['bill_no'], $x['invoice_no'], $x['supplier'], $x['gstin'], f3_r($x['subtotal']), f3_r($x['discount']), f3_r($x['tax']), f3_r($x['transport']), f3_r($x['grand_total']), f3_r($x['paid']), f3_r($x['outstanding'])], $r['rows']);
            $t = $r['totals']; $rows[] = ['TOTAL', '', '', '', '', f3_r($t['subtotal']), f3_r($t['discount']), f3_r($t['tax']), f3_r($t['transport']), f3_r($t['grand_total']), f3_r($t['paid']), f3_r($t['outstanding'])];
            $title = 'Purchase register ' . $from . ' to ' . $to; $w = [52, 56, 56, 110, 70, 56, 50, 46, 50, 60, 56, 60]; break;
        case 'aging':
            require_once __DIR__ . '/purchase.php'; $r = f3_aging($q, $u); $head = array_merge(['Supplier'], array_column($r['buckets'], 1), ['Older / advance', 'Balance']);
            $rows = array_map(fn($x) => array_merge([$x['name']], array_map(fn($b) => f3_r($x[$b[0]]), $r['buckets']), [f3_r($x['other']), f3_r($x['balance'])]), $r['rows']);
            $rows[] = array_merge(['TOTAL'], array_map(fn($b) => f3_r($r['totals'][$b[0]]), $r['buckets']), [f3_r($r['totals']['other']), f3_r($r['totals']['balance'])]); $title = 'Supplier aging as on ' . $r['as_of']; $from = $to = $r['as_of']; $w = [140, 60, 60, 60, 60, 60, 60, 70, 70]; break;
        case 'stock-valuation':
            require_once __DIR__ . '/stock.php'; $r = f3_stock_valuation($to, $q, $u); $head = ['Item', 'Category', 'Qty', 'Unit', 'Avg cost', 'Value', 'Last used', 'Last received'];
            $rows = array_map(fn($x) => [$x['name'], $x['category'], number_format($x['qty'], 3, '.', ''), $x['unit'], $x['qty'] > 0 ? f3_r((int)round($x['value'] / $x['qty'])) : '', f3_r($x['value']), $x['last_out'], $x['last_in']], $r['rows']);
            $rows[] = ['TOTAL', '', '', '', '', f3_r($r['total']), '', '']; $title = 'Stock register / valuation as on ' . $to; $from = $to; $w = [150, 90, 60, 40, 60, 80, 70, 70]; break;
        case 'stock-moves':
            require_once __DIR__ . '/stock.php'; f3_need($u, 'stock.export', 'stock.view'); $r = f3_stock_moves_report(['kind' => $q['move_kind'] ?? ''] + $q, $u); $from = $r['from']; $to = $r['to'];
            $head = ['Date', 'Move', 'Item', 'Type', 'Qty', 'Unit', 'Balance', 'Value', 'Reason', 'Location', 'By'];
            $rows = array_map(fn($m) => [$m['date'], $m['move_no'], $m['item'], $m['kind_label'], number_format($m['qty'], 3, '.', ''), $m['unit'], number_format($m['qty_after'], 3, '.', ''), isset($m['value']) ? f3_r($m['value']) : '', $m['reason'], $m['location'], $m['by_name']], $r['rows']);
            $title = 'Stock movements ' . $from . ' to ' . $to; $w = [52, 56, 110, 70, 46, 34, 50, 56, 140, 60, 60]; break;
        case 'sales':
            $h = f3_sales_history($from, $to, $u); $var = f3_can($u, 'closing.variance');
            $head = array_merge(['Date', 'Cash Sale', 'Card Sale', 'UPI Sale', 'Other', 'Total Sale', 'Expenses', 'Status'], $var ? ['Actual Cash', 'Cash Acess / Short (operational)', 'Accounting variance'] : []);
            $rows = array_map(fn($d) => array_merge([$d['date'], f3_r($d['cash']), f3_r($d['card']), f3_r($d['upi']), f3_r($d['other']), f3_r($d['total']), f3_r($d['expenses']), $d['status']], $var ? [isset($d['actual_cash']) ? f3_r($d['actual_cash']) : '', isset($d['operational_diff']) ? ($d['operational_diff'] > 0 ? 'Cash Acess ' : ($d['operational_diff'] < 0 ? 'Cash Short ' : '')) . f3_r(abs($d['operational_diff'])) : '', isset($d['accounting_variance']) ? f3_r($d['accounting_variance']) : ''] : []), $h['days']);
            $title = 'Daily sales ' . $from . ' to ' . $to; $w = $var ? [60, 60, 60, 60, 50, 66, 60, 60, 64, 120, 80] : [80, 90, 90, 90, 80, 100, 90, 100]; break;
        case 'expenses':
            $e = f3_expense_list(['from' => $from, 'to' => $to] + $q, $u);
            $head = ['Date', 'Category', 'Group', 'Amount', 'Paid from', 'Party', 'Bill no', 'Description', 'Entered by', 'Review'];
            $rows = array_map(fn($r) => [$r['date'], $r['category'], $r['group'], f3_r($r['total']), $r['account'], $r['party'], $r['bill_no'], $r['description'], $r['created_by_name'], $r['review_status']], $e['rows']);
            $rows[] = ['TOTAL', '', '', f3_r($e['total'])]; $title = 'Expenses ' . $from . ' to ' . $to; $w = [52, 90, 80, 56, 60, 90, 50, 200, 70, 50]; break;
        case 'cashbook':
            $acct = txt($q['account'] ?? f3_set('f3_acct_cash'), 40); $b = f3_book($acct, $from, $to, $u);
            $head = ['Date', 'Type', 'Party / category', 'Description', 'In', 'Out', 'Balance']; $rows = [['', 'Opening balance', '', '', '', '', f3_r($b['opening'])]];
            foreach ($b['txns'] as $t) if (!$t['voided']) $rows[] = [$t['date'], $t['label'], $t['party'] ?: $t['category'], $t['description'], $t['cash'] > 0 ? f3_r($t['cash']) : '', $t['cash'] < 0 ? f3_r(-$t['cash']) : '', f3_r($t['running'])];
            $rows[] = ['', 'Closing balance', '', '', '', '', f3_r($b['closing'])]; $title = $acct . ' book ' . $from . ' to ' . $to; $w = [56, 90, 120, 280, 70, 70, 80]; break;
        case 'staff-outstanding':
            $r = f3_report_staff_outstanding($u); $head = ['Code', 'Name', 'Department', 'Designation', 'Advance outstanding', 'Penalty pending approval', 'OT unpaid', 'Salary balance'];
            $rows = array_map(fn($x) => [$x['code'], $x['name'], $x['dept'], $x['designation'], f3_r($x['advance']), f3_r($x['penalty_pending']), f3_r($x['ot_unpaid']), f3_r($x['salary_due'])], $r['rows']);
            $rows[] = ['', 'TOTAL', '', '', f3_r($r['totals']['advance']), f3_r($r['totals']['penalty_pending']), f3_r($r['totals']['ot_unpaid']), f3_r($r['totals']['salary_due'])]; $title = 'Staff outstanding'; $w = [50, 140, 80, 90, 100, 110, 80, 100]; break;
        case 'staff-txns':
            $r = f3_staff_txn_list(['from' => $from, 'to' => $to] + $q, $u); $head = ['No', 'Date', 'Staff', 'Type', 'Amount', 'Mode', 'Status', 'Reason', 'Applied in salary', 'By'];
            $rows = array_map(fn($x) => [$x['txn_no'], $x['date'], $x['emp_name'], $x['kind_label'], f3_r($x['amount']), $x['mode'], $x['status'], $x['reason'], $x['applied_period'], $x['created_by_name']], $r['rows']); $title = 'Staff entries ' . $from . ' to ' . $to; $w = [50, 52, 100, 110, 60, 40, 50, 190, 60, 70]; break;
        default: fail('Unknown export.', 404);
    }
    f3_audit($u, 'export.' . $kind, ['format' => $fmt, 'from' => $from, 'to' => $to]);
    $name = 'KSDD-' . $kind . '-' . $from . '_' . $to;
    if ($fmt === 'pdf') f3_pdf_out($name, f3_pdf($title, 'Generated by Finance 360 · amounts in Rs', $head, array_map(fn($r) => ['cells' => $r, 'bold' => str_contains(strtoupper((string)($r[0] ?? '') . ' ' . (string)($r[1] ?? '')), 'TOTAL') || str_contains((string)($r[1] ?? ''), 'balance')], $rows), $w));
    $sheet = array_merge([[['v' => setting('restaurant', 'Khadak Singh Da Dhaba') . ' — ' . $title, 'b' => true]], [], array_map(fn($h) => ['v' => $h, 'b' => true], $head)], array_map(fn($r) => array_map(fn($c) => is_string($c) && preg_match('/^-?[\d,]+\.\d{2}$/', $c) ? ['v' => (float)str_replace(',', '', $c), 'n' => true] : $c, $r), $rows));
    if ($fmt === 'xlsx') f3_xlsx_out($name, [$title => $sheet]);
    f3_csv_out($name, [$title => array_map(fn($r) => array_map(fn($c) => is_array($c) ? $c['v'] : $c, $r), $sheet)]);
}
