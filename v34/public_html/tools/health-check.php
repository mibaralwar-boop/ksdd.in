<?php
declare(strict_types=1);
/* Finance 360 V34 — installation health check (CLI only, read-only).
     php tools/health-check.php            exit 0 = all OK, 1 = at least one FAIL (WARN does not fail)
   Checks: config + private storage outside the web root, writable private folders, database connection, single-database
   isolation state (registry ↔ views, guard active, unscoped connection sees no rows), platform schema, cron, payments/signup. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'lib.php';
$fail = 0; $warn = 0;
$out = function (string $lvl, string $msg) use (&$fail, &$warn) { if ($lvl === 'FAIL') $fail++; if ($lvl === 'WARN') $warn++; printf("  %-4s %s\n", $lvl, $msg); };
$web = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
echo "Finance 360 health check — " . gmdate('Y-m-d H:i') . " UTC — web root $web\n";

// ---- configuration & private storage ----
$cfgFile = null; $env = (string)getenv('F360_PRIVATE_DIR');
foreach ([$env !== '' ? rtrim($env, '/') . '/config.php' : '', dirname($web) . '/ksdd_private/config.php', $web . '/config.php'] as $f) if ($f !== '' && is_file($f)) { $cfgFile = realpath($f); break; }
if (!$cfgFile) $out('FAIL', 'config.php not found (expected ' . dirname($web) . '/ksdd_private/config.php or $F360_PRIVATE_DIR/config.php)');
elseif (str_starts_with($cfgFile, $web . '/')) $out('FAIL', "config.php is INSIDE the web root ($cfgFile) — move it to the private folder");
else $out('OK', "config.php outside the web root: $cfgFile");
$pr = realpath(private_root()) ?: private_root();
private_outside_webroot() ? $out('OK', "private storage outside the web root: $pr") : $out('FAIL', "private storage is INSIDE the web root: $pr");
foreach (['uploads', 'backups', 'exports', 'imports', 'logs', 'platform', 'tenants'] as $d) { $p = $pr . '/' . $d; if (!is_dir($p)) @mkdir($p, 0750, true); is_writable($p) ? $out('OK', "writable: $d/") : $out('FAIL', "not writable: $p"); }
if (is_dir($web . '/private') && glob($web . '/private/uploads/*')) $out('WARN', 'old files remain in public_html/private/uploads — move them to ' . $pr . '/uploads (see guide)');
$c = cfg();
foreach (['setup_key', 'pii_key'] as $k) { $v = (string)($c[$k] ?? ''); (strlen($v) >= 32 && !str_starts_with($v, 'REPLACE_') && !str_starts_with($v, 'COPY_')) ? $out('OK', "$k set") : $out('FAIL', "$k missing or placeholder"); }
$isStaging = ($c['environment'] ?? 'production') === 'staging';
$out('OK', 'environment: ' . ($c['environment'] ?? 'production') . ' · database: ' . ($c['db_name'] ?? '?'));
if ($isStaging && ($c['db_name'] ?? '') === 'u245900320_ksdd') $out('FAIL', 'STAGING config points at the PRODUCTION database u245900320_ksdd');
if (!$isStaging && ($c['db_name'] ?? '') === 'u245900320_staging') $out('FAIL', 'PRODUCTION config points at the staging database');

// ---- database ----
try { $p = db_connect_target(['db_host' => $c['db_host'] ?? 'localhost', 'db_port' => $c['db_port'] ?? 3306, 'db_name' => $c['db_name'] ?? '', 'db_user' => $c['db_user'] ?? '', 'db_password' => $c['db_password'] ?? '']); db_session_collation($p);
    $out('OK', 'database connection: ' . $p->rawQuery('SELECT VERSION()')->fetchColumn()); }
catch (Throwable $e) { $out('FAIL', 'database connection: ' . $e->getMessage()); echo "\nRESULT: FAIL\n"; exit(1); }
$conv = (int)$p->rawQuery("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='mt_tables'")->fetchColumn();
if (mt_enabled()) {
    if (!$conv) $out('FAIL', "single_db=true but the database is not converted (run tools/v31-convert.php)");
    else {
        $reg = (int)$p->rawQuery('SELECT COUNT(*) FROM mt_tables')->fetchColumn();
        $views = (int)$p->rawQuery("SELECT COUNT(*) FROM information_schema.VIEWS v JOIN mt_tables t ON t.name=v.TABLE_NAME WHERE v.TABLE_SCHEMA=DATABASE()")->fetchColumn();
        $legacy = (int)$p->rawQuery("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND (TABLE_NAME LIKE 'dd\\_%' OR TABLE_NAME LIKE 'f3\\_%')")->fetchColumn();
        $views === $reg ? $out('OK', "single database: $reg scoped tables, every one behind a scoped view") : $out('FAIL', "scoped tables $reg but views $views (run tools/v31-convert.php --refresh-views)");
        $legacy === 0 ? $out('OK', 'no unscoped business tables left') : $out('FAIL', "$legacy business table(s) are still plain tables (not isolated)");
        try { $p->query('SELECT 1 FROM mt_ctx'); $out('FAIL', 'statement guard NOT active (internal table readable through the app connection)'); } catch (MtGuardError $e) { $out('OK', 'statement guard active (internal tables refused)'); }
        $n = (int)$p->query('SELECT COUNT(*) FROM dd_settings')->fetchColumn(); $n === 0 ? $out('OK', 'unscoped connection sees 0 restaurant rows (fail closed)') : $out('FAIL', "unscoped connection sees $n rows");
        $sc = (array)($c['single_db_scope'] ?? []); if (empty($c['platform']['enabled']) && (empty($sc['tenant_id']) || empty($sc['branch_id']))) $out('FAIL', 'single_db_scope missing while the platform is disabled');
    }
} else $conv ? $out('FAIL', 'database is converted but single_db is not true in config.php') : $out('WARN', 'single-restaurant mode (database not converted yet)');

// ---- platform ----
if (!empty($c['platform']['enabled'])) {
    try { require_once 'platform/schema.php'; $v = p_schema_version(); $v === P_SCHEMA ? $out('OK', "platform schema v$v") : $out('FAIL', "platform schema v$v, code expects v" . P_SCHEMA . ' (php tools/platform-setup.php migrate)');
        $adm = (int)pval('SELECT COUNT(*) FROM p_admins'); $adm ? $out('OK', "$adm Super Admin account(s)") : $out('WARN', 'no Super Admin yet (php tools/platform-setup.php admin <email> "<name>")');
        $last = pval("SELECT MAX(started_at) FROM p_job_runs"); $last && strtotime($last . ' UTC') > time() - 3600 ? $out('OK', "cron ran at $last UTC") : $out('WARN', 'cron has not run in the last hour (hPanel → Cron Jobs: platform-cron.php every 15 min)');
        $bm = (string)($c['platform']['billing']['mode'] ?? 'disabled'); $out($bm === 'live' && empty($c['platform']['billing']['live_approved']) ? 'FAIL' : 'OK', "payments: $bm");
        $out('OK', 'self-registration: ' . (p_signup_open() ? 'OPEN' : 'closed'));
        if ($isStaging && (string)($c['platform']['mail']['mode'] ?? '') !== 'log') $out('WARN', 'staging sends real e-mail (set platform.mail.mode = log)');
    } catch (Throwable $e) { $out('FAIL', 'platform: ' . $e->getMessage()); }
} else $out('WARN', 'platform (registration / Super Admin / app API) is disabled');
echo "\nRESULT: " . ($fail ? "FAIL ($fail)" : 'OK') . ($warn ? " · $warn warning(s)" : '') . "\n"; exit($fail ? 1 : 0);
