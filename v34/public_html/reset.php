<?php
declare(strict_types=1);
require __DIR__ . '/platform/ui.php';
ui_boot();
$tok = (string)($_GET['token'] ?? $_POST['token'] ?? '');
ui_post(function () use ($tok) {
    if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) fail('The two passwords do not match.');
    p_password_reset($tok, (string)$_POST['password']);
    ui_flash('ok', 'Password changed. You were signed out everywhere. Sign in with the new password.');
    return 'signin.php';
}, 'reset.php?token=' . urlencode($tok));
ui_head(tr('New password', 'नया पासवर्ड'), ['narrow' => true, 'home' => 'site.php']);
echo '<h1>' . pe(tr('Choose a new password', 'नया पासवर्ड चुनें')) . '</h1>' . ui_card('', '<form method="post">' . ui_csrf() . '<input type="hidden" name="token" value="' . pe($tok) . '">'
   . ui_field('password', tr('New password (10+ characters, letters and numbers)', 'नया पासवर्ड (10+ अक्षर)'), ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password'])
   . ui_field('password2', tr('Repeat new password', 'पासवर्ड दोबारा'), ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password']) . ui_btn(tr('Save password', 'पासवर्ड सेव करें'), 'primary full') . '</form>');
ui_foot();
