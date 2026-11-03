#!/usr/bin/env bash
# V34 hosting rehearsal (STAGING/TEST ONLY) on REAL Apache with .htaccess (like Hostinger LiteSpeed):
#   Part A — production layout  <root>/public_html + <root>/ksdd_private, the exact steps of docs/V34_STAGING_GUIDE.md
#   Part B — nested staging layout  <root>/public_html/staging + <root>/staging_private (SetEnv F360_PRIVATE_DIR)
# Usage: tests/hosting_rehearsal.sh <populated_source_test_db>      (needs root for Apache, MariaDB user f360/stagingpw)
set -uo pipefail
SRC_DB="${1:?source test database}"; DB=ksdd_host_test; PORT=8093
HERE="$(cd "$(dirname "$0")/.." && pwd)"; ROOT="$(mktemp -d)"; W="$ROOT/public_html"; PRIV="$ROOT/ksdd_private"
cp -r "$HERE" "$W"; rm -rf "$W/tests" "$W/private"; cp -r "${KSDD_PRIVATE_SRC:-$HERE/../ksdd_private}" "$PRIV"; rm -f "$PRIV"/config.php
mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" && mysqldump --single-transaction "$SRC_DB" | mysql "$DB"
DBS0=$(mysql -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA")
P=0; F=0; ok() { echo "  PASS $1"; P=$((P+1)); }; bad() { echo "  FAIL $1"; F=$((F+1)); }
KEYS="'setup_key'=>'TEST-ONLY-setup-key-0123456789abcdef','pii_key'=>'TEST-ONLY-pii-key-0123456789abcdef0123','restaurant'=>'Khadak Singh Da Dhaba','environment'=>'production'"
PLAT() { echo "'platform'=>['enabled'=>$1,'key'=>'TEST-ONLY-platform-key-0123456789abcdef0123','base_url'=>'http://127.0.0.1:$PORT','support_email'=>'s@example.test','signup_enabled'=>true,'admin_mfa_required'=>true,'mail'=>['mode'=>'log'],'billing'=>['mode'=>'disabled']]"; }
cfg() { echo "<?php return ['db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'$DB','db_user'=>'f360','db_password'=>'stagingpw',$KEYS,$1];" > "$PRIV/config.php"; chown www-data "$PRIV/config.php"; }
chmod 755 "$ROOT"; chmod -R a+rX "$W"
printf 'Listen %s\n<VirtualHost 127.0.0.1:%s>\n  DocumentRoot %s\n  <Directory %s>\n    AllowOverride All\n    Require all granted\n  </Directory>\n  ErrorLog %s/apache-error.log\n</VirtualHost>\n' "$PORT" "$PORT" "$W" "$W" "$ROOT" > /etc/apache2/sites-available/f360v34.conf
a2ensite -q f360v34 >/dev/null; a2enmod -q env headers rewrite >/dev/null 2>&1
srv() { chown -R www-data:www-data "$PRIV"; [ -d "$ROOT/staging_private" ] && chown -R www-data:www-data "$ROOT/staging_private"; apache2ctl restart >/dev/null 2>&1; for _ in $(seq 50); do curl -s -o /dev/null http://127.0.0.1:$PORT/ && break; sleep 0.1; done; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
api() { local a=(-s -X "$1" "http://127.0.0.1:$PORT/api/v1/index.php?__path=$2" -H 'Content-Type: application/json'); [ -n "${4:-}" ] && a+=(-H "Authorization: Bearer $4"); [ -n "${3:-}" ] && a+=(-d "$3"); curl "${a[@]}"; }
j() { python3 -c "import json,sys
raw=sys.stdin.read()
try:
    d=json.loads(raw); print($1)
except Exception:
    sys.stderr.write('     unexpected reply: '+raw[:300]+'\\n'); print('')"; }
php_w() { (cd "$W" && php "$@"); }

echo "PART A · STEP 1 — new files, config OUTSIDE public_html, not converted, platform OFF"
cfg "$(PLAT false)"; srv
[ "$(code http://127.0.0.1:$PORT/)" = 200 ] && ok "home page 200" || bad "home page"
for f in config.php lib.php tenant_scope.php platform/mt.php platform/core.php f360/core.php tools/v31-convert.php tools/health-check.php migrations/v31_single_db_conversion_REFERENCE.sql migrations/f360_v17_reconcile.sql schema.sql docs/TEST_REPORT_PLATFORM.md KSDD-360-INSTALL.txt SALARY-REGISTER-INSTALL.txt api360.php books360.php cron.php platform-cron.php f360-cron.php; do
  c=$(code "http://127.0.0.1:$PORT/$f"); { [ "$c" = 403 ] || [ "$c" = 404 ]; } && ok "/$f not served ($c)" || bad "/$f served ($c)"; done
[ ! -e "$W/config.php" ] && [ ! -e "$W/private" ] && [ ! -e "$W/staging" ] && ok "no config.php, private/ or staging/ inside public_html" || bad "sensitive folders in public_html"
php_w tools/health-check.php > "$ROOT/h1.txt"; grep -q "OK   config.php outside the web root" "$ROOT/h1.txt" && grep -q "OK   private storage outside the web root" "$ROOT/h1.txt" && ok "health-check: config + private storage outside the web root" || { bad "health-check"; cat "$ROOT/h1.txt"; }

echo "PART A · STEP 2 — conversion (dry run → apply) with reconciliation"
php_w tools/v31-convert.php | grep -q "DRY RUN" && ok "dry run" || bad "dry run"
{ php_w tools/v31-convert.php --apply --i-have-a-backup 2>&1 || true; } | grep -q "owner-approved-live" && ok "production environment: conversion refused without --owner-approved-live" || bad "live guard"
out=$(php_w tools/v31-convert.php --apply --i-have-a-backup --owner-approved-live 2>&1); echo "$out" | grep -E "Converted|Financial" | sed 's/^/     /'
echo "$out" | grep -q "Verification: [0-9]* OK, 0 mismatch" && echo "$out" | grep -qE "[0-9]+ totals compared, 0 difference" && ok "conversion verified + reconciled" || bad "conversion"
ls "$PRIV"/logs/v31-convert-*-reconciliation.md >/dev/null 2>&1 && ok "reports in ksdd_private/logs (outside the web root)" || bad "report location"
TID=$(mysql -N -e "SELECT tenant_id FROM $DB.p_branches WHERE is_primary=1 ORDER BY id LIMIT 1"); BID=$(mysql -N -e "SELECT id FROM $DB.p_branches WHERE is_primary=1 ORDER BY id LIMIT 1")

echo "PART A · STEP 3 — single_db + platform ON: website, registration, Super Admin, /api/v1 on ONE domain"
cfg "'single_db'=>true,'single_db_scope'=>['tenant_id'=>$TID,'branch_id'=>$BID,'branch_uid'=>''],$(PLAT true)"; srv
php_w tools/platform-setup.php migrate >/dev/null; printf 'AdminPass#2026x\n' | php_w tools/platform-setup.php admin admin@example.test "Super Admin" >/dev/null
php_w tools/health-check.php > "$ROOT/h2.txt"; grep -q "^RESULT: OK" "$ROOT/h2.txt" && ok "health-check after conversion: OK ($(grep -c '  OK ' "$ROOT/h2.txt") checks)" || { bad "health-check"; grep -E "FAIL|WARN" "$ROOT/h2.txt"; }
[ "$(curl -s -D - -o /dev/null http://127.0.0.1:$PORT/ | tr -d '\r' | grep -i '^location:' | awk '{print $2}')" = site.php ] && ok "visitors → website (site.php)" || bad "GET / redirect"
for u in /site.php /signin.php /register.php /platform-admin/ "/api/v1/index.php?__path=health"; do c=$(code "http://127.0.0.1:$PORT$u"); [ "$c" = 200 ] && ok "GET $u → 200" || bad "GET $u → $c"; done
L=$(api POST auth/login '{"email":"owner@staging.test","password":"OwnerPass#2026","device_id":"rehearsal-dev-1"}'); TOK=$(echo "$L" | j "d['access_token']")
TOK=$(api POST tenants/select "{\"tenant_id\":$TID,\"branch_id\":$BID}" "$TOK" | j "d['access_token']")
n=$(api GET employees "" "$TOK" | j "len(d['staff'])"); [ "${n:-0}" -gt 0 ] && ok "KSDD owner: OLD password via /api/v1, sees $n staff" || bad "owner api"
(cd "$W" && php -r 'require "platform/core.php"; p_setting_set("signup_enabled","1");') && ok "Super Admin opens self-registration"
R=$(api POST tenants/register '{"owner_name":"New Owner","mobile":"9876543210","email":"new@example.test","password":"NewOwnerPass#1","restaurant":"New Dhaba","city":"Delhi","state":"Delhi","pincode":"110001","branch_name":"Main","cutoff_hour":4,"accept_terms":true}')
echo "$R" | grep -q '"registration"' && ok "self-registration accepted" || { bad "register"; echo "     $R"; }
VT=$(cd "$W" && php -r 'require "platform/core.php"; preg_match("/token=([A-Za-z0-9_-]{20,})/", (string)pval("SELECT body FROM p_outbox WHERE recipient=? AND kind=? ORDER BY id DESC LIMIT 1","new@example.test","verify"), $m); echo $m[1] ?? "";')
[ -n "$VT" ] && curl -s -o /dev/null "http://127.0.0.1:$PORT/verify.php?token=$VT"
[ "$(mysql -N -e "SELECT COUNT(*) FROM $DB.p_users WHERE email='new@example.test' AND email_verified_at IS NOT NULL")" = 1 ] && ok "verification link (web) verifies the owner" || bad "verify"
T2=$(api POST auth/login '{"email":"new@example.test","password":"NewOwnerPass#1","device_id":"rehearsal-dev-2"}'); TK2=$(echo "$T2" | j "d['access_token']")
TID2=$(echo "$T2" | j "d['memberships'][0]['id'] if d.get('memberships') else ''"); BID2=$(echo "$T2" | j "d['memberships'][0]['branches'][0]['id'] if d.get('memberships') else ''")
[ -n "$TID2" ] && ok "web verification provisioned the restaurant (tenant $TID2 / branch $BID2)" || { bad "provisioning via web (P_ALL_MODULES)"; mysql -N -e "SELECT status,LEFT(error,200) FROM $DB.p_registrations"; }
S2=$(api POST tenants/select "{\"tenant_id\":${TID2:-0},\"branch_id\":${BID2:-0}}" "$TK2"); TK2=$(echo "$S2" | j "d['access_token']")
[ "$(echo "$S2" | j "d['permissions']['subscription']['state']")" = trialing ] && ok "30-day trial active" || bad "trial"
[ "$(api GET employees "" "$TK2" | j "len(d['staff'])")" = 0 ] && ok "new restaurant sees 0 of KSDD's staff" || bad "staff leak"
[ "$(api GET departments "" "$TK2" | j "','.join(x['name'] for x in d['departments'])")" = "Management,UT,Kitchen,Waiter" ] && ok "new restaurant has its own 4 departments" || bad "departments"
[ "$(api POST tenants/select "{\"tenant_id\":$TID,\"branch_id\":$BID}" "$TK2" | j "d.get('code')")" = FORBIDDEN ] && ok "new owner cannot open KSDD (403)" || bad "cross-tenant select"
[ "$(mysql -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA")" = "$DBS0" ] && ok "no database created for the new restaurant" || bad "extra database"
curl -s -c "$ROOT/prod.cookies" -o /dev/null "http://127.0.0.1:$PORT/signin.php"; curl -s -c "$ROOT/padmin.cookies" -o /dev/null "http://127.0.0.1:$PORT/platform-admin/"
ls "$PRIV"/sessions/sess_* >/dev/null 2>&1 && ok "web + Super Admin sessions stored in ksdd_private/sessions ($(ls "$PRIV"/sessions | wc -l) files), not the shared PHP default" || bad "session location"
grep -q DHABA_OPS "$ROOT/prod.cookies" && grep -q F360_PADMIN "$ROOT/padmin.cookies" && ok "production cookie names DHABA_OPS / F360_PADMIN" || bad "prod cookie names"

echo "PART B · nested staging layout (public_html/staging + staging_private via SetEnv F360_PRIVATE_DIR)"
SW="$W/staging"; SP="$ROOT/staging_private"; mkdir -p "$SW"; cp -r "$HERE"/. "$SW"/; rm -rf "$SW/tests" "$SW/private"; cp -r "$PRIV" "$SP"; rm -rf "$SP/logs"/*; rm -rf "$SP/sessions"; sed -i "s/'environment'=>'production'/'environment'=>'staging'/" "$SP/config.php"
printf '\n# STAGING: private folder outside every web root\nSetEnv F360_PRIVATE_DIR %s\n' "$SP" >> "$SW/.htaccess"; chmod -R a+rX "$SW"; srv
c=$(code "http://127.0.0.1:$PORT/staging/api/v1/index.php?__path=health"); [ "$c" = 200 ] && ok "staging site reads its config from staging_private via SetEnv (health 200)" || { bad "staging health $c"; tail -3 "$ROOT/apache-error.log"; }
for f in staging/config.php staging/lib.php staging/platform/mt.php staging/tools/v31-convert.php; do c=$(code "http://127.0.0.1:$PORT/$f"); { [ "$c" = 403 ] || [ "$c" = 404 ]; } && ok "/$f not served ($c)" || bad "/$f served ($c)"; done
curl -s -c "$ROOT/stg.cookies" -o /dev/null "http://127.0.0.1:$PORT/staging/signin.php"
grep -q F360_STAGING "$ROOT/stg.cookies" && ls "$SP"/sessions/sess_* >/dev/null 2>&1 && ok "staging uses its own cookie name (F360_STAGING) and staging_private/sessions" || bad "staging sessions"
PSID=$(awk '$6=="DHABA_OPS"{print $7}' "$ROOT/prod.cookies"); [ -n "$PSID" ] && [ ! -e "$SP/sessions/sess_$PSID" ] && ok "a production session id does not exist in staging's session store (no cross-environment replay)" || bad "session replay"
{ (cd "$SW" && F360_PRIVATE_DIR="$SP" php tools/health-check.php) || true; } | grep -q "private storage outside the web root: $SP" && ok "CLI with F360_PRIVATE_DIR uses staging_private" || bad "staging CLI"

apache2ctl stop >/dev/null 2>&1; a2dissite -q f360v34 >/dev/null
echo; echo "RESULT: $P passed, $F failed   (work dir $ROOT)"; [ $F = 0 ]
