<?php
declare(strict_types=1);
/* =====================================================================================================================
   Finance 360 V31 — ONE database for every restaurant (tenant) and branch.

   HOW ISOLATION WORKS (docs/V34_TENANT_ISOLATION_REPORT.md describes the design and the V34 hardening):
   • Every legacy finance table `dd_x` / `f3_x` is converted ONCE into a base table `mt_dd_x` that carries
     tenant_id + branch_id (NOT NULL), and every unique key / primary key / foreign key is prefixed with them.
   • The old name `dd_x` becomes a VIEW:  SELECT <original columns> FROM mt_dd_x
       WHERE tenant_id = (ctx of THIS connection) AND branch_id = (ctx of THIS connection)  WITH CASCADED CHECK OPTION
     The ~1,600 existing SQL statements keep working unchanged, but every SELECT / UPDATE / DELETE / JOIN / report /
     export can only ever see the current branch's rows. This is enforced by the DATABASE, not by remembering a WHERE.
   • The connection context lives in mt_ctx (one row per MySQL connection id). It is written by the server from a
     VERIFIED membership (platform/tenancy.php) right after connecting. No context → views return ZERO rows (fail closed).
   • INSERT / REPLACE statements are rewritten by MtPDO below to write the base table with the server's tenant/branch.
     An INSERT the rewriter cannot handle is refused (exception) — never written unscoped. An INSERT that bypasses the
     rewriter hits the view and the CHECK OPTION rejects it (error 1369).
   • Runtime CREATE/ALTER/DROP/TRUNCATE of a converted table is refused (schema changes go through tools/v31-convert.php).
   Requirements: only CREATE VIEW + ALTER privileges (no triggers, no stored functions, no SUPER) → works on Hostinger.
   ===================================================================================================================== */

const MT_PREFIX = 'mt_';
const MT_SCHEMA = 1;

/** Is this installation converted to the single-database model? (config.php 'single_db' => true, set after tools/v31-convert.php) */
function mt_enabled(): bool { return !empty(cfg()['single_db']); }

final class MtPDO extends PDO {
    public int $mtTenant = 0; public int $mtBranch = 0;
    /** @var array<string,string>|null converted table => scope */
    public ?array $mtTables = null;
    public bool $mtAllowDdl = false; // only tools/v31-convert.php sets this
    public function mtRegistry(): array {
        if ($this->mtTables !== null) return $this->mtTables;
        try { $st = parent::query('SELECT name, scope FROM ' . MT_PREFIX . 'tables'); $this->mtTables = $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : []; }
        catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) === 1146) { $this->mtTables = []; } else throw $e; }
        return $this->mtTables;
    }
    private function mtSql(string $sql): string {
        if (mt_enabled()) mt_guard($sql); // V34: no app statement may name a base table (mt_*) or the context table, ever
        if (!mt_enabled() && $this->mtBranch === 0) return $sql;
        $reg = $this->mtRegistry(); if (!$reg) return $sql;
        if (!$this->mtAllowDdl && mt_is_ddl($sql)) { mt_ddl($this, $sql); return 'SELECT 1 FROM DUAL WHERE 0'; }
        return mt_rewrite($sql, $reg, $this->mtTenant, $this->mtBranch, $this->mtAllowDdl);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare($this->mtSql($query), $options); }
    public function exec(string $statement): int|false { $q = $this->mtSql($statement); return $q === 'SELECT 1 FROM DUAL WHERE 0' ? 0 : parent::exec($q); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $q = $this->mtSql($query);
        return $fetchMode === null ? parent::query($q) : parent::query($q, $fetchMode, ...$fetchModeArgs);
    }
    /** Raw access for the conversion tool only (no rewriting). */
    public function rawExec(string $sql): int|false { if (isset($GLOBALS['MT_SQL_LOG']) && preg_match('/^\s*(ALTER|CREATE|RENAME|DROP)\b/i', $sql)) $GLOBALS['MT_SQL_LOG'][] = $sql; return parent::exec($sql); }
    public function rawQuery(string $sql): PDOStatement|false { return parent::query($sql); }
    /** Internal prepared statement without rewriting/guard (isolation module and conversion tool only). */
    public function rawPrepare(string $sql): PDOStatement|false { return parent::prepare($sql); }
}

