<?php
declare(strict_types=1);
// Finance 360 V29 — platform identities. Restaurant users (p_users) and platform admins (p_admins) are separate tables,
// separate sessions/cookies and separate routes: a restaurant Owner is never a Super Admin, and vice versa.
require_once __DIR__ . '/tenancy.php';

const P_ACCESS_TTL = 900;            // 15 min access tokens (mobile)
const P_REFRESH_TTL = 30 * 86400;    // 30 day rotating refresh tokens, bound to one device id

function p_email(mixed $v): string { $e = strtolower(txt($v, 190)); if (!filter_var($e, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address.'); return $e; }
function p_password_ok(string $pw): void {
    if (strlen($pw) < 10 || strlen($pw) > 200) fail('Password must be 10–200 characters.');
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) fail('Use letters and numbers in the password.');
}
function p_mobile(mixed $v, bool $required = true): ?string {
    $m = preg_replace('/[\s-]/', '', txt($v, 20)); if ($m === '' && !$required) return null;
    if (str_starts_with($m, '+91')) $m = substr($m, 3); if (!preg_match('/^[6-9]\d{9}$/', $m)) fail('Enter a valid 10-digit Indian mobile number.'); return $m;
}

/* ------------------------------ password login ------------------------------ */
function p_login(string $email, string $password): array {
    $email = strtolower(trim($email));
    p_rate('login-ip:' . p_ip_hash(), 30, 900); p_rate('login:' . $email, 10, 900);
    $u = prow('SELECT * FROM p_users WHERE email=?', $email);
    $hash = $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuSsUczQ4yQmR4nV2QHV0yI0pPtA3qQ9e'; // constant-time-ish for unknown users
    if (!password_verify($password, $hash) || !$u || $u['status'] !== 'active') { p_audit('system', null, null, 'login.fail', ['email_sha' => substr(p_sha($email), 0, 16)]); fail('Email or password is incorrect.', 401); }
    if (!$u['email_verified_at']) fail('Please verify your email first. Check your inbox or use "Resend verification".', 403);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) prun('UPDATE p_users SET password_hash=? WHERE id=?', password_hash($password, PASSWORD_DEFAULT), $u['id']);
    prun('UPDATE p_users SET last_login_at=UTC_TIMESTAMP() WHERE id=?', $u['id']);
    p_audit('user', (int)$u['id'], null, 'login', []);
    unset($u['password_hash']); return $u;
}
function p_web_user(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['p_uid']) || ($_SESSION['expires'] ?? 0) < time()) return null;
    $u = prow('SELECT id,uid,email,name,mobile,status,session_rev,email_verified_at FROM p_users WHERE id=?', (int)$_SESSION['p_uid']);
    if (!$u || $u['status'] !== 'active' || (int)$u['session_rev'] !== (int)($_SESSION['p_rev'] ?? -1)) return null;
    return $u;
}
function p_web_login(array $u): void {
    session_regenerate_id(true);
    $_SESSION = ['p_uid' => (int)$u['id'], 'p_rev' => (int)$u['session_rev'], 'expires' => time() + 28800, 'csrf' => bin2hex(random_bytes(24))];
}
function p_web_logout(): void { $_SESSION = []; if (session_status() === PHP_SESSION_ACTIVE) session_destroy(); }

