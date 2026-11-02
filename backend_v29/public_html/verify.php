<?php
declare(strict_types=1);
// Email verification → provisions the restaurant (resumable) → asks the owner to sign in.
require __DIR__ . '/platform/ui.php';
ui_boot();
$state = 'error'; $msg = ''; $tenant = null;
try {
    $u = p_verify_email((string)($_GET['token'] ?? ''));
    $reg = prow("SELECT * FROM p_registrations WHERE user_id=? AND status IN ('draft','verified','failed','provisioning') ORDER BY id DESC LIMIT 1", $u['id']);
    if ($reg) { prun("UPDATE p_registrations SET status='verified' WHERE id=? AND status='draft'", $reg['id']); $tenant = reg_provision($reg['uid']); $state = 'ready'; }
    else $state = 'verified';
} catch (Throwable $e) { $msg = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'We could not finish setting up your restaurant yet.'; if (!$e instanceof RuntimeException || $e instanceof PDOException) error_log('verify: ' . $e->getMessage()); }
ui_head(tr('Email verification', 'ईमेल सत्यापन'), ['narrow' => true, 'home' => 'site.php']);
if ($state === 'ready') { $ent = billing_entitlement((int)$tenant['id']);
    echo ui_card(tr('Your restaurant is ready', 'आपका रेस्टोरेंट तैयार है'), '<p><b>' . pe($tenant['name']) . '</b> ' . pe(tr('has been set up.', 'सेट हो गया है।')) . '</p>' . ($ent['kind'] === 'trial' && $ent['period_end'] ? '<p>' . pe(tr('Free trial ends', 'मुफ़्त ट्रायल ख़त्म')) . ': <b>' . pe(p_ist($ent['period_end'])) . '</b>. ' . pe(tr('No payment is taken during the trial.', 'ट्रायल में कोई भुगतान नहीं।')) . '</p>' : '<p>' . pe(tr('This owner account already used its free trial. Choose a plan in Billing to start entering data.', 'इस खाते का ट्रायल पहले ही उपयोग हो चुका है।')) . '</p>') . '<a class="btn primary full" href="signin.php">' . pe(tr('Sign in to continue', 'आगे बढ़ने के लिए साइन इन करें')) . '</a>');
} elseif ($state === 'verified') echo ui_card(tr('Email verified', 'ईमेल सत्यापित'), '<a class="btn primary full" href="signin.php">' . pe(tr('Sign in', 'साइन इन')) . '</a>');
else echo ui_card(tr('Not finished yet', 'अभी पूरा नहीं हुआ'), '<p>' . pe($msg) . '</p><p class="muted">' . pe(tr('Your registration is saved. If your email is verified, sign in — you will see "Finish setup" to try again.', 'आपका रजिस्ट्रेशन सेव है। साइन इन करें और "सेटअप पूरा करें" दबाएँ।')) . '</p><a class="btn ghost" href="forgot.php">' . pe(tr('Get a new link', 'नया लिंक पाएँ')) . '</a>');
ui_foot();
