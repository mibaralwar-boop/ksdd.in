#!/usr/bin/env bash
# Finance 360 V34 — READ-ONLY website check over SSH (changes nothing: only HTTP GET requests and file listings).
#   bash tools/site-check.sh https://staging.ksdd.in ~/domains/ksdd.in/public_html/staging
#   bash tools/site-check.sh https://ksdd.in        ~/domains/ksdd.in/public_html
# Ends with "RESULT: N ok, N warning(s), N problem(s)". Exit code 1 if any problem.
BASE="${1%/}"; WEB="${2:-}"
[ -n "$BASE" ] || { echo "usage: bash tools/site-check.sh https://site [web-root]"; exit 2; }
OK=0; WARN=0; BAD=0
ok()   { OK=$((OK+1));   echo "  OK    $*"; }
warn() { WARN=$((WARN+1)); echo "  WARN  $*"; }
bad()  { BAD=$((BAD+1));  echo "  FAIL  $*"; }
code() { curl -s -o /dev/null -m 20 -w '%{http_code}' "$1"; }
HOST=$(echo "$BASE" | sed -E 's#^https?://##; s#/.*##')
echo "Finance 360 site check — $(date -u '+%Y-%m-%d %H:%M UTC') — $BASE"

echo "1. Pages"
for u in / /site.php /signin.php /register.php /platform-admin/ "/api/v1/index.php?__path=health"; do
  c=$(code "$BASE$u")
  case "$c" in 200|302|303) ok "GET $u → $c";; 403|404|503) warn "GET $u → $c (normal if the platform is not enabled on this site)";; *) bad "GET $u → $c";; esac
done
t=$(curl -s -o /dev/null -m 20 -w '%{time_total}' "$BASE/"); awk "BEGIN{exit !($t<2.5)}" && ok "home page time ${t}s" || warn "home page slow: ${t}s"

echo "2. Private files must NOT be downloadable (expect 403/404)"
for f in config.php lib.php tenant_scope.php platform/mt.php platform/core.php platform/mt_convert.php f360/core.php \
         tools/v31-convert.php tools/health-check.php tools/site-check.sh tools/platform-setup.php \
         migrations/v31_single_db_conversion_REFERENCE.sql schema.sql tests/run_tests.php .htaccess .git/config .env \
         private/ private/config.php staging/config.php ops/config.php backups/ logs/ \
         cron.php platform-cron.php f360-cron.php composer.json error_log; do
  c=$(code "$BASE/$f"); case "$c" in 403|404) ok "/$f blocked ($c)";; *) bad "/$f answered $c — must be 403/404";; esac
done

echo "3. HTTPS and security headers"
c=$(curl -s -o /dev/null -m 20 -w '%{http_code} %{redirect_url}' "http://$HOST/")
case "$c" in 301*https*|302*https*|308*https*) ok "http:// redirects to https ($c)";; *) warn "http:// does not redirect to https ($c) — hPanel → SSL → Force HTTPS";; esac
H=$(curl -s -D - -o /dev/null -m 20 "$BASE/")
for h in X-Content-Type-Options Referrer-Policy; do echo "$H" | grep -qi "^$h:" && ok "header $h" || warn "header $h missing"; done
echo "$H" | grep -qi '^Strict-Transport-Security:' && ok "HSTS on" || warn "HSTS off (turn on in .htaccess only after HTTPS works everywhere)"
echo "$H" | grep -qiE '^(X-Frame-Options|Content-Security-Policy):' && ok "click-jacking protection header" || warn "no X-Frame-Options / CSP frame-ancestors"
echo "$H" | grep -qi '^X-Powered-By:' && warn "server reveals PHP version (X-Powered-By)" || ok "no X-Powered-By"
if echo "$HOST" | grep -q staging; then echo "$H" | grep -qi '^X-Robots-Tag:.*noindex' && ok "staging is noindex" || bad "staging missing X-Robots-Tag noindex"; fi
if command -v openssl >/dev/null; then
  end=$(echo | openssl s_client -servername "$HOST" -connect "$HOST:443" 2>/dev/null | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)
  if [ -n "$end" ]; then days=$(( ( $(date -d "$end" +%s) - $(date +%s) ) / 86400 )); [ "$days" -gt 14 ] && ok "SSL certificate valid $days more days" || bad "SSL certificate expires in $days days"; else warn "could not read the SSL certificate"; fi
