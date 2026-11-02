<?php
declare(strict_types=1);
// Finance 360 V29 scheduler (CLI only). hPanel → Cron Jobs → every 15 minutes:
//   /usr/bin/php /home/<user>/public_html/<folder>/platform-cron.php
// 1) platform jobs: email outbox, trial/renewal reminders, stale-order expiry;  2) for EVERY active branch, the existing
// Finance 360 jobs (6 AM closing reminders, payroll reminders, Drive sync/backups, WhatsApp) with that branch's own DB.
// Each branch runs in isolation: a failure in one never stops the others. Times use Asia/Kolkata inside PHP (f3_now).
// Replaces f360-cron.php in multi-restaurant mode (keep using f360-cron.php in single-restaurant mode).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/platform/tenancy.php';
require __DIR__ . '/f360/dispatch.php';
$lock = 'f360_platform_cron'; if ((int)pval('SELECT GET_LOCK(?,1)', $lock) !== 1) { echo "another run is active\n"; exit(0); }
$bad = 0; $t0 = p_utc();
try {
    $r = ['reminders' => billing_reminders(), 'mail' => p_outbox_run(100)];
    prun("UPDATE p_orders SET status='expired' WHERE status='created' AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)");
    prun("INSERT INTO p_job_runs(job,started_at,finished_at,status,message) VALUES('platform',?,UTC_TIMESTAMP(),'ok',?)", $t0, json_encode($r));
    echo 'platform ok ' . json_encode($r) . "\n";
} catch (Throwable $e) { $bad++; prun("INSERT INTO p_job_runs(job,started_at,finished_at,status,message) VALUES('platform',?,UTC_TIMESTAMP(),'failed',?)", $t0, mb_substr($e->getMessage(), 0, 300)); echo 'platform FAILED ' . $e->getMessage() . "\n"; }
foreach (prows("SELECT b.* FROM p_branches b JOIN p_tenants t ON t.id=b.tenant_id WHERE b.status='active' AND t.status='active' ORDER BY b.id") as $b) {
    $t1 = p_utc();
    try {
        db_switch(tenancy_branch_target($b)); f3_ready();
        $jobs = f3_int_cron(45); $fail = array_filter($jobs, fn($j) => $j['status'] === 'failed');
        prun("INSERT INTO p_job_runs(job,started_at,finished_at,status,message) VALUES(?,?,UTC_TIMESTAMP(),?,?)", 'branch:' . $b['id'], $t1, $fail ? 'failed' : 'ok', mb_substr(implode('; ', array_map(fn($j) => $j['job'] . '=' . $j['status'], $jobs)), 0, 300));
        if ($fail) $bad++; echo 'branch ' . $b['id'] . ' ' . ($fail ? 'had failures' : 'ok') . "\n";
    } catch (Throwable $e) { $bad++; prun("INSERT INTO p_job_runs(job,started_at,finished_at,status,message) VALUES(?,?,UTC_TIMESTAMP(),'failed',?)", 'branch:' . $b['id'], $t1, mb_substr($e->getMessage(), 0, 300)); echo 'branch ' . $b['id'] . ' FAILED ' . $e->getMessage() . "\n"; }
    finally { db_switch(null); }
}
pval('SELECT RELEASE_LOCK(?)', $lock);
exit($bad ? 2 : 0);
