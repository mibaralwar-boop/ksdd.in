<?php
declare(strict_types=1);
// Tenant logo — members of that restaurant (or a support session for it) only. Never a public URL.
require __DIR__ . '/platform/auth.php';
security_headers();
try {
    start_session(); $uid = (string)($_GET['t'] ?? ''); if (!preg_match('/^[a-z0-9]{12}$/', $uid)) fail('Not found', 404);
    $t = prow('SELECT id,logo_path FROM p_tenants WHERE uid=?', $uid); if (!$t || !$t['logo_path']) fail('Not found', 404);
    $me = p_web_user(); $ok = $me && tenancy_member_role((int)$me['id'], (int)$t['id']) !== null;
    if (!$ok && !empty($_SESSION['p_support'])) $ok = (int)pval("SELECT tenant_id FROM p_support_grants WHERE id=? AND status='approved' AND expires_at>UTC_TIMESTAMP()", (int)$_SESSION['p_support']) === (int)$t['id'];
    if (!$ok) fail('Not found', 404);
    $f = realpath(p_private('') . '/' . $t['logo_path']); $base = realpath(p_private(''));
    if (!$f || !$base || !str_starts_with($f, $base . DIRECTORY_SEPARATOR)) fail('Not found', 404);
    header('Content-Type: image/png'); header('Cache-Control: private, max-age=3600'); readfile($f);
} catch (Throwable $e) { http_response_code($e->getCode() === 404 ? 404 : 400); }
