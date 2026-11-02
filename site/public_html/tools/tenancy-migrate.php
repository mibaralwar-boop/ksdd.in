<?php
declare(strict_types=1);
/*
 * Finance 360 V30 — convert the EXISTING single-restaurant database to shared multi-restaurant tenancy (CLI only).
 *
 *   php tools/tenancy-migrate.php                                   DRY RUN: plan + reconciliation baseline, writes nothing
 *   php tools/tenancy-migrate.php --apply --i-have-a-backup         APPLY (resumable: re-run after any interruption)
 *   php tools/tenancy-migrate.php --rollback=<report.json> --i-have-a-backup
 *                                                                   UNDO exactly (only while no other restaurant has data)
 *
 * Every row of every business table (dd_*, f3_*) in THIS database belongs to Khadak Singh Da Dhaba (the database has only
 * ever held one restaurant), so all existing rows map to the ONE adopted tenant/branch found in p_branches. Nothing is guessed.
 *
 * Phase A  per table (checkpoint written after each):
 *          + tenant_id, branch_id INT NOT NULL (filled for existing rows in the same ALTER, no default afterwards)
 *          + natural / explicit-id PRIMARY KEY and every UNIQUE key → (branch_id, …); AUTO_INCREMENT id keys stay global
 *          + UNIQUE (branch_id, id) as the target of composite foreign keys; KEY (tenant_id, branch_id)
 *          verify: row count + fingerprint of every ORIGINAL column identical, every row tagged
 * Phase B  database-level safeguards:
 *          + every existing foreign key becomes composite: (branch_id, col) → parent (branch_id, id)   — no cross-branch links
 *          + every business table: (tenant_id, branch_id) → p_branches (tenant_id, id)                 — no orphan / mismatched rows
 * Phase C  financial reconciliation: totals of every money / quantity column of the ledger, cash book, GL, payroll,
 *          supplier, purchase and stock tables, before (whole table) vs after (this branch only) — must be identical.
 * Never run against live production without a verified backup and the owner's written approval.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'lib.php';
$args = getopt('', ['apply', 'i-have-a-backup', 'rollback:', 'tenant:', 'branch:']);
$apply = isset($args['apply']); $backup = isset($args['i-have-a-backup']); $rollback = $args['rollback'] ?? null;
if (($apply || $rollback) && !$backup) { fwrite(STDERR, "Refusing: take a full database + private files backup first, then add --i-have-a-backup.\n"); exit(2); }

$pdo = db_connect_target(main_db_target()); db_session_setup($pdo);
$q = function (string $sql, ...$a) use ($pdo): PDOStatement { $s = $pdo->prepare($sql); $s->execute($a); return $s; };
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$tables = []; foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r) if (ts_business_name((string)$r[0])) $tables[] = (string)$r[0];
sort($tables);
$hasPBranches = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='p_branches'")->fetchColumn();
$cols = fn(string $t) => $q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $t)->fetchAll(PDO::FETCH_COLUMN);
$autoInc = fn(string $t) => $q("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND EXTRA LIKE '%auto_increment%'", $t)->fetchAll(PDO::FETCH_COLUMN);
$indexes = function (string $t) use ($q): array { $o = [];
    foreach ($q('SELECT INDEX_NAME,NON_UNIQUE,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', $t)->fetchAll() as $r)
        { $o[$r['INDEX_NAME']]['unique'] = (int)$r['NON_UNIQUE'] === 0; $o[$r['INDEX_NAME']]['cols'][] = [$r['COLUMN_NAME'], $r['SUB_PART'] !== null ? (int)$r['SUB_PART'] : null]; }
    return $o; };
$colList = fn(array $c) => implode(',', array_map(fn($x) => '`' . $x[0] . '`' . ($x[1] ? '(' . $x[1] . ')' : ''), $c));
$fingerprint = function (string $t, array $columns) use ($pdo): array {
    $expr = 'CONCAT_WS(0x1f,' . implode(',', array_map(fn($c) => "IFNULL(`$c`,0x00)", $columns)) . ')';
    $r = $pdo->query("SELECT COUNT(*) n, COALESCE(SUM(CRC32($expr)),0) h, COALESCE(BIT_XOR(CRC32(SHA2($expr,256))),0) x FROM `$t`")->fetch(PDO::FETCH_NUM);
    return ['rows' => (int)$r[0], 'sum' => (string)$r[1], 'xor' => (string)$r[2]];
};
/** All foreign keys between business tables: name, child cols, parent, parent cols, rules. */
$foreignKeys = function () use ($pdo): array { $o = [];
    foreach ($pdo->query("SELECT k.CONSTRAINT_NAME n,k.TABLE_NAME t,k.COLUMN_NAME c,k.REFERENCED_TABLE_NAME rt,k.REFERENCED_COLUMN_NAME rc,r.DELETE_RULE dr,r.UPDATE_RULE ur FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION")->fetchAll() as $r) {
        if (!ts_business_name($r['t'])) continue;
        $o[$r['t'] . '.' . $r['n']] ??= ['name' => $r['n'], 'table' => $r['t'], 'cols' => [], 'ref' => $r['rt'], 'ref_cols' => [], 'del' => $r['dr'], 'upd' => $r['ur']];
        $o[$r['t'] . '.' . $r['n']]['cols'][] = $r['c']; $o[$r['t'] . '.' . $r['n']]['ref_cols'][] = $r['rc']; }
    return $o; };
