#!/usr/bin/env bash
# Runs the LIVE API tests: real Finance 360 PHP backend (default: V34 = V31 single database) + local MariaDB, disposable demo databases.
# Requirements: php 8.2+ (pdo_mysql, gd, sodium), MariaDB/MySQL with user f360/stagingpw that may create *_test databases.
# NEVER point this at production: tests/platform_tests.php refuses database names without "test".
set -euo pipefail
APP="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${F360_BACKEND:-$APP/../v34/public_html}"   # V34 backend (V31 single database + V34 fixes)
TCFG="${F360_TEST_CFG:-tests/platform_test_config_v31.php}"
WORK="${F360_WORK:-$(mktemp -d)}"; mkdir -p "$WORK"
rm -rf "$WORK/www"; cp -r "$SRC" "$WORK/www"; cd "$WORK/www"
echo "1/4 building disposable test databases with the backend's own platform suite…"
if [ -x tests/platform_tests_v31.sh ]; then TMPDIR="$WORK" bash tests/platform_tests_v31.sh | grep -E "MODE|Converted|RESULT"; else F360_PLATFORM_TEST_CFG="$TCFG" php tests/platform_tests.php | grep -E "MODE|RESULT"; fi
# Clock two days ahead of the real IST date (08:00 IST): its previous business day was never touched by the backend suite,
# whose own closing test finalises the REAL previous day.
D=$(TZ=Asia/Kolkata date -d '+2 days' +%F)
TZ=UTC date -d "$D 02:30" '+%F %T' > private/p_fake_now.txt
echo "$D 08:00:00" > private/f3_fake_now.txt
php -r '$i=imagecreatetruecolor(320,240); imagefill($i,0,0,imagecolorallocate($i,250,240,220)); imagestring($i,5,20,100,"DAYBOOK 2026-11-02",0); imagejpeg($i,"'"$WORK"'/daybook.jpg",80);'
echo "2/4 starting PHP server on 127.0.0.1:8099"
F360_CONFIG="$PWD/$TCFG" php -S 127.0.0.1:8099 -t . > "$WORK/server.log" 2>&1 &
PID=$!; trap 'kill $PID 2>/dev/null || true' EXIT
for _ in $(seq 50); do curl -s -o /dev/null http://127.0.0.1:8099/ && break; sleep 0.1; done
echo "3/4 flutter test test/live"
cd "$APP"
F360_LIVE_BASE=http://127.0.0.1:8099/api/v1/index.php F360_LIVE_JPEG="$WORK/daybook.jpg" flutter test test/live --reporter expanded
echo "4/4 done (server log: $WORK/server.log)"
