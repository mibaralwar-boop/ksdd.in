<?php
declare(strict_types=1);
// Finance 360 V16 JSON API. Sign-in / sign-out / CSRF bootstrap stay on the existing api.php (same session, same throttling).
// Every route checks permissions on the server (f3_need); POSTs need the CSRF header and run inside one DB transaction.
require __DIR__ . '/lib.php';
require __DIR__ . '/f360/dispatch.php';
security_headers();
try {
    start_session();
    $method = $_SERVER['REQUEST_METHOD']; $route = (string)($_GET['route'] ?? '');
    if (!preg_match('/^[a-z0-9-]{2,40}$/', $route)) fail('Not found', 404);
    ['GET' => $GET, 'POST' => $POST] = f3_routes();
    if (!(($method === 'GET' && in_array($route, $GET, true)) || ($method === 'POST' && in_array($route, $POST, true)))) fail(in_array($route, array_merge($GET, $POST), true) ? 'Method not allowed' : 'Not found', in_array($route, array_merge($GET, $POST), true) ? 405 : 404);
    if (!configured() || !installed()) fail('Complete install.php first.', 503);
    if ($method === 'POST') check_csrf();
    if ($route === 'me' && (empty($_SESSION['user_id']) || ($_SESSION['expires'] ?? 0) < time())) send(['auth' => false]); // first load: no console 401
    $u = user();
    f3_ready();
    f3_dispatch($method, $route, $_GET, $method === 'POST' ? require_json() : [], $u);
} catch (Throwable $e) {
    try { if (configured() && db()->inTransaction()) db()->rollBack(); } catch (Throwable $ignored) {}
    if ($e instanceof F3Conflict) send(['error' => $e->getMessage()] + $e->extra, 409);
    if ($e instanceof RuntimeException && $e->getCode() === 422) send(['error' => $e->getMessage()], 422);
    error_response($e);
}
