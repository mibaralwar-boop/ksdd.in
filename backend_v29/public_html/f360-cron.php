<?php
declare(strict_types=1);
// Finance 360 scheduler (CLI only). Hostinger hPanel → Advanced → Cron Jobs → "Every 15 minutes":
//   /usr/bin/php /home/<user>/public_html/<folder>/f360-cron.php
// Timezone: all business rules use Asia/Kolkata inside PHP (f3_now), whatever the server/cron timezone is, so a
// 15-minute cron needs no timezone translation. The 6 AM IST reminders fire on the first run at/after 06:00 IST.
// Each job is isolated, logged in f3_job_runs (start, end, status) and protected by a MySQL lock against double runs.
// If cron is missed, the next run (or any sign-in) detects overdue work — reminders are idempotent by key.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib.php';
require __DIR__ . '/f360/admin.php';
require __DIR__ . '/f360/purchase.php';
require __DIR__ . '/f360/integrations.php';
require __DIR__ . '/f360/control.php';   // V23 fix: f3_business() used by the WhatsApp scan job (was missing in CLI → wa_scan failed every run)
require __DIR__ . '/f360/reconcile.php';
require __DIR__ . '/f360/v23.php';
try {
    f3_ready();
    $jobs = f3_int_cron(45); $bad = 0;
    foreach ($jobs as $j) { echo str_pad($j['job'], 14) . ' ' . $j['status'] . ' ' . ($j['message'] ?? '') . "\n"; if ($j['status'] === 'failed') $bad++; }
    echo 'done ' . f3_now()->format('Y-m-d H:i') . " IST\n"; exit($bad ? 2 : 0);
} catch (Throwable $e) { fwrite(STDERR, 'f360-cron: ' . $e->getMessage() . "\n"); exit(1); }
