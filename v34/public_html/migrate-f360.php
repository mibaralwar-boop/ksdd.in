<?php
declare(strict_types=1);
// Finance 360 V16 — one-click database upgrade (owner only) + CLI:  php migrate-f360.php
// Additive only: creates f3_* tables, adds NULLable columns to dd_employees, copies legacy section/designation into the
// four V16 departments, seeds settings with INSERT IGNORE. Nothing is deleted or overwritten. Rollback: migrations/f360_rollback.sql
require __DIR__ . '/lib.php';
require __DIR__ . '/f360/core.php';
require_once __DIR__ . '/salary360.php';
/** V21 §13: back up before migrating. Automatic (encrypted, verified) once the backup passphrase exists (schema ≥ 7);
 *  before that the owner must confirm a manual export. Returns a note, or throws so the upgrade does not run. */
function f3_pre_migration_backup(?array $u, bool $confirmed): string {
    if (f3_schema_version() >= F3_SCHEMA) return '';
    $auto = false; try { $auto = f3_schema_version() >= 7 && (bool)row("SELECT 1 FROM f3_int_secrets WHERE name='backup_passphrase'"); } catch (Throwable $e) {}
    if ($auto) { require_once __DIR__ . '/f360/admin.php'; require_once __DIR__ . '/f360/integrations.php'; $r = f3_backup_run($u, true); return 'Backup taken and verified before the upgrade: ' . $r['name'] . '. '; }
    if (!$confirmed) fail('Take a backup first (hPanel → Databases → phpMyAdmin → Export, and a copy of public_html), then tick "I have a backup" (CLI: add --i-have-a-backup) and run the upgrade again.', 409);
    return '';
}
if (PHP_SAPI === 'cli') { $note = f3_pre_migration_backup(null, in_array('--i-have-a-backup', $argv, true)); if ($note) echo $note . "\n"; $a = f3_migrate_all(null); echo 'Finance 360 schema v' . f3_schema_version() . ' (applied: ' . ($a ? implode(',', $a) : 'none') . ")\n"; $m = f3_map_legacy_staff(); echo 'Staff still needing a department: ' . count($m['unmapped']) . ($m['unmapped'] ? ' (' . implode(', ', $m['unmapped']) . ')' : '') . "\n"; exit(0); }
security_headers(); $msg = ''; $err = ''; $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
try {
    start_session(); $u = user(); if (($u['role'] ?? '') !== 'owner') fail('Only the owner can run the Finance 360 upgrade.', 403);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { check_csrf((string)($_POST['csrf'] ?? '')); set_time_limit(300); $note = f3_pre_migration_backup($u, !empty($_POST['backup_ok'])); $a = f3_migrate_all($u); $msg = ($note ?? '') . ($a ? 'Applied Finance 360 update(s): v' . implode(', v', $a) . '. Existing records were preserved.' : 'Already up to date.'); }
    $ver = f3_schema_version(); $unmapped = $ver ? rows("SELECT name FROM dd_employees WHERE COALESCE(status,'active')='active' AND f3_dept_id IS NULL") : [];
} catch (Throwable $e) { $err = $e instanceof RuntimeException ? $e->getMessage() : 'Upgrade failed — nothing was deleted. Check the PHP error log.'; if (!$e instanceof RuntimeException) error_log('F360 upgrade: ' . $e->getMessage()); $ver = 0; $unmapped = []; }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Finance 360 upgrade</title><link rel="stylesheet" href="assets/f360/app.css?v=16"></head>
<body class="plain-page"><main class="plain-wrap"><img src="assets/brand/logo-full.png" alt="Khadak Singh Da Dhaba" class="plain-logo"><h1>Finance 360 — database upgrade</h1>
<?php if ($err): ?><div class="alert danger"><?= $h($err) ?> <?php if (str_contains($err, 'sign in')): ?><a href="index.php">Sign in as owner</a><?php endif; ?></div><?php endif; ?>
<?php if ($msg): ?><div class="alert ok"><?= $h($msg) ?></div><?php endif; ?>
<?php if (!$err || $ver): ?><p>Current Finance 360 schema: <b>v<?= (int)$ver ?></b> of <b>v<?= F3_SCHEMA ?></b>. Take a backup first (Settings → Backup, or phpMyAdmin → Export).</p>
<form method="post"><input type="hidden" name="csrf" value="<?= $h(csrf()) ?>"><?php if ($ver < F3_SCHEMA): ?><p><label><input type="checkbox" name="backup_ok" value="1"> I have a backup of the database and files (needed when the app cannot take one automatically)</label></p><?php endif; ?><button class="btn primary" type="submit">Run Finance 360 upgrade</button> <a class="btn" href="index.php">Open Finance 360</a></form>
<?php if ($unmapped): ?><div class="alert warn"><b><?= count($unmapped) ?> active staff need a department</b> (their old section could not be matched): <?= $h(implode(', ', array_column($unmapped, 'name'))) ?>. Fix them in Staff.</div><?php endif; endif; ?>
</main></body></html>
