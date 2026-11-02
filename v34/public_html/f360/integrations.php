<?php
declare(strict_types=1);
// Finance 360 V20/V21 — Google Drive (documents & backups) and AiSensy WhatsApp notifications.
// Design rules:
//  * Integrations NEVER run inside a financial transaction. A cron scanner reads committed records and queues work,
//    so a Drive or WhatsApp failure can never block or roll back a closing, bill, payment or payroll posting.
//  * Credentials / tokens / phone numbers are stored AES-256-GCM encrypted (pii_key) in f3_int_secrets / f3_wa_recipients.
//  * Only the Owner can see or change integration settings (server-side check on every route).
//  * Every queue item has an idempotency key (UNIQUE) → no duplicate uploads / messages, even if cron runs twice.
//  * Nothing here is claimed as "connected" until a live test against Google / AiSensy succeeds (status shown in the UI).
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/purchase.php';

const F3_INT_EP = [
    'google_auth' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'google_token' => 'https://oauth2.googleapis.com/token',
    'google_revoke' => 'https://oauth2.googleapis.com/revoke',
    'drive' => 'https://www.googleapis.com/drive/v3',
    'drive_upload' => 'https://www.googleapis.com/upload/drive/v3',
    // AiSensy Campaign API (documented at aisensy.com/docs/api-docs/api-reference-docs). Editable in Integrations because
    // AiSensy accounts can be on different API versions/endpoints (V21 §9).
    'aisensy' => 'https://backend.aisensy.com/campaign/t1/api/v2',
];
const F3_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file'; // minimum scope: only files/folders this app creates
const F3_FOLDER_MIME = 'application/vnd.google-apps.folder';

final class F3IntError extends RuntimeException {
    public function __construct(string $m, public string $kind = 'api', public bool $retry = false) { parent::__construct($m); }
}

/* ------------------------------ common ------------------------------ */
/** V31: staging.ksdd.in uses the …_appksdd database (no 'staging' in the name) → the explicit config 'environment' => 'staging' also counts. */
function f3_int_staging(): bool { if ((cfg()['environment'] ?? '') === 'staging') return true; $n = strtolower((string)(cfg()['db_name'] ?? '')); return str_contains($n, 'staging') || str_contains($n, 'test') || str_contains($n, 'appksdd'); }
/** Endpoint overrides (mock servers) are honoured ONLY on a staging/test database — never in production. */
function f3_int_ep(string $k): string {
    $o = cfg()['int_endpoints_override'] ?? null;
    if (is_array($o) && isset($o[$k]) && f3_int_staging()) return (string)$o[$k];
    if ($k === 'aisensy') { $c = f3_wa_cfg(); if (!empty($c['endpoint']) && preg_match('#^https://[a-z0-9.-]*aisensy\.com/#i', $c['endpoint'])) return $c['endpoint']; }
    return F3_INT_EP[$k];
}
function f3_int_owner(array $u): bool { return ($u['role'] ?? '') === 'owner'; }
function f3_int_need(array $u): void { if (function_exists('platform_mode') && platform_mode() && empty(db_target()['entitlement']['modules']['integrations'] ?? true)) fail('Integrations are not included in your plan.', 403); if (!f3_int_owner($u)) fail('Only the Owner can manage integrations and their credentials.', 403); }
function f3_int_now(): string { return (string)row('SELECT UTC_TIMESTAMP() n')['n']; }

function f3_secret_get(string $n): ?string { $r = row('SELECT value_enc FROM f3_int_secrets WHERE name=?', $n); return $r ? f3_dec($r['value_enc']) : null; }
function f3_secret_has(string $n): bool { return (bool)row('SELECT 1 FROM f3_int_secrets WHERE name=?', $n); }
function f3_secret_set(string $n, ?string $v, ?array $u = null): void {
    if ($v === null || $v === '') { run('DELETE FROM f3_int_secrets WHERE name=?', $n); return; }
    $e = f3_enc($v); if (!$e) fail('Credentials cannot be stored securely: the encryption key (pii_key) is missing in config.php. Add it first (Integration setup guide, step 1).', 503);
    run('INSERT INTO f3_int_secrets(name,value_enc,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE value_enc=VALUES(value_enc),updated_by=VALUES(updated_by)', $n, $e, $u['id'] ?? null);
}
/** Remove any secret value from text before it is logged or shown. */
function f3_int_redact(string $s): string {
    foreach (['wa_api_key', 'drive_client_secret', 'drive_refresh_token', 'wa_webhook_token', 'wa_webhook_secret', 'backup_passphrase'] as $k) { $v = f3_secret_get($k); if ($v && strlen($v) >= 6) $s = str_replace($v, '***', $s); }
    $a = json_decode((string)f3_secret_get('drive_access'), true); if (!empty($a['t'])) $s = str_replace($a['t'], '***', $s);
    return preg_replace('/(ya29\.)[A-Za-z0-9._-]+/', '$1***', $s);
}
function f3_int_log(string $svc, string $event, string $level, string $msg, ?array $u = null, ?string $ref = null): void {
    run('INSERT INTO f3_int_events(service,event,level,message,ref,user_id) VALUES(?,?,?,?,?,?)', $svc, $event, $level, mb_substr(f3_int_redact($msg), 0, 500), $ref, $u['id'] ?? null);
}
function f3_http(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 30): array {
    if (!function_exists('curl_init')) throw new F3IntError('PHP cURL extension is not enabled on this server.', 'config', false);
    // V31 §A3: a staging copy must never silently talk to live Google / AiSensy / Firebase. Only local mock servers are allowed,
    // unless the owner explicitly sets 'allow_live_integrations_on_staging' => true for a supervised provider test.
    if (f3_int_staging() && empty(cfg()['allow_live_integrations_on_staging']) && !preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?/#i', $url))
        throw new F3IntError('Staging: live integration call blocked (' . (parse_url($url, PHP_URL_HOST) ?: 'unknown host') . '). Use a mock endpoint or the owner-approved live test switch.', 'config', false);
    $ch = curl_init($url); $rh = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (f3_int_staging() ? CURLPROTO_HTTP : 0),
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$rh) { $p = strpos($h, ':'); if ($p) $rh[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1)); return strlen($h); }]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch); $err = $raw === false ? curl_error($ch) : null; $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $raw = $raw === false ? '' : (string)$raw;
    return ['code' => $code, 'body' => $raw, 'json' => json_decode($raw, true), 'headers' => $rh, 'error' => $err];
}
function f3_int_backoff(int $attempts): string { $m = [5, 15, 60, 240, 720, 1440][min(max(0, $attempts - 1), 5)]; return gmdate('Y-m-d H:i:s', time() + $m * 60); }

/* ------------------------------ encryption of files / backups ------------------------------ */
// Format: "KSDDENC1" | salt(16) | iv(12) | tag(16) | AES-256-GCM ciphertext. Key = PBKDF2-SHA256(passphrase, salt, 200k).
function f3_backup_pass(): ?string { return f3_secret_get('backup_passphrase'); }
function f3_encrypt_blob(string $data, string $pass): string {
    $salt = random_bytes(16); $iv = random_bytes(12); $tag = ''; $k = hash_pbkdf2('sha256', $pass, $salt, 200000, 32, true);
    $c = openssl_encrypt($data, 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag); if ($c === false) throw new F3IntError('Encryption failed.', 'config', false);
    return 'KSDDENC1' . $salt . $iv . $tag . $c;
}
function f3_decrypt_blob(string $blob, string $pass): string {
    if (strlen($blob) < 52 || substr($blob, 0, 8) !== 'KSDDENC1') throw new F3IntError('Not a KSDD encrypted file.', 'verify', false);
    $salt = substr($blob, 8, 16); $iv = substr($blob, 24, 12); $tag = substr($blob, 36, 16); $k = hash_pbkdf2('sha256', $pass, $salt, 200000, 32, true);
    $p = openssl_decrypt(substr($blob, 52), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, $iv, $tag); if ($p === false) throw new F3IntError('Decryption failed — wrong backup passphrase or damaged file.', 'verify', false);
    return $p;
}

/* ------------------------------ scheduler health (V21 §10) ------------------------------ */
function f3_job_start(string $job): int { run('INSERT INTO f3_job_runs(job,status,started_at) VALUES(?,?,UTC_TIMESTAMP())', $job, 'running'); return (int)db()->lastInsertId(); }
function f3_job_end(int $id, string $status, string $msg = '', int $items = 0): void { run('UPDATE f3_job_runs SET status=?,message=?,items=?,finished_at=UTC_TIMESTAMP() WHERE id=?', $status, mb_substr(f3_int_redact($msg), 0, 500), $items, $id); }
/** Runs one job: never throws, records start/end/status. Skips if the same job is already running (MySQL named lock). */
function f3_job(string $job, callable $fn): array {
    $lock = 'ksdd_job_' . $job;
    if ((int)row('SELECT GET_LOCK(?,0) n', $lock)['n'] !== 1) return ['job' => $job, 'status' => 'skipped', 'message' => 'already running'];
    $id = f3_job_start($job);
    try { $r = $fn(); $items = (int)($r['items'] ?? 0); f3_job_end($id, 'ok', (string)($r['message'] ?? ''), $items); return ['job' => $job, 'status' => 'ok'] + $r; }
    catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); f3_job_end($id, 'failed', $e->getMessage()); try { f3_int_log('cron', $job, 'error', 'Job failed: ' . $e->getMessage()); } catch (Throwable $_) {} return ['job' => $job, 'status' => 'failed', 'message' => $e->getMessage()]; }
    finally { row('SELECT RELEASE_LOCK(?) n', $lock); }
}
const F3_JOBS = ['reminders' => ['Closing & salary reminders (6 AM IST)', 15], 'drive_scan' => ['Drive: find new documents', 15], 'drive_upload' => ['Drive: upload & retry queue', 15],
    'backup' => ['Encrypted database + documents backup', 1440], 'wa_scan' => ['WhatsApp: find new notifications', 15], 'wa_send' => ['WhatsApp: send & retry queue', 15], 'exceptions' => ['Financial checks → Error resolution (daily, after 6 AM IST)', 1440]];
function f3_scheduler_health(): array {
    $o = [];
    foreach (F3_JOBS as $k => [$label, $every]) {
        $last = row('SELECT * FROM f3_job_runs WHERE job=? ORDER BY id DESC LIMIT 1', $k); $ok = row("SELECT finished_at FROM f3_job_runs WHERE job=? AND status='ok' ORDER BY id DESC LIMIT 1", $k);
        $fails = (int)row("SELECT COUNT(*) n FROM f3_job_runs WHERE job=? AND status='failed' AND started_at>UTC_TIMESTAMP()-INTERVAL 1 DAY", $k)['n'];
        $lateMin = $ok ? (int)((time() - strtotime($ok['finished_at'] . ' UTC')) / 60) : null;
        $o[] = ['job' => $k, 'label' => $label, 'every_min' => $every, 'last' => $last ? ['status' => $last['status'], 'started_at' => $last['started_at'], 'finished_at' => $last['finished_at'], 'message' => $last['message'], 'items' => (int)$last['items']] : null,
            'last_ok' => $ok['finished_at'] ?? null, 'next_due' => $ok ? gmdate('Y-m-d H:i:s', strtotime($ok['finished_at'] . ' UTC') + $every * 60) : null, 'fails_24h' => $fails,
            'health' => !$ok ? 'never' : ($lateMin > $every * 3 + 15 ? 'late' : ($fails && $last['status'] === 'failed' ? 'failing' : 'ok'))];
    }
    return $o;
}
/** Called by f360-cron.php every 15 minutes (and by "Run now"). Each job is isolated: one failure does not stop the others. */
function f3_int_cron(int $budget = 45): array {
    $t0 = microtime(true); $left = fn() => max(5, $budget - (int)(microtime(true) - $t0));
    $out = [];
    $out[] = f3_job('reminders', function () { transaction(fn() => f3_sync_notifications()); return ['message' => 'target ' . f3_target_date()]; });
    $out[] = f3_job('drive_scan', fn() => f3_drive_cfg()['enabled'] ? f3_drive_scan() : ['message' => 'Drive not enabled']);
    $out[] = f3_job('backup', fn() => f3_backup_due() ? f3_backup_run(null) : ['message' => 'not due']);
    $out[] = f3_job('drive_upload', fn() => f3_drive_cfg()['enabled'] ? f3_drive_process($left()) : ['message' => 'Drive not enabled']);
    $out[] = f3_job('wa_scan', fn() => f3_wa_cfg()['enabled'] ? f3_wa_scan() : ['message' => 'WhatsApp not enabled']);
    $out[] = f3_job('wa_send', fn() => f3_wa_cfg()['enabled'] ? f3_wa_process($left()) : ['message' => 'WhatsApp not enabled']);
    // V23: daily reconciliation scan into the Error resolution centre (detection only — never edits records). Runs once per IST day after 06:00.
    $out[] = f3_job('exceptions', function () { require_once __DIR__ . '/reconcile.php'; require_once __DIR__ . '/v23.php'; $n = f3_now(); $last = setting('f3_exc_last_scan', '');
        if ((int)$n->format('G') < 6 || ($last !== '' && (new DateTimeImmutable($last . ' UTC'))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('Y-m-d') === $n->format('Y-m-d'))) return ['message' => 'not due'];
        $own = row("SELECT * FROM dd_users WHERE role='owner' AND COALESCE(active,1)=1 ORDER BY id LIMIT 1"); if (!$own) return ['message' => 'no owner user'];
        $r = transaction(fn() => f3_exc_scan($own)); return ['items' => $r['new'], 'message' => $r['found'] . ' open problem(s), ' . $r['new'] . ' new']; });
    return $out;
}

