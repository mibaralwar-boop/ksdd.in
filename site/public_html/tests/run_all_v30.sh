#!/usr/bin/env bash
# Finance 360 V30 — complete local test run (STAGING/TEST databases only; MariaDB user f360/stagingpw).
# Every suite is run on a fresh copy of this folder so test artefacts never touch the source tree.
set -uo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"; OUT="${1:-$(mktemp -d)}"; mkdir -p "$OUT"
run() { local name="$1"; shift; local W="$OUT/$name"; rm -rf "$W"; cp -r "$HERE" "$W"; rm -rf "$W/private"
  ( cd "$W" && "$@" ) > "$OUT/$name.log" 2>&1; local rc=$?
  printf '%-34s %s\n' "$name" "$(grep -E '^RESULT' "$OUT/$name.log" | tail -1 || echo "rc=$rc (see $OUT/$name.log)")"; }
LEG="$OUT/legacy_cfg.php"; LEGS="$OUT/legacy_shared_cfg.php"
mysql -e "CREATE DATABASE IF NOT EXISTS ksdd_v30_legacy_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE IF NOT EXISTS ts_unit_test"
base="'db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'ksdd_v30_legacy_test','db_user'=>'f360','db_password'=>'stagingpw','setup_key'=>'TEST-ONLY-setup-key-0123456789abcdef','pii_key'=>'TEST-ONLY-pii-key-0123456789abcdef0123','restaurant'=>'Khadak Singh Da Dhaba','f3_allow_fake_clock'=>true"
echo "<?php return [$base];" > "$LEG"; echo "<?php return [$base,'shared_tenant'=>['tenant_id'=>1,'branch_id'=>1]];" > "$LEGS"
echo "Finance 360 V30 test run — $(date -u '+%Y-%m-%d %H:%M UTC') — logs in $OUT"
run scoper_unit           env TS_DSN="mysql:host=127.0.0.1;dbname=ts_unit_test" TS_USER=f360 TS_PASS=stagingpw php tests/tenant_scope_tests.php
run legacy_shared_db      env F360_CONFIG="$LEGS" php tests/run_tests.php
run legacy_single_db      env F360_CONFIG="$LEG" php tests/run_tests.php
run platform_shared_db    sh -c 'F360_PLATFORM_TEST_CFG=tests/platform_test_config_shared.php php tests/platform_tests.php && php tests/isolation_sweep.php | tee isolation.log >/dev/null; tail -1 isolation.log | sed "s/^/ISOLATION /"'
grep -E '^RESULT' "$OUT/platform_shared_db/isolation.log" | sed 's/^/isolation_sweep                    /'
run platform_db_per_branch php tests/platform_tests.php
run rollout_rehearsal     env KSDD_PRIVATE_SRC="$HERE/../ksdd_private" bash tests/rollout_rehearsal.sh ksdd_v30_legacy_test
