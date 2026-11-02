<?php
declare(strict_types=1);
// Google OAuth 2.0 redirect target (Settings → Integrations → Connect Google Drive).
// The session cookie is SameSite=Strict, so it is not sent on this cross-site redirect; the request is instead
// authorised by the single-use, 10-minute, hashed state token created when the Owner pressed "Connect".
require __DIR__ . '/lib.php';
require __DIR__ . '/f360/admin.php';
require __DIR__ . '/f360/purchase.php';
require __DIR__ . '/f360/integrations.php';
security_headers();
header('Cache-Control: no-store'); header('Referrer-Policy: no-referrer');
$ok = false; $msg = '';
try {
    f3_ready();
    if (isset($_GET['error'])) throw new F3IntError('Google sign-in was cancelled or refused (' . preg_replace('/[^a-z_]/i', '', (string)$_GET['error']) . ').');
    $code = (string)($_GET['code'] ?? ''); $state = (string)($_GET['state'] ?? '');
    if ($code === '' || !preg_match('/^[a-f0-9]{48}$/', $state)) throw new F3IntError('Invalid sign-in response.');
    $r = transaction(fn() => f3_drive_auth_finish($code, $state)); $ok = true; $msg = 'Google Drive authorised' . ($r['email'] ? ' for ' . $r['email'] : '') . '. Now press "Test connection".';
} catch (Throwable $e) { $msg = $e instanceof F3IntError ? $e->getMessage() : 'Sign-in could not be completed. See Settings → Integrations → Activity.'; try { if (!($e instanceof F3IntError)) f3_int_log('drive', 'connect.fail', 'error', $e->getMessage()); } catch (Throwable $_) {} }
$back = 'index.php#/settings?tab=integrations&drive=' . ($ok ? 'ok' : 'fail');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="3;url=<?= htmlspecialchars($back) ?>"><title>Google Drive</title>
<style>body{font-family:system-ui,sans-serif;background:#FFF8EE;color:#1a1a1a;display:grid;place-items:center;min-height:100vh;margin:0}main{background:#fff;border-radius:16px;padding:28px;max-width:440px;box-shadow:0 6px 24px #0001}h1{font-size:20px;margin:0 0 8px;color:<?= $ok ? '#1d7a3a' : '#b42318' ?>}a{color:#E8651A;font-weight:700}</style></head>
<body><main><h1><?= $ok ? '✓ Connected' : 'Not connected' ?></h1><p><?= htmlspecialchars($msg) ?></p><p><a href="<?= htmlspecialchars($back) ?>">Back to Integrations</a></p></main></body></html>
