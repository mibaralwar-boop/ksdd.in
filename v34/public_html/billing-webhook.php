<?php
declare(strict_types=1);
// Razorpay webhook endpoint (configure in Razorpay Dashboard → Webhooks). Verifies X-Razorpay-Signature (HMAC-SHA256 of the
// raw body with the webhook secret), de-duplicates by X-Razorpay-Event-Id, then processes inside one platform transaction.
// Returns 503 while billing.mode = 'disabled' (default). Never trusts browser redirects.
require __DIR__ . '/platform/billing.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"error":"POST only"}'; exit; }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1000000) { http_response_code(413); exit; }
    $raw = (string)file_get_contents('php://input', false, null, 0, 1000001);
    [$code, $msg] = billing_webhook($raw, (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? ''), (string)($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? ''));
    http_response_code($code); echo json_encode(['status' => $msg]);
} catch (Throwable $e) { error_log('billing-webhook: ' . get_class($e)); http_response_code(500); echo '{"status":"error"}'; }