/** Bind THIS connection to one tenant + branch (or to nothing: 0/0 = sees no rows). Called by db()/pdb() only. */
function mt_set_scope(PDO $p, int $tenantId, int $branchId): void {
    if (!$p instanceof MtPDO) fail('Single-database mode needs the scoped connection class.', 503);
    if ($p->inTransaction()) fail('Cannot change restaurant/branch inside an open transaction.', 500);
    if (($tenantId === 0) !== ($branchId === 0) || $tenantId < 0 || $branchId < 0) fail('Invalid restaurant/branch scope.', 503);
    $p->rawExec('REPLACE INTO ' . MT_PREFIX . 'ctx(conn_id,tenant_id,branch_id,set_at) VALUES(CONNECTION_ID(),' . $tenantId . ',' . $branchId . ',UTC_TIMESTAMP())');
    // V34: read back — the database must hold exactly this scope for THIS connection, otherwise refuse (fail closed).
    $chk = $p->rawQuery('SELECT tenant_id,branch_id FROM ' . MT_PREFIX . 'ctx WHERE conn_id=CONNECTION_ID()')->fetch(PDO::FETCH_NUM);
    if (!$chk || (int)$chk[0] !== $tenantId || (int)$chk[1] !== $branchId) { $p->mtTenant = 0; $p->mtBranch = 0; fail('Restaurant/branch scope could not be verified.', 503); }
    $p->mtTenant = $tenantId; $p->mtBranch = $branchId;
    static $registered = [];
    $oid = spl_object_id($p);
    if (!isset($registered[$oid])) { $registered[$oid] = true; register_shutdown_function(function () use ($p) { try { $p->rawExec('DELETE FROM ' . MT_PREFIX . 'ctx WHERE conn_id=CONNECTION_ID()'); } catch (Throwable $e) {} }); }
}
/** Read back what the DATABASE believes the scope is (tests / health). */
function mt_db_scope(PDO $p): array { $r = ($p instanceof MtPDO ? $p->rawQuery('SELECT tenant_id,branch_id FROM ' . MT_PREFIX . 'ctx WHERE conn_id=CONNECTION_ID()') : $p->query('SELECT tenant_id,branch_id FROM ' . MT_PREFIX . 'ctx WHERE conn_id=CONNECTION_ID()'))->fetch(PDO::FETCH_ASSOC); return $r ? ['tenant_id' => (int)$r['tenant_id'], 'branch_id' => (int)$r['branch_id']] : ['tenant_id' => 0, 'branch_id' => 0]; }


/* ------------------------------------------- V34 statement guard -------------------------------------------
   The scoped views are only as strong as the context row behind them, and on shared hosting the runtime database user
   necessarily has rights on the base tables and on mt_ctx. So EVERY statement the application sends through MtPDO is
   checked before it reaches MySQL and refused if it
     • names any base table / internal table (identifier starting with mt_ — e.g. mt_dd_users, mt_ctx, mt_tables), or
     • contains a MySQL executable comment (slash-star-bang or slash-star-M-bang), which MySQL RUNS as SQL although it looks like a comment, or
     • contains more than one statement.
   Only platform/mt.php itself and tools/v31-convert.php use rawQuery()/rawExec() for base-table work. */
final class MtGuardError extends RuntimeException {}
function mt_guard(string $sql): void {
    static $ok = [];
    if (isset($ok[$sql])) return;
    $n = strlen($sql); $i = 0;
    while ($i < $n) {
        $c = $sql[$i];
        if ($c === "'" || $c === '"') { $i = mt_skip_quoted($sql, $i); continue; }
        if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
            $k = $sql[$i + 2] ?? '';
            if ($k === '!' || (($k === 'M' || $k === 'm') && ($sql[$i + 3] ?? '') === '!')) throw new MtGuardError('Refused: executable comments are not allowed.', 500);
            $e = strpos($sql, '*/', $i + 2); $i = $e === false ? $n : $e + 2; continue;
        }
        if ($c === '#' || ($c === '-' && ($sql[$i + 1] ?? '') === '-')) { $e = strpos($sql, "\n", $i); $i = $e === false ? $n : $e + 1; continue; }
        if ($c === ';') { if (trim(substr($sql, $i + 1)) !== '' && !preg_match('/^\s*(--[^\n]*|#[^\n]*|\/\*.*?\*\/|\s)*$/s', substr($sql, $i + 1))) throw new MtGuardError('Refused: one statement at a time.', 500); $i++; continue; }
        if ($c === '`') { $e = mt_skip_quoted($sql, $i); $id = str_replace('``', '`', substr($sql, $i + 1, $e - $i - 2)); if (stripos($id, MT_PREFIX) === 0) throw new MtGuardError('Refused: internal table ' . $id . ' is not accessible.', 500); $i = $e; continue; }
        if (ctype_alpha($c) || $c === '_') {
            $j = $i; while ($j < $n && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$')) $j++;
            if (($i === 0 || !ctype_alnum($sql[$i - 1])) && stripos(substr($sql, $i, $j - $i), MT_PREFIX) === 0) throw new MtGuardError('Refused: internal table ' . substr($sql, $i, $j - $i) . ' is not accessible.', 500);
            $i = $j; continue;
        }
        $i++;
    }
    if (count($ok) > 5000) $ok = [];
    $ok[$sql] = true;
}
/** V34: read-only counters for Super Admin / setup status (internal tables are not reachable through app SQL). */
function mt_admin_stats(PDO $p): array {
    if (!$p instanceof MtPDO) return ['tables' => 0, 'ctx_recent' => 0];
    try { return ['tables' => (int)$p->rawQuery('SELECT COUNT(*) FROM ' . MT_PREFIX . 'tables')->fetchColumn(),
                  'ctx_recent' => (int)$p->rawQuery('SELECT COUNT(*) FROM ' . MT_PREFIX . 'ctx WHERE set_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR')->fetchColumn()]; }
    catch (PDOException $e) { return ['tables' => 0, 'ctx_recent' => 0]; }
}
/** V34: forget context rows of connections that no longer exist (crash / killed process) so a recycled connection id can
 *  never inherit a scope. Called by platform-cron.php; every new connection also resets its own row (db_connect_target). */
