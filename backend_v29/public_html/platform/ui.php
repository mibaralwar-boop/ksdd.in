<?php
declare(strict_types=1);
// Server-rendered UI kit for the public website, owner portal and Super Admin portal.
// Works without JavaScript; CSP-safe (no inline scripts). Colours: Orange #E87520, Black #191919, Cream #FFF8ED.
require_once __DIR__ . '/register.php';

function ui_head(string $title, array $o = []): void {
    $lang = p_lang(); $brand = $o['brand'] ?? null; $GLOBALS['UI_ROOT'] = (string)($o['root'] ?? '');
    echo '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
       . '<meta name="theme-color" content="#191919">' . (!empty($o['index']) ? '' : '<meta name="robots" content="noindex">')
       . '<title>' . pe($title) . ' · Finance 360</title><link rel="icon" href="assets/brand/favicon-32.png"><link rel="stylesheet" href="' . pe($o['root'] ?? '') . 'assets/platform.css?v=29.0.0"></head><body class="' . pe($o['body'] ?? '') . '">';
    echo '<header class="top"><a class="logo" href="' . pe($o['home'] ?? 'index.php') . '">' . ($brand ? '<img src="' . pe($brand['logo']) . '" alt="" width="34" height="34">' : '<span class="mark">F</span>') . '<span><b>' . pe($brand['name'] ?? 'Finance 360') . '</b>' . ($brand ? '<small>Finance 360</small>' : '<small>' . pe(tr('Restaurant financial control', 'रेस्टोरेंट वित्तीय नियंत्रण')) . '</small>') . '</span></a>';
    echo '<nav>' . ($o['nav'] ?? '') . '<a class="lang" href="?' . pe(http_build_query(array_merge($_GET, ['lang' => $lang === 'hi' ? 'en' : 'hi']))) . '">' . ($lang === 'hi' ? 'English' : 'हिन्दी') . '</a></nav></header><main class="wrap' . (!empty($o['narrow']) ? ' narrow' : '') . '">';
    foreach (ui_flash_take() as [$kind, $msg]) echo '<div class="alert ' . pe($kind) . '" role="' . ($kind === 'err' ? 'alert' : 'status') . '">' . pe($msg) . '</div>';
}
function ui_foot(): void {
    $r = pe($GLOBALS['UI_ROOT'] ?? ''); echo '</main><footer class="foot"><a href="' . $r . 'site.php?p=terms">' . pe(tr('Terms', 'शर्तें')) . '</a> · <a href="' . $r . 'site.php?p=privacy">' . pe(tr('Privacy', 'गोपनीयता')) . '</a> · <a href="' . $r . 'site.php?p=contact">' . pe(tr('Support', 'सहायता')) . '</a><span>© ' . date('Y') . ' Finance 360</span></footer></body></html>';
}
function ui_flash(string $kind, string $msg): void { $_SESSION['flash'][] = [$kind, $msg]; }
function ui_flash_take(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return is_array($f) ? $f : []; }
function ui_csrf(): string { return '<input type="hidden" name="csrf" value="' . pe($_SESSION['csrf'] ?? '') . '">'; }
function ui_field(string $name, string $label, array $o = []): string {
    $type = $o['type'] ?? 'text'; $v = $o['value'] ?? ($_POST[$name] ?? ''); $req = !empty($o['required']) ? ' required' : '';
    $attrs = ''; foreach (['maxlength', 'minlength', 'pattern', 'inputmode', 'autocomplete', 'placeholder', 'min', 'max', 'step', 'accept'] as $a) if (isset($o[$a])) $attrs .= ' ' . $a . '="' . pe($o[$a]) . '"';
    $hint = isset($o['hint']) ? '<small>' . pe($o['hint']) . '</small>' : '';
    if ($type === 'select') { $h = '<select name="' . pe($name) . '"' . $req . '>'; foreach ($o['options'] as $k => $l) $h .= '<option value="' . pe($k) . '"' . ((string)$k === (string)$v ? ' selected' : '') . '>' . pe($l) . '</option>'; return '<label class="f"><span>' . pe($label) . '</span>' . $h . '</select>' . $hint . '</label>'; }
    if ($type === 'textarea') return '<label class="f"><span>' . pe($label) . '</span><textarea name="' . pe($name) . '"' . $req . $attrs . '>' . pe($v) . '</textarea>' . $hint . '</label>';
    if ($type === 'checkbox') return '<label class="chk"><input type="checkbox" name="' . pe($name) . '" value="1"' . (!empty($v) ? ' checked' : '') . $req . '> <span>' . ($o['html'] ?? pe($label)) . '</span></label>';
    return '<label class="f"><span>' . pe($label) . '</span><input type="' . pe($type) . '" name="' . pe($name) . '" value="' . ($type === 'password' || $type === 'file' ? '' : pe($v)) . '"' . $req . $attrs . '>' . $hint . '</label>';
}
function ui_btn(string $label, string $cls = 'primary', string $name = '', string $value = ''): string { return '<button class="btn ' . pe($cls) . '"' . ($name ? ' name="' . pe($name) . '" value="' . pe($value) . '"' : '') . '>' . pe($label) . '</button>'; }
function ui_card(string $title, string $body, string $cls = ''): string { return '<section class="card ' . pe($cls) . '">' . ($title !== '' ? '<h2>' . pe($title) . '</h2>' : '') . $body . '</section>'; }
function ui_table(array $head, array $rows, string $empty = 'Nothing here yet.'): string {
    if (!$rows) return '<p class="empty">' . pe($empty) . '</p>';
    $h = '<div class="tw"><table><thead><tr>'; foreach ($head as $c) $h .= '<th>' . pe($c) . '</th>'; $h .= '</tr></thead><tbody>';
    foreach ($rows as $r) { $h .= '<tr>'; foreach ($r as $i => $c) $h .= '<td data-l="' . pe($head[$i] ?? '') . '">' . $c . '</td>'; $h .= '</tr>'; }
    return $h . '</tbody></table></div>';
}
function ui_badge(string $state, ?string $label = null): string {
    $cls = match ($state) { 'active', 'trialing', 'paid', 'processed', 'approved', 'sent', 'logged' => 'ok', 'grace', 'created', 'requested', 'review', 'queued', 'provisioning' => 'warn', default => 'bad' };
    return '<span class="badge ' . $cls . '">' . pe($label ?? (P_STATE_LABELS[$state] ?? ucfirst(str_replace('_', ' ', $state)))) . '</span>';
}
/** POST handler wrapper: CSRF check, run, flash error, redirect (PRG). */
function ui_post(callable $fn, string $redirect): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    try { check_csrf((string)($_POST['csrf'] ?? '')); $r = $fn(); if (is_string($r)) $redirect = $r; }
    catch (Throwable $e) { if (pdb()->inTransaction()) pdb()->rollBack(); $safe = $e instanceof RuntimeException && !$e instanceof PDOException; // never show SQL errors
        ui_flash('err', $safe ? $e->getMessage() : 'Something went wrong. Please try again.');
        if (!$safe) error_log('platform ui: ' . get_class($e) . ' ' . $e->getMessage()); $_SESSION['old_post'] = array_diff_key($_POST, array_flip(['password', 'password2', 'csrf', 'logo'])); }
    header('Location: ' . $redirect, true, 303); exit;
}
function ui_old(): void { if (!empty($_SESSION['old_post']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') { $_POST = $_SESSION['old_post'] + $_POST; } unset($_SESSION['old_post']); }
function ui_file_b64(string $name): ?array { $f = $_FILES[$name] ?? null; if (!$f || ($f['error'] ?? 4) === 4) return null; if ($f['error'] !== 0 || !is_uploaded_file($f['tmp_name'])) fail('Upload failed. Try a smaller image.'); return ['data' => base64_encode((string)file_get_contents($f['tmp_name'])), 'name' => (string)$f['name']]; }
function ui_boot(): void { security_headers(); start_session(); p_lang(); ui_old(); }
