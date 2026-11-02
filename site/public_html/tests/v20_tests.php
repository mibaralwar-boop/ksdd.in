<?php
declare(strict_types=1);
// Finance 360 V20/V21 — Google Drive, encrypted backups, AiSensy WhatsApp, scheduler. Included by tests/run_tests.php.
// Runs against the local MOCK Google/AiSensy server (scripts/mock_int.py on 127.0.0.1:8399) — staging only.
// These tests prove the code paths (success, failure, retry, dedupe, security). They do NOT prove the live Google/AiSensy
// accounts work: that needs the owner's real credentials (see docs/INTEGRATION_TEST_REPORT.md).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once 'f360/integrations.php';
global $own, $acc, $mgr, $ctr;
const MOCK = 'http://127.0.0.1:8399';
function mock(string $path, ?array $body = null): array { $r = f3_http($body === null ? 'GET' : 'POST', MOCK . $path, ['Content-Type: application/json'], $body === null ? null : json_encode($body)); return $r['json'] ?? []; }
/** Like t() but without wrapping a DB transaction (integration code manages its own, as in production). */
function t0(string $id, string $name, callable $fn): void { global $P, $Fl, $log;
    try { $r = $fn(); $ok = $r !== false; } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); $ok = false; $r = get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(); }
    $ok ? $P++ : $Fl++; $log[] = [$id, $name, $ok ? 'PASS' : 'FAIL', $ok ? '' : (is_string($r) ? $r : 'returned false')];
    echo ($ok ? "  PASS " : "  FAIL ") . "$id $name" . ($ok ? '' : "  → " . (is_string($r) ? $r : 'false')) . "\n"; }

echo "\n== V20 · Integrations (mock Google Drive + AiSensy) ==\n";
$up = f3_http('GET', MOCK . '/_state'); if ($up['code'] !== 200) { echo "  SKIP: mock server not running on 8399 — start: python3 scripts/mock_int.py 8399\n"; return; }
mock('/_reset', []);
// clean integration state from earlier runs (staging only)
foreach (['f3_int_secrets', 'f3_drive_folders', 'f3_drive_items', 'f3_wa_messages', 'f3_wa_recipients', 'f3_wa_webhooks', 'f3_job_runs'] as $tb) run("DELETE FROM $tb");
set_setting('f3_drive_cfg', '{}'); set_setting('f3_wa_cfg', '{}');
$ok = fn(bool $c, string $m = '') => $c ?: throw new RuntimeException($m ?: 'assertion failed');

t0('V20-SEC-01', 'Only the Owner can read or change integrations (accountant / manager / counter blocked on every route)', function () use ($acc, $mgr, $ctr) {
    foreach ([$acc, $mgr, $ctr] as $u) { throws(fn() => f3_int_state($u), 'Only the Owner'); foreach (['int-drive-save', 'int-wa-save', 'int-drive-connect', 'int-backup-now', 'int-wa-test'] as $r) throws(fn() => f3_int_post($r, [], $u), 'Only the Owner'); }
    return true; });

t0('V20-DRV-01', 'Save Drive settings; secret stored encrypted; Connect builds a Google URL with drive.file scope, offline access and a state token', function () use ($own, $ok) {
    f3_int_post('int-drive-save', ['client_id' => '1234-demo.apps.googleusercontent.com', 'client_secret' => 'GOCSPX-demo-secret-value', 'redirect_uri' => 'https://example.test/f360-oauth.php', 'types' => array_fill_keys(array_keys(F3_DRIVE_TYPES), true), 'encrypt_sensitive' => 1, 'backup_passphrase' => 'demo backup passphrase 2026', 'backup_files' => 1], $own);
    $raw = row("SELECT value_enc FROM f3_int_secrets WHERE name='drive_client_secret'")['value_enc']; $ok(!str_contains($raw, 'GOCSPX') && str_starts_with($raw, 'v1:'), 'secret not encrypted');
    $u = f3_int_post('int-drive-connect', [], $own)['url']; parse_str(parse_url($u, PHP_URL_QUERY), $q);
    $ok($q['scope'] === F3_DRIVE_SCOPE && $q['access_type'] === 'offline' && $q['prompt'] === 'consent' && strlen($q['state']) === 48, 'bad auth url');
    $GLOBALS['V20_STATE'] = $q['state']; return true; });

t0('V20-DRV-02', 'OAuth callback: wrong state refused; correct state stores refresh token (encrypted) and the account; state is single-use', function () use ($ok) {
    throws(fn() => transaction(fn() => f3_drive_auth_finish('code1', str_repeat('a', 48))), 'expired');
    $r = transaction(fn() => f3_drive_auth_finish('code1', $GLOBALS['V20_STATE'])); $ok($r['email'] === 'demo.owner@example.com', 'email');
    $ok(f3_secret_has('drive_refresh_token') && !str_contains(row("SELECT value_enc FROM f3_int_secrets WHERE name='drive_refresh_token'")['value_enc'], 'rt-'), 'refresh token');
    $ok(f3_drive_cfg()['state'] === 'authorised' && f3_drive_cfg()['enabled'], 'state authorised');
    return throws(fn() => transaction(fn() => f3_drive_auth_finish('code1', $GLOBALS['V20_STATE'])), 'expired'); });

t0('V20-SEC-02', 'Integration state sent to the browser contains no secrets (client secret, tokens, API key, passphrase)', function () use ($own, $ok) {
    $j = json_encode(f3_int_state($own)); foreach (['GOCSPX', 'demo backup passphrase'] as $s) $ok(!str_contains($j, $s), "leaked $s"); return $ok(!preg_match('/\b[ar]t-[0-9a-f]{32}/', $j), 'token leaked'); });

