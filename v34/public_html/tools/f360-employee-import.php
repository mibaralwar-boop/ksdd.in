<?php
declare(strict_types=1);
// One-time employee master import — command line (the same code as Settings → Staff import in the browser).
//   php tools/f360-employee-import.php preview  --salary=S.xlsx --csv=E.csv [--bank=B.xlsx] [--report=DIR]
//   php tools/f360-employee-import.php preview  --oneclick=KSDD_OneClick_Import.xlsx [--report=DIR]   (staff list + old months, record only)
//   php tools/f360-employee-import.php decide   --batch=EMP-XXXX --file=decisions.json   (JSON object: {"src:S7":"107", ...})
//   php tools/f360-employee-import.php apply    --batch=EMP-XXXX --confirm=IMPORT [--i-have-a-backup]
//   php tools/f360-employee-import.php verify   --batch=EMP-XXXX [--period=2026-10]
//   php tools/f360-employee-import.php rollback --batch=EMP-XXXX --reason="..."
// Runs as the first active Owner account. Reports mask mobile and bank numbers (last 4 digits only).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'lib.php'; require 'f360/admin.php'; require 'f360/purchase.php'; require 'f360/emp_import.php';
$cmd = $argv[1] ?? ''; $o = []; foreach (array_slice($argv, 2) as $a) if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $a, $m)) $o[$m[1]] = $m[2] ?? true;
$u = row("SELECT * FROM dd_users WHERE role='owner' AND COALESCE(active,1)=1 ORDER BY id LIMIT 1"); if (!$u) exit("No active owner account.\n"); unset($u['password']);
$die = function (string $m) { fwrite(STDERR, "ERROR: $m\n"); exit(1); };
try {
    f3_ready();
    switch ($cmd) {
        case 'preview':
            if (!empty($o['oneclick'])) { if (!is_file($o['oneclick'])) $die('--oneclick=<file> not found'); $files = ['oneclick' => ['name' => basename($o['oneclick']), 'data' => base64_encode((string)file_get_contents($o['oneclick']))]]; }
            else { foreach (['salary', 'csv'] as $k) if (empty($o[$k]) || !is_file($o[$k])) $die("--$k=<file> is required (or --oneclick=<file>)");
            $files = ['salary' => ['name' => basename($o['salary']), 'data' => base64_encode((string)file_get_contents($o['salary']))], 'csv' => ['name' => basename($o['csv']), 'data' => base64_encode((string)file_get_contents($o['csv']))]];
            if (!empty($o['bank'])) $files['bank'] = ['name' => basename($o['bank']), 'data' => base64_encode((string)file_get_contents($o['bank']))]; }
            $r = transaction(fn() => ei_upload($files, $u)); echo "Batch {$r['batch']}" . ($r['existing'] ? " (same files as an earlier upload; status {$r['status']})" : '') . "\n";
            $p = ei_preview($r['batch'], $u); ei_print($p); if (!empty($o['report'])) ei_report($p, $o['report']); break;
        case 'decide':
            $d = json_decode((string)file_get_contents($o['file'] ?? ''), true); if (!is_array($d)) $die('--file must be a JSON object of decisions');
            transaction(fn() => ei_decide(['batch' => $o['batch'] ?? '', 'decisions' => $d], $u)); $p = ei_preview($o['batch'], $u); ei_print($p); if (!empty($o['report'])) ei_report($p, $o['report']); break;
        case 'apply':
            $r = ei_apply(['batch' => $o['batch'] ?? '', 'confirm' => $o['confirm'] ?? '', 'backup_ok' => isset($o['i-have-a-backup'])], $u); echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n"; break;
        case 'verify': echo json_encode(ei_verify_wizard($o['batch'] ?? '', $o['period'] ?? null), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n"; break;
        case 'rollback': echo json_encode(ei_rollback(['batch' => $o['batch'] ?? '', 'reason' => $o['reason'] ?? ''], $u), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n"; break;
        default: $die('command: preview | decide | apply | verify | rollback');
    }
} catch (Throwable $e) { $die($e->getMessage()); }

function ei_print(array $p): void {
    $c = $p['counts']; echo "Create {$c['create']} · Update {$c['update']} · Unchanged {$c['unchanged']} · Inactive {$c['inactive']} · Needs review {$c['review']}" . ($p['ready'] ? " · READY to apply\n" : " · NOT ready\n");
}
function ei_md(string $s): string { return str_replace(['|', "\n"], ['/', ' '], $s); }
function ei_report(array $p, string $dir): void {
    @mkdir($dir, 0750, true); $R = fn($paise) => $paise === null ? '—' : '₹' . number_format($paise / 100);
    $ch = $p['check']; $sec = $ch['sections'];
    $h = "# Employee master import — dry-run report\n\nBatch **{$p['batch']['id']}** · status **{$p['batch']['status']}** · database `{$p['inspect']['db']}` · generated " . gmdate('Y-m-d H:i') . " UTC\n\n";
    $h .= "## Source files (originals kept in private/imports/{$p['batch']['id']}/)\n| Kind | File | Size | SHA-256 |\n|---|---|---|---|\n"; foreach ($p['batch']['files'] as $f) $h .= "| {$f['kind']} | " . ei_md($f['name']) . " | {$f['size']} | `{$f['sha256']}` |\n";
    $h .= "\n## 61 or 62 employees?\n- Headline cell \"Total Employee\": **{$ch['headline_count']}** (salary total ₹" . number_format((int)$ch['headline_salary']) . ")\n- Employee rows actually on the sheet: **{$ch['rows']}** (salary total ₹" . number_format($ch['row_salary']) . ")\n\n| Section | Rows | Sheet's own count | Salary of rows | Sheet's salary total |\n|---|---|---|---|---|\n";
    foreach ($sec as $k => $s) $h .= "| $k | {$s['rows']} | {$s['sheet_count']} | ₹" . number_format($s['salary']) . " | ₹" . number_format((int)$s['sheet_salary']) . " |\n";
    $h .= "\nDuplicate serial numbers: " . ($ch['duplicate_serials'] ? implode('; ', array_map('ei_md', $ch['duplicate_serials'])) : 'none') . "\n\n";
    $h .= "## Read-only schema inspection\n- Schema ready: **" . ($p['inspect']['schema_ok'] ? 'yes' : 'NO — missing ' . implode(', ', $p['inspect']['missing'])) . "** (Finance 360 schema v{$p['inspect']['f3_schema']})\n- Employees in database before import: **{$p['inspect']['employees_total']}**\n- Encryption key for bank details (pii_key): **" . ($p['inspect']['pii_key'] ? 'configured' : 'MISSING — bank details will be skipped') . "**\n- Salary months approved/locked: " . (implode(', ', array_map(fn($x) => $x['period'] . ' ' . $x['status'], $p['inspect']['periods_closed'])) ?: 'none') . "\n- New salary rates take effect from **{$p['rate_from']}**\n\n";
    $c = $p['counts']; $h .= "## Summary\n| Create | Update | Unchanged | Inactive | Needs review |\n|---|---|---|---|---|\n| {$c['create']} | {$c['update']} | {$c['unchanged']} | {$c['inactive']} | {$c['review']} |\n\n";
    $h .= "## Active employee preview (September 2026 sheet, " . count($p['rows']) . " rows)\n| # | Section · S.No | September name | → Department / designation | Salary | CSV match | How matched | Existing staff | Action | Notes |\n|---|---|---|---|---|---|---|---|---|---|\n";
    $i = 0; foreach ($p['rows'] as $r) { $i++; $h .= "| $i | {$r['section']} · {$r['sno']} | " . ei_md($r['sept_name']) . " | {$r['dept']} / " . ei_md($r['desig']) . " | {$R($r['monthly'])} | " . ($r['csv'] ? ei_md($r['csv']['code'] . ' ' . $r['csv']['name']) . ' (' . $r['csv']['mobile'] . ')' : '—') . " | " . ei_md($r['source_tier'] . ': ' . $r['source_reason']) . " | " . ($r['employee_id'] ? '#' . $r['employee_id'] . ' ' . ei_md($r['match_how']) : 'new') . " | **{$r['action']}** | " . ei_md(implode('; ', array_merge(array_map(fn($x) => 'REVIEW: ' . $x['text'], $r['review']), $r['changes'], $r['issues']))) . " |\n"; }
    $h .= "\n## Existing staff not on the September sheet (" . count($p['inactive']) . ")\n| Staff | Code | Department | Joined | Action | Reason |\n|---|---|---|---|---|---|\n"; foreach ($p['inactive'] as $x) $h .= "| #{$x['employee_id']} " . ei_md($x['name']) . " | {$x['code']} | {$x['dept']} | " . ($x['joined_on'] ?? '—') . " | **{$x['action']}** | " . ei_md($x['reason']) . " |\n";
    $h .= "\n## Duplicate and mismatch report\n### Needs your decision\n"; $n = 0; foreach ($p['rows'] as $r) foreach ($r['review'] as $x) { $n++; $h .= "- **" . ei_md($r['sept_name']) . "** ({$r['section']} S.No {$r['sno']}): " . ei_md($x['text']) . ($x['type'] === 'source' && $r['candidates'] ? ' — candidates: ' . ei_md(implode('; ', array_map(fn($c) => 'CSV ' . $c['code'] . ' score ' . $c['score'] . ' (' . $c['why'] . ')', $r['candidates']))) : '') . "\n"; }
    foreach ($p['inactive'] as $x) if ($x['action'] === 'review') { $n++; $h .= "- **" . ei_md($x['name']) . "** (#{$x['employee_id']}): " . ei_md($x['reason']) . "\n"; } if (!$n) $h .= "None.\n";
    $h .= "\n### Data mismatches (information; September sheet wins, app values kept where stated)\n"; foreach ($p['rows'] as $r) foreach ($r['issues'] as $x) $h .= "- " . ei_md($r['sept_name']) . ": " . ei_md($x) . "\n";
    $h .= "\n### CSV people not on the September sheet (" . count($p['csv_only']) . " — not created)\n"; foreach ($p['csv_only'] as $x) $h .= "- CSV {$x['code']} " . ei_md($x['name']) . " · {$x['dept']} · {$x['status']}" . ($x['doj'] ? ' · joined ' . $x['doj'] : '') . "\n";
    $h .= "\n### Bank-letter rows that match nobody in the CSV (" . count($p['bank_only']) . " — ignored)\n"; foreach ($p['bank_only'] as $x) $h .= "- " . ei_md($x['holder']) . " · acct {$x['account']} · mobile {$x['mobile']} · {$x['desig']}\n";
    $h .= "\n### CSV file checks\n" . ($p['csv_issues'] ? implode("\n", array_map(fn($x) => '- ' . ei_md($x), $p['csv_issues'])) : 'No duplicate codes, mobiles or bank accounts.') . "\n";
    if (!empty($p['history'])) { $h .= "\n## Old months — record only (not used for salary, advances, payments or the cash book)\n| Month | Rows | Linked by HR code | Monthly salary | Total salary | Advance | Paid | Rows kept with name only |\n|---|---|---|---|---|---|---|---|\n";
        foreach ($p['history'] as $x) $h .= "| {$x['period']} | {$x['rows']} | {$x['linked']} | {$R($x['monthly'])} | {$R($x['total'])} | {$R($x['advance'])} | {$R($x['paid'])} | " . ei_md(implode(', ', $x['not_linked'])) . " |\n"; }
    if ($p['batch']['before']) { $h .= "\n## Before / after counts\n| Measure | Before | After |\n|---|---|---|\n"; foreach ($p['batch']['after'] as $k => $v) $h .= "| $k | " . ($p['batch']['before'][$k] ?? 0) . " | $v |\n"; }
    if ($p['wizard']) { $w = $p['wizard']; $h .= "\n## Salary Wizard check ({$w['period']})\n- Imported active staff that will appear in the right department: **{$w['ok']}**\n- Problems: " . ($w['problems'] ? implode('; ', array_map('ei_md', $w['problems'])) : 'none') . "\n"; }
    file_put_contents($dir . '/EMPLOYEE_IMPORT_REPORT_' . $p['batch']['status'] . '.md', $h);
    $f = fopen($dir . '/employee_import_preview_' . $p['batch']['status'] . '.csv', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Section', 'S.No', 'September name', 'Department', 'Designation', 'Monthly salary', 'CSV code', 'CSV name', 'CSV mobile (masked)', 'Match tier', 'Match reason', 'Existing staff id', 'How matched', 'Code', 'Joining date', 'Bank (masked)', 'Action', 'Changes', 'Needs decision', 'Notes']);
    foreach ($p['rows'] as $r) fputcsv($f, [$r['section'], $r['sno'], $r['sept_name'], $r['dept'], $r['desig'], $r['monthly'] / 100, $r['csv']['code'] ?? '', $r['csv']['name'] ?? '', $r['csv']['mobile'] ?? '', $r['source_tier'], $r['source_reason'], $r['employee_id'] ?? '', $r['match_how'], $r['code'], $r['joined_on'] ?? '', $r['bank'] ? $r['bank']['bank'] . ' ' . $r['bank']['account'] : '', $r['action'], implode('; ', $r['changes']), implode('; ', array_column($r['review'], 'text')), implode('; ', $r['issues'])]);
    foreach ($p['inactive'] as $x) fputcsv($f, ['(existing)', '', $x['name'], $x['dept'], '', '', '', '', '', '', '', $x['employee_id'], '', $x['code'], $x['joined_on'] ?? '', '', $x['action'], '', $x['action'] === 'review' ? $x['reason'] : '', $x['reason']]);
    fclose($f); echo "Report written to $dir\n";
}
