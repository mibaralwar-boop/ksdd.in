<?php
declare(strict_types=1);
// Apply the (additive) Finance 360 schema migrations to EVERY active branch database after uploading a new version.
//   php tools/platform-migrate-branches.php            dry run: shows each branch's schema version
//   php tools/platform-migrate-branches.php --apply    migrates branches one by one; a failure stops only that branch
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
require 'platform/schema.php'; require_once 'platform/tenancy.php'; require_once 'f360/admin.php';
$apply = in_array('--apply', $argv, true); $bad = 0;
echo 'Platform schema v' . p_schema_version() . ' (code v' . P_SCHEMA . ")\n"; if ($apply) { $d = p_migrate(); echo 'Platform migrations applied: ' . ($d ? implode(',', $d) : 'none') . "\n"; }
foreach (prows("SELECT * FROM p_branches WHERE status IN ('active','disabled') ORDER BY id") as $b) {
    try { db_switch(tenancy_branch_target($b)); $v = f3_schema_version(); $msg = "branch #{$b['id']} {$b['name']} ({$b['db_name']}): v$v";
        if ($apply && $v < F3_SCHEMA) { $done = f3_migrate_all(null); $msg .= ' → v' . f3_schema_version() . ' (' . implode(',', array_map('strval', (array)$done)) . ')'; }
        echo $msg . ($v < F3_SCHEMA && !$apply ? ' — needs update' : '') . "\n";
    } catch (Throwable $e) { $bad++; echo "branch #{$b['id']} FAILED: " . $e->getMessage() . "\n"; } finally { db_switch(null); }
}
exit($bad ? 2 : 0);
