<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';
security_headers();
legacy_page_guard();
if (!configured()) { header('Location: install.php'); exit; }
try { if (!installed()) { header('Location: install.php'); exit; } }
catch (Throwable $e) { http_response_code(503); exit('Database unavailable. Check ops/config.php in Hostinger File Manager.'); }
?><!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#143c32"><title>Legacy CRM / Store / Kitchen · Khadak Singh Da Dhaba</title><link rel="stylesheet" href="assets/style.css?v=a26"><script src="assets/app.js?v=a26" defer></script><script src="assets/staff-ui.js?v=php1" defer></script><link rel="stylesheet" href="assets/crm360.css?v=360b"><script src="assets/crm360.js?v=v6a" defer></script><script src="assets/supplier360.js?v=360b" defer></script><link rel="stylesheet" href="assets/finance360.css?v=a26"><script src="assets/finance360.js?v=a26" defer></script><script src="assets/salary-link.js?v=2" defer></script></head><body><?php if (legacy_archived()): ?><div role="note" style="position:sticky;top:0;z-index:999;background:#1B1B1B;color:#FFF6E8;padding:10px 14px;font:600 14px system-ui,sans-serif;display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between"><span>📦 Old records archive — read-only. Nothing can be changed here.</span><a href="index.php" style="color:#fff;background:#E7661A;padding:6px 12px;border-radius:8px;text-decoration:none">← Back to Finance 360</a></div><?php endif; ?><div id="app"><div class="loading">Opening Dhaba Desk…</div></div><div id="toast" role="status" aria-live="polite"></div><dialog id="modal"><div id="modal-body"></div></dialog></body></html>
