<?php
declare(strict_types=1);
// Finance 360 V29 public website. Prices come from the database (Super Admin → Plans); nothing is hard-coded.
// No testimonials, no invented numbers. Terms/Privacy are plain-language drafts that MUST be reviewed by a lawyer before launch.
require __DIR__ . '/platform/ui.php';
ui_boot();
$p = preg_replace('/[^a-z]/', '', (string)($_GET['p'] ?? 'home')) ?: 'home';
$nav = '<a href="site.php?p=features">' . tr('Features', 'सुविधाएँ') . '</a><a href="site.php?p=pricing">' . tr('Pricing', 'कीमत') . '</a><a href="site.php?p=faq">FAQ</a><a href="signin.php">' . tr('Sign in', 'साइन इन') . '</a>';
$open = p_signup_open();
$cta = $open ? '<a class="btn primary" href="register.php">' . pe(tr('Start 30-day free trial', '30 दिन का मुफ़्त ट्रायल शुरू करें')) . '</a>' : '<span class="btn ghost" aria-disabled="true">' . pe(tr('Registration opens soon', 'रजिस्ट्रेशन जल्द शुरू होगा')) . '</span>';
ui_post(function () {
    $email = p_email($_POST['email'] ?? ''); $msg = txt($_POST['message'] ?? '', 2000); if (mb_strlen($msg) < 10) fail('Please write a short message.');
    p_rate('contact:' . p_ip_hash(), 5, 3600);
    $to = (string)(pcfg()['support_email'] ?? ''); if ($to === '') fail('Support email is not configured yet. Please email us directly.');
    p_queue('contact', 'contact:' . p_sha($email . $msg), 'email', $to, 'Finance 360 support request from ' . $email, '<p>From: ' . pe($email) . ' · ' . pe(txt($_POST['name'] ?? '', 120)) . '</p><pre>' . pe($msg) . '</pre>');
    ui_flash('ok', 'Thank you. We will reply by email.');
}, 'site.php?p=contact');
$titles = ['home' => 'Restaurant finance, simply', 'features' => 'Features', 'pricing' => 'Pricing', 'faq' => 'Questions', 'contact' => 'Support', 'terms' => 'Terms of Service', 'privacy' => 'Privacy Policy'];
if (!isset($titles[$p])) { http_response_code(404); $p = 'home'; }
ui_head($titles[$p], ['nav' => $nav, 'index' => in_array($p, ['home', 'features', 'pricing', 'faq'], true), 'home' => 'site.php']);
$days = (int)p_setting('trial_days');
switch ($p) {
case 'home':
    echo '<section class="hero"><h1>' . pe(tr('Daily cash closing, salary, purchases and stock — in one place.', 'रोज़ का कैश क्लोज़िंग, सैलरी, ख़रीद और स्टॉक — एक ही जगह।')) . '</h1><p>' . pe(tr('Finance 360 is the back-office money book for restaurants: what came in, what went out, who is owed, and what is in the store.', 'Finance 360 रेस्टोरेंट का हिसाब-किताब है: कितना आया, कितना गया, किसका बकाया है और स्टोर में क्या है।')) . '</p>' . $cta . ' <a class="btn ghost" href="site.php?p=features">' . pe(tr('See features', 'सुविधाएँ देखें')) . '</a></section>';
    echo '<div class="grid g3">' . ui_card(tr('Morning closing in minutes', 'मिनटों में मॉर्निंग क्लोज़िंग'), '<p>' . pe(tr('Cash, card and UPI sale, expenses, actual cash in drawer. Cash Acess / Cash Short shown clearly. Add a photo of your daybook if you like.', 'कैश, कार्ड, UPI बिक्री, ख़र्च और दराज़ का असली कैश। Cash Acess / Cash Short साफ़ दिखता है। चाहें तो डे-बुक की फ़ोटो जोड़ें।')) . '</p>')
       . ui_card(tr('Salary by department', 'विभाग अनुसार सैलरी'), '<p>' . pe(tr('Management, UT, Kitchen, Waiter. Six fields per person, month-length divisor (28–31 days), advances and penalties, resume where you left off.', 'मैनेजमेंट, यूटी, किचन, वेटर। हर व्यक्ति के छह खाने, महीने के दिनों से भाग, एडवांस व जुर्माना।')) . '</p>')
       . ui_card(tr('Suppliers and stock', 'सप्लायर और स्टॉक'), '<p>' . pe(tr('Pick a supplier and see what you owe them, their usual items and last rates. Stock in, stock out, counts and reorder hints.', 'सप्लायर चुनें — बकाया, उनकी आम चीज़ें और पिछले रेट दिखेंगे। स्टॉक, गिनती और दोबारा मँगाने के सुझाव।')) . '</p>') . '</div>';
    echo ui_card(tr('How it works', 'कैसे काम करता है'), '<ol class="steps"><li>' . pe(tr('Register your restaurant and verify your email.', 'अपना रेस्टोरेंट रजिस्टर करें और ईमेल सत्यापित करें।')) . '</li><li>' . pe(tr("Use everything free for $days days — no card needed.", "$days दिन तक सब कुछ मुफ़्त — कार्ड की ज़रूरत नहीं।")) . '</li><li>' . pe(tr('Invite your cashier, store keeper and salary manager — each sees only their own screens.', 'कैशियर, स्टोरकीपर और सैलरी मैनेजर को जोड़ें — हर कोई सिर्फ़ अपनी स्क्रीन देखेगा।')) . '</li><li>' . pe(tr('Choose a plan when the trial ends. If you do not, your records stay safe and readable.', 'ट्रायल ख़त्म होने पर प्लान चुनें। न चुनें तो भी आपका रिकॉर्ड सुरक्षित और पढ़ने योग्य रहेगा।')) . '</li></ol>');
    echo '<p class="muted">' . pe(tr('Not a POS. Finance 360 does not take orders, print KOTs or manage tables; it is your accounts back office.', 'यह POS नहीं है। ऑर्डर, KOT या टेबल नहीं — यह आपका हिसाब-किताब है।')) . '</p>';
    break;
case 'features':
    $f = [['Daily cash closing', 'Cash → Card → UPI → expenses → actual cash → review → optional daybook photo → final. No waiting for approval. Locked days are corrected only by an authorised reopen with a reason.'],
          ['Cash handover', 'Counter hands cash to a manager; the manager confirms. It is a transfer, never counted as income.'],
          ['Staff & salary', 'Four departments (Management, UT, Kitchen, Waiter), designations, joining dates, advances, approved penalties, overtime paid separately (never counted twice), salary slips, month-wise history.'],
          ['Purchases & suppliers', 'Supplier-first bills, outstanding before/after, last items and rates, price-change confirmation, other suppliers’ prices per unit, credit / partial payments, returns.'],
          ['Stock', 'Item master with units and pack sizes, receipts, issues, wastage, transfers, physical counts that respect movements made during the count, reorder suggestions, valuation.'],
          ['Accounts & reports', 'Cash book, bank/UPI book, supplier ledger and aging, trial balance, P&L, day-wise and month-wise reports, PDF/Excel exports for authorised users.'],
          ['Branches', 'Each branch keeps its own closing, staff, stock and books. Owners see branch and combined views; staff see only the branch they are given.'],
          ['Access control', 'Every screen and every API checks permissions on the server. A cashier with closing access sees only Closing.'],
          ['Android app', 'The Finance 360 Android app uses the same server, the same rules and the same permissions as the website.']];
    echo '<h1>' . pe(tr('Features', 'सुविधाएँ')) . '</h1><div class="grid g2">'; foreach ($f as [$h, $b]) echo ui_card($h, '<p>' . pe($b) . '</p>'); echo '</div>';
    break;
case 'pricing':
    echo '<h1>' . pe(tr('Pricing', 'कीमत')) . '</h1><div class="banner">' . pe(tr("Every new restaurant gets a $days-day free trial with all features. No payment is taken during the trial.", "हर नए रेस्टोरेंट को $days दिन का मुफ़्त ट्रायल मिलता है। ट्रायल में कोई भुगतान नहीं।")) . '</div>';
    $plans = billing_plans(); $any = false;
    foreach ($plans as $pl) {
        $terms = array_filter($pl['terms'], fn($t) => $t['price_paise'] >= 100); if (!$terms) continue; $any = true;
        $rows = array_map(fn($t) => [pe(['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Six-monthly', 'yearly' => 'Yearly'][$t['term']]), '<b>' . pe(p_rs($t['price_paise'])) . '</b>', pe($t['max_branches'] . ' branch(es), ' . $t['max_users'] . ' users')], $terms);
        echo ui_card($pl['name'], '<p>' . pe((string)$pl['description']) . '</p>' . ui_table(['Term', 'Price', 'Includes'], array_values($rows)));
    }
    if (!$any) echo ui_card('', '<p>' . pe(tr('Plan prices will be published here before paid plans start. Your trial is free.', 'पेड प्लान शुरू होने से पहले कीमतें यहाँ दिखेंगी। ट्रायल मुफ़्त है।')) . '</p>');
    echo '<p class="muted">' . pe(tr('Prices are for the whole restaurant organisation, not per branch. Applicable taxes on the subscription, if any, are shown on the receipt.', 'कीमत पूरे रेस्टोरेंट संगठन के लिए है, हर ब्रांच के लिए नहीं।')) . '</p>' . $cta;
    break;
case 'faq':
    $q = [['Do I need GST details to use Finance 360?', 'No. Purchase entry is internal supplier accounting and does not ask for GST billing fields.'],
          ['What happens when my trial or plan ends?', 'You can still sign in, view and export all your records and pay to renew. New entries are paused until you renew. Nothing is deleted.'],
          ['Can my staff see salaries or profit?', 'Only if you give them that permission. Each person sees only the screens and branches you allow — on the website and in the app.'],
          ['Can I run more than one restaurant or branch?', 'Yes. One owner account can manage several restaurants; each restaurant can have branches according to its plan.'],
          ['Can Finance 360 staff see my accounts?', 'Not by default. Support can view your data only after you approve a time-limited, read-only access request, and every view is logged.'],
          ['Is this a POS?', 'No. Enter your POS totals (cash, card, UPI) at closing; Finance 360 keeps the books.']];
    echo '<h1>FAQ</h1>'; foreach ($q as [$a, $b]) echo '<details class="card"><summary>' . pe($a) . '</summary><p>' . pe($b) . '</p></details>';
    break;
case 'contact':
    echo '<h1>' . pe(tr('Support', 'सहायता')) . '</h1>' . ui_card('', '<form method="post">' . ui_csrf() . ui_field('name', 'Your name', ['maxlength' => 120]) . ui_field('email', 'Email', ['type' => 'email', 'required' => true, 'autocomplete' => 'email']) . ui_field('message', 'How can we help?', ['type' => 'textarea', 'required' => true, 'maxlength' => 2000]) . ui_btn('Send') . '</form>');
    break;
case 'terms':
    echo '<h1>Terms of Service</h1>' . ui_card('', '<p class="muted">Last updated: November 2026.</p><ol><li><b>Service.</b> Finance 360 provides restaurant back-office accounting software. It does not give legal, tax or accounting advice.</li><li><b>Your account.</b> You are responsible for the people you invite and the permissions you give them. Keep passwords private.</li><li><b>Your data.</b> Your restaurant records belong to you. We process them only to provide the service. You can export them at any time while your account exists.</li><li><b>Trial and payment.</b> New restaurants receive a free trial of the length shown at sign-up. Paid plans renew only when you pay; prices are shown before payment. Refunds follow the policy shown at checkout.</li><li><b>Expiry.</b> If a plan ends, your account becomes read-only; records are not deleted because of non-payment.</li><li><b>Acceptable use.</b> Do not misuse the service, attempt to access other customers’ data, or upload unlawful content.</li><li><b>Changes.</b> We will notify account owners of material changes to these terms.</li><li><b>Contact.</b> Use the Support page.</li></ol>');
    break;
case 'privacy':
    echo '<h1>Privacy Policy</h1>' . ui_card('', '<p class="muted">Last updated: November 2026.</p><ul><li><b>What we collect:</b> account details (name, email, mobile), restaurant details, and the business records you enter (sales totals, expenses, staff and salary records, suppliers, stock, documents).</li><li><b>Why:</b> to run the service for you, send account and billing notices, keep the service secure, and meet legal obligations.</li><li><b>Staff data:</b> bank and identity details are stored encrypted and shown only to users you authorise.</li><li><b>Sharing:</b> we do not sell personal data. Service providers (hosting, email, payment processing) process data only on our instructions.</li><li><b>Access by our team:</b> only with your approval for a limited time, logged, read-only.</li><li><b>Retention and deletion:</b> records are kept while your account is active and for any period required by law; the owner can request export or deletion through Support.</li><li><b>Your choices:</b> you can correct your details, export your data and withdraw marketing consent at any time.</li></ul>');
    break;
}
ui_foot();
