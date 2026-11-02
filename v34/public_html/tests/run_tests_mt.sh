#!/usr/bin/env bash
# V34: legacy finance suite on a database CONVERTED by tools/v31-convert.php (single-database mode, scoped views).
# STAGING/TEST only. Usage: tests/run_tests_mt.sh   (MariaDB user f360/stagingpw, database ksdd_mt_test)
set -euo pipefail
cd "$(dirname "$0")/.."
T="$(mktemp -d)"; DB="${F360_TEST_DB:-ksdd_mt_test}"
mysql -e "CREATE DATABASE IF NOT EXISTS $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
base="'db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'$DB','db_user'=>'f360','db_password'=>'stagingpw','setup_key'=>'TEST-ONLY-setup-key-0123456789abcdef','pii_key'=>'TEST-ONLY-pii-key-0123456789abcdef0123','restaurant'=>'Khadak Singh Da Dhaba','f3_allow_fake_clock'=>true"
echo "<?php return [$base];" > "$T/plain.php"
echo "<?php return [$base,'single_db'=>true,'single_db_scope'=>['tenant_id'=>1,'branch_id'=>1,'branch_uid'=>'']];" > "$T/mt.php"
F360_CONFIG="$T/plain.php" php tests/staging_setup.php >/dev/null
F360_CONFIG="$T/plain.php" php tools/v31-convert.php --apply --i-have-a-backup --no-platform --tenant=1 --branch=1 | grep -E "Converted|MISMATCH|STOP" || true
F360_CONFIG="$T/mt.php" F360_SKIP_SETUP=1 php tests/run_tests.php
