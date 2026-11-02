<?php
declare(strict_types=1);
// KSDD Finance 360 V23 — move encrypted personal data (bank accounts, PAN, Drive/AiSensy secrets, WhatsApp phones/params) from an OLD key
// (setup_key fallback or pii_key_previous) to the CURRENT pii_key. CLI only. Dry run by default; never prints any secret or plain value.
//   1. Back up the database.  2. In config.php add 'pii_key' => '<new 32+ random chars>' and KEEP setup_key / add 'pii_key_previous' => '<old pii_key>'.
//   3. php tools/f360-rekey.php            (dry run: counts per column)   4. php tools/f360-rekey.php --apply --i-have-a-backup
//   5. Run the dry run again: "legacy" must be 0 everywhere. Only then may the old key be removed from config.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php';
require __DIR__ . '/../f360/core.php';
$apply = in_array('--apply', $argv, true); if ($apply && !in_array('--i-have-a-backup', $argv, true)) { fwrite(STDERR, "Refusing: add --i-have-a-backup after taking a database backup.\n"); exit(1); }
$keys = f3_pii_keys(); if (!$keys) { fwrite(STDERR, "No usable key in config.php.\n"); exit(1); }
$primary = $keys[0]; $c = cfg(); if (empty($c['pii_key'])) { echo "Note: no 'pii_key' set — the primary key is still the setup_key fallback. Nothing to move.\n"; }
$cols = rows("SELECT TABLE_NAME t,COLUMN_NAME c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME LIKE '%\\_enc' ORDER BY 1,2");
$tot = ['primary' => 0, 'legacy' => 0, 'unreadable' => 0, 'moved' => 0];
db()->beginTransaction();
try {
    foreach ($cols as $col) { $t = $col['t']; $k = $col['c']; $pk = row("SELECT COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' LIMIT 1", $t)['c'] ?? null; if (!$pk) continue;
        $n = ['primary' => 0, 'legacy' => 0, 'unreadable' => 0, 'moved' => 0];
        foreach (rows("SELECT `$pk` id,`$k` v FROM `$t` WHERE `$k` LIKE 'v1:%'") as $r) {
            if (f3_dec_with($r['v'], $primary) !== null) { $n['primary']++; continue; }
            $plain = f3_dec($r['v']); if ($plain === null) { $n['unreadable']++; continue; } $n['legacy']++;
            if ($apply) { run("UPDATE `$t` SET `$k`=? WHERE `$pk`=?", f3_enc($plain), $r['id']); if ($t === 'f3_wa_recipients' && $k === 'phone_enc') run('UPDATE f3_wa_recipients SET phone_hash=? WHERE id=?', hash_hmac('sha256', $plain, $primary), $r['id']); $n['moved']++; } }
        foreach ($n as $x => $v) $tot[$x] += $v; printf("%-28s %-18s primary=%d legacy=%d unreadable=%d%s\n", $t, $k, $n['primary'], $n['legacy'], $n['unreadable'], $apply ? ' moved=' . $n['moved'] : ''); }
    if ($apply) { if ($tot['unreadable']) throw new RuntimeException($tot['unreadable'] . ' value(s) cannot be read with ANY configured key — nothing was changed. Restore the missing old key first.'); db()->commit(); audit(null, 'f360.pii.rekey', $tot); } else db()->rollBack();
} catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n"); exit(1); }
echo ($apply ? 'APPLIED' : 'DRY RUN') . ': ' . json_encode($tot) . "\n";
