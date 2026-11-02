<?php
declare(strict_types=1);
// Finance 360 V29 — sign in (restaurant users). Platform admins sign in at platform-admin/.
require __DIR__ . '/platform/ui.php';
ui_boot();
if (isset($_GET['logout'])) { if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') { check_csrf((string)($_POST['csrf'] ?? '')); p_web_logout(); start_session(); ui_flash('ok', 'You are signed out.'); } header('Location: signin.php', true, 303); exit; }
if (p_web_user()) { header('Location: choose.php'); exit; }
ui_post(function () {
    $u = p_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
    p_web_login($u);
    return 'choose.php';
}, 'signin.php');
ui_head(tr('Sign in', 'साइन इन'), ['narrow' => true, 'home' => 'site.php', 'nav' => '<a href="site.php">' . tr('Home', 'होम') . '</a>']);
echo '<h1>' . pe(tr('Sign in', 'साइन इन')) . '</h1>' . ui_card('', '<form method="post" autocomplete="on">' . ui_csrf()
   . ui_field('email', tr('Email', 'ईमेल'), ['type' => 'email', 'required' => true, 'autocomplete' => 'username', 'maxlength' => 190])
   . ui_field('password', tr('Password', 'पासवर्ड'), ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
   . ui_btn(tr('Sign in', 'साइन इन'), 'primary full') . '</form><p class="row"><a href="forgot.php">' . pe(tr('Forgot password?', 'पासवर्ड भूल गए?')) . '</a><span class="sp"></span>' . (p_signup_open() ? '<a href="register.php">' . pe(tr('Register a restaurant', 'रेस्टोरेंट रजिस्टर करें')) . '</a>' : '') . '</p>');
ui_foot();
