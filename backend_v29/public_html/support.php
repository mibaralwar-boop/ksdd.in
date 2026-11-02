<?php
declare(strict_types=1);
// Break-glass entry: one-time token (2 min) issued by platform-admin for an OWNER-APPROVED, unexpired grant.
// Opens the branch read-only as a dedicated local "support" user (viewer template); the write gate blocks every POST.
require __DIR__ . '/platform/auth.php';
security_headers();
try {
    start_session();
    $g = ptx(function () {
        $t = p_token_use((string)($_GET['token'] ?? ''), 'support_entry');
        $g = prow("SELECT * FROM p_support_grants WHERE id=? AND admin_id=? AND status='approved' AND expires_at>UTC_TIMESTAMP()", (int)$t['data']['grant'], (int)$t['data']['admin']) ?? fail('Support access is not approved or has ended.', 403);
        return $g;
    });
    $b = prow("SELECT * FROM p_branches WHERE id=? AND tenant_id=? AND status='active'", $g['branch_id'], $g['tenant_id']) ?? fail('Branch not available.', 404);
    $local = prov_local_user($b, ['id' => 0, 'email' => 'support-' . (int)$g['admin_id'] . '@support.finance360.invalid', 'name' => 'Finance 360 Support (read-only)'], 'viewer');
    db_switch(tenancy_branch_target($b)); $lu = row('SELECT id,session_rev FROM dd_users WHERE id=?', $local);
    session_regenerate_id(true);
    $_SESSION = ['p_support' => (int)$g['id'], 'p_admin_support' => (int)$g['admin_id'], 'user_id' => (int)$lu['id'], 'rev' => (int)$lu['session_rev'], 'expires' => p_ts((string)$g['expires_at']), 'csrf' => bin2hex(random_bytes(24))];
    p_audit('admin', (int)$g['admin_id'], (int)$g['tenant_id'], 'support.session_start', ['grant' => (int)$g['id'], 'branch' => (int)$b['id']]);
    header('Location: index.php'); exit;
} catch (Throwable $e) { http_response_code(403); echo 'Support access unavailable: ' . htmlspecialchars($e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'error', ENT_QUOTES); }
