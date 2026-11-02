<?php
declare(strict_types=1);
/*
 * Finance 360 V30 — convert the EXISTING single-restaurant database to shared multi-restaurant tenancy (CLI only).
 *
 *   php tools/tenancy-migrate.php                                   dry run: shows what would change, writes nothing
 *   php tools/tenancy-migrate.php --apply --i-have-a-backup         apply (KSDD rows become tenant/branch of the adopted primary branch)
 *   php tools/tenancy-migrate.php --rollback=<report.json> --i-have-a-backup
 *                                                                   undo, only while no other restaurant has data
 *
 * For every business table (dd_*, f3_*):
 *   1. ADD tenant_id, branch_id (NOT NULL, filled for every existing row in the same ALTER), KEY (tenant_id, branch_id)
 *   2. natural PRIMARY KEY and every UNIQUE key → (branch_id, …)  [AUTO_INCREMENT id keys are kept]
 *   3. verify: row count and a fingerprint of every ORIGINAL column are identical before/after; all rows tagged
 * The original key definitions are saved in the report so --rollback can restore them exactly.
 * Never run against live production without a verified backup and the owner's written approval.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'lib.php';
$args = getopt('', ['apply', 'i-have-a-backup', 'rollback:', 'tenant:', 'branch:']);
$apply = isset($args['apply']); $backup = isset($args['i-have-a-backup']); $rollback = $args['rollback'] ?? null;
if (($apply || $rollback) && !$backup) { fwrite(STDERR, "Refusing: take a full database + private files backup first, then add --i-have-a-backup.\n"); exit(2); }

$pdo = db_connect_target(main_db_target()); db_session_setup($pdo);
$q = fn(string $sql, ...$a) => (function () use ($pdo, $sql, $a) { $s = $pdo->prepare($sql); $s->execute($a); return $s; })();
$dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
$tables = []; foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r) if (ts_business_name((string)$r[0])) $tables[] = (string)$r[0];
sort($tables);
$cols = fn(string $t) => $q('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', $t)->fetchAll(PDO::FETCH_COLUMN);
/** @return array<string,array{unique:bool,cols:array<int,array{0:string,1:?int}>}> */
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
$fks = function (string $t) use ($q): array { return $q('SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL', $t)->fetchAll(PDO::FETCH_COLUMN); };