t0('V20-DRV-03', 'Test connection: token refresh, quota, folder, upload, download and checksum compare all pass', function () use ($own, $ok) {
    $r = f3_int_post('int-drive-test', [], $own); $ok($r['ok'], json_encode($r['steps'])); return eq(f3_drive_cfg()['state'], 'verified'); });

// documents to sync: one posted bill with a photo, an item photo, a reviewed expense receipt
$GLOBALS['mkpng'] = function (): string { $im = imagecreatetruecolor(8, 8); imagefill($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255))); ob_start(); imagepng($im); return base64_encode((string)ob_get_clean()); };
$png = base64_encode(file_get_contents(__DIR__ . '/../assets/brand/logo-mark-96.png'));
$bill = row("SELECT id FROM f3_purchase_bills WHERE status='posted' ORDER BY id LIMIT 1");
t0('V20-DRV-04', 'Scan queues bill photos, item photos and reviewed expense receipts into FY / month / type folders; second scan adds nothing', function () use ($own, $png, $bill, $ok) {
    $ok((bool)$bill, 'need a posted bill from V17 tests');
    $fid = transaction(fn() => store_upload(['data' => $png, 'name' => 'bill.png'], $own)); run('INSERT IGNORE INTO f3_bill_files(bill_id,file_id,kind) VALUES(?,?,?)', $bill['id'], $fid, 'bill');
    $iid = (int)row('SELECT id FROM dd_bk_items ORDER BY id LIMIT 1')['id']; $fid2 = transaction(fn() => store_upload(['data' => $png, 'name' => 'item.png'], $own)); run('UPDATE dd_bk_items SET photo_file_id=? WHERE id=?', $fid2, $iid);
    $s1 = f3_drive_scan(); $s2 = f3_drive_scan(); $ok($s1['items'] >= 2 && $s2['items'] === 0, json_encode([$s1, $s2]));
    $b = row("SELECT * FROM f3_drive_items WHERE source_key=?", 'bill:' . $bill['id'] . ':' . $fid); $ok((bool)preg_match('#^FY \d{4}-\d{2}/\d{4}-\d{2} \w+/Purchase bills$#', $b['folder']), 'folder ' . $b['folder']);
    $sal = row("SELECT * FROM f3_drive_items WHERE kind='salary' LIMIT 1"); $ok(!$sal || ((int)$sal['is_sensitive'] === 1 && str_contains($sal['folder'], 'Salary (restricted)')), 'salary restricted');
    return true; });

t0('V20-DRV-05', 'Upload queue: all items uploaded once, MD5 verified, links stored; mock Drive has exactly one file per item', function () use ($ok) {
    $r = f3_drive_process(60, 200); $n = (int)row("SELECT COUNT(*) n FROM f3_drive_items WHERE status='done'")['n'];
    $ok($n > 0 && !row("SELECT 1 FROM f3_drive_items WHERE status IN ('queued','retry','failed')"), json_encode($r) . ' ' . json_encode(rows("SELECT status,last_error FROM f3_drive_items WHERE status<>'done' LIMIT 3")));
    $ok(!row("SELECT 1 FROM f3_drive_items WHERE status='done' AND (verified_at IS NULL OR web_link IS NULL)"), 'not verified');
    $files = array_filter(mock('/_state')['files'], fn($f) => isset($f['appProperties']['ksdd_key'])); $keys = array_count_values(array_map(fn($f) => $f['appProperties']['ksdd_key'], $files));
    return eq(max($keys), 1, 'duplicate in Drive') && eq(count($keys), $n, 'files vs items'); });

t0('V20-DRV-06', 'Restricted salary sheet is encrypted before upload (Drive copy starts KSDDENC1 and decrypts back to the exact .xlsx)', function () use ($ok) {
    $it = row("SELECT * FROM f3_drive_items WHERE kind='salary' AND name LIKE '%.xlsx' AND status='done' LIMIT 1"); if (!$it) return 'no approved salary period in staging to back up';
    $ok((int)$it['encrypted'] === 1, 'flag');
    $d = f3_drive_api('GET', f3_int_ep('drive') . '/files/' . $it['drive_file_id'] . '?alt=media')['body']; $ok(str_starts_with($d, 'KSDDENC1'), 'not encrypted in Drive');
    $plain = f3_decrypt_blob($d, f3_backup_pass()); return eq(hash('sha256', $plain), $it['sha256'], 'roundtrip') && eq(substr($plain, 0, 2), 'PK'); });

t0('V20-DRV-07', 'Lost upload response → retry finds the file by its app key and does NOT upload a duplicate', function () use ($own, $png, $bill, $ok) {
    $fid = transaction(fn() => store_upload(['data' => $GLOBALS['mkpng'](), 'name' => 'b2.png'], $own)); run('INSERT IGNORE INTO f3_bill_files(bill_id,file_id,kind) VALUES(?,?,?)', $bill['id'], $fid, 'bill');
    f3_drive_scan(); mock('/_mode', ['drop_response_once' => true]); $before = mock('/_state')['uploads'];
    f3_drive_process(20, 5); $it = row('SELECT * FROM f3_drive_items WHERE source_key=?', 'bill:' . $bill['id'] . ':' . $fid); $ok($it['status'] === 'retry' && $it['next_try_at'], 'should be retry: ' . $it['status']);
    run('UPDATE f3_drive_items SET next_try_at=NULL WHERE id=?', $it['id']); f3_drive_process(20, 5);
    $it = row('SELECT * FROM f3_drive_items WHERE id=?', $it['id']); return eq($it['status'], 'done') && eq(mock('/_state')['uploads'] - $before, 1, 'uploads'); });

