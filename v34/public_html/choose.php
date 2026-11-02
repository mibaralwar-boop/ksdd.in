<?php
declare(strict_types=1);
// After sign-in: pick a restaurant + branch from the account's OWN memberships. The server re-checks the choice.
require __DIR__ . '/platform/ui.php';
ui_boot();
$me = p_web_user(); if (!$me) { header('Location: signin.php'); exit; }
ui_post(function () use ($me) {
    if (isset($_POST['finish'])) { $r = prow("SELECT uid FROM p_registrations WHERE uid=? AND user_id=? AND status<>'complete'", (string)$_POST['finish'], $me['id']) ?? fail('Registration not found.', 404); reg_provision($r['uid']); ui_flash('ok', 'Setup finished.'); return 'choose.php'; }
    tenancy_session_enter($me, (int)($_POST['tenant'] ?? 0), (int)($_POST['branch'] ?? 0));
    return 'index.php';
}, 'choose.php');
$mem = tenancy_memberships((int)$me['id']);
$pending = prows("SELECT uid,status,error,data_json FROM p_registrations WHERE user_id=? AND status IN ('verified','provisioning','failed')", $me['id']);
$all = []; foreach ($mem as $t) foreach ($t['branches'] as $b) $all[] = [$t, $b];
if (count($all) === 1 && !$pending && !isset($_GET['stay'])) { tenancy_session_enter($me, $all[0][0]['id'], $all[0][1]['id']); header('Location: index.php'); exit; }
$isOwner = (bool)array_filter($mem, fn($t) => in_array($t['role'], ['owner', 'admin'], true));
ui_head(tr('Choose restaurant', 'रेस्टोरेंट चुनें'), ['home' => 'choose.php', 'nav' => ($isOwner ? '<a href="owner.php">' . tr('Owner portal', 'मालिक पोर्टल') . '</a>' : '') . '<form method="post" action="signin.php?logout=1" style="display:inline">' . ui_csrf() . '<button class="btn sm ghost">' . pe(tr('Sign out', 'साइन आउट')) . '</button></form>']);
echo '<h1>' . pe(tr('Namaste', 'नमस्ते')) . ', ' . pe($me['name']) . '</h1>';
foreach ($pending as $r) { $d = json_decode((string)$r['data_json'], true); echo '<div class="banner"><b>' . pe($d['restaurant'] ?? '') . '</b> — ' . pe(tr('setup not finished', 'सेटअप अधूरा')) . ($r['error'] ? ': ' . pe($r['error']) : '') . '<form method="post" class="row" style="margin-top:8px">' . ui_csrf() . ui_btn(tr('Finish setup', 'सेटअप पूरा करें'), 'primary sm', 'finish', $r['uid']) . '</form></div>'; }
if (!$mem && !$pending) echo ui_card('', '<p>' . pe(tr('Your account is not linked to any restaurant yet. Ask the owner to invite you, or register your own restaurant.', 'आपका खाता किसी रेस्टोरेंट से जुड़ा नहीं है।')) . '</p>' . (p_signup_open() ? '<a class="btn primary" href="register.php">' . pe(tr('Register a restaurant', 'रेस्टोरेंट रजिस्टर करें')) . '</a>' : ''));
foreach ($mem as $t) {
    $s = $t['subscription']; $h = '<p class="row">' . ui_badge($s['state'], $s['label']) . ($s['period_end'] ? ' <span class="muted">' . pe(($s['state'] === 'trialing' ? tr('Trial ends ', 'ट्रायल ख़त्म ') : tr('Valid till ', 'वैध ')) . $s['period_end_ist']) . '</span>' : '') . '</p>';
    if ($s['read_only']) $h .= '<div class="banner bad">' . pe(tr('Read-only: you can view and export records. New entries need an active plan.', 'केवल पढ़ने योग्य: रिकॉर्ड देख और निर्यात कर सकते हैं।')) . '</div>';
    $h .= '<div class="grid g3">';
    foreach ($t['branches'] as $b) $h .= '<form method="post" class="card branch">' . ui_csrf() . '<input type="hidden" name="tenant" value="' . $t['id'] . '"><input type="hidden" name="branch" value="' . $b['id'] . '"><h2>' . pe($b['name']) . '</h2><p class="muted">' . pe($b['city'] ?? '') . '</p>' . ui_btn(tr('Open', 'खोलें'), 'primary full') . '</form>';
    $h .= '</div>' . (!$t['branches'] ? '<p class="empty">' . pe(tr('No branch access yet.', 'अभी किसी ब्रांच की अनुमति नहीं।')) . '</p>' : '');
    echo ui_card($t['name'], $h);
}
ui_foot();
