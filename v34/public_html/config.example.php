<?php
// KSDD Finance 360 V31 — copy to config.php with Hostinger File Manager and fill in YOUR values. NEVER share config.php or put it in a ZIP.
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'YOUR_HOSTINGER_DATABASE_NAME',   // V31: the ONE database for every restaurant (staging uses its own separate database)
    'db_user' => 'YOUR_HOSTINGER_DATABASE_USER',
    'db_password' => 'YOUR_DATABASE_PASSWORD',
    // Proof of ownership for install.php / reset-owner.php. 32+ random characters. Keep it: if 'pii_key' is NOT set,
    // this key is ALSO what protects stored bank accounts, PAN, Drive/AiSensy secrets and WhatsApp numbers.
    'setup_key' => 'REPLACE_WITH_32_OR_MORE_RANDOM_CHARACTERS',
    // Encryption key for personal data (AES-256-GCM). 32+ random characters. Set ONCE and keep a secure offline copy.
    // V23: adding a new pii_key never breaks old data — values encrypted earlier with setup_key (or with 'pii_key_previous')
    // are still read. Move them to the new key with:  php tools/f360-rekey.php  (dry run)  then  --apply --i-have-a-backup.
    // Only remove an old key after the dry run reports legacy=0 everywhere.
    'pii_key' => 'REPLACE_WITH_ANOTHER_32_OR_MORE_RANDOM_CHARACTERS',
    // 'pii_key_previous' => 'ONLY_WHEN_ROTATING: the old pii_key until tools/f360-rekey.php reports legacy=0',
    'restaurant' => 'Khadak Singh Da Dhaba',
    // Daybook closing emails (Hostinger Mail: smtp.hostinger.com, port 465, SSL). Use a dedicated mailbox.
    'smtp' => [
        'host' => 'smtp.hostinger.com', 'port' => 465, 'secure' => 'ssl',
        'user' => 'reports@ksdd.in', 'password' => 'MAILBOX_PASSWORD',
        'from' => 'reports@ksdd.in', 'from_name' => 'KSDD Daybook',
    ],
    // Windows PC Petpooja upload (sync.php). Store ONLY the SHA-256 of the token here, never the token itself.
    'sync_token_sha256' => '',
    // V31: 'staging' on staging.ksdd.in → integrations (Drive / AiSensy / FCM / payments) never call live services.
    'environment' => 'production',   // 'production' | 'staging'
    // V31 single database. Set by tools/v31-convert.php AFTER it converts the tables (it prints the exact lines):
    'single_db' => false,
    // 'single_db_scope' => ['tenant_id' => 1, 'branch_id' => 1, 'branch_uid' => '...'],   // founding restaurant, used only if 'platform' is disabled
    // STAGING ONLY (never on live — ignored unless db_name contains "staging" or "test"):
    // 'f3_allow_fake_clock' => true,
    // 'int_endpoints_override' => ['google_token' => 'http://127.0.0.1:8399/token', ...],   // mock server for tests

    // ---------------- Multi-restaurant platform (V29 portal, V31 single database) ----------------
    // Platform tables (p_*) live in the SAME database as above. There is no per-branch database any more.
    // 'sync_branch_uid' => '',   // branch uid printed by tools/v31-convert.php, only if the Petpooja PC upload (sync.php) is used
    'platform' => [
        'enabled' => false,
        'key' => 'REPLACE_WITH_ANOTHER_32_OR_MORE_RANDOM_CHARACTERS',   // encrypts platform secrets (TOTP, tokens); keep an offline copy
        'base_url' => 'https://ksdd.in',                                // used in email links
        'support_email' => 'support@ksdd.in',
        'signup_enabled' => false,                                      // ALSO needs Super Admin → Settings; open only after the V31 isolation gate passes on staging
        'admin_mfa_required' => true,
        'mail' => ['mode' => 'smtp', 'smtp' => ['host' => 'smtp.hostinger.com', 'port' => 465, 'secure' => 'ssl', 'user' => 'noreply@your-domain.example', 'password' => 'MAILBOX_PASSWORD', 'from' => 'noreply@your-domain.example', 'from_name' => 'Finance 360']],
        // Payments stay OFF until you approve keys, prices, legal/tax details and the webhook (docs/SUBSCRIPTIONS_AND_BILLING.md)
        'billing' => ['provider' => 'razorpay', 'mode' => 'disabled', 'key_id' => '', 'key_secret' => '', 'webhook_secret' => '', 'live_approved' => false, 'allow_app_checkout' => false, 'app_may_link_web' => false],
    ],
];