t0('V20-DRV-08', 'Drive storage full → item failed, queue paused, admin alert logged; Retry un-pauses and uploads', function () use ($own, $png, $bill, $ok) {
    $fid = transaction(fn() => store_upload(['data' => $GLOBALS['mkpng'](), 'name' => 'b3.png'], $own));
    run('INSERT IGNORE INTO f3_bill_files(bill_id,file_id,kind) VALUES(?,?,?)', $bill['id'], $fid, 'bill'); f3_drive_scan();
    mock('/_mode', ['quota' => true]); f3_drive_process(20, 5);
    $it = row('SELECT * FROM f3_drive_items WHERE source_key=?', 'bill:' . $bill['id'] . ':' . $fid);
    $ok($it['status'] === 'failed' && str_contains($it['last_error'], 'storage is full') && f3_drive_cfg()['paused'] === 'storage full', json_encode($it));
    $ok((bool)row("SELECT 1 FROM f3_int_events WHERE service='drive' AND level='error' AND message LIKE '%storage is full%'"), 'no alert');
    mock('/_mode', ['quota' => false]); f3_int_post('int-drive-retry', ['id' => $it['id']], $own); f3_drive_process(20, 5);
    return eq(row('SELECT status FROM f3_drive_items WHERE id=?', $it['id'])['status'], 'done'); });

t0('V20-DRV-09', 'Temporary Google error (503) → retry with back-off; no failure', function () use ($own, $ok) {
    f3_drive_queue('test:503', 'test', ['local_path' => 'assets/brand/logo-mark-96.png', 'name' => 'retry-test.png', 'folder' => '_Connection tests', 'mime' => 'image/png']);
    mock('/_mode', ['drive_500_once' => true]); f3_drive_process(10, 1); $it = row("SELECT * FROM f3_drive_items WHERE source_key='test:503'");
    $ok($it['status'] === 'retry' && strtotime($it['next_try_at'] . ' UTC') > time() + 200, 'backoff ' . json_encode($it));
    run("UPDATE f3_drive_items SET next_try_at=NULL WHERE id=?", $it['id']); f3_drive_process(10, 1); return eq(row('SELECT status FROM f3_drive_items WHERE id=?', $it['id'])['status'], 'done'); });

t0('V20-DRV-10', 'Manual re-sync finds a file deleted in Drive and re-uploads it', function () use ($own, $ok) {
    $it = row("SELECT * FROM f3_drive_items WHERE status='done' AND kind='bills' LIMIT 1"); f3_drive_api('DELETE', f3_int_ep('drive') . '/files/' . $it['drive_file_id']);
    run('UPDATE f3_drive_items SET verified_at=NULL WHERE id=?', $it['id']); $r = f3_drive_resync($own, 500); $ok($r['requeued'] === 1, json_encode($r));
    f3_drive_process(10, 5); return eq(row('SELECT status FROM f3_drive_items WHERE id=?', $it['id'])['status'], 'done'); });

t0('V20-BKP-01', 'Encrypted backup (database + documents) is created on the server, self-verified, and queued to Drive', function () use ($own, $ok) {
    $r = f3_backup_run($own, true); $ok($r['verify']['ok'] && $r['verify']['rows'] > 100 && $r['verify']['documents'] > 0 && $r['drive_queued'], json_encode($r['verify']));
    $p = f3_backup_dir() . '/' . $r['name']; $ok(substr((string)file_get_contents($p), 0, 8) === 'KSDDENC1', 'not encrypted');
    $GLOBALS['V20_BK'] = $r['name']; f3_drive_process(60, 5); return eq(row("SELECT status FROM f3_drive_items WHERE kind='backup' AND name=?", $r['name'])['status'], 'done'); });

t0('V20-BKP-02', 'Verify downloads the backup back from Drive, decrypts it and checks every table row-count and document checksum', function () use ($own, $ok) {
    $v = f3_int_post('int-backup-verify', ['name' => $GLOBALS['V20_BK'], 'from' => 'drive'], $own); return $ok($v['ok'] && $v['documents_ok'] === $v['documents'], json_encode($v)); });

t0('V20-BKP-03', 'Tampered or wrong-passphrase backups are rejected', function () use ($ok) {
    $b = (string)file_get_contents(f3_backup_dir() . '/' . $GLOBALS['V20_BK']); $b[200] = chr(ord($b[200]) ^ 1);
    throws(fn() => f3_backup_verify_blob($b, f3_backup_pass()), 'Decryption failed');
    return throws(fn() => f3_backup_verify_blob((string)file_get_contents(f3_backup_dir() . '/' . $GLOBALS['V20_BK']), 'wrong passphrase 123'), 'Decryption failed'); });

t0('V20-DRV-11', 'Google access revoked → status "reconnect needed", error logged, queue stops cleanly (no crash, nothing lost)', function () use ($ok) {
    f3_secret_set('drive_access', null); mock('/_mode', ['revoked' => true]);
    f3_drive_queue('test:revoked', 'test', ['local_path' => 'assets/brand/logo-mark-96.png', 'name' => 'revoked-test.png', 'folder' => '_Connection tests', 'mime' => 'image/png']);
    f3_drive_process(10, 2); $it = row("SELECT * FROM f3_drive_items WHERE source_key='test:revoked'");
    $ok(f3_drive_cfg()['state'] === 'reconnect_needed' && $it['status'] === 'failed', json_encode([$it['status'], f3_drive_cfg()['state']]));
    mock('/_mode', ['revoked' => false]); return true; });

t0('V20-DRV-12', 'Disconnect revokes at Google, deletes tokens, keeps upload history', function () use ($own, $ok) {
    $n = (int)row('SELECT COUNT(*) n FROM f3_drive_items')['n']; $r = f3_int_post('int-drive-disconnect', [], $own);
    return $ok($r['revoked'] && !f3_secret_has('drive_refresh_token') && !f3_secret_has('drive_access') && (int)row('SELECT COUNT(*) n FROM f3_drive_items')['n'] === $n && !f3_drive_cfg()['enabled']); });

