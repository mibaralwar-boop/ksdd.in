<?php
declare(strict_types=1);
// Finance 360 V29 — Register a restaurant (public) / Add another restaurant (signed-in owner).
// Steps on one phone-friendly page: 1 Owner · 2 Restaurant · 3 Logo · 4 First branch · 5 Trial terms · 6 Consent.
// Nothing becomes active until the email is verified and provisioning completes (platform/register.php).
require __DIR__ . '/platform/ui.php';
ui_boot();
$me = p_web_user();
$open = p_signup_open();
ui_post(function () use ($me) {
    $in = $_POST; $in['logo'] = ui_file_b64('logo');
    if ($me) { $r = reg_start($in, $me); reg_provision($r['registration']); ui_flash('ok', 'Your new restaurant is ready.'); return 'choose.php'; }
    if (($in['password'] ?? '') !== ($in['password2'] ?? '')) fail('The two passwords do not match.');
    $r = reg_start($in);
    $_SESSION['reg_email'] = $r['email'];
    return 'register.php?sent=1';
}, 'register.php' . ($me ? '?add=1' : ''));
$days = (int)p_setting('trial_days'); $end = billing_trial_end(p_now(), $days);
ui_head(tr('Register your restaurant', 'अपना रेस्टोरेंट रजिस्टर करें'), ['narrow' => true, 'home' => 'site.php', 'nav' => '<a href="signin.php">' . tr('Sign in', 'साइन इन') . '</a>']);
if (!$open) { echo ui_card(tr('Registration opens soon', 'रजिस्ट्रेशन जल्द शुरू होगा'), '<p>' . pe(tr('New restaurant sign-up is not open yet. Please check back or contact support.', 'नए रेस्टोरेंट का साइन-अप अभी शुरू नहीं हुआ है।')) . '</p><a class="btn ghost" href="site.php?p=contact">' . pe(tr('Contact support', 'सहायता से संपर्क')) . '</a>'); ui_foot(); exit; }
if (isset($_GET['sent'])) {
    echo ui_card(tr('Check your email', 'अपना ईमेल देखें'), '<p>' . pe(tr('We sent a verification link to', 'सत्यापन लिंक भेजा गया')) . ' <b>' . pe($_SESSION['reg_email'] ?? '') . '</b>. ' . pe(tr('Open it to finish setting up your restaurant. The link works for 48 hours.', 'रेस्टोरेंट सेटअप पूरा करने के लिए इसे खोलें। लिंक 48 घंटे चलेगा।')) . '</p><p class="muted">' . pe(tr('Registration status: waiting for email verification. Your restaurant is not active until then.', 'स्थिति: ईमेल सत्यापन बाक़ी। तब तक रेस्टोरेंट सक्रिय नहीं होगा।')) . '</p><a class="btn ghost" href="forgot.php">' . pe(tr('Resend link', 'लिंक दोबारा भेजें')) . '</a>');
    ui_foot(); exit;
}
$f = '<form method="post" enctype="multipart/form-data" novalidate>' . ui_csrf() . '<input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">';
if (!$me) $f .= '<fieldset><legend>1 · ' . pe(tr('Owner', 'मालिक')) . '</legend>' . ui_field('owner_name', tr('Your full name', 'आपका पूरा नाम'), ['required' => true, 'maxlength' => 160, 'autocomplete' => 'name'])
    . ui_field('mobile', tr('Mobile number', 'मोबाइल नंबर'), ['type' => 'tel', 'required' => true, 'inputmode' => 'numeric', 'pattern' => '[6-9][0-9]{9}', 'maxlength' => 13, 'autocomplete' => 'tel', 'hint' => tr('10 digits. Not verified by OTP yet.', '10 अंक।')])
    . ui_field('email', tr('Email (you will verify it)', 'ईमेल (सत्यापित करेंगे)'), ['type' => 'email', 'required' => true, 'maxlength' => 190, 'autocomplete' => 'email'])
    . ui_field('password', tr('Password (10+ characters, letters and numbers)', 'पासवर्ड (10+ अक्षर, अक्षर और अंक)'), ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password'])
    . ui_field('password2', tr('Repeat password', 'पासवर्ड दोबारा'), ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password']) . '</fieldset>';
$f .= '<fieldset><legend>2 · ' . pe(tr('Restaurant', 'रेस्टोरेंट')) . '</legend>' . ui_field('restaurant', tr('Restaurant / business name', 'रेस्टोरेंट का नाम'), ['required' => true, 'maxlength' => 160])
    . ui_field('contact_phone', tr('Restaurant contact number', 'रेस्टोरेंट संपर्क नंबर'), ['type' => 'tel', 'inputmode' => 'numeric', 'maxlength' => 13, 'hint' => tr('Leave empty to use your mobile.', 'ख़ाली छोड़ें तो आपका मोबाइल।')])
    . ui_field('address', tr('Address', 'पता'), ['maxlength' => 300, 'autocomplete' => 'street-address']) . '<div class="grid g3">' . ui_field('city', tr('City', 'शहर'), ['required' => true, 'maxlength' => 80, 'autocomplete' => 'address-level2'])
    . ui_field('state', tr('State', 'राज्य'), ['maxlength' => 80, 'autocomplete' => 'address-level1']) . ui_field('pincode', tr('PIN code', 'पिन कोड'), ['inputmode' => 'numeric', 'pattern' => '[1-9][0-9]{5}', 'maxlength' => 6, 'autocomplete' => 'postal-code']) . '</div></fieldset>';
$f .= '<fieldset><legend>3 · ' . pe(tr('Logo (optional)', 'लोगो (वैकल्पिक)')) . '</legend>' . ui_field('logo', tr('PNG, JPG or WEBP, up to 2 MB', 'PNG, JPG या WEBP, 2 MB तक'), ['type' => 'file', 'accept' => 'image/png,image/jpeg,image/webp', 'hint' => tr('It is resized to 512 px and shown on your screens. You can change it later.', 'बाद में बदल सकते हैं।')]) . '</fieldset>';
$f .= '<fieldset><legend>4 · ' . pe(tr('First branch', 'पहली ब्रांच')) . '</legend>' . ui_field('branch_name', tr('Branch name', 'ब्रांच का नाम'), ['value' => $_POST['branch_name'] ?? 'Main', 'maxlength' => 120])
    . ui_field('cutoff_hour', tr('Business day ends at (hour after midnight)', 'बिज़नेस डे कितने बजे ख़त्म होता है'), ['type' => 'select', 'value' => $_POST['cutoff_hour'] ?? '4', 'options' => array_combine(range(0, 12), array_map(fn($h) => sprintf('%02d:00', $h) . ($h === 4 ? ' (recommended)' : ''), range(0, 12))), 'hint' => tr('Sales made after midnight but before this hour count for the previous day.', 'आधी रात के बाद इस समय तक की बिक्री पिछले दिन में गिनी जाएगी।')]) . '</fieldset>';
$f .= '<fieldset><legend>5 · ' . pe(tr('Free trial', 'मुफ़्त ट्रायल')) . '</legend><p>' . pe(tr("All features free for $days days. If you register today, the trial ends on", "$days दिन सभी सुविधाएँ मुफ़्त। आज रजिस्टर करें तो ट्रायल ख़त्म होगा")) . ' <b>' . pe(p_ist(p_utc($end))) . '</b>. ' . pe(tr('No card or payment is needed. Charges begin only if you choose and pay for a plan.', 'कोई कार्ड या भुगतान नहीं। प्लान चुनकर भुगतान करने पर ही शुल्क लगेगा।')) . '</p><p class="muted">' . pe(tr('One free trial per owner account.', 'हर मालिक खाते पर एक ट्रायल।')) . '</p></fieldset>';
$f .= '<fieldset><legend>6 · ' . pe(tr('Confirm', 'पुष्टि')) . '</legend>' . ui_field('accept_terms', '', ['type' => 'checkbox', 'required' => true, 'html' => pe(tr('I accept the', 'मैं स्वीकार करता/करती हूँ')) . ' <a href="site.php?p=terms" target="_blank">' . pe(tr('Terms', 'शर्तें')) . '</a> ' . pe(tr('and', 'और')) . ' <a href="site.php?p=privacy" target="_blank">' . pe(tr('Privacy Policy', 'गोपनीयता नीति')) . '</a>.']) . '</fieldset>';
$f .= ui_btn($me ? tr('Create restaurant', 'रेस्टोरेंट बनाएँ') : tr('Create my account', 'मेरा खाता बनाएँ'), 'primary full') . '</form>';
echo '<h1>' . pe($me ? tr('Add another restaurant', 'एक और रेस्टोरेंट जोड़ें') : tr('Register your restaurant', 'अपना रेस्टोरेंट रजिस्टर करें')) . '</h1>' . $f;
ui_foot();
