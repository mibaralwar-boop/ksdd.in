<?php
declare(strict_types=1);
/* Finance 360 V31 — one-time conversion of the existing single-restaurant tables into the shared, scoped model.
   Used ONLY by tools/v31-convert.php and the test-suite. Every step is idempotent (safe to re-run after an interruption).
   MySQL/MariaDB DDL auto-commits: there is NO single transaction around this. Safety comes from (1) the mandatory
   backup, (2) per-step idempotency, (3) row-count + content-hash verification of every table, (4) mt_rollback(). */
require_once __DIR__ . '/mt.php';

/** Finance tables that belong to a restaurant branch (everything the legacy app created). */
function mtc_legacy_tables(MtPDO $p): array {
    return $p->rawQuery("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND (TABLE_NAME LIKE 'dd\\_%' OR TABLE_NAME LIKE 'f3\\_%') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
}
function mtc_converted(MtPDO $p): array {
    try { return $p->rawQuery('SELECT name FROM ' . MT_PREFIX . 'tables ORDER BY name')->fetchAll(PDO::FETCH_COLUMN); } catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) === 1146) return []; throw $e; }
}
function mtc_pk(MtPDO $p, string $t): array {
    return $p->rawQuery("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t) . " AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
}
/** Indexes (excluding PRIMARY) as [name => ['unique'=>bool,'type'=>'BTREE','cols'=>[[col,subpart|null],...]]] */
function mtc_indexes(MtPDO $p, string $t): array {
    $o = [];
    foreach ($p->rawQuery("SELECT INDEX_NAME,NON_UNIQUE,INDEX_TYPE,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t) . " AND INDEX_NAME<>'PRIMARY' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o[$r['INDEX_NAME']] ??= ['unique' => (int)$r['NON_UNIQUE'] === 0, 'type' => $r['INDEX_TYPE'], 'cols' => []];
        $o[$r['INDEX_NAME']]['cols'][] = [$r['COLUMN_NAME'], $r['SUB_PART'] !== null ? (int)$r['SUB_PART'] : null];
    }
    return $o;
}
/** Foreign keys whose child table is $t: [name => ['cols'=>[], 'ref_table'=>, 'ref_cols'=>[], 'del'=>, 'upd'=>]] */
function mtc_fks(MtPDO $p, string $t): array {
    $o = [];
    foreach ($p->rawQuery("SELECT k.CONSTRAINT_NAME n,k.COLUMN_NAME c,k.REFERENCED_TABLE_NAME rt,k.REFERENCED_COLUMN_NAME rc,r.DELETE_RULE d,r.UPDATE_RULE u FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=" . $p->quote($t) . " AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o[$r['n']] ??= ['cols' => [], 'ref_table' => $r['rt'], 'ref_cols' => [], 'del' => $r['d'], 'upd' => $r['u']];
        $o[$r['n']]['cols'][] = $r['c']; $o[$r['n']]['ref_cols'][] = $r['rc'];
    }
    return $o;
}
function mtc_col_list(array $cols): string { return implode(',', array_map(fn($c) => '`' . $c[0] . '`' . ($c[1] ? '(' . $c[1] . ')' : ''), $cols)); }
/** Count + SHA-256 over every row in primary-key order (values only, column order fixed). Used before/after and for rollback. */
function mtc_hash(PDO $p, string $t, array $pk): array {
    $order = $pk ? implode(',', array_map(fn($c) => "`$c`", $pk)) : '1';
    $st = ($p instanceof MtPDO ? $p->rawQuery("SELECT * FROM `$t` ORDER BY $order") : $p->query("SELECT * FROM `$t` ORDER BY $order"));
    $h = hash_init('sha256'); $n = 0;
    while ($r = $st->fetch(PDO::FETCH_NUM)) { hash_update($h, json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n"); $n++; }
    return [$n, hash_final($h)];
}

/** Preflight report (no writes). */
function mtc_preflight(MtPDO $p): array {
    $tables = mtc_legacy_tables($p); $issues = [];
    $ver = (string)$p->rawQuery('SELECT VERSION()')->fetchColumn();
    foreach ($tables as $t) {
        $cols = $p->rawQuery("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t))->fetchAll(PDO::FETCH_COLUMN);
        if (array_intersect($cols, ['tenant_id', 'branch_id'])) $issues[] = "$t already has tenant_id/branch_id";
        if (strlen(MT_PREFIX . $t) > 64) $issues[] = "$t name too long";
        $eng = (string)$p->rawQuery("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t))->fetchColumn();
        if ($eng !== 'InnoDB') $issues[] = "$t engine $eng (InnoDB required)";
    }
    // Privileges: try a throw-away view
    try { $p->rawExec('CREATE OR REPLACE VIEW mt_probe_v AS SELECT 1 AS x'); $p->rawExec('DROP VIEW mt_probe_v'); } catch (PDOException $e) { $issues[] = 'CREATE VIEW privilege missing: ' . $e->getMessage(); }
    return ['server' => $ver, 'tables' => $tables, 'converted' => mtc_converted($p), 'issues' => $issues];
}

/**
 * Convert every legacy table to the scoped model, assigning ALL existing rows to ($tenant, $branch).
 * Returns per-table verification [table => [rows_before, rows_after, ok]].
 */
function mtc_convert(MtPDO $p, int $tenant, int $branch, ?callable $log = null): array {
    $log ??= fn(string $m) => null;
    if ($tenant <= 0 || $branch <= 0) throw new RuntimeException('Tenant and branch ids are required.');
    $p->mtAllowDdl = true;
    $p->rawExec(mt_ctx_ddl()); $p->rawExec(mt_tables_ddl());
    $pending = mtc_legacy_tables($p);
    // 1. record originals (create statement, indexes, FKs, row hash) BEFORE anything changes
    foreach ($pending as $t) {
        if ($p->rawQuery('SELECT 1 FROM ' . MT_PREFIX . 'tables WHERE name=' . $p->quote($t))->fetchColumn()) continue;
        $pk = mtc_pk($p, $t);
        [$n, $h] = mtc_hash($p, $t, $pk);
        $create = (string)$p->rawQuery("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $orig = ['create' => $create, 'pk' => $pk, 'indexes' => mtc_indexes($p, $t), 'fks' => mtc_fks($p, $t),
                 'auto_increment' => (bool)$p->rawQuery("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t) . " AND EXTRA LIKE '%auto_increment%'")->fetchColumn()];
        $st = $p->prepare('INSERT INTO ' . MT_PREFIX . "tables(name,scope,original_json,converted_at,rows_before,hash_before) VALUES(?,'branch',?,UTC_TIMESTAMP(),?,?)");
        $st->execute([$t, json_encode($orig, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $n, $h]);
        $log("recorded $t ($n rows)");
    }
    $reg = $p->rawQuery('SELECT name,original_json FROM ' . MT_PREFIX . 'tables ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    $orig = array_map(fn($j) => json_decode($j, true), $reg);
    $parents = [];
    foreach ($orig as $t => $o) foreach ($o['fks'] as $fk) $parents[$fk['ref_table']][implode(',', $fk['ref_cols'])] = $fk['ref_cols'];
    // 2. drop legacy FKs (they are recreated as tenant-composite FKs in step 6)
    foreach ($orig as $t => $o) {
        $base = mtc_exists($p, $t, 'BASE TABLE') ? $t : MT_PREFIX . $t;
        foreach ($o['fks'] as $name => $fk) if (mtc_fk_exists($p, $base, $name) && !mtc_fk_is_composite($p, $base, $name)) { $p->rawExec("ALTER TABLE `$base` DROP FOREIGN KEY `$name`"); $log("dropped FK $t.$name"); }
    }
    // 3. add scope columns + rebuild keys with the tenant/branch prefix
    foreach ($orig as $t => $o) {
        if (!mtc_exists($p, $t, 'BASE TABLE')) continue; // already renamed in an earlier run
        $has = $p->rawQuery("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t) . " AND COLUMN_NAME IN ('tenant_id','branch_id')")->fetchColumn();
        if ((int)$has === 0) {
            $p->rawExec("ALTER TABLE `$t` ADD COLUMN `tenant_id` INT UNSIGNED NOT NULL DEFAULT $tenant, ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT $branch");
            $p->rawExec("ALTER TABLE `$t` ALTER COLUMN `tenant_id` SET DEFAULT 0, ALTER COLUMN `branch_id` SET DEFAULT 0");
        }
        mt_normalize($p, $t, false); $log("keys rebuilt $t");
    }
    // 4. rename all base tables in ONE atomic statement
    $ren = []; foreach ($orig as $t => $o) if (mtc_exists($p, $t, 'BASE TABLE')) $ren[] = "`$t` TO `" . MT_PREFIX . $t . '`';
    if ($ren) { $p->rawExec('RENAME TABLE ' . implode(', ', $ren)); $log('renamed ' . count($ren) . ' tables'); }
    // 5. scoped views with the old names
    foreach ($orig as $t => $o) $p->rawExec(mt_view_sql($p, $t, 'branch'));
    $log('created ' . count($orig) . ' scoped views');
    // 6. tenant-composite foreign keys: a child row can only reference a parent row of the SAME tenant + branch
    foreach ($orig as $t => $o) foreach ($o['fks'] as $name => $fk) {
        if (mtc_fk_exists($p, MT_PREFIX . $t, $name)) continue;
        $p->rawExec('ALTER TABLE `' . MT_PREFIX . $t . "` ADD CONSTRAINT `$name` FOREIGN KEY (`tenant_id`,`branch_id`," . implode(',', array_map(fn($c) => "`$c`", $fk['cols'])) . ') REFERENCES `' . MT_PREFIX . $fk['ref_table'] . '` (`tenant_id`,`branch_id`,' . implode(',', array_map(fn($c) => "`$c`", $fk['ref_cols'])) . ") ON DELETE {$fk['del']} ON UPDATE {$fk['upd']}");
        $log("composite FK $t.$name");
    }
    // 7. verify: every table seen through its view in the KSDD scope = exactly the original rows
    $p->mtAllowDdl = false; $p->mtTables = null;
    mt_set_scope($p, $tenant, $branch);
    return mtc_verify($p);
}
function mtc_verify(MtPDO $p): array {
    $out = [];
    foreach ($p->rawQuery('SELECT name,rows_before,hash_before,original_json FROM ' . MT_PREFIX . 'tables ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o = json_decode($r['original_json'], true);
        [$n, $h] = mtc_hash($p, $r['name'], $o['pk']);
        $out[$r['name']] = ['before' => (int)$r['rows_before'], 'after' => $n, 'ok' => $n === (int)$r['rows_before'] && hash_equals((string)$r['hash_before'], $h)];
    }
    return $out;
}
function mtc_exists(MtPDO $p, string $t, string $type): bool { return (bool)$p->rawQuery("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t) . " AND TABLE_TYPE=" . $p->quote($type))->fetchColumn(); }
function mtc_fk_exists(MtPDO $p, string $t, string $name): bool { return (bool)$p->rawQuery("SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t) . " AND CONSTRAINT_NAME=" . $p->quote($name))->fetchColumn(); }
function mtc_fk_is_composite(MtPDO $p, string $t, string $name): bool { return (int)$p->rawQuery("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t) . " AND CONSTRAINT_NAME=" . $p->quote($name) . " AND COLUMN_NAME IN ('tenant_id','branch_id')")->fetchColumn() > 0; }

/**
 * Undo the conversion (pre-launch rollback). Refuses when any row belongs to another tenant/branch — at that point the
 * only correct rollback is the backup restore described in docs/V31_MIGRATION_ROLLBACK.md.
 */
function mtc_rollback(MtPDO $p, int $tenant, int $branch, ?callable $log = null): array {
    $log ??= fn(string $m) => null;
    $p->mtAllowDdl = true;
    $reg = $p->rawQuery('SELECT name,original_json FROM ' . MT_PREFIX . 'tables ORDER BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
    $orig = array_map(fn($j) => json_decode($j, true), $reg);
    foreach ($orig as $t => $o) {
        $base = mtc_exists($p, MT_PREFIX . $t, 'BASE TABLE') ? MT_PREFIX . $t : $t;
        $other = (int)$p->rawQuery("SELECT COUNT(*) FROM `$base` WHERE NOT (tenant_id=$tenant AND branch_id=$branch)")->fetchColumn();
        if ($other > 0) throw new RuntimeException("Rollback refused: $t holds $other row(s) of other restaurants/branches. Restore the pre-conversion backup instead.");
    }
    foreach ($orig as $t => $o) foreach ($o['fks'] as $name => $fk) { $b = mtc_exists($p, MT_PREFIX . $t, 'BASE TABLE') ? MT_PREFIX . $t : $t; if (mtc_fk_exists($p, $b, $name)) $p->rawExec("ALTER TABLE `$b` DROP FOREIGN KEY `$name`"); }
    foreach ($orig as $t => $o) if (mtc_exists($p, $t, 'VIEW')) $p->rawExec("DROP VIEW `$t`");
    $ren = []; foreach ($orig as $t => $o) if (mtc_exists($p, MT_PREFIX . $t, 'BASE TABLE')) $ren[] = '`' . MT_PREFIX . $t . "` TO `$t`";
    if ($ren) $p->rawExec('RENAME TABLE ' . implode(', ', $ren));
    foreach ($orig as $t => $o) {
        $alter = []; $cur = mtc_indexes($p, $t);
        foreach ($cur as $name => $ix) if (!isset($o['indexes'][$name]) || ($ix['cols'][0][0] ?? '') === 'tenant_id') $alter[] = "DROP INDEX `$name`";
        if ($o['pk'] && mtc_pk($p, $t) !== $o['pk']) { $alter[] = 'DROP PRIMARY KEY'; $alter[] = 'ADD PRIMARY KEY (' . implode(',', array_map(fn($c) => "`$c`", $o['pk'])) . ')'; }
        foreach ($o['indexes'] as $name => $ix) if (!isset($cur[$name]) || ($cur[$name]['cols'][0][0] ?? '') === 'tenant_id') $alter[] = 'ADD ' . ($ix['unique'] ? 'UNIQUE ' : ($ix['type'] === 'FULLTEXT' ? 'FULLTEXT ' : '')) . "INDEX `$name` (" . mtc_col_list($ix['cols']) . ')';
        $has = (int)$p->rawQuery("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $p->quote($t) . " AND COLUMN_NAME IN ('tenant_id','branch_id')")->fetchColumn();
        if ($has) { $alter[] = 'DROP COLUMN `tenant_id`'; $alter[] = 'DROP COLUMN `branch_id`'; }
        if ($alter) $p->rawExec("ALTER TABLE `$t` " . implode(', ', $alter));
        $log("restored $t");
    }
    foreach ($orig as $t => $o) foreach ($o['fks'] as $name => $fk) if (!mtc_fk_exists($p, $t, $name))
        $p->rawExec("ALTER TABLE `$t` ADD CONSTRAINT `$name` FOREIGN KEY (" . implode(',', array_map(fn($c) => "`$c`", $fk['cols'])) . ") REFERENCES `{$fk['ref_table']}` (" . implode(',', array_map(fn($c) => "`$c`", $fk['ref_cols'])) . ") ON DELETE {$fk['del']} ON UPDATE {$fk['upd']}");
    // verify against the hashes recorded before conversion
    $out = [];
    foreach ($p->rawQuery('SELECT name,rows_before,hash_before,original_json FROM ' . MT_PREFIX . 'tables')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        [$n, $h] = mtc_hash($p, $r['name'], json_decode($r['original_json'], true)['pk']);
        $out[$r['name']] = ['before' => (int)$r['rows_before'], 'after' => $n, 'ok' => $n === (int)$r['rows_before'] && hash_equals((string)$r['hash_before'], $h)];
    }
    if (!in_array(false, array_column($out, 'ok'), true)) { $p->rawExec('DROP TABLE ' . MT_PREFIX . 'tables'); $p->rawExec('DROP TABLE IF EXISTS ' . MT_PREFIX . 'ctx'); }
    $p->mtAllowDdl = false;
    return $out;
}
