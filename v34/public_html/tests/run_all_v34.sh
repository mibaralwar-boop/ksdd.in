#!/usr/bin/env bash
# Finance 360 V34 — complete local test run (STAGING/TEST databases only; MariaDB user f360/stagingpw; Apache for the
# hosting rehearsal). Every suite runs on a fresh copy of this folder. Usage: tests/run_all_v34.sh [log_dir]
set -uo pipefail
HERE="$(cd "$(dirname "$0")/.." && pwd)"; OUT="${1:-$(mktemp -d)}"; mkdir -p "$OUT"
run() { local name="$1"; shift; local W="$OUT/$name"; rm -rf "$W"; cp -r "$HERE" "$W"; rm -rf "$W/private"
  ( cd "$W" && "$@" ) > "$OUT/$name.log" 2>&1; local rc=$?
  printf '%-30s %s\n' "$name" "$(grep -E '^RESULT' "$OUT/$name.log" | tail -1 || true) $( [ $rc = 0 ] || echo "(exit $rc — see $OUT/$name.log)")"; }
echo "Finance 360 V34 test run — $(date -u '+%Y-%m-%d %H:%M UTC') — logs in $OUT"
n=0; bad=0; for f in $(cd "$HERE" && find . -name '*.php' -not -path './tests/*'); do n=$((n+1)); php -l "$HERE/$f" >/dev/null 2>&1 || { bad=$((bad+1)); echo "  syntax error: $f"; }; done
printf '%-30s RESULT: %d passed, %d failed (php -l, every PHP file)\n' php_syntax $((n-bad)) $bad
base="'db_host'=>'127.0.0.1','db_port'=>3306,'db_user'=>'f360','db_password'=>'stagingpw','setup_key'=>'TEST-ONLY-setup-key-0123456789abcdef','pii_key'=>'TEST-ONLY-pii-key-0123456789abcdef0123','restaurant'=>'Khadak Singh Da Dhaba','f3_allow_fake_clock'=>true"
mysql -e "CREATE DATABASE IF NOT EXISTS ksdd_v34_single_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
echo "<?php return [$base,'db_name'=>'ksdd_v34_single_test'];" > "$OUT/single.php"
echo "<?php return [$base,'db_name'=>'ksdd_mt_test','single_db'=>true,'single_db_scope'=>['tenant_id'=>1,'branch_id'=>1,'branch_uid'=>'']];" > "$OUT/mt.php"
run legacy_unconverted   env F360_CONFIG="$OUT/single.php" php tests/run_tests.php
run legacy_single_db     bash tests/run_tests_mt.sh
run mt_hostile           env F360_CONFIG="$OUT/mt.php" php tests/mt_hostile_tests.php
run platform_single_db   env TMPDIR="$OUT" bash tests/platform_tests_v31.sh
run isolation_sweep      sh -c "cd '$OUT/platform_single_db' && php tests/isolation_sweep_v31.php"
run conversion_rehearsal bash tests/conversion_rehearsal.sh "${F360_POPULATED_DB:-ksdd_v34_single_test}"
run hosting_rehearsal    env KSDD_PRIVATE_SRC="$HERE/../ksdd_private" bash tests/hosting_rehearsal.sh "${F360_POPULATED_DB:-ksdd_v34_single_test}"
