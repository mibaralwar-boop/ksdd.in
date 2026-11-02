#!/usr/bin/env bash
# V34: platform suite in V31 single-database mode. Builds the KSDD-like legacy database, converts it with the REAL
# tools/v31-convert.php (platform tables + founding tenant + scoped views), then runs tests/platform_tests_v31.php.
set -euo pipefail
cd "$(dirname "$0")/.."
DB=ksdd_test_legacy; TMP="${TMPDIR:-/tmp}"
mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
base="'db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'$DB','db_user'=>'f360','db_password'=>'stagingpw','setup_key'=>'TEST-ONLY-setup-key-0123456789abcdef','pii_key'=>'TEST-ONLY-pii-key-0123456789abcdef0123','restaurant'=>'Khadak Singh Da Dhaba','f3_allow_fake_clock'=>true"
echo "<?php return [$base];" > "$TMP/f360_v31_legacy_cfg.php"
echo "<?php return [$base,'platform'=>['enabled'=>true,'key'=>'TEST-ONLY-platform-key-0123456789abcdef0123','base_url'=>'http://127.0.0.1:8099','mail'=>['mode'=>'log'],'billing'=>['mode'=>'test','offline_test'=>true,'webhook_secret'=>'whsec_test_0123456789']]];" > "$TMP/f360_v31_pre_cfg.php"
F360_CONFIG="$TMP/f360_v31_legacy_cfg.php" php tests/staging_setup.php > /dev/null
F360_CONFIG="$TMP/f360_v31_legacy_cfg.php" php -r 'require "lib.php"; require "f360/admin.php"; f3_migrate_all(null);' > /dev/null
F360_CONFIG="$TMP/f360_v31_pre_cfg.php" php tools/v31-convert.php --apply --i-have-a-backup > "$TMP/f360_v31_convert.out" 2>&1 || { cat "$TMP/f360_v31_convert.out"; exit 1; }
grep -E "Converted|Registered|MISMATCH" "$TMP/f360_v31_convert.out"
TMPDIR="$TMP" php tests/platform_tests_v31.php
