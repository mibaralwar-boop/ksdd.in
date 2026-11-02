<?php
declare(strict_types=1);
// V34 hostile tests for the V31 single-database isolation (platform/mt.php). STAGING/TEST ONLY.
// Needs a database converted by tools/v31-convert.php (tests/run_tests_mt.sh leaves one: ksdd_mt_test).
//   F360_CONFIG=<config with single_db=true, db_name containing "test"> php tests/mt_hostile_tests.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'lib.php';
if (!mt_enabled() || !preg_match('/test/', (string)cfg()['db_name'])) exit("refusing: needs single_db=true on a *test* database\n");
$P = 0; $F = 0;
function T(string $id, string $name, callable $fn): void { global $P, $F; try { $r = $fn(); $ok = $r === true; $m = is_string($r) ? $r : ''; } catch (Throwable $e) { $ok = false; $m = get_class($e) . ': ' . $e->getMessage(); } $ok ? $P++ : $F++; echo ($ok ? '  PASS ' : '  FAIL ') . "$id $name" . ($m !== '' ? "  → $m" : '') . "\n"; }
function refused(callable $fn, string $why = ''): bool|string { try { $fn(); } catch (MtGuardError $e) { return true; } catch (PDOException $e) { return $why === 'db' ? true : 'refused by MySQL not by the guard: ' . $e->getMessage(); } catch (RuntimeException $e) { return true; } return 'NOT refused'; }
$t = main_db(); // two independent app connections, like two PHP requests of two different restaurants
function main_db(): array { $c = cfg(); return ['db_host' => $c['db_host'], 'db_port' => $c['db_port'], 'db_name' => $c['db_name'], 'db_user' => $c['db_user'], 'db_password' => $c['db_password']]; }
$A = db_connect_target($t); db_session_collation($A); mt_set_scope($A, 1, 1);
$B = db_connect_target($t); db_session_collation($B); mt_set_scope($B, 2, 2);
$q = fn(PDO $p, string $sql, ...$a) => (function () use ($p, $sql, $a) { $s = $p->prepare($sql); $s->execute($a); return $s; })();
$A->exec("DELETE FROM dd_settings WHERE `key` LIKE 'hostile_%'"); $B->exec("DELETE FROM dd_settings WHERE `key` LIKE 'hostile_%'");
$q($A, 'INSERT INTO dd_settings(`key`,value) VALUES(?,?)', 'hostile_probe', 'SECRET-A');
$q($B, 'INSERT INTO dd_settings(`key`,value) VALUES(?,?)', 'hostile_probe', 'SECRET-B');
$rowsA = (int)$A->query('SELECT COUNT(*) FROM dd_employees')->fetchColumn();

echo "== data isolation through the scoped views\n";
T('H01', 'each restaurant sees only its own row for the same key (per-tenant unique keys)', fn() => $q($A, "SELECT value FROM dd_settings WHERE `key`='hostile_probe'")->fetchAll(PDO::FETCH_COLUMN) === ['SECRET-A'] && $q($B, "SELECT value FROM dd_settings WHERE `key`='hostile_probe'")->fetchAll(PDO::FETCH_COLUMN) === ['SECRET-B']);
T('H02', '"OR 1=1", UNION and sub-queries cannot widen the view', fn() => !str_contains(json_encode($q($B, "SELECT value FROM dd_settings WHERE `key`='x' OR 1=1 UNION SELECT value FROM dd_settings WHERE (SELECT COUNT(*) FROM dd_settings)>=0")->fetchAll(PDO::FETCH_COLUMN)), 'SECRET-A'));
T('H03', 'restaurant B sees none of KSDD\'s employees', fn() => (int)$B->query('SELECT COUNT(*) FROM dd_employees')->fetchColumn() === 0 && $rowsA > 0 ? true : "A=$rowsA");
T('H04', 'UPDATE / DELETE without WHERE only touch the own restaurant', function () use ($q, $A, $B) { $B->exec("UPDATE dd_settings SET value=CONCAT(value,'!') WHERE `key`='hostile_probe'"); $B->exec("DELETE FROM dd_settings WHERE `key`='hostile_probe' AND 1=1");
    return $q($A, "SELECT value FROM dd_settings WHERE `key`='hostile_probe'")->fetchColumn() === 'SECRET-A' ? true : 'A changed'; });
T('H05', 'tenant_id is not even a column of the app view (cannot be read or set)', fn() => refused(fn() => $B->exec("UPDATE dd_settings SET tenant_id=1"), 'db'));

