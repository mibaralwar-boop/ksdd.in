<?php
declare(strict_types=1);
// Unit tests for tenant_scope.php (shared-database isolation rewriter). CLI only.  php tests/tenant_scope_tests.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/tenant_scope.php';

$T = ['dd_settings' => true, 'dd_employees' => true, 'dd_ledger' => true, 'f3_pay_lines' => true, 'f3_pay_periods' => true, 'dd_files' => true, 'f3_closings' => true, 'dd_users' => true];
$P = 0; $F = 0;
function S(string $sql): string { global $T; return ts_scope($sql, 7, 42, $T); }
function check(string $name, callable $fn): void { global $P, $F; try { $fn(); $P++; echo "  PASS $name\n"; } catch (Throwable $e) { $F++; echo "  FAIL $name → " . $e->getMessage() . "\n"; } }
function has(string $out, string ...$needles): void { $w = fn($x) => preg_replace('/\s+/', ' ', str_replace(['( ', ' )'], ['(', ')'], $x)); foreach ($needles as $n) if (!str_contains($w($w($out)), $w($w($n)))) throw new RuntimeException("missing [{$n}] in: $out"); }
function refused(string $sql): void { try { S($sql); } catch (TenantScopeError $e) { return; } throw new RuntimeException("not refused: $sql"); }
$C = '`e`.tenant_id=7 AND `e`.branch_id=42';