/* -------- WhatsApp -------- */
$emp = row("SELECT id,name FROM dd_employees WHERE status='active' ORDER BY id LIMIT 1"); $sup = row("SELECT id FROM dd_bk_parties WHERE kind IN ('supplier','both') AND merged_into IS NULL ORDER BY id LIMIT 1");
t0('V20-WA-01', 'Recipient rules: valid number, consent needs a recorded source, salary messages only to that staff member, internal alerts never to suppliers', function () use ($own) {
    throws(fn() => f3_int_post('int-wa-recipient', ['name' => 'X', 'phone' => '12345', 'role' => 'manager'], $own), 'valid WhatsApp number');
    throws(fn() => f3_int_post('int-wa-recipient', ['name' => 'X', 'phone' => '9876500001', 'role' => 'manager', 'consent' => 1], $own), 'Record how consent');
    throws(fn() => f3_int_post('int-wa-recipient', ['name' => 'X', 'phone' => '9876500001', 'role' => 'manager', 'events' => ['staff_payment']], $own), 'cannot be sent');
    return throws(fn() => f3_int_post('int-wa-recipient', ['name' => 'X', 'phone' => '9876500001', 'role' => 'supplier', 'events' => ['low_stock']], $own), 'cannot be sent'); });
$R = [];
t0('V20-WA-02', 'Phone numbers stored encrypted + hashed; list shows only the last 4 digits; duplicates refused', function () use ($own, $emp, $sup, &$R, $ok) {
    $all = array_keys(array_filter(F3_WA_EVENTS, fn($e) => $e[1] === 'internal'));
    $R['owner'] = f3_int_post('int-wa-recipient', ['name' => 'DEMO Owner', 'phone' => '98765 43210', 'role' => 'owner', 'events' => $all, 'consent' => 1, 'consent_source' => 'Owner (self)'], $own)['id'];
    $R['noconsent'] = f3_int_post('int-wa-recipient', ['name' => 'DEMO Manager', 'phone' => '9876500002', 'role' => 'manager', 'events' => $all], $own)['id'];
    $R['staff'] = f3_int_post('int-wa-recipient', ['name' => 'DEMO Staff', 'phone' => '9876500003', 'role' => 'staff', 'employee_id' => $emp['id'], 'events' => ['staff_payment'], 'consent' => 1, 'consent_source' => 'Signed form'], $own)['id'];
    $R['sup'] = f3_int_post('int-wa-recipient', ['name' => 'DEMO Supplier', 'phone' => '+919876500004', 'role' => 'supplier', 'supplier_id' => $sup['id'], 'events' => ['supplier_payment'], 'consent' => 1, 'consent_source' => 'WhatsApp opt-in'], $own)['id'];
    $raw = row('SELECT * FROM f3_wa_recipients WHERE id=?', $R['owner']); $ok(!str_contains($raw['phone_enc'], '9876543210') && $raw['phone_last4'] === '3210', 'plain phone');
    $ok(!str_contains(json_encode(f3_int_state($own)['wa']['recipients']), '98765'), 'list leaks');
    return throws(fn() => f3_int_post('int-wa-recipient', ['name' => 'Dup', 'phone' => '+919876543210', 'role' => 'manager'], $own), 'already'); });

t0('V20-WA-03', 'Settings: key stored encrypted; campaign names validated; enabling records the start time (no flood of old events)', function () use ($own, $ok) {
    throws(fn() => f3_int_post('int-wa-save', ['enabled' => 1], $own), 'API key');
    $ev = []; foreach (F3_WA_EVENTS as $k => $_) $ev[$k] = ['on' => 1, 'campaign' => 'ksdd_' . $k];
    throws(fn() => f3_int_post('int-wa-save', ['api_key' => 'mock-aisensy-key-0123456789abcdef', 'events' => ['low_stock' => ['on' => 1, 'campaign' => 'bad<name>']]], $own), 'Campaign names');
    run("UPDATE f3_closings SET submitted_at=submitted_at - INTERVAL 1 DAY"); // older closings must not trigger alerts
    f3_int_post('int-wa-save', ['api_key' => 'mock-aisensy-key-0123456789abcdef', 'enabled' => 1, 'events' => $ev, 'monthly_limit' => 100, 'quiet_from' => 0, 'quiet_to' => 0], $own); // quiet hours tested separately (test clock may be at night)
    $ok(!str_contains(row("SELECT value_enc FROM f3_int_secrets WHERE name='wa_api_key'")['value_enc'], 'mock-aisensy'), 'key plain');
    return $ok((bool)f3_wa_cfg()['since'] && f3_secret_has('wa_webhook_token')); });

mock('/_mode', ['wa' => 'ok']);
t0('V20-WA-04', 'Scan: only consenting, subscribed recipients get messages; second scan adds none (idempotent)', function () use ($own, &$R, $ok) {
    run("INSERT INTO f3_notifications(kind,dedupe_key,title,perm,status,created_at) VALUES('closing-due','closing-due-2026-11-01','Closing pending','closing.entry','open',NOW()) ON DUPLICATE KEY UPDATE status='open',created_at=NOW()");
    $a = f3_wa_scan(); $b = f3_wa_scan();
    $ok($a['items'] >= 1 && $b['items'] === 0, json_encode([$a, $b]));
    $ok(!row('SELECT 1 FROM f3_wa_messages WHERE recipient_id=?', $R['noconsent']), 'sent to non-consenting');
    return eq((int)row("SELECT COUNT(*) n FROM f3_wa_messages WHERE event='closing_pending' AND recipient_id=?", $R['owner'])['n'], 1); });