/* ------------------------------ one-time tokens (verify / reset / invite) ------------------------------ */
function p_token_issue(?int $userId, string $kind, int $ttl, array $data = []): string {
    $t = p_token(); prun('INSERT INTO p_tokens(user_id,kind,token_sha,data,expires_at) VALUES(?,?,?,?,?)', $userId, $kind, p_sha($t), json_encode($data), p_utc(p_now()->modify('+' . $ttl . ' seconds'))); return $t;
}
function p_token_use(string $token, string $kind, bool $consume = true): array {
    $r = prow('SELECT * FROM p_tokens WHERE token_sha=? AND kind=? FOR UPDATE', p_sha($token), $kind);
    if (!$r || $r['used_at'] || p_ts($r['expires_at']) < p_now()->getTimestamp()) fail('This link has expired or was already used. Request a new one.', 410);
    if ($consume) prun('UPDATE p_tokens SET used_at=UTC_TIMESTAMP() WHERE id=?', $r['id']);
    $r['data'] = json_decode((string)$r['data'], true) ?: []; return $r;
}
function p_send_verification(array $u): void {
    p_rate('verify-mail:' . $u['id'], 5, 3600);
    $tok = p_token_issue((int)$u['id'], 'email_verify', 86400 * 2);
    $link = p_base_url() . '/verify.php?token=' . urlencode($tok);
    p_queue('verify', 'verify:' . $u['id'] . ':' . substr(p_sha($tok), 0, 12), 'email', $u['email'], 'Verify your email for Finance 360',
        '<p>Namaste ' . pe($u['name']) . ',</p><p>Confirm your email to finish setting up your restaurant:</p><p><a href="' . pe($link) . '">' . pe($link) . '</a></p><p>This link works for 48 hours. If you did not register, ignore this email.</p>');
}
function p_verify_email(string $token): array {
    return ptx(function () use ($token) {
        $r = p_token_use($token, 'email_verify');
        prun('UPDATE p_users SET email_verified_at=COALESCE(email_verified_at,UTC_TIMESTAMP()) WHERE id=?', $r['user_id']);
        p_audit('user', (int)$r['user_id'], null, 'email.verified');
        return prow('SELECT id,email,name FROM p_users WHERE id=?', $r['user_id']);
    });
}
/** Always answers the same way (no account enumeration). */
function p_password_forgot(string $email): void {
    $email = strtolower(trim($email)); p_rate('forgot-ip:' . p_ip_hash(), 10, 3600); p_rate('forgot:' . $email, 3, 3600);
    $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? prow("SELECT * FROM p_users WHERE email=? AND status='active'", $email) : null;
    if (!$u) return;
    $tok = p_token_issue((int)$u['id'], 'password_reset', 3600);
    $link = p_base_url() . '/reset.php?token=' . urlencode($tok);
    p_queue('reset', 'reset:' . $u['id'] . ':' . substr(p_sha($tok), 0, 12), 'email', $u['email'], 'Reset your Finance 360 password', '<p>Use this link within 1 hour to choose a new password:</p><p><a href="' . pe($link) . '">' . pe($link) . '</a></p><p>If you did not ask for this, ignore this email.</p>');
}
function p_password_reset(string $token, string $pw): void {
    p_password_ok($pw);
    ptx(function () use ($token, $pw) {
        $r = p_token_use($token, 'password_reset');
        // session_rev+1 signs out every browser; refresh/access tokens are revoked → every phone must sign in again.
        prun('UPDATE p_users SET password_hash=?,session_rev=session_rev+1,email_verified_at=COALESCE(email_verified_at,UTC_TIMESTAMP()) WHERE id=?', password_hash($pw, PASSWORD_DEFAULT), $r['user_id']);
        prun('UPDATE p_api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL', $r['user_id']);
        p_audit('user', (int)$r['user_id'], null, 'password.reset');
    });
}