check('simple select gets WHERE', function () { has(S('SELECT * FROM dd_employees'), 'WHERE (`dd_employees`.tenant_id=7 AND `dd_employees`.branch_id=42)'); });
check('existing WHERE is wrapped (OR cannot escape)', function () use ($C) { has(S('SELECT * FROM dd_employees e WHERE a=1 OR b=2 ORDER BY id'), "WHERE ($C) AND ( a=1 OR b=2 ) ORDER BY"); });
check('alias with AS', function () use ($C) { has(S('SELECT e.id FROM `dd_employees` AS e WHERE e.id=?'), "($C)"); });
check('inner join scopes both in WHERE', function () { has(S('SELECT * FROM dd_employees e JOIN f3_pay_lines l ON l.employee_id=e.id WHERE l.period=?'), '`e`.branch_id=42', '`l`.branch_id=42'); });
check('LEFT JOIN is scoped in ON (keeps outer-join rows)', function () { $o = S('SELECT * FROM dd_employees e LEFT JOIN f3_pay_lines l ON l.employee_id=e.id AND l.period=? WHERE e.active=1'); has($o, 'ON (`l`.tenant_id=7 AND `l`.branch_id=42) AND ( l.employee_id=e.id AND l.period=? )', 'WHERE (`e`.tenant_id=7'); });
check('LEFT() function inside ON is not a join', function () { has(S('SELECT * FROM dd_employees e LEFT JOIN dd_users u ON LEFT(u.email,3)=e.code'), '`u`.branch_id=42'); });
check('LEFT JOIN … ON … GROUP BY without WHERE (ordering)', function () { $o = S('SELECT p.id FROM dd_employees p LEFT JOIN dd_ledger t ON t.employee_id=p.id AND t.voided=0 GROUP BY p.id ORDER BY p.id'); has($o, 'AND t.voided=0) WHERE (`p`.tenant_id=7 AND `p`.branch_id=42) GROUP BY'); });
check('table created after the connection opened is still scoped (by name)', function () { has(S('SELECT * FROM f3_brand_new_table WHERE x=1'), '`f3_brand_new_table`.branch_id=42'); has(S('INSERT INTO f3_brand_new_table(a) VALUES(?)'), '(tenant_id,branch_id,a)'); });
check('comma join', function () { has(S('SELECT * FROM dd_employees a, dd_ledger b WHERE a.id=b.employee_id'), '`a`.branch_id=42', '`b`.branch_id=42'); });
check('sub-query in WHERE and SELECT list', function () { $o = S('SELECT e.id,(SELECT COUNT(*) FROM f3_pay_lines p WHERE p.employee_id=e.id) n FROM dd_employees e WHERE e.id IN (SELECT employee_id FROM dd_ledger)'); has($o, '`p`.branch_id=42', '`dd_ledger`.branch_id=42', '`e`.branch_id=42'); });
check('derived table', function () { has(S('SELECT x.n FROM (SELECT COUNT(*) n FROM dd_ledger WHERE voided=0) x'), '`dd_ledger`.branch_id=42'); });
check('UNION parts each scoped', function () { $o = S('SELECT id FROM dd_ledger WHERE a=1 UNION ALL SELECT id FROM dd_files'); has($o, '`dd_ledger`.branch_id=42', '`dd_files`.branch_id=42'); });
check('GROUP BY / LIMIT / FOR UPDATE without WHERE', function () { has(S('SELECT k FROM dd_settings GROUP BY k LIMIT 1 FOR UPDATE'), 'dd_settings WHERE (`dd_settings`.tenant_id=7 AND `dd_settings`.branch_id=42) GROUP BY'); has(S('SELECT * FROM f3_closings WHERE business_date=? FOR UPDATE'), ') FOR UPDATE'); });
check('platform tables untouched', function () { if (S('SELECT * FROM p_users WHERE id=?') !== 'SELECT * FROM p_users WHERE id=?') throw new RuntimeException('changed'); });
check('column named like a table (f3_dept_id) is not a table', function () { has(S('UPDATE dd_employees SET f3_dept_id=? WHERE id=?'), '`dd_employees`.branch_id=42'); });
check('UPDATE adds scope', function () { has(S("UPDATE dd_settings SET value=? WHERE `key`=?"), "WHERE (`dd_settings`.tenant_id=7 AND `dd_settings`.branch_id=42) AND ( `key`=? )"); has(S('UPDATE dd_settings SET value=1'), 'value=1 WHERE ('); });
check('UPDATE with JOIN', function () { has(S('UPDATE f3_pay_lines l JOIN dd_employees e ON e.id=l.employee_id SET l.x=e.y WHERE l.period=?'), '`l`.branch_id=42', '`e`.branch_id=42'); });
check('DELETE single + multi table', function () { has(S('DELETE FROM dd_files WHERE id=?'), '`dd_files`.branch_id=42'); has(S('DELETE l FROM f3_pay_lines l JOIN dd_employees e ON e.id=l.employee_id WHERE e.id=?'), '`l`.branch_id=42', '`e`.branch_id=42'); });
check('INSERT VALUES (multi-row) gets columns and values', function () { has(S('INSERT INTO dd_settings(`key`,value) VALUES(?,?),(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)'), '(tenant_id,branch_id,`key`,value)', 'VALUES(7,42,?,?),(7,42,?,?)', 'VALUES(value)'); });
check('INSERT IGNORE / REPLACE / SET form', function () { has(S('INSERT IGNORE INTO dd_settings (`key`, value) VALUES (?, ?)'), 'tenant_id,branch_id,'); has(S('REPLACE INTO dd_settings(`key`,value) VALUES(?,?)'), '7,42,?'); has(S('INSERT INTO dd_files SET name=?, size=?'), 'SET tenant_id=7,branch_id=42, name=?'); });
check('INSERT … SELECT scopes target and source', function () { $o = S('INSERT INTO f3_pay_lines(period,employee_id) SELECT ?,id FROM dd_employees WHERE active=1'); has($o, '(tenant_id,branch_id,period,employee_id)', 'SELECT 7,42, ?,id', '`dd_employees`.branch_id=42'); });
check('INSERT into platform table from business SELECT', function () { has(S('INSERT INTO p_audit(x) SELECT id FROM dd_users'), '`dd_users`.branch_id=42'); });
check('refuses: RIGHT JOIN, NATURAL, USING, no column list, tenant assignment, TRUNCATE, DROP, CTE', function () {
    refused('SELECT * FROM dd_ledger a RIGHT JOIN dd_files b ON a.id=b.id');
    refused('SELECT * FROM dd_ledger NATURAL JOIN dd_files');
    refused('SELECT * FROM dd_ledger a LEFT JOIN dd_files b USING (id)');
    refused('INSERT INTO dd_settings VALUES (?,?)');
    refused('UPDATE dd_settings SET branch_id=1 WHERE 1');
    refused('INSERT INTO dd_settings(tenant_id,`key`) VALUES(1,?)');
    refused('TRUNCATE dd_ledger');
    refused('DROP TABLE IF EXISTS dd_ledger');
    refused('WITH x AS (SELECT * FROM dd_ledger) SELECT * FROM x');
    refused('SELECT * FROM (dd_ledger a JOIN dd_files b ON a.id=b.id)');
});
check('strings / comments are not touched', function () { $o = S("SELECT 'FROM dd_ledger WHERE' s, \"x\" FROM dd_files -- dd_ledger\n WHERE n='a''b' /* dd_ledger */"); has($o, "'FROM dd_ledger WHERE'", "n='a''b'", '`dd_files`.branch_id=42'); });
check('CREATE TABLE gets tenant columns and per-branch keys', function () {
    $o = S('CREATE TABLE IF NOT EXISTS dd_newthing (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE, period CHAR(7), name VARCHAR(10), UNIQUE KEY uq_p (period,name), KEY ix (name)) ENGINE=InnoDB');
    has($o, '`tenant_id` INT NOT NULL, `branch_id` INT NOT NULL', 'UNIQUE KEY `uq_branch_row` (`branch_id`,`id`)', 'id INT AUTO_INCREMENT PRIMARY KEY', 'UNIQUE KEY uq_p (`branch_id`,period,name)', 'UNIQUE KEY `uq_code` (`branch_id`,`code`)', 'KEY `ix_tenant_branch`');
    if (str_contains($o, 'NOT NULL UNIQUE')) throw new RuntimeException('inline unique left: ' . $o);
    $o = S('CREATE TABLE IF NOT EXISTS dd_kv (`key` VARCHAR(40) NOT NULL PRIMARY KEY, value TEXT)');
    has($o, 'PRIMARY KEY (`branch_id`,`key`)');
    $o = S('CREATE TABLE IF NOT EXISTS f3_x (period CHAR(7) NOT NULL, a INT, PRIMARY KEY (period))');
    has($o, 'PRIMARY KEY (`branch_id`,period)');
});
check('explicit (non auto-increment) id key becomes per-branch; FOREIGN KEYs become composite; rows tied to p_branches', function () {
    global $T;
    $o = S('CREATE TABLE IF NOT EXISTS f3_departments (id TINYINT UNSIGNED PRIMARY KEY, code VARCHAR(20) NOT NULL UNIQUE)');
    has($o, 'PRIMARY KEY (`branch_id`,`id`)'); if (str_contains($o, 'uq_branch_row')) throw new RuntimeException('explicit id needs no extra key: ' . $o);
    $o = S('CREATE TABLE IF NOT EXISTS f3_designations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, dept_id TINYINT UNSIGNED NOT NULL, CONSTRAINT f3_desig_dept FOREIGN KEY (dept_id) REFERENCES f3_departments(id))');
    has($o, 'FOREIGN KEY (`branch_id`,dept_id) REFERENCES f3_departments(`branch_id`,id)', 'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'uq_branch_row');
    $o = ts_scope('CREATE TABLE IF NOT EXISTS dd_x (id INT AUTO_INCREMENT PRIMARY KEY)', 7, 42, $T + ['p_branches' => true]);
    has($o, 'CONSTRAINT `fkb_dd_x` FOREIGN KEY (`tenant_id`,`branch_id`) REFERENCES `p_branches` (`tenant_id`,`id`)');
    has(S('ALTER TABLE dd_bills ADD CONSTRAINT fk1 FOREIGN KEY (supplier_id) REFERENCES dd_suppliers(id)'), 'FOREIGN KEY (`branch_id`,supplier_id) REFERENCES dd_suppliers(`branch_id`,id)');
    refused('CREATE TABLE dd_y (id INT AUTO_INCREMENT PRIMARY KEY, sup BIGINT REFERENCES dd_suppliers(id))');
});
check('ALTER TABLE ADD UNIQUE is per branch; inline unique refused', function () {
    has(S('ALTER TABLE dd_ledger ADD UNIQUE KEY uq_ref (reference)'), '(`branch_id`,reference)');
    has(S('ALTER TABLE dd_ledger ADD CONSTRAINT c1 UNIQUE (a,b)'), '(`branch_id`,a,b)');
    has(S('ALTER TABLE dd_ledger ADD COLUMN z INT NULL, ADD KEY ix_z (z)'), 'ADD KEY ix_z (z)');
    refused('ALTER TABLE dd_ledger ADD COLUMN code VARCHAR(9) UNIQUE');
    has(S('CREATE UNIQUE INDEX u1 ON dd_ledger (a)'), '(`branch_id`,a)');
});

// Syntax: every rewritten statement must be accepted by the real server (PREPARE checks syntax and columns).
if (getenv('TS_DSN')) {
    $pdo = new PDO((string)getenv('TS_DSN'), (string)getenv('TS_USER'), (string)getenv('TS_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec('DROP TABLE IF EXISTS dd_employees, dd_ledger, f3_pay_lines, dd_settings, dd_users, dd_files');
    $pdo->exec('CREATE TABLE dd_employees (tenant_id INT, branch_id INT, id INT, code VARCHAR(9), active INT, f3_dept_id INT, x INT, y INT)');
    $pdo->exec('CREATE TABLE dd_ledger (tenant_id INT, branch_id INT, id INT, employee_id INT, voided INT, a INT, b INT)');
    $pdo->exec('CREATE TABLE f3_pay_lines (tenant_id INT, branch_id INT, id INT, employee_id INT, period CHAR(7), x INT)');
    $pdo->exec('CREATE TABLE dd_settings (tenant_id INT, branch_id INT, `key` VARCHAR(40), value TEXT, k INT, PRIMARY KEY (branch_id,`key`))');
    $pdo->exec('CREATE TABLE dd_users (tenant_id INT, branch_id INT, id INT, email VARCHAR(90))');
    $pdo->exec('CREATE TABLE dd_files (tenant_id INT, branch_id INT, id INT, name VARCHAR(9), size INT)');
    foreach (['SELECT * FROM dd_employees e WHERE a=1 OR b=2 ORDER BY id' => false, 'SELECT e.id,(SELECT COUNT(*) FROM f3_pay_lines p WHERE p.employee_id=e.id) n FROM dd_employees e WHERE e.id IN (SELECT employee_id FROM dd_ledger)' => true,
              'SELECT * FROM dd_employees e LEFT JOIN f3_pay_lines l ON l.employee_id=e.id AND l.period=? WHERE e.active=1' => true, 'UPDATE f3_pay_lines l JOIN dd_employees e ON e.id=l.employee_id SET l.x=e.y WHERE l.period=?' => true,
              'DELETE l FROM f3_pay_lines l JOIN dd_employees e ON e.id=l.employee_id WHERE e.id=?' => true, 'INSERT INTO dd_settings(`key`,value) VALUES(?,?),(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)' => true,
              'INSERT INTO f3_pay_lines(period,employee_id) SELECT ?,id FROM dd_employees WHERE active=1' => true, 'INSERT INTO dd_files SET name=?, size=?' => true, 'SELECT k FROM dd_settings GROUP BY k LIMIT 1 FOR UPDATE' => true,
              'SELECT id FROM dd_ledger WHERE a=1 UNION ALL SELECT id FROM dd_files' => true, 'UPDATE dd_employees SET f3_dept_id=? WHERE id=?' => true, 'SELECT p.id FROM dd_employees p LEFT JOIN dd_ledger t ON t.employee_id=p.id AND t.voided=0 GROUP BY p.id ORDER BY p.id' => true] as $sql => $_) {
        check('server accepts: ' . substr($sql, 0, 60), function () use ($pdo, $sql) { if (str_contains($sql, 'a=1 OR b=2')) $sql = str_replace('a=1 OR b=2', 'active=1 OR code=2', $sql); $pdo->prepare(S($sql)); });
    }
    check('server: data really isolated', function () use ($pdo) {
        $pdo->exec('DELETE FROM dd_settings');
        foreach ([[7, 42], [8, 43]] as [$t, $b]) { global $T; $pdo->prepare(ts_scope('INSERT INTO dd_settings(`key`,value) VALUES(?,?)', $t, $b, $T))->execute(['restaurant', "R$b"]); }
        $s = $pdo->prepare(S('SELECT value FROM dd_settings WHERE `key`=? OR 1=1')); $s->execute(['restaurant']); $v = $s->fetchAll(PDO::FETCH_COLUMN);
        if ($v !== ['R42']) throw new RuntimeException('saw ' . json_encode($v));
    });
    $pdo->exec('DROP TABLE IF EXISTS dd_employees, dd_ledger, f3_pay_lines, dd_settings, dd_users, dd_files');
}
echo "\nRESULT: $P passed, $F failed\n"; exit($F ? 1 : 0);