$bq = fn(array $c) => implode(',', array_map(fn($x) => "`$x`", $c));

/** Financial reconciliation: SUM of every numeric (non-id) column + row count of the money/stock/payroll tables. */
$RECON = ['dd_ledger', 'dd_bk_txns', 'dd_bk_lines', 'dd_gl_journals', 'dd_gl_lines', 'dd_payroll', 'dd_sal_rows', 'dd_sal_entries', 'f3_sal_history', 'f3_pay_lines', 'f3_pay_payments', 'f3_staff_txns',
          'f3_purchase_bills', 'f3_purchase_lines', 'f3_supplier_payments', 'f3_supplier_allocations', 'f3_purchase_returns', 'dd_bills', 'dd_bill_payments', 'f3_stock_moves', 'f3_stock_balances', 'dd_stock_moves',
          'dd_stock_balances', 'dd_inventory', 'f3_closings', 'dd_counter_closes', 'dd_cash_counts', 'dd_handovers', 'f3_handovers', 'dd_employees', 'dd_suppliers', 'dd_bk_parties'];
$reconcile = function (?int $branch) use ($pdo, $q, $RECON): array { $o = [];
    foreach ($RECON as $t) {
        if (!(int)$q('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', $t)->fetchColumn()) continue;
        $num = $q("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND DATA_TYPE IN ('int','bigint','smallint','mediumint','decimal') AND COLUMN_NAME NOT IN ('tenant_id','branch_id') AND COLUMN_NAME<>'id' AND COLUMN_NAME NOT LIKE '%\\_id' AND COLUMN_KEY<>'PRI' ORDER BY ORDINAL_POSITION", $t)->fetchAll(PDO::FETCH_COLUMN);
        $sel = array_merge(['COUNT(*) AS `rows`'], array_map(fn($c) => "COALESCE(SUM(`$c`),0) AS `$c`", $num));
        $o[$t] = array_map('strval', $pdo->query('SELECT ' . implode(',', $sel) . " FROM `$t`" . ($branch !== null ? " WHERE branch_id=$branch" : ''))->fetch(PDO::FETCH_ASSOC));
    }
    return $o; };

@mkdir(private_root() . '/logs', 0750, true);
$ckFile = private_root() . '/logs/tenancy-migrate-checkpoint.json';

