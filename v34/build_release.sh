#!/usr/bin/env bash
# Builds the V34 staging/production package from v34/ (no secrets, no logs, no sessions). Usage: v34/build_release.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; OUT="$HERE/../releases/v34"; N=FINANCE360_V34; T="$(mktemp -d)/$N"; mkdir -p "$T" "$OUT"
for bad in config.php private staging; do [ -e "$HERE/public_html/$bad" ] && { echo "refusing: public_html/$bad exists"; exit 1; }; done
cp -r "$HERE/public_html" "$T/public_html"; mv "$T/public_html/tests" "$T/tests"
cp -r "$HERE/ksdd_private" "$T/ksdd_private"; rm -f "$T/ksdd_private/config.php"
find "$T/ksdd_private" -mindepth 2 -type f ! -name '.htaccess' ! -path '*/fonts/*' -delete
cp -r "$HERE/docs" "$T/docs"
# no real secrets: only placeholders / test-only values may appear
if grep -rIE "'(db_password|password|key_secret|webhook_secret)'\s*=>\s*'[^']{6,}'" "$T/public_html" "$T/ksdd_private" | grep -vE "YOUR_|MAILBOX_PASSWORD|REPLACE_|COPY_FROM|TEST-ONLY|stagingpw|dummy|whsec_test" ; then echo "refusing: secret-looking value"; exit 1; fi
( cd "$T" && find . -type f ! -name SHA256SUMS.txt -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS.txt )
rm -f "$OUT/$N.zip"; ( cd "$(dirname "$T")" && zip -qr "$OUT/$N.zip" "$N" )
echo "$OUT/$N.zip ($(du -h "$OUT/$N.zip" | cut -f1), $(wc -l < "$T/SHA256SUMS.txt") files)"
