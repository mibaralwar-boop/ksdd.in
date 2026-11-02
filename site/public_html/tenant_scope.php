<?php
declare(strict_types=1);
/*
 * Finance 360 — shared-database tenant isolation (V30).
 *
 * Every business table (dd_* and f3_*) carries tenant_id + branch_id. This file rewrites EVERY SQL statement sent on
 * the branch connection so that it can only see or change rows of the branch chosen by the server from the signed-in
 * user's verified membership:
 *   SELECT / UPDATE / DELETE  → `alias`.tenant_id=T AND `alias`.branch_id=B for every business table reference
 *                               (in WHERE for inner tables, in ON for LEFT JOINed tables, per sub-query)
 *   INSERT / REPLACE          → tenant_id, branch_id columns + values added (VALUES, SET and INSERT … SELECT forms)
 * It is FAIL-CLOSED: a statement that touches a business table in a form it does not understand (RIGHT/NATURAL JOIN,
 * JOIN … USING, INSERT without column list, CTE, assignments to tenant_id/branch_id, TRUNCATE/DROP of a business table…)
 * is refused instead of being sent unscoped. A final check refuses the statement if any business-table name in table
 * position was not scoped. T and B are integers from the server context — never from the request.
 */

final class TenantScopeError extends RuntimeException {}

/** Business tables are dd_* and f3_* (platform control-plane tables are p_* and are never scoped). */
/** Name of the constraint tying a business table's (tenant_id, branch_id) to a real platform branch. */
function ts_fkb_name(string $table): string { return substr('fkb_' . $table, 0, 64); }
function ts_business_name(string $name): bool { return (bool)preg_match('/^(dd|f3)_[a-z0-9_]+$/i', $name); }

/** @return array<int,array{0:string,1:string}> tokens [type,text]; types: ws, c (comment), s (string), q (backtick), w (word), n (number), p (punct) */
function ts_tokenize(string $sql): array {
    $t = []; $i = 0; $n = strlen($sql);
    while ($i < $n) {
        $c = $sql[$i];
        if (ctype_space($c)) { $j = $i; while ($j < $n && ctype_space($sql[$j])) $j++; $t[] = ['ws', substr($sql, $i, $j - $i)]; $i = $j; continue; }
        if ($c === '-' && substr($sql, $i, 2) === '--' && ($i + 2 >= $n || ctype_space($sql[$i + 2]))) { $j = strpos($sql, "\n", $i); $j = $j === false ? $n : $j; $t[] = ['c', substr($sql, $i, $j - $i)]; $i = $j; continue; }
        if ($c === '#') { $j = strpos($sql, "\n", $i); $j = $j === false ? $n : $j; $t[] = ['c', substr($sql, $i, $j - $i)]; $i = $j; continue; }
        if ($c === '/' && substr($sql, $i, 2) === '/*') { $j = strpos($sql, '*/', $i + 2); if ($j === false) throw new TenantScopeError('Unterminated comment'); $t[] = ['c', substr($sql, $i, $j + 2 - $i)]; $i = $j + 2; continue; }
        if ($c === "'" || $c === '"') {
            $j = $i + 1;
            while (true) {
                if ($j >= $n) throw new TenantScopeError('Unterminated string');
                if ($sql[$j] === '\\') { $j += 2; continue; }
                if ($sql[$j] === $c) { if ($j + 1 < $n && $sql[$j + 1] === $c) { $j += 2; continue; } break; }
                $j++;
            }
            $t[] = ['s', substr($sql, $i, $j + 1 - $i)]; $i = $j + 1; continue;
        }
        if ($c === '`') { $j = $i + 1; while (true) { if ($j >= $n) throw new TenantScopeError('Unterminated identifier'); if ($sql[$j] === '`') { if ($j + 1 < $n && $sql[$j + 1] === '`') { $j += 2; continue; } break; } $j++; } $t[] = ['q', substr($sql, $i, $j + 1 - $i)]; $i = $j + 1; continue; }
        if (ctype_alpha($c) || $c === '_' || $c === '$' || ord($c) >= 128) { $j = $i; while ($j < $n && (ctype_alnum($sql[$j]) || $sql[$j] === '_' || $sql[$j] === '$' || ord($sql[$j]) >= 128)) $j++; $t[] = ['w', substr($sql, $i, $j - $i)]; $i = $j; continue; }
        if (ctype_digit($c)) { $j = $i; while ($j < $n && (ctype_alnum($sql[$j]) || $sql[$j] === '.' || $sql[$j] === '_')) $j++; $t[] = ['n', substr($sql, $i, $j - $i)]; $i = $j; continue; }
        $t[] = ['p', $c]; $i++;
    }
    return $t;
}

final class TenantScoper {
    /** @var array<int,array{0:string,1:string}> */ private array $t;
    /** @var int[] index of significant tokens */ private array $sig = [];
    /** @var array<int,int> matching paren (token index → token index) */ private array $match = [];
    /** @var array<int,int> paren depth of each token */ private array $depth = [];
    /** @var array<int,string> */ private array $pre = [];
    /** @var array<int,string> */ private array $post = [];
    /** @var array<int,bool> business table tokens that were scoped */ private array $handled = [];
    /** @var array<int,bool> */ private array $done = [];
    private string $cond;

