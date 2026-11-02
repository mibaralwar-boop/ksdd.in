<?php
// STAGING/TEST ONLY — used by tests/platform_tests.php via F360_CONFIG. Never deploy. Dummy local credentials.
return [
    'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'ksdd_test_legacy', 'db_user' => 'f360', 'db_password' => 'stagingpw',
    'setup_key' => 'TEST-ONLY-setup-key-0123456789abcdef', 'pii_key' => 'TEST-ONLY-pii-key-0123456789abcdef0123', 'restaurant' => 'Khadak Singh Da Dhaba',
    'f3_allow_fake_clock' => true,
    'platform' => [
        'enabled' => true, 'key' => 'TEST-ONLY-platform-key-0123456789abcdef0123', 'base_url' => 'http://127.0.0.1:8099', 'signup_enabled' => true, 'support_email' => 'support@example.test',
        'db' => ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => 'f360_platform_test', 'db_user' => 'f360', 'db_password' => 'stagingpw'],
        'provision' => ['mode' => 'create', 'create' => ['db_host' => '127.0.0.1', 'db_port' => 3306, 'db_user' => 'f360', 'db_password' => 'stagingpw', 'prefix' => 'f360test_']],
        'mail' => ['mode' => 'log'],
        'billing' => ['provider' => 'razorpay', 'mode' => 'test', 'offline_test' => true, 'key_id' => 'rzp_test_DUMMY', 'key_secret' => 'dummy', 'webhook_secret' => 'whsec_test_0123456789'],
        'admin_mfa_required' => true,
    ],
];