t0('V20-WA-05', 'Send: AiSensy receives the mapped campaign, +91 number and the right number of template variables; status "sent" (accepted), NOT "delivered"', function () use ($ok) {
    mock('/_mode', ['wa' => 'ok']); $r = f3_wa_process(20, 10); $m = row("SELECT * FROM f3_wa_messages WHERE event='closing_pending' LIMIT 1");
    $ok($m['status'] === 'sent' && $m['provider_id'] && !$m['delivered_at'], json_encode($m));
    $sent = array_values(array_filter(mock('/_state')['wa'], fn($x) => $x['campaignName'] === 'ksdd_closing_pending'))[0];
    return eq($sent['destination'], '+919876543210') && eq(count($sent['templateParams']), 3) && eq($sent['key_ok'], true); });

t0('V20-WA-06', 'Failures: 500 → retry with back-off; 400 (campaign not live) → failed, not retried; 401 → failed + "key rejected"; admin alert logged', function () use ($own, &$R, $ok) {
    $mk = function (string $k) use (&$R) { $r = row('SELECT * FROM f3_wa_recipients WHERE id=?', $R['owner']); f3_wa_enqueue('low_stock', $k, $r, ['', '1', 'test item'], 'test'); return (int)row('SELECT id FROM f3_wa_messages WHERE idem_key=?', $k . ':' . $R['owner'])['id']; };
    $a = $mk('t500'); mock('/_mode', ['wa' => '500']); f3_wa_process(10, 1); $m = row('SELECT * FROM f3_wa_messages WHERE id=?', $a); $ok($m['status'] === 'retry' && $m['next_try_at'], 'retry ' . $m['status']);
    run("UPDATE f3_wa_messages SET status='cancelled' WHERE id=?", $a);
    $b = $mk('t400'); mock('/_mode', ['wa' => '400']); f3_wa_process(10, 1); $m = row('SELECT * FROM f3_wa_messages WHERE id=?', $b); $ok($m['status'] === 'failed' && str_contains($m['last_error'], 'not live'), 'fail400 ' . $m['status']);
    $c = $mk('t401'); mock('/_mode', ['wa' => '401']); f3_wa_process(10, 1); $m = row('SELECT * FROM f3_wa_messages WHERE id=?', $c); $ok($m['status'] === 'failed' && f3_wa_cfg()['state'] === 'auth_failed', 'fail401');
    mock('/_mode', ['wa' => 'ok']); return $ok((bool)row("SELECT 1 FROM f3_int_events WHERE service='wa' AND level='error'")); });

t0('V20-WA-07', 'Webhook: wrong token 403; delivered → read updates; late "failed" never downgrades a read message; duplicate body ignored', function () use ($ok) {
    $tok = f3_secret_get('wa_webhook_token'); $m = row("SELECT * FROM f3_wa_messages WHERE event='closing_pending' AND status='sent' LIMIT 1");
    $body = fn(string $st) => json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => 'x', 'changes' => [['field' => 'messages', 'value' => ['statuses' => [['id' => $m['provider_id'], 'status' => $st, 'recipient_id' => '919876543210']]]]]]]]);
    eq(f3_wa_webhook($body('delivered'), [], 'wrong')[0], 403, 'token');
    eq(transaction(fn() => f3_wa_webhook($body('delivered'), [], $tok))[0], 200); eq(row('SELECT status FROM f3_wa_messages WHERE id=?', $m['id'])['status'], 'delivered');
    transaction(fn() => f3_wa_webhook($body('read'), [], $tok)); transaction(fn() => f3_wa_webhook($body('failed'), [], $tok));
    eq(transaction(fn() => f3_wa_webhook($body('read'), [], $tok))[1], 'duplicate', 'dup');
    return eq(row('SELECT status FROM f3_wa_messages WHERE id=?', $m['id'])['status'], 'read'); });

t0('V20-WA-08', 'Opt-out: inbound "STOP" withdraws consent and cancels queued messages for that number', function () use (&$R, $ok) {
    $r = row('SELECT * FROM f3_wa_recipients WHERE id=?', $R['owner']); f3_wa_enqueue('low_stock', 'optout-test', $r, ['', '2', 'x'], 'x');
    $tok = f3_secret_get('wa_webhook_token'); transaction(fn() => f3_wa_webhook(json_encode(['entry' => [['changes' => [['value' => ['messages' => [['from' => '919876543210', 'text' => ['body' => 'stop']]]]]]]]]), [], $tok));
    $r = row('SELECT * FROM f3_wa_recipients WHERE id=?', $R['owner']); $ok(!$r['consent'] && $r['opted_out_at'] && $r['opt_out_source'] === 'whatsapp STOP', 'not opted out');
    $ok(row("SELECT status FROM f3_wa_messages WHERE idem_key=?", 'optout-test:' . $R['owner'])['status'] === 'cancelled', 'queued not cancelled');
    f3_int_post('int-wa-recipient', ['id' => $R['owner'], 'name' => 'DEMO Owner', 'role' => 'owner', 'events' => array_keys(array_filter(F3_WA_EVENTS, fn($e) => $e[1] === 'internal')), 'consent' => 1, 'consent_source' => 'Owner re-confirmed'], $GLOBALS['own']); return true; });

t0('V20-WA-09', 'Template variable check: wrong count is held as "skipped" with the reason; newlines/tabs are cleaned', function () use (&$R, $ok) {
    $r = row('SELECT * FROM f3_wa_recipients WHERE id=?', $R['owner']); f3_wa_enqueue('low_stock', 'badparams', $r, ['only one'], 'x');
    $m = row('SELECT * FROM f3_wa_messages WHERE idem_key=?', 'badparams:' . $R['owner']); $ok($m['status'] === 'skipped' && str_contains($m['last_error'], 'needs 3'), 'not skipped');
    return eq(f3_wa_param("a\n\tb    c"), 'a b c'); });

