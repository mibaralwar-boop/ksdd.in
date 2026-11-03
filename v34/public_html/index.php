<?php
declare(strict_types=1);
// Khadak Singh Da Dhaba — Finance 360 (V17) app shell. The previous CRM 360 screen is preserved at legacy.php
// (Store, Kitchen and other non-financial modules are unchanged and still reachable there for their users).
require __DIR__ . '/lib.php';
security_headers();
// V29 multi-restaurant mode: visitors see the website; signed-in users without a chosen branch pick one; the app opens
// only for a verified membership (db() re-checks it on every API call).
if (platform_mode()) {
    require_once __DIR__ . '/platform/auth.php';
    try { start_session(); $pu = p_web_user(); } catch (Throwable $e) { http_response_code(503); exit('Service unavailable.'); }
    if (empty($_SESSION['p_support'])) {
        if (!$pu) { header('Location: site.php'); exit; }
        if (empty($_SESSION['p_branch'])) { header('Location: choose.php'); exit; }
        try { db_target(); } catch (Throwable $e) { unset($_SESSION['p_branch'], $_SESSION['p_tenant']); header('Location: choose.php'); exit; }
    }
}
if (!configured()) { header('Location: install.php'); exit; }
try { if (!installed()) { header('Location: install.php'); exit; } }
catch (Throwable $e) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); exit('Database unavailable: ' . db_unavailable_message($e) . "\nYour data is not lost. Fix config.php in Hostinger File Manager, then reload."); }
$v = '29.0.0';
?><!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#1B1B1B"><meta name="robots" content="noindex">
<title>Finance 360 · Khadak Singh Da Dhaba</title><link rel="icon" href="assets/brand/favicon-32.png"><link rel="apple-touch-icon" href="assets/brand/icon-192.png"><link rel="manifest" href="manifest.webmanifest"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">
<link rel="preload" href="assets/f360/fonts/mukta-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/f360/app.css?v=<?= $v ?>">
<script src="assets/f360/core.js?v=<?= $v ?>" defer></script><script src="assets/f360/daily.js?v=<?= $v ?>" defer></script><script src="assets/f360/accounts.js?v=<?= $v ?>" defer></script><script src="assets/f360/staff.js?v=<?= $v ?>" defer></script><script src="assets/f360/purchase.js?v=<?= $v ?>" defer></script><script src="assets/f360/stock.js?v=<?= $v ?>" defer></script><script src="assets/f360/control.js?v=<?= $v ?>" defer></script><script src="assets/f360/salary.js?v=<?= $v ?>" defer></script>
<script defer src="assets/f360/integrations.js?v=<?= $v ?>"></script><script defer src="assets/f360/v31.js?v=<?= $v ?>"></script><script defer src="assets/f360/staff_import.js?v=<?= $v ?>"></script><script src="assets/f360/admin.js?v=<?= $v ?>" defer></script><script src="assets/f360/v23.js?v=<?= $v ?>" defer></script>
</head><body<?= platform_mode() ? ' data-platform="1"' : '' ?>><div class="login"><div class="box" style="text-align:center"><img src="assets/brand/logo-full.png" alt="Khadak Singh Da Dhaba" width="210"><p class="muted">Opening Finance 360…</p><noscript><div class="alert danger">Please enable JavaScript.</div></noscript></div></div></body></html>