function mt_purge_stale_ctx(MtPDO $p): int {
    try { return (int)$p->rawExec('DELETE c FROM ' . MT_PREFIX . 'ctx c LEFT JOIN information_schema.PROCESSLIST pl ON pl.ID=c.conn_id WHERE pl.ID IS NULL'); }
    catch (PDOException $e) { return (int)$p->rawExec('DELETE FROM ' . MT_PREFIX . 'ctx WHERE set_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'); }
}

/* ------------------------------------------- SQL tokenizer helpers ------------------------------------------- */
/** Position after whitespace and comments starting at $i. */
function mt_skip_ws(string $s, int $i): int {
    $n = strlen($s);
    while ($i < $n) {
        $c = $s[$i];
        if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") { $i++; continue; }
        if ($c === '#' || ($c === '-' && substr($s, $i, 3) === '-- ') || ($c === '-' && substr($s, $i, 2) === "--" && ($i + 2 >= $n || ctype_space($s[$i + 2])))) { $e = strpos($s, "\n", $i); $i = $e === false ? $n : $e + 1; continue; }
        if ($c === '/' && ($s[$i + 1] ?? '') === '*') { $e = strpos($s, '*/', $i + 2); $i = $e === false ? $n : $e + 2; continue; }
        break;
    }
    return $i;
}
/** End position (exclusive) of a quoted string / identifier starting at $i. */
function mt_skip_quoted(string $s, int $i): int {
    $q = $s[$i]; $n = strlen($s); $i++;
    while ($i < $n) {
        $c = $s[$i];
        if ($c === '\\' && $q !== '`') { $i += 2; continue; }
        if ($c === $q) { if (($s[$i + 1] ?? '') === $q) { $i += 2; continue; } return $i + 1; }
        $i++;
    }
    throw new RuntimeException('Unterminated quote in SQL.', 500);
}
/** Index of the matching ')' for the '(' at $i. */
function mt_match_paren(string $s, int $i): int {
    $n = strlen($s); $d = 0;
    for (; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === "'" || $c === '"' || $c === '`') { $i = mt_skip_quoted($s, $i) - 1; continue; }
        if ($c === '(') $d++;
        elseif ($c === ')') { $d--; if ($d === 0) return $i; }
    }
    throw new RuntimeException('Unbalanced parentheses in SQL.', 500);
}
/** Find a top-level (depth 0) keyword (word boundary) at or after $i. Returns position or -1. */
function mt_find_top_kw(string $s, int $i, array $kws): int {
    $n = strlen($s); $d = 0;
    for (; $i < $n; $i++) {
        $c = $s[$i];
        if ($c === "'" || $c === '"' || $c === '`') { $i = mt_skip_quoted($s, $i) - 1; continue; }
        if ($c === '(') { $d++; continue; } if ($c === ')') { $d--; continue; }
        if ($d === 0 && ctype_alpha($c) && ($i === 0 || !preg_match('/[A-Za-z0-9_$]/', $s[$i - 1]))) {
            foreach ($kws as $k) { $l = strlen($k); if (strncasecmp(substr($s, $i, $l), $k, $l) === 0 && !preg_match('/[A-Za-z0-9_$]/', $s[$i + $l] ?? ' ')) return $i; }
        }
    }
    return -1;
}