t0('V20-WA-10', 'Monthly limit and quiet hours hold messages (nothing lost, sent later)', function () use (&$R, $ok) {
    $r = row('SELECT * FROM f3_wa_recipients WHERE id=?', $R['owner']); f3_wa_enqueue('low_stock', 'limit-test', $r, ['', '1', 'x'], 'x');
    $c = f3_wa_cfg(); f3_wa_cfg_set(['monthly_limit' => f3_wa_month_count()]); f3_wa_process(5, 3); $ok(row("SELECT status FROM f3_wa_messages WHERE idem_key=?", 'limit-test:' . $R['owner'])['status'] === 'queued', 'limit');
    f3_wa_cfg_set(['monthly_limit' => 1000, 'quiet_from' => 0, 'quiet_to' => 23]); $q = f3_wa_process(5, 3); $ok(str_contains($q['message'], 'quiet'), 'quiet');
    f3_wa_cfg_set(['quiet_from' => 0, 'quiet_to' => 0]); f3_wa_process(5, 3); return eq(row("SELECT status FROM f3_wa_messages WHERE idem_key=?", 'limit-test:' . $R['owner'])['status'], 'sent'); });

t0('V20-WA-11', 'Salary-payment messages: never sent unless the owner authorises them, and then only to that staff member', function () use ($own, $emp, &$R, $ok) {
    $pp = row('SELECT id FROM f3_pay_payments WHERE voided=0 AND employee_id=? ORDER BY id DESC LIMIT 1', $emp['id']);
    if (!$pp) { $pp = ['id' => 0]; run("INSERT INTO f3_pay_payments(pay_no,period,employee_id,date,amount,mode,created_at) VALUES('PAYTEST-1','2026-10',?,'2026-11-02',100,'cash',NOW())", $emp['id']); $pp['id'] = (int)db()->lastInsertId(); }
    else run('UPDATE f3_pay_payments SET created_at=NOW() WHERE id=?', $pp['id']);
    f3_wa_cfg_set(['staff_payment_authorized' => false]); f3_wa_scan(); $ok(!row("SELECT 1 FROM f3_wa_messages WHERE event='staff_payment'"), 'sent without authorisation');
    f3_wa_cfg_set(['staff_payment_authorized' => true]); f3_wa_scan(); $m = rows("SELECT recipient_id FROM f3_wa_messages WHERE event='staff_payment'");
    run("UPDATE f3_pay_payments SET voided=1,void_reason='V20 test fixture' WHERE pay_no='PAYTEST-1'");
    return eq(count($m) >= 1, true) && eq(array_unique(array_map('intval', array_column($m, 'recipient_id'))), [$R['staff']]); });

t0('V20-WA-12', 'WhatsApp outage never blocks money: a supplier payment posts normally while AiSensy returns 500, and its confirmation is queued afterwards', function () use ($own, $sup, &$R, $ok) {
    mock('/_mode', ['wa' => '500']); $before = (int)row('SELECT COUNT(*) n FROM f3_supplier_payments')['n'];
    $p = transaction(fn() => f3_sup_pay(['client_key' => 'v20pay' . bin2hex(random_bytes(4)), 'supplier_id' => $sup['id'], 'amount' => '10', 'mode' => 'Cash', 'date' => f3_business_date(), 'reference' => 'V20 test'], $own));
    $ok((int)row('SELECT COUNT(*) n FROM f3_supplier_payments')['n'] === $before + 1, 'payment not posted');
    f3_wa_scan(); f3_wa_process(5, 5); $m = row("SELECT * FROM f3_wa_messages WHERE event='supplier_payment' ORDER BY id DESC LIMIT 1");
    $ok($m && (int)$m['recipient_id'] === $R['sup'] && $m['status'] === 'retry', json_encode($m));
    mock('/_mode', ['wa' => 'ok']); run('UPDATE f3_wa_messages SET next_try_at=NULL WHERE id=?', $m['id']); f3_wa_process(5, 5); return eq(row('SELECT status FROM f3_wa_messages WHERE id=?', $m['id'])['status'], 'sent'); });

mock('/_mode', ['wa' => 'ok']);
t0('V20-WA-13', 'Test message: refused for non-consenting numbers; accepted test is clearly "accepted, not proof of delivery"; owner can confirm delivery', function () use ($own, &$R, $ok) {
    throws(fn() => f3_int_post('int-wa-test', ['event' => 'low_stock', 'recipient_id' => $R['noconsent']], $own), 'consent');
    $r = f3_int_post('int-wa-test', ['event' => 'low_stock', 'recipient_id' => $R['owner']], $own); $ok($r['ok'] && str_contains($r['note'], 'NOT prove'), json_encode($r));
    f3_int_post('int-wa-confirm', ['id' => $r['id']], $own); return eq(f3_wa_cfg()['state'], 'delivery_confirmed'); });

t0('V20-JOB-01', 'Scheduler: every job logs start/end/status; one failing job does not stop the others; health view reports it', function () use ($ok) {
    $r = f3_job('wa_scan', fn() => throw new RuntimeException('simulated failure')); $ok($r['status'] === 'failed', 'not failed');
    $all = f3_int_cron(20); $ok(count($all) === count(F3_JOBS) && !in_array('failed', array_column($all, 'status'), true), json_encode($all));
    $h = array_column(f3_scheduler_health(), null, 'job'); return $ok($h['wa_scan']['fails_24h'] === 1 && $h['reminders']['health'] === 'ok' && $h['drive_upload']['last'] !== null); });

