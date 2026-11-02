<?php
// Finance 360 V30 — ksdd.in configuration. Copy to config.php IN THIS FOLDER (…/domains/ksdd.in/ksdd_private/config.php),
// which is OUTSIDE public_html and can never be downloaded. Fill in YOUR values. NEVER share config.php or put it in a ZIP.
return [
    // ---- the ONE database: your EXISTING Khadak Singh Da Dhaba database (hPanel → Databases) ----
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'YOUR_EXISTING_KSDD_DATABASE',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_password' => 'YOUR_DATABASE_PASSWORD',

    // Keep your CURRENT values for these two (copy them from the old public_html/config.php). Changing them makes
    // stored bank accounts / PAN / integration secrets unreadable. 32+ random characters each.
    'setup_key' => 'COPY_FROM_OLD_CONFIG',
    'pii_key' => 'COPY_FROM_OLD_CONFIG',
    'restaurant' => 'Khadak Singh Da Dhaba',

    // Daybook / report emails (Hostinger Mail). Use a dedicated mailbox.
    'smtp' => ['host' => 'smtp.hostinger.com', 'port' => 465, 'secure' => 'ssl', 'user' => 'reports@ksdd.in', 'password' => 'MAILBOX_PASSWORD', 'from' => 'reports@ksdd.in', 'from_name' => 'KSDD Daybook'],
    'sync_token_sha256' => '',

    // ---- ROLLOUT STEP 3 only (after tools/tenancy-migrate.php --apply, before the platform is switched on):
    // 'shared_tenant' => ['tenant_id' => 1, 'branch_id' => 1],

    // ---- multi-restaurant platform on the SAME domain and the SAME database (ROLLOUT STEP 4) ----
    'platform' => [
        'enabled' => false,                                   // true only at rollout step 4
        'key' => 'REPLACE_WITH_32_OR_MORE_RANDOM_CHARACTERS',  // encrypts platform secrets; keep an offline copy
        'base_url' => 'https://ksdd.in',                      // ONE website: /register.php, /signin.php, /owner.php, /platform-admin/, /api/v1/
        'support_email' => 'support@ksdd.in',
        'signup_enabled' => false,                            // open self-registration only after the staging checks AND Super Admin → Settings
        'admin_mfa_required' => true,
        // no 'db' block: platform tables (p_*) live in the same database as everything else
        'provision' => ['mode' => 'shared'],                  // V30: new restaurants/branches = rows with their own tenant_id/branch_id, no new databases
        'mail' => ['mode' => 'smtp', 'smtp' => ['host' => 'smtp.hostinger.com', 'port' => 465, 'secure' => 'ssl', 'user' => 'noreply@ksdd.in', 'password' => 'MAILBOX_PASSWORD', 'from' => 'noreply@ksdd.in', 'from_name' => 'Finance 360']],
        // Payments stay OFF until you approve keys, prices, legal/tax details and the webhook (see docs)
        'billing' => ['provider' => 'razorpay', 'mode' => 'disabled', 'key_id' => '', 'key_secret' => '', 'webhook_secret' => '', 'live_approved' => false, 'allow_app_checkout' => false, 'app_may_link_web' => false],
    ],
];