/**
 * Rewrite one SQL statement for single-database tenancy.
 *  - INSERT/REPLACE into a converted table → base table mt_x with tenant_id/branch_id appended (values are server ints).
 *  - CREATE TABLE IF NOT EXISTS on a converted name → harmless no-op SELECT; any other DDL on converted names → refused.
 *  - Everything else is returned unchanged (views enforce the scope).
 */
function mt_rewrite(string $sql, array $reg, int $tenant, int $branch, bool $allowDdl = false): string {
    $i = mt_skip_ws($sql, 0);
    if (!preg_match('/\G(INSERT|REPLACE|CREATE|ALTER|DROP|TRUNCATE|RENAME|LOAD)\b/i', $sql, $m, 0, $i)) return $sql;
    $verb = strtoupper($m[1]);
    if ($verb !== 'INSERT' && $verb !== 'REPLACE') {
        if ($verb === 'LOAD' && !$allowDdl) throw new RuntimeException('LOAD DATA is not allowed in single-database mode.', 500);
        return $sql; // DDL is routed through mt_ddl() before we get here
    }
    // INSERT / REPLACE [LOW_PRIORITY|DELAYED|HIGH_PRIORITY] [IGNORE] [INTO] tbl
    if (!preg_match('/\G(INSERT|REPLACE)((?:\s+(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE))*)(\s+INTO)?\s+(`?)([A-Za-z0-9_]+)\4/i', $sql, $m, PREG_OFFSET_CAPTURE, $i)) return $sql;
    $table = $m[5][0];
    if (!isset($reg[$table])) {
        if (str_starts_with(strtolower($table), MT_PREFIX) && !$allowDdl) throw new RuntimeException('Direct writes to base tables are not allowed.', 500);
        return $sql;
    }
    if ($tenant <= 0 || $branch <= 0) throw new RuntimeException('No restaurant/branch selected for this write (' . $table . ').', 500);
    $tStart = $m[5][1] - ($m[4][0] === '`' ? 1 : 0); $tEnd = $m[5][1] + strlen($table) + ($m[4][0] === '`' ? 1 : 0);
    $head = substr($sql, 0, $tStart) . '`' . MT_PREFIX . $table . '`';
    $p = mt_skip_ws($sql, $tEnd);
    $T = (string)$tenant; $B = (string)$branch;
    if (($sql[$p] ?? '') === '(') {
        $close = mt_match_paren($sql, $p);
        $cols = substr($sql, $p + 1, $close - $p - 1);
        if (preg_match('/(^|[\s,`])(tenant_id|branch_id)([\s,`]|$)/i', $cols)) throw new RuntimeException('Application code may not set tenant_id/branch_id.', 500);
        $out = $head . substr($sql, $tEnd, $p - $tEnd) . '(' . $cols . ',`tenant_id`,`branch_id`)';
        $q = mt_skip_ws($sql, $close + 1);
        $gap = substr($sql, $close + 1, $q - $close - 1);
        if (preg_match('/\GVALUES?\b/i', $sql, $vm, 0, $q)) {
            $out .= $gap . $vm[0]; $r = $q + strlen($vm[0]);
            while (true) {
                $r2 = mt_skip_ws($sql, $r);
                if (($sql[$r2] ?? '') !== '(') throw new RuntimeException('Unsupported INSERT VALUES form for ' . $table . '.', 500);
                $e = mt_match_paren($sql, $r2);
                $out .= substr($sql, $r, $e - $r) . ',' . $T . ',' . $B . ')';
                $r = $e + 1; $r3 = mt_skip_ws($sql, $r);
                if (($sql[$r3] ?? '') === ',') { $out .= substr($sql, $r, $r3 + 1 - $r); $r = $r3 + 1; continue; }
                break;
            }
            return $out . substr($sql, $r);
        }
        // INSERT ... (cols) SELECT ...   (optionally parenthesised / WITH) — append the scope to the select list
        if (preg_match('/\G(\(\s*)?SELECT\b/i', $sql, $sm, 0, $q)) {
            if (!empty($sm[1])) throw new RuntimeException('Parenthesised INSERT ... SELECT is not supported for ' . $table . '.', 500);
            if (mt_find_top_kw($sql, $q, ['UNION', 'INTERSECT', 'EXCEPT']) >= 0) throw new RuntimeException('INSERT ... SELECT with UNION is not supported for ' . $table . '.', 500);
            $f = mt_find_top_kw($sql, $q + 6, ['FROM']);
            if ($f < 0) { $f = mt_find_top_kw($sql, $q + 6, ['ON DUPLICATE', 'WHERE', 'LIMIT']); if ($f < 0) $f = strlen(rtrim($sql, " \t\r\n;")); }
            return $out . $gap . substr($sql, $q, $f - $q) . ',' . $T . ',' . $B . ' ' . substr($sql, $f);
        }
        throw new RuntimeException('Unsupported INSERT form for ' . $table . '.', 500);
    }
    if (preg_match('/\GSET\b/i', $sql, $sm, 0, $p)) {
        $rest = substr($sql, $p + 3);
        if (preg_match('/(^|[\s,`])(tenant_id|branch_id)\s*`?\s*=/i', $rest)) throw new RuntimeException('Application code may not set tenant_id/branch_id.', 500);
        return $head . substr($sql, $tEnd, $p - $tEnd) . 'SET `tenant_id`=' . $T . ',`branch_id`=' . $B . ',' . $rest;
    }
    throw new RuntimeException('INSERT into ' . $table . ' must name its columns (single-database mode).', 500);
}