t0('V20-JOB-02', 'Same job cannot run twice at once (named lock)', function () use ($ok) {
    $pdo2 = new PDO('mysql:host=' . (cfg()['db_host'] ?? 'localhost') . ';dbname=' . cfg()['db_name'], cfg()['db_user'], cfg()['db_password']); $pdo2->query("SELECT GET_LOCK('ksdd_job_wa_send',0)");
    $r = f3_job('wa_send', fn() => ['message' => 'should not run']); $pdo2->query("SELECT RELEASE_LOCK('ksdd_job_wa_send')"); return $ok($r['status'] === 'skipped', json_encode($r)); });

t0('V20-LEAK-01', 'Logs, events and message history contain no API key, tokens, passphrase or full phone numbers', function () use ($ok) {
    $blob = json_encode([rows('SELECT * FROM f3_int_events'), rows('SELECT idem_key,summary,last_error,provider_id FROM f3_wa_messages'), rows('SELECT message FROM f3_job_runs'), rows('SELECT last_error,name,folder FROM f3_drive_items')]);
    foreach (['mock-aisensy-key', 'GOCSPX', 'demo backup passphrase', '9876543210'] as $s) $ok(!str_contains($blob, $s), "leaked $s"); return $ok(!preg_match('/\b[ar]t-[0-9a-f]{32}/', $blob), 'token leaked'); });

echo "\n== V21 · Financial reconciliation & controls ==\n";
require_once 'f360/reconcile.php'; require_once 'f360/overview.php'; require_once 'f360/stock.php';
t('V21-STK-01', 'Opening stock and stock corrections are refused without a reason (date, user and audit are recorded)', function () use ($own) {
    $iid = (int)row("SELECT id FROM dd_bk_items WHERE category='Vol' ORDER BY id LIMIT 1")['id'];
    throws(fn() => f3_item_opening(['id' => $iid, 'qty' => '5', 'value' => '50', 'date' => '2026-10-31'], $own), 'Reason');
    return throws(fn() => f3_stock_manual(['client_key' => 'v21' . bin2hex(random_bytes(4)), 'kind' => 'adjust_in', 'item_id' => $iid, 'qty' => '1', 'value' => '1', 'reason' => ''], $own), 'reason'); });
t0('V21-REC-01', 'Reconciliation: every money check passes on the full test data set (closing, payroll, advances, OT, purchases, allocations, stock, expenses, GL)', function () use ($own) {
    $r = f3_reconcile($own); $bad = array_filter($r['checks'], fn($c) => !$c['ok']); $GLOBALS['V21_REC'] = $r;
    if ($bad) throw new RuntimeException(implode(' | ', array_map(fn($c) => $c['id'] . ': ' . implode('; ', $c['examples']), $bad)));
    return eq($r['failed'], 0) && eq(count($r['checks']) >= 17, true); });
t('V21-REC-02', 'Reconciliation detects a planted error (a closing sale posted twice) — then the plant is rolled back', function () use ($own) {
    $c = row("SELECT * FROM f3_closings WHERE status IN ('submitted','approved') AND sales_post_status='posted' AND COALESCE(cash_sale,0)>0 LIMIT 1"); if (!$c) return 'no posted closing';
    run("INSERT INTO dd_bk_txns(date,type,category,total,paid,account,import_key,source) VALUES(?,'sale','Sales',?,?,'Cash',?,'f360')", $c['business_date'], (int)$c['cash_sale'], (int)$c['cash_sale'], F3_SALE_KEY . $c['business_date'] . ':cash:dup');
    $r = array_column(f3_reconcile($own)['checks'], null, 'id'); db()->rollBack(); db()->beginTransaction();
    return eq($r['CL-2']['ok'], false) && eq(str_contains($r['CL-2']['examples'][0], $c['business_date']), true); });
t('V21-REC-03', 'Planted payroll over-payment and double OT are detected', function () use ($own) {
    $l = row("SELECT l.* FROM f3_pay_lines l JOIN f3_pay_periods p ON p.period=l.period WHERE p.status IN ('approved','locked') AND l.state='done' AND l.net_payable>0 LIMIT 1"); if (!$l) return 'no approved line';
    run("INSERT INTO f3_pay_payments(pay_no,period,employee_id,date,amount,mode) VALUES('PLANT-1',?,?,'2026-11-01',?,'Cash')", $l['period'], $l['employee_id'], (int)$l['net_payable'] + (int)$l['carried_forward'] + 100);
    run("UPDATE f3_staff_txns SET applied_period='2026-10' WHERE kind='overtime' LIMIT 1");
    $r = array_column(f3_reconcile($own)['checks'], null, 'id'); db()->rollBack(); db()->beginTransaction();
    return eq($r['PY-2']['ok'], false) && eq($r['PY-1']['ok'], false) && (row("SELECT 1 FROM f3_staff_txns WHERE kind='overtime'") ? eq($r['PY-5']['ok'], false) : true); });
t('V21-EXP-01', 'Expense with several photos + a PDF; same receipt re-uploaded on another expense is flagged; edit can remove one photo', function () use ($own) {
    $date = f3_business_date(); $pdf = base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $p1 = $GLOBALS['mkpng'](); $p2 = $GLOBALS['mkpng']();
    $r = f3_expense_save(['date' => $date, 'category' => 'Vegetable', 'amount' => '777', 'mode' => 'Cash', 'description' => 'V21 multi', 'files' => [['data' => $p1, 'name' => 'a.png'], ['data' => $p2, 'name' => 'b.png'], ['data' => $pdf, 'name' => 'c.pdf']]], $own);
    $x = f3_expense_list(['id' => $r['id']], $own)['rows'][0]; eq(count($x['files']), 3, 'files');
    $caught = false; try { f3_expense_save(['date' => $date, 'category' => 'Grocery', 'amount' => '55', 'mode' => 'Cash', 'files' => [['data' => $p1, 'name' => 'again.png']]], $own); } catch (F3Conflict $e) { $caught = ($e->extra['reason'] ?? '') === 'same_receipt' && $e->extra['duplicate'] === $r['id']; }
    eq($caught, true, 'same receipt not flagged');
    f3_expense_save(['id' => $r['id'], 'date' => $date, 'category' => 'Vegetable', 'amount' => '777', 'mode' => 'Cash', 'description' => 'V21 multi', 'files_remove' => [$x['files'][0]['id']]], $own);
    return eq(count(f3_expense_list(['id' => $r['id']], $own)['rows'][0]['files']), 2, 'after remove'); });
