<?php
declare(strict_types=1);
require __DIR__ . '/platform/ui.php';
ui_boot();
ui_post(function () {
    p_password_forgot((string)($_POST['email'] ?? '')); // same answer whether or not the account exists
    ui_flash('ok', 'If an account exists for that email, a reset link has been sent. It works for 1 hour.');
    // resend verification for unverified accounts (same neutral answer)
    $u = prow('SELECT * FROM p_users WHERE email=? AND email_verified_at IS NULL', strtolower(trim((string)($_POST['email'] ?? ''))));
    if ($u && !empty($_POST['resend'])) p_send_verification($u);
}, 'forgot.php');
ui_head(tr('Forgot password', 'पासवर्ड भूल गए'), ['narrow' => true, 'home' => 'site.php', 'nav' => '<a href="signin.php">' . tr('Sign in', 'साइन इन') . '</a>']);
echo '<h1>' . pe(tr('Reset your password', 'पासवर्ड रीसेट करें')) . '</h1>' . ui_card('', '<form method="post">' . ui_csrf() . ui_field('email', tr('Email', 'ईमेल'), ['type' => 'email', 'required' => true, 'autocomplete' => 'email'])
   . ui_field('resend', '', ['type' => 'checkbox', 'html' => pe(tr('I never verified my email — resend the verification link too', 'मैंने ईमेल सत्यापित नहीं किया — सत्यापन लिंक भी भेजें'))]) . ui_btn(tr('Send link', 'लिंक भेजें'), 'primary full') . '</form>');
ui_foot();