/* ------------------------------------------- registry / views ------------------------------------------- */
function mt_ctx_ddl(): string {
    return 'CREATE TABLE IF NOT EXISTS ' . MT_PREFIX . 'ctx (conn_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL, branch_id INT UNSIGNED NOT NULL, set_at DATETIME NOT NULL, KEY k_set(set_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
}
function mt_tables_ddl(): string {
    return 'CREATE TABLE IF NOT EXISTS ' . MT_PREFIX . "tables (name VARCHAR(64) NOT NULL PRIMARY KEY, scope ENUM('branch','tenant') NOT NULL DEFAULT 'branch', original_json LONGTEXT NOT NULL, converted_at DATETIME NOT NULL, rows_before BIGINT NOT NULL DEFAULT 0, hash_before CHAR(64) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}
function mt_view_sql(PDO $p, string $name, string $scope = 'branch'): string {
    $cols = $p->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='" . MT_PREFIX . $name . "' AND COLUMN_NAME NOT IN ('tenant_id','branch_id') ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
    if (!$cols) throw new RuntimeException("Base table for $name not found.");
    $ctx = MT_PREFIX . 'ctx';
    $w = "`tenant_id`=(SELECT `tenant_id` FROM `$ctx` WHERE `conn_id`=CONNECTION_ID())";
    if ($scope === 'branch') $w .= " AND `branch_id`=(SELECT `branch_id` FROM `$ctx` WHERE `conn_id`=CONNECTION_ID())";
    return "CREATE OR REPLACE ALGORITHM=MERGE SQL SECURITY INVOKER VIEW `$name` AS SELECT " . implode(',', array_map(fn($c) => "`$c`", $cols)) . ' FROM `' . MT_PREFIX . $name . "` WHERE $w WITH CASCADED CHECK OPTION";
}
/** Recreate every scoped view (after a restore, or after adding a column to a base table). */
function mt_refresh_views(MtPDO $p): int { $n = 0; foreach ($p->rawQuery('SELECT name,scope FROM ' . MT_PREFIX . 'tables')->fetchAll(PDO::FETCH_KEY_PAIR) as $t => $s) { $p->rawExec(mt_view_sql($p, $t, $s)); $n++; } return $n; }

/** CREATE TABLE statement for a scoped table WITHOUT tenant/branch columns — used by per-branch backups so a restaurant's
 *  export restores into an ordinary single-restaurant database and never reveals platform internals or other tenants. */
function mt_portable_create(PDO $p, string $name): string {
    $reg = $p instanceof MtPDO ? $p->mtRegistry() : [];
    if (!isset($reg[$name])) { $r = $p->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_NUM); return (string)$r[1]; }
    $ddl = (string)$p->rawQuery('SHOW CREATE TABLE `' . MT_PREFIX . $name . '`')->fetch(PDO::FETCH_NUM)[1]; // internal (V34 guard)
    $out = [];
    foreach (explode("\n", $ddl) as $line) {
        if (preg_match('/^\s*`(tenant_id|branch_id)`/', $line) || preg_match('/KEY `mt_scope`/', $line)) continue;
        $line = str_replace(['`tenant_id`,`branch_id`,', '`tenant_id`,', '`branch_id`,'], '', $line);
        $line = preg_replace('/REFERENCES `' . MT_PREFIX . '/', 'REFERENCES `', $line);
        $out[] = $line;
    }
    $ddl = implode("\n", $out);
    $ddl = preg_replace('/CREATE TABLE `' . MT_PREFIX . '/', 'CREATE TABLE `', $ddl, 1);
    $ddl = preg_replace('/,(\s*\n\))/', '$1', $ddl);
    return preg_replace('/\s+AUTO_INCREMENT=\d+/', '', $ddl);
}