t('V21-STK-02', 'Daily consumption: many items in one save, each a normal stock issue; retry with the same key never issues twice; one bad line saves nothing', function () use ($own) {
    $its = rows("SELECT b.item_id,b.qty FROM f3_stock_balances b JOIN dd_bk_items i ON i.id=b.item_id WHERE b.location='Main Store' AND b.qty>=2 AND i.is_stock=1 ORDER BY b.item_id LIMIT 2"); if (count($its) < 2) return 'need 2 items in stock';
    $before = array_map(fn($i) => (float)$i['qty'], $its); $key = 'cons' . bin2hex(random_bytes(5)); $lines = array_map(fn($i) => ['item_id' => (int)$i['item_id'], 'qty' => '1'], $its);
    $date = f3_business_date(); $r = f3_stock_consume(['client_key' => $key, 'date' => $date, 'lines' => $lines], $own); eq($r['moves'], 2);
    f3_stock_consume(['client_key' => $key, 'date' => $date, 'lines' => $lines], $own); // retry
    foreach ($its as $k => $i) eq((float)row("SELECT qty FROM f3_stock_balances WHERE item_id=? AND location='Main Store'", $i['item_id'])['qty'], $before[$k] - 1, 'qty item ' . $i['item_id']);
    $n = (int)row('SELECT COUNT(*) n FROM f3_stock_moves')['n'];
    throws(fn() => f3_stock_consume(['client_key' => 'cons' . bin2hex(random_bytes(5)), 'date' => $date, 'lines' => [['item_id' => (int)$its[0]['item_id'], 'qty' => '1'], ['item_id' => 999999, 'qty' => '1']]], $own), '');
    db()->rollBack(); db()->beginTransaction(); return true; });
t0('V21-OVR-01', 'Owner overview lists only what each user may open; every item links to a working screen', function () {
    $o = f3_overview(U('owner@staging.test')); $c = f3_overview(U('counter@staging.test'));
    eq($o['checked'] > $c['checked'], true, 'counter sees less'); foreach ($c['items'] as $i) eq(in_array($i['key'], ['closing', 'cash_short'], true), true, 'counter saw ' . $i['key']);
    foreach ($o['items'] as $i) eq((bool)preg_match('~^#/[a-z-]+~', $i['link']), true, 'link ' . $i['link']); return true; });
t('V21-GL-01', 'Advance recovery: nothing posted while the ledger is off; when on, one no-cash journal Dr Salary / Cr Employee Advances (idempotent); unlock reverses it; GL-4 catches a missing journal', function () use ($own) {
    $p = row("SELECT applied_period p,SUM(amount) a FROM f3_staff_txns WHERE kind='recovery' AND status='approved' AND applied_period IS NOT NULL GROUP BY applied_period LIMIT 1"); if (!$p) throw new RuntimeException('no approved recovery in test data');
    eq((bool)row("SELECT 1 FROM dd_gl_journals WHERE source_key LIKE ?", 'f3:payrec:%'), false, 'posted while ledger is off');
    set_setting('unify_from', '2026-10-01'); f3_payroll_gl_recovery($p['p'], $own); // back-fill, as Update ledger does
    $j = row("SELECT * FROM dd_gl_journals WHERE source_key LIKE ? AND status='posted'", 'f3:payrec:' . $p['p'] . ':%'); eq((bool)$j, true, 'journal missing');
    $l = rows('SELECT a.code,SUM(l.debit) d,SUM(l.credit) c FROM dd_gl_lines l JOIN dd_gl_accounts a ON a.id=l.account_id WHERE l.journal_id=? GROUP BY a.code ORDER BY a.code', $j['id']);
    eq(array_map(fn($x) => [$x['code'], (int)$x['d'], (int)$x['c']], $l), [['1300', 0, (int)$p['a']], ['6000', (int)$p['a'], 0]], 'lines');
    eq(f3_payroll_gl_recovery($p['p'], $own), (int)$j['id'], 'not idempotent');
    f3_payroll_gl_recovery_reverse($p['p'], 'test', $own); eq(row('SELECT status FROM dd_gl_journals WHERE id=?', $j['id'])['status'], 'reversed');
    $r = array_column(f3_reconcile($own)['checks'], null, 'id'); db()->rollBack(); db()->beginTransaction();
    return eq($r['GL-4']['ok'], false); });
t('V21-GL-02', 'Ledger switched on (post cash book from 1 Oct): Update ledger posts the Finance 360 entries; trial balance and balance sheet balance; Employee Advances in the ledger = staff ledger; possible duplicates are held for a decision', function () use ($own) {
    set_setting('unify_from', '2026-10-01'); $s = f3_gl_sync($own); eq($s['posted']['posted'] > 100, true, 'posted ' . json_encode($s['posted']));
    $r = array_column(f3_reconcile($own)['checks'], null, 'id');
    foreach (['GL-1', 'GL-2', 'GL-4', 'GL-5'] as $k) eq($r[$k]['ok'], true, $k . ' ' . json_encode($r[$k]['examples']));
    eq($r['GL-3']['issues'] === 0 || $s['held'] > 0, true, 'held');
    $st = gl_statements('2026-10-01', '2026-11-02'); eq($st['balance_sheet']['balanced'], true, 'BS');
    db()->rollBack(); db()->beginTransaction(); return true; });
