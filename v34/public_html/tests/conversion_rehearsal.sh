#!/usr/bin/env bash
# V34 conversion rehearsal (STAGING/TEST ONLY): dry run → convert (+ financial reconciliation) → verify → app reads →
# rollback → every business row identical to the pre-conversion dump. Same commands as docs/V34_STAGING_GUIDE.md.
# Usage: tests/conversion_rehearsal.sh <populated_source_test_db>
set -uo pipefail
SRC="${1:?source test database}"; DB=ksdd_conv_test
cd "$(dirname "$0")/.."; T="$(mktemp -d)"; P=0; F=0
ok() { echo "  PASS $1"; P=$((P+1)); }; bad() { echo "  FAIL $1"; F=$((F+1)); }
mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" && mysqldump --single-transaction "$SRC" | mysql "$DB"
base="'db_host'=>'127.0.0.1','db_port'=>3306,'db_name'=>'$DB','db_user'=>'f360','db_password'=>'stagingpw','setup_key'=>'TEST-ONLY-setup-key-0123456789abcdef','pii_key'=>'TEST-ONLY-pii-key-0123456789abcdef0123','restaurant'=>'Khadak Singh Da Dhaba','private_dir'=>'$T'"
plat="'platform'=>['enabled'=>false,'key'=>'TEST-ONLY-platform-key-0123456789abcdef0123','base_url'=>'https://staging.example.test','mail'=>['mode'=>'log']]"
echo "<?php return [$base,$plat];" > "$T/pre.php"
biz() { mysqldump --skip-dump-date --skip-extended-insert --no-create-info "$DB" $(mysql -N -e "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_TYPE='BASE TABLE' AND (TABLE_NAME LIKE 'dd\_%' OR TABLE_NAME LIKE 'f3\_%') ORDER BY 1") | grep '^INSERT' | sort; }
biz > "$T/before.sql"; echo "  business rows before: $(wc -l < "$T/before.sql")"
out=$(F360_CONFIG="$T/pre.php" php tools/v31-convert.php 2>&1); echo "$out" | grep -q "DRY RUN" && ok "dry run writes nothing" || bad "dry run: $out"
[ "$(biz | md5sum)" = "$(md5sum < "$T/before.sql")" ] && ok "database unchanged after dry run" || bad "dry run changed data"
out=$(F360_CONFIG="$T/pre.php" php tools/v31-convert.php --apply --i-have-a-backup 2>&1); echo "$out" | grep -E "Converted|Financial|Registered" | sed 's/^/     /'
echo "$out" | grep -q "Verification: [0-9]* OK, 0 mismatch" && ok "every table verified (row count + SHA-256)" || bad "verification"
echo "$out" | grep -qE "Financial reconciliation: [0-9]+ totals compared, 0 difference" && ok "financial totals identical before/after" || bad "reconciliation"
echo "$out" | grep -q "unbalanced 0 → 0" && ok "GL journals balanced (debits = credits) before and after" || bad "GL balance"
ls "$T"/logs/v31-convert-*-reconciliation.md >/dev/null 2>&1 && ok "reconciliation report written to the PRIVATE logs folder" || bad "report"
TID=$(mysql -N -e "SELECT tenant_id FROM $DB.p_branches WHERE is_primary=1 ORDER BY id LIMIT 1"); BID=$(mysql -N -e "SELECT id FROM $DB.p_branches WHERE is_primary=1 ORDER BY id LIMIT 1")
echo "<?php return [$base,$plat,'single_db'=>true,'single_db_scope'=>['tenant_id'=>$TID,'branch_id'=>$BID,'branch_uid'=>'']];" > "$T/mt.php"
F360_CONFIG="$T/mt.php" php tools/v31-convert.php --verify 2>&1 | grep -q " 0 mismatch" && ok "--verify in converted mode: 0 mismatch" || bad "verify"
n1=$(mysql -N -e "SELECT COUNT(*) FROM $SRC.dd_bk_txns"); n2=$(F360_CONFIG="$T/mt.php" php -r 'require "lib.php"; echo row("SELECT COUNT(*) n FROM dd_bk_txns")["n"];')
[ "$n1" = "$n2" ] && ok "app (single-DB mode) reads all $n2 KSDD cash-book transactions through the scoped view" || bad "app reads $n2 of $n1"
F360_CONFIG="$T/pre.php" php tools/v31-convert.php --apply --i-have-a-backup 2>&1 | grep -q "Already converted" && ok "re-running the conversion is a no-op" || bad "re-run"
out=$(F360_CONFIG="$T/mt.php" php tools/v31-convert.php --rollback --i-have-a-backup 2>&1); echo "$out" | tail -2 | sed 's/^/     /'
echo "$out" | grep -q " 0 mismatch" && ok "rollback verified against the pre-conversion hashes" || bad "rollback"
biz | grep -v "'f3_closing_direct'" > "$T/after.sql"; grep -v "'f3_closing_direct'" "$T/before.sql" > "$T/before2.sql"; mv "$T/before2.sql" "$T/before.sql"   # the one announced planned change
diff -q "$T/before.sql" "$T/after.sql" >/dev/null && ok "after rollback every business row is identical to the pre-conversion dump ($(wc -l < "$T/after.sql") rows)" || bad "rows differ: $(diff "$T/before.sql" "$T/after.sql" | wc -l)"
[ "$(mysql -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND (TABLE_NAME LIKE 'mt\_%' OR TABLE_TYPE='VIEW')")" = 0 ] && ok "no base-table prefix or views left after rollback" || bad "leftovers"
echo; echo "RESULT: $P passed, $F failed"; [ $F = 0 ]
