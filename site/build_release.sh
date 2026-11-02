#!/usr/bin/env bash
# Builds the ONE Hostinger upload ZIP for ksdd.in from site/ (no tests, no secrets, no logs).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; V="${1:-V30}"; OUT="$HERE/../releases"; T="$(mktemp -d)/FINANCE360_${V}_KSDD_IN"
mkdir -p "$T" "$OUT"
cp -r "$HERE/public_html" "$T/public_html"; rm -rf "$T/public_html/tests" "$T/public_html/private" "$T/public_html/config.php"
cp -r "$HERE/ksdd_private" "$T/ksdd_private"; rm -f "$T/ksdd_private/config.php"
find "$T/ksdd_private" -type f \( -name '*.json' -o -name '*.md' -o -name '*.log' \) -path '*/logs/*' -delete
find "$T/ksdd_private" -mindepth 2 -type f ! -name '.htaccess' ! -path '*/fonts/*' -delete
mkdir -p "$T/docs"; cp "$HERE/docs/"*.md "$T/docs/"
if grep -rIlE "u245900320_[a-z]+'\s*,\s*'db_password'\s*=>\s*'[^Y]" "$T" >/dev/null; then echo "secret-looking config found, aborting"; exit 1; fi
( cd "$T" && find . -type f ! -name SHA256SUMS.txt -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS.txt )
rm -f "$OUT/FINANCE360_${V}_KSDD_IN.zip"; ( cd "$(dirname "$T")" && zip -qr "$OUT/FINANCE360_${V}_KSDD_IN.zip" "$(basename "$T")" )
echo "$OUT/FINANCE360_${V}_KSDD_IN.zip ($(du -h "$OUT/FINANCE360_${V}_KSDD_IN.zip" | cut -f1), $(wc -l < "$T/SHA256SUMS.txt") files)"