/* ------------------------------------------- schema changes in single-database mode -------------------------------------------
   The legacy installer and every versioned migration keep running unchanged. DDL that names a scoped table is applied ONCE
   to the shared base table (all restaurants share one schema) and then re-normalised (tenant/branch-prefixed keys,
   composite FKs) and its view is refreshed. Re-running an already-applied step for another branch is a no-op.
   DROP / TRUNCATE / RENAME of a scoped table would destroy every restaurant's data → always refused. */
function mt_is_ddl(string $sql): bool { $i = mt_skip_ws($sql, 0); return (bool)preg_match('/\G(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/i', $sql, $m, 0, $i); }
function mt_name_scoped(string $n): bool { return (bool)preg_match('/^(dd|f3)_[A-Za-z0-9_]+$/', $n); }
function mt_ddl(MtPDO $p, string $sql): void {
    $reg = $p->mtRegistry(); $i = mt_skip_ws($sql, 0);
    // Provisioning a NEW branch replays the installer + migrations only for their seed data: the shared schema is already
    // current, so DDL on scoped tables is skipped (never rebuilds a table that holds other restaurants' rows).
    if (!empty($GLOBALS['MT_SEED_ONLY'])) {
        if (preg_match('/\GCREATE\s+TEMPORARY\b/i', $sql, $m, 0, $i)) { $p->rawExec($sql); return; }
        if (preg_match('/\G(?:CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|ALTER\s+(?:ONLINE\s+|IGNORE\s+)*TABLE\s+|CREATE\s+(?:UNIQUE\s+)?INDEX\s+\S+\s+ON\s+|DROP\s+INDEX\s+\S+\s+ON\s+)`?([A-Za-z0-9_]+)`?/i', $sql, $m, 0, $i)) {
            if (isset($reg[$m[1]])) return;
            if (mt_name_scoped($m[1])) throw new RuntimeException('Database schema is behind the code (' . $m[1] . '). Run tools/platform-migrate-branches.php --apply first.', 503);
        }
    }
    if (preg_match('/\GCREATE\s+TEMPORARY\b/i', $sql, $m, 0, $i)) { $p->rawExec($sql); return; }
    if (preg_match('/\GCREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i', $sql, $m, PREG_OFFSET_CAPTURE, $i)) {
        $name = $m[2][0];
        if (isset($reg[$name])) return;                         // already exists (as a scoped view)
        if (!mt_name_scoped($name)) { $p->rawExec($sql); return; }
        mt_create_scoped($p, $name, $sql, $m[0][1] + strlen($m[0][0])); return;
    }
    if (preg_match('/\GALTER\s+(?:ONLINE\s+|IGNORE\s+)*TABLE\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m, PREG_OFFSET_CAPTURE, $i)) {
        $name = $m[1][0];
        if (!isset($reg[$name])) { if (mt_name_scoped($name) || str_starts_with($name, MT_PREFIX)) throw new RuntimeException("ALTER of $name is refused in single-database mode (base tables change only through tools/v31-convert.php).", 500); $p->rawExec($sql); return; }
        $rest = mt_ddl_refs(substr($sql, $m[0][1] + strlen($m[0][0])), $reg);
        if (preg_match('/\b(DROP\s+(COLUMN\s+)?`?(tenant_id|branch_id)`?|RENAME\s+TO)\b/i', $rest)) throw new RuntimeException('That schema change is not allowed in single-database mode.', 500);
        $rest = preg_replace('/(\b(?:UNIQUE(?:\s+(?:KEY|INDEX))?|PRIMARY\s+KEY)(?:\s+`?[A-Za-z0-9_]+`?)?\s*)\(/i', '$1(`tenant_id`,`branch_id`,', $rest);
        mt_ddl_exec($p, 'ALTER TABLE `' . MT_PREFIX . $name . '`' . $rest);
        mt_normalize($p, MT_PREFIX . $name, true); $p->rawExec(mt_view_sql($p, $name, $reg[$name])); return;
    }
    if (preg_match('/\GCREATE\s+(UNIQUE\s+)?INDEX\s+`?([A-Za-z0-9_]+)`?\s+ON\s+`?([A-Za-z0-9_]+)`?\s*(\(.*)$/is', $sql, $m, 0, $i) && isset($reg[$m[3]])) {
        mt_ddl($p, 'ALTER TABLE `' . $m[3] . '` ADD ' . ($m[1] ? 'UNIQUE ' : '') . 'INDEX `' . $m[2] . '` ' . $m[4]); return;
    }
    if (preg_match('/\GDROP\s+INDEX\s+`?([A-Za-z0-9_]+)`?\s+ON\s+`?([A-Za-z0-9_]+)`?/i', $sql, $m, 0, $i) && isset($reg[$m[2]])) {
        mt_ddl($p, 'ALTER TABLE `' . $m[2] . '` DROP INDEX `' . $m[1] . '`'); return;
    }
    foreach (array_keys($reg) as $t) if (preg_match('/(^|[`\s.,(])' . preg_quote($t, '/') . '([`\s;,()]|$)/', $sql)) throw new RuntimeException('Dropping, truncating or renaming ' . $t . ' would affect every restaurant and is blocked in single-database mode.', 500);
    if (preg_match('/[`\s.,(]' . MT_PREFIX . '/', $sql)) throw new RuntimeException('Schema changes to base tables must use tools/v31-convert.php.', 500);
    $p->rawExec($sql);
}
/** REFERENCES dd_x → REFERENCES mt_dd_x (foreign keys must point at base tables). */
function mt_ddl_refs(string $s, array $reg): string {
    // FOREIGN KEY (a) REFERENCES dd_p (id)  →  FOREIGN KEY (tenant_id,branch_id,a) REFERENCES mt_dd_p (tenant_id,branch_id,id)
    $s = preg_replace_callback('/\bFOREIGN\s+KEY\s*(`?[A-Za-z0-9_]*`?\s*)\(([^)]*)\)\s*REFERENCES\s+`?([A-Za-z0-9_]+)`?\s*\(([^)]*)\)/i', function ($m) use ($reg) {
        if (!(isset($reg[$m[3]]) || mt_name_scoped($m[3]))) return $m[0];
        return 'FOREIGN KEY ' . $m[1] . '(`tenant_id`,`branch_id`,' . $m[2] . ') REFERENCES `' . MT_PREFIX . $m[3] . '` (`tenant_id`,`branch_id`,' . $m[4] . ')';
    }, $s);
    return preg_replace_callback('/\bREFERENCES\s+`?([A-Za-z0-9_]+)`?/i', fn($m) => (isset($reg[$m[1]]) || mt_name_scoped($m[1])) && !str_starts_with($m[1], MT_PREFIX) ? 'REFERENCES `' . MT_PREFIX . $m[1] . '`' : $m[0], $s);
}
/** Run DDL; "already exists" errors mean another branch's migration already changed the shared schema → no-op. */
function mt_ddl_exec(MtPDO $p, string $sql): void {
    try { $p->rawExec($sql); }
    catch (PDOException $e) { if (!in_array((int)($e->errorInfo[1] ?? 0), [1060, 1061, 1826, 1050], true)) throw $e; }
}
function mt_create_scoped(MtPDO $p, string $name, string $sql, int $afterName): void {
    $rest = mt_ddl_refs(substr($sql, $afterName), $p->mtRegistry());
    $o = strpos($rest, '('); if ($o === false) throw new RuntimeException("CREATE TABLE $name without columns.", 500);
    $ddl = 'CREATE TABLE IF NOT EXISTS `' . MT_PREFIX . $name . '` ' . substr($rest, 0, $o) . '(' . substr($rest, $o + 1);
    $close = mt_match_paren($ddl, strpos($ddl, '('));
    $ddl = substr($ddl, 0, $close) . ',`tenant_id` INT UNSIGNED NOT NULL DEFAULT 0,`branch_id` INT UNSIGNED NOT NULL DEFAULT 0' . substr($ddl, $close);
    $p->rawExec($ddl);
    mt_normalize($p, MT_PREFIX . $name, true);
    $p->rawExec(mt_view_sql($p, $name, 'branch'));
    $p->rawExec('INSERT IGNORE INTO ' . MT_PREFIX . "tables(name,scope,original_json,converted_at,rows_before,hash_before) VALUES(" . $p->quote($name) . ",'branch'," . $p->quote(json_encode(['created_scoped' => true, 'sql' => $sql], JSON_UNESCAPED_UNICODE)) . ',UTC_TIMESTAMP(),0,NULL)');
    $p->mtTables = null;
}
/** Make a base table's keys tenant-safe: PK / unique / secondary indexes prefixed with (tenant_id, branch_id); FKs composite. */
function mt_normalize(MtPDO $p, string $base, bool $fks): void {
    $q = fn(string $s) => $p->rawQuery($s)->fetchAll(PDO::FETCH_ASSOC);
    $bq = $p->quote($base);
    $pk = array_column($q("SELECT COLUMN_NAME c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=$bq AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX"), 'c');
    $ai = array_column($q("SELECT COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=$bq AND EXTRA LIKE '%auto_increment%'"), 'c');
    $fkRows = $q("SELECT k.CONSTRAINT_NAME n,k.COLUMN_NAME c,k.REFERENCED_TABLE_NAME rt,k.REFERENCED_COLUMN_NAME rc,r.DELETE_RULE d,r.UPDATE_RULE u FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=$bq AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION");
    $fk = []; foreach ($fkRows as $r) { $fk[$r['n']] ??= ['cols' => [], 'rt' => $r['rt'], 'rc' => [], 'd' => $r['d'], 'u' => $r['u']]; $fk[$r['n']]['cols'][] = $r['c']; $fk[$r['n']]['rc'][] = $r['rc']; }
    $plainFk = array_filter($fk, fn($f) => $f['cols'][0] !== 'tenant_id');
    if ($fks) foreach ($plainFk as $n => $f) $p->rawExec("ALTER TABLE `$base` DROP FOREIGN KEY `$n`");
    $ix = []; foreach ($q("SELECT INDEX_NAME n,NON_UNIQUE nu,INDEX_TYPE t,COLUMN_NAME c,SUB_PART sp FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=$bq AND INDEX_NAME<>'PRIMARY' ORDER BY INDEX_NAME,SEQ_IN_INDEX") as $r) { $ix[$r['n']] ??= ['u' => (int)$r['nu'] === 0, 't' => $r['t'], 'cols' => []]; $ix[$r['n']]['cols'][] = [$r['c'], $r['sp'] !== null ? (int)$r['sp'] : null]; }
    $alter = [];
    $pkIsId = count($pk) === 1 && in_array($pk[0], $ai, true);
    if ($pk && !$pkIsId && $pk[0] !== 'tenant_id') { $alter[] = 'DROP PRIMARY KEY'; $alter[] = 'ADD PRIMARY KEY (`tenant_id`,`branch_id`,' . implode(',', array_map(fn($c) => "`$c`", $pk)) . ')'; }
    foreach ($ix as $n => $x) {
        if (in_array($x['t'], ['FULLTEXT', 'SPATIAL'], true) || $x['cols'][0][0] === 'tenant_id') continue;
        $alter[] = "DROP INDEX `$n`"; $alter[] = 'ADD ' . ($x['u'] ? 'UNIQUE ' : '') . "INDEX `$n` (`tenant_id`,`branch_id`," . implode(',', array_map(fn($c) => '`' . $c[0] . '`' . ($c[1] ? '(' . $c[1] . ')' : ''), $x['cols'])) . ')';
    }
    if ($pkIsId && !isset($ix['mt_scope'])) $alter[] = 'ADD UNIQUE INDEX `mt_scope` (`tenant_id`,`branch_id`,`' . $pk[0] . '`)';
    if (!$pk && !isset($ix['mt_scope'])) $alter[] = 'ADD INDEX `mt_scope` (`tenant_id`,`branch_id`)';
    if ($alter) $p->rawExec("ALTER TABLE `$base` " . implode(', ', $alter));
    if ($fks) foreach ($plainFk as $n => $f) {
        $rt = str_starts_with($f['rt'], MT_PREFIX) ? $f['rt'] : MT_PREFIX . $f['rt'];
        $p->rawExec("ALTER TABLE `$base` ADD CONSTRAINT `$n` FOREIGN KEY (`tenant_id`,`branch_id`," . implode(',', array_map(fn($c) => "`$c`", $f['cols'])) . ") REFERENCES `$rt` (`tenant_id`,`branch_id`," . implode(',', array_map(fn($c) => "`$c`", $f['rc'])) . ") ON DELETE {$f['d']} ON UPDATE {$f['u']}");
    }
}
/** Index check that works for scoped names (information_schema.STATISTICS lists nothing for a view). */
function mt_has_index(string $table, string $idx): bool {
    $p = db(); $t = ($p instanceof MtPDO && isset($p->mtRegistry()[$table])) ? MT_PREFIX . $table : $table;
    return (bool)$p->query('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=' . $p->quote($t) . ' AND INDEX_NAME=' . $p->quote($idx))->fetchColumn();
}