/* ================================== GOOGLE DRIVE ================================== */
const F3_DRIVE_TYPES = ['bills' => ['Purchase bill photos / PDFs', false], 'items' => ['Item photos', false], 'suppliers' => ['Supplier documents & payment proofs', false], 'expenses' => ['Expense receipts (reviewed)', false],
    'salary' => ['Approved salary sheets (restricted)', true], 'reports' => ['Monthly reports (purchase register, aging, stock, sales, expenses)', false], 'staff_docs' => ['Staff advance / penalty proofs (restricted)', true]];
function f3_drive_cfg(): array {
    $d = ['enabled' => false, 'client_id' => '', 'redirect_uri' => '', 'root' => 'KSDD Finance 360', 'types' => array_map(fn($t) => $t[0] !== 'Staff advance / penalty proofs (restricted)', F3_DRIVE_TYPES),
        'encrypt_sensitive' => true, 'backup_freq' => 'off', 'backup_hour' => 3, 'backup_files' => true, 'retention_local' => 8, 'retention_drive' => 12, 'connected_email' => null, 'connected_at' => null, 'state' => 'not_connected', 'paused' => null, 'last_test' => null];
    $c = f3_json_setting('f3_drive_cfg'); $o = array_replace($d, $c); $o['types'] = array_replace($d['types'], (array)($c['types'] ?? [])); return $o;
}
function f3_drive_cfg_set(array $patch): void { $c = f3_drive_cfg(); foreach ($patch as $k => $v) $c[$k] = $v; set_setting('f3_drive_cfg', json_encode($c, JSON_UNESCAPED_SLASHES)); }
function f3_drive_default_redirect(): string {
    $host = preg_replace('/[^a-z0-9.:-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')); $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return (https_request() ? 'https' : 'http') . '://' . $host . $dir . '/f360-oauth.php';
}
function f3_dq(string $s): string { return str_replace(['\\', "'"], ['\\\\', "\\'"], $s); }