/* ------------------------------ mobile API tokens ------------------------------ */
function p_api_issue(array $u, string $deviceId, ?string $deviceName, ?string $family = null, ?int $tenantId = null, ?int $branchId = null): array {
    if (!preg_match('/^[A-Za-z0-9._:-]{8,80}$/', $deviceId)) fail('A device id (8–80 letters/numbers) is required.', 400);
    $family ??= p_uid(24); $a = p_token(); $r = p_token();
    $exp = fn(int $s) => p_utc(p_now()->modify('+' . $s . ' seconds')); // one clock (the application's) for writing and checking expiry
    prun("INSERT INTO p_api_tokens(user_id,family,kind,token_sha,device_id,device_name,tenant_id,branch_id,user_rev,expires_at) VALUES(?,?,'access',?,?,?,?,?,?,?)", $u['id'], $family, p_sha($a), $deviceId, $deviceName ? mb_substr($deviceName, 0, 120) : null, $tenantId, $branchId, $u['session_rev'], $exp(P_ACCESS_TTL));
    prun("INSERT INTO p_api_tokens(user_id,family,kind,token_sha,device_id,device_name,tenant_id,branch_id,user_rev,expires_at) VALUES(?,?,'refresh',?,?,?,?,?,?,?)", $u['id'], $family, p_sha($r), $deviceId, $deviceName ? mb_substr($deviceName, 0, 120) : null, $tenantId, $branchId, $u['session_rev'], $exp(P_REFRESH_TTL));
    return ['access_token' => $a, 'refresh_token' => $r, 'token_type' => 'Bearer', 'expires_in' => P_ACCESS_TTL, 'refresh_expires_in' => P_REFRESH_TTL];
}
/** Validates a Bearer access token. Context (tenant/branch) lives on the SERVER row; the app cannot forge it. */
function p_api_auth(string $bearer): array {
    $t = prow("SELECT a.*,u.session_rev,u.status ustatus FROM p_api_tokens a JOIN p_users u ON u.id=a.user_id WHERE a.token_sha=? AND a.kind='access'", p_sha($bearer));
    if (!$t || $t['revoked_at'] || p_ts($t['expires_at']) < p_now()->getTimestamp() || $t['ustatus'] !== 'active' || (int)$t['user_rev'] !== (int)$t['session_rev']) fail('Session expired. Please sign in again.', 401);
    return $t;
}
/** Refresh rotation with reuse detection: presenting an already-used refresh token revokes the whole family (stolen token). */
function p_api_refresh(string $refresh, string $deviceId): array {
    // Reuse detection must COMMIT the family revocation before refusing (a throw inside the transaction would roll it back).
    $reused = ptx(function () use ($refresh) {
        $t = prow("SELECT * FROM p_api_tokens WHERE token_sha=? AND kind='refresh' FOR UPDATE", p_sha($refresh));
        if ($t && ($t['used_at'] || $t['revoked_at'])) { prun('UPDATE p_api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE family=? AND revoked_at IS NULL', $t['family']); p_audit('user', (int)$t['user_id'], null, 'token.reuse_detected', ['family' => $t['family']]); return true; }
        return false;
    });
    if ($reused) fail('Session expired. Please sign in again.', 401);
    return ptx(function () use ($refresh, $deviceId) {
        $t = prow("SELECT * FROM p_api_tokens WHERE token_sha=? AND kind='refresh' FOR UPDATE", p_sha($refresh));
        if (!$t || $t['used_at'] || $t['revoked_at']) fail('Session expired. Please sign in again.', 401);
        if (!hash_equals((string)$t['device_id'], $deviceId)) fail('This session belongs to another device.', 401);
        if (p_ts($t['expires_at']) < p_now()->getTimestamp()) fail('Session expired. Please sign in again.', 401);
        $u = prow("SELECT * FROM p_users WHERE id=? AND status='active'", $t['user_id']); if (!$u || (int)$u['session_rev'] !== (int)$t['user_rev']) fail('Session expired. Please sign in again.', 401);
        prun('UPDATE p_api_tokens SET used_at=UTC_TIMESTAMP() WHERE id=?', $t['id']);
        prun("UPDATE p_api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE family=? AND kind='access' AND revoked_at IS NULL", $t['family']);
        return p_api_issue($u, $deviceId, $t['device_name'], $t['family'], $t['tenant_id'] ? (int)$t['tenant_id'] : null, $t['branch_id'] ? (int)$t['branch_id'] : null);
    });
}
function p_api_logout(string $family): void { prun('UPDATE p_api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE family=? AND revoked_at IS NULL', $family); prun('UPDATE p_device_tokens SET revoked_at=UTC_TIMESTAMP() WHERE api_family=? AND revoked_at IS NULL', $family); }