    private const CLAUSE_END = ['WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'FOR', 'LOCK', 'WINDOW', 'UNION', 'INTO', 'PROCEDURE'];
    private const NOT_ALIAS = ['ON', 'USING', 'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'NATURAL', 'STRAIGHT_JOIN', 'GROUP', 'ORDER',
        'LIMIT', 'HAVING', 'SET', 'FOR', 'LOCK', 'UNION', 'USE', 'FORCE', 'IGNORE', 'WINDOW', 'PARTITION', 'VALUES', 'VALUE', 'SELECT', 'AS', 'INTO', 'FROM'];

    /** @param array<string,bool> $tables existing business tables (lower-case name => true) */
    public function __construct(string $sql, private int $tenant, private int $branch, private array $tables, private bool $pBranches = false) {
        $this->t = ts_tokenize($sql);
        $stack = []; $d = 0;
        foreach ($this->t as $i => [$ty, $tx]) {
            if ($ty === 'ws' || $ty === 'c') { $this->depth[$i] = $d; continue; }
            $this->sig[] = $i;
            if ($ty === 'p' && $tx === '(') { $this->depth[$i] = $d; $stack[] = $i; $d++; continue; }
            if ($ty === 'p' && $tx === ')') { if (!$stack) throw new TenantScopeError('Unbalanced parenthesis'); $o = array_pop($stack); $d--; $this->match[$o] = $i; $this->match[$i] = $o; $this->depth[$i] = $d; continue; }
            $this->depth[$i] = $d;
        }
        if ($stack) throw new TenantScopeError('Unbalanced parenthesis');
    }

    /** Business table in a TABLE position (FROM/JOIN/INTO/UPDATE…): decided by name, so tables created moments ago are scoped too. */
    private function isTable(string $name): bool { return ts_business_name($name) || isset($this->tables[strtolower($name)]); }
    /** Catch-all check for bare identifiers anywhere: only real tables (columns such as f3_dept_id are not tables). */
    private function isKnownTable(string $name): bool { return isset($this->tables[strtolower($name)]); }
    private function up(int $i): string { return strtoupper($this->t[$i][1]); }
    private function isW(int $i, string ...$w): bool { return isset($this->t[$i]) && $this->t[$i][0] === 'w' && in_array(strtoupper($this->t[$i][1]), $w, true); }
    private function isP(int $i, string $p): bool { return isset($this->t[$i]) && $this->t[$i][0] === 'p' && $this->t[$i][1] === $p; }
    private function ident(int $i): ?string { $ty = $this->t[$i][0] ?? ''; if ($ty === 'w') return $this->t[$i][1]; if ($ty === 'q') return str_replace('``', '`', substr($this->t[$i][1], 1, -1)); return null; }
    /** next significant token index after $i (or null) within [.., $b) */
    private function nx(int $i, int $b): ?int { foreach ($this->sig as $s) if ($s > $i) return $s < $b ? $s : null; return null; }
    private function first(int $a, int $b): ?int { foreach ($this->sig as $s) if ($s >= $a) return $s < $b ? $s : null; return null; }
    /** significant tokens in [a,b) at depth d */
    private function at(int $a, int $b, int $d): array { $o = []; foreach ($this->sig as $s) { if ($s < $a) continue; if ($s >= $b) break; if ($this->depth[$s] === $d) $o[] = $s; } return $o; }
    private function cond(string $alias): string { $q = '`' . str_replace('`', '``', $alias) . '`'; return "$q.tenant_id={$this->tenant} AND $q.branch_id={$this->branch}"; }
    private function fail(string $why): never { throw new TenantScopeError('Tenant scope: ' . $why); }

    public function rewrite(): string {
        $f = $this->first(0, count($this->t));
        if ($f === null) return $this->out();
        $kw = $this->t[$f][0] === 'w' ? $this->up($f) : ($this->isP($f, '(') ? 'SELECT' : '');
        $end = count($this->t);
        // trailing semicolon
        $last = end($this->sig); if ($last !== false && $this->isP($last, ';')) $end = $last;
        switch ($kw) {
            case 'SELECT': $this->selectish($f, $end); break;
            case 'INSERT': case 'REPLACE': $this->insert($f, $end); break;
            case 'UPDATE': $this->update($f, $end); break;
            case 'DELETE': $this->delete($f, $end); break;
            case 'WITH': if ($this->mentionsScoped(0, $end)) $this->fail('common table expressions are not supported'); break;
            case 'TRUNCATE': case 'DROP': case 'RENAME': if ($this->mentionsScoped(0, $end)) $this->fail("$kw on a shared business table is not allowed"); return $this->out();
            case 'CREATE': return $this->ddlCreate($f, $end);
            case 'ALTER': return $this->ddlAlter($f, $end);
            case 'SHOW': case 'DESCRIBE': case 'DESC': case 'EXPLAIN': case 'SET': case 'START': case 'COMMIT': case 'ROLLBACK': case 'SAVEPOINT':
            case 'RELEASE': case 'LOCK': case 'UNLOCK': case 'CHECKSUM': case 'ANALYZE': case 'OPTIMIZE': case 'DO': case 'BEGIN':
                return $this->out(); // schema / session statements are shared by design (no row data)
            default: if ($this->mentionsScoped(0, $end)) $this->fail("unsupported statement $kw");
        }
        $this->verify(0, $end);
        return $this->out();
    }


    /* ---------------- DDL: business tables are created / altered with tenant_id + branch_id and per-branch keys ---------------- */
    private function text(int $a, int $b, array $skip = []): string { $o = ''; for ($i = $a; $i < $b; $i++) if (!isset($skip[$i])) $o .= $this->t[$i][1]; return trim($o); }
    private function colsKey(int $open): string { return strtolower(str_replace(['`', ' '], '', $this->text($open + 1, $this->match[$open]))); }
    private function prefixCols(int $open): void { if (!str_starts_with($this->colsKey($open), 'branch_id')) $this->post[$open] = ($this->post[$open] ?? '') . '`branch_id`,'; }
    private function nextParen(int $a, int $b): ?int { foreach ($this->sig as $s) { if ($s < $a) continue; if ($s >= $b) return null; if ($this->isP($s, '(')) return $s; } return null; }

    private function ddlCreate(int $a, int $b): string {
        $x = $this->nx($a, $b);
        if ($x !== null && $this->isW($x, 'UNIQUE')) { // CREATE UNIQUE INDEX name ON table (cols)
            $lvl = $this->at($a, $b, $this->depth[$a]); $on = null; foreach ($lvl as $s) if ($this->isW($s, 'ON')) { $on = $s; break; }
            $tb = $on !== null ? $this->ident($this->nx($on, $b) ?? -1) : null;
            if ($tb !== null && ts_business_name($tb)) { $p = $this->nextParen($on, $b) ?? $this->fail('bad CREATE INDEX'); $this->prefixCols($p); }
            return $this->out();
        }
        if ($x === null || !$this->isW($x, 'TABLE')) return $this->out();
        $y = $this->nx($x, $b); if ($y !== null && $this->isW($y, 'IF')) { $y = $this->nx($this->nx($this->nx($y, $b) ?? -1, $b) ?? -1, $b); }
        $name = $y !== null ? $this->ident($y) : null;
        if ($name === null || !ts_business_name($name)) return $this->out();
        $open = $this->nx($y, $b);
        if ($open === null || !$this->isP($open, '(')) $this->fail("CREATE TABLE $name must list its columns");
        $close = $this->match[$open]; $d = $this->depth[$open] + 1;
        $parts = []; $st = $open + 1;
        foreach ($this->at($open + 1, $close, $d) as $s) if ($this->isP($s, ',')) { $parts[] = [$st, $s]; $st = $s + 1; }
        $parts[] = [$st, $close];
        // pass 1: which columns are AUTO_INCREMENT (only those keep a global single-column primary key)
        $auto = []; $hasId = false;
        foreach ($parts as [$pa, $pb]) { $f = $this->first($pa, $pb); if ($f === null || $this->t[$f][0] === 'p') continue;
            $id = strtolower((string)$this->ident($f)); if ($id === 'id') $hasId = true;
            foreach ($this->at($pa, $pb, $d) as $s) if ($this->isW($s, 'AUTO_INCREMENT')) $auto[$id] = true; }
        $defs = []; $extra = []; $pkGlobalId = false;
        foreach ($parts as [$pa, $pb]) {
            $f = $this->first($pa, $pb); if ($f === null) continue;
            $w = $this->t[$f][0] === 'w' ? $this->up($f) : '';
            if ($this->ident($f) !== null && in_array(strtolower((string)$this->ident($f)), ['tenant_id', 'branch_id'], true)) return $this->out(); // already tenant-aware
            $k = $f; if ($w === 'CONSTRAINT') { $k = $this->nx($f, $pb); if ($k !== null && !$this->isW($k, 'PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK')) $k = $this->nx($k, $pb); $w = $k !== null && $this->t[$k][0] === 'w' ? $this->up($k) : ''; }
            if ($w === 'PRIMARY') { $p = $this->nextParen($k, $pb) ?? $this->fail('bad PRIMARY KEY'); $ck = $this->colsKey($p);
                if (isset($auto[$ck]) && !str_contains($ck, ',')) $pkGlobalId = $ck === 'id'; else $this->prefixCols($p);
                $defs[] = $this->textWithPost($pa, $pb); continue; }
            if ($w === 'UNIQUE') { $p = $this->nextParen($k, $pb) ?? $this->fail('bad UNIQUE KEY'); $this->prefixCols($p); $defs[] = $this->textWithPost($pa, $pb); continue; }
            if ($w === 'FOREIGN') { $defs[] = $this->compositeFk($k, $pb, $pa); continue; }
            if (in_array($w, ['KEY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'CHECK'], true)) { $defs[] = $this->text($pa, $pb); continue; }
            // column definition: move inline PRIMARY KEY (unless AUTO_INCREMENT) / UNIQUE to per-branch table keys
            $col = (string)$this->ident($f); $skip = [];
            foreach ($this->at($pa, $pb, $d) as $s) {
                if ($this->isW($s, 'PRIMARY')) { $n2 = $this->nx($s, $pb); if ($n2 !== null && $this->isW($n2, 'KEY')) { if (isset($auto[strtolower($col)])) { if (strtolower($col) === 'id') $pkGlobalId = true; continue; } $skip[$s] = true; $skip[$n2] = true; $extra[] = "PRIMARY KEY (`branch_id`,`$col`)"; } }
                if ($this->isW($s, 'UNIQUE')) { $skip[$s] = true; $n2 = $this->nx($s, $pb); if ($n2 !== null && $this->isW($n2, 'KEY')) $skip[$n2] = true; $extra[] = "UNIQUE KEY `uq_{$col}` (`branch_id`,`$col`)"; }
                if ($this->isW($s, 'REFERENCES')) $this->fail('inline REFERENCES on a shared business table (use a table-level FOREIGN KEY)');
            }
            $defs[] = $this->text($pa, $pb, $skip);
        }
        if ($hasId && $pkGlobalId) $extra[] = 'UNIQUE KEY `uq_branch_row` (`branch_id`,`id`)'; // target for composite foreign keys
        $extra[] = 'KEY `ix_tenant_branch` (`tenant_id`,`branch_id`)';
        if ($this->pBranches) $extra[] = 'CONSTRAINT `' . ts_fkb_name($name) . '` FOREIGN KEY (`tenant_id`,`branch_id`) REFERENCES `p_branches` (`tenant_id`,`id`)';
        $all = array_merge(['`tenant_id` INT NOT NULL', '`branch_id` INT NOT NULL'], $defs, $extra);
        return $this->text(0, $open + 1) . implode(', ', $all) . $this->text($close, count($this->t));
    }
    /** FOREIGN KEY (c…) REFERENCES business_parent (r…) → FOREIGN KEY (`branch_id`, c…) REFERENCES parent (`branch_id`, r…): links can never cross branches. */
    private function compositeFk(int $k, int $pb, int $pa): string {
        $p1 = $this->nextParen($k, $pb) ?? $this->fail('bad FOREIGN KEY');
        $ref = null; foreach ($this->sig as $s) if ($s > $this->match[$p1] && $s < $pb && $this->isW($s, 'REFERENCES')) { $ref = $s; break; }
        if ($ref === null) $this->fail('bad FOREIGN KEY');
        $pt = $this->ident($this->nx($ref, $pb) ?? -1);
        if ($pt !== null && ts_business_name($pt)) { $p2 = $this->nextParen($ref, $pb) ?? $this->fail('bad REFERENCES'); $this->prefixCols($p1); $this->prefixCols($p2); }
        return $this->textWithPost($pa, $pb);
    }
    private function textWithPost(int $a, int $b): string { $o = ''; for ($i = $a; $i < $b; $i++) $o .= ($this->pre[$i] ?? '') . $this->t[$i][1] . ($this->post[$i] ?? ''); return trim($o); }

    private function ddlAlter(int $a, int $b): string {
        $x = $this->nx($a, $b); while ($x !== null && $this->isW($x, 'ONLINE', 'IGNORE')) $x = $this->nx($x, $b);
        if ($x === null || !$this->isW($x, 'TABLE')) return $this->out();
        $y = $this->nx($x, $b); $name = $y !== null ? $this->ident($y) : null;
        if ($name === null || !ts_business_name($name)) return $this->out();
        $d = $this->depth[$a]; $parts = []; $st = $y + 1;
        foreach ($this->at($y + 1, $b, $d) as $s) if ($this->isP($s, ',')) { $parts[] = [$st, $s]; $st = $s + 1; }
        $parts[] = [$st, $b];
        foreach ($parts as [$pa, $pb]) {
            $f = $this->first($pa, $pb); if ($f === null || !$this->isW($f, 'ADD', 'MODIFY', 'CHANGE')) continue;
            $k = $this->nx($f, $pb); if ($k !== null && $this->isW($k, 'CONSTRAINT')) { $k = $this->nx($k, $pb); if ($k !== null && !$this->isW($k, 'PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK')) $k = $this->nx($k, $pb); }
            if ($this->isW($f, 'ADD') && $k !== null && $this->isW($k, 'PRIMARY')) { $p = $this->nextParen($k, $pb) ?? $this->fail('bad ADD PRIMARY KEY'); if ($this->colsKey($p) !== 'id') $this->prefixCols($p); continue; }
            if ($this->isW($f, 'ADD') && $k !== null && $this->isW($k, 'UNIQUE')) { $p = $this->nextParen($k, $pb) ?? $this->fail('bad ADD UNIQUE'); $this->prefixCols($p); continue; }
            if ($this->isW($f, 'ADD') && $k !== null && $this->isW($k, 'FOREIGN')) { $this->compositeFk($k, $pb, $pa); continue; }
            foreach ($this->at($pa, $pb, $d) as $s) if ($this->isW($s, 'UNIQUE', 'REFERENCES') || ($this->isW($s, 'PRIMARY') && $this->isW($this->nx($s, $pb) ?? -1, 'KEY'))) $this->fail('inline UNIQUE / PRIMARY KEY / REFERENCES in ALTER TABLE on a shared business table');
        }
        return $this->out();
    }

    private function mentionsScoped(int $a, int $b): bool { foreach ($this->sig as $s) { if ($s < $a) continue; if ($s >= $b) break; $id = $this->ident($s); if ($id !== null && ($this->isKnownTable($id) || (ts_business_name($id) && !preg_match('/_id$/i', $id)))) return true; } return false; }

    /** Every business-table identifier in table position must have been scoped. Qualifiers (x.col) and column names are skipped. */
    private function verify(int $a, int $b): void {
        foreach ($this->sig as $s) {
            if ($s < $a || $s >= $b) continue;
            $id = $this->ident($s);
            if ($id === null || !$this->isKnownTable($id) || isset($this->handled[$s])) continue;
            $n = $this->nx($s, $b); $p = $this->prevSig($s);
            if ($n !== null && $this->isP($n, '.')) continue;          // `dd_x`.col qualifier
            if ($p !== null && $this->isP($p, '.')) continue;          // db.`dd_x` handled at name, or column
            $this->fail('unscoped reference to ' . $id);
        }
    }
    private function prevSig(int $i): ?int { $p = null; foreach ($this->sig as $s) { if ($s >= $i) break; $p = $s; } return $p; }

    private function out(): string { $o = ''; foreach ($this->t as $i => [, $tx]) $o .= ($this->pre[$i] ?? '') . $tx . ($this->post[$i] ?? ''); return $o; }

    /** Process every sub-query "( SELECT … )" inside [a,b) that is not already processed. */
    private function subqueries(int $a, int $b): void {
        foreach ($this->sig as $s) {
            if ($s < $a || $s >= $b || !$this->isP($s, '(') || isset($this->done[$s])) continue;
            $in = $this->first($s + 1, $this->match[$s]);
            if ($in === null) continue;
            if ($this->isW($in, 'SELECT') || ($this->isP($in, '(') && $this->startsSelect($in))) { $this->done[$s] = true; $this->selectish($s + 1, $this->match[$s]); }
            if ($this->isW($in, 'WITH') && $this->mentionsScoped($s, $this->match[$s])) $this->fail('common table expressions are not supported');
        }
    }
    private function startsSelect(int $p): bool { $x = $p; while ($this->isP($x, '(')) { $x = $this->first($x + 1, $this->match[$x]); if ($x === null) return false; } return $this->isW($x, 'SELECT'); }

    /** SELECT … [UNION SELECT …] in [a,b) */
    private function selectish(int $a, int $b): void {
        $f = $this->first($a, $b); if ($f === null) return; $d = $this->depth[$f];
        $parts = []; $start = $a;
        foreach ($this->at($a, $b, $d) as $s) if ($this->isW($s, 'UNION', 'EXCEPT', 'INTERSECT')) { $parts[] = [$start, $s]; $start = $s + 1; }
        $parts[] = [$start, $b];
        foreach ($parts as [$pa, $pb]) {
            $x = $this->first($pa, $pb); if ($x === null) continue;
            if ($this->isW($x, 'ALL', 'DISTINCT')) $x = $this->nx($x, $pb);
            if ($x !== null && $this->isP($x, '(')) { $this->done[$x] = true; $this->selectish($x + 1, $this->match[$x]); $this->subqueries($this->match[$x] + 1, $pb); continue; }
            if ($x !== null && $this->isW($x, 'SELECT')) $this->select($x, $pb);
        }
    }

    /** One SELECT block: [a = SELECT token, b) */
    private function select(int $a, int $b): void {
        $d = $this->depth[$a];
        $this->subqueries($a, $b);
        $lvl = $this->at($a, $b, $d);
        $from = null; foreach ($lvl as $s) if ($this->isW($s, 'FROM')) { $from = $s; break; }
        if ($from === null) return;
        $fromEnd = $b; foreach ($lvl as $s) if ($s > $from && $this->isW($s, ...self::CLAUSE_END)) { $fromEnd = $s; break; }
        $where = $this->tableRefs($from + 1, $fromEnd, $d);
        $this->addWhere($fromEnd, $b, $d, $where, ['GROUP', 'HAVING', 'ORDER', 'LIMIT', 'FOR', 'LOCK', 'WINDOW', 'UNION', 'INTO', 'PROCEDURE']);
    }

    /**
     * Parse a table reference list in [a,b) at depth d. Scopes LEFT JOINed business tables in their ON clause and returns the
     * conditions that belong in WHERE (first table, comma / inner / cross joins).
     * @return string[]
     */
    private function tableRefs(int $a, int $b, int $d): array {
        $toks = $this->at($a, $b, $d); $n = count($toks); $k = 0; $where = []; $join = 'base';
        while ($k < $n) {
            $s = $toks[$k];
            // join keywords
            if ($this->isP($s, ',')) { $join = 'inner'; $k++; continue; }
            if ($this->isW($s, 'NATURAL')) $this->fail('NATURAL JOIN is not supported');
            if ($this->isW($s, 'RIGHT')) { if ($this->mentionsScoped($a, $b)) $this->fail('RIGHT JOIN is not supported'); $join = 'outer'; $k++; continue; }
            if ($this->isW($s, 'LEFT')) { $join = 'outer'; $k++; continue; }
            if ($this->isW($s, 'INNER', 'CROSS')) { $join = 'inner'; $k++; continue; }
            if ($this->isW($s, 'OUTER')) { $k++; continue; }
            if ($this->isW($s, 'JOIN', 'STRAIGHT_JOIN')) { if ($join === 'base') $join = 'inner'; $k++; continue; }
            // table factor
            $alias = null; $isScoped = false; $nameTok = null;
            if ($this->isP($s, '(')) {
                $in = $this->first($s + 1, $this->match[$s]);
                if ($in === null || !$this->startsSelect($s)) { if ($this->mentionsScoped($s, $this->match[$s])) $this->fail('parenthesised joins are not supported'); }
                else if (!isset($this->done[$s])) { $this->done[$s] = true; $this->selectish($s + 1, $this->match[$s]); }
                $k++; while ($k < $n && $toks[$k] < $this->match[$s]) $k++; $k++; // skip to after ')'
            } else {
                $name = $this->ident($s); if ($name === null) $this->fail('unexpected token in FROM: ' . $this->t[$s][1]);
                $nameTok = $s; $k++;
                if ($k < $n && $this->isP($toks[$k], '.')) { $nameTok = $toks[$k + 1] ?? $this->fail('bad table name'); $name = $this->ident($nameTok) ?? $this->fail('bad table name'); $k += 2; }
                $isScoped = $this->isTable($name); $alias = $name;
            }
            if ($k < $n && $this->isW($toks[$k], 'AS')) { $k++; $alias = $this->ident($toks[$k] ?? -1) ?? $this->fail('bad alias'); $k++; }
            elseif ($k < $n && ($this->t[$toks[$k]][0] === 'q' || ($this->t[$toks[$k]][0] === 'w' && !in_array($this->up($toks[$k]), self::NOT_ALIAS, true)))) { $alias = $this->ident($toks[$k]); $k++; }
            // index hints
            while ($k < $n && $this->isW($toks[$k], 'USE', 'FORCE', 'IGNORE') && $this->isW($toks[$k + 1] ?? -1, 'INDEX', 'KEY')) {
                $k += 2; if ($k < $n && $this->isW($toks[$k], 'FOR')) { $k++; while ($k < $n && !$this->isP($toks[$k], '(')) $k++; }
                if ($k < $n && $this->isP($toks[$k], '(')) { $close = $this->match[$toks[$k]]; while ($k < $n && $toks[$k] <= $close) $k++; }
            }
            if ($isScoped && $nameTok !== null) {
                $this->handled[$nameTok] = true;
                $c = $this->cond((string)$alias);
                if ($join === 'outer') {
                    if ($k < $n && $this->isW($toks[$k], 'USING')) $this->fail('LEFT JOIN … USING is not supported');
                    if (!($k < $n && $this->isW($toks[$k], 'ON'))) $this->fail('LEFT JOIN without ON');
                    $on = $toks[$k]; $e = $this->onEnd($toks, $k + 1, $n, $b);
                    $this->post[$on] = ($this->post[$on] ?? '') . " ($c) AND (";
                    $this->closeBefore($e);
                } else $where[] = $c;
            }
            // skip ON / USING clause of this factor
            if ($k < $n && $this->isW($toks[$k], 'ON')) { $e = $this->onEnd($toks, $k + 1, $n, $b); while ($k < $n && $toks[$k] < $e) $k++; }
            elseif ($k < $n && $this->isW($toks[$k], 'USING')) { $k++; if ($k < $n && $this->isP($toks[$k], '(')) { $close = $this->match[$toks[$k]]; while ($k < $n && $toks[$k] <= $close) $k++; } }
            $join = 'next';
        }
        return $where;
    }

    /** token index where an ON expression ends (next join keyword or ',' at the same depth, or b) */
    private function onEnd(array $toks, int $k, int $n, int $b): int {
        for (; $k < $n; $k++) {
            $s = $toks[$k];
            if ($this->isW($s, 'LEFT', 'RIGHT') && $this->isP($toks[$k + 1] ?? -1, '(')) continue; // LEFT()/RIGHT() string functions
            if ($this->isP($s, ',') || $this->isW($s, 'LEFT', 'RIGHT', 'INNER', 'CROSS', 'JOIN', 'STRAIGHT_JOIN', 'NATURAL')) return $s;
        }
        return $b;
    }

    /** Add scope conditions to the WHERE clause of a block (create WHERE when missing). [a,b) is the rest of the block after its table list. */
    private function addWhere(int $a, int $b, int $d, array $conds, array $ends): void {
        if (!$conds) return;
        $c = '(' . implode(' AND ', $conds) . ')';
        $lvl = $this->at($a, $b, $d);
        $w = null; foreach ($lvl as $s) if ($this->isW($s, 'WHERE')) { $w = $s; break; }
        if ($w !== null) {
            $e = $b; foreach ($lvl as $s) if ($s > $w && $this->isW($s, ...$ends)) { $e = $s; break; }
            $this->post[$w] = ($this->post[$w] ?? '') . " $c AND (";
            $this->closeBefore($e);
            return;
        }
        $e = $b; foreach ($lvl as $s) if ($this->isW($s, ...$ends)) { $e = $s; break; }
        $this->insertBefore($e, " WHERE $c ");
    }
    /** Close a parenthesis right after the last significant token before $e (so later inserts at $e come after it). */
    private function closeBefore(int $e): void {
        $p = $this->prevSig(min($e, count($this->t)));
        if ($p === null) $this->fail('cannot close clause');
        $this->post[$p] = ($this->post[$p] ?? '') . ')';
    }
    private function insertBefore(int $i, string $txt): void {
        if ($i >= count($this->t)) { $l = count($this->t) - 1; $this->post[$l] = ($this->post[$l] ?? '') . $txt; return; }
        $this->pre[$i] = $txt . ($this->pre[$i] ?? '');
    }

    private function assignsScopeColumns(int $a, int $b): bool {
        foreach ($this->sig as $s) { if ($s < $a || $s >= $b) continue; $id = $this->ident($s); if ($id !== null && in_array(strtolower($id), ['tenant_id', 'branch_id'], true)) { $n = $this->nx($s, $b); if ($n !== null && $this->isP($n, '=')) return true; } }
        return false;
    }

    private function update(int $a, int $b): void {
        $d = $this->depth[$a]; $lvl = $this->at($a, $b, $d);
        $set = null; foreach ($lvl as $s) if ($this->isW($s, 'SET')) { $set = $s; break; }
        if ($set === null) $this->fail('UPDATE without SET');
        $x = $this->nx($a, $b); while ($x !== null && $this->isW($x, 'LOW_PRIORITY', 'IGNORE')) $x = $this->nx($x, $b);
        $this->subqueries($a, $b);
        $where = $this->tableRefs($x, $set, $d);
        $we = $b; foreach ($lvl as $s) if ($s > $set && $this->isW($s, 'WHERE')) { $we = $s; break; }
        if ($this->assignsScopeColumns($set, $we) && $this->mentionsScoped($a, $set)) $this->fail('tenant_id/branch_id cannot be changed');
        $this->addWhere($set, $b, $d, $where, ['ORDER', 'LIMIT']);
    }

    private function delete(int $a, int $b): void {
        $d = $this->depth[$a]; $lvl = $this->at($a, $b, $d);
        $this->subqueries($a, $b);
        $x = $this->nx($a, $b); while ($x !== null && $this->isW($x, 'LOW_PRIORITY', 'QUICK', 'IGNORE')) $x = $this->nx($x, $b);
        if ($x === null) $this->fail('bad DELETE');
        foreach ($lvl as $s) if ($this->isW($s, 'USING')) $this->fail('DELETE … USING is not supported');
        if ($this->isW($x, 'FROM')) { // single-table (or FROM t1 JOIN …)
            $end = $b; foreach ($lvl as $s) if ($s > $x && $this->isW($s, 'WHERE', 'ORDER', 'LIMIT')) { $end = $s; break; }
            $where = $this->tableRefs($x + 1, $end, $d);
            $this->addWhere($end, $b, $d, $where, ['ORDER', 'LIMIT']);
            return;
        }
        // multi-table: DELETE t1[, t2] FROM refs WHERE …  (targets are aliases of the refs)
        $from = null; foreach ($lvl as $s) if ($s > $x && $this->isW($s, 'FROM')) { $from = $s; break; }
        if ($from === null) $this->fail('bad multi-table DELETE');
        foreach ($this->sig as $s) if ($s >= $x && $s < $from && $this->ident($s) !== null && $this->isTable((string)$this->ident($s))) $this->handled[$s] = true; // target list repeats names scoped in FROM
        $end = $b; foreach ($lvl as $s) if ($s > $from && $this->isW($s, 'WHERE', 'ORDER', 'LIMIT')) { $end = $s; break; }
        $where = $this->tableRefs($from + 1, $end, $d);
        $this->addWhere($end, $b, $d, $where, ['ORDER', 'LIMIT']);
    }

    private function insert(int $a, int $b): void {
        $d = $this->depth[$a]; $lvl = $this->at($a, $b, $d);
        $x = $this->nx($a, $b); while ($x !== null && $this->isW($x, 'LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE', 'INTO')) $x = $this->nx($x, $b);
        if ($x === null) $this->fail('bad INSERT');
        $nameTok = $x; $name = $this->ident($x) ?? $this->fail('bad INSERT table');
        $y = $this->nx($x, $b);
        if ($y !== null && $this->isP($y, '.')) { $nameTok = $this->nx($y, $b) ?? $this->fail('bad INSERT table'); $name = $this->ident($nameTok) ?? $this->fail('bad INSERT table'); $y = $this->nx($nameTok, $b); }
        $scoped = $this->isTable($name); if ($scoped) $this->handled[$nameTok] = true;
        $cols = null;
        if ($y !== null && $this->isP($y, '(') && !$this->startsSelect($y)) { $cols = $y; $y = $this->nx($this->match[$y], $b); }
        $dup = null; foreach ($lvl as $s) if ($this->isW($s, 'ON') && $this->isW($this->nx($s, $b) ?? -1, 'DUPLICATE')) { $dup = $s; break; }
        $bodyEnd = $dup ?? $b;
        if ($y === null) $this->fail('bad INSERT body');
        if ($this->isW($y, 'VALUES', 'VALUE')) {
            $this->subqueries($y, $b);
            if ($scoped) {
                if ($cols === null) $this->fail("INSERT INTO $name needs a column list");
                $this->addCols($cols);
                foreach ($this->at($y, $bodyEnd, $d) as $s) if ($this->isP($s, '(')) $this->addVals($s);
            }
        } elseif ($this->isW($y, 'SET')) {
            $this->subqueries($y, $b);
            if ($scoped) {
                if ($this->assignsScopeColumns($y, $bodyEnd)) $this->fail('tenant_id/branch_id are set by the server');
                $this->post[$y] = ($this->post[$y] ?? '') . " tenant_id={$this->tenant},branch_id={$this->branch},";
            }
        } elseif ($this->isW($y, 'SELECT') || $this->isP($y, '(')) {
            if ($dup !== null) $this->subqueries($dup, $b);
            if ($scoped) {
                if ($cols === null) $this->fail("INSERT INTO $name … SELECT needs a column list");
                foreach ($this->at($y, $bodyEnd, $d) as $s) if ($this->isW($s, 'UNION')) $this->fail('INSERT … SELECT … UNION is not supported');
                if (!$this->isW($y, 'SELECT')) $this->fail('INSERT … (SELECT) is not supported');
                $this->addCols($cols);
                $z = $this->nx($y, $bodyEnd); while ($z !== null && $this->isW($z, 'DISTINCT', 'ALL', 'DISTINCTROW', 'STRAIGHT_JOIN', 'SQL_NO_CACHE', 'SQL_CALC_FOUND_ROWS', 'HIGH_PRIORITY')) $z = $this->nx($z, $bodyEnd);
                $last = $z === null ? $y : $this->prevSig($z) ?? $y;
                $this->post[$last] = ($this->post[$last] ?? '') . " {$this->tenant},{$this->branch},";
            }
            $this->selectish($y, $bodyEnd);
        } else $this->fail('unsupported INSERT form');
    }
    private function addCols(int $open): void {
        $in = $this->first($open + 1, $this->match[$open]);
        if ($in !== null) foreach ($this->sig as $s) { if ($s <= $open || $s >= $this->match[$open]) continue; $id = $this->ident($s); if ($id !== null && in_array(strtolower($id), ['tenant_id', 'branch_id'], true)) $this->fail('tenant_id/branch_id are set by the server'); }
        $this->post[$open] = ($this->post[$open] ?? '') . ($in === null ? 'tenant_id,branch_id' : 'tenant_id,branch_id,');
    }
    private function addVals(int $open): void {
        $in = $this->first($open + 1, $this->match[$open]);
        $this->post[$open] = ($this->post[$open] ?? '') . ($in === null ? "{$this->tenant},{$this->branch}" : "{$this->tenant},{$this->branch},");
    }
}

/** Rewrite one statement for tenant T / branch B (cached per process). */
function ts_scope(string $sql, int $tenant, int $branch, array $tables): string {
    static $cache = [];
    if ($tenant <= 0 || $branch <= 0) throw new TenantScopeError('Tenant scope: no tenant/branch context');
    $pb = isset($tables['p_branches']); unset($tables['p_branches']);
    $k = $tenant . ':' . $branch . ':' . count($tables) . ($pb ? 'P' : '') . ':' . $sql;
    if (isset($cache[$k])) return $cache[$k];
    if (count($cache) > 4000) $cache = [];
    return $cache[$k] = (new TenantScoper($sql, $tenant, $branch, $tables, $pb))->rewrite();
}

/** PDO used for branch ledgers in shared-database mode: every statement is scoped before it reaches MySQL. */
final class TenantPDO extends PDO {
    public int $tenantId = 0;
    public int $branchId = 0;
    /** @var array<string,bool>|null */ private ?array $tables = null;
    public function businessTables(): array {
        if ($this->tables === null) { $this->tables = []; foreach (parent::query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) if (ts_business_name((string)$t) || $t === 'p_branches') $this->tables[strtolower((string)$t)] = true; }
        return $this->tables;
    }
    public function scope(string $sql): string { return ts_scope($sql, $this->tenantId, $this->branchId, $this->businessTables()); }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $st = parent::prepare($this->scope($query), $options);
        if (preg_match('/^\s*CREATE\s+TABLE/i', $query)) $this->tables = null;
        return $st;
    }
    public function exec(string $statement): int|false {
        $r = parent::exec($this->scope($statement));
        if (preg_match('/^\s*CREATE\s+TABLE/i', $statement)) $this->tables = null; // new business table becomes scoped immediately
        return $r;
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return $fetchMode === null ? parent::query($this->scope($query)) : parent::query($this->scope($query), $fetchMode, ...$fetchModeArgs);
    }
}
