<?php
declare(strict_types=1);
// AiSensy → Finance 360 delivery-status / opt-out webhook. URL (with secret token) is shown in Settings → Integrations.
// Authentication: the long random token in the URL (required). Optional HMAC check of X-AiSensy-Signature when a
// webhook secret is saved — AiSensy does not publish the exact signing scheme, so "signature required" mode stays off
// until it is confirmed against a real AiSensy request (see Integration setup guide).
require __DIR__ . '/lib.php';
require __DIR__ . '/f360/admin.php';
require __DIR__ . '/f360/purchase.php';
require __DIR__ . '/f360/integrations.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"error":"POST only"}'; exit; }
    if (platform_mode()) { require_once __DIR__ . '/platform/tenancy.php'; db_switch(tenancy_target_by_branch_uid((string)($_GET['b'] ?? ''))); } // V29: route by branch, then that branch's own token is checked
    f3_ready();
    $raw = (string)file_get_contents('php://input', false, null, 0, 262145);
    $h = []; foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $h[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
    [$code, $m] = transaction(fn() => f3_wa_webhook($raw, $h, (string)($_GET['k'] ?? '')));
    http_response_code($code); echo json_encode(['status' => $m]);
} catch (Throwable $e) { http_response_code(500); echo '{"status":"error"}'; try { f3_int_log('wa', 'webhook.fail', 'error', $e->getMessage()); } catch (Throwable $_) {} }