/* ------------------------------ TOTP (RFC 6238, SHA-1, 30 s, 6 digits) ------------------------------ */
function p_b32_decode(string $s): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s)); $bits = ''; foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT); $o = ''; foreach (str_split($bits, 8) as $b) if (strlen($b) === 8) $o .= chr(bindec($b)); return $o; }
function p_b32_encode(string $b): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; foreach (str_split($b) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); $o = ''; foreach (str_split($bits, 5) as $x) $o .= $a[bindec(str_pad($x, 5, '0'))]; return $o; }
function p_hotp(string $key, int $counter, int $digits = 6): string {
    $h = hash_hmac('sha1', pack('J', $counter), $key, true); $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}
/** Returns the accepted time step (±1 window) or null. Callers store it to block replay of the same code. */
function p_totp_check(string $secretB32, string $code, int $lastStep, ?int $now = null): ?int {
    if (!preg_match('/^\d{6}$/', $code)) return null; $k = p_b32_decode($secretB32); $step = intdiv($now ?? p_now()->getTimestamp(), 30);
    foreach ([0, -1, 1] as $d) { $s = $step + $d; if ($s > $lastStep && hash_equals(p_hotp($k, $s), $code)) return $s; } return null;
}

/* ------------------------------ platform admins ------------------------------ */
function pa_login(string $email, string $password, string $code): array {
    $email = strtolower(trim($email)); p_rate('admin-login-ip:' . p_ip_hash(), 20, 900); p_rate('admin-login:' . $email, 6, 900);
    $a = prow("SELECT * FROM p_admins WHERE email=?", $email);
    if (!$a || $a['status'] !== 'active' || !password_verify($password, $a['password_hash'])) { p_audit('admin', $a['id'] ?? null, null, 'admin.login_fail', []); fail('Login failed.', 401); }
    $mfaRequired = (pcfg()['admin_mfa_required'] ?? true) !== false;
    if ($a['totp_enabled']) {
        $step = p_totp_check((string)p_dec($a['totp_secret_enc']), $code, (int)$a['totp_last_step']);
        if ($step === null) { p_audit('admin', (int)$a['id'], null, 'admin.mfa_fail', []); fail('Login failed.', 401); }
        prun('UPDATE p_admins SET totp_last_step=? WHERE id=?', $step, $a['id']);
    } elseif ($mfaRequired) $a['_mfa_setup_needed'] = true;
    prun('UPDATE p_admins SET last_login_at=UTC_TIMESTAMP() WHERE id=?', $a['id']); p_audit('admin', (int)$a['id'], null, 'admin.login', []);
    return $a;
}
function pa_current(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['pa_id']) || ($_SESSION['pa_exp'] ?? 0) < time()) return null;
    $a = prow("SELECT id,email,name,role,status,session_rev,totp_enabled FROM p_admins WHERE id=?", (int)$_SESSION['pa_id']);
    if (!$a || $a['status'] !== 'active' || (int)$a['session_rev'] !== (int)($_SESSION['pa_rev'] ?? -1)) return null;
    $_SESSION['pa_exp'] = min((int)$_SESSION['pa_hard'], time() + 1800); // 30 min idle, 8 h hard limit
    return $a;
}
const PA_ROLE_CAN = ['super_admin' => ['*'], 'billing_admin' => ['view', 'billing', 'trial'], 'support' => ['view', 'support']];
function pa_can(array $a, string $p): bool { $r = PA_ROLE_CAN[$a['role']] ?? []; return in_array('*', $r, true) || in_array($p, $r, true); }
function pa_need(array $a, string $p): void { if (!pa_can($a, $p)) fail('Your platform role cannot do this.', 403); }
function pa_create(string $email, string $name, string $password, string $role): int {
    p_password_ok($password); if (!isset(PA_ROLE_CAN[$role])) fail('Unknown role.');
    prun('INSERT INTO p_admins(email,name,password_hash,role) VALUES(?,?,?,?)', p_email($email), txt($name, 160), password_hash($password, PASSWORD_DEFAULT), $role); return pid();
}