/* ------------------------------ rollback ------------------------------ */
if ($rollback) {
    $rep = json_decode((string)file_get_contents($rollback), true); if (!$rep || empty($rep['tables'])) { fwrite(STDERR, "Bad report file.\n"); exit(2); }
    $B = (int)$rep['branch_id'];
    foreach ($rep['tables'] as $t => $info) { if (!in_array('branch_id', $cols($t), true)) continue;
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE branch_id<>$B")->fetchColumn(); if ($n) { fwrite(STDERR, "Refusing rollback: $t has $n row(s) of other branches/restaurants.\n"); exit(3); } }
    foreach ($rep['tables'] as $t => $info) {
        if (!in_array('branch_id', $cols($t), true)) continue;
        $now = $indexes($t); $alter = [];
        foreach ($now as $name => $ix) {
            $perBranchUnique = $ix['unique'] && ($ix['cols'][0][0] ?? '') === 'branch_id';
            if ($perBranchUnique || $name === 'ix_tenant_branch') $alter[] = $name === 'PRIMARY' ? 'DROP PRIMARY KEY' : "DROP INDEX `$name`";
        }
        foreach ($info['indexes'] as $name => $ix) if ($ix['unique'] && in_array($name, $info['changed_keys'], true)) $alter[] = $name === 'PRIMARY' ? 'ADD PRIMARY KEY (' . $colList($ix['cols']) . ')' : "ADD UNIQUE KEY `$name` (" . $colList($ix['cols']) . ')';
        $alter[] = 'DROP COLUMN `branch_id`'; $alter[] = 'DROP COLUMN `tenant_id`';
        foreach ($info['added_fk_keys'] ?? [] as $k) $alter[] = "DROP INDEX `$k`";
        $pdo->exec("ALTER TABLE `$t` " . implode(', ', array_unique($alter)));
        $fp = $fingerprint($t, $info['columns']); if ($fp !== $info['before']) { fwrite(STDERR, "Fingerprint changed on $t after rollback!\n"); exit(4); }
        echo "  rolled back $t\n";
    }
    echo "Rollback complete. Set the config back to single-database mode.\n"; exit(0);
}

/* ------------------------------ plan / apply ------------------------------ */
$T = isset($args['tenant']) ? (int)$args['tenant'] : 0; $B = isset($args['branch']) ? (int)$args['branch'] : 0;
if (!$T || !$B) {
    try { $r = $pdo->query("SELECT b.id,b.tenant_id FROM p_branches b WHERE b.is_primary=1 AND (b.db_name=" . $pdo->quote($dbName) . " OR b.db_name IS NULL) ORDER BY b.id LIMIT 1")->fetch(); if ($r) { $T = (int)$r['tenant_id']; $B = (int)$r['id']; } }
    catch (PDOException $e) {}
}
if (!$T || !$B) { fwrite(STDERR, "Cannot find the adopted primary branch. Run tools/platform-adopt-legacy.php first, or pass --tenant= and --branch=.\n"); exit(2); }
echo ($apply ? 'APPLY' : 'DRY RUN') . " · database $dbName · existing rows become tenant $T / branch $B · " . count($tables) . " business tables\n";
$report = ['at' => gmdate('c'), 'database' => $dbName, 'tenant_id' => $T, 'branch_id' => $B, 'tables' => []]; $done = 0; $skipped = 0;
foreach ($tables as $t) {
    $c = $cols($t);
    if (in_array('tenant_id', $c, true) || in_array('branch_id', $c, true)) { $skipped++; continue; }
    $ix = $indexes($t); $before = $fingerprint($t, $c);
    $changes = [];
    foreach ($ix as $name => $i) { if (!$i['unique']) continue; if ($name === 'PRIMARY' && count($i['cols']) === 1 && $i['cols'][0][0] === 'id') continue; $changes[$name] = $i; }
    // keep an index for every foreign-key column whose only index is about to change
    $addFk = [];
    foreach ($fks($t) as $fc) {
        $ok = false; foreach ($ix as $name => $i) if (!isset($changes[$name]) && $i['cols'][0][0] === $fc) $ok = true;
        if (!$ok) $addFk[] = 'ix_fk_' . substr($fc, 0, 50);
    }
    $report['tables'][$t] = ['columns' => $c, 'before' => $before, 'indexes' => $ix, 'changed_keys' => array_keys($changes), 'added_fk_keys' => $addFk];
    echo sprintf("  %-34s rows %7d · keys to make per-branch: %s\n", $t, $before['rows'], $changes ? implode(', ', array_keys($changes)) : '—');
    if (!$apply) continue;
    $pdo->exec("ALTER TABLE `$t` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT $T FIRST, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT $B AFTER `tenant_id`");
    $alter = ['ALTER COLUMN `tenant_id` DROP DEFAULT', 'ALTER COLUMN `branch_id` DROP DEFAULT', 'ADD KEY `ix_tenant_branch` (`tenant_id`,`branch_id`)'];
    foreach ($addFk as $k) { $alter[] = "ADD KEY `$k` (`" . substr($k, 6) . '`)'; }
    foreach ($changes as $name => $i) {
        $alter[] = $name === 'PRIMARY' ? 'DROP PRIMARY KEY' : "DROP INDEX `$name`";
        $alter[] = $name === 'PRIMARY' ? 'ADD PRIMARY KEY (`branch_id`,' . $colList($i['cols']) . ')' : "ADD UNIQUE KEY `$name` (`branch_id`," . $colList($i['cols']) . ')';
    }
    $pdo->exec("ALTER TABLE `$t` " . implode(', ', $alter));
    $after = $fingerprint($t, $c);
    $bad = (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE tenant_id<>$T OR branch_id<>$B")->fetchColumn();
    if ($after !== $before || $bad) { fwrite(STDERR, "VERIFY FAILED on $t (before " . json_encode($before) . ', after ' . json_encode($after) . ", untagged $bad). Stop and restore the backup.\n"); exit(5); }
    $done++;
}
@mkdir(private_root() . '/logs', 0750, true);
$file = private_root() . '/logs/tenancy-migrate-' . gmdate('Ymd-His') . ($apply ? '' : '-dryrun') . '.json';
file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT));
echo ($apply ? "Done: $done table(s) converted and verified (rows + content fingerprints identical), $skipped already converted.\n" : "Dry run only. Nothing was changed.\n") . "Report: $file\n";
if ($apply) echo "Next: set 'provision' => ['mode' => 'shared'] in ksdd_private/config.php, then run the isolation smoke test (docs/HOSTING_KSDD_IN.md).\n";