fi

echo "4. Cookies"
CK=$(curl -s -D - -o /dev/null -m 20 "$BASE/signin.php" | grep -i '^Set-Cookie:')
if [ -n "$CK" ]; then
  echo "$CK" | grep -qi secure && ok "session cookie Secure" || bad "session cookie without Secure"
  echo "$CK" | grep -qi httponly && ok "session cookie HttpOnly" || bad "session cookie without HttpOnly"
  echo "$CK" | grep -qi samesite && ok "session cookie SameSite" || warn "session cookie without SameSite"
else ok "no cookie before sign-in"; fi

if [ -n "$WEB" ] && [ -d "$WEB" ]; then
  echo "5. Files inside the web root ($WEB)"
  EX=(); [ -d "$WEB/staging" ] && [ "$(basename "$WEB")" = public_html ] && EX=(-path "$WEB/staging" -prune -o)
  n=$(find "$WEB" "${EX[@]}" -name config.php -type f -print 2>/dev/null | xargs -r grep -l "db_password" 2>/dev/null)
  [ -z "$n" ] && ok "no config.php with a password inside the web root" || bad "config.php with a password inside the web root: $(echo $n) — move/delete it (the live one is outside public_html)"
  n=$(find "$WEB" "${EX[@]}" -type f \( -iname '*.sql' -o -iname '*.sql.gz' -o -iname '*.zip' -o -iname '*.bak' -o -iname '*.tar*' -o -iname '.env' -o -iname '*.xlsx' -o -iname '*.csv' -o -iname 'error_log' -o -iname '*.log' \) -print 2>/dev/null | grep -v '/migrations/' | head -20)
  [ -z "$n" ] && ok "no backups/exports/logs/ZIPs in the web root" || warn "files that should not be in the web root:
$(echo "$n" | sed 's/^/          /')"
  [ -d "$WEB/private" ] && warn "$WEB/private still exists (old layout) — move its uploads/ to the private folder, then delete it" || ok "no old private/ folder in the web root"
  n=$(find "$WEB" "${EX[@]}" \( -type f -o -type d \) -perm -0002 -print 2>/dev/null | head -5)
  [ -z "$n" ] && ok "nothing world-writable" || bad "world-writable: $(echo $n)"
  n=$(find "$WEB" "${EX[@]}" -name '*.php' -newer "$WEB/index.php" -mmin -1440 -type f -print 2>/dev/null | head -10)
  [ -z "$n" ] && ok "no PHP file changed in the last 24 h (after index.php)" || warn "PHP files changed in the last 24 h (check they are yours):
$(echo "$n" | sed 's/^/          /')"
  PRIV="${F360_PRIVATE_DIR:-$(dirname "$WEB")/ksdd_private}"
  if [ -f "$PRIV/config.php" ]; then p=$(stat -c %a "$PRIV/config.php"); case "$p" in 600|640|400|440) ok "private config.php permissions $p";; *) warn "private config.php permissions $p — run: chmod 640 $PRIV/config.php";; esac
    du -sh "$PRIV" 2>/dev/null | awk '{print "  INFO  private folder size " $1}'; fi
  df -h "$HOME" 2>/dev/null | awk 'NR==2{print "  INFO  disk: " $3 " used of " $2}'
fi
echo; echo "RESULT: $OK ok, $WARN warning(s), $BAD problem(s)"; [ "$BAD" -eq 0 ]