function f3_drive_auth_start(array $u): array {
    f3_int_need($u); $c = f3_drive_cfg();
    if (!$c['client_id'] || !f3_secret_has('drive_client_secret')) fail('Save the Google OAuth Client ID and Client secret first.');
    if (!$c['redirect_uri']) fail('Redirect URI missing — save the Drive settings again.');
    $state = bin2hex(random_bytes(24));
    // SameSite=Strict session cookies are NOT sent on the redirect back from Google, so the callback is authorised by this
    // single-use, 10-minute state token bound to the Owner who started it (stored hashed + encrypted).
    f3_secret_set('drive_oauth_state', json_encode(['h' => hash('sha256', $state), 'uid' => (int)$u['id'], 'exp' => time() + 600]), $u);
    f3_int_log('drive', 'connect.start', 'info', 'Owner started Google sign-in', $u);
    return ['url' => f3_int_ep('google_auth') . '?' . http_build_query(['client_id' => $c['client_id'], 'redirect_uri' => $c['redirect_uri'], 'response_type' => 'code', 'scope' => F3_DRIVE_SCOPE,
        'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state])];
}
function f3_drive_auth_finish(string $code, string $state): array {
    $st = json_decode((string)f3_secret_get('drive_oauth_state'), true);
    if (!$st || !hash_equals((string)$st['h'], hash('sha256', $state)) || (int)$st['exp'] < time()) throw new F3IntError('This Google sign-in has expired or was already used. Start again from Settings → Integrations.', 'auth');
    f3_secret_set('drive_oauth_state', null);
    $u = row('SELECT * FROM dd_users WHERE id=?', (int)$st['uid']); if (!$u || ($u['role'] ?? '') !== 'owner') throw new F3IntError('Only the Owner can connect Google Drive.', 'auth');
    $c = f3_drive_cfg();
    $r = f3_http('POST', f3_int_ep('google_token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['code' => $code, 'client_id' => $c['client_id'],
        'client_secret' => (string)f3_secret_get('drive_client_secret'), 'redirect_uri' => $c['redirect_uri'], 'grant_type' => 'authorization_code']));
    if ($r['error']) throw new F3IntError('Could not reach Google: ' . $r['error'], 'network');
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) { $m = 'Google refused the sign-in: ' . ($r['json']['error_description'] ?? $r['json']['error'] ?? 'HTTP ' . $r['code']); f3_int_log('drive', 'connect.fail', 'error', $m, $u); throw new F3IntError($m, 'auth'); }
    if (empty($r['json']['refresh_token'])) { $m = 'Google did not return a refresh token. Open myaccount.google.com/permissions, remove this app, then connect again.'; f3_int_log('drive', 'connect.fail', 'error', $m, $u); throw new F3IntError($m, 'auth'); }
    if (!str_contains((string)($r['json']['scope'] ?? F3_DRIVE_SCOPE), 'drive.file')) { $m = 'The Drive permission was not granted. Tick the Google Drive box on the consent screen and connect again.'; f3_int_log('drive', 'connect.fail', 'error', $m, $u); throw new F3IntError($m, 'auth'); }
    f3_secret_set('drive_refresh_token', (string)$r['json']['refresh_token'], $u);
    f3_secret_set('drive_access', json_encode(['t' => $r['json']['access_token'], 'exp' => time() + (int)($r['json']['expires_in'] ?? 3600)]), $u);
    $email = null; try { $a = f3_drive_api('GET', f3_int_ep('drive') . '/about?fields=user(emailAddress)'); $email = $a['json']['user']['emailAddress'] ?? null; } catch (Throwable $e) {}
    f3_drive_cfg_set(['connected_email' => $email, 'connected_at' => gmdate('Y-m-d H:i:s'), 'state' => 'authorised', 'paused' => null, 'enabled' => true]);
    run('DELETE FROM f3_drive_folders'); // folder cache belongs to the previous Google account
    f3_int_log('drive', 'connect.ok', 'info', 'Google account authorised' . ($email ? ' (' . $email . ')' : '') . '. Run "Test connection" to verify upload.', $u);
    f3_audit($u, 'integration.drive_connect', ['email' => $email]);
    return ['email' => $email];
}
function f3_drive_disconnect(array $u): array {
    f3_int_need($u); $rt = f3_secret_get('drive_refresh_token'); $revoked = false;
    if ($rt) { try { $r = f3_http('POST', f3_int_ep('google_revoke'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $rt]), 15); $revoked = $r['code'] === 200; } catch (Throwable $e) {} }
    foreach (['drive_refresh_token', 'drive_access', 'drive_oauth_state'] as $k) f3_secret_set($k, null);
    run('DELETE FROM f3_drive_folders');
    f3_drive_cfg_set(['enabled' => false, 'state' => 'not_connected', 'connected_email' => null, 'connected_at' => null, 'paused' => null]);
    f3_int_log('drive', 'disconnect', 'info', 'Google Drive disconnected' . ($revoked ? ' and access revoked at Google' : ' (revoke at Google could not be confirmed — remove the app at myaccount.google.com/permissions)'), $u);
    f3_audit($u, 'integration.drive_disconnect', ['revoked' => $revoked]);
    return ['ok' => true, 'revoked' => $revoked, 'note' => 'Upload history and local files are kept. Files already in Drive are not deleted.'];
}
function f3_drive_token(bool $force = false): string {
    $a = json_decode((string)f3_secret_get('drive_access'), true);
    if (!$force && $a && (int)$a['exp'] > time() + 60) return (string)$a['t'];
    $rt = f3_secret_get('drive_refresh_token'); if (!$rt) throw new F3IntError('Google Drive is not connected.', 'auth');
    $c = f3_drive_cfg();
    $r = f3_http('POST', f3_int_ep('google_token'), ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['client_id' => $c['client_id'], 'client_secret' => (string)f3_secret_get('drive_client_secret'), 'refresh_token' => $rt, 'grant_type' => 'refresh_token']), 20);
    if ($r['error']) throw new F3IntError('Network error refreshing Google token: ' . $r['error'], 'network', true);
    if ($r['code'] >= 500) throw new F3IntError('Google token service busy (HTTP ' . $r['code'] . ')', 'rate', true);
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) {
        $err = (string)($r['json']['error'] ?? 'HTTP ' . $r['code']);
        f3_drive_cfg_set(['state' => 'reconnect_needed']); f3_int_log('drive', 'token.revoked', 'error', 'Google access was revoked or expired (' . $err . '). Reconnect Google Drive in Settings → Integrations.');
        throw new F3IntError('Google Drive access was revoked or expired. Reconnect in Settings → Integrations.', 'auth');
    }
    f3_secret_set('drive_access', json_encode(['t' => $r['json']['access_token'], 'exp' => time() + (int)($r['json']['expires_in'] ?? 3600)]));
    return (string)$r['json']['access_token'];
}
function f3_drive_api(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array {
    $r = null;
    for ($i = 0; $i < 2; $i++) { $r = f3_http($method, $url, array_merge(['Authorization: Bearer ' . f3_drive_token($i > 0)], $headers), $body, $timeout); if ($r['code'] === 401 && $i === 0) continue; break; }
    if ($r['error']) throw new F3IntError('Network error: ' . $r['error'], 'network', true);
    if ($r['code'] >= 200 && $r['code'] < 300) return $r;
    $reason = (string)($r['json']['error']['errors'][0]['reason'] ?? ($r['json']['error']['status'] ?? '')); $msg = (string)($r['json']['error']['message'] ?? 'HTTP ' . $r['code']);
    if ($r['code'] === 403 && in_array($reason, ['storageQuotaExceeded', 'quotaExceeded'], true)) throw new F3IntError('Google Drive storage is full. Free space or buy storage, then press Retry.', 'quota');
    if (in_array($r['code'], [408, 429, 500, 502, 503, 504], true) || ($r['code'] === 403 && stripos($reason, 'ratelimit') !== false)) throw new F3IntError("Google Drive busy ($msg)", 'rate', true);
    if ($r['code'] === 401) { f3_drive_cfg_set(['state' => 'reconnect_needed']); throw new F3IntError('Google Drive access was revoked. Reconnect in Settings → Integrations.', 'auth'); }
    if ($r['code'] === 404) throw new F3IntError("Not found in Google Drive ($msg)", 'notfound', true);
    throw new F3IntError("Google Drive error {$r['code']}: $msg", 'api');
}
/** Find-or-create a folder path under the app root. Cached; the cache entry is dropped if Drive says the folder is gone. */
function f3_drive_folder(array $parts): string {
    $parent = 'root'; $path = ''; $c = f3_drive_cfg();
    foreach (array_merge([$c['root'] ?: 'KSDD Finance 360'], $parts) as $p) {
        $p = trim(preg_replace('/[\/\\\\]+/', '-', (string)$p)); if ($p === '') continue; $path .= '/' . $p; $h = hash('sha256', $path);
        $r = row('SELECT drive_id FROM f3_drive_folders WHERE path_hash=?', $h); if ($r) { $parent = $r['drive_id']; continue; }
        $q = "mimeType='" . F3_FOLDER_MIME . "' and trashed=false and name='" . f3_dq($p) . "' and '" . f3_dq($parent) . "' in parents";
        $res = f3_drive_api('GET', f3_int_ep('drive') . '/files?' . http_build_query(['q' => $q, 'fields' => 'files(id,name)', 'spaces' => 'drive', 'pageSize' => 5]));
        $id = $res['json']['files'][0]['id'] ?? null;
        if (!$id) { $cr = f3_drive_api('POST', f3_int_ep('drive') . '/files?fields=id', ['Content-Type: application/json; charset=UTF-8'], json_encode(['name' => $p, 'mimeType' => F3_FOLDER_MIME, 'parents' => [$parent], 'appProperties' => ['ksdd_app' => 'finance360']])); $id = $cr['json']['id'] ?? null; }
        if (!$id) throw new F3IntError('Could not create Drive folder ' . $path, 'api', true);
        run('INSERT INTO f3_drive_folders(path_hash,path,drive_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE drive_id=VALUES(drive_id)', $h, mb_substr($path, 0, 500), $id); $parent = $id;
    }
    return $parent;
}
function f3_drive_fy(string $date): string { $y = (int)substr($date, 0, 4); $m = (int)substr($date, 5, 2); $s = $m >= 4 ? $y : $y - 1; return 'FY ' . $s . '-' . substr((string)($s + 1), 2); }
function f3_month_folder(string $date): string { return substr($date, 0, 7) . ' ' . (new DateTimeImmutable(substr($date, 0, 7) . '-01'))->format('F'); }
function f3_drive_path(string $type, string $date, ?string $sub = null): string {
    $names = ['bills' => 'Purchase bills', 'items' => 'Item photos', 'suppliers' => 'Suppliers', 'expenses' => 'Expense receipts', 'salary' => 'Salary (restricted)', 'reports' => 'Reports', 'staff_docs' => 'Staff documents (restricted)', 'backup' => 'Backups (encrypted)', 'test' => '_Connection tests'];
    if ($type === 'items') return 'Master data/Item photos';
    if ($type === 'test') return '_Connection tests';
    return f3_drive_fy($date) . '/' . f3_month_folder($date) . '/' . ($names[$type] ?? 'Other') . ($sub ? '/' . $sub : '');
}
function f3_safe_name(string $s, int $max = 90): string { $s = preg_replace('/[^\p{L}\p{N} ._()-]+/u', '-', $s); return mb_substr(trim(preg_replace('/-{2,}/', '-', $s), ' -.'), 0, $max) ?: 'file'; }

/** Queue one item. Idempotent on source_key; identical bytes already uploaded to the same folder are skipped as duplicates. */
function f3_drive_queue(string $key, string $kind, array $x): bool {
    if (row('SELECT 1 FROM f3_drive_items WHERE source_key=?', $key)) return false;
    $sens = !empty($x['sensitive']) ? 1 : 0;
    run('INSERT IGNORE INTO f3_drive_items(source_key,kind,ref,file_id,local_path,name,folder,mime,is_sensitive,sha256,size,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
        $key, $kind, $x['ref'] ?? null, $x['file_id'] ?? null, $x['local_path'] ?? null, mb_substr($x['name'], 0, 250), $x['folder'], $x['mime'] ?? 'application/octet-stream', $sens, $x['sha256'] ?? null, $x['size'] ?? null, $x['user_id'] ?? null);
    return true;
}
function f3_upload_path(string $rel): string { return storage_dir('uploads') . '/' . $rel; }
function f3_drive_q_file(string $type, string $key, int $fileId, string $date, string $label, ?string $sub = null, bool $sens = false): bool {
    $f = row('SELECT * FROM dd_files WHERE id=?', $fileId); if (!$f) return false;
    $ext = pathinfo((string)$f['path'], PATHINFO_EXTENSION);
    return f3_drive_queue($key, $type, ['file_id' => $fileId, 'name' => f3_safe_name($label) . '.' . $ext, 'folder' => f3_drive_path($type, $date, $sub), 'mime' => $f['mime'], 'sensitive' => $sens, 'sha256' => $f['sha256'], 'size' => (int)$f['size'], 'ref' => $key]);
}
/** Discover documents that are not yet queued (runs from cron; reads committed data only). */
function f3_drive_scan(): array {
    $c = f3_drive_cfg(); $t = $c['types']; $n = 0;
    if ($t['bills']) {
        foreach (rows("SELECT bf.file_id,bf.kind,b.id,b.bill_no,b.invoice_no,COALESCE(b.invoice_date,b.post_date,DATE(b.created_at)) d,p.name sup FROM f3_bill_files bf JOIN f3_purchase_bills b ON b.id=bf.bill_id LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id
            WHERE bf.removed=0 AND b.status IN ('review','approved','posted','reversed') AND NOT EXISTS(SELECT 1 FROM f3_drive_items di WHERE di.source_key=CONCAT('bill:',b.id,':',bf.file_id)) LIMIT 500") as $r)
            $n += (int)f3_drive_q_file('bills', 'bill:' . $r['id'] . ':' . $r['file_id'], (int)$r['file_id'], $r['d'], $r['bill_no'] . ' ' . ($r['sup'] ?? '') . ' ' . ($r['invoice_no'] ?? '') . ' ' . $r['kind'] . '-' . $r['file_id']);
        foreach (rows("SELECT l.id,l.photo_file_id fid,b.bill_no,COALESCE(b.invoice_date,b.post_date,DATE(b.created_at)) d FROM f3_purchase_lines l JOIN f3_purchase_bills b ON b.id=l.bill_id WHERE l.photo_file_id IS NOT NULL AND b.status IN ('review','approved','posted','reversed')
            AND NOT EXISTS(SELECT 1 FROM f3_drive_items di WHERE di.source_key=CONCAT('billline:',l.id,':',l.photo_file_id)) LIMIT 500") as $r)
            $n += (int)f3_drive_q_file('bills', 'billline:' . $r['id'] . ':' . $r['fid'], (int)$r['fid'], $r['d'], $r['bill_no'] . ' item photo ' . $r['id']);
    }
    if ($t['items']) foreach (rows("SELECT id,name,photo_file_id fid FROM dd_bk_items WHERE photo_file_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM f3_drive_items di WHERE di.source_key=CONCAT('item:',dd_bk_items.id,':',dd_bk_items.photo_file_id)) LIMIT 500") as $r)
        $n += (int)f3_drive_q_file('items', 'item:' . $r['id'] . ':' . $r['fid'], (int)$r['fid'], date('Y-m-d'), $r['name'] . ' ' . $r['id']);
    if ($t['suppliers']) {
        foreach (rows("SELECT id,name,photo_file_id fid,DATE(created_at) d FROM dd_bk_parties WHERE photo_file_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM f3_drive_items di WHERE di.source_key=CONCAT('party:',dd_bk_parties.id,':',dd_bk_parties.photo_file_id)) LIMIT 500") as $r)
            $n += (int)f3_drive_q_file('suppliers', 'party:' . $r['id'] . ':' . $r['fid'], (int)$r['fid'], $r['d'] ?: date('Y-m-d'), $r['name'] . ' document', f3_safe_name($r['name'], 60));
        foreach (rows("SELECT sp.txn_id,sp.pay_no,sp.file_id,t.date,p.name FROM f3_supplier_payments sp JOIN dd_bk_txns t ON t.id=sp.txn_id LEFT JOIN dd_bk_parties p ON p.id=sp.supplier_id WHERE sp.file_id IS NOT NULL
            AND NOT EXISTS(SELECT 1 FROM f3_drive_items di WHERE di.source_key=CONCAT('suppay:',sp.txn_id,':',sp.file_id)) LIMIT 500") as $r)
            $n += (int)f3_drive_q_file('suppliers', 'suppay:' . $r['txn_id'] . ':' . $r['file_id'], (int)$r['file_id'], $r['date'], $r['pay_no'] . ' payment proof', f3_safe_name((string)$r['name'], 60));
    }
    if ($t['expenses']) foreach (rows("SELECT m.txn_id,m.file_id,m.business_date d,t.category FROM f3_expense_meta m LEFT JOIN dd_bk_txns t ON t.id=m.txn_id WHERE m.file_id IS NOT NULL AND m.review_status='reviewed'
            AND NOT EXISTS(SELECT 1 FROM f3_drive_items di WHERE di.source_key=CONCAT('exp:',m.txn_id,':',m.file_id)) LIMIT 500") as $r)
        $n += (int)f3_drive_q_file('expenses', 'exp:' . $r['txn_id'] . ':' . $r['file_id'], (int)$r['file_id'], $r['d'], 'Expense ' . $r['txn_id'] . ' ' . ($r['category'] ?? ''));
    if ($t['staff_docs']) foreach (rows("SELECT s.id,s.txn_no,s.file_id,s.date,s.kind FROM f3_staff_txns s WHERE s.file_id IS NOT NULL AND s.status NOT IN ('pending','draft','rejected','reversed')
            AND NOT EXISTS(SELECT 1 FROM f3_drive_items di WHERE di.source_key=CONCAT('stafftxn:',s.id,':',s.file_id)) LIMIT 500") as $r)
        $n += (int)f3_drive_q_file('staff_docs', 'stafftxn:' . $r['id'] . ':' . $r['file_id'], (int)$r['file_id'], $r['date'], $r['txn_no'] . ' ' . $r['kind'], null, true);
    if ($t['salary']) foreach (rows("SELECT period,approved_at,status FROM f3_pay_periods WHERE status IN ('approved','locked') AND approved_at IS NOT NULL ORDER BY period") as $p) {
        $key = 'salary:' . $p['period'] . ':' . substr(hash('sha256', (string)$p['approved_at']), 0, 12);
        if (row('SELECT 1 FROM f3_drive_items WHERE source_key=?', $key . ':xlsx')) continue;
        foreach (['xlsx', 'pdf'] as $fmt) { $g = f3_export_capture(['kind' => 'salary', 'period' => $p['period'], 'format' => $fmt]); if (!$g) continue;
            $n += (int)f3_drive_queue($key . ':' . $fmt, 'salary', ['local_path' => $g['path'], 'name' => $g['name'], 'folder' => f3_drive_path('salary', $p['period'] . '-01', 'All departments'), 'mime' => $g['mime'], 'sensitive' => true, 'sha256' => $g['sha256'], 'size' => $g['size'], 'ref' => 'payroll:' . $p['period']]); }
    }
    if ($t['reports']) { // previous month's reports, generated once from the 2nd of the month (month is closed by then)
        $now = f3_now(); if ((int)$now->format('j') >= 2) { $pm = $now->modify('first day of this month')->modify('-1 month'); $from = $pm->format('Y-m-01'); $to = $pm->format('Y-m-t');
            foreach (['purchase-register', 'aging', 'stock-valuation', 'sales', 'expenses'] as $k) { $key = 'report:' . $k . ':' . $pm->format('Y-m'); if (row('SELECT 1 FROM f3_drive_items WHERE source_key=?', $key)) continue;
                $g = f3_export_capture(['kind' => $k, 'from' => $from, 'to' => $to, 'as_of' => $to, 'format' => 'xlsx']); if (!$g) continue;
                $n += (int)f3_drive_queue($key, 'report', ['local_path' => $g['path'], 'name' => $g['name'], 'folder' => f3_drive_path('reports', $from), 'mime' => $g['mime'], 'sha256' => $g['sha256'], 'size' => $g['size'], 'ref' => $key]); } }
    }
    return ['items' => $n, 'message' => $n . ' new document(s) queued'];
}
/** Generate an export as a file (local copy kept in private/exports) — used for salary/report backups. Runs as the Owner account. */
function f3_export_capture(array $q): ?array {
    $owner = row("SELECT * FROM dd_users WHERE role='owner' AND COALESCE(active,1)=1 ORDER BY id LIMIT 1"); if (!$owner) return null; unset($owner['password']);
    $GLOBALS['F3_CAPTURE'] = true;
    try { f3_export((string)$q['kind'], $q, $owner); }
    catch (F3Captured $c) {
        $dir = storage_dir('exports/' . date('Y/m')); if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = f3_safe_name(pathinfo($c->fname, PATHINFO_FILENAME), 120) . '.' . pathinfo($c->fname, PATHINFO_EXTENSION); $path = $dir . '/' . bin2hex(random_bytes(4)) . '-' . $name;
        if (file_put_contents($path, $c->bytes) === false) throw new F3IntError('private/exports is not writable', 'config');
        return ['path' => substr($path, strlen(dirname(__DIR__)) + 1), 'name' => $name, 'mime' => $c->mime, 'sha256' => hash('sha256', $c->bytes), 'size' => strlen($c->bytes)];
    }
    catch (Throwable $e) { f3_int_log('drive', 'export.fail', 'warn', 'Could not generate ' . $q['kind'] . ': ' . $e->getMessage()); return null; }
    finally { unset($GLOBALS['F3_CAPTURE']); }
    return null;
}
function f3_drive_bytes(array $it): string {
    if ($it['file_id']) { $f = row('SELECT path FROM dd_files WHERE id=?', $it['file_id']); $p = $f ? f3_upload_path($f['path']) : null; }
    else $p = dirname(__DIR__) . '/' . $it['local_path'];
    if (!$p || !is_file($p) || str_contains((string)$it['local_path'], '..')) throw new F3IntError('Local file is missing on the server (' . ($it['file_id'] ? 'upload #' . $it['file_id'] : $it['local_path']) . ').', 'missing');
    $d = file_get_contents($p); if ($d === false) throw new F3IntError('Local file could not be read.', 'missing');
    if ($it['sha256'] && $it['kind'] !== 'backup' && hash('sha256', $d) !== $it['sha256']) throw new F3IntError('Local file checksum changed since it was recorded — not uploaded. Check the file.', 'verify');
    return $d;
}
function f3_drive_upload_item(array $it): void {
    $c = f3_drive_cfg(); $data = f3_drive_bytes($it); $name = $it['name']; $mime = $it['mime']; $enc = 0;
    if ($it['is_sensitive'] && $c['encrypt_sensitive'] && $it['kind'] !== 'backup') { $pass = f3_backup_pass(); if (!$pass) throw new F3IntError('Restricted files need a backup passphrase (Integrations → Backup) before upload.', 'config');
        $data = f3_encrypt_blob($data, $pass); $name .= '.ksddenc'; $mime = 'application/octet-stream'; $enc = 1; }
    $keyH = substr(hash('sha256', $it['source_key']), 0, 40); $sha = hash('sha256', $data);
    $folder = f3_drive_folder(explode('/', $it['folder']));
    // Dedupe after a lost response: the same source key may already be in Drive.
    $ex = f3_drive_api('GET', f3_int_ep('drive') . '/files?' . http_build_query(['q' => "appProperties has { key='ksdd_key' and value='" . $keyH . "' } and trashed=false", 'fields' => 'files(id,webViewLink,md5Checksum)', 'spaces' => 'drive']));
    $f = $ex['json']['files'][0] ?? null;
    if (!$f) {
        $meta = json_encode(['name' => $name, 'parents' => [$folder], 'mimeType' => $mime, 'description' => 'KSDD Finance 360 · ' . $it['kind'] . ' · ' . $it['source_key'], 'appProperties' => ['ksdd_key' => $keyH, 'ksdd_sha' => $sha, 'ksdd_app' => 'finance360']]);
        if (strlen($data) <= 5 * 1024 * 1024) {
            $b = 'ksdd' . bin2hex(random_bytes(8));
            $body = "--$b\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n--$b\r\nContent-Type: $mime\r\n\r\n$data\r\n--$b--";
            $r = f3_drive_api('POST', f3_int_ep('drive_upload') . '/files?uploadType=multipart&fields=id,webViewLink,md5Checksum,size', ['Content-Type: multipart/related; boundary=' . $b], $body, 120);
        } else {
            $s = f3_drive_api('POST', f3_int_ep('drive_upload') . '/files?uploadType=resumable&fields=id,webViewLink,md5Checksum,size', ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . strlen($data)], $meta);
            $loc = $s['headers']['location'] ?? ''; if (!$loc || !preg_match('#^https?://#', $loc)) throw new F3IntError('Drive did not return an upload session.', 'api', true);
            $r = f3_drive_api('PUT', $loc, ['Content-Type: ' . $mime], $data, 600);
        }
        $f = $r['json'] ?? [];
        if (empty($f['id'])) throw new F3IntError('Drive accepted the upload but returned no file id.', 'api', true);
        if (!empty($f['md5Checksum']) && !hash_equals((string)$f['md5Checksum'], md5($data))) throw new F3IntError('Checksum mismatch after upload — will retry.', 'verify', true);
    }
    run("UPDATE f3_drive_items SET status='done',drive_file_id=?,web_link=?,encrypted=?,last_error=NULL,next_try_at=NULL,uploaded_at=UTC_TIMESTAMP(),verified_at=IF(?,UTC_TIMESTAMP(),NULL),size=COALESCE(size,?) WHERE id=?",
        $f['id'], mb_substr((string)($f['webViewLink'] ?? ''), 0, 300) ?: null, $enc, !empty($f['md5Checksum']) ? 1 : 0, strlen($data), $it['id']);
}
function f3_drive_process(int $budget = 40, int $max = 40): array {
    $c = f3_drive_cfg(); if (!f3_secret_has('drive_refresh_token')) return ['message' => 'not connected'];
    if ($c['paused']) return ['message' => 'paused: ' . $c['paused']];
    run("UPDATE f3_drive_items SET status='retry' WHERE status='uploading' AND updated_at<UTC_TIMESTAMP()-INTERVAL 15 MINUTE");
    $t0 = microtime(true); $ok = 0; $bad = 0;
    while ($ok + $bad < $max && microtime(true) - $t0 < $budget) {
        $it = row("SELECT * FROM f3_drive_items WHERE status IN ('queued','retry') AND (next_try_at IS NULL OR next_try_at<=UTC_TIMESTAMP()) ORDER BY id LIMIT 1"); if (!$it) break;
        if (run("UPDATE f3_drive_items SET status='uploading',attempts=attempts+1 WHERE id=? AND status IN ('queued','retry')", $it['id'])->rowCount() !== 1) continue;
        $it['attempts']++;
        try { f3_drive_upload_item($it); $ok++; }
        catch (Throwable $e) {
            $bad++; $retry = $e instanceof F3IntError ? $e->retry : true; $kind = $e instanceof F3IntError ? $e->kind : 'error';
            if ($kind === 'notfound') run('DELETE FROM f3_drive_folders'); // a cached folder was deleted in Drive
            $final = !$retry || $it['attempts'] >= 8;
            run('UPDATE f3_drive_items SET status=?,last_error=?,next_try_at=? WHERE id=?', $final ? 'failed' : 'retry', mb_substr(f3_int_redact($e->getMessage()), 0, 500), $final ? null : f3_int_backoff((int)$it['attempts']), $it['id']);
            if ($final) f3_int_log('drive', 'upload.fail', 'error', $it['name'] . ': ' . $e->getMessage(), null, $it['source_key']);
            if ($kind === 'quota') { f3_drive_cfg_set(['paused' => 'storage full']); break; }
            if ($kind === 'auth' || $kind === 'config') break;
        }
    }
    if ($ok) f3_drive_cfg_set(['state' => 'working', 'last_ok' => gmdate('Y-m-d H:i:s')]);
    return ['items' => $ok, 'message' => "$ok uploaded, $bad failed/retrying"];
}
function f3_drive_retry(array $b, array $u): array {
    f3_int_need($u); f3_drive_cfg_set(['paused' => null]);
    $n = !empty($b['id']) ? run("UPDATE f3_drive_items SET status='retry',next_try_at=NULL,attempts=0 WHERE id=? AND status IN ('failed','retry')", (int)$b['id'])->rowCount()
        : run("UPDATE f3_drive_items SET status='retry',next_try_at=NULL,attempts=0 WHERE status IN ('failed','retry')")->rowCount();
    f3_int_log('drive', 'retry', 'info', "$n item(s) queued for retry", $u); return ['ok' => true, 'queued' => $n];
}
/** Manual re-sync: confirm uploaded files still exist (not trashed) in Drive; missing ones are re-queued. */
function f3_drive_resync(array $u, int $limit = 60): array {
    f3_int_need($u); $miss = 0; $ok = 0;
    foreach (rows("SELECT * FROM f3_drive_items WHERE status='done' ORDER BY COALESCE(verified_at,'2000-01-01'),id LIMIT $limit") as $it) {
        try { $r = f3_drive_api('GET', f3_int_ep('drive') . '/files/' . rawurlencode($it['drive_file_id']) . '?fields=id,trashed,md5Checksum'); $gone = !empty($r['json']['trashed']); }
        catch (F3IntError $e) { if ($e->kind !== 'notfound') throw $e; $gone = true; }
        if ($gone) { run("UPDATE f3_drive_items SET status='retry',attempts=0,drive_file_id=NULL,web_link=NULL,next_try_at=NULL,last_error='Missing or in trash in Drive — re-uploading' WHERE id=?", $it['id']); $miss++; }
        else { run('UPDATE f3_drive_items SET verified_at=UTC_TIMESTAMP() WHERE id=?', $it['id']); $ok++; }
    }
    f3_int_log('drive', 'resync', 'info', "Re-sync: $ok verified, $miss re-queued", $u); return ['verified' => $ok, 'requeued' => $miss];
}
/** Test: authorise, read account + quota, upload a small file, download it back and compare checksums. */
function f3_drive_test(array $u): array {
    f3_int_need($u); $steps = [];
    try {
        $a = f3_drive_api('GET', f3_int_ep('drive') . '/about?fields=user(emailAddress),storageQuota(limit,usage)');
        $steps[] = ['Sign-in and token refresh', true, 'Account ' . ($a['json']['user']['emailAddress'] ?? '?')];
        $q = $a['json']['storageQuota'] ?? []; $steps[] = ['Storage', true, isset($q['limit']) ? round($q['usage'] / 1e9, 2) . ' GB used of ' . round($q['limit'] / 1e9, 2) . ' GB' : 'Unlimited / not reported'];
        $folder = f3_drive_folder(['_Connection tests']); $steps[] = ['Folder access', true, 'App folder ready'];
        $data = 'KSDD Finance 360 connection test ' . gmdate('c') . ' ' . bin2hex(random_bytes(8)); $b = 'ksdd' . bin2hex(random_bytes(6));
        $meta = json_encode(['name' => 'connection-test-' . gmdate('Ymd-His') . '.txt', 'parents' => [$folder], 'appProperties' => ['ksdd_app' => 'finance360']]);
        $r = f3_drive_api('POST', f3_int_ep('drive_upload') . '/files?uploadType=multipart&fields=id,md5Checksum', ['Content-Type: multipart/related; boundary=' . $b], "--$b\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n--$b\r\nContent-Type: text/plain\r\n\r\n$data\r\n--$b--");
        $id = $r['json']['id'] ?? ''; $steps[] = ['Upload', (bool)$id, $id ? 'Uploaded test file' : 'No file id returned'];
        $d = f3_drive_api('GET', f3_int_ep('drive') . '/files/' . rawurlencode($id) . '?alt=media'); $same = hash_equals($d['body'], $data);
        $steps[] = ['Download & checksum', $same, $same ? 'Downloaded copy matches exactly' : 'Downloaded copy differs!'];
        $ok = $id && $same;
    } catch (Throwable $e) { $steps[] = ['Error', false, $e->getMessage()]; $ok = false; }
    f3_drive_cfg_set(['last_test' => ['at' => gmdate('Y-m-d H:i:s'), 'ok' => $ok], 'state' => $ok ? 'verified' : (f3_drive_cfg()['state'] === 'not_connected' ? 'not_connected' : 'test_failed')]);
    f3_int_log('drive', 'test', $ok ? 'info' : 'error', 'Connection test ' . ($ok ? 'passed' : 'FAILED') . ': ' . implode(' · ', array_map(fn($s) => $s[0] . ($s[1] ? ' ok' : ' FAIL'), $steps)), $u);
    return ['ok' => $ok, 'steps' => array_map(fn($s) => ['step' => $s[0], 'ok' => $s[1], 'detail' => $s[2]], $steps)];
}

/* ------------------------------ encrypted backups (DB + uploaded documents) ------------------------------ */
// Strategy (V21 §8): every backup is written FIRST to private/backups on the server (copy 1, kept per retention),
// then uploaded to Drive (copy 2) when Drive is connected. The Owner should also download one copy monthly (copy 3).
function f3_backup_dir(): string { $d = storage_dir('backups'); if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function f3_backup_due(): bool {
    $c = f3_drive_cfg(); if ($c['backup_freq'] === 'off' || !f3_backup_pass()) return false;
    $now = f3_now(); if ((int)$now->format('G') < (int)$c['backup_hour']) return false;
    $last = row("SELECT MAX(started_at) t FROM f3_job_runs WHERE job='backup' AND status='ok' AND message LIKE 'Backup %'")['t'] ?? null;
    if (!$last) return true; $age = time() - strtotime($last . ' UTC');
    return $c['backup_freq'] === 'daily' ? $age > 20 * 3600 : $age > 6.5 * 86400;
}
/** SQL dump: one statement per line; each row its own line "(...)," so it can be counted and verified without a SQL parser. */
function f3_db_dump(): array {
    $pdo = db(); $out = "-- KSDD Finance 360 database backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n"; $man = [];
    // V31 single database: dump ONLY this branch's scoped tables (views → this restaurant's rows) with a portable CREATE TABLE.
    // Never list base tables: they hold every restaurant's rows and the platform's logins.
    $names = mt_enabled() ? array_keys($pdo instanceof MtPDO ? $pdo->mtRegistry() : []) : array_map(fn($t) => (string)array_values($t)[0], rows("SHOW FULL TABLES WHERE Table_type='BASE TABLE'"));
    if (mt_enabled() && !$names) fail('Backup unavailable: scoped table registry missing.', 503);
    foreach ($names as $name) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) continue;
        $create = mt_enabled() ? mt_portable_create($pdo, $name) : (string)array_values(row("SHOW CREATE TABLE `$name`"))[1];
        $out .= "-- @table $name\nDROP TABLE IF EXISTS `$name`;\n" . str_replace("\n", ' ', $create) . ";\n"; $n = 0; $buf = [];
        $st = $pdo->query("SELECT * FROM `$name`");
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            $buf[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string)$v : (mb_check_encoding((string)$v, 'UTF-8') ? $pdo->quote((string)$v) : '0x' . bin2hex((string)$v))), $r)) . ')'; $n++;
            if (count($buf) >= 200) { $out .= "INSERT INTO `$name` VALUES\n" . implode(",\n", $buf) . ";\n"; $buf = []; }
        }
        if ($buf) $out .= "INSERT INTO `$name` VALUES\n" . implode(",\n", $buf) . ";\n";
        $out .= "-- @end $name rows=$n\n"; $man[$name] = $n;
    }
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n-- @complete\n";
    return [$out, $man];
}
/** Count rows per table inside a dump (used to verify a downloaded/restored backup). */
function f3_dump_counts(string $sql): array {
    $o = []; $cur = null; $ok = false;
    foreach (explode("\n", $sql) as $line) {
        if (str_starts_with($line, '-- @table ')) { $cur = substr($line, 10); $o[$cur] = 0; }
        elseif ($cur !== null && $line !== '' && $line[0] === '(') $o[$cur]++;
        elseif ($line === '-- @complete') $ok = true;
    }
    return [$o, $ok];
}
/** Pack: KSDD backup = tar-like container {manifest.json, database.sql, files/...} gzipped, then encrypted. */
function f3_backup_pack(bool $withFiles): array {
    [$sql, $man] = f3_db_dump();
    $files = []; if ($withFiles) foreach (rows('SELECT id,path,sha256 FROM dd_files ORDER BY id') as $f) { $p = f3_upload_path($f['path']); if (is_file($p)) $files[] = ['id' => (int)$f['id'], 'path' => $f['path'], 'sha256' => $f['sha256'], 'p' => $p]; }
    $manifest = ['app' => 'KSDD Finance 360', 'format' => 1, 'created_utc' => gmdate('c'), 'schema' => (int)setting('f3_schema'), 'tables' => $man, 'sql_sha256' => hash('sha256', $sql),
        'files' => array_map(fn($f) => ['id' => $f['id'], 'path' => $f['path'], 'sha256' => $f['sha256']], $files)];
    // Simple length-prefixed container: [len][name][len][bytes]...
    $box = ''; $add = function (string $n, string $b) use (&$box) { $box .= pack('N', strlen($n)) . $n . pack('J', strlen($b)) . $b; };
    $add('manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES)); $add('database.sql', $sql);
    foreach ($files as $f) $add('files/' . $f['path'], (string)file_get_contents($f['p']));
    return [gzencode($box, 6), $manifest];
}
function f3_backup_unpack(string $gz): array {
    $box = gzdecode($gz); if ($box === false) throw new F3IntError('Backup is damaged (cannot decompress).', 'verify'); $o = []; $i = 0; $L = strlen($box);
    while ($i < $L) { $nl = unpack('N', substr($box, $i, 4))[1]; $i += 4; $n = substr($box, $i, $nl); $i += $nl; $bl = unpack('J', substr($box, $i, 8))[1]; $i += 8; $o[$n] = substr($box, $i, $bl); $i += $bl; if (strlen($o[$n]) !== $bl) throw new F3IntError('Backup is truncated.', 'verify'); }
    return $o;
}
/** Verify a backup blob end-to-end: decrypt → decompress → SQL checksum → per-table row counts → every document checksum. */
function f3_backup_verify_blob(string $blob, string $pass): array {
    $parts = f3_backup_unpack(f3_decrypt_blob($blob, $pass));
    $m = json_decode($parts['manifest.json'] ?? '', true); if (!$m) throw new F3IntError('Backup manifest missing.', 'verify');
    $sql = $parts['database.sql'] ?? ''; $issues = [];
    if (!hash_equals((string)$m['sql_sha256'], hash('sha256', $sql))) $issues[] = 'database.sql checksum mismatch';
    [$cnt, $complete] = f3_dump_counts($sql); if (!$complete) $issues[] = 'database dump incomplete';
    foreach ($m['tables'] as $t => $n) if (($cnt[$t] ?? -1) !== (int)$n) $issues[] = "table $t: expected $n rows, found " . ($cnt[$t] ?? 'none');
    $fok = 0; foreach ($m['files'] as $f) { $b = $parts['files/' . $f['path']] ?? null; if ($b === null) { $issues[] = 'missing document ' . $f['path']; continue; } if ($f['sha256'] && hash('sha256', $b) !== $f['sha256']) $issues[] = 'document checksum mismatch ' . $f['path']; else $fok++; }
    return ['ok' => !$issues, 'issues' => array_slice($issues, 0, 20), 'tables' => count($m['tables']), 'rows' => array_sum($m['tables']), 'documents' => count($m['files']), 'documents_ok' => $fok, 'created_utc' => $m['created_utc'], 'schema' => $m['schema']];
}
function f3_backup_run(?array $u, ?bool $withFiles = null): array {
    $pass = f3_backup_pass(); if (!$pass) throw new F3IntError('Set a backup passphrase in Settings → Integrations → Backup first.', 'config');
    $c = f3_drive_cfg(); $withFiles ??= (bool)$c['backup_files'];
    [$gz, $man] = f3_backup_pack($withFiles); $blob = f3_encrypt_blob($gz, $pass);
    $name = 'ksdd-backup-' . f3_now()->format('Ymd-His') . '-' . bin2hex(random_bytes(2)) . ($withFiles ? '-db+docs' : '-db') . '.ksddbk'; $path = f3_backup_dir() . '/' . $name;
    if (file_put_contents($path, $blob) === false) throw new F3IntError('private/backups is not writable', 'config');
    $v = f3_backup_verify_blob((string)file_get_contents($path), $pass); // verify the local copy immediately
    if (!$v['ok']) throw new F3IntError('Backup verification failed: ' . implode('; ', $v['issues']), 'verify');
    $rel = substr(f3_backup_dir() . '/' . $name, strlen(dirname(__DIR__)) + 1); $sha = hash('sha256', $blob); $queued = false;
    if ($c['enabled'] && f3_secret_has('drive_refresh_token')) $queued = f3_drive_queue('backup:' . $sha, 'backup', ['local_path' => $rel, 'name' => $name, 'folder' => f3_drive_path('backup', f3_now()->format('Y-m-d')), 'mime' => 'application/octet-stream', 'sensitive' => true, 'sha256' => $sha, 'size' => strlen($blob), 'ref' => $name, 'user_id' => $u['id'] ?? null]);
    run('UPDATE f3_drive_items SET encrypted=1 WHERE source_key=?', 'backup:' . $sha);
    f3_backup_prune($name);
    f3_int_log('backup', 'create', 'info', "Backup $name created and verified: {$v['tables']} tables, {$v['rows']} rows, {$v['documents']} documents, " . round(strlen($blob) / 1048576, 2) . ' MB' . ($queued ? ' · queued for Drive' : ' · local only (Drive not connected)'), $u);
    if ($u) f3_audit($u, 'backup.create', ['name' => $name, 'size' => strlen($blob)]);
    return ['items' => 1, 'message' => 'Backup ' . $name . ' (' . $v['rows'] . ' rows, ' . $v['documents'] . ' docs) verified' . ($queued ? ', queued for Drive' : ', local only'), 'name' => $name, 'verify' => $v, 'drive_queued' => $queued];
}
/** Retention: keep the newest N local backups (older local copies removed only after they are confirmed in Drive, or beyond 2×N). */
function f3_backup_prune(string $justMade = ''): void {
    // newest first by real file time (not by name: names carry the business clock); the backup just made is never removed
    $c = f3_drive_cfg(); $keep = max(3, (int)$c['retention_local']); $files = array_values(array_filter(glob(f3_backup_dir() . '/ksdd-backup-*.ksddbk') ?: [], fn($f) => basename($f) !== $justMade));
    usort($files, fn($a, $b) => (filemtime($b) <=> filemtime($a)) ?: strcmp($b, $a)); $keep = max(0, $keep - ($justMade !== '' ? 1 : 0));
    foreach (array_slice($files, $keep) as $i => $f) { $name = basename($f); $inDrive = (bool)row("SELECT 1 FROM f3_drive_items WHERE kind='backup' AND name=? AND status='done'", $name);
        if ($inDrive || $i >= $keep) { @unlink($f); f3_int_log('backup', 'prune', 'info', 'Old local backup removed by retention policy: ' . $name); } }
    // Drive retention: delete our own old backup files in Drive beyond retention_drive (they are app-created; drive.file scope).
    $keepD = max(3, (int)$c['retention_drive']);
    foreach (rows("SELECT * FROM f3_drive_items WHERE kind='backup' AND status='done' ORDER BY id DESC LIMIT 1000 OFFSET $keepD") as $it) {
        try { f3_drive_api('DELETE', f3_int_ep('drive') . '/files/' . rawurlencode($it['drive_file_id'])); run("UPDATE f3_drive_items SET status='expired',last_error='Removed from Drive by retention policy' WHERE id=?", $it['id']); }
        catch (Throwable $e) { if ($e instanceof F3IntError && $e->kind === 'notfound') run("UPDATE f3_drive_items SET status='expired' WHERE id=?", $it['id']); break; }
    }
}
function f3_backup_list(): array {
    $o = []; foreach (array_slice(array_reverse(glob(f3_backup_dir() . '/ksdd-backup-*.ksddbk') ?: []), 0, 30) as $f) { $n = basename($f); $d = row("SELECT status,web_link,verified_at FROM f3_drive_items WHERE kind='backup' AND name=? ORDER BY id DESC LIMIT 1", $n);
        $o[] = ['name' => $n, 'size' => filesize($f), 'at' => gmdate('Y-m-d H:i:s', filemtime($f)), 'drive' => $d['status'] ?? null, 'drive_verified' => $d['verified_at'] ?? null]; }
    return $o;
}
/** Verify a backup: local copy, or (from=drive) download it back from Google Drive first. */
function f3_backup_verify(array $b, array $u): array {
    f3_int_need($u); $name = basename((string)($b['name'] ?? '')); if (!preg_match('/^ksdd-backup-[\w+.-]+\.ksddbk$/', $name)) fail('Choose a backup.');
    $pass = f3_backup_pass(); if (!$pass) fail('Backup passphrase is not set.');
    if (($b['from'] ?? 'local') === 'drive') { $it = row("SELECT * FROM f3_drive_items WHERE kind='backup' AND name=? AND status='done' ORDER BY id DESC LIMIT 1", $name); if (!$it) fail('This backup is not in Google Drive yet.');
        try { $r = f3_drive_api('GET', f3_int_ep('drive') . '/files/' . rawurlencode($it['drive_file_id']) . '?alt=media', [], null, 600); } catch (F3IntError $e) { fail('Could not download from Drive: ' . $e->getMessage(), 502); }
        $blob = $r['body']; if ($it['sha256'] && hash('sha256', $blob) !== $it['sha256']) fail('The copy in Drive does not match the original checksum.', 409); }
    else { $p = f3_backup_dir() . '/' . $name; if (!is_file($p)) fail('Local copy not found (removed by retention?). Verify the Drive copy instead.'); $blob = (string)file_get_contents($p); }
    try { $v = f3_backup_verify_blob($blob, $pass); } catch (F3IntError $e) { $v = ['ok' => false, 'issues' => [$e->getMessage()]]; }
    if (($b['from'] ?? '') === 'drive' && $v['ok']) run("UPDATE f3_drive_items SET verified_at=UTC_TIMESTAMP() WHERE kind='backup' AND name=?", $name);
    f3_int_log('backup', 'verify', $v['ok'] ? 'info' : 'error', 'Verify ' . $name . ' (' . ($b['from'] ?? 'local') . '): ' . ($v['ok'] ? 'OK' : implode('; ', $v['issues'])), $u);
    return $v + ['name' => $name, 'from' => $b['from'] ?? 'local', 'restore_note' => 'Full restore is done with tools/f360-restore.php into an empty database (see Integration setup guide §6). It was tested on staging.'];
}

/* ================================== AISENSY WHATSAPP ================================== */
// event => [label, audience, params(in template order), sensitive-amounts?]
const F3_WA_EVENTS = [
    'closing_pending' => ['Daily closing pending', 'internal', ['recipient_name', 'business_date', 'business_name']],
    'salary_reminder' => ['Monthly salary sheet pending', 'internal', ['recipient_name', 'month', 'status']],
    'payroll_approval' => ['Payroll waiting for approval', 'internal', ['recipient_name', 'month', 'staff_count']],
    'staff_payment' => ['Salary payment made (sent to that staff member only)', 'staff', ['staff_name', 'month', 'amount', 'date']],
    'supplier_due' => ['Supplier payments due (weekly summary)', 'internal', ['recipient_name', 'supplier_count', 'total_due', 'as_of']],
    'supplier_payment' => ['Supplier payment confirmation (sent to that supplier only)', 'supplier', ['supplier_name', 'amount', 'date', 'reference', 'business_name']],
    'low_stock' => ['Low-stock alert (daily)', 'internal', ['recipient_name', 'item_count', 'items']],
    'purchase_approval' => ['Purchase bill waiting for approval', 'internal', ['recipient_name', 'bill_no', 'supplier', 'amount']],
    'cash_discrepancy' => ['Critical Cash Short in a closing', 'internal', ['recipient_name', 'business_date', 'amount']],
    'admin_alert' => ['Backup / integration failure (admins)', 'internal', ['recipient_name', 'alert']],
];
const F3_WA_ROLES = ['owner' => 'Owner', 'manager' => 'Manager', 'accountant' => 'Accountant', 'staff' => 'Staff member', 'supplier' => 'Supplier'];
function f3_wa_cfg(): array {
    $d = ['enabled' => false, 'endpoint' => F3_INT_EP['aisensy'], 'events' => [], 'staff_payment_authorized' => false, 'quiet_from' => 22, 'quiet_to' => 7, 'monthly_limit' => 300, 'cost_per_msg' => 0.0,
        'cash_short_alert' => 500, 'since' => null, 'last_test' => null, 'state' => 'not_configured', 'webhook_mode' => 'token'];
    $c = array_replace($d, f3_json_setting('f3_wa_cfg')); foreach (F3_WA_EVENTS as $k => $_) $c['events'][$k] = array_replace(['on' => false, 'campaign' => ''], (array)($c['events'][$k] ?? [])); return $c;
}
function f3_wa_cfg_set(array $patch): void { $c = f3_wa_cfg(); foreach ($patch as $k => $v) $c[$k] = $v; set_setting('f3_wa_cfg', json_encode($c, JSON_UNESCAPED_SLASHES)); }
function f3_phone_norm(string $p): ?string { $d = preg_replace('/[^\d+]/', '', $p); if (preg_match('/^[6-9]\d{9}$/', $d)) return '+91' . $d; if (preg_match('/^0[6-9]\d{9}$/', $d)) return '+91' . substr($d, 1); if (preg_match('/^91[6-9]\d{9}$/', $d)) return '+' . $d; if (preg_match('/^\+[1-9]\d{7,14}$/', $d)) return $d; return null; }
function f3_phone_hash(string $e164): string { $k = f3_pii_key(); if (!$k) fail('pii_key missing in config.php — phone numbers cannot be stored securely.', 503); return hash_hmac('sha256', $e164, $k); }
/** WhatsApp template variables: no newlines/tabs, no 4+ spaces, non-empty, ≤ 1024 chars (Meta rules). */
function f3_wa_param(mixed $v): string { $s = trim(preg_replace('/\s+/u', ' ', (string)$v)); return $s === '' ? '-' : mb_substr($s, 0, 1000); }
function f3_wa_recipient_save(array $b, array $u): array {
    f3_int_need($u); $id = (int)($b['id'] ?? 0); $name = f3_text($b['name'] ?? '', 80); if ($name === '') fail('Name is required.');
    $role = (string)($b['role'] ?? 'manager'); if (!isset(F3_WA_ROLES[$role])) fail('Choose a role.');
    $ev = array_values(array_filter((array)($b['events'] ?? []), fn($e) => isset(F3_WA_EVENTS[$e])));
    foreach ($ev as $e) { $aud = F3_WA_EVENTS[$e][1]; if (($aud === 'staff' && $role !== 'staff') || ($aud === 'supplier' && $role !== 'supplier') || ($aud === 'internal' && in_array($role, ['staff', 'supplier'], true))) fail('"' . F3_WA_EVENTS[$e][0] . '" cannot be sent to a ' . F3_WA_ROLES[$role] . '.'); }
    $emp = $role === 'staff' ? (int)($b['employee_id'] ?? 0) : 0; $sup = $role === 'supplier' ? (int)($b['supplier_id'] ?? 0) : 0;
    if ($role === 'staff' && !row('SELECT 1 FROM dd_employees WHERE id=?', $emp)) fail('Choose the staff member.');
    if ($role === 'supplier' && !row("SELECT 1 FROM dd_bk_parties WHERE id=? AND kind IN ('supplier','both')", $sup)) fail('Choose the supplier.');
    $consent = !empty($b['consent']); $src = f3_text($b['consent_source'] ?? '', 120);
    if ($consent && $src === '') fail('Record how consent was given (e.g. "Signed form 2 Nov", "Said yes on call").');
    $x = ['name' => $name, 'role' => $role, 'employee_id' => $emp ?: null, 'supplier_id' => $sup ?: null, 'events' => json_encode($ev), 'active' => empty($b['inactive']) ? 1 : 0];
    if (!empty($b['phone']) || !$id) { $e164 = f3_phone_norm((string)($b['phone'] ?? '')); if (!$e164) fail('Enter a valid WhatsApp number (10-digit Indian mobile, or +country code).');
        $h = f3_phone_hash($e164); $dup = row('SELECT id FROM f3_wa_recipients WHERE phone_hash=? AND id<>?', $h, $id); if ($dup) fail('This number is already a recipient.');
        $x += ['phone_enc' => f3_enc($e164), 'phone_hash' => $h, 'phone_last4' => substr($e164, -4)]; }
    $cur = $id ? row('SELECT * FROM f3_wa_recipients WHERE id=?', $id) : null; if ($id && !$cur) fail('Recipient not found.', 404);
    if ($consent && (!$cur || !$cur['consent'] || $cur['opted_out_at'])) $x += ['consent' => 1, 'consent_source' => $src, 'consent_at' => gmdate('Y-m-d H:i:s'), 'consent_by' => $u['id'], 'opted_out_at' => null, 'opt_out_source' => null];
    if (!$consent && $cur && $cur['consent']) $x += ['consent' => 0, 'opted_out_at' => gmdate('Y-m-d H:i:s'), 'opt_out_source' => 'admin'];
    if ($id) { $set = implode(',', array_map(fn($k) => "`$k`=?", array_keys($x))); run("UPDATE f3_wa_recipients SET $set WHERE id=?", ...array_merge(array_values($x), [$id])); }
    else { $x['created_by'] = $u['id']; run('INSERT INTO f3_wa_recipients(' . implode(',', array_map(fn($k) => "`$k`", array_keys($x))) . ') VALUES(' . implode(',', array_fill(0, count($x), '?')) . ')', ...array_values($x)); $id = (int)db()->lastInsertId(); }
    f3_audit($u, 'integration.wa_recipient', ['id' => $id, 'role' => $role, 'consent' => $consent, 'events' => $ev]);
    return ['ok' => true, 'id' => $id];
}
function f3_wa_recipients(): array {
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'phone' => '••••••' . $r['phone_last4'], 'role' => $r['role'], 'employee_id' => $r['employee_id'] ? (int)$r['employee_id'] : null, 'supplier_id' => $r['supplier_id'] ? (int)$r['supplier_id'] : null,
        'events' => json_decode((string)$r['events'], true) ?: [], 'consent' => (bool)$r['consent'], 'consent_source' => $r['consent_source'], 'consent_at' => $r['consent_at'], 'opted_out_at' => $r['opted_out_at'], 'opt_out_source' => $r['opt_out_source'], 'active' => (bool)$r['active']],
        rows('SELECT * FROM f3_wa_recipients ORDER BY active DESC,role,name'));
}
/** Recipients allowed to receive an event right now: active, consented, not opted-out, subscribed, linked correctly. */
function f3_wa_targets(string $event, ?int $employee = null, ?int $supplier = null): array {
    $o = []; foreach (rows('SELECT * FROM f3_wa_recipients WHERE active=1 AND consent=1 AND opted_out_at IS NULL') as $r) {
        if (!in_array($event, json_decode((string)$r['events'], true) ?: [], true)) continue;
        if ($employee !== null && (int)$r['employee_id'] !== $employee) continue; if ($supplier !== null && (int)$r['supplier_id'] !== $supplier) continue; $o[] = $r; }
    return $o;
}
function f3_wa_enqueue(string $event, string $idem, array $r, array $params, string $summary, ?string $ref = null): bool {
    $c = f3_wa_cfg(); $ev = $c['events'][$event] ?? null;
    $status = 'queued'; $err = null;
    if (!$ev || !$ev['on']) return false;
    if (!$ev['campaign']) { $status = 'skipped'; $err = 'No AiSensy API campaign mapped for this notification.'; }
    $want = count(F3_WA_EVENTS[$event][2]); if (count($params) !== $want) { $status = 'skipped'; $err = "Template needs $want variables, got " . count($params); }
    $params = array_map('f3_wa_param', $params);
    $s = run('INSERT IGNORE INTO f3_wa_messages(idem_key,event,recipient_id,ref,campaign,params_enc,summary,status,last_error) VALUES(?,?,?,?,?,?,?,?,?)', mb_substr($idem . ':' . $r['id'], 0, 160), $event, $r['id'], $ref, $ev['campaign'] ?: null, f3_enc(json_encode($params)), mb_substr($summary, 0, 200), $status, $err);
    return $s->rowCount() === 1;
}
/** Discover notifications from committed records (never called inside a posting). Only events after "since" are sent. */
function f3_wa_scan(): array {
    $c = f3_wa_cfg(); $since = $c['since'] ?: gmdate('Y-m-d H:i:s'); if (!$c['since']) f3_wa_cfg_set(['since' => $since]);
    $sinceLocal = (string)row('SELECT CONVERT_TZ(?, "+00:00", @@session.time_zone) t', $since)['t'] ?: $since; $biz = f3_business()['name']; $n = 0; $now = f3_now();
    $q = function (string $ev, string $idem, array $params, string $sum, ?string $ref = null, ?int $emp = null, ?int $sup = null) use (&$n) {
        foreach (f3_wa_targets($ev, $emp, $sup) as $r) { $p = $params; if (in_array(F3_WA_EVENTS[$ev][2][0], ['recipient_name'], true)) $p[0] = $r['name']; $n += (int)f3_wa_enqueue($ev, $idem, $r, $p, $sum, $ref); } };
    foreach (rows("SELECT * FROM f3_notifications WHERE status='open' AND kind IN ('closing-due','payroll-due','payroll-review') AND created_at>=?", $sinceLocal) as $x) {
        if ($x['kind'] === 'closing-due') { $d = substr($x['dedupe_key'], 12); $q('closing_pending', 'closing_pending:' . $d, ['', (new DateTimeImmutable($d))->format('D j M Y'), $biz], 'Closing pending for ' . $d, $x['dedupe_key']); }
        if ($x['kind'] === 'payroll-due') { $p = substr($x['dedupe_key'], 12); $per = f3_period($p); $q('salary_reminder', 'salary_reminder:' . $p, ['', (new DateTimeImmutable($p . '-01'))->format('F Y'), $per['status'] ?? 'not started'], 'Salary sheet ' . $p . ' pending', $x['dedupe_key']); }
        if ($x['kind'] === 'payroll-review') { $p = substr((string)preg_replace('/^\D+/', '', $x['dedupe_key']), 0, 7); $cnt = (int)(row('SELECT COUNT(*) n FROM f3_pay_lines WHERE period=? AND state=\'done\'', $p)['n'] ?? 0);
            $q('payroll_approval', 'payroll_approval:' . $x['dedupe_key'], ['', (new DateTimeImmutable($p . '-01'))->format('F Y'), (string)$cnt], 'Payroll ' . $p . ' awaiting approval', $x['dedupe_key']); }
    }
    foreach (rows("SELECT b.id,b.bill_no,b.grand_total,p.name FROM f3_purchase_bills b LEFT JOIN dd_bk_parties p ON p.id=b.supplier_id WHERE b.status='review' AND b.updated_at>=?", $sinceLocal) as $b)
        $q('purchase_approval', 'purchase_approval:' . $b['id'], ['', $b['bill_no'], (string)($b['name'] ?? '-'), '₹' . f3_r((int)$b['grand_total'])], 'Bill ' . $b['bill_no'] . ' awaiting approval', 'bill:' . $b['id']);
    foreach (rows("SELECT business_date,operational_diff,accounting_variance FROM f3_closings WHERE status IN ('submitted','approved') AND submitted_at>=?", $sinceLocal) as $cl) {
        $short = -min((int)($cl['operational_diff'] ?? 0), (int)($cl['accounting_variance'] ?? 0)); if ($short >= (int)round($c['cash_short_alert'] * 100) && $c['cash_short_alert'] > 0)
            $q('cash_discrepancy', 'cash_discrepancy:' . $cl['business_date'], ['', (new DateTimeImmutable($cl['business_date']))->format('D j M Y'), '₹' . f3_r($short)], 'Cash Short on ' . $cl['business_date'], 'closing:' . $cl['business_date']); }
    if ((int)$now->format('G') >= 9) {
        $low = rows('SELECT i.name FROM dd_bk_items i LEFT JOIN (SELECT item_id,SUM(qty) qty FROM f3_stock_balances GROUP BY item_id) b ON b.item_id=i.id WHERE i.active=1 AND i.is_stock=1 AND GREATEST(i.min_level,i.reorder)>0 AND COALESCE(b.qty,0)<=GREATEST(i.min_level,i.reorder) ORDER BY i.name LIMIT 200');
        if ($low) $q('low_stock', 'low_stock:' . $now->format('Y-m-d'), ['', (string)count($low), implode(', ', array_slice(array_column($low, 'name'), 0, 6)) . (count($low) > 6 ? ' +' . (count($low) - 6) . ' more' : '')], count($low) . ' item(s) low', 'stock');
        if ($now->format('N') === '1') { require_once __DIR__ . '/purchase.php'; $due = array_filter(f3_sup_balances(), fn($s) => (int)$s['balance'] > 0); $tot = array_sum(array_map(fn($s) => (int)$s['balance'], $due));
            if ($due) $q('supplier_due', 'supplier_due:' . $now->format('o-W'), ['', (string)count($due), '₹' . f3_r($tot), $now->format('j M Y')], count($due) . ' suppliers due', 'aging'); }
    }
    foreach (rows("SELECT sp.txn_id,sp.supplier_id,sp.pay_no,sp.reference,t.total amount,t.date,p.name FROM f3_supplier_payments sp JOIN dd_bk_txns t ON t.id=sp.txn_id LEFT JOIN dd_bk_parties p ON p.id=sp.supplier_id WHERE sp.kind IN ('payment','advance') AND sp.created_at>=? AND COALESCE(t.voided,0)=0", $sinceLocal) as $p)
        $q('supplier_payment', 'supplier_payment:' . $p['txn_id'], [(string)$p['name'], '₹' . f3_r((int)$p['amount']), (new DateTimeImmutable($p['date']))->format('j M Y'), $p['reference'] ?: $p['pay_no'], $biz], 'Payment ' . $p['pay_no'], 'suppay:' . $p['txn_id'], null, (int)$p['supplier_id']);
    if ($c['staff_payment_authorized']) foreach (rows('SELECT pp.id,pp.period,pp.employee_id,pp.amount,pp.date,e.name FROM f3_pay_payments pp JOIN dd_employees e ON e.id=pp.employee_id WHERE pp.voided=0 AND pp.created_at>=?', $sinceLocal) as $p)
        $q('staff_payment', 'staff_payment:' . $p['id'], [(string)$p['name'], (new DateTimeImmutable($p['period'] . '-01'))->format('F Y'), '₹' . f3_r((int)$p['amount']), (new DateTimeImmutable($p['date']))->format('j M Y')], 'Salary payment ' . $p['period'], 'paypay:' . $p['id'], (int)$p['employee_id']);
    $err = rows("SELECT id,service,message FROM f3_int_events WHERE level='error' AND at>=? AND service<>'wa' ORDER BY id DESC LIMIT 5", $sinceLocal);
    if ($err) $q('admin_alert', 'admin_alert:' . $now->format('YmdH'), ['', count($err) . ' issue(s): ' . mb_substr($err[0]['service'] . ' — ' . $err[0]['message'], 0, 300)], 'Integration alert', 'int');
    return ['items' => $n, 'message' => $n . ' message(s) queued'];
}
function f3_wa_quiet(): bool { $c = f3_wa_cfg(); $h = (int)f3_now()->format('G'); $f = (int)$c['quiet_from']; $t = (int)$c['quiet_to']; if ($f === $t) return false; return $f > $t ? ($h >= $f || $h < $t) : ($h >= $f && $h < $t); }
function f3_wa_month_count(): int { return (int)row("SELECT COUNT(*) n FROM f3_wa_messages WHERE sent_at>=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-01') AND status IN ('sent','delivered','read','failed_after_send')")['n']; }
/** Send one message via the AiSensy Campaign API. HTTP 200 = ACCEPTED by AiSensy — not proof of delivery (V21 §9). */
function f3_wa_send_row(array $m): array {
    $key = f3_secret_get('wa_api_key'); if (!$key) throw new F3IntError('AiSensy API key not set.', 'config');
    $r = row('SELECT * FROM f3_wa_recipients WHERE id=?', $m['recipient_id']); if (!$r) throw new F3IntError('Recipient deleted.', 'config');
    if (!$r['consent'] || $r['opted_out_at'] || !$r['active']) throw new F3IntError('Recipient has no consent / opted out / inactive — not sent.', 'consent');
    $phone = f3_dec($r['phone_enc']); $params = json_decode((string)f3_dec((string)$m['params_enc']), true) ?: [];
    $body = json_encode(['apiKey' => $key, 'campaignName' => $m['campaign'], 'destination' => $phone, 'userName' => $r['name'], 'source' => 'KSDD Finance 360', 'templateParams' => array_values($params)], JSON_UNESCAPED_UNICODE);
    $res = f3_http('POST', f3_int_ep('aisensy'), ['Content-Type: application/json', 'Accept: application/json'], $body, 25);
    $snippet = f3_int_redact(mb_substr(preg_replace('/\s+/', ' ', $res['body']), 0, 300));
    if ($res['error']) throw new F3IntError('Network error: ' . $res['error'], 'network', true);
    if ($res['code'] === 200 || $res['code'] === 201) { $j = $res['json'] ?? []; $pid = $j['submitted_message_id'] ?? $j['messageId'] ?? $j['message_id'] ?? $j['id'] ?? ($j['data']['id'] ?? null); return ['provider_id' => is_scalar($pid) ? (string)$pid : null, 'response' => $snippet]; }
    if ($res['code'] === 429 || $res['code'] >= 500) throw new F3IntError("AiSensy busy (HTTP {$res['code']}): $snippet", 'rate', true);
    if ($res['code'] === 401 || $res['code'] === 403) { f3_wa_cfg_set(['state' => 'auth_failed']); throw new F3IntError("AiSensy rejected the API key (HTTP {$res['code']}): $snippet", 'auth'); }
    throw new F3IntError("AiSensy rejected the message (HTTP {$res['code']}): $snippet", 'api');
}
function f3_wa_process(int $budget = 30, int $max = 30): array {
    $c = f3_wa_cfg(); if (!f3_secret_has('wa_api_key')) return ['message' => 'API key not set'];
    if (f3_wa_quiet()) return ['message' => 'quiet hours — messages wait'];
    run("UPDATE f3_wa_messages SET status='retry' WHERE status='sending' AND updated_at<UTC_TIMESTAMP()-INTERVAL 15 MINUTE");
    $t0 = microtime(true); $ok = 0; $bad = 0;
    while ($ok + $bad < $max && microtime(true) - $t0 < $budget) {
        if ($c['monthly_limit'] > 0 && f3_wa_month_count() >= (int)$c['monthly_limit']) { f3_int_log('wa', 'limit', 'warn', 'Monthly WhatsApp limit reached (' . $c['monthly_limit'] . '). Messages are held until next month or until the limit is raised.'); break; }
        $m = row("SELECT * FROM f3_wa_messages WHERE status IN ('queued','retry') AND (next_try_at IS NULL OR next_try_at<=UTC_TIMESTAMP()) ORDER BY id LIMIT 1"); if (!$m) break;
        if (run("UPDATE f3_wa_messages SET status='sending',attempts=attempts+1 WHERE id=? AND status IN ('queued','retry')", $m['id'])->rowCount() !== 1) continue; $m['attempts']++;
        try { $r = f3_wa_send_row($m); run("UPDATE f3_wa_messages SET status='sent',provider_id=?,last_error=?,sent_at=UTC_TIMESTAMP(),next_try_at=NULL WHERE id=?", $r['provider_id'], 'Accepted by AiSensy: ' . mb_substr($r['response'], 0, 200), $m['id']); $ok++; }
        catch (Throwable $e) { $bad++; $retry = $e instanceof F3IntError ? $e->retry : true; $kind = $e instanceof F3IntError ? $e->kind : 'error'; $final = !$retry || $m['attempts'] >= 6;
            run('UPDATE f3_wa_messages SET status=?,last_error=?,next_try_at=?,failed_at=IF(?,UTC_TIMESTAMP(),NULL) WHERE id=?', $final ? ($kind === 'consent' ? 'blocked' : 'failed') : 'retry', mb_substr(f3_int_redact($e->getMessage()), 0, 500), $final ? null : f3_int_backoff((int)$m['attempts']), $final ? 1 : 0, $m['id']);
            if ($final && $kind !== 'consent') f3_int_log('wa', 'send.fail', 'error', $m['event'] . ': ' . $e->getMessage(), null, $m['idem_key']);
            if ($kind === 'auth' || $kind === 'config') break; }
    }
    if ($ok) f3_wa_cfg_set(['state' => 'sending']);
    return ['items' => $ok, 'message' => "$ok accepted by AiSensy, $bad failed/retrying"];
}
function f3_wa_test(array $b, array $u): array {
    f3_int_need($u); $ev = (string)($b['event'] ?? ''); if (!isset(F3_WA_EVENTS[$ev])) fail('Choose a notification type.');
    $c = f3_wa_cfg(); $camp = trim((string)($b['campaign'] ?? $c['events'][$ev]['campaign'])); if ($camp === '') fail('Map an AiSensy API campaign name to this notification first.');
    $r = row('SELECT * FROM f3_wa_recipients WHERE id=?', (int)($b['recipient_id'] ?? 0)); if (!$r) fail('Choose a recipient.');
    if (!$r['consent'] || $r['opted_out_at']) fail('This recipient has not given consent (or opted out). Tests are only sent to consenting numbers.');
    $sample = ['recipient_name' => $r['name'], 'staff_name' => $r['name'], 'supplier_name' => $r['name'], 'business_date' => f3_now()->format('D j M Y'), 'business_name' => f3_business()['name'], 'month' => f3_now()->format('F Y'), 'status' => 'TEST',
        'staff_count' => '0', 'amount' => '₹0.00 (test)', 'date' => f3_now()->format('j M Y'), 'supplier_count' => '0', 'total_due' => '₹0.00', 'as_of' => f3_now()->format('j M Y'), 'reference' => 'TEST', 'item_count' => '0', 'items' => 'test', 'bill_no' => 'TEST',
        'supplier' => 'test', 'alert' => 'This is a test message from KSDD Finance 360'];
    $params = array_map(fn($p) => f3_wa_param($sample[$p] ?? 'test'), F3_WA_EVENTS[$ev][2]);
    run('INSERT INTO f3_wa_messages(idem_key,event,recipient_id,ref,campaign,params_enc,summary,status,attempts) VALUES(?,?,?,?,?,?,?,?,1)', 'test:' . bin2hex(random_bytes(8)), $ev, $r['id'], 'test', $camp, f3_enc(json_encode($params)), 'TEST · ' . F3_WA_EVENTS[$ev][0], 'sending');
    $id = (int)db()->lastInsertId(); $m = row('SELECT * FROM f3_wa_messages WHERE id=?', $id);
    try { $res = f3_wa_send_row($m); run("UPDATE f3_wa_messages SET status='sent',provider_id=?,last_error=?,sent_at=UTC_TIMESTAMP() WHERE id=?", $res['provider_id'], 'Accepted by AiSensy: ' . mb_substr($res['response'], 0, 200), $id);
        f3_wa_cfg_set(['last_test' => ['at' => gmdate('Y-m-d H:i:s'), 'ok' => true, 'event' => $ev], 'state' => 'accepted']); f3_int_log('wa', 'test', 'info', 'Test "' . $ev . '" accepted by AiSensy for ••' . $r['phone_last4'] . '. Confirm it arrived on the phone, then mark "Delivered" in Integrations.', $u);
        return ['ok' => true, 'id' => $id, 'accepted' => true, 'note' => 'AiSensy accepted the request. This does NOT prove WhatsApp delivery — check the phone, and the delivery status if webhooks are set up.', 'response' => $res['response']]; }
    catch (F3IntError $e) { run("UPDATE f3_wa_messages SET status='failed',last_error=?,failed_at=UTC_TIMESTAMP() WHERE id=?", mb_substr(f3_int_redact($e->getMessage()), 0, 500), $id);
        f3_wa_cfg_set(['last_test' => ['at' => gmdate('Y-m-d H:i:s'), 'ok' => false, 'event' => $ev]]); f3_int_log('wa', 'test', 'error', 'Test failed: ' . $e->getMessage(), $u); return ['ok' => false, 'id' => $id, 'error' => f3_int_redact($e->getMessage())]; }
}
/** Owner confirms a test (or any message) actually arrived on the phone — the only proof of delivery without webhooks. */
function f3_wa_confirm(array $b, array $u): array { f3_int_need($u); $id = (int)($b['id'] ?? 0); $n = run("UPDATE f3_wa_messages SET status='delivered',delivered_at=COALESCE(delivered_at,UTC_TIMESTAMP()),last_error=CONCAT_WS(' · ',last_error,'Delivery confirmed manually by owner') WHERE id=? AND status IN ('sent','delivered')", $id)->rowCount();
    if ($n && row("SELECT 1 FROM f3_wa_messages WHERE id=? AND ref='test'", $id)) f3_wa_cfg_set(['state' => 'delivery_confirmed']); f3_audit($u, 'integration.wa_confirm', ['id' => $id]); return ['ok' => (bool)$n]; }
function f3_wa_retry(array $b, array $u): array { f3_int_need($u);
    $n = !empty($b['id']) ? run("UPDATE f3_wa_messages SET status='retry',attempts=0,next_try_at=NULL WHERE id=? AND status IN ('failed','retry')", (int)$b['id'])->rowCount() : run("UPDATE f3_wa_messages SET status='retry',attempts=0,next_try_at=NULL WHERE status IN ('failed','retry')")->rowCount();
    return ['ok' => true, 'queued' => $n]; }
function f3_wa_cancel(array $b, array $u): array { f3_int_need($u); $n = run("UPDATE f3_wa_messages SET status='cancelled' WHERE id=? AND status IN ('queued','retry','failed','skipped')", (int)($b['id'] ?? 0))->rowCount(); return ['ok' => (bool)$n]; }

/** Webhook (f360-wa-webhook.php). Accepts Meta-style payloads (entry[].changes[].value.statuses / messages), as shown in
 *  AiSensy's "Direct Webhooks" docs, plus a flat {messageId,status} form. Unknown shapes are logged, not guessed. */
function f3_wa_webhook(string $raw, array $headers, string $token): array {
    $tok = f3_secret_get('wa_webhook_token'); if (!$tok || !hash_equals($tok, $token)) return [403, 'forbidden'];
    if (strlen($raw) > 262144) return [413, 'too large'];
    $sig = null; $sec = f3_secret_get('wa_webhook_secret');
    if ($sec) { $h = (string)($headers['x-aisensy-signature'] ?? ''); $calc = hash_hmac('sha256', $raw, $sec); $sig = $h !== '' && (hash_equals($calc, strtolower(preg_replace('/^sha256=/i', '', $h))) || hash_equals(base64_encode(hex2bin($calc)), $h));
        if (!$sig && f3_wa_cfg()['webhook_mode'] === 'signature') return [401, 'bad signature']; }
    $hash = hash('sha256', $raw); if (row('SELECT 1 FROM f3_wa_webhooks WHERE body_hash=?', $hash)) return [200, 'duplicate'];
    $j = json_decode($raw, true); if (!is_array($j)) return [400, 'bad json'];
    $statuses = []; $inbound = [];
    foreach ((array)($j['entry'] ?? []) as $e) foreach ((array)($e['changes'] ?? []) as $ch) { $v = $ch['value'] ?? []; foreach ((array)($v['statuses'] ?? []) as $s) $statuses[] = $s; foreach ((array)($v['messages'] ?? []) as $m) $inbound[] = $m; }
    if (isset($j['statuses'])) $statuses = array_merge($statuses, (array)$j['statuses']);
    if (isset($j['messageId']) || isset($j['message_id'])) $statuses[] = ['id' => $j['messageId'] ?? $j['message_id'], 'status' => $j['status'] ?? ($j['message_status'] ?? ''), 'recipient_id' => $j['destination'] ?? ($j['phone'] ?? null), 'errors' => $j['errors'] ?? null];
    $matched = 0; $rank = ['sent' => 1, 'delivered' => 2, 'read' => 3];
    foreach ($statuses as $s) {
        $st = strtolower((string)($s['status'] ?? '')); $pid = (string)($s['id'] ?? ''); if ($pid === '' || !in_array($st, ['sent', 'delivered', 'read', 'failed'], true)) continue;
        $m = row('SELECT * FROM f3_wa_messages WHERE provider_id=? ORDER BY id DESC LIMIT 1', $pid); if (!$m) continue; $matched++;
        if ($st === 'failed') { if (!in_array($m['status'], ['delivered', 'read'], true)) run("UPDATE f3_wa_messages SET status='failed',failed_at=UTC_TIMESTAMP(),last_error=? WHERE id=?", mb_substr('WhatsApp delivery failed: ' . json_encode($s['errors'] ?? []), 0, 500), $m['id']); continue; }
        if (($rank[$st] ?? 0) > ($rank[$m['status']] ?? 0)) run('UPDATE f3_wa_messages SET status=?,' . ($st === 'delivered' ? 'delivered_at' : ($st === 'read' ? 'read_at' : 'sent_at')) . '=UTC_TIMESTAMP() WHERE id=?', $st, $m['id']);
    }
    foreach ($inbound as $m) { $txt = strtoupper(trim((string)($m['text']['body'] ?? ''))); $from = (string)($m['from'] ?? '');
        if ($from !== '' && in_array($txt, ['STOP', 'UNSUBSCRIBE', 'BAND KARO', 'बंद'], true)) { $e = f3_phone_norm($from) ?? ('+' . ltrim($from, '+')); run("UPDATE f3_wa_recipients SET opted_out_at=UTC_TIMESTAMP(),opt_out_source='whatsapp STOP',consent=0 WHERE phone_hash=?", f3_phone_hash($e));
            run("UPDATE f3_wa_messages SET status='cancelled',last_error='Recipient opted out' WHERE status IN ('queued','retry') AND recipient_id IN (SELECT id FROM f3_wa_recipients WHERE phone_hash=?)", f3_phone_hash($e)); } }
    run('INSERT IGNORE INTO f3_wa_webhooks(body_hash,sig_ok,statuses,matched,inbound,note) VALUES(?,?,?,?,?,?)', $hash, $sig === null ? null : (int)$sig, count($statuses), $matched, count($inbound), $statuses || $inbound ? null : 'Unrecognised payload shape (logged only)');
    return [200, 'ok'];
}

/* ================================== admin API ================================== */
function f3_int_state(array $u): array {
    f3_int_need($u); $d = f3_drive_cfg(); $w = f3_wa_cfg();
    $base = rtrim(dirname(f3_drive_default_redirect()), '/');
    $cnt = fn(string $t) => array_column(rows("SELECT status,COUNT(*) n FROM $t GROUP BY status"), 'n', 'status');
    return [
        'pii_key' => f3_pii_key() !== null, 'curl' => function_exists('curl_init'), 'staging' => f3_int_staging(),
        'drive' => array_diff_key($d, ['client_id' => 0]) + ['client_id' => $d['client_id'], 'has_secret' => f3_secret_has('drive_client_secret'), 'connected' => f3_secret_has('drive_refresh_token'), 'scope' => F3_DRIVE_SCOPE,
            'suggested_redirect' => f3_drive_default_redirect(), 'types_meta' => array_map(fn($t) => ['label' => $t[0], 'restricted' => $t[1]], F3_DRIVE_TYPES), 'counts' => $cnt('f3_drive_items'),
            'items' => rows('SELECT id,kind,name,folder,status,attempts,last_error,web_link,is_sensitive,encrypted,size,sha256,created_at,uploaded_at,verified_at,next_try_at FROM f3_drive_items ORDER BY id DESC LIMIT 80')],
        'backup' => ['has_pass' => f3_secret_has('backup_passphrase'), 'list' => f3_backup_list()],
        'wa' => $w + ['has_key' => f3_secret_has('wa_api_key'), 'has_webhook_secret' => f3_secret_has('wa_webhook_secret'), 'webhook_url' => f3_secret_has('wa_webhook_token') ? $base . '/f360-wa-webhook.php?k=' . f3_secret_get('wa_webhook_token') : null,
            'events_meta' => array_map(fn($e) => ['label' => $e[0], 'audience' => $e[1], 'params' => $e[2]], F3_WA_EVENTS), 'roles' => F3_WA_ROLES, 'recipients' => f3_wa_recipients(), 'counts' => $cnt('f3_wa_messages'), 'month_count' => f3_wa_month_count(),
            'messages' => array_map(fn($m) => $m + ['recipient' => ($r = row('SELECT name,phone_last4 FROM f3_wa_recipients WHERE id=?', $m['recipient_id'])) ? $r['name'] . ' ••' . $r['phone_last4'] : '?'],
                rows('SELECT id,event,recipient_id,campaign,summary,status,attempts,last_error,created_at,sent_at,delivered_at,read_at,failed_at,next_try_at,ref FROM f3_wa_messages ORDER BY id DESC LIMIT 80')),
            'webhooks' => rows('SELECT received_at,sig_ok,statuses,matched,inbound,note FROM f3_wa_webhooks ORDER BY id DESC LIMIT 10'),
            'staff' => rows("SELECT id,name FROM dd_employees WHERE COALESCE(left_on,'9999-12-31')>=CURDATE() ORDER BY name LIMIT 500"), 'suppliers' => rows("SELECT id,name FROM dd_bk_parties WHERE kind IN ('supplier','both') AND merged_into IS NULL ORDER BY name LIMIT 500")],
        'scheduler' => f3_scheduler_health(), 'cron_hint' => '*/15 * * * * /usr/bin/php ' . dirname(__DIR__) . '/f360-cron.php',
        'events' => rows('SELECT e.at,e.service,e.event,e.level,e.message,u.name user FROM f3_int_events e LEFT JOIN dd_users u ON u.id=e.user_id ORDER BY e.id DESC LIMIT 60'),
    ];
}
function f3_int_post(string $route, array $b, array $u): array {
    f3_int_need($u);
    $tx = fn(callable $f) => transaction($f);
    switch ($route) {
        case 'int-drive-save': return $tx(function () use ($b, $u) {
            $cid = trim((string)($b['client_id'] ?? '')); if ($cid !== '' && !preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $cid)) fail('The Client ID should end with .apps.googleusercontent.com');
            $redir = trim((string)($b['redirect_uri'] ?? '')) ?: f3_drive_default_redirect(); if (!preg_match('#^https://#', $redir) && !f3_int_staging()) fail('The redirect URI must start with https://');
            if (!empty($b['client_secret'])) f3_secret_set('drive_client_secret', trim((string)$b['client_secret']), $u);
            $types = []; foreach (F3_DRIVE_TYPES as $k => $_) $types[$k] = !empty($b['types'][$k]);
            $freq = in_array($b['backup_freq'] ?? 'off', ['off', 'daily', 'weekly'], true) ? ($b['backup_freq'] ?? 'off') : 'off';
            if ($freq !== 'off' && !f3_secret_has('backup_passphrase') && empty($b['backup_passphrase'])) fail('Scheduled backups need a backup passphrase.');
            if (!empty($b['backup_passphrase'])) { $p = (string)$b['backup_passphrase']; if (mb_strlen($p) < 12) fail('Backup passphrase must be at least 12 characters. Write it down and keep it safe — backups cannot be opened without it.'); f3_secret_set('backup_passphrase', $p, $u); f3_int_log('backup', 'passphrase', 'info', 'Backup passphrase set/changed (older backups still need the old passphrase)', $u); }
            f3_drive_cfg_set(['client_id' => $cid, 'redirect_uri' => $redir, 'root' => f3_safe_name((string)($b['root'] ?? 'KSDD Finance 360'), 60), 'types' => $types, 'encrypt_sensitive' => !empty($b['encrypt_sensitive']), 'backup_freq' => $freq,
                'backup_hour' => max(0, min(23, (int)($b['backup_hour'] ?? 3))), 'backup_files' => !empty($b['backup_files']), 'retention_local' => max(3, min(60, (int)($b['retention_local'] ?? 8))), 'retention_drive' => max(3, min(120, (int)($b['retention_drive'] ?? 12))),
                'enabled' => f3_secret_has('drive_refresh_token') && empty($b['pause'])]);
            f3_audit($u, 'integration.drive_settings', ['types' => $types, 'backup' => $freq]); return ['ok' => true]; });
        case 'int-drive-connect': return $tx(fn() => f3_drive_auth_start($u));
        case 'int-drive-disconnect': return f3_drive_disconnect($u);
        case 'int-drive-test': return f3_drive_test($u);
        case 'int-drive-retry': return f3_drive_retry($b, $u);
        case 'int-drive-resync': return f3_drive_resync($u);
        case 'int-run': return ['jobs' => f3_int_cron(25)];
        case 'int-backup-now': $r = f3_job('backup', fn() => f3_backup_run($u)); if ($r['status'] !== 'ok') fail('Backup failed: ' . ($r['message'] ?? ''), 500); return $r;
        case 'int-backup-verify': return f3_backup_verify($b, $u);
        case 'int-wa-save': return $tx(function () use ($b, $u) {
            if (!empty($b['api_key'])) { $k = trim((string)$b['api_key']); if (strlen($k) < 20) fail('That does not look like an AiSensy API key (Manage → API Key → Copy).'); f3_secret_set('wa_api_key', $k, $u); f3_wa_cfg_set(['state' => 'key_saved']); }
            if (!empty($b['clear_key'])) { f3_secret_set('wa_api_key', null); f3_wa_cfg_set(['state' => 'not_configured', 'enabled' => false]); }
            if (!empty($b['webhook_secret'])) f3_secret_set('wa_webhook_secret', trim((string)$b['webhook_secret']), $u);
            if (!f3_secret_has('wa_webhook_token') || !empty($b['regen_webhook'])) f3_secret_set('wa_webhook_token', bin2hex(random_bytes(20)), $u);
            $ep = trim((string)($b['endpoint'] ?? F3_INT_EP['aisensy'])); if (!preg_match('#^https://[a-z0-9.-]*aisensy\.com/[\w/.-]+$#i', $ep)) fail('The API endpoint must be an https://…aisensy.com/… address from your AiSensy account.');
            $ev = []; foreach (F3_WA_EVENTS as $k => $_) { $camp = trim((string)($b['events'][$k]['campaign'] ?? '')); if ($camp !== '' && !preg_match('/^[\w .-]{1,120}$/u', $camp)) fail('Campaign names may contain letters, numbers, spaces, - _ . only.'); $ev[$k] = ['on' => !empty($b['events'][$k]['on']), 'campaign' => $camp]; }
            $en = !empty($b['enabled']); if ($en && !f3_secret_has('wa_api_key')) fail('Save the AiSensy API key before switching notifications on.');
            $prev = f3_wa_cfg();
            f3_wa_cfg_set(['enabled' => $en, 'endpoint' => $ep, 'events' => $ev, 'staff_payment_authorized' => !empty($b['staff_payment_authorized']), 'quiet_from' => max(0, min(23, (int)($b['quiet_from'] ?? 22))), 'quiet_to' => max(0, min(23, (int)($b['quiet_to'] ?? 7))),
                'monthly_limit' => max(0, min(100000, (int)($b['monthly_limit'] ?? 300))), 'cost_per_msg' => max(0, min(100, (float)($b['cost_per_msg'] ?? 0))), 'cash_short_alert' => max(0, min(1000000, (int)($b['cash_short_alert'] ?? 500))),
                'webhook_mode' => ($b['webhook_mode'] ?? 'token') === 'signature' ? 'signature' : 'token', 'since' => $en && (!$prev['enabled'] || !$prev['since']) ? gmdate('Y-m-d H:i:s') : $prev['since']]);
            f3_audit($u, 'integration.wa_settings', ['enabled' => $en, 'events' => array_keys(array_filter($ev, fn($e) => $e['on']))]); return ['ok' => true]; });
        case 'int-wa-recipient': return $tx(fn() => f3_wa_recipient_save($b, $u));
        case 'int-wa-test': return f3_wa_test($b, $u);
        case 'int-wa-confirm': return $tx(fn() => f3_wa_confirm($b, $u));
        case 'int-wa-retry': return $tx(fn() => f3_wa_retry($b, $u));
        case 'int-wa-cancel': return $tx(fn() => f3_wa_cancel($b, $u));
    }
    fail('Not found', 404);
}
