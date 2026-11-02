<?php
declare(strict_types=1);
// Finance 360 V31 — Firebase Cloud Messaging (HTTP v1) for the Flutter app, tenant-safe.
//  • Fan-out (per branch, inside that branch's scope): new open f3_notifications → devices bound to THIS tenant+branch whose
//    user still has an ACTIVE membership of the branch AND the notification's permission (checked with the same f3_can()).
//  • Sender: one outbox row per device (dedupe), re-checked at send time (device not revoked, still bound to the same
//    branch, membership still active). Service-account JSON lives OUTSIDE the web root (config platform.fcm.service_account_file).
//  • Messages carry only a title, a short body and an in-app route — never salary amounts or bank details.
//  • Staging: only local mock endpoints are called (same rule as Drive / AiSensy).
require_once __DIR__ . '/core.php';

function push_cfg(): array { return (array)(pcfg()['fcm'] ?? []); }
function push_endpoints(): array {
    $c = push_cfg(); $staging = (cfg()['environment'] ?? '') === 'staging' || preg_match('/(staging|test|appksdd)/i', (string)(cfg()['db_name'] ?? ''));
    $o = $staging ? (array)($c['endpoints_override'] ?? []) : [];
    return ['token' => (string)($o['token'] ?? 'https://oauth2.googleapis.com/token'), 'send' => (string)($o['send'] ?? 'https://fcm.googleapis.com/v1/projects/{project}/messages:send'), 'staging' => (bool)$staging];
}
/** Queue pushes for one branch. Call with the branch already selected (db_switch). Returns number queued. */
function push_fanout(array $branch): int {
    $tid = (int)$branch['tenant_id']; $bid = (int)$branch['id'];
    $mark = (int)(pval('SELECT last_notification_id FROM p_push_marks WHERE branch_id=?', $bid) ?? -1);
    if ($mark < 0) { $max = (int)(row('SELECT COALESCE(MAX(id),0) m FROM f3_notifications')['m'] ?? 0); prun('INSERT IGNORE INTO p_push_marks(branch_id,last_notification_id) VALUES(?,?)', $bid, $max); return 0; } // first run: no backlog spam
    $notes = rows("SELECT id,kind,title,perm,ref FROM f3_notifications WHERE id>? AND status='open' ORDER BY id LIMIT 200", $mark); if (!$notes) return 0;
    $devs = prows("SELECT d.id,d.user_id,bm.local_user_id FROM p_device_tokens d JOIN p_branch_members bm ON bm.user_id=d.user_id AND bm.tenant_id=d.tenant_id AND bm.branch_id=d.branch_id AND bm.status='active'
                   JOIN p_tenant_members tm ON tm.user_id=d.user_id AND tm.tenant_id=d.tenant_id AND tm.status='active' JOIN p_users u ON u.id=d.user_id AND u.status='active'
                   WHERE d.tenant_id=? AND d.branch_id=? AND d.revoked_at IS NULL", $tid, $bid);
    $n = 0; $users = [];
    foreach ($devs as $d) { $lu = $users[(int)$d['local_user_id']] ??= row('SELECT * FROM dd_users WHERE id=?', (int)$d['local_user_id']); if (!$lu) continue;
        foreach ($notes as $no) { if (!f3_can($lu, (string)$no['perm'])) continue;
            $body = json_encode(['title' => mb_substr((string)$no['title'], 0, 120), 'body' => (string)($branch['name'] ?? ''), 'route' => (string)$no['kind'], 'tenant_id' => $tid, 'branch_id' => $bid], JSON_UNESCAPED_UNICODE);
            if (p_queue('push', 'push:' . $no['id'] . ':' . $d['id'], 'fcm', 'device:' . $d['id'], mb_substr((string)$no['title'], 0, 120), $body, $tid)) $n++; } }
    prun('UPDATE p_push_marks SET last_notification_id=? WHERE branch_id=?', (int)end($notes)['id'], $bid);
    return $n;
}
function push_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
/** OAuth2 access token for the service account (JWT bearer grant). */
function push_access_token(): array {
    static $tok = null; if ($tok && $tok['exp'] > time() + 60) return $tok;
    $c = push_cfg(); $file = (string)($c['service_account_file'] ?? '');
    if ($file === '' || !is_file($file)) throw new RuntimeException('FCM not configured (platform.fcm.service_account_file).');
    $sa = json_decode((string)file_get_contents($file), true); if (empty($sa['client_email']) || empty($sa['private_key'])) throw new RuntimeException('FCM service account file is invalid.');
    $ep = push_endpoints(); $now = time();
    $jwt = push_b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . push_b64u(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => $ep['token'], 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign($jwt, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('FCM: could not sign the token request.');
    $r = push_http('POST', $ep['token'], ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt . '.' . push_b64u($sig)]));
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) throw new RuntimeException('FCM token request failed (HTTP ' . $r['code'] . ').');
    return $tok = ['t' => (string)$r['json']['access_token'], 'exp' => $now + (int)($r['json']['expires_in'] ?? 3000), 'project' => (string)($c['project_id'] ?? $sa['project_id'] ?? '')];
}
function push_http(string $m, string $url, array $h, string $body): array {
    $ep = push_endpoints();
    if ($ep['staging'] && !preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?/#', $url) && empty(cfg()['allow_live_integrations_on_staging'])) throw new RuntimeException('Staging: live FCM call blocked.');
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => $body,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | ($ep['staging'] ? CURLPROTO_HTTP : 0)]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $code, 'json' => is_string($raw) ? (json_decode($raw, true) ?: []) : []];
}
/** Send queued pushes. Never throws for one bad message; FCM "UNREGISTERED" revokes the device. */
function push_send(int $max = 100): array {
    $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
    $rows = prows("SELECT * FROM p_outbox WHERE channel='fcm' AND status='queued' AND next_at<=UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, min(500, $max)));
    if (!$rows) return $out;
    try { $tok = push_access_token(); } catch (Throwable $e) {
        foreach ($rows as $m) prun("UPDATE p_outbox SET status='skipped',last_error=? WHERE id=?", mb_substr($e->getMessage(), 0, 250), $m['id']); $out['skipped'] = count($rows); return $out; }
    $ep = push_endpoints();
    foreach ($rows as $m) {
        $p = json_decode((string)$m['body'], true) ?: []; $did = (int)substr((string)$m['recipient'], 7);
        $d = prow("SELECT d.* FROM p_device_tokens d JOIN p_branch_members bm ON bm.user_id=d.user_id AND bm.tenant_id=d.tenant_id AND bm.branch_id=d.branch_id AND bm.status='active' WHERE d.id=? AND d.revoked_at IS NULL", $did);
        if (!$d || (int)$d['tenant_id'] !== (int)($p['tenant_id'] ?? -1) || (int)$d['branch_id'] !== (int)($p['branch_id'] ?? -1)) { prun("UPDATE p_outbox SET status='skipped',last_error='device no longer bound to this branch' WHERE id=?", $m['id']); $out['skipped']++; continue; }
        $token = p_dec($d['token_enc']); if (!$token) { prun("UPDATE p_outbox SET status='skipped',last_error='token unreadable' WHERE id=?", $m['id']); $out['skipped']++; continue; }
        $msg = ['message' => ['token' => $token, 'notification' => ['title' => (string)($p['title'] ?? ''), 'body' => (string)($p['body'] ?? '')],
                'data' => ['route' => (string)($p['route'] ?? ''), 'tenant_id' => (string)$p['tenant_id'], 'branch_id' => (string)$p['branch_id']], 'android' => ['priority' => 'high']]];
        $r = push_http('POST', str_replace('{project}', rawurlencode($tok['project']), $ep['send']), ['Authorization: Bearer ' . $tok['t'], 'Content-Type: application/json'], (string)json_encode($msg));
        if ($r['code'] === 200) { prun("UPDATE p_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=?", $m['id']); prun('UPDATE p_device_tokens SET last_push_at=UTC_TIMESTAMP() WHERE id=?', $did); $out['sent']++; }
        elseif (in_array($r['code'], [404, 400], true) && str_contains(json_encode($r['json']), 'UNREGISTERED')) { prun("UPDATE p_device_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=?", $did); prun("UPDATE p_outbox SET status='skipped',last_error='device unregistered' WHERE id=?", $m['id']); $out['skipped']++; }
        else { prun("UPDATE p_outbox SET attempts=attempts+1,last_error=?,status=IF(attempts>=5,'failed','queued'),next_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL LEAST(60,POW(2,attempts)) MINUTE) WHERE id=?", 'HTTP ' . $r['code'], $m['id']); $out['failed']++; }
    }
    return $out;
}
