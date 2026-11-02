<?php
declare(strict_types=1);
// KSDD Books 360 — page shell (replaces Vyapar daybook). Data loads from books-api.php after CRM sign-in.
require __DIR__.'/lib.php';
security_headers();
legacy_page_guard();
if(!configured()){ header('Location: install.php'); exit; }
?><!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#143c32">
<title>Books · Khadak Singh Da Dhaba</title>
<link rel="stylesheet" href="assets/salary.css?v=a26"><link rel="stylesheet" href="assets/books.css?v=a26"><script src="assets/books.js?v=a26" defer></script></head>
<body><?php if (legacy_archived()): ?><div role="note" style="position:sticky;top:0;z-index:999;background:#1B1B1B;color:#FFF6E8;padding:10px 14px;font:600 14px system-ui,sans-serif;display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between"><span>📦 Old records archive — read-only. Nothing can be changed here.</span><a href="index.php" style="color:#fff;background:#E7661A;padding:6px 12px;border-radius:8px;text-decoration:none">← Back to Finance 360</a></div><?php endif; ?><div id="app"><div class="sr-loading">Opening Books…</div></div>
<div id="sr-toast" role="status" aria-live="polite"></div>
<dialog id="sr-modal"><div id="sr-modal-body"></div></dialog><div id="sr-print"></div></body></html>
