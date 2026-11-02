#!/usr/bin/env bash
# V21 §8 restore evidence (staging only): backup → restore into an EMPTY database → compare every table checksum and
# every document hash with the source. Needs local MariaDB root (sudo) to create the scratch database.
set -euo pipefail
cd "$(dirname "$0")/.."
DB=ksdd_restore_test; OUT=/tmp/ksdd_restore_docs; PASSF=$(mktemp); trap 'rm -f $PASSF' EXIT
sudo mysql -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4; GRANT ALL ON $DB.* TO 'ksdd_stg'@'localhost';"
php -r 'require "lib.php"; require "f360/admin.php"; require "f360/integrations.php"; file_put_contents($argv[1], f3_backup_pass()); $r = f3_backup_run(null, true); echo $r["name"];' "$PASSF" > /tmp/bkname
NAME=$(cat /tmp/bkname); echo "backup: $NAME"
# snapshot source checksums right after the backup (nothing else writes to staging during this script)
TABLES=$(mysql -N -u ksdd_stg -pstg_pass_123 ksdd_staging -e "SHOW FULL TABLES WHERE Table_type='BASE TABLE'" 2>/dev/null | cut -f1 | tr '\n' ',' | sed 's/,$//')
mysql -N -u ksdd_stg -pstg_pass_123 ksdd_staging -e "CHECKSUM TABLE ${TABLES//,/, }" 2>/dev/null | sed 's/^ksdd_staging\.//' | sort > /tmp/cs_src
rm -rf $OUT
php tools/f360-restore.php --file=private/backups/$NAME --pass-file=$PASSF --db-host=localhost --db-name=$DB --db-user=ksdd_stg --db-pass=stg_pass_123 --files-to=$OUT
mysql -N -u ksdd_stg -pstg_pass_123 $DB -e "CHECKSUM TABLE ${TABLES//,/, }" 2>/dev/null | sed "s/^$DB\.//" | sort > /tmp/cs_dst
# f3_int_events gets its "backup created" log line AFTER the dump was taken, so it is the one table expected to differ.
grep -v '^f3_int_events' /tmp/cs_src > /tmp/cs_src2; grep -v '^f3_int_events' /tmp/cs_dst > /tmp/cs_dst2; mv /tmp/cs_src2 /tmp/cs_src; mv /tmp/cs_dst2 /tmp/cs_dst
if diff /tmp/cs_src /tmp/cs_dst > /tmp/cs_diff; then echo "TABLE CHECKSUMS: all $(wc -l < /tmp/cs_src) tables identical (f3_int_events excluded: log written after dump)"; else echo "TABLE CHECKSUM DIFFERENCES:"; cat /tmp/cs_diff; exit 1; fi
BAD=0; N=0; while IFS=$'\t' read -r p s; do N=$((N+1)); [ "$(sha256sum "$OUT/$p" | cut -d' ' -f1)" = "$s" ] || { echo "doc mismatch $p"; BAD=$((BAD+1)); }; done < <(mysql -N -u ksdd_stg -pstg_pass_123 ksdd_staging -e "SELECT path,sha256 FROM dd_files" 2>/dev/null)
echo "DOCUMENTS: $N checked, $BAD mismatches"; [ $BAD -eq 0 ]
# safety: restore tool must refuse the live (configured) database and a non-empty target
R1=$(php tools/f360-restore.php --file=private/backups/$NAME --pass-file=$PASSF --db-name=ksdd_staging --db-user=ksdd_stg --db-pass=stg_pass_123 2>&1 || true)
if grep -q "Refusing" <<< "$R1"; then echo "SAFETY: refuses to overwrite configured database"; else echo "SAFETY FAIL"; exit 1; fi
R2=$(php tools/f360-restore.php --file=private/backups/$NAME --pass-file=$PASSF --db-name=$DB --db-user=ksdd_stg --db-pass=stg_pass_123 2>&1 || true)
if grep -q "not empty" <<< "$R2"; then echo "SAFETY: refuses a non-empty target"; else echo "SAFETY FAIL"; exit 1; fi
echo "RESTORE TEST: PASS"
