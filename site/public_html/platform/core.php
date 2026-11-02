<?php
declare(strict_types=1);
// Finance 360 V29 — PLATFORM (control-plane) core.
// The platform database holds ONLY SaaS control data: restaurants (tenants), branches, users, memberships, plans,
// subscriptions, orders, webhook events, platform admins and audit. It never holds restaurant financial records.
// Each branch keeps its own ledger database (same schema as the original Khadak Singh Da Dhaba database), selected
// per request from a verified membership (platform/tenancy.php). Money = integer paise. Storage times = UTC.
require_once dirname(__DIR__) . '/lib.php';

const P_TZ = 'Asia/Kolkata';
const P_SCHEMA = 2;

function pcfg(): array { return (array)(cfg()['platform'] ?? []); }
function pdb(): PDO {
    if (!empty($GLOBALS['P_PDO'])) return $GLOBALS['P_PDO'];
    // V30 single database: platform (p_*) tables live in the SAME database as the business tables unless a separate one is configured.
    $c = (array)(pcfg()['db'] ?? []); if (empty($c['db_name']) && tenancy_shared()) $c = main_db_target();
    if (empty($c['db_name'])) fail('Platform database is not configured.', 503);
    $p = db_connect_target($c);
    db_session_collation($p); $p->exec("SET time_zone = '+00:00'");
    $p->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $p->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    return $GLOBALS['P_PDO'] = $p;
}
function prun(string $sql, mixed ...$a): PDOStatement { $s = pdb()->prepare($sql); $s->execute($a); return $s; }
function prows(string $sql, mixed ...$a): array { return prun($sql, ...$a)->fetchAll(); }
function prow(string $sql, mixed ...$a): ?array { $r = prun($sql, ...$a)->fetch(); return $r === false ? null : $r; }
function pval(string $sql, mixed ...$a): mixed { $r = prun($sql, ...$a)->fetchColumn(); return $r === false ? null : $r; }
function pid(): int { return (int)pdb()->lastInsertId(); }
function ptx(callable $fn): mixed {
    $p = pdb(); if ($p->inTransaction()) return $fn();
    $p->beginTransaction(); try { $v = $fn(); $p->commit(); return $v; } catch (Throwable $e) { if ($p->inTransaction()) $p->rollBack(); throw $e; }
}

/* ------------------------------ time ------------------------------ */
function p_now(): DateTimeImmutable {
    // Staging hook (same rule as f3_now): only when config explicitly enables a fake clock AND db names say staging/test.
    $c = cfg(); $f = !empty($c['f3_allow_fake_clock']) ? (getenv('P_FAKE_NOW') ?: (is_file(private_root() . '/p_fake_now.txt') ? trim((string)file_get_contents(private_root() . '/p_fake_now.txt')) : '')) : '';
    return $f !== '' ? new DateTimeImmutable($f, new DateTimeZone('UTC')) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
}
function p_utc(?DateTimeImmutable $d = null): string { return ($d ?? p_now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
function p_ist(?string $utc, string $fmt = 'j M Y, g:i A'): string { if (!$utc) return '—'; return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(P_TZ))->format($fmt) . ' IST'; }
function p_ts(string $utc): int { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->getTimestamp(); }

/* ------------------------------ identifiers & crypto ------------------------------ */
function p_uid(int $len = 12): string { $a = 'abcdefghijkmnopqrstuvwxyz23456789'; $s = ''; for ($i = 0; $i < $len; $i++) $s .= $a[random_int(0, strlen($a) - 1)]; return $s; }
function p_token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function p_sha(string $t): string { return hash('sha256', $t); }
function p_key(): string {
    $k = (string)(pcfg()['key'] ?? '');
    if (strlen($k) < 32 || str_starts_with($k, 'REPLACE_')) fail('Platform encryption key (platform.key) is missing in config.php.', 503);
    return hash('sha256', 'f360-platform|' . $k, true);
}
function p_enc(string $plain): string { $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES); return 'p1:' . base64_encode($n . sodium_crypto_secretbox($plain, $n, p_key())); }
function p_dec(?string $enc): ?string {
    if (!$enc || !str_starts_with($enc, 'p1:')) return null; $b = base64_decode(substr($enc, 3), true); if ($b === false || strlen($b) < 40) return null;
    $p = sodium_crypto_secretbox_open(substr($b, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($b, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), p_key()); return $p === false ? null : $p;
}
function p_ip_hash(): string { return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . (pcfg()['key'] ?? '')), 0, 24); }

/* ------------------------------ settings ------------------------------ */
const P_SETTING_DEFAULTS = [
    'trial_days' => '30', 'grace_days' => '3', 'signup_enabled' => '0', 'readonly_on_expiry' => '1',
    'support_max_minutes' => '120', 'brand_name' => 'Finance 360', 'billing_legal_name' => '', 'billing_gstin' => '', 'billing_address' => '',
];
function p_setting(string $k): string {
    $v = pval('SELECT v FROM p_settings WHERE k=?', $k);
    return $v === null ? (P_SETTING_DEFAULTS[$k] ?? '') : (string)$v;
}
function p_setting_set(string $k, string $v): void { prun('INSERT INTO p_settings(k,v) VALUES(?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)', $k, $v); }
/** Public sign-up needs BOTH the config switch and the Super Admin setting (V29 §19: never before isolation is proven). */
function p_signup_open(): bool { return !empty(pcfg()['signup_enabled']) && p_setting('signup_enabled') === '1'; }

