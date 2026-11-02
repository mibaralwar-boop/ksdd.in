#!/bin/bash
# Finance 360 V16 — HTTP-level security tests against a STAGING server (never production).
# Usage: BASE=http://127.0.0.1:8360 bash tests/http_tests.sh
BASE=${BASE:-http://127.0.0.1:8360}; P=0; F=0; TMP=$(mktemp -d)
login(){ local j=$TMP/$1; rm -f $j; local c; c=$(curl -s -c $j -b $j "$BASE/api.php?route=status" | python3 -c "import sys,json;print(json.load(sys.stdin)['csrf'])")
  curl -s -o /dev/null -c $j -b $j -H "Content-Type: application/json" -H "X-CSRF-Token: $c" -X POST "$BASE/api.php?route=login" -d "{\"email\":\"$2\",\"password\":\"$3\"}"
  curl -s -c $j -b $j "$BASE/f360-api.php?route=me" | python3 -c "import sys,json;print(json.load(sys.stdin).get('csrf',''))" > $j.csrf; }
code(){ local j=$TMP/$1; if [ "$2" = GET ]; then curl -s -o $TMP/body -w '%{http_code}' -b $j "$BASE/f360-api.php?route=$3"; else curl -s -o $TMP/body -w '%{http_code}' -b $j -H "Content-Type: application/json" -H "X-CSRF-Token: ${5-$(cat $j.csrf)}" -X POST "$BASE/f360-api.php?route=$3" -d "${4:-{\}}"; fi; }
check(){ local name=$1 want=$2 got=$3; if [ "$got" = "$want" ]; then P=$((P+1)); echo "  PASS $name ($got)"; else F=$((F+1)); echo "  FAIL $name expected $want got $got · $(head -c 160 $TMP/body)"; fi; }
login own owner@staging.test 'OwnerPass#2026'; login ctr counter@staging.test 'StagingPass#2026'; login chef chef@staging.test 'StagingPass#2026'
echo "== Authentication & CSRF =="
check "anonymous me → 200 {auth:false} (no data)" 200 "$(code anon GET me)"; grep -q "\"auth\":false" $TMP/body && [ $(wc -c < $TMP/body) -lt 20 ] && { P=$((P+1)); echo "  PASS anonymous me reveals nothing"; } || { F=$((F+1)); echo "  FAIL anonymous me body"; }
check "anonymous dashboard → 401" 401 "$(code anon GET dashboard)"
check "POST without CSRF token → 403" 403 "$(code own POST closing-save '{"date":"2026-11-01"}' 'bad-token')"
check "GET on POST route → 405" 405 "$(code own GET closing-save)"
check "unknown route → 404" 404 "$(code own GET nope)"
check "path-traversal route name rejected → 404" 404 "$(code own GET '../config')"
echo "== Counter: allowed =="
for r in me dashboard "closing&date=2026-10-31" "sales-history&from=2026-10-01&to=2026-10-31" "expenses&from=2026-10-25&to=2026-10-31" expense-categories notifications "assist&section=closing"; do check "counter GET $r" 200 "$(code ctr GET "$r")"; done
echo "== Counter: crafted calls to forbidden data/actions =="
for r in staff "staff-get&id=1" payroll-months "payroll&period=2026-10" "payroll-sheet&period=2026-10" "payslip&period=2026-10&employee_id=2" users settings audit "gl&what=trial" "book&account=Cash" suppliers stock report-staff "export&kind=salary&period=2026-10&format=xlsx" "export&kind=cashbook&format=csv" "file&id=1"; do check "counter GET $r → 403/404" 403 "$(code ctr GET "$r" | sed 's/404/403/')"; done
for r in staff-save staff-txn payroll-open payroll-approve payroll-pay user-save settings-save closing-reopen closing-review correction-decide supplier-pay bill-save stock-move transfer-save; do check "counter POST $r → 403" 403 "$(code ctr POST $r '{"period":"2026-10","date":"2026-10-31","id":1}')"; done
check "counter cannot write 2 days back → 409" 409 "$(code ctr POST expense-save '{"date":"2026-10-29","category":"Vegetable","amount":"5","mode":"Cash"}')"
check "counter cannot edit locked day → 409" 409 "$(code ctr POST closing-save '{"date":"2026-10-31","cash_sale":"1"}')"
echo "== Kitchen / store role (V17.1: uses Finance 360 as Store clerk) =="
check "chef me → 200" 200 "$(code chef GET me)"
check "chef dashboard → 200 (store clerk template)" 200 "$(code chef GET dashboard)"
check "chef expenses → 403" 403 "$(code chef GET 'expenses')"
echo "== Owner =="
for r in users settings audit "payroll&period=2026-10" "payroll-sheet&period=2026-10" "gl&what=trial" stock suppliers; do check "owner GET $r" 200 "$(code own GET "$r")"; done
check "owner export salary xlsx" 200 "$(code own GET 'export&kind=salary&period=2026-10&format=xlsx')"; file $TMP/body | grep -q -i "zip\|excel\|Microsoft OOXML" && { P=$((P+1)); echo "  PASS xlsx is a zip/OOXML file"; } || { F=$((F+1)); echo "  FAIL xlsx type: $(file $TMP/body)"; }
check "owner export salary pdf" 200 "$(code own GET 'export&kind=salary&period=2026-10&format=pdf')"; head -c 5 $TMP/body | grep -q '%PDF' && { P=$((P+1)); echo "  PASS pdf header"; } || { F=$((F+1)); echo "  FAIL pdf header"; }
check "owner export sales csv" 200 "$(code own GET 'export&kind=sales&from=2026-10-01&to=2026-10-31&format=csv')"
echo "== Direct access to protected files =="
for f in config.php f360/core.php f360/schema.php lib.php migrations.php private/f3_fake_now.txt tests/run_tests.php; do c=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$f"); echo "  INFO $f → $c (Apache .htaccess denies these on Hostinger; PHP built-in server ignores .htaccess)"; done
echo; echo "RESULT: $P passed, $F failed"; rm -rf $TMP; [ $F -eq 0 ]