echo "== statement guard (P0-2): base tables, context table, hidden SQL\n";
foreach (['SELECT * FROM mt_dd_users' => 'plain', 'SELECT * FROM `mt_dd_users`' => 'backticks', 'select * from MT_DD_USERS' => 'upper case', 'SELECT u.* FROM dd_settings s JOIN mt_dd_users u ON 1' => 'join',
          'SELECT (SELECT COUNT(*) FROM mt_dd_employees)' => 'sub-query', 'UPDATE mt_ctx SET tenant_id=1, branch_id=1 WHERE conn_id=CONNECTION_ID()' => 'context rewrite', 'REPLACE INTO mt_ctx VALUES(CONNECTION_ID(),1,1,NOW())' => 'context replace',
          'DELETE FROM mt_ctx' => 'context delete', 'SELECT 1 /*!50000 , (SELECT password FROM mt_dd_users LIMIT 1) */' => 'executable comment', "SELECT 1 /*M!100000 , 2 */" => 'MariaDB executable comment',
          'SELECT 1; UPDATE dd_settings SET value=1' => 'stacked statements', 'INSERT INTO mt_dd_settings(`key`,value,tenant_id,branch_id) VALUES(1,1,1,1)' => 'direct base write', 'SELECT * FROM mt_tables' => 'registry',
          'CREATE TEMPORARY TABLE x AS SELECT * FROM mt_dd_users' => 'copy via temp table', 'SELECT * FROM information_schema.TABLES t JOIN mt_ctx c' => 'join context'] as $sql => $what)
    T('G-' . str_pad((string)(crc32($sql) % 1000), 3, '0', STR_PAD_LEFT), "refuses $what: " . substr($sql, 0, 60), fn() => refused(fn() => $B->query($sql)) === true && refused(fn() => $B->prepare($sql)) === true && refused(fn() => $B->exec($sql)) === true);
T('G-OK', 'normal statements with mt_ inside strings / comments are allowed', fn() => $B->query("SELECT 'mt_dd_users' x /* mt_ctx */ -- mt_x\n")->fetchColumn() === 'mt_dd_users');
T('H06', 'after every refused attempt the context is still B (database view of the scope)', fn() => mt_db_scope($B) === ['tenant_id' => 2, 'branch_id' => 2] && mt_db_scope($A) === ['tenant_id' => 1, 'branch_id' => 1]);

echo "== INSERT rewriting\n";
T('H07', 'application cannot choose tenant_id / branch_id on INSERT', fn() => refused(fn() => $q($B, 'INSERT INTO dd_settings(`key`,value,tenant_id,branch_id) VALUES(?,?,1,1)', 'x', 'y')) === true && refused(fn() => $q($B, 'INSERT INTO dd_settings SET `key`=?, tenant_id=1', 'x')) === true);
T('H08', 'INSERT without a column list is refused (would bypass the rewriter)', fn() => refused(fn() => $B->exec("INSERT INTO dd_settings VALUES('a','b')")));

echo "== missing / stale context (fail closed)\n";
T('H09', 'a new connection has NO scope: views return zero rows, writes refused', function () use ($t) { $N = db_connect_target($t); db_session_collation($N);
    $n = (int)$N->query('SELECT COUNT(*) FROM dd_settings')->fetchColumn(); $w = refused(fn() => $N->exec("INSERT INTO dd_settings(`key`,value) VALUES('z','z')"));
    return $n === 0 && $w === true && mt_db_scope($N) === ['tenant_id' => 0, 'branch_id' => 0] ? true : "rows=$n write=" . json_encode($w); });
T('H10', 'a stale context row of a dead connection is purged', function () use ($A) { $A->rawExec("REPLACE INTO mt_ctx VALUES(4294967000,1,1,UTC_TIMESTAMP())"); mt_purge_stale_ctx($A);
    return (int)$A->rawQuery('SELECT COUNT(*) FROM mt_ctx WHERE conn_id=4294967000')->fetchColumn() === 0 && mt_db_scope($A)['tenant_id'] === 1; });
T('H11', 'scope cannot change inside an open transaction', function () use ($A) { $A->beginTransaction(); try { return refused(fn() => mt_set_scope($A, 2, 2)); } finally { $A->rollBack(); } });
T('H12', 'scope is re-verified from the database after it is set', fn() => mt_db_scope($A) === ['tenant_id' => 1, 'branch_id' => 1]);
T('H13', 'the platform connection (scope 0/0) sees no restaurant rows', function () use ($t) { $Pc = db_connect_target($t); db_session_collation($Pc); mt_set_scope($Pc, 0, 0); return (int)$Pc->query('SELECT COUNT(*) FROM dd_employees')->fetchColumn() === 0; });

$A->exec("DELETE FROM dd_settings WHERE `key` LIKE 'hostile_%'"); $B->exec("DELETE FROM dd_settings WHERE `key` LIKE 'hostile_%'");
echo "\nRESULT: $P passed, $F failed\n"; exit($F ? 1 : 0);