/* ------------------------------ rollback ------------------------------ */
if ($rollback) {
    $rep = json_decode((string)file_get_contents($rollback), true); if (!$rep || empty($rep['tables'])) { fwrite(STDERR, "Bad report file.\n"); exit(2); }
    $B = (int)$rep['branch_id'];
    foreach ($rep['tables'] as $t => $info) { if (!in_array('branch_id', $cols($t), true)) continue;
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE branch_id<>$B")->fetchColumn(); if ($n) { fwrite(STDERR, "Refusing rollback: $t has $n row(s) of other branches/restaurants.\n"); exit(3); } }
    // Phase B back: drop the composite / branch foreign keys, then restore the original ones after the columns are gone
    foreach ($foreignKeys() as $fk) if ($fk['ref'] === 'p_branches' || $fk['cols'][0] === 'branch_id') $pdo->exec("ALTER TABLE `{$fk['table']}` DROP FOREIGN KEY `{$fk['name']}`");
    foreach ($rep['tables'] as $t => $info) {
        if (!in_array('branch_id', $cols($t), true)) continue;
        $now = $indexes($t); $alter = [];
        foreach ($now as $name => $ix) {
            $perBranch = ($ix['cols'][0][0] ?? '') === 'branch_id' && ($ix['unique'] || in_array($name, ['ix_fk_branch'], true));
            if ($perBranch || in_array($name, ['ix_tenant_branch', 'uq_branch_row'], true) || str_starts_with($name, 'fkb_')) $alter[] = $name === 'PRIMARY' ? 'DROP PRIMARY KEY' : "DROP INDEX `$name`";
        }
        foreach ($info['indexes'] as $name => $ix) if ($ix['unique'] && in_array($name, $info['changed_keys'], true)) $alter[] = $name === 'PRIMARY' ? 'ADD PRIMARY KEY (' . $colList($ix['cols']) . ')' : "ADD UNIQUE KEY `$name` (" . $colList($ix['cols']) . ')';
        foreach ($now as $name => $ix) if (!$ix['unique'] && !in_array($name, ['ix_tenant_branch'], true) && ($ix['cols'][0][0] ?? '') === 'branch_id' && !isset($info['indexes'][$name])) $alter[] = "DROP INDEX `$name`";
        $alter[] = 'DROP COLUMN `branch_id`'; $alter[] = 'DROP COLUMN `tenant_id`';
        foreach ($info['added_fk_keys'] ?? [] as $k) $alter[] = "DROP INDEX `$k`";
        $pdo->exec("ALTER TABLE `$t` " . implode(', ', array_unique($alter)));
        $fp = $fingerprint($t, $info['columns']); if ($fp !== $info['before']) { fwrite(STDERR, "Fingerprint changed on $t after rollback!\n"); exit(4); }
        echo "  rolled back $t\n";
    }
    foreach ($rep['foreign_keys'] ?? [] as $fk) {
        $exists = (int)$q('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?', $fk['table'], $fk['name'])->fetchColumn();
        if (!$exists) $pdo->exec("ALTER TABLE `{$fk['table']}` ADD CONSTRAINT `{$fk['name']}` FOREIGN KEY ({$bq($fk['cols'])}) REFERENCES `{$fk['ref']}` ({$bq($fk['ref_cols'])}) ON DELETE {$fk['del']} ON UPDATE {$fk['upd']}");
    }
    @unlink($ckFile);
    echo "Rollback complete: columns, keys and original foreign keys restored; every table's content fingerprint matches the pre-migration value.\nSet the config back to single-database mode (remove 'shared_tenant' / platform 'shared').\n"; exit(0);
}

/* ------------------------------ plan / apply ------------------------------ */
$T = isset($args['tenant']) ? (int)$args['tenant'] : 0; $B = isset($args['branch']) ? (int)$args['branch'] : 0;
if ((!$T || !$B) && $hasPBranches) {
    $r = $pdo->query("SELECT b.id,b.tenant_id FROM p_branches b WHERE b.is_primary=1 AND (b.db_name=" . $pdo->quote($dbName) . ") ORDER BY b.id")->fetchAll();
    if (count($r) > 1) { fwrite(STDERR, "Ambiguous: more than one primary branch is mapped to this database. Pass --tenant= and --branch= after checking p_branches.\n"); exit(2); }
    if ($r) { $T = (int)$r[0]['tenant_id']; $B = (int)$r[0]['id']; }
}
if (!$T || !$B) { fwrite(STDERR, "Cannot find the adopted primary branch. Run tools/platform-adopt-legacy.php first, or pass --tenant= and --branch=.\n"); exit(2); }
if ($hasPBranches && !$pdo->query("SELECT 1 FROM p_branches WHERE id=$B AND tenant_id=$T")->fetchColumn()) { fwrite(STDERR, "Branch $B of tenant $T does not exist in p_branches.\n"); exit(2); }

// resume from a checkpoint of an interrupted run on this database
$ck = is_file($ckFile) ? json_decode((string)file_get_contents($ckFile), true) : null;
if ($ck && ($ck['database'] ?? '') === $dbName && (int)$ck['tenant_id'] === $T && (int)$ck['branch_id'] === $B) { $report = $ck; echo "Resuming from checkpoint of {$ck['at']}\n"; }
else $report = ['at' => gmdate('c'), 'database' => $dbName, 'tenant_id' => $T, 'branch_id' => $B, 'tables' => [], 'foreign_keys' => array_values($foreignKeys()), 'reconciliation_before' => $reconcile(null)];
echo ($apply ? 'APPLY' : 'DRY RUN') . " · database $dbName · ALL existing rows → tenant $T / branch $B (single-restaurant database) · " . count($tables) . " business tables\n";
$save = function () use (&$report, $ckFile) { file_put_contents($ckFile, json_encode($report, JSON_PRETTY_PRINT)); };

// ---- Phase A ----
$done = 0; $skipped = 0;
foreach ($tables as $t) {
    $c = $cols($t);
    if (in_array('tenant_id', $c, true) || in_array('branch_id', $c, true)) { $skipped++; continue; }
    $ix = $indexes($t); $ai = $autoInc($t); $before = $fingerprint($t, $c);
    $changes = [];
    foreach ($ix as $name => $i) { if (!$i['unique']) continue; if ($name === 'PRIMARY' && count($i['cols']) === 1 && in_array($i['cols'][0][0], $ai, true)) continue; $changes[$name] = $i; }
    $globalId = isset($ix['PRIMARY']) && !isset($changes['PRIMARY']) && $ix['PRIMARY']['cols'][0][0] === 'id';
    $addFk = [];
    foreach ($report['foreign_keys'] as $fk) if ($fk['table'] === $t) foreach ($fk['cols'] as $fc) {
        $ok = false; foreach ($ix as $name => $i) if (!isset($changes[$name]) && $i['cols'][0][0] === $fc) $ok = true;
        if (!$ok) $addFk[] = 'ix_fk_' . substr($fc, 0, 50);
    }
    $addFk = array_values(array_unique($addFk));
    $report['tables'][$t] = ['columns' => $c, 'before' => $before, 'indexes' => $ix, 'changed_keys' => array_keys($changes), 'added_fk_keys' => $addFk, 'uq_branch_row' => $globalId];
    echo sprintf("  A %-32s rows %7d · per-branch keys: %s\n", $t, $before['rows'], $changes ? implode(', ', array_keys($changes)) : '—');
    if (!$apply) { unset($report['tables'][$t]['done']); continue; }
    $pdo->exec("ALTER TABLE `$t` ADD COLUMN `tenant_id` INT NOT NULL DEFAULT $T FIRST, ADD COLUMN `branch_id` INT NOT NULL DEFAULT $B AFTER `tenant_id`");
    $alter = ['ALTER COLUMN `tenant_id` DROP DEFAULT', 'ALTER COLUMN `branch_id` DROP DEFAULT', 'ADD KEY `ix_tenant_branch` (`tenant_id`,`branch_id`)'];
    foreach ($addFk as $k) $alter[] = "ADD KEY `$k` (`" . substr($k, 6) . '`)';
    foreach ($changes as $name => $i) {
        $alter[] = $name === 'PRIMARY' ? 'DROP PRIMARY KEY' : "DROP INDEX `$name`";
        $alter[] = $name === 'PRIMARY' ? 'ADD PRIMARY KEY (`branch_id`,' . $colList($i['cols']) . ')' : "ADD UNIQUE KEY `$name` (`branch_id`," . $colList($i['cols']) . ')';
    }
    if ($globalId) $alter[] = 'ADD UNIQUE KEY `uq_branch_row` (`branch_id`,`id`)';
    $pdo->exec("ALTER TABLE `$t` " . implode(', ', $alter));
    $after = $fingerprint($t, $c);
    $bad = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE tenant_id<>$T OR branch_id<>$B")->fetchColumn();
    if ($after !== $before || $bad) { $save(); fwrite(STDERR, "VERIFY FAILED on $t (before " . json_encode($before) . ', after ' . json_encode($after) . ", untagged $bad). Stop and restore the backup.\n"); exit(5); }
    $report['tables'][$t]['done'] = gmdate('c'); $save(); $done++;
}

// ---- Phase B ----
$fkDone = 0; $fkbDone = 0;
foreach ($report['foreign_keys'] as $fk) {
    $now = $foreignKeys()[$fk['table'] . '.' . $fk['name']] ?? null;
    echo "  B composite FK {$fk['table']}.{$fk['name']}: (" . implode(',', $fk['cols']) . ") → {$fk['ref']}(" . implode(',', $fk['ref_cols']) . ")  becomes (branch_id, …)\n";
    if (!$apply || ($now && $now['cols'][0] === 'branch_id')) continue;
    $pdo->exec("ALTER TABLE `{$fk['table']}` DROP FOREIGN KEY `{$fk['name']}`");
    $pdo->exec("ALTER TABLE `{$fk['table']}` ADD CONSTRAINT `{$fk['name']}` FOREIGN KEY (`branch_id`,{$bq($fk['cols'])}) REFERENCES `{$fk['ref']}` (`branch_id`,{$bq($fk['ref_cols'])}) ON DELETE {$fk['del']} ON UPDATE {$fk['upd']}");
    $fkDone++;
}
if ($hasPBranches) {
    echo "  B every business table: (tenant_id, branch_id) → p_branches(tenant_id, id)\n";
    if ($apply) foreach ($tables as $t) {
        $name = ts_fkb_name($t);
        if ((int)$q('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?', $t, $name)->fetchColumn()) continue;
        $pdo->exec("ALTER TABLE `$t` ADD CONSTRAINT `$name` FOREIGN KEY (`tenant_id`,`branch_id`) REFERENCES `p_branches` (`tenant_id`,`id`)");
        $fkbDone++;
    }
} else echo "  B (skipped: p_branches not present — run platform setup + adoption first for the branch constraint)\n";
if ($apply) { $report['phase_b'] = ['composite_fks' => $fkDone, 'branch_fks' => $fkbDone, 'at' => gmdate('c')]; $save(); }

// ---- Phase C ----
$recon = []; $mismatch = 0;
if ($apply) {
    $after = $reconcile($B);
    foreach ($report['reconciliation_before'] as $t => $vals) foreach ($vals as $k => $v) { $a = $after[$t][$k] ?? null; $ok = $a === $v; if (!$ok) $mismatch++; $recon[] = [$t, $k, $v, $a, $ok]; }
    $report['reconciliation_after'] = $after; $report['reconciliation_ok'] = $mismatch === 0;
}
$stamp = gmdate('Ymd-His');
$file = private_root() . "/logs/tenancy-migrate-$stamp" . ($apply ? '' : '-dryrun') . '.json';
file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
$md = "# Tenancy migration reconciliation — $dbName — " . gmdate('Y-m-d H:i') . " UTC\n\nTenant $T / branch $B. Phase A: $done table(s) converted, $skipped already converted. Phase B: $fkDone composite foreign key(s), $fkbDone branch constraint(s).\n\n"
    . ($apply ? ($mismatch ? "**RECONCILIATION FAILED: $mismatch value(s) differ. Restore the backup.**\n\n" : "**Reconciliation OK: every total below is identical before (whole table) and after (branch $B only).**\n\n") . "| Table | Column | Before | After | OK |\n|---|---|---:|---:|---|\n"
    . implode("\n", array_map(fn($r) => "| {$r[0]} | {$r[1]} | {$r[2]} | {$r[3]} | " . ($r[4] ? 'yes' : '**NO**') . ' |', $recon)) . "\n" : "Dry run: baseline totals recorded in the JSON report; nothing changed.\n");
file_put_contents(substr($file, 0, -5) . '.md', $md);
if ($apply) @unlink($ckFile);
echo ($apply ? "Done: Phase A $done converted + verified, $skipped already converted · Phase B $fkDone composite FK, $fkbDone branch FK · Phase C reconciliation " . ($mismatch ? "FAILED ($mismatch differences)" : 'OK (' . count($recon) . ' totals identical)') . "\n" : "Dry run only. Nothing was changed.\n") . "Report: $file (+ .md)\n";
if ($apply && $mismatch) exit(6);