/* ------------------------------ audit ------------------------------ */
function p_audit(string $actorType, ?int $actorId, ?int $tenantId, string $action, array $detail = []): void {
    prun('INSERT INTO p_audit(at,actor_type,actor_id,tenant_id,action,detail,ip_hash) VALUES(UTC_TIMESTAMP(),?,?,?,?,?,?)', $actorType, $actorId, $tenantId, substr($action, 0, 80),
        json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), p_ip_hash());
}

/* ------------------------------ rate limiting ------------------------------ */
function p_rate(string $bucket, int $max, int $windowSec): void {
    $k = p_sha($bucket); $now = time();
    prun('INSERT INTO p_rate(k,n,reset_at) VALUES(?,0,?) ON DUPLICATE KEY UPDATE n=IF(reset_at<?,0,n),reset_at=IF(reset_at<?,VALUES(reset_at),reset_at)', $k, $now + $windowSec, $now, $now);
    prun('UPDATE p_rate SET n=n+1 WHERE k=?', $k);
    if ((int)pval('SELECT n FROM p_rate WHERE k=?', $k) > $max) fail('Too many attempts. Please wait and try again.', 429);
}

/* ------------------------------ outbox (email / push / WhatsApp) ------------------------------ */
/** Queue a message once per dedupe key. Delivery never blocks the business transaction that queued it. */
function p_queue(string $kind, string $dedupe, string $channel, string $to, string $subject, string $body, ?int $tenantId = null): bool {
    return prun('INSERT IGNORE INTO p_outbox(kind,dedupe_key,channel,recipient,subject,body,tenant_id,status,created_at,next_at) VALUES(?,?,?,?,?,?,?,\'queued\',UTC_TIMESTAMP(),UTC_TIMESTAMP())',
        $kind, substr($dedupe, 0, 190), $channel, substr($to, 0, 190), substr($subject, 0, 250), $body, $tenantId)->rowCount() > 0;
}
/** Send queued email. mail.mode = smtp (Hostinger mailbox) | log (staging: kept in the outbox only, marked 'logged'). */
function p_outbox_run(int $max = 50): array {
    $mode = (string)(pcfg()['mail']['mode'] ?? 'log'); $done = ['sent' => 0, 'failed' => 0, 'logged' => 0];
    foreach (prows("SELECT * FROM p_outbox WHERE status='queued' AND channel='email' AND next_at<=UTC_TIMESTAMP() ORDER BY id LIMIT " . max(1, min(200, $max))) as $m) {
        if ($mode !== 'smtp') { prun("UPDATE p_outbox SET status='logged',sent_at=UTC_TIMESTAMP() WHERE id=?", $m['id']); $done['logged']++; continue; }
        try {
            require_once dirname(__DIR__) . '/report360.php';
            smtp_send((array)pcfg()['mail']['smtp'], [$m['recipient']], [], $m['subject'], $m['body'], []);
            prun("UPDATE p_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),attempts=attempts+1 WHERE id=?", $m['id']); $done['sent']++;
        } catch (Throwable $e) {
            $a = (int)$m['attempts'] + 1;
            prun("UPDATE p_outbox SET attempts=?,last_error=?,status=IF(?>=6,'failed','queued'),next_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE) WHERE id=?", $a, mb_substr($e->getMessage(), 0, 250), $a, 2 ** $a, $m['id']); $done['failed']++;
        }
    }
    return $done;
}
function p_private(string $sub): string { $d = private_root() . '/platform/' . trim($sub, '/'); if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function p_base_url(): string { return rtrim((string)(pcfg()['base_url'] ?? ''), '/'); }

/* ------------------------------ i18n (English / Hindi) ------------------------------ */
function p_lang(): string {
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'hi'], true)) { if (PHP_SAPI !== 'cli' && !headers_sent()) setcookie('f360_lang', $_GET['lang'], ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax', 'secure' => https_request()]); return $_GET['lang']; }
    return ($_COOKIE['f360_lang'] ?? 'en') === 'hi' ? 'hi' : 'en';
}
function tr(string $en, ?string $hi = null): string { return p_lang() === 'hi' && $hi !== null ? $hi : $en; }
function pe(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function p_rs(int $paise): string { $neg = $paise < 0; $p = abs($paise); $r = intdiv($p, 100); $s = (string)$r; $l = strlen($s); if ($l > 3) $s = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, $l - 3)) . ',' . substr($s, -3); return ($neg ? '-' : '') . '₹' . $s . ($p % 100 ? '.' . str_pad((string)($p % 100), 2, '0', STR_PAD_LEFT) : ''); }
