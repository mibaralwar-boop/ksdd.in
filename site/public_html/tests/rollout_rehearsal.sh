#!/usr/bin/env bash
# V30 rollout rehearsal (STAGING ONLY): the exact ksdd.in steps from docs/HOSTING_KSDD_IN.md, on a COPY of a populated
# single-restaurant database, with the production folder layout:  <root>/public_html  +  <root>/ksdd_private/config.php
# Usage: tests/rollout_rehearsal.sh <source_test_db>     (needs MariaDB user f360/stagingpw; db names must contain "test")
set -euo pipefail
SRC_DB="${1:-ksdd_corpus_test}"; DB=ksdd_rollout_test; PORT=8097
HERE="$(cd "$(dirname "$0")/.." && pwd)"; ROOT="$(mktemp -d)"; W="$ROOT/public_html"; PRIV="$ROOT/ksdd_private"
cp -r "$HERE" "$W"; rm -rf "$W/private" "$W/tests"; cp -r "$HERE/../ksdd_private" "$PRIV"
mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysqldump --single-transaction "$SRC_DB" | mysql "$DB"
P=0; F=0; ok() { echo "  PASS $1"; P=$((P+1)); }; bad() { echo "  FAIL $1"; F=$((F+1)); }
cfg() { # $1 = extra PHP array entries
cat > "$PRIV/config.php" <<EOF
<?php return ['db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'$DB','db_user'=>'f360','db_password'=>'stagingpw',
 'setup_key'=>'TEST-ONLY-setup-key-0123456789abcdef','pii_key'=>'TEST-ONLY-pii-key-0123456789abcdef0123','restaurant'=>'Khadak Singh Da Dhaba', $1];
EOF
}
PLATFORM="'platform'=>['enabled'=>true,'key'=>'TEST-ONLY-platform-key-0123456789abcdef0123','base_url'=>'http://127.0.0.1:$PORT','support_email'=>'s@example.test','signup_enabled'=>true,'admin_mfa_required'=>true,'provision'=>['mode'=>'shared'],'mail'=>['mode'=>'log'],'billing'=>['mode'=>'disabled']]"
# Real Apache with .htaccess (AllowOverride All), like Hostinger's LiteSpeed. PHP's built-in server would ignore .htaccess.
chmod 755 "$ROOT"; chmod -R a+rX "$W"
printf 'Listen %s\n<VirtualHost 127.0.0.1:%s>\n  DocumentRoot %s\n  <Directory %s>\n    AllowOverride All\n    Require all granted\n  </Directory>\n  ErrorLog %s/apache-error.log\n</VirtualHost>\n' "$PORT" "$PORT" "$W" "$W" "$ROOT" > /etc/apache2/sites-available/f360rehearsal.conf
a2ensite -q f360rehearsal >/dev/null
srv() { chown -R www-data:www-data "$PRIV"; apache2ctl restart >/dev/null 2>&1 || service apache2 restart >/dev/null; for _ in $(seq 50); do curl -s -o /dev/null http://127.0.0.1:$PORT/ && break; sleep 0.1; done; }
stop() { apache2ctl stop >/dev/null 2>&1 || true; sleep 0.5; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
api() { local a=(-s -X "$1" "http://127.0.0.1:$PORT/api/v1/index.php?__path=$2" -H 'Content-Type: application/json'); [ -n "${4:-}" ] && a+=(-H "Authorization: Bearer $4"); [ -n "${3:-}" ] && a+=(-d "$3"); curl "${a[@]}"; }
j() { python3 -c "import json,sys
raw=sys.stdin.read()
try:
    d=json.loads(raw); print($1)
except Exception as e:
    sys.stderr.write('     unexpected reply: '+raw[:300]+'\\n'); sys.exit(1)"; }

echo "STEP 1 · new files, config OUTSIDE public_html, platform OFF (site behaves exactly as before)"
cfg "'platform'=>['enabled'=>false,'key'=>'TEST-ONLY-platform-key-0123456789abcdef0123']"; srv
[ "$(code http://127.0.0.1:$PORT/)" = 200 ] && ok "home page 200" || bad "home page"
for f in config.php tenant_scope.php lib.php schema.sql tools/tenancy-migrate.php migrations/f360_v17_reconcile.sql platform/core.php f360/core.php docs/openapi.yaml; do c=$(code http://127.0.0.1:$PORT/$f); [ "$c" = 403 ] || [ "$c" = 404 ] && ok "/$f not downloadable ($c)" || bad "/$f served ($c)"; done
[ ! -e "$W/config.php" ] && ok "no config.php inside public_html" || bad "config.php inside public_html"
cnt=$(cd "$W" && php -r 'require "lib.php"; echo count(rows("SELECT id FROM dd_employees"));'); [ "$cnt" -gt 0 ] && ok "app reads existing data from config in ksdd_private ($cnt employees)" || bad "data"
stop

echo "STEP 2 · platform tables in the SAME database + adopt Khadak Singh Da Dhaba as tenant 1"
(cd "$W" && php tools/platform-setup.php migrate | tail -1)
(cd "$W" && php tools/platform-adopt-legacy.php --apply --i-have-a-backup | tail -2) | grep -q "byte-identical" && ok "adoption: business tables byte-identical" || bad "adoption"
[ "$(mysql -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME LIKE 'p\_%'")" -gt 20 ] && ok "platform tables created in the one database" || bad "platform tables"

echo "STEP 3 · tenant_id/branch_id migration (maintenance window), then shared_tenant in config"
before=$(mysql -N -e "SELECT COUNT(*) FROM $DB.dd_bk_txns"); (cd "$W" && php tools/tenancy-migrate.php --apply --i-have-a-backup | grep -E "^Done") && ok "migration verified" || bad "migration"
cfg "'shared_tenant'=>['tenant_id'=>1,'branch_id'=>1], 'platform'=>['enabled'=>false,'key'=>'TEST-ONLY-platform-key-0123456789abcdef0123']"
after=$(cd "$W" && php -r 'require "lib.php"; echo row("SELECT COUNT(*) n FROM dd_bk_txns")["n"];'); [ "$before" = "$after" ] && ok "single-restaurant mode on migrated DB sees all $after KSDD transactions" || bad "rows $before vs $after"
(cd "$W" && php -r 'require "lib.php"; run("INSERT INTO dd_settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)","rollout_probe","1");') && [ "$(mysql -N -e "SELECT CONCAT(tenant_id,'/',branch_id) FROM $DB.dd_settings WHERE \`key\`='rollout_probe'")" = "1/1" ] && ok "new writes tagged tenant 1 / branch 1" || bad "write tagging"

echo "STEP 4 · platform ON (shared): website + registration + Super Admin + /api/v1 on ONE domain"
cfg "$PLATFORM"; srv
(cd "$W" && printf 'AdminPass#2026x\n' | php tools/platform-setup.php admin admin@example.test "Super Admin" | tail -1)
[ "$(curl -s -D - -o /dev/null http://127.0.0.1:$PORT/ | tr -d '\r' | grep -i '^location:' | awk '{print $2}')" = site.php ] && ok "GET / → visitors are sent to the website (site.php)" || bad "GET / redirect"
for u in /site.php /signin.php /register.php /platform-admin/ "/api/v1/index.php?__path=health"; do c=$(code "http://127.0.0.1:$PORT$u"); [ "$c" = 200 ] && ok "GET $u → 200" || bad "GET $u → $c"; done
L=$(api POST auth/login '{"email":"owner@staging.test","password":"OwnerPass#2026","device_id":"rehearsal-dev-1"}'); echo "$L" | grep -q access_token || echo "     login reply: $L"
TOK=$(echo "$L" | j "d['access_token']")
SEL=$(api POST tenants/select '{"tenant_id":1,"branch_id":1}' "$TOK"); TOK=$(echo "$SEL" | j "d['access_token']")
n=$(api GET employees "" "$TOK" | j "len(d['staff'])"); [ "$n" -gt 0 ] && ok "KSDD owner signs in with OLD password via /api/v1 and sees $n staff" || bad "owner api"
R=$(api POST tenants/register '{"owner_name":"New Owner","mobile":"9876543210","email":"new@example.test","password":"NewOwnerPass#1","restaurant":"New Dhaba","city":"Delhi","state":"Delhi","pincode":"110001","branch_name":"Main","cutoff_hour":4,"accept_terms":true}')
echo "$R" | grep -q registration && ok "self-registration accepted (email verification pending)" || bad "register: $R"
VT=$(cd "$W" && php -r 'require "platform/core.php"; preg_match("/token=([A-Za-z0-9_-]{20,})/", (string)pval("SELECT body FROM p_outbox WHERE recipient=? AND kind=? ORDER BY id DESC LIMIT 1","new@example.test","verify"), $m); echo $m[1];')
[ "$(code "http://127.0.0.1:$PORT/verify.php?token=$VT")" -lt 400 ] && ok "verification link works" || bad "verify"
(cd "$W" && php -r 'require "platform/register.php"; foreach (prows("SELECT uid FROM p_registrations WHERE email=? AND status IN (\"verified\",\"draft\")","new@example.test") as $r) { prun("UPDATE p_registrations SET status=\"verified\" WHERE uid=?",$r["uid"]); try { reg_provision($r["uid"]); } catch (Throwable $e) { echo $e->getMessage(); } }') >/dev/null
T2=$(api POST auth/login '{"email":"new@example.test","password":"NewOwnerPass#1","device_id":"rehearsal-dev-2"}'); TID=$(echo "$T2" | j "d['memberships'][0]['id']"); BID=$(echo "$T2" | j "d['memberships'][0]['branches'][0]['id']"); TK2=$(echo "$T2" | j "d['access_token']")
S2=$(api POST tenants/select "{\"tenant_id\":$TID,\"branch_id\":$BID}" "$TK2"); TK2=$(echo "$S2" | j "d['access_token']"); ST=$(echo "$S2" | j "d['permissions']['subscription']['state']")
[ "$ST" = trialing ] && ok "new restaurant $TID/$BID provisioned in the SAME database, 30-day trial" || bad "trial state $ST"
m=$(api GET employees "" "$TK2" | j "len(d['staff'])"); [ "$m" = 0 ] && ok "new restaurant sees 0 of KSDD's staff" || bad "new restaurant sees $m staff"
[ "$(api POST tenants/select '{"tenant_id":1,"branch_id":1}' "$TK2" | j "d.get('code')")" = FORBIDDEN ] && ok "new owner cannot open KSDD (403)" || bad "cross-tenant select"
[ "$(mysql -N -e "SELECT COUNT(DISTINCT branch_id) FROM $DB.dd_settings")" -ge 2 ] && ok "one database holds both restaurants' rows (tagged by branch_id)" || bad "branch rows"
[ "$(mysql -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'f360%b%'")" = 0 ] && ok "no extra databases were created" || bad "extra databases"
stop
a2dissite -q f360rehearsal >/dev/null; echo; echo "RESULT: $P passed, $F failed   (work dir $ROOT)"; [ $F = 0 ]
